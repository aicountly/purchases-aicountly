/**
 * No blank errors: every call carries a reference, and a 5xx always shows it (requestRef.ts).
 */
import { describe, it } from 'node:test'
import assert from 'node:assert/strict'
import { messageWithRef, newRequestId, requestIdOf } from './requestRef.ts'

const headers = (value: string | null) => ({ get: (name: string) => (name.toLowerCase() === 'x-request-id' ? value : null) })

describe('requestRef', () => {
  it('makes a fresh 32-hex id per request', () => {
    const a = newRequestId()
    const b = newRequestId()
    assert.match(a, /^[0-9a-f]{32}$/)
    assert.notEqual(a, b)
  })

  it('takes the id the body names, else the header, else the one sent', () => {
    assert.equal(requestIdOf({ request_id: 'body-ref-1' }, headers('hdr-ref-1'), 'sent-ref-1'), 'body-ref-1')
    assert.equal(requestIdOf({ error: { request_id: 'err-ref-1' } }, null, 'sent-ref-1'), 'err-ref-1')
    assert.equal(requestIdOf({ error: { details: { request_id: 'det-ref-1' } } }, null, 'sent-ref-1'), 'det-ref-1')
    assert.equal(requestIdOf(null, headers('hdr-ref-1'), 'sent-ref-1'), 'hdr-ref-1')
    assert.equal(requestIdOf(null, headers(null), 'sent-ref-1'), 'sent-ref-1')
  })

  it('an empty 500 says what happened and quotes the reference — never a bare "Request failed (500)"', () => {
    const message = messageWithRef(500, undefined, 'abc123def456', 'Test')
    assert.match(message, /\(Ref: abc123def456\)$/)
    assert.doesNotMatch(message, /^Request failed/)
  })

  it('a 5xx keeps the server sentence and adds the reference once', () => {
    assert.equal(messageWithRef(503, 'Database down.', 'r-123456', 'Test'), 'Database down. (Ref: r-123456)')
    assert.equal(messageWithRef(500, 'Quote reference r-123456.', 'r-123456', 'Test'), 'Quote reference r-123456.')
  })

  it('a 4xx is the server sentence alone', () => {
    assert.equal(messageWithRef(404, 'Not found.', 'r-123456', 'Test'), 'Not found.')
    assert.equal(messageWithRef(400, '', 'r-123456', 'Test'), 'Request failed (400)')
  })
})
