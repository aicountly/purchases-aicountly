<?php

declare(strict_types=1);

namespace Aicountly\Api\Clients;

use Aicountly\Api\Context;
use Aicountly\Api\Env;
use Aicountly\Api\IdempotencyKey;

/**
 * Live reads and writes against books.aicountly.com.
 *
 * Books is the ONLY authority for the financial transaction: the voucher, the
 * ledger, GST, the receivable, the receipt and its bill-by-bill allocation,
 * e-Invoice and e-Way Bill. This product initiates those documents and keeps
 * the UUID Books hands back — never a second copy of the voucher, never a
 * receivable balance of its own, never a tax it calculated itself.
 *
 * Every number a screen shows for "invoice value", "outstanding" or "credit
 * limit" is fetched here on the request that renders it.
 */
final class BooksClient extends ApiClient
{
    /**
     * Voucher types, as `books_voucher_types.vch_type_id`.
     *
     * These are stable platform ids (PrintConfigurationService names the same
     * values), but they are still only a FALLBACK: voucherTypes() reads the live
     * list, so a deployment that numbers them differently is followed rather
     * than overwritten.
     */
    public const VCH_SALES       = 18;
    public const VCH_PURCHASE    = 11;
    public const VCH_CREDIT_NOTE = 2;
    public const VCH_DEBIT_NOTE  = 3;
    public const VCH_RECEIPT     = 13;
    public const VCH_PAYMENT     = 9;
    public const VCH_CONTRA      = 1;
    public const VCH_JOURNAL     = 5;

    private string $authorization = '';

    public function service(): string
    {
        return 'books';
    }

    protected function productionBase(): string
    {
        return 'https://books.aicountly.com';
    }

    protected function sandboxBase(): string
    {
        return 'https://books.gh.aicountly.com';
    }

    protected function baseEnvKey(): string
    {
        return 'BOOKS_API_BASE';
    }

    public function withSession(string $sesKey): self
    {
        $this->authorization = 'Bearer ' . $sesKey;

        return $this;
    }

    /**
     * Every Books call is made as the signed-in person (their own session). The service-key
     * variant had no caller and sent a header Books does not read for these routes (F374/F510);
     * it is gone rather than left to be wired against the wrong contract.
     *
     * @return array<string, string>
     */
    private function authHeaders(): array
    {
        return ['Authorization' => $this->authorization];
    }

    private function call(string $method, string $path, ?array $body = null, bool $required = false, array $extraHeaders = []): array
    {
        return $this->request($method, $path, $body, $this->authHeaders() + $extraHeaders, $required);
    }

    // -----------------------------------------------------------------------
    // Parties and ledgers — Books owns the accounting identity
    // -----------------------------------------------------------------------

    /** Ledger accounts. `nature` narrows to debtors / creditors where the API supports it. */
    public function accounts(Context $ctx, array $filters = []): array
    {
        return $this->call('GET', 'masters/accounts' . self::query($filters + $ctx->asQuery()));
    }

    public function account(Context $ctx, int $accountId): array
    {
        return $this->call('GET', 'masters/accounts/' . $accountId . self::query($ctx->asQuery()));
    }

    public function taxCategories(Context $ctx): array
    {
        return $this->call('GET', 'masters/tax-categories' . self::query(['limit' => 200] + $ctx->asQuery()));
    }

    // -----------------------------------------------------------------------
    // Vouchers — Books creates and owns them. We send a request and keep the id.
    // -----------------------------------------------------------------------

    /**
     * Create a draft voucher.
     *
     * The idempotency key is ours and is kept for the life of the command: a
     * retry after a timeout must reach the SAME key, so Books replays the
     * original answer instead of writing a second invoice. That is the whole
     * defence against a double-posted sale, and it is why the key is stored in
     * our integration-command row before the call is made rather than generated
     * at the call site.
     *
     * @param array<string, mixed> $payload
     */
    public function createVoucherDraft(Context $ctx, int $vchTypeId, array $payload, string $idempotencyKey): array
    {
        return $this->call('POST', 'vouchers/drafts', [
            'vch_type_id' => $vchTypeId,
            'payload'     => $payload,
        ] + $ctx->asBody(), true, ['Idempotency-Key' => $idempotencyKey]);
    }

    public function postVoucherDraft(Context $ctx, int $draftId, string $idempotencyKey): array
    {
        return $this->call('POST', 'vouchers/drafts/' . $draftId . '/post', $ctx->asBody(), true, ['Idempotency-Key' => $idempotencyKey]);
    }

    /**
     * Create and post in one call from our point of view.
     *
     * Returns the posted voucher, or the failure with the draft id so the
     * command can be retried at the post step without re-creating the draft.
     *
     * @param array<string, mixed> $payload
     * @return array{ok:bool, status:int, body:?array, error:?string, draft_id?:int}
     */
    public function createAndPostVoucher(Context $ctx, int $vchTypeId, array $payload, string $idempotencyKey): array
    {
        // The step rides on the stored key and the pair is sized to Books' 64 characters on the
        // way out (ApiClient → IdempotencyKey), so the draft and the post never share a key and
        // neither is refused for its length. See wireKeys().
        $draft = $this->createVoucherDraft($ctx, $vchTypeId, $payload, $idempotencyKey . ':draft');
        if (!$draft['ok']) {
            return $draft;
        }

        $draftId = (int) ($draft['body']['data']['draft_id'] ?? $draft['body']['data']['id'] ?? $draft['body']['draft_id'] ?? 0);
        if ($draftId === 0) {
            return ['ok' => false, 'status' => $draft['status'], 'body' => $draft['body'], 'error' => 'books_draft_id_missing'];
        }

        $posted = $this->postVoucherDraft($ctx, $draftId, $idempotencyKey . ':post');
        $posted['draft_id'] = $draftId;

        return $posted;
    }

    /**
     * The Idempotency-Keys Books receives for a command's stored key: the draft's and the post's,
     * exactly as createAndPostVoucher() puts them on the wire. For a report that has to say which
     * key Books saw — never for building a request, which goes through request() like any other.
     *
     * @return array{draft: string, post: string}
     */
    public static function wireKeys(string $storedKey): array
    {
        return [
            'draft' => IdempotencyKey::forWire($storedKey, ':draft', IdempotencyKey::BOOKS),
            'post'  => IdempotencyKey::forWire($storedKey, ':post', IdempotencyKey::BOOKS),
        ];
    }

    public function voucher(Context $ctx, int $voucherId): array
    {
        return $this->call('GET', 'vouchers/' . $voucherId . self::query($ctx->asQuery()));
    }

    /**
     * Voucher drafts of one type and status in this year, newest first, each with the payload it
     * was saved from — the only place Books keeps an integration's source_app /
     * source_document_* (DraftsController::index; at most 100 rows).
     */
    public function drafts(Context $ctx, int $vchTypeId, string $status, int $limit = 100): array
    {
        return $this->call('GET', 'vouchers/drafts' . self::query([
            'vch_type_id' => $vchTypeId, 'status' => $status, 'limit' => max(1, min(100, $limit)),
        ] + $ctx->asQuery()));
    }

    /** Voucher register — the live list of invoices, receipts or bills behind any of our screens. */
    public function registers(Context $ctx, array $filters = []): array
    {
        return $this->call('GET', 'registers' . self::query($filters + $ctx->asQuery()));
    }

    // -----------------------------------------------------------------------
    // Receivables, payables, credit control — read live, never stored
    // -----------------------------------------------------------------------

    /**
     * Bill-by-bill outstanding. This IS the receivables/payables report; there is
     * no billing_receivables table anywhere in this product and there never will
     * be, because a second copy of a balance is a second answer.
     */
    public function billByBill(Context $ctx, array $filters = []): array
    {
        return $this->call('GET', 'reports/bill-by-bill' . self::query($filters + $ctx->asQuery()));
    }

    public function purchaseDashboard(Context $ctx, array $filters = []): array
    {
        return $this->call('GET', 'dashboard/purchase' . self::query($filters + $ctx->asQuery()));
    }

    // -----------------------------------------------------------------------
    // Statutory — Books owns the IRN and the e-Way Bill. We only ask.
    // -----------------------------------------------------------------------

    // -----------------------------------------------------------------------
    // Printing — reuse Books' own invoice PDF rather than re-rendering a second one
    // -----------------------------------------------------------------------

}
