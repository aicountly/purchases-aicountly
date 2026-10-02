<?php

declare(strict_types=1);

namespace Aicountly\Api\Clients;

use Aicountly\Api\Env;

/**
 * The company's shared business contacts, in Aicountly Contacts — the one adapter Purchases
 * talks to Contacts through.
 *
 * A supplier has three identities and each lives in one place: its ledger (the creditor
 * account, its balance, its bills) in Books; the people and addresses you talk to in Contacts;
 * the procurement workflow here. The link between the first two is Contacts' own identity
 * reference — books / ledger_account / <acc_id> — one contact per ledger per company.
 *
 * CONTRACT. This codes to the canonical Contacts API contract v1 (envelope, error codes, list
 * by-reference, lookup, resolve, Idempotency-Key on creates). It is deliberately the ONLY class
 * that knows Contacts' paths and envelope, so that when contacts-react-app publishes its shared
 * client (clients/php/ContactsApiClient.php), vendoring it is a mechanical swap of this class's
 * transport and nothing in the domain changes.
 *
 * PATHS ARE RELATIVE TO apiRoot(), which already ends in /api (https://contacts.aicountly.com/api,
 * or CONTACTS_API_BASE as configured). A path that starts with `api/` is the old defect: it made
 * every call /api/api/companies/…, which Contacts answers with a bare 404. pathsAreRelative()
 * exists so a test can pin that.
 *
 * ONLY the company routes (`/api/companies/{cmp_id}/contacts…`). A user's personal contacts are
 * private to them and are never read or written from here. Every call is made as the signed-in
 * user (their session); Contacts checks their membership of the company with Manage itself, and
 * their Contacts role. Nothing read here is stored: names, emails and numbers are read when shown.
 *
 * ANSWERS. Every method returns the transport result plus an `outcome` — the one word a caller
 * branches on — so no caller re-derives meaning from a status code:
 *
 *   ok                 2xx with the v1 envelope
 *   not_found          Contacts answered (envelope) that there is no such contact / reference
 *   route_missing      a bare 404 with no envelope: the address is not one Contacts serves. A
 *                      routing or configuration fault on OUR side — never "no contact", never
 *                      "not deployed yet"
 *   unauthorized       401: Contacts did not accept the session (never treated as a sign-out here)
 *   forbidden          403 company_forbidden: Manage says this person is not in this company
 *   insufficient_role  403 insufficient_role: a member whose Contacts role does not allow it
 *   archived           409 contact_archived
 *   merged             409 contact_merged — details.into is the surviving contact's id
 *   conflict           409 reference_conflict / idempotency_conflict / duplicate_suspected
 *   invalid            400/422 the request was refused as malformed
 *   scope_unavailable  503 company_scope_unavailable: Contacts says company contacts are not
 *                      enabled on that server
 *   unavailable        503 / 5xx / 429 / timeout / unreachable: an answer we could not get
 *   error              anything else
 */
final class ContactsClient extends ApiClient
{
    /** Query parameters the company list route accepts (contract v1 §3.4); anything else is dropped. */
    private const LIST_PARAMS = ['q', 'email', 'phone', 'type', 'category', 'source', 'tags', 'page', 'per_page', 'include_archived', 'ids', 'sort'];

    /** Lookup keys. The company lookup answers email, phone and tax id; one key per call. */
    private const LOOKUP_KEYS = ['email', 'phone', 'tax_id'];

    /** Every path this adapter calls, relative to apiRoot(). Pinned by a test: none may start with `api/`. */
    public const PATHS = [
        'companies/{cmp}/contacts',
        'companies/{cmp}/contacts/{id}',
        'companies/{cmp}/contacts/{id}/resolve',
        'companies/{cmp}/contacts/lookup',
        'companies/{cmp}/contacts/by-reference',
        'companies/{cmp}/contacts/{id}/references',
        'companies/{cmp}/contacts/{id}/references/{refId}',
    ];

    private string $authorization = '';

    public function service(): string
    {
        return 'contacts';
    }

    protected function productionBase(): string
    {
        return 'https://contacts.aicountly.com';
    }

    protected function sandboxBase(): string
    {
        return 'https://contacts.gh.aicountly.com';
    }

    protected function baseEnvKey(): string
    {
        return 'CONTACTS_API_BASE';
    }

    public function withSession(string $sesKey): self
    {
        $this->authorization = 'Bearer ' . $sesKey;

        return $this;
    }

    public function configured(): bool
    {
        return Env::get('CONTACTS_API_BASE') !== '' || Env::get('CONTACTS_ENABLED') === '1';
    }

    /** True when no adapter path is written as `api/…` (which would double apiRoot's /api). */
    public static function pathsAreRelative(): bool
    {
        foreach (self::PATHS as $path) {
            if (str_starts_with(ltrim($path, '/'), 'api/')) {
                return false;
            }
        }

        return true;
    }

    /** The full URL a company-contacts call goes to — for diagnostics and the URL-composition test. */
    public function urlFor(int $cmpId, string $suffix = ''): string
    {
        return $this->apiRoot() . '/' . self::company($cmpId) . $suffix;
    }

    /**
     * Company contacts matching a search, one page at a time (never personal ones).
     *
     * @param array<string, mixed> $filters q, page, per_page, … (only contract parameters are sent)
     */
    public function search(int $cmpId, array $filters): array
    {
        $query = array_intersect_key($filters, array_flip(self::LIST_PARAMS));
        if (isset($query['per_page'])) {
            $query['per_page'] = max(1, min(100, (int) $query['per_page']));
        }
        if (isset($query['page'])) {
            $query['page'] = max(1, (int) $query['page']);
        }

        return $this->call('GET', self::company($cmpId) . self::query($query));
    }

    /**
     * Exact lookup by ONE of email | phone | tax_id. Normalised to a list of matches plus a count,
     * whether Contacts answers with the list it serves today or the v1 `{data: contact|null,
     * meta: {matchCount}}` single-answer shape.
     *
     * @return array{ok:bool, status:int, outcome:string, code:?string, message:?string, data:mixed, meta:array<string,mixed>, details:array<string,mixed>, matches:list<array<string,mixed>>, matchCount:int, error:?string}
     */
    public function lookup(int $cmpId, string $key, string $value): array
    {
        if (!in_array($key, self::LOOKUP_KEYS, true)) {
            throw new \InvalidArgumentException('Contacts lookup takes email, phone or tax_id.');
        }
        $res = $this->call('GET', self::company($cmpId) . '/lookup' . self::query([$key => $value]));
        $matches = [];
        if ($res['outcome'] === 'ok') {
            $data = $res['data'];
            if (is_array($data) && array_is_list($data)) {
                $matches = array_values(array_filter($data, 'is_array'));
            } elseif (is_array($data)) {
                $matches = [$data];
            }
        }
        $res['matches'] = $matches;
        $res['matchCount'] = isset($res['meta']['matchCount']) ? (int) $res['meta']['matchCount'] : count($matches);

        return $res;
    }

    public function contact(int $cmpId, string $contactId): array
    {
        return $this->call('GET', self::company($cmpId) . '/' . rawurlencode($contactId));
    }

    /**
     * Where a stored contact id stands now: active, archived, merged (with the survivor) or deleted.
     *
     * Asks Contacts' resolve route (contract v1 §3.6). A Contacts that does not serve it yet answers
     * route_missing, and then the contact itself is read and its state worked out from what it
     * carries (archivedAt, mergedIntoId / integrationMeta.mergedInto, state) — so a caller gets the
     * same shape either way and never mistakes a tombstone for a live contact.
     *
     * @return array{ok:bool, status:int, outcome:string, state:?string, survivorId:?string, contact:?array<string,mixed>, via:string, code:?string, message:?string, error:?string}
     */
    public function resolve(int $cmpId, string $contactId): array
    {
        $res = $this->call('GET', self::company($cmpId) . '/' . rawurlencode($contactId) . '/resolve');
        if ($res['outcome'] === 'ok' && is_array($res['data'])) {
            $data = $res['data'];
            $state = is_string($data['state'] ?? null) ? $data['state'] : null;
            $contact = is_array($data['contact'] ?? null) ? $data['contact'] : null;

            return self::resolution($res, $state, self::idOrNull($data['survivorId'] ?? null), $contact, 'resolve');
        }
        if ($res['outcome'] !== 'route_missing') {
            return self::resolution($res, $res['outcome'] === 'not_found' ? 'deleted' : null, null, null, 'resolve');
        }

        // Contacts without the resolve route: read the contact and judge it by what it carries.
        $read = $this->contact($cmpId, $contactId);
        if ($read['outcome'] !== 'ok' || !is_array($read['data'])) {
            return self::resolution($read, $read['outcome'] === 'not_found' ? 'deleted' : null, null, null, 'contact');
        }

        return self::resolution($read, self::stateOf($read['data']), self::survivorOf($read['data']), $read['data'], 'contact');
    }

    /** The contacts Contacts links to this Books ledger account (a list; at most one for an identity ref). */
    public function byLedgerAccount(int $cmpId, int $accId): array
    {
        return $this->call('GET', self::company($cmpId) . '/by-reference' . self::query(['product' => 'books', 'ref_type' => 'ledger_account', 'ref' => (string) $accId]));
    }

    /**
     * Link a company contact to a Books ledger account — Contacts' identity reference.
     *
     * The Idempotency-Key is the caller's, and must be unique to this ATTEMPT: Contacts keeps a
     * keyed answer, so a key reused after the reference was removed replays the old success and
     * creates nothing.
     */
    public function linkLedgerAccount(int $cmpId, string $contactId, int $accId, string $idempotencyKey, string $caption = 'Supplier ledger in Smart Books'): array
    {
        return $this->call('POST', self::company($cmpId) . '/' . rawurlencode($contactId) . '/references', [
            'product'  => 'books',
            'ref_type' => 'ledger_account',
            'ref'      => (string) $accId,
            'caption'  => $caption,
        ], $idempotencyKey);
    }

    /** Remove one reference from a contact (Contacts lets its creator or a Contacts admin do this). */
    public function deleteReference(int $cmpId, string $contactId, string $referenceId): array
    {
        return $this->call('DELETE', self::company($cmpId) . '/' . rawurlencode($contactId) . '/references/' . rawurlencode($referenceId));
    }

    // ── internals ────────────────────────────────────────────────────────────────────────

    private static function company(int $cmpId): string
    {
        return 'companies/' . $cmpId . '/contacts';
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array{ok:bool, status:int, outcome:string, code:?string, message:?string, data:mixed, meta:array<string,mixed>, details:array<string,mixed>, body:?array<string,mixed>, error:?string, replayed:bool}
     */
    private function call(string $method, string $path, ?array $body = null, string $idempotencyKey = ''): array
    {
        $headers = ['Authorization' => $this->authorization];
        if ($idempotencyKey !== '') {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }

        return self::interpret($this->request($method, $path, $body, $headers, $method !== 'GET'));
    }

    /**
     * The transport result, read against the v1 envelope.
     *
     * @param array{ok:bool, status:int, body:?array<string,mixed>, error:?string} $res
     */
    public static function interpret(array $res): array
    {
        $body = is_array($res['body'] ?? null) ? $res['body'] : null;
        $status = (int) ($res['status'] ?? 0);
        $error = is_array($body['error'] ?? null) ? $body['error'] : null;
        $code = is_string($error['code'] ?? null) ? $error['code'] : null;
        $message = is_string($error['message'] ?? null) ? $error['message'] : (is_string($body['message'] ?? null) ? $body['message'] : null);
        $details = is_array($error['details'] ?? null) ? $error['details'] : [];
        // Older Contacts builds repeat details at the top level (contactId, candidates, …).
        foreach (['contactId', 'into', 'candidates', 'reference', 'role', 'action'] as $key) {
            if (!array_key_exists($key, $details) && $body !== null && array_key_exists($key, $body)) {
                $details[$key] = $body[$key];
            }
        }

        $outcome = match (true) {
            $status >= 200 && $status < 300 => 'ok',
            $status === 0 => 'unavailable',
            $status === 404 && $body === null => 'route_missing',
            $status === 404 => 'not_found',
            $status === 401 => 'unauthorized',
            $status === 403 && $code === 'insufficient_role' => 'insufficient_role',
            $status === 403 => 'forbidden',
            $status === 409 && $code === 'contact_archived' => 'archived',
            $status === 409 && $code === 'contact_merged' => 'merged',
            $status === 409 => 'conflict',
            $status === 412 => 'conflict',
            $status === 400 || $status === 422 => 'invalid',
            $status === 503 && $code === 'company_scope_unavailable' => 'scope_unavailable',
            $status === 429 || $status >= 500 => 'unavailable',
            default => 'error',
        };

        return [
            'ok'       => $outcome === 'ok',
            'status'   => $status,
            'outcome'  => $outcome,
            'code'     => $code,
            'message'  => $message,
            'data'     => $body['data'] ?? null,
            'meta'     => is_array($body['meta'] ?? null) ? $body['meta'] : [],
            'details'  => $details,
            'body'     => $body,
            'error'    => $res['error'] ?? null,
            'replayed' => false,
        ];
    }

    /** active | archived | merged, from what a contact carries. @param array<string, mixed> $contact */
    public static function stateOf(array $contact): string
    {
        $state = $contact['state'] ?? null;
        if (in_array($state, ['active', 'archived', 'merged', 'deleted'], true)) {
            return $state;
        }
        if (self::survivorOf($contact) !== null) {
            return 'merged';
        }

        return empty($contact['archivedAt']) ? 'active' : 'archived';
    }

    /** The contact this one was merged into, if it says so. @param array<string, mixed> $contact */
    public static function survivorOf(array $contact): ?string
    {
        $into = $contact['mergedIntoId'] ?? null;
        if ($into === null && is_array($contact['integrationMeta'] ?? null)) {
            $into = $contact['integrationMeta']['mergedInto'] ?? null;
        }

        return self::idOrNull($into);
    }

    private static function idOrNull(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = strtolower(trim($value));

        return preg_match('/^[0-9a-f-]{36}$/', $value) === 1 ? $value : null;
    }

    /**
     * @param array<string, mixed>      $res
     * @param array<string, mixed>|null $contact
     */
    private static function resolution(array $res, ?string $state, ?string $survivorId, ?array $contact, string $via): array
    {
        return [
            'ok'         => $state !== null,
            'status'     => $res['status'],
            'outcome'    => $state !== null ? 'ok' : $res['outcome'],
            'state'      => $state,
            'survivorId' => $survivorId,
            'contact'    => $contact,
            'via'        => $via,
            'code'       => $res['code'] ?? null,
            'message'    => $res['message'] ?? null,
            'error'      => $res['error'] ?? null,
        ];
    }
}
