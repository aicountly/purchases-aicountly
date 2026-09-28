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
 * it. Nothing about the contact is stored here but its id on the procurement profile.
 */
final class SupplierContactService
{
    public function __construct(
        private readonly Context $ctx,
        private readonly Auth $auth,
    ) {
    }

    /** @return array{linked: bool, contact: ?array<string, mixed>} */
    public function contactFor(int $accId): array
    {
        Permissions::assert($this->ctx, $this->auth, 'supplier.view');
        $response = $this->client()->byLedgerAccount($this->ctx->cmpId, $accId);
        $this->assertAnswered($response);
        $contacts = (array) ($response['body']['data'] ?? []);
        $contact = $contacts[0] ?? null;

        return ['linked' => $contact !== null, 'contact' => is_array($contact) ? self::summary($contact) : null];
    }

    /** Company contacts to choose from when linking one. @return list<array<string, mixed>> */
    public function candidates(string $term): array
    {
        Permissions::assert($this->ctx, $this->auth, 'supplier.view');
        $response = $this->client()->companyContacts($this->ctx->cmpId, $term);
        $this->assertAnswered($response);

        return array_map([self::class, 'summary'], array_values(array_filter((array) ($response['body']['data'] ?? []), 'is_array')));
    }

    /**
     * Ask Contacts to link this company contact to the supplier's ledger. Idempotent: the same
     * link asked twice is one link; a ledger Contacts already links to another contact is refused
     * by Contacts, and that refusal is passed on as it is.
     *
     * @param array<string, mixed> $input {contact_id}
     */
    public function link(int $accId, array $input): array
    {
        Permissions::assert($this->ctx, $this->auth, 'supplier.manage');
        $contactId = trim((string) ($input['contact_id'] ?? ''));
        if (preg_match('/^[0-9a-f-]{36}$/i', $contactId) !== 1) {
            Http::validationFailed('Choose a company contact.', ['field' => 'contact_id']);
        }
        $contact = $this->client()->companyContact($this->ctx->cmpId, $contactId);
        $this->assertAnswered($contact, 'That contact is not one of this company\'s shared contacts.');

        $response = $this->client()->linkLedgerAccount(
            $this->ctx->cmpId,
            $contactId,
            $accId,
            sprintf('purchases:%d:supplier-contact:%d:%s', $this->ctx->cmpId, $accId, strtolower($contactId)),
        );
        if (!$response['ok']) {
            $code = (string) ($response['body']['error']['code'] ?? '');
            if ($response['status'] === 409 && $code === 'reference_conflict') {
                Http::error(409, 'supplier_contact_conflict', 'Contacts already links this supplier\'s ledger to another contact. Change it there if it is wrong.', ['contact_id' => $response['body']['error']['details']['contactId'] ?? $response['body']['contactId'] ?? null]);
            }
            $this->assertAnswered($response);
        }

        Db::run(
            'INSERT INTO purchase_supplier_profiles (cmp_id, supplier_account_id, contact_id, updated_at)
             VALUES (:cmp, :acc, :contact, NOW())
             ON CONFLICT (cmp_id, supplier_account_id) DO UPDATE SET contact_id = EXCLUDED.contact_id, updated_at = NOW()',
            ['cmp' => $this->ctx->cmpId, 'acc' => $accId, 'contact' => $contactId],
        );
        Audit::record($this->ctx, $this->auth, 'supplier.contact_linked', 'supplier', $accId, null, ['contact_id' => $contactId]);

        return $this->contactFor($accId);
    }

    private function client(): ContactsClient
    {
        return (new ContactsClient())->withSession($this->auth->sesKey());
    }

    /** @param array{ok: bool, status: int, body: ?array<string, mixed>, error: ?string} $response */
    private function assertAnswered(array $response, ?string $notFound = null): void
    {
        if ($response['ok']) {
            return;
        }
        // A company route that exists answers a missing contact with its own error code; a
        // Contacts without its company-contacts release has no such route and answers a bare 404.
        $structured = is_array($response['body']['error'] ?? null) && isset($response['body']['error']['code']);
        if ($response['status'] === 403) {
            Http::error(403, 'contacts_forbidden', 'Contacts does not let you see this company\'s contacts.');
        }
        if ($response['status'] === 404 && $structured && $notFound !== null) {
            Http::error(404, 'contact_not_found', $notFound);
        }
        if ($response['status'] === 404 || $response['status'] === 503) {
            Http::error(503, 'contacts_unavailable', 'Company contacts are not available from Aicountly Contacts yet. Suppliers are still chosen from their Books ledger.', ['retryable' => true]);
        }
        Http::error(502, 'contacts_unavailable', 'Aicountly Contacts did not answer: ' . (string) $response['error'], ['retryable' => true]);
    }

    /** @param array<string, mixed> $contact */
    private static function summary(array $contact): array
    {
        return [
            'id'               => (string) ($contact['id'] ?? ''),
            'display_name'     => (string) ($contact['displayName'] ?? ''),
            'organization_name' => $contact['organizationName'] ?? null,
            'emails'           => array_values(array_map(static fn ($e) => is_array($e) ? (string) ($e['value'] ?? '') : (string) $e, (array) ($contact['emails'] ?? []))),
            'phones'           => array_values(array_map(static fn ($p) => is_array($p) ? (string) ($p['value'] ?? '') : (string) $p, (array) ($contact['phones'] ?? []))),
            'tax_ids'          => (array) ($contact['taxIds'] ?? []),
        ];
    }
}
