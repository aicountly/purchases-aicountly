<?php

declare(strict_types=1);

namespace Aicountly\Api\Clients;

use Aicountly\Api\Env;

/**
 * The company's shared business contacts, in Aicountly Contacts.
 *
 * A supplier has three identities and each lives in one place: its ledger (the creditor
 * account, its balance, its bills) in Books; the people and addresses you talk to in Contacts;
 * the procurement workflow here. The link between the first two is Contacts' own identity
 * reference — books / ledger_account / <acc_id> — one contact per ledger per company.
 *
 * ONLY the company routes (`/api/companies/{cmp_id}/contacts…`). A user's personal contacts are
 * private to them and are never read or written from here. Every call is made as the signed-in
 * user (their session); Contacts checks their membership of the company with Manage itself.
 * Nothing read here is stored: names, emails and numbers are read when shown.
 *
 * Contacts answers 404 for these routes until its company-contacts release is deployed; callers
 * report that as "not available", never fall back to personal contacts.
 */
final class ContactsClient extends ApiClient
{
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

    /** Company contacts matching a search (never personal ones). */
    public function companyContacts(int $cmpId, string $term, int $perPage = 20): array
    {
        return $this->request('GET', 'api/companies/' . $cmpId . '/contacts' . self::query(['q' => $term, 'per_page' => max(1, min(100, $perPage))]), null, ['Authorization' => $this->authorization]);
    }

    public function companyContact(int $cmpId, string $contactId): array
    {
        return $this->request('GET', 'api/companies/' . $cmpId . '/contacts/' . rawurlencode($contactId), null, ['Authorization' => $this->authorization]);
    }

    /** The contact Contacts has linked to this Books ledger account, if any. */
    public function byLedgerAccount(int $cmpId, int $accId): array
    {
        return $this->request('GET', 'api/companies/' . $cmpId . '/contacts/by-reference' . self::query(['product' => 'books', 'ref_type' => 'ledger_account', 'ref' => (string) $accId]), null, ['Authorization' => $this->authorization]);
    }

    /** Link a company contact to a Books ledger account — Contacts' identity reference. */
    public function linkLedgerAccount(int $cmpId, string $contactId, int $accId, string $idempotencyKey): array
    {
        return $this->request('POST', 'api/companies/' . $cmpId . '/contacts/' . rawurlencode($contactId) . '/references', [
            'product'  => 'books',
            'ref_type' => 'ledger_account',
            'ref'      => (string) $accId,
            'caption'  => 'Supplier ledger in Smart Books',
        ], [
            'Authorization'   => $this->authorization,
            'Idempotency-Key' => $idempotencyKey,
        ]);
    }
}
