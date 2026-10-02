<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain;

use Aicountly\Api\Audit;
use Aicountly\Api\Auth;
use Aicountly\Api\Clients\ContactsClient;
use Aicountly\Api\Context;
use Aicountly\Api\Db;
use Aicountly\Api\Http;
use Aicountly\Api\Permissions;

/**
 * Who to talk to at a supplier: the company contact Contacts links to the supplier's ledger.
 *
 * The supplier IS its Books creditor account (supplier_account_id everywhere in this product).
 * Contacts holds the people, emails and numbers, and links a contact to that account with its own
 * identity reference. This reads that link live and, when a person chooses, asks Contacts to make
 * or remove it. Nothing about the contact is stored here but its id on the procurement profile —
 * a cache of Contacts' answer, written only by this class and corrected whenever Contacts says
 * something different.
 *
 * A linked contact is never presented as live when it is not: an archived contact is shown as
 * archived, a merged one as merged with the contact that survived, and a contact Contacts no
 * longer has as gone. Whatever Contacts could not answer is said as such — an unreachable
 * Contacts is "unavailable", a wrong address is our fault, and neither is "no contact".
 */
final class SupplierContactService
{
    /** A GSTIN (15) or PAN (10): looked up exactly, because a name search never matches a tax id. */
    private const GSTIN = '/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][0-9A-Z]Z[0-9A-Z]$/';
    private const PAN   = '/^[A-Z]{5}[0-9]{4}[A-Z]$/';

    public function __construct(
        private readonly Context $ctx,
        private readonly Auth $auth,
        private readonly ?ContactsClient $client = null,
    ) {
    }

    /**
     * The contact linked to this supplier's ledger, and the state it is in.
     *
     * @return array{linked: bool, state: ?string, contact: ?array<string, mixed>, survivor: ?array<string, mixed>, reference_id: ?string, message: ?string}
     */
    public function contactFor(int $accId): array
    {
        Permissions::assert($this->ctx, $this->auth, 'supplier.view');
        $response = $this->contacts()->byLedgerAccount($this->ctx->cmpId, $accId);
        $this->assertAnswered($response);

        $contacts = array_values(array_filter(is_array($response['data']) ? $response['data'] : [], 'is_array'));
        $references = array_values(array_filter(is_array($response['body']['references'] ?? null) ? $response['body']['references'] : [], 'is_array'));
        $contact = $contacts[0] ?? null;
        if ($contact === null) {
            $this->rememberLink($accId, null);

            return ['linked' => false, 'state' => null, 'contact' => null, 'survivor' => null, 'reference_id' => null, 'message' => null];
        }

        $id = (string) ($contact['id'] ?? '');
        $referenceId = null;
        foreach ($references as $reference) {
            if (strtolower((string) ($reference['contactId'] ?? '')) === strtolower($id)) {
                $referenceId = (string) ($reference['id'] ?? '') ?: null;
            }
        }

        $state = ContactsClient::stateOf($contact);
        $survivor = null;
        $message = null;
        if ($state !== 'active') {
            // A tombstone is never shown as the supplier's contact. Ask where it stands now.
            $resolved = $this->contacts()->resolve($this->ctx->cmpId, $id);
            if ($resolved['ok']) {
                $state = (string) $resolved['state'];
                // Contacts names the contact itself as the "survivor" of an active or archived one.
                if ($state === 'merged' && $resolved['survivorId'] !== null && $resolved['survivorId'] !== strtolower($id)) {
                    $read = $this->contacts()->contact($this->ctx->cmpId, $resolved['survivorId']);
                    if ($read['outcome'] === 'ok' && is_array($read['data'])) {
                        $survivor = self::summary($read['data']);
                    }
                }
            }
            $message = match ($state) {
                'merged'   => 'Contacts merged this contact into another one. Link the contact that survived.',
                'deleted'  => 'Contacts no longer has this contact. Link another one.',
                default    => 'This contact is archived in Aicountly Contacts. Remove the link and choose an active contact.',
            };
        }
        $this->rememberLink($accId, $state === 'active' ? $id : null);

        return [
            'linked'       => true,
            'state'        => $state,
            'contact'      => self::summary($contact),
            'survivor'     => $survivor,
            'reference_id' => $referenceId,
            'message'      => $message,
        ];
    }

    /**
     * Company contacts to choose from when linking one — read through the company routes only.
     *
     * A GSTIN, PAN, email or phone is looked up exactly (company lookup); anything else searches
     * names, a page at a time. Choosing a contact to link is a supplier.manage act, so searching
     * the company's contact book for one is too; what comes back is only what a person needs to
     * tell the candidates apart.
     *
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function candidates(string $term, int $page = 1, int $perPage = 20): array
    {
        Permissions::assert($this->ctx, $this->auth, 'supplier.manage');
        $term = trim($term);
        $page = max(1, $page);
        $perPage = max(1, min(50, $perPage));

        [$key, $value] = self::lookupKey($term);
        if ($key !== null) {
            $response = $this->contacts()->lookup($this->ctx->cmpId, $key, $value);
            $this->assertAnswered($response);
            $rows = array_map([self::class, 'candidate'], $response['matches']);

            return ['data' => $rows, 'meta' => ['searched_by' => $key, 'page' => 1, 'per_page' => count($rows), 'total' => $response['matchCount'], 'total_pages' => 1]];
        }

        $response = $this->contacts()->search($this->ctx->cmpId, ['q' => $term, 'page' => $page, 'per_page' => $perPage]);
        $this->assertAnswered($response);
        $rows = array_map([self::class, 'candidate'], array_values(array_filter(is_array($response['data']) ? $response['data'] : [], 'is_array')));
        $meta = $response['meta'];

        return ['data' => $rows, 'meta' => [
            'searched_by' => 'name',
            'page'        => (int) ($meta['page'] ?? $page),
            'per_page'    => (int) ($meta['per_page'] ?? $perPage),
            'total'       => isset($meta['total']) ? (int) $meta['total'] : count($rows),
            'total_pages' => isset($meta['total_pages']) ? (int) $meta['total_pages'] : 1,
        ]];
    }

    /**
     * Ask Contacts to link this company contact to the supplier's ledger.
     *
     * The contact is resolved first, so a stale picker cannot link a tombstone: a merged contact is
     * refused with the survivor to link instead, an archived one is refused as archived. A ledger
     * Contacts already links to another contact is refused by Contacts, and that refusal is passed on.
     *
     * The Idempotency-Key is unique to this attempt. Contacts already answers a repeated identical
     * link from the existing reference; a key shared across attempts only made a re-link after the
     * reference was removed replay the old success and create nothing. After Contacts answers, the
     * link is read back — the profile and the audit trail record a link only when Contacts has it.
     *
     * @param array<string, mixed> $input {contact_id}
     */
    public function link(int $accId, array $input): array
    {
        Permissions::assert($this->ctx, $this->auth, 'supplier.manage');
        $contactId = strtolower(trim((string) ($input['contact_id'] ?? '')));
        if (preg_match('/^[0-9a-f-]{36}$/', $contactId) !== 1) {
            Http::validationFailed('Choose a company contact.', ['field' => 'contact_id']);
        }

        $resolved = $this->contacts()->resolve($this->ctx->cmpId, $contactId);
        if (!$resolved['ok']) {
            $this->assertAnswered(['outcome' => $resolved['outcome'], 'status' => $resolved['status'], 'code' => $resolved['code'], 'message' => $resolved['message'], 'details' => [], 'error' => $resolved['error']], 'That contact is not one of this company\'s shared contacts.');
        }
        if ($resolved['state'] === 'deleted') {
            Http::error(404, 'contact_not_found', 'That contact is not one of this company\'s shared contacts.');
        }
        if ($resolved['state'] === 'merged') {
            Http::error(409, 'contact_merged', 'Contacts merged that contact into another one. Link the contact that survived.', ['into' => $resolved['survivorId'], 'retryable' => false]);
        }
        if ($resolved['state'] === 'archived') {
            Http::error(409, 'contact_archived', 'That contact is archived in Aicountly Contacts. Choose an active contact.', ['retryable' => false]);
        }

        $response = $this->contacts()->linkLedgerAccount(
            $this->ctx->cmpId,
            $contactId,
            $accId,
            sprintf('purchases:%d:supplier-contact:%d:%s:%s', $this->ctx->cmpId, $accId, $contactId, bin2hex(random_bytes(8))),
        );
        if ($response['outcome'] === 'conflict' && $response['code'] === 'reference_conflict') {
            $holder = is_string($response['details']['contactId'] ?? null) ? strtolower($response['details']['contactId']) : null;
            Http::error(409, 'supplier_contact_conflict', 'Contacts already links this supplier\'s ledger to another contact. Remove that link first if it is wrong.', ['contact_id' => $holder, 'retryable' => false]);
        }
        $this->assertAnswered($response);

        // Contacts said yes: confirm the link is really there before anything here records it.
        $now = $this->contactFor($accId);
        if (!$now['linked'] || strtolower((string) ($now['contact']['id'] ?? '')) !== $contactId) {
            Http::error(409, 'supplier_contact_not_linked', 'Contacts answered, but the supplier\'s ledger is not linked to that contact. Nothing was recorded; try again.', ['retryable' => true]);
        }

        Audit::record($this->ctx, $this->auth, 'supplier.contact_linked', 'supplier', $accId, null, ['contact_id' => $contactId]);

        return $now;
    }

    /**
     * Remove the link between the supplier's ledger and its contact, in Contacts.
     *
     * Contacts decides whether this person may: the reference's creator or a Contacts admin. The
     * typical use is replacing an archived contact — Contacts keeps an archived contact's references.
     */
    public function unlink(int $accId): array
    {
        Permissions::assert($this->ctx, $this->auth, 'supplier.manage');
        $current = $this->contactFor($accId);
        if (!$current['linked']) {
            return $current;
        }
        $contactId = (string) ($current['contact']['id'] ?? '');
        $referenceId = (string) ($current['reference_id'] ?? '');
        if ($contactId === '' || $referenceId === '') {
            Http::error(502, 'contacts_reference_unknown', 'Contacts did not say which reference links this supplier, so it cannot be removed from here.', ['retryable' => false]);
        }

        $response = $this->contacts()->deleteReference($this->ctx->cmpId, $contactId, $referenceId);
        if ($response['outcome'] !== 'not_found') {
            $this->assertAnswered($response);
        }

        $this->rememberLink($accId, null);
        Audit::record($this->ctx, $this->auth, 'supplier.contact_unlinked', 'supplier', $accId, ['contact_id' => $contactId], null);

        return $this->contactFor($accId);
    }

    private function contacts(): ContactsClient
    {
        return ($this->client ?? new ContactsClient())->withSession($this->auth->sesKey());
    }

    /**
     * Keep the procurement profile's cached contact id in step with what Contacts just said.
     * Only an existing profile is touched; nothing is created to hold a cache.
     */
    private function rememberLink(int $accId, ?string $contactId): void
    {
        Db::run(
            'UPDATE purchase_supplier_profiles SET contact_id = :contact, updated_at = NOW()
             WHERE cmp_id = :cmp AND supplier_account_id = :acc AND contact_id IS DISTINCT FROM :contact',
            ['cmp' => $this->ctx->cmpId, 'acc' => $accId, 'contact' => $contactId],
        );
        if ($contactId !== null) {
            Db::run(
                'INSERT INTO purchase_supplier_profiles (cmp_id, supplier_account_id, contact_id, updated_at)
                 VALUES (:cmp, :acc, :contact, NOW())
                 ON CONFLICT (cmp_id, supplier_account_id) DO NOTHING',
                ['cmp' => $this->ctx->cmpId, 'acc' => $accId, 'contact' => $contactId],
            );
        }
    }

    /**
     * Turn anything but a clean answer into an honest error for the person.
     *
     * @param array{outcome: string, status: int, code: ?string, message: ?string, details: array<string, mixed>, error: ?string} $response
     */
    private function assertAnswered(array $response, ?string $notFound = null): void
    {
        $outcome = $response['outcome'];
        if ($outcome === 'ok') {
            return;
        }

        switch ($outcome) {
            case 'route_missing':
                // A bare 404: the address is not one Contacts serves. That is a fault in how this
                // product calls Contacts, never "not deployed yet" and never "no contact".
                error_log('[contacts] route_missing: Purchases called an address Contacts does not serve');
                Http::error(502, 'contacts_route_missing', 'Purchases asked Aicountly Contacts at an address it does not serve. This is a set-up fault in Purchases, not missing data — the supplier\'s Books ledger is unaffected.', ['retryable' => false]);
            case 'scope_unavailable':
                Http::error(503, 'contacts_company_scope_unavailable', 'Aicountly Contacts says company contacts are not enabled on its server yet. Suppliers are still chosen from their Books ledger.', ['retryable' => false]);
            case 'unavailable':
                Http::error(503, 'contacts_unavailable', 'Aicountly Contacts could not be reached or could not answer right now. Try again in a moment; the supplier\'s Books ledger is unaffected.', ['retryable' => true]);
            case 'unauthorized':
                // Purchases has just accepted this session itself; Contacts refusing it is not a
                // sign-out here. Most often Contacts could not check it.
                Http::error(503, 'contacts_session_unconfirmed', 'Aicountly Contacts could not confirm your session just now. You are still signed in; try again in a moment.', ['retryable' => true]);
            case 'forbidden':
                Http::error(403, 'contacts_forbidden', 'Aicountly Contacts says you are not a member of this company.');
            case 'insufficient_role':
                $role = is_string($response['details']['role'] ?? null) ? $response['details']['role'] : null;
                Http::error(403, 'contacts_role_insufficient', 'Your role in Aicountly Contacts for this company' . ($role !== null ? ' (' . $role . ')' : '') . ' does not allow this. A Contacts admin can change it.', ['role' => $role]);
            case 'not_found':
                Http::error(404, 'contact_not_found', $notFound ?? 'Aicountly Contacts has no such contact in this company.');
            case 'archived':
                Http::error(409, 'contact_archived', 'That contact is archived in Aicountly Contacts. Choose an active contact.', ['retryable' => false]);
            case 'merged':
                Http::error(409, 'contact_merged', 'Contacts merged that contact into another one. Link the contact that survived.', ['into' => $response['details']['into'] ?? null, 'retryable' => false]);
            case 'invalid':
            case 'conflict':
                Http::error(422, 'contacts_refused', 'Aicountly Contacts refused that: ' . ($response['message'] ?? 'no reason given') . '.', ['code' => $response['code'], 'retryable' => false]);
            default:
                Http::error(502, 'contacts_error', 'Aicountly Contacts answered HTTP ' . $response['status'] . '. Nothing was changed.', ['retryable' => false]);
        }
    }

    /** @return array{0: ?string, 1: string} */
    private static function lookupKey(string $term): array
    {
        $upper = strtoupper(str_replace(' ', '', $term));
        if (preg_match(self::GSTIN, $upper) === 1 || preg_match(self::PAN, $upper) === 1) {
            return ['tax_id', $upper];
        }
        if (str_contains($term, '@') && filter_var($term, FILTER_VALIDATE_EMAIL) !== false) {
            return ['email', strtolower($term)];
        }
        $digits = preg_replace('/\D/', '', $term) ?? '';
        if (preg_match('/^\+?[\d\s()-]+$/', $term) === 1 && strlen($digits) >= 6) {
            return ['phone', str_starts_with(trim($term), '+') ? '+' . $digits : $digits];
        }

        return [null, $term];
    }

    /**
     * What a person needs to tell candidates apart before linking one — no more. Contact details
     * are shown in full only on the linked supplier's card.
     *
     * @param array<string, mixed> $contact
     */
    private static function candidate(array $contact): array
    {
        $emails = self::values($contact['emails'] ?? []);
        $phones = self::values($contact['phones'] ?? []);

        return [
            'id'                => (string) ($contact['id'] ?? ''),
            'display_name'      => (string) ($contact['displayName'] ?? ''),
            'organization_name' => ($contact['organizationName'] ?? '') === '' ? null : (string) $contact['organizationName'],
            'email_hint'        => $emails === [] ? null : self::maskEmail($emails[0]),
            'phone_hint'        => $phones === [] ? null : self::maskPhone($phones[0]),
            'tax_ids'           => array_values(array_filter((array) ($contact['taxIds'] ?? []), 'is_array')),
            'state'             => ContactsClient::stateOf($contact),
        ];
    }

    /** @param array<string, mixed> $contact */
    private static function summary(array $contact): array
    {
        return [
            'id'                => (string) ($contact['id'] ?? ''),
            'display_name'      => (string) ($contact['displayName'] ?? ''),
            'organization_name' => ($contact['organizationName'] ?? '') === '' ? null : (string) $contact['organizationName'],
            'emails'            => self::values($contact['emails'] ?? []),
            'phones'            => self::values($contact['phones'] ?? []),
            'tax_ids'           => array_values(array_filter((array) ($contact['taxIds'] ?? []), 'is_array')),
            'state'             => ContactsClient::stateOf($contact),
            'archived_at'       => $contact['archivedAt'] ?? null,
        ];
    }

    /** @return list<string> */
    private static function values(mixed $list): array
    {
        $out = [];
        foreach (is_array($list) ? $list : [] as $item) {
            $value = trim(is_array($item) ? (string) ($item['value'] ?? '') : (string) $item);
            if ($value !== '') {
                $out[] = $value;
            }
        }

        return $out;
    }

    private static function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($local, 0, 1) . '•••@' . $domain;
    }

    private static function maskPhone(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone) ?? '';

        return strlen($digits) <= 4 ? '••••' : '••••' . substr($digits, -4);
    }
}
