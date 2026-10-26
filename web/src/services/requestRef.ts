/**
 * The reference that ties an error on screen to its line in the server log ("Ref: …").
 *
 * Every API call sends a fresh X-Request-Id. The API logs a failure it could not handle under that id
 * and answers it back (`request_id`); support finds the line with the find-ref workflow. On a 5xx the
 * message shown always carries the reference — an empty 500 must never read as a bare
 * "Request failed (500)" that nobody can trace.
 */

/** A fresh 32-hex id for one request. */
export function newRequestId(): string {
  try {
    if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
      return crypto.randomUUID().replace(/-/g, '')
    }
  } catch {
    // fall through
  }
  let id = ''
  while (id.length < 32) id += Math.floor(Math.random() * 16).toString(16)
  return id
}

/**
 * The id the server logged this request under: the one its body names (`request_id`, or inside
 * `error`), else the response's X-Request-Id header, else the one this call sent (the API keeps it).
 */
export function requestIdOf(parsed: unknown, headers: { get(name: string): string | null } | null | undefined, sent: string): string {
  const body = (parsed && typeof parsed === 'object' ? parsed : {}) as {
    request_id?: unknown
    error?: { request_id?: unknown; details?: { request_id?: unknown } } | unknown
  }
  const error = (body.error && typeof body.error === 'object' ? body.error : {}) as {
    request_id?: unknown
    details?: { request_id?: unknown }
  }
  for (const candidate of [body.request_id, error.request_id, error.details?.request_id]) {
    if (typeof candidate === 'string' && candidate.trim() !== '') return candidate.trim()
  }
  try {
    const header = headers?.get('X-Request-Id')
    if (header && header.trim() !== '') return header.trim()
  } catch {
    // no readable headers
  }
  return sent
}

/**
 * The message to show for a failed call: the server's own sentence when it sent one (else
 * `fallback`), and on a 5xx the reference appended unless the sentence already quotes it.
 */
export function messageWithRef(status: number, serverMessage: string | null | undefined, requestId: string, app: string): string {
  let message = typeof serverMessage === 'string' && serverMessage.trim() !== '' ? serverMessage : ''
  if (message === '') {
    message =
      status >= 500
        ? `The ${app} server hit an unexpected error and gave no details. Please retry; if it keeps failing, contact support with this reference.`
        : `Request failed (${status})`
  }
  if (status >= 500 && requestId && !message.includes(requestId)) message = `${message} (Ref: ${requestId})`
  return message
}
