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
import { AuthError, ensureSesKey, redirectToPortalSso } from '../auth/portal'
import { clearAllTokens } from '../auth/tokens'
import { noteApiRefusal } from '../lifecycle/companyLifecycle'
import { PLAN_LIMIT_CODE, planLimitFromError, planLimitMessage } from './planLimit'
import { messageWithRef, newRequestId, requestIdOf } from './requestRef'

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
    /** The reference the server logged this request under (X-Request-Id); quoted on a 5xx. */
    readonly requestId: string = '',
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
  /** Extra request headers (an Idempotency-Key). Never Authorization, which is always the session's. */
  headers?: Record<string, string>
}

async function send<T>(path: string, options: RequestOptions, sesKey: string): Promise<T> {
  const scoped = options.scoped !== false
  const method = options.method ?? 'GET'

  // A fresh reference per call: the API logs a failure under it and the 5xx message quotes it.
  const sentRequestId = newRequestId()
  const headers: Record<string, string> = {
    ...(options.headers ?? {}),
    Accept: 'application/json',
    Authorization: `Bearer ${sesKey}`,
    'X-Request-Id': sentRequestId,
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
    // 423 COMPANY_RESTRICTED: the company is view/export-only (lapsed subscription) — banner + toast.
    noteApiRefusal(response.status, parsed, scoped ? (scope?.cmp_id ?? null) : null)
    const envelope = parsed as
      | { error?: { code?: string; message?: string; details?: Record<string, unknown> }; message?: string }
      | null
    // 402 PLAN_LIMIT_REACHED: one sentence naming the limit and who can change it, on every screen.
    const planLimit = planLimitFromError(response.status, envelope?.error?.code ?? null, envelope?.error?.details ?? null)
    if (planLimit) {
      throw new ApiError(response.status, PLAN_LIMIT_CODE, planLimitMessage(planLimit), envelope?.error?.details ?? {})
    }
    const requestId = requestIdOf(parsed, response.headers, sentRequestId)
    throw new ApiError(
      response.status,
      envelope?.error?.code ?? 'error',
      messageWithRef(response.status, envelope?.error?.message ?? envelope?.message, requestId, 'Purchases'),
      envelope?.error?.details ?? {},
      requestId,
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
      try {
        const freshKey = await ensureSesKey(true)
        return await send<T>(path, options, freshKey)
      } catch (retryError) {
        // The session is dead (the sign-in itself is spent, or a fresh key is refused too): back to
        // sign-in, rather than every screen showing an error or, worse, an empty list (F146).
        if ((retryError instanceof AuthError && retryError.status === 401) || (retryError instanceof ApiError && retryError.status === 401)) {
          clearAllTokens()
          redirectToPortalSso()
        }
        throw retryError
      }
    }
    throw error
  }
}

/**
 * A POST that may be a decision on one's own document. The company's segregation-of-duties policy
 * lets the owner decide on what they raised only by saying why (sod_reason_required): the reason
 * is asked for here and the same request is sent once more with it as its note. Every other
 * refusal — including sod_self_decision, where nobody may — is the caller's to show.
 */
async function postDeciding<T>(path: string, body?: unknown, params?: QueryParams): Promise<ItemResponse<T>> {
  try {
    return await request<ItemResponse<T>>(path, { method: 'POST', body: body ?? {}, params })
  } catch (error) {
    if (
      error instanceof ApiError &&
      error.code === 'sod_reason_required' &&
      typeof window !== 'undefined' &&
      typeof window.prompt === 'function' &&
      (body === undefined || (typeof body === 'object' && body !== null && !Array.isArray(body)))
    ) {
      const reason = window.prompt(`${error.message}\n\nWhy are you deciding on it yourself?`)
      if (reason && reason.trim() !== '') {
        return await request<ItemResponse<T>>(path, { method: 'POST', body: { ...((body as Record<string, unknown>) ?? {}), note: reason.trim() }, params })
      }
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

  post: <T>(path: string, body?: unknown, params?: QueryParams) => postDeciding<T>(path, body, params),

  /**
   * A POST that creates something in another product under an Idempotency-Key (one per form), so a
   * retry after a lost answer is that product's replay of the same record, never a second one.
   */
  postOnce: <T>(path: string, body: unknown, idempotencyKey: string) =>
    request<ItemResponse<T>>(path, { method: 'POST', body, headers: { 'Idempotency-Key': idempotencyKey } }),

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

    const sentRequestId = newRequestId()
    const pull = async (key: string): Promise<Response> =>
      fetch(buildUrl(path, params, true), {
        method: 'GET',
        headers: { Authorization: `Bearer ${key}`, Accept: '*/*', 'X-Request-Id': sentRequestId },
      })

    let response = await pull(sesKey)
    if (response.status === 401) {
      response = await pull(await ensureSesKey(true))
    }

    if (!response.ok) {
      const text = await response.text()
      let message = `That export could not be produced (${response.status}).`
      let parsed: unknown = null
      try {
        parsed = JSON.parse(text)
        const envelope = parsed as { error?: { message?: string }; message?: string }
        message = envelope?.error?.message ?? envelope?.message ?? message
      } catch {
        /* a non-JSON error body is not worth showing raw */
      }
      const requestId = requestIdOf(parsed, response.headers, sentRequestId)
      throw new ApiError(response.status, 'export_failed', messageWithRef(response.status, message, requestId, 'Purchases'), {}, requestId)
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
