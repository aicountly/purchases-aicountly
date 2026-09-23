/**
 * Typed fetch wrapper for the Purchases API.
 *
 * What it encodes so pages do not have to:
 *
 *  - `Authorization: Bearer <ses_key>` from the portal session, minted on demand,
 *    and one silent retry on 401 with a fresh key (a key can be revoked before
 *    its local expiry).
 *  - Company context (cmp_id, fy_id, bo_id) on every scoped call, as query
 *    parameters and — for JSON bodies — in the body too, which is what the
 *    backend's Http::param() reads.
 *  - The fleet's envelopes: `{data}`, `{data, meta}`, and errors as ApiError.
 */

import { getApiBaseUrl } from '../config'
import { ensureSesKey } from '../auth/portal'

export interface CompanyScope {
  cmp_id: number
  fy_id: number
  /** 0 = consolidated, all branches. */
  bo_id: number
}

export interface ListMeta {
  total: number
  limit: number
  offset: number
  [key: string]: unknown
}

export interface ListResponse<T> {
  data: T[]
  meta: ListMeta
}

export interface ItemResponse<T> {
  data: T
}

export type QueryValue = string | number | boolean | null | undefined
export type QueryParams = Record<string, QueryValue>

export class ApiError extends Error {
  constructor(
    readonly status: number,
    readonly code: string,
    message: string,
    readonly details: Record<string, unknown> = {},
  ) {
    super(message)
    this.name = 'ApiError'
  }

  /**
   * True when pressing the same button again could reasonably work.
   *
   * The backend says so explicitly for cross-service failures, because the
   * difference between "Books was unreachable" and "Books refused this" decides
   * whether the UI offers Retry or asks the user to change something.
   */
  get retryable(): boolean {
    if (typeof this.details.retryable === 'boolean') return this.details.retryable
    return this.status === 0 || this.status === 503 || this.status === 502
  }
}

/** The company scope, registered once by CompanyProvider and read by every call. */
let scope: CompanyScope | null = null

export function setScope(next: CompanyScope | null): void {
  scope = next
}

export function getScope(): CompanyScope | null {
  return scope
}

function buildUrl(path: string, params: QueryParams | undefined, scoped: boolean): string {
  const url = new URL(`${getApiBaseUrl()}/${path.replace(/^\//, '')}`, window.location.origin)

  if (scoped && scope) {
    url.searchParams.set('cmp_id', String(scope.cmp_id))
    url.searchParams.set('fy_id', String(scope.fy_id))
    url.searchParams.set('bo_id', String(scope.bo_id))
  }

  for (const [key, value] of Object.entries(params ?? {})) {
    if (value === null || value === undefined || value === '') continue
    url.searchParams.set(key, String(value))
  }

  return url.toString()
}

interface RequestOptions {
  method?: 'GET' | 'POST' | 'PUT' | 'DELETE'
  params?: QueryParams
  body?: unknown
  /** A multipart upload. Mutually exclusive with `body`. */
  form?: FormData
  /** Pass false for calls that take no company context. */
  scoped?: boolean
  signal?: AbortSignal
}

async function send<T>(path: string, options: RequestOptions, sesKey: string): Promise<T> {
  const scoped = options.scoped !== false
  const method = options.method ?? 'GET'

  const headers: Record<string, string> = {
    Accept: 'application/json',
    Authorization: `Bearer ${sesKey}`,
  }

  let body: string | FormData | undefined

  if (options.form !== undefined) {
    // NO Content-Type header. The browser must set it itself, because a
    // multipart body is only parseable with the boundary token the browser
    // generates — setting it by hand produces a request every server rejects
    // as malformed, and the error says nothing about why.
    body = options.form
  } else if (options.body !== undefined) {
    headers['Content-Type'] = 'application/json'
    // Context travels in the body as well: a POST that carries it only in the
    // query string works until someone reads the body first, and then fails in
    // a way that looks like the company was never chosen.
    const payload =
      scoped && scope && typeof options.body === 'object' && options.body !== null && !Array.isArray(options.body)
        ? { ...scope, ...(options.body as Record<string, unknown>) }
        : options.body
    body = JSON.stringify(payload)
  }

  const response = await fetch(buildUrl(path, options.params, scoped), {
    method,
    headers,
    body,
    signal: options.signal,
  })

  const text = await response.text()
  let parsed: unknown = null
  if (text) {
    try {
      parsed = JSON.parse(text)
    } catch {
      parsed = null
    }
  }

  if (!response.ok) {
    const envelope = parsed as
      | { error?: { code?: string; message?: string; details?: Record<string, unknown> }; message?: string }
      | null
    throw new ApiError(
      response.status,
      envelope?.error?.code ?? 'error',
      envelope?.error?.message ?? envelope?.message ?? `Request failed (${response.status})`,
      envelope?.error?.details ?? {},
    )
  }

  return parsed as T
}

async function request<T>(path: string, options: RequestOptions = {}): Promise<T> {
  const sesKey = await ensureSesKey()

  try {
    return await send<T>(path, options, sesKey)
  } catch (error) {
    // Exactly one retry, and only for 401: a key can be revoked server-side
    // before it expires locally, and making the user sign in again for that is
    // a bad trade. Anything else is the caller's to handle.
    if (error instanceof ApiError && error.status === 401) {
      const freshKey = await ensureSesKey(true)
      return await send<T>(path, options, freshKey)
    }
    throw error
  }
}

export const api = {
  get: <T>(path: string, params?: QueryParams, signal?: AbortSignal) =>
    request<T>(path, { method: 'GET', params, signal }),

  list: <T>(path: string, params?: QueryParams, signal?: AbortSignal) =>
    request<ListResponse<T>>(path, { method: 'GET', params, signal }),

  one: <T>(path: string, params?: QueryParams, signal?: AbortSignal) =>
    request<ItemResponse<T>>(path, { method: 'GET', params, signal }),

  post: <T>(path: string, body?: unknown, params?: QueryParams) =>
    request<ItemResponse<T>>(path, { method: 'POST', body: body ?? {}, params }),

  put: <T>(path: string, body?: unknown, params?: QueryParams) =>
    request<ItemResponse<T>>(path, { method: 'PUT', body: body ?? {}, params }),

  del: <T>(path: string, params?: QueryParams) => request<ItemResponse<T>>(path, { method: 'DELETE', params }),

  /** Context-free: the health check and the portal relay. */
  /**
   * Context-free: the health check, the portal relay, and the company switcher.
   *
   * Takes a signal because the switcher pages through Manage and can be
   * unmounted mid-flight; without it those pages keep arriving and setting
   * state on a component that is gone.
   */
  unscoped: <T>(path: string, params?: QueryParams, signal?: AbortSignal) =>
    request<T>(path, { method: 'GET', params, scoped: false, signal }),

  /**
   * A file, posted as multipart.
   *
   * The company context rides in the query string rather than the body: a
   * multipart body is a stream of parts, and appending scope fields to it would
   * put them after the file on the wire for no benefit.
   */
  upload: <T>(path: string, form: FormData, params?: QueryParams, signal?: AbortSignal) =>
    request<ItemResponse<T>>(path, { method: 'POST', form, params, signal }),

  /**
   * Fetch a file and hand it to the browser to save.
   *
   * WHY NOT AN <a href>. This API authenticates with a bearer token, and a
   * plain link cannot carry one: the browser sends the URL and nothing else, so
   * every export link in this product was answering 401 and the click appeared
   * to do nothing. There is no cookie to fall back on and there should not be —
   * a cookie that authenticates a download authenticates every other request
   * the same way, from any page that can make one.
   *
   * So the file is fetched like any other call, with the header, and saved from
   * the Blob. The object URL is revoked immediately; leaving it alive pins the
   * whole file in memory for the life of the tab.
   */
  download: async (path: string, filename: string, params?: QueryParams): Promise<void> => {
    const sesKey = await ensureSesKey()

    const pull = async (key: string): Promise<Response> =>
      fetch(buildUrl(path, params, true), {
        method: 'GET',
        headers: { Authorization: `Bearer ${key}`, Accept: '*/*' },
      })

    let response = await pull(sesKey)
    if (response.status === 401) {
      response = await pull(await ensureSesKey(true))
    }

    if (!response.ok) {
      const text = await response.text()
      let message = `That export could not be produced (${response.status}).`
      try {
        const envelope = JSON.parse(text) as { error?: { message?: string }; message?: string }
        message = envelope?.error?.message ?? envelope?.message ?? message
      } catch {
        /* a non-JSON error body is not worth showing raw */
      }
      throw new ApiError(response.status, 'export_failed', message, {})
    }

    const blob = await response.blob()
    const url = URL.createObjectURL(blob)
    const link = document.createElement('a')
    link.href = url
    link.download = filename
    document.body.appendChild(link)
    link.click()
    link.remove()
    URL.revokeObjectURL(url)
  },
}
