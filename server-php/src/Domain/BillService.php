<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain;

use Aicountly\Api\Audit;
use Aicountly\Api\Auth;
use Aicountly\Api\Clients\BooksClient;
use Aicountly\Api\Context;
use Aicountly\Api\Db;
use Aicountly\Api\Http;
use Aicountly\Api\IntegrationCommand;
use Aicountly\Api\Permissions;

/**
 * Vendor bills — entered here, matched here, POSTED BY BOOKS.
 *
 * Purchases is where the bill is checked against what was ordered and what
 * arrived, because that is a procurement control. Once it passes, Books creates
 * the purchase voucher and owns everything financial about it: the input GST,
 * the TDS, the payable and the bill-by-bill allocation. We keep the voucher
 * uuid and nothing else.
 *
 * The three-way match runs BEFORE the post, and a BLOCKED verdict stops it —
 * that is the whole point of the control. Posting first and matching afterwards
 * would mean the exception is found once the money is already owed.
 */
final class BillService
{
    public const COMMAND_BILL = 'purchases.bill.post';

    /** Orders a bill may be entered or posted against. */
    private const BILLABLE_PO = ['APPROVED', 'ISSUED', 'ACKNOWLEDGED', 'PARTIALLY_RECEIVED', 'RECEIVED'];

    private const EPSILON = 0.00005;

    /**
     * A bill that looks like an earlier one from the same supplier.
     *
     * The exact repeat — same supplier, same invoice number — is refused when
     * the bill is entered, so it never reaches this. What is caught is same
     * supplier and same invoice date, which is a coincidence often enough to
     * be offered for review rather than blocked.
     *
     * `<` rather than `<>` is deliberate and is the whole reason this is a
     * constant: it flags the LATER bill of a pair, once, which is the same rule
     * the "Possible duplicate bills" card counts by. Written out twice, the two
     * drifted apart and the card said one where the tab said two.
     */
    private const DUPLICATE_CLAUSE = "b.supplier_invoice_date IS NOT NULL
        AND b.status NOT IN ('CANCELLED', 'POSTED')
        AND EXISTS (SELECT 1 FROM purchase_bill_requests o
                     WHERE o.cmp_id = b.cmp_id
                       AND o.supplier_account_id = b.supplier_account_id
                       AND o.supplier_invoice_date = b.supplier_invoice_date
                       AND o.request_id < b.request_id
                       AND o.status <> 'CANCELLED')";

    public function __construct(
        private readonly Context $ctx,
        private readonly Auth $auth,
    ) {
    }

    /**
     * Enter a supplier bill and match it. Nothing is posted yet.
     *
     * Against a purchase order, or without one: a service, an expense, a direct purchase.
     * A bill without an order cannot be three-way matched, so its match says so
     * (REVIEW_REQUIRED) and it waits for somebody holding match.resolve before it can post.
     *
     * @param array<string, mixed> $input
     */
    public function enter(array $input): array
    {
        Permissions::assert($this->ctx, $this->auth, 'bill.enter');

        $supplierId = (int) ($input['supplier_account_id'] ?? 0);
        if ($supplierId <= 0) {
            Http::validationFailed('Say which supplier this bill is from.', ['field' => 'supplier_account_id']);
        }

        $invoiceNo = self::text($input['supplier_invoice_no'] ?? null);
        if ($invoiceNo === null) {
            Http::validationFailed('The supplier\'s invoice number is required.', ['field' => 'supplier_invoice_no']);
        }
        $invoiceDate = self::validDate($input['supplier_invoice_date'] ?? null);
        if ($invoiceDate === null) {
            Http::validationFailed('The supplier\'s invoice date is required.', ['field' => 'supplier_invoice_date']);
        }
        $dueDate = self::validDate($input['due_date'] ?? null);
        if ($dueDate !== null && $dueDate < $invoiceDate) {
            Http::validationFailed('The due date cannot be before the invoice date.', ['field' => 'due_date']);
        }
        $postingDate = $this->postingDate($input['posting_date'] ?? null, $invoiceDate);

        $poId = self::id($input['po_id'] ?? null);
        $lines = $this->normaliseLines($input['lines'] ?? [], $poId);
        if ($lines === []) {
            Http::validationFailed('A bill needs at least one line.', ['field' => 'lines']);
        }
        $kind = $poId !== null ? 'po' : (array_filter($lines, static fn (array $l) => !$l['is_service'] && $l['item_id'] !== null) === [] ? 'service' : 'direct');
        // The GSTIN printed on the invoice, when the supplier bills from a registration other
        // than the one on their Books ledger: its state is where the goods are supplied from.
        $supplierGstin = PlaceOfSupply::normaliseGstin($input['supplier_gstin'] ?? null, 'supplier_gstin');

        $requestId = Db::transaction(function () use ($supplierId, $invoiceNo, $invoiceDate, $dueDate, $postingDate, $poId, $lines, $kind, $input, $supplierGstin): int {
            if ($poId !== null) {
                $po = PoProgress::lock($poId, $this->ctx->cmpId);
                if ($po === null) {
                    Http::notFound('That purchase order does not exist.');
                }
                $this->assertBillable($po);
                if ((int) $po['supplier_account_id'] !== $supplierId) {
                    Http::validationFailed('This bill is from a different supplier than the purchase order.', ['field' => 'supplier_account_id']);
                }
            }

            // A duplicate supplier invoice number is the oldest payables fraud and the
            // commonest honest mistake. Caught here, and by a unique index behind it.
            $duplicate = Db::first(
                "SELECT request_id FROM purchase_bill_requests
                 WHERE cmp_id = :cmp AND supplier_account_id = :supplier
                   AND lower(supplier_invoice_no) = lower(:invoice) AND status <> 'CANCELLED'",
                ['cmp' => $this->ctx->cmpId, 'supplier' => $supplierId, 'invoice' => $invoiceNo],
            );
            if ($duplicate !== null) {
                Http::conflict(
                    'A bill with that invoice number has already been entered for this supplier.',
                    ['existing_request_id' => (int) $duplicate['request_id']],
                );
            }

            try {
                return (int) Db::insert('purchase_bill_requests', [
                    'cmp_id'                => $this->ctx->cmpId,
                    'fy_id'                 => $this->ctx->fyId,
                    'bo_id'                 => $this->ctx->boId,
                    'po_id'                 => $poId,
                    'bill_kind'             => $kind,
                    'supplier_account_id'   => $supplierId,
                    'supplier_invoice_no'   => $invoiceNo,
                    'supplier_invoice_date' => $invoiceDate,
                    'supplier_gstin'        => $supplierGstin,
                    'posting_date'          => $postingDate === $invoiceDate ? null : $postingDate,
                    'due_date'              => $dueDate,
                    'status'                => 'MATCHING',
                    'requested_lines'       => $lines,
                    'receipt_references'    => is_array($input['receipt_references'] ?? null) ? $input['receipt_references'] : [],
                    'requested_by'          => $this->auth->uuid,
                ], 'request_id');
            } catch (\PDOException $e) {
                if (($e->errorInfo[0] ?? '') === '23505') {
                    Http::conflict('A bill with that invoice number has already been entered for this supplier.');
                }
                throw $e;
            }
        });

        $bill = $this->find($requestId);
        $match = (new ThreeWayMatchService($this->ctx, $this->auth))->run($bill);

        Db::update('purchase_bill_requests', [
            'status'     => $match['verdict'] === ThreeWayMatchService::BLOCKED || $match['verdict'] === ThreeWayMatchService::REVIEW_REQUIRED
                ? 'EXCEPTION'
                : 'MATCHED',
            'updated_at' => self::now(),
        ], ['request_id' => $requestId]);

        Audit::record($this->ctx, $this->auth, 'bill.entered', 'bill_request', $requestId, null, [
            'supplier_invoice_no' => $invoiceNo,
            'bill_kind'           => $kind,
            'match_verdict'       => $match['verdict'],
        ]);

        $result = $this->find($requestId);
        $result['match'] = $match;

        return $result;
    }

    /**
     * Post a bill to Books, once.
     *
     * Decided under locks — the bill row and its order — and sent with none held: the
     * order must still be billable (a cancel that raced the post loses to whichever took
     * the lock first, and a CANCELLED order is never billed), the match exceptions must
     * be resolved, and what the bill settles in stock is fixed and stored on the bill so
     * a second bill against the same order cannot settle the same goods while this one is
     * on its way. The body sent to Books is stored on the command and replayed verbatim
     * by any retry.
     *
     * Books is written as the person posting, on their session: a bill is theirs to post,
     * and Books checks their permission and their company. Acceptance is what Books
     * RECORDED, read back after the post — not the 200.
     *
     * @param array<string, mixed> $input
     */
    public function post(int $requestId, array $input = []): array
    {
        Permissions::assert($this->ctx, $this->auth, 'bill.post');

        // The bill is posted in the year and branch it was ENTERED in, never the ones the
        // screen happens to be on: a bill entered under branch 2 and posted from the
        // consolidated view is still branch 2's purchase, with branch 2's GSTIN deciding its tax.
        // Confirmed with Manage before any row is locked — no lock is held across a network call.
        $entered = $this->find($requestId);
        if ($entered === []) {
            Http::notFound('That bill does not exist.');
        }
        $billScope = $this->scopeOf($entered);

        // Where the supplier supplies from, read from their Books ledger as this person — only
        // for a revision not yet sent (a sent one replays its stored body) and before any lock.
        $supply = null;
        if (IntegrationCommand::find($this->ctx->cmpId, self::COMMAND_BILL, 'bill_request', $requestId, (int) $entered['revision']) === null
            && !in_array($entered['status'], ['POSTED', 'CANCELLED'], true)) {
            $supply = PlaceOfSupply::forSupplier(
                (new BooksClient())->withSession($this->auth->sesKey()),
                $billScope,
                (int) $entered['supplier_account_id'],
                $entered['supplier_gstin'] ?? null,
            );
        }

        $prepared = Db::transaction(function () use ($requestId, $input, $billScope, $supply, $entered): array {
            $bill = Db::first(
                'SELECT * FROM purchase_bill_requests WHERE request_id = :id AND cmp_id = :cmp FOR UPDATE',
                ['id' => $requestId, 'cmp' => $this->ctx->cmpId],
            );
            if ($bill === null) {
                Http::notFound('That bill does not exist.');
            }
            if ($bill['status'] === 'POSTED') {
                Http::conflict('That bill has already been posted to Smart Books.');
            }
            if ($bill['status'] === 'CANCELLED') {
                Http::conflict('That bill was cancelled.');
            }
            if ($bill['po_id'] !== null) {
                $po = PoProgress::lock((int) $bill['po_id'], $this->ctx->cmpId);
                if ($po === null) {
                    Http::conflict('The purchase order behind this bill no longer exists.');
                }
                $this->assertBillable($po);
            }

            $this->assertExceptionsResolved($requestId);

            $revision = (int) $bill['revision'];
            $command = IntegrationCommand::find($this->ctx->cmpId, self::COMMAND_BILL, 'bill_request', $requestId, $revision);
            if ($command === null) {
                if ($supply === null || $revision !== (int) $entered['revision'] || ($bill['supplier_gstin'] ?? null) !== ($entered['supplier_gstin'] ?? null)) {
                    Http::conflict('This bill changed while it was being posted. Post it again.', ['request_id' => $requestId, 'retryable' => true]);
                }
                // First send of this revision: decide the stock effect and the settlements
                // now, store them, and store the body.
                $plan = $this->stockPlan($bill);
                Db::update('purchase_bill_requests', [
                    'stock_effect'      => $plan['stock_effect'],
                    'stock_settlements' => $plan['challan_settlements'],
                ], ['request_id' => $requestId]);
                $bill['stock_effect'] = $plan['stock_effect'];
                $bill['stock_settlements'] = $plan['challan_settlements'];

                $command = IntegrationCommand::ensure(
                    $billScope,
                    'books',
                    self::COMMAND_BILL,
                    'bill_request',
                    $requestId,
                    $this->buildVoucherPayload($bill, $plan, $input, $supply),
                    ['supplier_invoice_no' => $bill['supplier_invoice_no'], 'po_id' => $bill['po_id']],
                    $revision,
                );
            }

            Db::update('purchase_bill_requests', ['status' => 'POSTING', 'last_error' => null, 'updated_at' => self::now()], ['request_id' => $requestId]);

            return ['bill' => $bill, 'command' => $command];
        });

        $bill = $prepared['bill'];
        $command = $prepared['command'];
        $body = is_array($command['request_payload'] ?? null) ? $command['request_payload'] : [];
        $scope = Context::of((int) $command['cmp_id'], (int) $command['fy_id'], (int) $command['bo_id']);
        $books = (new BooksClient())->withSession($this->auth->sesKey());
        $attempt = IntegrationCommand::attempt(
            $command,
            static fn (array $payload, string $key) => $books->createAndPostVoucher($scope, BooksClient::VCH_PURCHASE, $payload, $key),
        );

        switch ($attempt['outcome']) {
            case 'completed':
                $voucher = $attempt['response']['body']['data'] ?? [];
                $voucherId = self::id($voucher['vch_txn_id'] ?? $voucher['voucher_id'] ?? null);
                if ($voucherId === null) {
                    IntegrationCommand::uncertain((int) $command['command_id'], (string) $attempt['lease'], 'Books answered without a voucher id.', (int) $attempt['response']['status']);
                    $this->markBill($requestId, 'UNCERTAIN', 'Books answered without a voucher id.');
                    Http::error(502, 'books_uncertain', 'Books did not say which voucher it posted. Retry — the same key cannot post it twice.', ['request_id' => $requestId, 'retryable' => true]);
                }
                $reference = self::voucherReference($voucher);
                $this->finalise($requestId, $reference, 'response', (int) $command['command_id'], (string) $attempt['lease'], (int) $attempt['response']['status']);
                break;

            case 'already_completed':
                // Books accepted it earlier and our side was not finished: finish it from
                // the reference the command holds.
                $this->finalise($requestId, (array) ($attempt['command']['external_reference'] ?? []), 'reconcile', (int) $command['command_id'], null, null);
                break;

            case 'withdrawn':
                Http::conflict('This bill was cancelled before it reached Smart Books.', ['request_id' => $requestId]);

            case 'in_progress':
                Http::error(409, 'bill_in_progress', 'This bill is being posted to Smart Books right now. Wait a moment and refresh.', ['request_id' => $requestId, 'retryable' => true]);

            case 'already_blocked':
            case 'blocked':
                $message = PlaceOfSupply::explainRefusal((string) ($attempt['message'] ?? 'Books refused this bill.'), $body, 'Revise the bill');
                $this->markBill($requestId, 'BLOCKED', $message);
                Http::error(409, 'books_refused', $message, ['request_id' => $requestId, 'retryable' => false, 'revise' => true]);

            case 'uncertain':
                $this->markBill($requestId, 'UNCERTAIN', (string) $attempt['message']);
                Http::error(502, 'books_uncertain', 'Smart Books did not confirm this bill. It may have been posted: Retry cannot post it twice.', ['request_id' => $requestId, 'retryable' => true, 'detail' => $attempt['message']]);

            default:
                $this->markBill($requestId, 'FAILED', (string) $attempt['message']);
                $status = (int) ($attempt['response']['status'] ?? 0);
                Http::error(
                    502,
                    'books_unavailable',
                    $status === 401
                        ? 'Smart Books did not accept your session. Sign in again, then Retry — nothing has been posted.'
                        : ($status === 403
                            ? 'Smart Books says you may not post vouchers in this company. Ask for that permission in Smart Books, then Retry — nothing has been posted.'
                            : 'Could not reach Smart Books to post this bill. Nothing has been posted — press Retry.'),
                    ['request_id' => $requestId, 'retryable' => true, 'detail' => $attempt['message']],
                );
        }

        $this->verifyWithBooks($requestId);

        Audit::record($this->ctx, $this->auth, 'bill.posted', 'bill_request', $requestId, null, [
            'books_voucher_id' => $this->find($requestId)['books_voucher_id'] ?? null,
        ]);

        return $this->find($requestId);
    }

    /**
     * Correct a bill Books refused, as a new revision: a new operation with a new key.
     *
     * Only a BLOCKED bill — one Books said no to on business grounds. A bill whose outcome
     * is merely unknown is retried on its own key, never revised: revising it could post
     * it twice.
     *
     * @param array<string, mixed> $input {lines?, supplier_invoice_date?, due_date?, note}
     */
    public function revise(int $requestId, array $input): array
    {
        Permissions::assert($this->ctx, $this->auth, 'bill.enter');

        $bill = $this->find($requestId);
        if ($bill === []) {
            Http::notFound('That bill does not exist.');
        }

        $lines = isset($input['lines']) ? $this->normaliseLines($input['lines'], $bill['po_id'] === null ? null : (int) $bill['po_id']) : Db::jsonColumn($bill['requested_lines']);
        if ($lines === []) {
            Http::validationFailed('A bill needs at least one line.', ['field' => 'lines']);
        }
        $invoiceDate = self::validDate($input['supplier_invoice_date'] ?? null) ?? (string) $bill['supplier_invoice_date'];
        $dueDate = array_key_exists('due_date', $input) ? self::validDate($input['due_date']) : ($bill['due_date'] ?? null);
        if ($dueDate !== null && $dueDate < $invoiceDate) {
            Http::validationFailed('The due date cannot be before the supplier invoice date.', ['field' => 'due_date']);
        }
        // Judged against the year the bill belongs to, as confirmed by Manage — not the year the
        // screen is on, which a revision does not move the bill into.
        $postingDate = $this->postingDate($input['posting_date'] ?? ($bill['posting_date'] ?? null), $invoiceDate, $this->scopeOf($bill));
        $supplierGstin = array_key_exists('supplier_gstin', $input)
            ? PlaceOfSupply::normaliseGstin($input['supplier_gstin'], 'supplier_gstin')
            : ($bill['supplier_gstin'] ?? null);

        $revision = Db::transaction(function () use ($requestId, $lines, $invoiceDate, $dueDate, $postingDate, $supplierGstin): int {
            $locked = Db::first(
                'SELECT * FROM purchase_bill_requests WHERE request_id = :id AND cmp_id = :cmp FOR UPDATE',
                ['id' => $requestId, 'cmp' => $this->ctx->cmpId],
            );
            if ($locked['status'] !== 'BLOCKED') {
                Http::conflict('Only a bill Smart Books refused can be revised; retry anything else as it stands.');
            }
            $from = (int) $locked['revision'];
            // The refused revision is finished with: it leaves the strip of open work, and
            // can never be sent again under its old key.
            $command = IntegrationCommand::find($this->ctx->cmpId, self::COMMAND_BILL, 'bill_request', $requestId, $from);
            if ($command !== null) {
                IntegrationCommand::withdraw((int) $command['command_id'], 'Superseded by revision ' . ($from + 1) . '.', 'superseded');
            }
            Db::update('purchase_bill_requests', [
                'revision'              => $from + 1,
                'requested_lines'       => $lines,
                'supplier_invoice_date' => $invoiceDate,
                'supplier_gstin'        => $supplierGstin,
                'posting_date'          => $postingDate === $invoiceDate ? null : $postingDate,
                'due_date'              => $dueDate,
                'stock_effect'          => null,
                'stock_settlements'     => null,
                'status'                => 'MATCHING',
                'last_error'            => null,
                'updated_at'            => self::now(),
            ], ['request_id' => $requestId, 'cmp_id' => $this->ctx->cmpId]);

            return $from + 1;
        });

        Audit::record($this->ctx, $this->auth, 'bill.revised', 'bill_request', $requestId, ['revision' => $revision - 1], ['revision' => $revision], self::text($input['note'] ?? null) ?? '');

        return $this->rematch($requestId);
    }

    /**
     * Withdraw a bill that has not reached Books.
     *
     * @param array<string, mixed> $input {reason}
     */
    public function cancel(int $requestId, array $input): array
    {
        Permissions::assert($this->ctx, $this->auth, 'bill.enter');

        $reason = self::text($input['reason'] ?? null);
        if ($reason === null) {
            Http::validationFailed('Say why this bill is being cancelled.', ['field' => 'reason']);
        }

        Db::transaction(function () use ($requestId, $reason): void {
            $bill = Db::first(
                'SELECT * FROM purchase_bill_requests WHERE request_id = :id AND cmp_id = :cmp FOR UPDATE',
                ['id' => $requestId, 'cmp' => $this->ctx->cmpId],
            );
            if ($bill === null) {
                Http::notFound('That bill does not exist.');
            }
            if ($bill['status'] === 'POSTED') {
                Http::conflict('This bill is posted in Smart Books. A correction there is a debit note, not a cancellation here.');
            }
            if ($bill['status'] === 'CANCELLED') {
                return;
            }
            // Withdrawn atomically, so a Post pressed at this moment cannot claim the
            // command after this check and send a cancelled bill to Books.
            $command = IntegrationCommand::latest($this->ctx->cmpId, self::COMMAND_BILL, 'bill_request', $requestId);
            if ($command !== null && $command['status'] !== IntegrationCommand::CANCELLED
                && !IntegrationCommand::withdraw((int) $command['command_id'], 'Bill cancelled: ' . $reason)) {
                Http::conflict('Smart Books may already hold this bill. Retry it to learn the outcome before cancelling.');
            }
            Db::update('purchase_bill_requests', [
                'status' => 'CANCELLED', 'cancelled_at' => self::now(), 'cancel_reason' => $reason, 'updated_at' => self::now(),
            ], ['request_id' => $requestId]);
            if ($bill['po_id'] !== null) {
                PoProgress::recompute((int) $bill['po_id'], $this->ctx->cmpId, $this->auth->uuid);
            }
        });

        Audit::record($this->ctx, $this->auth, 'bill.cancelled', 'bill_request', $requestId, null, ['status' => 'CANCELLED'], $reason);

        return $this->find($requestId);
    }

    /** @param array<string, mixed> $po locked order row */
    private function assertBillable(array $po): void
    {
        if (in_array($po['status'], self::BILLABLE_PO, true)) {
            return;
        }
        Http::conflict(match ((string) $po['status']) {
            'CANCELLED' => 'This purchase order was cancelled, so it cannot be billed.',
            'CLOSED' => 'This purchase order is closed. A bill for more goods needs a new order.',
            'DRAFT', 'APPROVAL_PENDING' => 'This purchase order has not been approved, so it cannot be billed yet.',
            default => 'This purchase order cannot be billed in its current state.',
        }, ['po_status' => $po['status']]);
    }

    private function assertExceptionsResolved(int $requestId): void
    {
        $latestMatch = Db::first(
            'SELECT * FROM purchase_match_results WHERE bill_request_id = :id ORDER BY match_id DESC LIMIT 1',
            ['id' => $requestId],
        );

        // Read as a row, not a scalar: Db::scalar() maps fetchColumn()'s false to null (its "no
        // row"), so a setting stored as FALSE came back null, "?? true" made it true, and turning
        // this setting off had no effect at all.
        $settings = Db::first(
            'SELECT block_bill_on_match_failure FROM purchase_settings WHERE cmp_id = :cmp',
            ['cmp' => $this->ctx->cmpId],
        );
        $blockOnFailure = $settings === null || (bool) $settings['block_bill_on_match_failure'];

        if ($latestMatch === null || !$blockOnFailure) {
            return;
        }
        $openExceptions = (int) Db::scalar(
            "SELECT COUNT(*) FROM purchase_match_exceptions WHERE match_id = :id AND status = 'OPEN'",
            ['id' => (int) $latestMatch['match_id']],
        );
        if ($openExceptions > 0) {
            Http::error(
                409,
                'match_exception_open',
                sprintf(
                    'This bill has %d unresolved match exception%s. Resolve %s before posting it.',
                    $openExceptions,
                    $openExceptions === 1 ? '' : 's',
                    $openExceptions === 1 ? 'it' : 'them',
                ),
                ['match_id' => (int) $latestMatch['match_id'], 'open_exceptions' => $openExceptions],
            );
        }
    }

    /**
     * Mark the bill POSTED and count its lines against the order — once.
     *
     * @param array<string, mixed> $reference what Books called the voucher
     */
    private function finalise(int $requestId, array $reference, string $resolvedBy, int $commandId, ?string $lease, ?int $status): void
    {
        Db::transaction(function () use ($requestId, $reference, $resolvedBy, $commandId, $lease, $status): void {
            $bill = Db::first(
                'SELECT * FROM purchase_bill_requests WHERE request_id = :id AND cmp_id = :cmp FOR UPDATE',
                ['id' => $requestId, 'cmp' => $this->ctx->cmpId],
            );
            if ($bill === null || $bill['status'] === 'POSTED') {
                return;
            }
            if ($bill['po_id'] !== null) {
                PoProgress::lock((int) $bill['po_id'], $this->ctx->cmpId);
            }

            Db::update('purchase_bill_requests', [
                'status'             => 'POSTED',
                'books_voucher_id'   => self::id($reference['books_voucher_id'] ?? null),
                'books_voucher_uuid' => self::text((string) ($reference['books_voucher_uuid'] ?? '')),
                'books_voucher_no'   => self::text((string) ($reference['books_voucher_no'] ?? '')),
                'posted_at'          => self::now(),
                'posted_by'          => $this->auth->uuid,
                'last_error'         => null,
                'updated_at'         => self::now(),
            ], ['request_id' => $requestId]);

            foreach (Db::jsonColumn($bill['requested_lines']) as $line) {
                $poLineId = (int) ($line['po_line_id'] ?? 0);
                if ($poLineId > 0 && $bill['po_id'] !== null) {
                    Db::run(
                        'UPDATE purchase_order_lines SET billed_qty = billed_qty + :qty, updated_at = NOW()
                         WHERE line_id = :id AND po_id = :po AND cmp_id = :cmp',
                        ['qty' => (float) ($line['qty'] ?? 0), 'id' => $poLineId, 'po' => (int) $bill['po_id'], 'cmp' => $this->ctx->cmpId],
                    );
                }
            }

            if ($bill['po_id'] !== null) {
                PoProgress::recompute((int) $bill['po_id'], $this->ctx->cmpId, $this->auth->uuid);
            }

            if ($lease !== null) {
                IntegrationCommand::complete($commandId, $lease, $reference, $resolvedBy, $status);
            } else {
                IntegrationCommand::completeByReconcile($commandId, $reference);
            }
        });
    }

    /**
     * Read the posted voucher back from Books and check it says what this bill says.
     *
     * Balanced; the supplier credited; the supplier's invoice as the bill reference; the
     * value Books composed the same as the bill's. A voucher that fails any of these was
     * still posted — Books owns it now — so the finding is recorded on the bill for a
     * person, not hidden and not "fixed" by posting something else.
     */
    public function verifyWithBooks(int $requestId): array
    {
        $bill = $this->find($requestId);
        if ($bill === [] || $bill['books_voucher_id'] === null) {
            return [];
        }

        $response = (new BooksClient())->withSession($this->auth->sesKey())->voucher($this->scopeOf($bill), (int) $bill['books_voucher_id']);
        $problems = [];
        $voucher = $response['ok'] ? ($response['body']['data'] ?? null) : null;
        if (!is_array($voucher)) {
            $verification = ['verified' => null, 'checked_at' => gmdate('c'), 'problems' => ['Smart Books could not be asked for the posted voucher; it was not checked.']];
        } else {
            $sent = IntegrationCommand::latest($this->ctx->cmpId, self::COMMAND_BILL, 'bill_request', $requestId);
            $problems = self::voucherProblems($bill, $voucher, is_array($sent['request_payload'] ?? null) ? $sent['request_payload'] : []);
            // The verdict and its reasons. The voucher itself stays in Books and is read
            // there when somebody wants to see it.
            $verification = [
                'verified'   => $problems === [],
                'checked_at' => gmdate('c'),
                'problems'   => $problems,
            ];
        }

        Db::update('purchase_bill_requests', ['posting_check' => $verification], ['request_id' => $requestId, 'cmp_id' => $this->ctx->cmpId]);

        return $verification;
    }

    /**
     * @param array<string, mixed> $bill
     * @param array<string, mixed> $voucher Books' posted voucher (GET vouchers/{id})
     * @return list<string>
     */
    public static function voucherProblems(array $bill, array $voucher, array $sent = []): array
    {
        $problems = [];
        $supplier = (int) $bill['supplier_account_id'];
        $lines = is_array($voucher['lines'] ?? null) ? $voucher['lines'] : [];

        if ($lines === []) {
            return ['Smart Books recorded no ledger lines for this bill: nothing is payable.'];
        }

        $dr = 0.0;
        $cr = 0.0;
        $supplierCredit = 0.0;
        foreach ($lines as $line) {
            $amount = (float) ($line['amount'] ?? 0);
            if ((int) ($line['dr_cr'] ?? 1) === 2) {
                $cr += $amount;
                if ((int) ($line['acc_id'] ?? 0) === $supplier) {
                    $supplierCredit += $amount;
                }
            } else {
                $dr += $amount;
            }
        }
        if (abs($dr - $cr) > 0.01) {
            $problems[] = sprintf('The voucher does not balance: debits %.2f, credits %.2f.', $dr, $cr);
        }
        if ($supplierCredit <= 0.0) {
            $problems[] = 'The supplier\'s account is not credited: Smart Books shows nothing payable to them.';
        }

        $partyId = (int) ($voucher['party']['acc_id'] ?? 0);
        if ($partyId > 0 && $partyId !== $supplier) {
            $problems[] = sprintf('Smart Books names account %d as the party, not the supplier (%d).', $partyId, $supplier);
        }

        $billRef = trim((string) ($voucher['bill']['bill_ref'] ?? ''));
        if (strcasecmp($billRef, trim((string) $bill['supplier_invoice_no'])) !== 0) {
            $problems[] = sprintf('The bill reference in Smart Books is "%s", not the supplier\'s invoice "%s".', $billRef, $bill['supplier_invoice_no']);
        }

        $subtotal = 0.0;
        foreach (Db::jsonColumn($bill['requested_lines']) as $line) {
            $subtotal += (float) ($line['amount'] ?? 0);
        }
        $taxable = $voucher['tax_summary']['taxable_value'] ?? null;
        if ($taxable !== null && abs((float) $taxable - $subtotal) > 1.0) {
            $problems[] = sprintf('Smart Books valued the bill at %.2f before tax; this bill says %.2f.', (float) $taxable, $subtotal);
        }
        $grand = $voucher['tax_summary']['grand_total'] ?? null;
        if ($grand !== null && $supplierCredit > 0 && abs((float) $grand - $supplierCredit) > 1.0) {
            $problems[] = sprintf('The supplier is credited %.2f against a voucher total of %.2f.', $supplierCredit, (float) $grand);
        }

        // The tax, where what was sent decides it. Books owns the GST; these say when what it
        // booked cannot be right for what this bill told it.
        $tax = is_array($voucher['tax_summary'] ?? null) ? $voucher['tax_summary'] : null;
        if ($tax !== null && $sent !== []) {
            $cgstSgst = abs((float) ($tax['cgst'] ?? 0)) + abs((float) ($tax['sgst'] ?? 0));
            $anyGst = $cgstSgst + abs((float) ($tax['igst'] ?? 0));
            if (($sent['supply_nature'] ?? null) === PlaceOfSupply::SEZ && $cgstSgst > 0.005) {
                $problems[] = 'The supplier is an SEZ unit: a supply from an SEZ is inter-state (IGST) even within the state, but Smart Books booked CGST and SGST. Have the voucher corrected in Smart Books.';
            }
            $categorised = array_filter(Db::jsonColumn($bill['requested_lines']), static fn (array $l) => !empty($l['tax_cat_id']));
            if ($anyGst <= 0.005 && $categorised !== [] && trim((string) ($sent['party']['pos_state_code'] ?? '')) === '') {
                $problems[] = 'Smart Books booked no GST on this bill, and Purchase could not tell it where the supplier supplies from (their ledger has no GSTIN or state). Check the voucher\'s tax in Smart Books.';
            }
        }

        return $problems;
    }

    private function markBill(int $requestId, string $status, string $message): void
    {
        Db::run(
            "UPDATE purchase_bill_requests SET status = :status, last_error = :err, updated_at = NOW()
              WHERE request_id = :id AND cmp_id = :cmp AND status NOT IN ('POSTED', 'CANCELLED')",
            ['status' => $status, 'err' => mb_substr($message, 0, 480), 'id' => $requestId, 'cmp' => $this->ctx->cmpId],
        );
    }

    /** @param array<string, mixed> $voucher */
    private static function voucherReference(array $voucher): array
    {
        return array_filter([
            'books_voucher_id'   => $voucher['vch_txn_id'] ?? $voucher['voucher_id'] ?? null,
            'books_voucher_uuid' => $voucher['vch_uuid'] ?? $voucher['voucher_uuid'] ?? null,
            'books_voucher_no'   => $voucher['vch_no'] ?? $voucher['vch_number'] ?? $voucher['voucher_no'] ?? null,
            'stock'              => is_array($voucher['stock'] ?? null) ? $voucher['stock'] : null,
        ], static fn ($v) => $v !== null);
    }

    /** Accept or reject a match exception, with a reason. */
    public function resolveException(int $exceptionId, string $action, array $input): array
    {
        Permissions::assert($this->ctx, $this->auth, 'match.resolve');

        $exception = Db::first(
            'SELECT e.*, m.bill_request_id FROM purchase_match_exceptions e
             JOIN purchase_match_results m ON m.match_id = e.match_id
             WHERE e.exception_id = :id AND e.cmp_id = :cmp',
            ['id' => $exceptionId, 'cmp' => $this->ctx->cmpId],
        );
        if ($exception === null) {
            Http::notFound('That match exception does not exist.');
        }
        if ($exception['status'] !== 'OPEN') {
            Http::conflict('That exception has already been decided.');
        }

        $note = self::text($input['note'] ?? null);
        if ($note === null) {
            // Accepting a variance costs the company money. Who accepted it and
            // why is the whole audit value of this control.
            Http::validationFailed('Say why this variance is being accepted or rejected.', ['field' => 'note']);
        }

        Db::update('purchase_match_exceptions', [
            'status'        => $action === 'accept' ? 'ACCEPTED' : 'REJECTED',
            'decided_by'    => $this->auth->uuid,
            'decided_at'    => self::now(),
            'decision_note' => $note,
        ], ['exception_id' => $exceptionId, 'cmp_id' => $this->ctx->cmpId]);

        Audit::record($this->ctx, $this->auth, 'match.' . $action, 'match_exception', $exceptionId, ['status' => 'OPEN'], [
            'status' => $action === 'accept' ? 'ACCEPTED' : 'REJECTED',
        ], $note);

        return $this->find((int) $exception['bill_request_id']);
    }

    /** Re-run the match, for instance after a late receipt was recorded. */
    public function rematch(int $requestId): array
    {
        Permissions::assert($this->ctx, $this->auth, 'match.view');

        $bill = $this->find($requestId);
        if ($bill === []) {
            Http::notFound('That bill does not exist.');
        }
        if ($bill['status'] === 'POSTED') {
            Http::conflict('That bill has been posted; matching it again would change nothing.');
        }

        $match = (new ThreeWayMatchService($this->ctx, $this->auth))->run($bill);

        Db::update('purchase_bill_requests', [
            'status'     => in_array($match['verdict'], [ThreeWayMatchService::BLOCKED, ThreeWayMatchService::REVIEW_REQUIRED], true)
                ? 'EXCEPTION'
                : 'MATCHED',
            'updated_at' => self::now(),
        ], ['request_id' => $requestId]);

        $result = $this->find($requestId);
        $result['match'] = $match;

        return $result;
    }

    /** @return array<string, mixed> */
    public function find(int $requestId): array
    {
        $row = Db::first(
            'SELECT * FROM purchase_bill_requests WHERE request_id = :id AND cmp_id = :cmp',
            ['id' => $requestId, 'cmp' => $this->ctx->cmpId],
        );
        if ($row === null) {
            return [];
        }

        $row['matches'] = Db::all(
            'SELECT * FROM purchase_match_results WHERE bill_request_id = :id ORDER BY match_id DESC',
            ['id' => $requestId],
        );
        foreach ($row['matches'] as $index => $match) {
            $row['matches'][$index]['exceptions'] = Db::all(
                'SELECT * FROM purchase_match_exceptions WHERE match_id = :id ORDER BY exception_id',
                ['id' => (int) $match['match_id']],
            );
        }
        $row['commands'] = IntegrationCommand::forEntity($this->ctx, 'bill_request', $requestId);
        foreach (['posting_check', 'stock_settlements'] as $column) {
            $row[$column] = $row[$column] === null ? null : Db::jsonColumn($row[$column]);
        }

        return $row;
    }

    /** @return array{rows:list<array<string, mixed>>, total:int} */
    public function search(array $filters, int $limit, int $offset, string $sort, string $order): array
    {
        [$scope, $params] = $this->ctx->scopeClause('b');
        $where = [$scope];

        if (!empty($filters['status'])) {
            $where[] = 'b.status = :status';
            $params['status'] = (string) $filters['status'];
        }
        if (!empty($filters['supplier_account_id'])) {
            $where[] = 'b.supplier_account_id = :supplier';
            $params['supplier'] = (int) $filters['supplier_account_id'];
        }
        if (!empty($filters['exceptions_only'])) {
            $where[] = "b.status = 'EXCEPTION'";
        }
        if (!empty($filters['q'])) {
            $where[] = 'b.supplier_invoice_no ILIKE :term';
            $params['term'] = '%' . $filters['q'] . '%';
        }

        $clause = implode(' AND ', $where);
        $sortColumn = in_array($sort, ['supplier_invoice_date', 'supplier_invoice_no', 'status', 'created_at'], true) ? $sort : 'created_at';

        return [
            'rows'  => Db::all("SELECT b.* FROM purchase_bill_requests b WHERE {$clause} ORDER BY b.{$sortColumn} {$order}, b.request_id {$order} LIMIT {$limit} OFFSET {$offset}", $params),
            'total' => (int) Db::scalar("SELECT COUNT(*) FROM purchase_bill_requests b WHERE {$clause}", $params),
        ];
    }

    /**
     * The payables workspace list.
     *
     * `search()` above answers "which bill requests exist" and is what the
     * plain bills screen wants. This answers a different question — what is
     * owed, on what, and what is holding it up — and so it carries the figures
     * the workspace shows in a row: the bill's own value, the supplier behind
     * the account id, how many exceptions are open against it and whether it
     * looks like an earlier bill.
     *
     * Three things are deliberately NOT here, because this application does not
     * know them:
     *
     *  - TAX. Lines carry a tax category, never a rate. Books computes the GST
     *    when it posts the voucher, and a second tax engine here would
     *    eventually disagree with the one that files the return. The row says
     *    the value is exclusive of tax rather than inventing a gross figure.
     *  - THE DUE DATE. It is Books', per bill, and Books answers open items one
     *    supplier at a time. The dashboard reads them for the suppliers it can
     *    and the workspace merges them onto these rows; a bill outside that set
     *    says so instead of guessing from the invoice date.
     *  - WHETHER IT IS PAID. Same reason. A bill posted from here is a voucher
     *    in Books, and only Books knows what has been settled against it.
     *
     * @param array<string, mixed> $filters
     * @return array{rows: list<array<string, mixed>>, total: int, counts: array<string, int>}
     */
    public function payables(array $filters, int $limit, int $offset, string $sort, string $order): array
    {
        Permissions::assert($this->ctx, $this->auth, 'bill.enter');

        [$scope, $params] = $this->ctx->scopeClause('b');
        $where = [$scope];

        // The workspace tabs. Each is a real state of a real bill, not a label
        // invented to fill a pill: a tab nobody can reach is worse than a tab
        // that is not there.
        $tab = (string) ($filters['tab'] ?? 'all');
        switch ($tab) {
            case 'awaiting_review':
                $where[] = "b.status IN ('DRAFT', 'MATCHING')";
                break;
            case 'exceptions':
                $where[] = "EXISTS (SELECT 1 FROM purchase_match_results m
                                     JOIN purchase_match_exceptions e ON e.match_id = m.match_id
                                    WHERE m.bill_request_id = b.request_id AND e.status = 'OPEN')";
                break;
            case 'ready_to_post':
                $where[] = "b.status = 'MATCHED'";
                break;
            case 'posted':
                $where[] = "b.status = 'POSTED'";
                break;
            case 'failed':
                $where[] = "b.status IN ('FAILED', 'EXCEPTION')";
                break;
            case 'duplicates':
                // `o.request_id < b.request_id` — the LATER bill of a pair, not
                // both of them. It is the rule the "Possible duplicate bills"
                // card counts by, and the two have to be the same rule or the
                // card says one and the tab it opens says two.
                $where[] = self::DUPLICATE_CLAUSE;
                break;
            default:
                $tab = 'all';
        }

        if (!empty($filters['status'])) {
            $where[] = 'b.status = :status';
            $params['status'] = (string) $filters['status'];
        }
        if (!empty($filters['supplier_account_id'])) {
            $where[] = 'b.supplier_account_id = :supplier';
            $params['supplier'] = (int) $filters['supplier_account_id'];
        }
        if (!empty($filters['po_id'])) {
            $where[] = 'b.po_id = :po';
            $params['po'] = (int) $filters['po_id'];
        }
        if (!empty($filters['from'])) {
            $where[] = 'b.supplier_invoice_date >= :from_date';
            $params['from_date'] = (string) $filters['from'];
        }
        if (!empty($filters['to'])) {
            $where[] = 'b.supplier_invoice_date <= :to_date';
            $params['to_date'] = (string) $filters['to'];
        }
        if (!empty($filters['q'])) {
            // The invoice number, the order it is against and the supplier name
            // frozen on that order — the three things somebody actually types.
            $where[] = '(b.supplier_invoice_no ILIKE :term
                         OR p.po_no ILIKE :term
                         OR p.supplier_name_snapshot ILIKE :term)';
            $params['term'] = '%' . $filters['q'] . '%';
        }

        $clause = implode(' AND ', $where);

        $sortable = [
            'invoice_date' => 'b.supplier_invoice_date',
            'invoice_no'   => 'b.supplier_invoice_no',
            'status'       => 'b.status',
            'created_at'   => 'b.created_at',
            'amount'       => 'value.subtotal',
            'supplier'     => 'supplier_name',
        ];
        $sortColumn = $sortable[$sort] ?? 'b.created_at';

        // The joins are shared by the page, the count and the tab counts, so
        // the three can never disagree about what a bill is.
        $from = "FROM purchase_bill_requests b
                 LEFT JOIN purchase_orders p ON p.po_id = b.po_id
                 LEFT JOIN LATERAL (
                     SELECT COUNT(*) AS line_count,
                            COALESCE(SUM(NULLIF(line->>'amount', '')::numeric), 0) AS subtotal
                     FROM jsonb_array_elements(b.requested_lines) AS line
                 ) value ON TRUE
                 LEFT JOIN LATERAL (
                     SELECT COUNT(*) AS open_exceptions
                     FROM purchase_match_exceptions e
                     JOIN purchase_match_results m ON m.match_id = e.match_id
                     WHERE m.bill_request_id = b.request_id AND e.status = 'OPEN'
                 ) exceptions ON TRUE";

        $rows = Db::all(
            "SELECT b.request_id, b.po_id, b.supplier_account_id, b.supplier_invoice_no,
                    b.supplier_invoice_date, b.status, b.books_voucher_no, b.books_voucher_uuid,
                    b.last_error, b.created_at, b.requested_by,
                    p.po_no, p.currency_code, p.payment_terms,
                    COALESCE(p.supplier_name_snapshot, latest.supplier_name) AS supplier_name,
                    value.line_count, value.subtotal::text AS subtotal,
                    exceptions.open_exceptions,
                    duplicate.other_id AS duplicate_of, duplicate.other_no AS duplicate_of_no
             {$from}
             LEFT JOIN LATERAL (
                 SELECT o.supplier_name_snapshot AS supplier_name
                 FROM purchase_orders o
                 WHERE o.cmp_id = b.cmp_id AND o.supplier_account_id = b.supplier_account_id
                   AND o.supplier_name_snapshot IS NOT NULL
                 ORDER BY o.po_date DESC, o.po_id DESC LIMIT 1
             ) latest ON TRUE
             LEFT JOIN LATERAL (
                 SELECT o.request_id AS other_id, o.supplier_invoice_no AS other_no
                 FROM purchase_bill_requests o
                 WHERE o.cmp_id = b.cmp_id
                   AND o.supplier_account_id = b.supplier_account_id
                   AND o.supplier_invoice_date = b.supplier_invoice_date
                   AND o.request_id < b.request_id
                   AND o.status <> 'CANCELLED'
                 ORDER BY o.request_id DESC LIMIT 1
             ) duplicate ON TRUE
             WHERE {$clause}
             ORDER BY {$sortColumn} {$order} NULLS LAST, b.request_id {$order}
             LIMIT {$limit} OFFSET {$offset}",
            $params,
        );

        $total = (int) Db::scalar("SELECT COUNT(*) {$from} WHERE {$clause}", $params);

        return [
            'rows'   => array_map(fn (array $row) => $this->payableRow($row), $rows),
            'total'  => $total,
            'tab'    => $tab,
            'counts' => $this->payableTabCounts($filters),
        ];
    }

    /**
     * One row of the workspace, with the permissions that decide its actions.
     *
     * `can_*` is a convenience for the interface, never the control: every one
     * of these actions asserts the same permission again on the way in, so a
     * row that arrives with a flag flipped by hand still cannot do anything.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function payableRow(array $row): array
    {
        $status = (string) $row['status'];
        $openExceptions = (int) ($row['open_exceptions'] ?? 0);
        $posted = $status === 'POSTED';

        return [
            'request_id'          => (int) $row['request_id'],
            'invoice_no'          => $row['supplier_invoice_no'],
            'invoice_date'        => $row['supplier_invoice_date'],
            'supplier_account_id' => (int) $row['supplier_account_id'],
            'supplier_name'       => $row['supplier_name'],
            'po_id'               => $row['po_id'] === null ? null : (int) $row['po_id'],
            'po_no'               => $row['po_no'],
            'payment_terms'       => $row['payment_terms'],
            'status'              => $status,
            'line_count'          => (int) ($row['line_count'] ?? 0),
            'currency'            => (string) ($row['currency_code'] ?? 'INR'),
            // Exclusive of tax, and labelled as such. See payables() above.
            'subtotal'            => (string) ($row['subtotal'] ?? '0'),
            'tax_basis'           => 'Books computes the tax when the bill is posted. This value is exclusive of it.',
            'open_exceptions'     => $openExceptions,
            'duplicate_of'        => $row['duplicate_of'] === null ? null : (int) $row['duplicate_of'],
            'duplicate_of_no'     => $row['duplicate_of_no'],
            'books_voucher_no'    => $row['books_voucher_no'],
            'last_error'          => $row['last_error'],
            'entered_by'          => $row['requested_by'],
            'entered_at'          => $row['created_at'],
            'route'               => '/bills/' . (int) $row['request_id'],
            'can_edit'            => !$posted && Permissions::allows($this->ctx, $this->auth, 'bill.enter'),
            'can_rematch'         => !$posted && Permissions::allows($this->ctx, $this->auth, 'bill.enter'),
            'can_resolve'         => $openExceptions > 0 && Permissions::allows($this->ctx, $this->auth, 'match.resolve'),
            'can_post'            => $status === 'MATCHED' && Permissions::allows($this->ctx, $this->auth, 'bill.post'),
        ];
    }

    /**
     * How many bills each tab holds, counted once for the whole strip.
     *
     * Counted WITHOUT the tab filter and with every other filter applied, which
     * is what makes the numbers on the unselected tabs mean anything: they say
     * how many rows a click would produce, not how many the current tab has.
     *
     * @param array<string, mixed> $filters
     * @return array<string, int>
     */
    private function payableTabCounts(array $filters): array
    {
        [$scope, $params] = $this->ctx->scopeClause('b');
        $where = [$scope];

        if (!empty($filters['supplier_account_id'])) {
            $where[] = 'b.supplier_account_id = :supplier';
            $params['supplier'] = (int) $filters['supplier_account_id'];
        }
        if (!empty($filters['from'])) {
            $where[] = 'b.supplier_invoice_date >= :from_date';
            $params['from_date'] = (string) $filters['from'];
        }
        if (!empty($filters['to'])) {
            $where[] = 'b.supplier_invoice_date <= :to_date';
            $params['to_date'] = (string) $filters['to'];
        }

        $clause = implode(' AND ', $where);

        $row = Db::first(
            "SELECT COUNT(*) AS all_bills,
                    COUNT(*) FILTER (WHERE b.status IN ('DRAFT', 'MATCHING')) AS awaiting_review,
                    COUNT(*) FILTER (WHERE b.status = 'MATCHED') AS ready_to_post,
                    COUNT(*) FILTER (WHERE b.status = 'POSTED') AS posted,
                    COUNT(*) FILTER (WHERE b.status IN ('FAILED', 'EXCEPTION')) AS failed,
                    COUNT(*) FILTER (WHERE EXISTS (
                        SELECT 1 FROM purchase_match_results m
                          JOIN purchase_match_exceptions e ON e.match_id = m.match_id
                         WHERE m.bill_request_id = b.request_id AND e.status = 'OPEN')) AS exceptions,
                    COUNT(*) FILTER (WHERE " . self::DUPLICATE_CLAUSE . ") AS duplicates
             FROM purchase_bill_requests b
             WHERE {$clause}",
            $params,
        ) ?? [];

        return [
            'all'             => (int) ($row['all_bills'] ?? 0),
            'awaiting_review' => (int) ($row['awaiting_review'] ?? 0),
            'exceptions'      => (int) ($row['exceptions'] ?? 0),
            'ready_to_post'   => (int) ($row['ready_to_post'] ?? 0),
            'posted'          => (int) ($row['posted'] ?? 0),
            'failed'          => (int) ($row['failed'] ?? 0),
            'duplicates'      => (int) ($row['duplicates'] ?? 0),
        ];
    }

    /**
     * The company, year and branch a bill was entered in — the scope it is posted, read back and
     * revised in. The same company as the request's (find() is company-scoped); the year and
     * branch are confirmed with Manage for this person when they differ from the request's, as the
     * request's own are, so a stored id is never trusted unchecked either.
     *
     * @param array<string, mixed> $bill
     */
    private function scopeOf(array $bill): Context
    {
        $scope = Context::of((int) $bill['cmp_id'], (int) $bill['fy_id'], (int) $bill['bo_id']);
        if ($scope->cmpId !== $this->ctx->cmpId) {
            Http::notFound('That bill does not exist.');
        }
        if ($scope->fyId !== $this->ctx->fyId || $scope->boId !== $this->ctx->boId || $scope->fyRange() === null) {
            $scope->assertAllowed($this->auth);
        }

        return $scope;
    }

    // -----------------------------------------------------------------------

    /**
     * The Books voucher payload — the shape Books composes a purchase from.
     *
     * No tax amount, no input-credit decision, no TDS: Books computes all of it. It is the
     * product that files the return, and a second tax engine would eventually disagree
     * with the one that matters. What is sent is what only this bill knows: the supplier
     * (party.acc_id), the supplier's own invoice (bill.bill_ref — kept apart from the
     * voucher number Books will assign), its date and due date, the lines with their tax
     * categories, and how the goods reach stock.
     *
     * @param array<string, mixed> $bill
     * @param array{stock_effect: ?string, challan_settlements: list<array<string, mixed>>} $plan
     * @return array<string, mixed>
     */
    private function buildVoucherPayload(array $bill, array $plan, array $input, ?array $supply = null): array
    {
        $inventoryLines = [];
        $serviceLines = [];

        // What each goods line settles, by the bill line it belongs to.
        $settledBy = [];
        foreach ($plan['challan_settlements'] as $settlement) {
            if (isset($settlement['bill_line_no'])) {
                $settledBy[(int) $settlement['bill_line_no']][] = $settlement;
            }
        }

        foreach (Db::jsonColumn($bill['requested_lines']) as $line) {
            if (!empty($line['is_service']) || empty($line['item_id'])) {
                $serviceLines[] = array_filter([
                    'description'     => $line['description'] ?? 'Service',
                    // The ledger this expense is booked to. Books composes a service line
                    // from it; without it the line would post to no account.
                    'purchase_acc_id' => isset($line['purchase_acc_id']) ? (int) $line['purchase_acc_id'] : null,
                    'amount'          => (float) ($line['amount'] ?? 0),
                    'tax_cat_id'      => $line['tax_cat_id'] ?? null,
                    'hsn_sac'         => $line['hsn_sac'] ?? null,
                    'source_line_ref' => isset($line['po_line_id']) ? (int) $line['po_line_id'] : null,
                ], static fn ($v) => $v !== null);
                continue;
            }
            // One Books item line per warehouse the goods are in: where its GRNs put them, for a
            // line that settles GRNs (one warehouse almost always); the line's own otherwise.
            foreach (self::warehousePieces($line, $settledBy[(int) ($line['line_no'] ?? 0)] ?? []) as [$warehouseId, $pieceQty, $pieceAmount]) {
                $inventoryLines[] = array_filter([
                    'source_line_ref' => isset($line['po_line_id']) ? (int) $line['po_line_id'] : null,
                    'item_id'         => (int) $line['item_id'],
                    'unit_id'         => $line['unit_id'] ?? null,
                    'mc_id'           => $warehouseId,
                    // A purchase line is on the debit side in Books.
                    'dr_cr'           => 1,
                    'qty'             => $pieceQty,
                    'rate'            => (float) ($line['rate'] ?? 0),
                    'discount_pc'     => (float) ($line['discount_pc'] ?? 0),
                    'amount'          => $pieceAmount,
                    'tax_cat_id'      => $line['tax_cat_id'] ?? null,
                    'hsn_sac'         => $line['hsn_sac'] ?? null,
                    'description'     => $line['description'] ?? null,
                ], static fn ($v) => $v !== null);
            }
        }

        $po = $bill['po_id'] === null ? null : Db::first('SELECT po_no, po_date FROM purchase_orders WHERE po_id = :id', ['id' => (int) $bill['po_id']]);

        $payload = [
            // Booked on its posting date; the supplier's own date travels as the bill's date.
            'vch_date'        => (string) ($bill['posting_date'] ?? $bill['supplier_invoice_date']),
            // Books composes a bill from party.acc_id and its lines; a flat party_acc_id is
            // never read, and a bill sent that way posted with nothing on it. pos_state_code is
            // where the supplier supplies from (PlaceOfSupply): without it Books cannot split
            // the GST, and refuses a GST-categorised bill rather than book it with none.
            'party'           => array_filter([
                'acc_id'         => (int) $bill['supplier_account_id'],
                'pos_state_code' => $supply['pos_state_code'] ?? null,
            ], static fn ($v) => $v !== null),
            // The supplier's invoice is the bill Books tracks the payable against — on the
            // CREDIT side (dr_cr 2): Books defaults a named bill to the debit side.
            'bill'            => array_filter([
                'bill_ref'  => self::text($bill['supplier_invoice_no'] ?? null),
                'bill_date' => self::text($bill['supplier_invoice_date'] ?? null),
                'due_date'  => self::text($bill['due_date'] ?? null),
                'dr_cr'     => 2,
            ], static fn ($v) => $v !== null),
            'narration'       => self::text($input['narration'] ?? null)
                ?? ('Supplier bill ' . $bill['supplier_invoice_no'] . ($po !== null ? ' against ' . $po['po_no'] : '')),
            'reference_no'    => (string) $bill['supplier_invoice_no'],
            'reference_date'  => (string) $bill['supplier_invoice_date'],
            'bo_id'           => (int) $bill['bo_id'],

            // Where this came from, so anyone auditing can walk back to our bill and order
            // without us copying anything of Books'.
            'source_app'           => 'purchases',
            'source_document_type' => 'purchases.bill',
            'source_document_id'   => (int) $bill['request_id'],
            'source_document_no'   => (string) $bill['supplier_invoice_no'],

            'inventory_lines' => $inventoryLines,
            'service_lines'   => $serviceLines,
        ];
        if (($supply['supply_nature'] ?? null) !== null) {
            $payload['supply_nature'] = $supply['supply_nature'];
        }

        if ($inventoryLines !== [] && $plan['stock_effect'] !== null) {
            $payload['stock_effect'] = $plan['stock_effect'];
            if ($plan['challan_settlements'] !== []) {
                // Books' settlement shape: which GRN, which item, how much, which warehouse —
                // the GRN's, where Inventory holds that GRN's pending stock.
                $payload['challan_settlements'] = array_map(static fn (array $s) => array_filter([
                    'source_document_id' => (int) $s['source_document_id'],
                    'item_id'            => (int) $s['item_id'],
                    'qty'                => (float) $s['qty'],
                    'mc_id'              => $s['mc_id'] ?? null,
                ], static fn ($v) => $v !== null), $plan['challan_settlements']);
            }
        }

        return $payload;
    }

    /**
     * A goods line as the warehouses its stock is in: [warehouse, qty, amount] per warehouse, in
     * the order the settlements name them. The amount is shared by quantity, the last warehouse
     * taking what rounding leaves, so the pieces add up to the line exactly.
     *
     * @param array<string, mixed> $line a requested line
     * @param list<array<string, mixed>> $settlements what this line settles
     * @return list<array{0: ?int, 1: float, 2: float}>
     */
    private static function warehousePieces(array $line, array $settlements): array
    {
        $qty = (float) ($line['qty'] ?? 0);
        $amount = (float) ($line['amount'] ?? 0);
        $own = isset($line['warehouse_id']) ? ((int) $line['warehouse_id'] ?: null) : null;

        $byWarehouse = [];
        foreach ($settlements as $settlement) {
            $warehouse = isset($settlement['mc_id']) ? ((int) $settlement['mc_id'] ?: null) : null;
            $key = $warehouse ?? 0;
            $byWarehouse[$key] = ['warehouse' => $warehouse ?? $own, 'qty' => round(($byWarehouse[$key]['qty'] ?? 0.0) + (float) $settlement['qty'], 4)];
        }
        if (count($byWarehouse) <= 1) {
            $only = reset($byWarehouse);

            return [[$only === false ? $own : $only['warehouse'], $qty, $amount]];
        }

        $pieces = [];
        $left = $amount;
        $groups = array_values($byWarehouse);
        foreach ($groups as $i => $group) {
            $share = $i === count($groups) - 1 ? round($left, 4) : round($amount * $group['qty'] / $qty, 4);
            $left = round($left - $share, 4);
            $pieces[] = [$group['warehouse'], $group['qty'], $share];
        }

        return $pieces;
    }

    /**
     * How this bill's goods reach stock, and which GRNs it settles.
     *
     *  - Services and expenses: no stock at all.
     *  - Goods without a purchase order (a direct purchase): Books receives them with the
     *    bill (`on_invoice`) — there is no GRN here to settle, and none will follow.
     *  - Goods on a purchase order: they came in at the GRN. The bill SETTLES those GRNs —
     *    first in, first out per order line, after what every other live bill of the order
     *    has already claimed (stored on those bills, so one still on its way to Books
     *    counts) — and never receives the goods again. Both kinds of GRN are settled with
     *    `from_challan`; Inventory decides from what the settled receipts did whether the
     *    goods still move: a physical GRN's bill moves nothing, clears the GRNI accrual and
     *    trues the cost up to the billed rate; a challan-only GRN's bill receives them now.
     *
     * Refused rather than guessed: goods billed beyond what has arrived and is not yet
     * billed (one Books voucher has one stock effect, and receiving the rest on this bill
     * would receive it AGAIN when its GRN is recorded), GRNs of both kinds on one bill,
     * and GRNs recorded before receipts had their own identity.
     *
     * @param array<string, mixed> $bill
     * @return array{stock_effect: ?string, challan_settlements: list<array<string, mixed>>}
     */
    private function stockPlan(array $bill): array
    {
        $goods = array_values(array_filter(
            Db::jsonColumn($bill['requested_lines']),
            static fn (array $l) => empty($l['is_service']) && !empty($l['item_id']),
        ));
        if ($goods === []) {
            return ['stock_effect' => null, 'challan_settlements' => []];
        }
        if ($bill['po_id'] === null) {
            return ['stock_effect' => 'on_invoice', 'challan_settlements' => []];
        }
        $poId = (int) $bill['po_id'];

        $receipts = Db::all(
            "SELECT request_id, receipt_no, inventory_document_id, stock_effect, source_document_type, applied_lines, requested_lines
               FROM purchase_receipt_requests
              WHERE po_id = :po AND cmp_id = :cmp AND applied_at IS NOT NULL AND status <> 'CANCELLED'
              ORDER BY request_id",
            ['po' => $poId, 'cmp' => $this->ctx->cmpId],
        );

        // Per order line, what each GRN brought in, oldest first — and WHERE it went: the GRN
        // line's own warehouse, as the receipt sent it to Inventory (the receiver's pick, which
        // need not be the order line's). Inventory finds the pending stock a bill settles by that
        // warehouse, and a bill that receives a challan-only GRN's goods puts them in the line's
        // warehouse; the bill or order line's was the wrong one to send. One delivery may put one
        // order line into two warehouses: those are parts of one portion (one GRN, one order line).
        $portions = [];
        foreach ($receipts as $receipt) {
            if ($receipt['inventory_document_id'] === null) {
                continue; // rejected in full: nothing in stock to settle
            }
            $kind = $receipt['source_document_type'] === ReceiptService::LEGACY_SOURCE_TYPE
                ? 'legacy'
                : ((string) $receipt['stock_effect'] === 'challan_only' ? 'challan_only' : 'physical');
            $sent = Db::jsonColumn($receipt['requested_lines']);
            // applied_lines mirrors requested_lines line for line (ReceiptService::applyOnce).
            $applied = $receipt['applied_lines'] === null ? $sent : Db::jsonColumn($receipt['applied_lines']);
            $byLine = [];
            foreach ($applied as $i => $line) {
                $qty = round((float) ($line['qty'] ?? 0), 4);
                if ($qty <= 0) {
                    continue;
                }
                $lineId = (int) ($line['line_id'] ?? 0);
                $grnLine = $sent[$i] ?? null;
                $warehouse = is_array($grnLine) && (int) ($grnLine['line_id'] ?? 0) === $lineId ? self::id($grnLine['warehouse_id'] ?? null) : null;
                $byLine[$lineId]['qty'] = round(($byLine[$lineId]['qty'] ?? 0.0) + $qty, 4);
                $byLine[$lineId]['parts'][] = ['warehouse_id' => $warehouse, 'qty' => $qty];
            }
            foreach ($byLine as $lineId => $portion) {
                $portions[$lineId][] = [
                    'request_id'   => (int) $receipt['request_id'],
                    'receipt_no'   => $receipt['receipt_no'],
                    'document_id'  => (int) $receipt['inventory_document_id'],
                    'qty'          => $portion['qty'],
                    'parts'        => $portion['parts'],
                    'kind'         => $kind,
                ];
            }
        }

        // What every other live bill of this order has already claimed, per GRN and line.
        $claimed = [];
        foreach (Db::all(
            "SELECT stock_settlements FROM purchase_bill_requests
              WHERE po_id = :po AND cmp_id = :cmp AND request_id <> :self AND status <> 'CANCELLED'
                AND stock_settlements IS NOT NULL",
            ['po' => $poId, 'cmp' => $this->ctx->cmpId, 'self' => (int) $bill['request_id']],
        ) as $row) {
            foreach (Db::jsonColumn($row['stock_settlements']) as $s) {
                $k = (int) ($s['source_document_id'] ?? 0) . ':' . (int) ($s['po_line_id'] ?? 0);
                $claimed[$k] = ($claimed[$k] ?? 0.0) + (float) ($s['qty'] ?? 0);
            }
        }

        $poLines = [];
        foreach (Db::all('SELECT line_id, line_no, item_id, warehouse_id FROM purchase_order_lines WHERE po_id = :po AND cmp_id = :cmp', ['po' => $poId, 'cmp' => $this->ctx->cmpId]) as $row) {
            $poLines[(int) $row['line_id']] = $row;
        }

        $settlements = [];
        $kinds = [];
        foreach ($goods as $line) {
            $poLineId = (int) ($line['po_line_id'] ?? 0);
            $lineNo = (int) ($poLines[$poLineId]['line_no'] ?? 0);
            $need = round((float) $line['qty'], 4);
            foreach ($portions[$poLineId] ?? [] as $portion) {
                if ($need <= self::EPSILON) {
                    break;
                }
                $k = $portion['document_id'] . ':' . $poLineId;
                $free = round($portion['qty'] - ($claimed[$k] ?? 0.0), 4);
                if ($free <= self::EPSILON) {
                    continue;
                }
                $take = round(min($need, $free), 4);
                if ($portion['kind'] === 'legacy') {
                    Http::conflict(
                        sprintf('Line %d was received on %s before receipts had their own identity. Review it in the receipt repair report before billing it here, so the goods are not received a second time.', $lineNo, $portion['receipt_no'] ?? 'an earlier GRN'),
                        ['po_line_id' => $poLineId, 'request_id' => $portion['request_id']],
                    );
                }
                $kinds[$portion['kind']] = true;
                // Out of which warehouse: what other bills already claimed of this GRN line is
                // used up first, part by part in the GRN's own order, then this bill's share.
                $skip = (float) ($claimed[$k] ?? 0.0);
                $left = $take;
                foreach ($portion['parts'] as $part) {
                    if ($left <= self::EPSILON) {
                        break;
                    }
                    $partQty = (float) $part['qty'];
                    if ($skip >= $partQty - self::EPSILON) {
                        $skip = round($skip - $partQty, 4);
                        continue;
                    }
                    $piece = round(min($left, $partQty - $skip), 4);
                    $skip = 0.0;
                    $settlements[] = [
                        'source_document_id' => $portion['document_id'],
                        'item_id'            => (int) $line['item_id'],
                        'qty'                => $piece,
                        // The GRN's warehouse; the bill line's only when the GRN named none.
                        'mc_id'              => $part['warehouse_id'] ?? (isset($line['warehouse_id']) ? ((int) $line['warehouse_id'] ?: null) : null),
                        'po_line_id'         => $poLineId,
                        'bill_line_no'       => (int) ($line['line_no'] ?? 0),
                        'receipt_request_id' => $portion['request_id'],
                        'receipt_no'         => $portion['receipt_no'],
                    ];
                    $left = round($left - $piece, 4);
                }
                $claimed[$k] = round(($claimed[$k] ?? 0.0) + $take, 4);
                $need = round($need - $take, 4);
            }
            if ($need > self::EPSILON) {
                Http::conflict(
                    sprintf('Line %d is billed for %s more than has been received and not yet billed. Record the GRN for those goods first — billing them here would receive them again when they arrive.', $lineNo, self::num($need)),
                    ['po_line_id' => $poLineId, 'unreceived_qty' => $need],
                );
            }
        }

        if (count($kinds) > 1) {
            Http::conflict('This bill settles goods received two different ways (on hand, and on the pending register): bill the two receipts separately.');
        }

        return [
            'stock_effect'        => 'from_challan',
            'challan_settlements' => $settlements,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function normaliseLines(mixed $raw, ?int $poId): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $poLines = [];
        if ($poId !== null) {
            foreach (Db::all('SELECT * FROM purchase_order_lines WHERE po_id = :id AND cmp_id = :cmp', ['id' => $poId, 'cmp' => $this->ctx->cmpId]) as $line) {
                $poLines[(int) $line['line_id']] = $line;
            }
        }

        $lines = [];
        $lineNo = 0;
        $defaultWarehouse = false; // read once, only if a direct goods line needs it
        foreach ($raw as $index => $line) {
            if (!is_array($line)) {
                continue;
            }
            $qty = round((float) ($line['qty'] ?? 0), 4);
            $rate = round((float) ($line['rate'] ?? 0), 4);
            if ($qty <= 0) {
                continue;
            }

            $poLineId = self::id($line['po_line_id'] ?? null);
            $poLine = $poLineId !== null ? ($poLines[$poLineId] ?? null) : null;
            if ($poLineId !== null && $poLine === null) {
                Http::validationFailed('A bill line names an order line that is not on this purchase order.', ['field' => 'lines', 'index' => $index]);
            }
            $discountPc = round((float) ($line['discount_pc'] ?? 0), 3);
            $gross = $qty * $rate;
            $itemId = self::id($line['item_id'] ?? ($poLine['item_id'] ?? null));
            $isService = (bool) ($line['is_service'] ?? ($poLine !== null ? self::truthy($poLine['is_service']) : $itemId === null));
            $expenseAcc = self::id($line['purchase_acc_id'] ?? $line['expense_acc_id'] ?? null);
            if (($isService || $itemId === null) && $expenseAcc === null) {
                Http::validationFailed('Choose the ledger this service or expense is booked to.', ['field' => 'lines', 'index' => $index, 'needs' => 'purchase_acc_id']);
            }
            $warehouseId = self::id($line['warehouse_id'] ?? ($poLine['warehouse_id'] ?? null));
            if ($poId === null && !$isService && $itemId !== null && $warehouseId === null) {
                // Goods bought without an order are received into stock BY THE BILL, and Books
                // will not receive an item line without the material centre it goes into
                // (VoucherPostingService::assertMaterialCentresOnInventoryLines, 422). Decided
                // here, when the bill is entered — not discovered when it is posted and BLOCKED.
                if ($defaultWarehouse === false) {
                    $settings = Db::first('SELECT default_warehouse_id FROM purchase_settings WHERE cmp_id = :cmp', ['cmp' => $this->ctx->cmpId]);
                    $defaultWarehouse = self::id($settings['default_warehouse_id'] ?? null);
                }
                $warehouseId = $defaultWarehouse;
                if ($warehouseId === null) {
                    Http::validationFailed(
                        sprintf('Line %d: say which warehouse these goods go into. A bill without a purchase order receives them into stock itself. (Or set a default warehouse in Settings.)', $lineNo + 1),
                        ['field' => 'lines', 'index' => $index, 'needs' => 'warehouse_id'],
                    );
                }
            }

            $lines[] = [
                'line_no'         => ++$lineNo,
                'po_line_id'      => $poLineId,
                'item_id'         => $isService ? null : $itemId,
                'unit_id'         => self::id($line['unit_id'] ?? ($poLine['unit_id'] ?? null)),
                'warehouse_id'    => $warehouseId,
                'is_service'      => $isService || $itemId === null,
                'purchase_acc_id' => $expenseAcc,
                'description'     => self::text($line['description'] ?? ($poLine['description'] ?? null)),
                'tax_cat_id'      => self::id($line['tax_cat_id'] ?? ($poLine['tax_cat_id'] ?? null)),
                'hsn_sac'         => self::text($line['hsn_sac'] ?? ($poLine['hsn_sac'] ?? null)),
                'qty'             => $qty,
                'rate'            => $rate,
                'discount_pc'     => $discountPc,
                'amount'          => round($gross - ($gross * $discountPc / 100), 4),
            ];
        }

        return $lines;
    }

    private static function truthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_string($value)) {
            return in_array(strtolower($value), ['t', 'true', '1', 'yes'], true);
        }

        return (bool) $value;
    }

    private static function num(float $value): string
    {
        return rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');
    }

    /**
     * The date the bill is booked on: the supplier's invoice date unless another is given, and
     * inside the financial year Manage confirmed for this request. An invoice dated in last year
     * and booked in this one needs a posting date here, and is refused before it reaches Books
     * rather than by Books.
     */
    private function postingDate(mixed $given, string $invoiceDate, ?Context $scope = null): string
    {
        $date = self::validDate($given) ?? $invoiceDate;
        if ($date < $invoiceDate) {
            Http::validationFailed('A bill cannot be booked before the supplier\'s invoice date.', ['field' => 'posting_date']);
        }
        $range = ($scope ?? $this->ctx)->fyRange();
        if ($range !== null && ($date < $range['from'] || $date > $range['to'])) {
            Http::validationFailed(
                sprintf(
                    'This bill would be booked on %s, outside the financial year it is booked in (%s to %s). %s',
                    $date,
                    $range['from'],
                    $range['to'],
                    $date < $range['from'] ? 'Book it in the year it belongs to, or give a posting date in this year; the supplier\'s invoice date stays as it is.' : 'Switch to the year it belongs to.',
                ),
                ['field' => 'posting_date', 'fy_from' => $range['from'], 'fy_to' => $range['to']],
            );
        }

        return $date;
    }

    private static function validDate(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($value)) === 1 ? trim($value) : null;
    }

    private static function id(mixed $value): ?int
    {
        return ($value === null || $value === '' || (int) $value === 0) ? null : (int) $value;
    }

    private static function text(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private static function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }
}
