<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain;

use Aicountly\Api\Audit;
use Aicountly\Api\Auth;
use Aicountly\Api\Clients\BooksClient;
use Aicountly\Api\Clients\InventoryClient;
use Aicountly\Api\Context;
use Aicountly\Api\Db;
use Aicountly\Api\Http;
use Aicountly\Api\IntegrationCommand;
use Aicountly\Api\Permissions;

/**
 * Purchase returns and supplier claims.
 *
 * Three products, three responsibilities:
 *
 *   Purchases  the commercial decision — may this go back, how much, on what terms
 *   Inventory  the goods leaving the warehouse
 *   Books      the debit note and what it does to the payable
 *
 * ONE OWNER PER MOVEMENT. A physical return's goods leave stock once: Purchase records the
 * dispatch in Inventory as a DELIVERY_CHALLAN (challan_only — goods out, pending), and the debit
 * note Books posts settles that challan (PURCHASE_RETURN, from_challan). Books sends its debit
 * note's item lines to Inventory itself; Books' own pending register is not involved, because the
 * challan is Inventory's. The debit note is refused until Inventory has confirmed the dispatch.
 *
 * A return with nothing going back — a rate difference, goods scrapped on site — is a FINANCIAL
 * return: a debit note on a ledger, no stock, separately authorised (return.financial_adjustment)
 * with a reason.
 *
 * Claims are settled by resolutions (ClaimResolutionService), each completed only when the
 * operation it depends on has succeeded upstream.
 */
final class ReturnClaimService
{
    public const COMMAND_RETURN_DISPATCH = 'purchases.return.dispatch';
    public const COMMAND_RETURN_RECALL   = 'purchases.return.recall';
    public const COMMAND_DEBIT_NOTE      = 'purchases.return.debit_note';

    public const KINDS = ['physical', 'financial'];

    /** Returns whose quantities are not yet in the order's returned_qty. */
    private const UNDISPATCHED = ['DRAFT', 'APPROVED'];

    private const EPSILON = 0.00005;

    public function __construct(
        private readonly Context $ctx,
        private readonly Auth $auth,
    ) {
    }

    // -----------------------------------------------------------------------
    // Returns
    // -----------------------------------------------------------------------

    /**
     * @param array<string, mixed> $input
     *   {supplier_account_id, return_kind?: physical|financial, po_id?, return_date?, reason_code?,
     *    reason_note?, expect_replacement?, adjustment_acc_id? (financial), adjustment_reason?
     *    (financial), claim_id?, lines: [{po_line_id?, item_id?, unit_id?, warehouse_id?, batch_id?,
     *    return_qty, rate?, description?, reason_code?}]}
     */
    public function createReturn(array $input): array
    {
        Permissions::assert($this->ctx, $this->auth, 'return.create');

        $kind = self::text($input['return_kind'] ?? null) ?? 'physical';
        if (!in_array($kind, self::KINDS, true)) {
            Http::validationFailed('A return is physical (goods go back) or financial (a debit note with nothing going back).', ['field' => 'return_kind']);
        }
        $supplierId = (int) ($input['supplier_account_id'] ?? 0);
        if ($supplierId <= 0) {
            Http::validationFailed('Say which supplier this return goes back to.', ['field' => 'supplier_account_id']);
        }
        $adjustmentAcc = self::id($input['adjustment_acc_id'] ?? null);
        $adjustmentReason = self::text($input['adjustment_reason'] ?? null);
        if ($kind === 'financial') {
            $this->assertFinancialAuthority($adjustmentAcc, $adjustmentReason);
        }

        $poId = self::id($input['po_id'] ?? null);
        $lines = $this->normaliseReturnLines($input['lines'] ?? [], $kind);
        if ($lines === []) {
            Http::validationFailed('A return needs at least one line.', ['field' => 'lines']);
        }

        return Db::transaction(function () use ($input, $kind, $supplierId, $poId, $lines, $adjustmentAcc, $adjustmentReason) {
            if ($poId !== null) {
                $po = PoProgress::lock($poId, $this->ctx->cmpId);
                if ($po === null) {
                    Http::notFound('That purchase order does not exist.');
                }
                if ((int) $po['supplier_account_id'] !== $supplierId) {
                    Http::validationFailed('That purchase order is with a different supplier.', ['field' => 'po_id']);
                }
            }
            if ($kind === 'physical') {
                $lines = $this->admitReturnLines($poId, $lines, null);
            }
            $lines = $this->withBilledTax($poId, $lines);

            $no = NumberSeries::next($this->ctx, 'return');
            $returnId = (int) Db::insert('purchase_returns', [
                'cmp_id'              => $this->ctx->cmpId,
                'fy_id'               => $this->ctx->fyId,
                'bo_id'               => $this->ctx->boId,
                'return_no'           => $no,
                'return_date'         => self::date($input['return_date'] ?? null),
                'po_id'               => $poId,
                'supplier_account_id' => $supplierId,
                'status'              => 'DRAFT',
                'return_kind'         => $kind,
                'expect_replacement'  => self::truthy($input['expect_replacement'] ?? false),
                'adjustment_acc_id'   => $kind === 'financial' ? $adjustmentAcc : null,
                'adjustment_reason'   => $kind === 'financial' ? $adjustmentReason : null,
                'claim_id'            => self::id($input['claim_id'] ?? null),
                'reason_code'         => self::text($input['reason_code'] ?? null),
                'reason_note'         => self::text($input['reason_note'] ?? null),
                'created_by'          => $this->auth->uuid,
            ], 'return_id');

            foreach ($lines as $line) {
                Db::insert('purchase_return_lines', [
                    'return_id'    => $returnId,
                    'cmp_id'       => $this->ctx->cmpId,
                    'line_no'      => $line['line_no'],
                    'po_line_id'   => $line['po_line_id'],
                    'item_id'      => $line['item_id'],
                    'unit_id'      => $line['unit_id'],
                    'warehouse_id' => $line['warehouse_id'],
                    'batch_id'     => $line['batch_id'],
                    'return_qty'   => $line['return_qty'],
                    'rate'         => $line['rate'],
                    'line_amount'  => $line['line_amount'],
                    'reason_code'  => $line['reason_code'],
                    'tax_cat_id'   => $line['tax_cat_id'],
                    'hsn_sac'      => $line['hsn_sac'],
                ], 'line_id');
            }

            Audit::record($this->ctx, $this->auth, 'return.created', 'purchase_return', $returnId, null, [
                'return_no' => $no, 'return_kind' => $kind, 'po_id' => $poId,
            ], $kind === 'financial' ? (string) $adjustmentReason : '');

            return $this->findReturn($returnId);
        });
    }

    public function approveReturn(int $returnId, array $input): array
    {
        Permissions::assert($this->ctx, $this->auth, 'return.approve');

        $return = $this->requireReturn($returnId);
        if ($return['return_kind'] === 'financial') {
            $this->assertFinancialAuthority(self::id($return['adjustment_acc_id']), self::text($return['adjustment_reason']));
        }

        Db::transaction(function () use ($returnId, $return) {
            if ($return['po_id'] !== null) {
                PoProgress::lock((int) $return['po_id'], $this->ctx->cmpId);
            }
            $locked = $this->lockReturn($returnId);
            if ($locked['status'] !== 'DRAFT') {
                Http::conflict('This return is already ' . strtolower((string) $locked['status']) . '.');
            }
            if ($locked['return_kind'] === 'physical') {
                // Checked again: another return may have been raised for the same goods since.
                $this->admitReturnLines(self::id($locked['po_id']), $this->storedLines($returnId), $returnId);
            }
            Db::update('purchase_returns', [
                'status' => 'APPROVED', 'approved_by' => $this->auth->uuid, 'approved_at' => self::now(), 'updated_at' => self::now(),
            ], ['return_id' => $returnId, 'cmp_id' => $this->ctx->cmpId]);
        });

        Audit::record($this->ctx, $this->auth, 'return.approved', 'purchase_return', $returnId, ['status' => 'DRAFT'], ['status' => 'APPROVED'], self::text($input['note'] ?? null) ?? '');

        return $this->findReturn($returnId);
    }

    /** Withdraw a return whose goods have not left. */
    public function cancelReturn(int $returnId, array $input): array
    {
        Permissions::assert($this->ctx, $this->auth, 'return.create');
        $reason = self::text($input['reason'] ?? null);
        if ($reason === null) {
            Http::validationFailed('Say why this return is being cancelled.', ['field' => 'reason']);
        }

        Db::transaction(function () use ($returnId, $reason) {
            $locked = $this->lockReturn($returnId);
            if (!in_array($locked['status'], self::UNDISPATCHED, true)) {
                Http::conflict($locked['status'] === 'DISPATCHED'
                    ? 'The goods have been dispatched. Recall the return to bring them back into stock.'
                    : 'This return is ' . strtolower((string) $locked['status']) . ' and cannot be cancelled.');
            }
            foreach ([self::COMMAND_RETURN_DISPATCH, self::COMMAND_DEBIT_NOTE] as $type) {
                $command = IntegrationCommand::latest($this->ctx->cmpId, $type, 'purchase_return', $returnId);
                if ($command !== null && $command['status'] !== IntegrationCommand::CANCELLED
                    && !IntegrationCommand::withdraw((int) $command['command_id'], 'Return cancelled: ' . $reason)) {
                    Http::conflict('Inventory or Smart Books may already hold this return. Retry it to learn the outcome before cancelling.');
                }
            }
            Db::update('purchase_returns', [
                'status' => 'CANCELLED', 'cancelled_at' => self::now(), 'cancel_reason' => $reason, 'updated_at' => self::now(),
            ], ['return_id' => $returnId, 'cmp_id' => $this->ctx->cmpId]);
        });

        Audit::record($this->ctx, $this->auth, 'return.cancelled', 'purchase_return', $returnId, null, ['status' => 'CANCELLED'], $reason);

        return $this->findReturn($returnId);
    }

    /**
     * Record the goods leaving for the supplier: a DELIVERY_CHALLAN (challan_only) in Inventory —
     * goods out on challan, pending the debit note, which moves them out of stock by settling it.
     */
    public function dispatchReturn(int $returnId): array
    {
        Permissions::assert($this->ctx, $this->auth, 'return.approve');

        $return = $this->requireReturn($returnId);
        if ($return['return_kind'] !== 'physical') {
            Http::conflict('Nothing goes back on a financial return; raise its debit note.');
        }
        if ($return['status'] === 'DISPATCHED' || $return['status'] === 'DEBITED') {
            return $return;
        }
        if ($return['status'] !== 'APPROVED') {
            Http::conflict('Approve the return before sending the goods back.');
        }
        // Checked before anything is sent, under the order's lock, against every other return.
        Db::transaction(function () use ($return, $returnId) {
            if ($return['po_id'] !== null) {
                PoProgress::lock((int) $return['po_id'], $this->ctx->cmpId);
            }
            $this->admitReturnLines(self::id($return['po_id']), $this->storedLines($returnId), $returnId);
        });

        $stockLines = array_values(array_filter($return['lines'], static fn (array $l) => $l['item_id'] !== null && (float) $l['return_qty'] > 0));
        $payload = [
            'document_type'        => 'DELIVERY_CHALLAN',
            'stock_effect'         => 'challan_only',
            'document_date'        => (string) $return['return_date'],
            'source_app'           => 'purchases',
            'source_document_type' => 'purchases.return',
            'source_document_id'   => $returnId,
            'source_document_uuid' => (string) $return['return_uuid'],
            'source_document_no'   => (string) $return['return_no'],
            'party_ref'            => (string) $return['supplier_account_id'],
            'narration'            => 'Purchase return ' . $return['return_no'],
            'lines'                => array_map(static fn (array $line) => [
                'source_line_ref' => (int) $line['line_id'],
                'item_id'         => (int) $line['item_id'],
                'warehouse_id'    => $line['warehouse_id'] === null ? null : (int) $line['warehouse_id'],
                'batch_id'        => $line['batch_id'] === null ? null : (int) $line['batch_id'],
                'unit_id'         => $line['unit_id'] === null ? null : (int) $line['unit_id'],
                'qty'             => (float) $line['return_qty'],
                'rate'            => (float) $line['rate'],
                'amount'          => (float) $line['line_amount'],
                'direction'       => 'out',
            ], $stockLines),
        ];

        $command = IntegrationCommand::ensure($this->ctx, 'inventory', self::COMMAND_RETURN_DISPATCH, 'purchase_return', $returnId, $payload, ['lines' => count($stockLines)]);
        $scope = Context::of((int) $command['cmp_id'], (int) $command['fy_id'], (int) $command['bo_id']);
        $inventory = (new InventoryClient())->withService($this->auth->uuid);
        $attempt = IntegrationCommand::attempt(
            $command,
            static fn (array $body, string $key) => $inventory->postDocument($scope, $body, $key),
        );

        if ($attempt['outcome'] === 'already_completed') {
            $ref = (array) ($attempt['command']['external_reference'] ?? []);
            $this->applyDispatch($returnId, ['document_id' => $ref['inventory_document_id'] ?? null, 'document_uuid' => $ref['inventory_document_uuid'] ?? null]);

            return $this->findReturn($returnId);
        }
        if ($attempt['outcome'] !== 'completed') {
            $this->failWith($attempt, 'Inventory', 'the dispatch');
        }

        $document = $attempt['response']['body']['data'] ?? [];
        $problem = self::dispatchMismatch($document, $returnId);
        if ($problem !== null) {
            IntegrationCommand::block((int) $command['command_id'], (string) $attempt['lease'], $problem, (int) $attempt['response']['status']);
            Http::error(409, 'dispatch_mismatch', $problem . ' Nothing was recorded against the order.', ['retryable' => false]);
        }
        IntegrationCommand::complete((int) $command['command_id'], (string) $attempt['lease'], [
            'inventory_document_id'   => $document['document_id'] ?? null,
            'inventory_document_uuid' => $document['document_uuid'] ?? null,
            // What the dispatch is in Inventory: the debit note settles a DELIVERY_CHALLAN.
            'document_type'           => 'DELIVERY_CHALLAN',
        ], !empty($document['duplicate']) ? 'replay' : 'response', (int) $attempt['response']['status']);

        $this->applyDispatch($returnId, $document);

        return $this->findReturn($returnId);
    }

    /**
     * Bring a dispatched return back: the supplier refused it, or it was sent in error. Inventory
     * reverses the challan; the order counts the goods as received again.
     *
     * @param array<string, mixed> $input {reason}
     */
    public function recallReturn(int $returnId, array $input): array
    {
        Permissions::assert($this->ctx, $this->auth, 'return.approve');
        $reason = self::text($input['reason'] ?? null);
        if ($reason === null) {
            Http::validationFailed('Say why the goods are coming back.', ['field' => 'reason']);
        }
        $return = $this->requireReturn($returnId);
        if ($return['status'] === 'RECALLED') {
            return $return;
        }
        if ($return['status'] !== 'DISPATCHED') {
            Http::conflict('Only a return whose goods have been dispatched, and which has no debit note, can be recalled.');
        }
        $debit = IntegrationCommand::latest($this->ctx->cmpId, self::COMMAND_DEBIT_NOTE, 'purchase_return', $returnId);
        if ($debit !== null && $debit['status'] !== IntegrationCommand::CANCELLED
            && !IntegrationCommand::withdraw((int) $debit['command_id'], 'Return recalled: ' . $reason)) {
            Http::conflict('Smart Books may already hold the debit note for this return. Retry the debit note to learn the outcome; a posted debit note is reversed in Books.');
        }
        $documentId = (int) ($return['inventory_document_id'] ?? 0);
        if ($documentId <= 0) {
            Http::conflict('This return was dispatched before its Inventory document was recorded here; recall it in Inventory.');
        }

        $command = IntegrationCommand::ensure($this->ctx, 'inventory', self::COMMAND_RETURN_RECALL, 'purchase_return', $returnId, ['document_id' => $documentId, 'reason' => $reason], ['document_id' => $documentId]);
        $scope = Context::of((int) $command['cmp_id'], (int) $command['fy_id'], (int) $command['bo_id']);
        $inventory = (new InventoryClient())->withService($this->auth->uuid);
        $attempt = IntegrationCommand::attempt(
            $command,
            static fn (array $body, string $key) => $inventory->reverseDocument($scope, (int) $body['document_id'], (string) $body['reason'], $key),
        );
        if ($attempt['outcome'] !== 'completed' && $attempt['outcome'] !== 'already_completed') {
            $this->failWith($attempt, 'Inventory', 'the recall');
        }
        if ($attempt['outcome'] === 'completed') {
            IntegrationCommand::complete((int) $command['command_id'], (string) $attempt['lease'], ['inventory_document_id' => $documentId, 'status' => 'REVERSED'], 'response', (int) $attempt['response']['status']);
        }

        Db::transaction(function () use ($returnId, $reason) {
            $locked = $this->lockReturn($returnId);
            if ($locked['status'] !== 'DISPATCHED') {
                return;
            }
            if ($locked['po_id'] !== null) {
                PoProgress::lock((int) $locked['po_id'], $this->ctx->cmpId);
            }
            foreach ($this->storedLines($returnId) as $line) {
                if ($line['po_line_id'] !== null) {
                    Db::run(
                        'UPDATE purchase_order_lines
                            SET returned_qty = returned_qty - :qty, short_closed_qty = GREATEST(0, short_closed_qty - :closes), updated_at = NOW()
                          WHERE line_id = :id AND cmp_id = :cmp',
                        ['qty' => (float) $line['return_qty'], 'closes' => (float) $line['closes_order_qty'], 'id' => (int) $line['po_line_id'], 'cmp' => $this->ctx->cmpId],
                    );
                }
            }
            Db::update('purchase_returns', [
                'status' => 'RECALLED', 'recalled_at' => self::now(), 'recall_reason' => $reason, 'updated_at' => self::now(),
            ], ['return_id' => $returnId, 'cmp_id' => $this->ctx->cmpId]);
            if ($locked['po_id'] !== null) {
                PoProgress::recompute((int) $locked['po_id'], $this->ctx->cmpId, $this->auth->uuid);
            }
        });

        Audit::record($this->ctx, $this->auth, 'return.recalled', 'purchase_return', $returnId, ['status' => 'DISPATCHED'], ['status' => 'RECALLED'], $reason);

        return $this->findReturn($returnId);
    }

    /**
     * Ask Books for the debit note. Books owns the credit against the payable.
     *
     * Physical: only once Inventory has confirmed the dispatch, and settling that dispatch
     * challan — the one movement of the goods. Financial: a ledger line, no stock, only by
     * someone who may make a financial adjustment.
     */
    public function requestDebitNote(int $returnId, array $input = []): array
    {
        Permissions::assert($this->ctx, $this->auth, 'return.approve');

        $return = $this->requireReturn($returnId);
        if ($return['status'] === 'DEBITED') {
            return $return;
        }
        $financial = $return['return_kind'] === 'financial';
        if ($financial) {
            $this->assertFinancialAuthority(self::id($return['adjustment_acc_id']), self::text($return['adjustment_reason']));
            if ($return['status'] !== 'APPROVED') {
                Http::conflict('Approve the return before raising its debit note.');
            }
        } elseif ($return['status'] !== 'DISPATCHED') {
            Http::conflict($return['status'] === 'APPROVED'
                ? 'Send the goods back first: the debit note for goods going back is raised once Inventory has recorded their dispatch. If nothing is going back, raise a financial return instead.'
                : 'This return is ' . strtolower((string) $return['status']) . '; a debit note cannot be raised for it.');
        }

        // Posted in the return's own year and branch — its date is in that year — whatever the
        // screen is on; confirmed with Manage when it differs.
        $scope = Context::of((int) $return['cmp_id'], (int) $return['fy_id'], (int) $return['bo_id']);
        if ($scope->fyId !== $this->ctx->fyId || $scope->boId !== $this->ctx->boId) {
            $scope->assertAllowed($this->auth);
        }
        $revision = $this->debitNoteRevision($returnId, self::truthy($input['resend'] ?? false), self::text($input['note'] ?? null));
        // Where the supplier supplies from, from their Books ledger — only for a request not yet
        // sent; one already sent replays its stored body.
        $supply = IntegrationCommand::find($this->ctx->cmpId, self::COMMAND_DEBIT_NOTE, 'purchase_return', $returnId, $revision) === null
            ? PlaceOfSupply::forSupplier((new BooksClient())->withSession($this->auth->sesKey()), $scope, (int) $return['supplier_account_id'])
            : null;
        $payload = $this->debitNotePayload($return, $financial, $supply);
        $voucher = (new DebitNotePoster($this->ctx, $this->auth))->post(self::COMMAND_DEBIT_NOTE, 'purchase_return', $returnId, $payload, 'return ' . $return['return_no'], $revision, null, $scope);

        Db::transaction(function () use ($returnId, $voucher, $financial) {
            $locked = $this->lockReturn($returnId);
            if ($locked['status'] === 'DEBITED') {
                return;
            }
            if ($locked['po_id'] !== null) {
                PoProgress::lock((int) $locked['po_id'], $this->ctx->cmpId);
            }
            if (!$financial) {
                foreach ($this->storedLines($returnId) as $line) {
                    if ($line['po_line_id'] !== null) {
                        Db::run(
                            'UPDATE purchase_order_lines SET debited_qty = debited_qty + :qty, updated_at = NOW() WHERE line_id = :id AND cmp_id = :cmp',
                            ['qty' => (float) $line['return_qty'], 'id' => (int) $line['po_line_id'], 'cmp' => $this->ctx->cmpId],
                        );
                    }
                }
            }
            Db::update('purchase_returns', [
                'status'                => 'DEBITED',
                'books_debit_note_id'   => $voucher['vch_txn_id'],
                'books_debit_note_uuid' => $voucher['vch_uuid'],
                'debited_at'            => self::now(),
                'updated_at'            => self::now(),
            ], ['return_id' => $returnId, 'cmp_id' => $this->ctx->cmpId]);
            if ($locked['po_id'] !== null) {
                PoProgress::recompute((int) $locked['po_id'], $this->ctx->cmpId, $this->auth->uuid);
            }
        });

        Audit::record($this->ctx, $this->auth, 'return.debited', 'purchase_return', $returnId, null, [
            'books_debit_note_id' => $voucher['vch_txn_id'], 'resolved_by' => $voucher['resolved_by'],
        ]);

        // A return raised to settle a claim completes that part of the claim now, and not before.
        if ($return['claim_id'] !== null) {
            (new ClaimResolutionService($this->ctx, $this->auth))->returnDebited($returnId);
        }

        return $this->findReturn($returnId);
    }

    /**
     * Which revision of the return's debit note this request is.
     *
     * The same one, normally: a retry is the same request on the same key. A debit note Smart
     * Books REFUSED is a dead end on its key — the refusal is the answer to that body — so once
     * what it refused is put right (the supplier's state on their ledger, say), it is sent again
     * as a new revision: the refused one is withdrawn as superseded, and the next is built from
     * the return as it stands now, under a key of its own. Only from a refusal: a request whose
     * outcome is unknown is retried, never re-sent, or it could post twice.
     */
    private function debitNoteRevision(int $returnId, bool $resend, ?string $note): int
    {
        $latest = IntegrationCommand::latest($this->ctx->cmpId, self::COMMAND_DEBIT_NOTE, 'purchase_return', $returnId);
        if ($latest === null) {
            return 0;
        }
        $revision = (int) $latest['revision'];
        if ($latest['status'] === IntegrationCommand::CANCELLED && ($latest['resolved_by'] ?? null) === 'superseded') {
            return $revision + 1; // superseded, and the next revision not sent yet
        }
        if (!$resend) {
            return $revision;
        }
        if ($latest['status'] !== IntegrationCommand::BLOCKED) {
            Http::conflict('Only a debit note Smart Books refused is sent again. This one is ' . strtolower((string) $latest['status']) . ': retry it as it stands.');
        }
        if (!IntegrationCommand::withdraw((int) $latest['command_id'], 'Sent again as revision ' . ($revision + 1) . ($note !== null ? ': ' . $note : '.'), 'superseded')) {
            $again = IntegrationCommand::latest($this->ctx->cmpId, self::COMMAND_DEBIT_NOTE, 'purchase_return', $returnId);
            if (($again['resolved_by'] ?? null) !== 'superseded' || (int) $again['revision'] !== $revision) {
                Http::conflict('This debit note changed while it was being sent again. Refresh and look before trying again.');
            }
        } else {
            Audit::record($this->ctx, $this->auth, 'return.debit_note_resent', 'purchase_return', $returnId, [
                'revision' => $revision, 'refusal' => $latest['last_error'],
            ], ['revision' => $revision + 1], $note ?? '');
        }

        return $revision + 1;
    }

    /**
     * The tax each line goes back under: what it was BILLED under — the latest posted bill's line
     * for the same order line — unless the line names its own; the order line's as a last
     * resort. A debit note that reversed at the item's default category reversed a different
     * GST from the one the bill charged.
     *
     * @param list<array<string, mixed>> $lines
     * @return list<array<string, mixed>>
     */
    private function withBilledTax(?int $poId, array $lines): array
    {
        if ($poId === null) {
            return $lines;
        }
        $billed = $this->billedLines($poId);
        $poLines = [];
        foreach (Db::all('SELECT line_id, tax_cat_id, hsn_sac FROM purchase_order_lines WHERE po_id = :po AND cmp_id = :cmp', ['po' => $poId, 'cmp' => $this->ctx->cmpId]) as $row) {
            $poLines[(int) $row['line_id']] = $row;
        }
        foreach ($lines as $i => $line) {
            $poLineId = $line['po_line_id'];
            if ($poLineId === null) {
                continue;
            }
            $source = $billed[$poLineId] ?? $poLines[$poLineId] ?? [];
            $lines[$i]['tax_cat_id'] ??= self::id($source['tax_cat_id'] ?? null);
            $lines[$i]['hsn_sac'] ??= self::text(isset($source['hsn_sac']) ? (string) $source['hsn_sac'] : null);
        }

        return $lines;
    }

    /**
     * The line each order line was last billed on, by order line: rate, discount, tax.
     *
     * @return array<int, array<string, mixed>>
     */
    private function billedLines(int $poId): array
    {
        $out = [];
        foreach (Db::all(
            "SELECT requested_lines FROM purchase_bill_requests
              WHERE po_id = :po AND cmp_id = :cmp AND status = 'POSTED'
              ORDER BY posted_at DESC NULLS LAST, request_id DESC",
            ['po' => $poId, 'cmp' => $this->ctx->cmpId],
        ) as $bill) {
            foreach (Db::jsonColumn($bill['requested_lines']) as $line) {
                $poLineId = (int) ($line['po_line_id'] ?? 0);
                if ($poLineId > 0 && !isset($out[$poLineId])) {
                    $out[$poLineId] = $line;
                }
            }
        }

        return $out;
    }

    /** @return array<string, mixed> */
    public function findReturn(int $returnId): array
    {
        $row = Db::first('SELECT * FROM purchase_returns WHERE return_id = :id AND cmp_id = :cmp', ['id' => $returnId, 'cmp' => $this->ctx->cmpId]);
        if ($row === null) {
            return [];
        }
        $row['lines'] = $this->storedLines($returnId);
        $row['commands'] = IntegrationCommand::forEntity($this->ctx, 'purchase_return', $returnId);

        return $row;
    }

    /** @return array{rows:list<array<string, mixed>>, total:int} */
    public function searchReturns(array $filters, int $limit, int $offset, string $sort, string $order): array
    {
        [$scope, $params] = $this->ctx->scopeClause('r');
        $where = [$scope];

        if (!empty($filters['status'])) {
            $where[] = 'r.status = :status';
            $params['status'] = (string) $filters['status'];
        }
        if (!empty($filters['supplier_account_id'])) {
            $where[] = 'r.supplier_account_id = :supplier';
            $params['supplier'] = (int) $filters['supplier_account_id'];
        }
        if (!empty($filters['q'])) {
            $where[] = '(r.return_no ILIKE :term OR r.reason_note ILIKE :term)';
            $params['term'] = '%' . $filters['q'] . '%';
        }

        $clause = implode(' AND ', $where);
        $sortColumn = in_array($sort, ['return_date', 'return_no', 'status', 'created_at'], true) ? $sort : 'return_date';

        return [
            'rows'  => Db::all("SELECT r.* FROM purchase_returns r WHERE {$clause} ORDER BY r.{$sortColumn} {$order}, r.return_id {$order} LIMIT {$limit} OFFSET {$offset}", $params),
            'total' => (int) Db::scalar("SELECT COUNT(*) FROM purchase_returns r WHERE {$clause}", $params),
        ];
    }

    // -----------------------------------------------------------------------
    // Claims
    // -----------------------------------------------------------------------

    /** @param array<string, mixed> $input */
    public function createClaim(array $input): array
    {
        Permissions::assert($this->ctx, $this->auth, 'claim.create');

        $supplierId = (int) ($input['supplier_account_id'] ?? 0);
        if ($supplierId <= 0) {
            Http::validationFailed('Say which supplier this claim is against.', ['field' => 'supplier_account_id']);
        }

        $kind = self::text($input['claim_kind'] ?? null) ?? 'other';
        $validKinds = ['shortage', 'damage', 'rate_difference', 'scheme', 'rebate', 'quality', 'late_delivery', 'other'];
        if (!in_array($kind, $validKinds, true)) {
            Http::validationFailed('Claim kind must be one of: ' . implode(', ', $validKinds) . '.', ['field' => 'claim_kind']);
        }

        $amount = round((float) ($input['claimed_amount'] ?? 0), 4);
        if ($amount <= 0) {
            Http::validationFailed('A claim needs an amount.', ['field' => 'claimed_amount']);
        }

        return Db::transaction(function () use ($input, $supplierId, $kind, $amount) {
            $no = NumberSeries::next($this->ctx, 'claim');

            $claimId = (int) Db::insert('purchase_claims', [
                'cmp_id'              => $this->ctx->cmpId,
                'fy_id'               => $this->ctx->fyId,
                'claim_no'            => $no,
                'claim_date'          => self::date($input['claim_date'] ?? null),
                'supplier_account_id' => $supplierId,
                'po_id'               => self::id($input['po_id'] ?? null),
                'claim_kind'          => $kind,
                'status'              => 'DRAFT',
                'claimed_amount'      => $amount,
                'description'         => self::text($input['description'] ?? null),
                'created_by'          => $this->auth->uuid,
            ], 'claim_id');

            Audit::record($this->ctx, $this->auth, 'claim.created', 'claim', $claimId, null, [
                'claim_no' => $no, 'claim_kind' => $kind, 'claimed_amount' => $amount,
            ]);

            return $this->findClaim($claimId);
        });
    }

    /**
     * The claim's own workflow: submitted, answered, approved (for how much), rejected, closed.
     *
     * Settling is not an action here any more. A claim is settled by its resolutions, each of
     * which completes only when Books, Inventory or the person recording a non-financial outcome
     * has done what it says (ClaimResolutionService).
     *
     * @param array<string, mixed> $input
     */
    public function updateClaim(int $claimId, string $action, array $input): array
    {
        $claim = $this->findClaim($claimId);
        if ($claim === []) {
            Http::notFound('That claim does not exist.');
        }
        if ($action === 'settle') {
            Http::conflict('A claim is settled by what actually happens: propose a resolution — a debit note, a return, a replacement, a refund, or a non-financial outcome — and approve it. It settles when that has been done.', ['use' => 'v1/claims/{id}/resolutions']);
        }

        [$permission, $from, $to] = match ($action) {
            'submit'  => ['claim.create', ['DRAFT'], 'SUBMITTED'],
            'respond' => ['claim.create', ['SUBMITTED'], 'SUPPLIER_RESPONDED'],
            'approve' => ['claim.settle', ['SUBMITTED', 'SUPPLIER_RESPONDED'], 'APPROVED'],
            'reject'  => ['claim.settle', ['SUBMITTED', 'SUPPLIER_RESPONDED'], 'REJECTED'],
            'close'   => ['claim.settle', ['APPROVED', 'PARTIALLY_SETTLED'], 'CLOSED'],
            default   => Http::validationFailed('Unknown action "' . $action . '".'),
        };

        Permissions::assert($this->ctx, $this->auth, $permission);

        $reason = self::text($input['note'] ?? $input['reason'] ?? null) ?? '';
        Db::transaction(function () use ($claimId, $action, $from, $to, $input, $reason) {
            $locked = Db::first('SELECT * FROM purchase_claims WHERE claim_id = :id AND cmp_id = :cmp FOR UPDATE', ['id' => $claimId, 'cmp' => $this->ctx->cmpId]);
            if (!in_array($locked['status'], $from, true)) {
                Http::conflict(sprintf(
                    'A claim that is %s cannot be %s.',
                    strtolower(str_replace('_', ' ', (string) $locked['status'])),
                    ['submit' => 'submitted', 'respond' => 'answered', 'approve' => 'approved', 'reject' => 'rejected', 'close' => 'closed'][$action],
                ));
            }

            $changes = ['status' => $to, 'updated_at' => self::now()];
            if ($action === 'respond') {
                $changes['supplier_response'] = self::text($input['supplier_response'] ?? null);
            }
            if ($action === 'approve') {
                $approved = round((float) ($input['approved_amount'] ?? $locked['claimed_amount']), 4);
                if ($approved <= 0 || $approved > (float) $locked['claimed_amount'] + self::EPSILON) {
                    Http::validationFailed('Approve an amount above zero and no more than was claimed.', ['field' => 'approved_amount']);
                }
                $changes['approved_amount'] = $approved;
            }
            if ($action === 'close') {
                if ($reason === '') {
                    Http::validationFailed('Say why the claim is being closed with what it has recovered so far.', ['field' => 'reason']);
                }
                $inFlight = (int) Db::scalar(
                    "SELECT COUNT(*) FROM purchase_claim_resolutions WHERE claim_id = :id AND status IN ('APPROVED', 'IN_PROGRESS', 'UNCERTAIN')",
                    ['id' => $claimId],
                );
                if ($inFlight > 0) {
                    Http::conflict('A resolution of this claim is still being carried out. Let it finish, or cancel it, before closing the claim.');
                }
                $changes['closed_reason'] = $reason;
            }
            Db::update('purchase_claims', $changes, ['claim_id' => $claimId, 'cmp_id' => $this->ctx->cmpId]);
            Audit::record($this->ctx, $this->auth, 'claim.' . $action, 'claim', $claimId, ['status' => $locked['status']], $changes, $reason);
        });

        return $this->findClaim($claimId);
    }

    /** @return array<string, mixed> */
    public function findClaim(int $claimId): array
    {
        $claim = Db::first('SELECT * FROM purchase_claims WHERE claim_id = :id AND cmp_id = :cmp', ['id' => $claimId, 'cmp' => $this->ctx->cmpId]);
        if ($claim === null) {
            return [];
        }
        $claim['resolutions'] = (new ClaimResolutionService($this->ctx, $this->auth))->forClaim($claimId);

        return $claim;
    }

    /** @return array{rows:list<array<string, mixed>>, total:int} */
    public function searchClaims(array $filters, int $limit, int $offset, string $sort, string $order): array
    {
        // Claims carry no bo_id — a shortage is against a supplier, not a
        // branch — so the scope is company and financial year only.
        $where = ['c.cmp_id = :ctx_cmp_id AND c.fy_id = :ctx_fy_id'];
        $params = ['ctx_cmp_id' => $this->ctx->cmpId, 'ctx_fy_id' => $this->ctx->fyId];

        if (!empty($filters['status'])) {
            $where[] = 'c.status = :status';
            $params['status'] = (string) $filters['status'];
        }
        if (!empty($filters['claim_kind'])) {
            $where[] = 'c.claim_kind = :kind';
            $params['kind'] = (string) $filters['claim_kind'];
        }
        if (!empty($filters['supplier_account_id'])) {
            $where[] = 'c.supplier_account_id = :supplier';
            $params['supplier'] = (int) $filters['supplier_account_id'];
        }
        if (!empty($filters['open_only'])) {
            $where[] = "c.status NOT IN ('SETTLED', 'CLOSED', 'REJECTED')";
        }

        $clause = implode(' AND ', $where);
        $sortColumn = in_array($sort, ['claim_date', 'claim_no', 'claimed_amount', 'status', 'created_at'], true) ? $sort : 'claim_date';

        return [
            'rows'  => Db::all("SELECT c.* FROM purchase_claims c WHERE {$clause} ORDER BY c.{$sortColumn} {$order}, c.claim_id {$order} LIMIT {$limit} OFFSET {$offset}", $params),
            'total' => (int) Db::scalar("SELECT COUNT(*) FROM purchase_claims c WHERE {$clause}", $params),
        ];
    }

    // -----------------------------------------------------------------------

    /**
     * What may go back on each order line, and the lines filled in from the order.
     *
     * A physical return sends back goods that were received AND billed: received − already
     * returned, and billed − already debited, whichever is less, less what other returns not yet
     * dispatched are about to send. Goods received but not yet billed are not returned here: the
     * debit note would reduce a payable that does not exist yet, and the dispatch challan moves
     * nothing without it. Bill what arrived, then return against the bill.
     *
     * Called under the order's row lock, so two returns raised together cannot both pass.
     *
     * @param list<array<string, mixed>> $lines
     * @return list<array<string, mixed>>
     */
    private function admitReturnLines(?int $poId, array $lines, ?int $excludeReturnId): array
    {
        $byId = [];
        if ($poId !== null) {
            foreach (Db::all('SELECT * FROM purchase_order_lines WHERE po_id = :po AND cmp_id = :cmp FOR UPDATE', ['po' => $poId, 'cmp' => $this->ctx->cmpId]) as $l) {
                $byId[(int) $l['line_id']] = $l;
            }
        }
        $pending = [];
        if ($poId !== null) {
            foreach (Db::all(
                "SELECT rl.po_line_id, SUM(rl.return_qty) AS qty
                   FROM purchase_return_lines rl JOIN purchase_returns r ON r.return_id = rl.return_id
                  WHERE r.po_id = :po AND r.cmp_id = :cmp AND r.return_kind = 'physical'
                    AND r.status IN ('" . implode("', '", self::UNDISPATCHED) . "')
                    AND (:exclude::bigint IS NULL OR r.return_id <> :exclude::bigint)
                  GROUP BY rl.po_line_id",
                ['po' => $poId, 'cmp' => $this->ctx->cmpId, 'exclude' => $excludeReturnId],
            ) as $p) {
                $pending[(int) $p['po_line_id']] = (float) $p['qty'];
            }
        }

        $billed = $poId === null ? [] : $this->billedLines($poId);
        $asked = [];
        foreach ($lines as $i => $line) {
            if ($poId !== null) {
                $poLine = $line['po_line_id'] !== null ? ($byId[(int) $line['po_line_id']] ?? null) : null;
                if ($poLine === null) {
                    Http::validationFailed(sprintf('Line %d: name the order line these goods were received on.', (int) $line['line_no']), ['field' => 'lines', 'index' => $i]);
                }
                if ($line['item_id'] !== null && (int) $line['item_id'] !== (int) $poLine['item_id']) {
                    Http::validationFailed(sprintf('Line %d: that item is not the one on order line %d.', (int) $line['line_no'], (int) $poLine['line_no']), ['field' => 'lines', 'index' => $i]);
                }
                $lines[$i]['item_id'] = (int) $poLine['item_id'];
                $lines[$i]['unit_id'] ??= $poLine['unit_id'] === null ? null : (int) $poLine['unit_id'];
                $lines[$i]['warehouse_id'] ??= $poLine['warehouse_id'] === null ? null : (int) $poLine['warehouse_id'];
                if ((float) $line['rate'] <= 0) {
                    // What the supplier billed for these goods — the payable the debit note
                    // reduces — net of the bill's discount; the order's net rate when not billed.
                    $bill = $billed[(int) $poLine['line_id']] ?? null;
                    $lines[$i]['rate'] = $bill !== null
                        ? round((float) ($bill['rate'] ?? 0) * (1 - (float) ($bill['discount_pc'] ?? 0) / 100), 4)
                        : ReceiptService::netRate($poLine);
                    $lines[$i]['line_amount'] = round((float) $line['return_qty'] * $lines[$i]['rate'], 4);
                }
                $asked[(int) $poLine['line_id']] = ($asked[(int) $poLine['line_id']] ?? 0.0) + (float) $line['return_qty'];
            } elseif ($lines[$i]['item_id'] === null) {
                Http::validationFailed(sprintf('Line %d: say which item is going back.', (int) $line['line_no']), ['field' => 'lines', 'index' => $i]);
            }
        }

        foreach ($asked as $poLineId => $qty) {
            $poLine = $byId[$poLineId];
            $kept = (float) $poLine['received_qty'] - (float) $poLine['returned_qty'];
            $billed = (float) $poLine['billed_qty'] - (float) $poLine['debited_qty'];
            $returnable = round(max(0.0, min($kept, $billed) - ($pending[$poLineId] ?? 0.0)), 4);
            if ($qty > $returnable + self::EPSILON) {
                $unbilled = round(max(0.0, $kept - $billed), 4);
                Http::validationFailed(
                    sprintf(
                        'Order line %d: %s can go back, not %s.%s%s',
                        (int) $poLine['line_no'],
                        self::num($returnable),
                        self::num($qty),
                        ($pending[$poLineId] ?? 0) > 0 ? sprintf(' %s more is already on another return.', self::num((float) $pending[$poLineId])) : '',
                        $unbilled > self::EPSILON ? sprintf(' %s received has not been billed yet: bill what arrived, then return it against the bill.', self::num($unbilled)) : '',
                    ),
                    ['field' => 'lines', 'po_line_id' => $poLineId, 'returnable' => $returnable],
                );
            }
        }

        return $lines;
    }

    /** @param array<string, mixed> $document */
    private function applyDispatch(int $returnId, array $document): void
    {
        Db::transaction(function () use ($returnId, $document) {
            $return = Db::first('SELECT po_id FROM purchase_returns WHERE return_id = :id AND cmp_id = :cmp', ['id' => $returnId, 'cmp' => $this->ctx->cmpId]);
            if ($return['po_id'] !== null) {
                PoProgress::lock((int) $return['po_id'], $this->ctx->cmpId);
            }
            $locked = $this->lockReturn($returnId);
            if ($locked['status'] !== 'APPROVED') {
                return;
            }
            $expectReplacement = self::truthy($locked['expect_replacement']);
            foreach ($this->storedLines($returnId) as $line) {
                if ($line['po_line_id'] === null) {
                    continue;
                }
                $closes = 0.0;
                if (!$expectReplacement) {
                    // Nothing is coming in place of these goods: the order stops waiting for them.
                    $poLine = Db::first('SELECT ordered_qty, short_closed_qty FROM purchase_order_lines WHERE line_id = :id', ['id' => (int) $line['po_line_id']]);
                    $closes = round(min((float) $line['return_qty'], max(0.0, (float) $poLine['ordered_qty'] - (float) $poLine['short_closed_qty'])), 4);
                }
                Db::run(
                    'UPDATE purchase_order_lines
                        SET returned_qty = returned_qty + :qty, short_closed_qty = short_closed_qty + :closes, updated_at = NOW()
                      WHERE line_id = :id AND cmp_id = :cmp',
                    ['qty' => (float) $line['return_qty'], 'closes' => $closes, 'id' => (int) $line['po_line_id'], 'cmp' => $this->ctx->cmpId],
                );
                Db::run('UPDATE purchase_return_lines SET closes_order_qty = :c WHERE line_id = :id', ['c' => $closes, 'id' => (int) $line['line_id']]);
            }
            Db::update('purchase_returns', [
                'status'                  => 'DISPATCHED',
                'inventory_document_id'   => self::id($document['document_id'] ?? null),
                'inventory_document_uuid' => self::text((string) ($document['document_uuid'] ?? '')),
                'dispatched_at'           => self::now(),
                'updated_at'              => self::now(),
            ], ['return_id' => $returnId, 'cmp_id' => $this->ctx->cmpId]);
            if ($locked['po_id'] !== null) {
                PoProgress::recompute((int) $locked['po_id'], $this->ctx->cmpId, $this->auth->uuid);
            }
        });

        Audit::record($this->ctx, $this->auth, 'return.dispatched', 'purchase_return', $returnId, null, [
            'inventory_document_id' => $document['document_id'] ?? null,
        ]);
    }

    /**
     * @param array<string, mixed> $return
     * @return array<string, mixed>
     */
    private function debitNotePayload(array $return, bool $financial, ?array $supply = null): array
    {
        $payload = [
            'vch_date'     => (string) $return['return_date'],
            // Where the supplier supplies from (PlaceOfSupply): the GST it reverses is split the
            // way the purchase's was.
            'party'        => array_filter([
                'acc_id'         => (int) $return['supplier_account_id'],
                'pos_state_code' => $supply['pos_state_code'] ?? null,
            ], static fn ($v) => $v !== null),
            // The debit note's own reference on the supplier's account: the return number.
            'bill'         => ['bill_ref' => (string) $return['return_no'], 'bill_date' => (string) $return['return_date'], 'dr_cr' => 1],
            'narration'    => ($financial ? 'Debit note (no goods returned) — ' . ($return['adjustment_reason'] ?? '') : 'Debit note against purchase return ') . ' ' . $return['return_no'],
            'reference_no' => (string) $return['return_no'],
            'bo_id'        => (int) $return['bo_id'],

            'source_app'           => 'purchases',
            'source_document_type' => 'purchases.return',
            'source_document_id'   => (int) $return['return_id'],
            'source_document_uuid' => (string) $return['return_uuid'],
        ];
        if (($supply['supply_nature'] ?? null) !== null) {
            $payload['supply_nature'] = $supply['supply_nature'];
        }

        if ($financial) {
            // An adjustment reverses tax only under the category it names (or the one its
            // order line was billed under); without one it is booked untaxed, as before.
            $payload['service_lines'] = array_values(array_map(static fn (array $line) => array_filter([
                'description'     => $line['reason_code'] ?? 'Adjustment',
                'purchase_acc_id' => (int) $return['adjustment_acc_id'],
                'qty'             => (float) $line['return_qty'],
                'rate'            => (float) $line['rate'],
                'amount'          => (float) $line['line_amount'],
                'tax_cat_id'      => isset($line['tax_cat_id']) ? (int) $line['tax_cat_id'] : null,
                'hsn_sac'         => $line['hsn_sac'] ?? null,
            ], static fn ($v) => $v !== null), $return['lines']));

            return $payload;
        }

        $documentId = (int) ($return['inventory_document_id'] ?? 0);
        if ($documentId <= 0) {
            $dispatch = IntegrationCommand::latest($this->ctx->cmpId, self::COMMAND_RETURN_DISPATCH, 'purchase_return', (int) $return['return_id']);
            $reference = $dispatch !== null && $dispatch['status'] === IntegrationCommand::COMPLETED ? (array) ($dispatch['external_reference'] ?? []) : [];
            if (($reference['document_type'] ?? null) !== 'DELIVERY_CHALLAN' || (int) ($reference['inventory_document_id'] ?? 0) <= 0) {
                Http::conflict('These goods left stock when they were dispatched, before returns went out on a challan; raising the debit note here would issue them again. Raise it in Books, where the stock effect can be chosen.');
            }
            $documentId = (int) $reference['inventory_document_id'];
        }
        $stockLines = array_values(array_filter($return['lines'], static fn (array $l) => $l['item_id'] !== null));
        // The goods leave once, with this debit note, by settling the dispatch challan.
        $payload['stock_effect'] = 'from_challan';
        $payload['challan_settlements'] = array_map(static fn (array $line) => array_filter([
            'source_document_id' => $documentId,
            'item_id'            => (int) $line['item_id'],
            'qty'                => (float) $line['return_qty'],
            'mc_id'              => $line['warehouse_id'] === null ? null : (int) $line['warehouse_id'],
        ], static fn ($v) => $v !== null), $stockLines);
        $payload['inventory_lines'] = array_values(array_map(static fn (array $line) => array_filter([
            'source_line_ref' => (string) $line['line_id'],
            'item_id'         => (int) $line['item_id'],
            'unit_id'         => $line['unit_id'] === null ? null : (int) $line['unit_id'],
            'mc_id'           => $line['warehouse_id'] === null ? null : (int) $line['warehouse_id'],
            'batch_id'        => $line['batch_id'] === null ? null : (int) $line['batch_id'],
            // A debit note's item line is on the credit side in Books.
            'dr_cr'           => 2,
            'qty'             => (float) $line['return_qty'],
            'rate'            => (float) $line['rate'],
            'amount'          => (float) $line['line_amount'],
            // The category the goods were billed under, so the GST reversed is the GST charged —
            // not the item master's default, which a bill line may have changed.
            'tax_cat_id'      => isset($line['tax_cat_id']) ? (int) $line['tax_cat_id'] : null,
            'hsn_sac'         => $line['hsn_sac'] ?? null,
        ], static fn ($v) => $v !== null), $stockLines));

        return $payload;
    }

    /** @param array<string, mixed> $document */
    private static function dispatchMismatch(array $document, int $returnId): ?string
    {
        if ((int) ($document['document_id'] ?? 0) <= 0) {
            return 'Inventory answered without a document.';
        }
        if (isset($document['source_document_type']) && $document['source_document_type'] !== 'purchases.return') {
            return 'Inventory answered with a document that is not a purchase return.';
        }
        if (isset($document['source_document_id']) && (int) $document['source_document_id'] !== $returnId) {
            return 'Inventory answered with the dispatch of a different return.';
        }

        return null;
    }

    /** @param array<string, mixed> $attempt */
    private function failWith(array $attempt, string $service, string $what): never
    {
        $message = (string) ($attempt['message'] ?? $service . ' did not accept ' . $what . '.');
        $refused = in_array($attempt['outcome'], ['blocked', 'already_blocked', 'withdrawn'], true);
        Http::error(
            $refused || $attempt['outcome'] === 'in_progress' ? 409 : 502,
            match (true) {
                $refused => strtolower($service) . '_refused',
                $attempt['outcome'] === 'in_progress' => 'return_in_progress',
                $attempt['outcome'] === 'uncertain' => strtolower($service) . '_uncertain',
                default => strtolower($service) . '_unavailable',
            },
            match (true) {
                $refused => $message,
                $attempt['outcome'] === 'in_progress' => 'This is being sent to ' . $service . ' right now. Wait a moment and refresh.',
                $attempt['outcome'] === 'uncertain' => $service . ' did not confirm ' . $what . '. It may have been recorded — Retry cannot record it twice.',
                default => 'Could not reach ' . $service . ' for ' . $what . '. Nothing has changed — press Retry.',
            },
            ['retryable' => !$refused, 'detail' => $message],
        );
    }

    private function assertFinancialAuthority(?int $adjustmentAcc, ?string $reason): void
    {
        Permissions::assert($this->ctx, $this->auth, 'return.financial_adjustment');
        if ($adjustmentAcc === null) {
            Http::validationFailed('Choose the ledger this adjustment is booked to.', ['field' => 'adjustment_acc_id']);
        }
        if ($reason === null) {
            Http::validationFailed('Say why a debit note is raised with nothing going back.', ['field' => 'adjustment_reason']);
        }
    }

    /** @return array<string, mixed> */
    private function requireReturn(int $returnId): array
    {
        $return = $this->findReturn($returnId);
        if ($return === []) {
            Http::notFound('That return does not exist.');
        }

        return $return;
    }

    /** @return array<string, mixed> */
    private function lockReturn(int $returnId): array
    {
        $row = Db::first('SELECT * FROM purchase_returns WHERE return_id = :id AND cmp_id = :cmp FOR UPDATE', ['id' => $returnId, 'cmp' => $this->ctx->cmpId]);
        if ($row === null) {
            Http::notFound('That return does not exist.');
        }

        return $row;
    }

    /** @return list<array<string, mixed>> */
    private function storedLines(int $returnId): array
    {
        return array_map(static function (array $l): array {
            foreach (['po_line_id', 'item_id', 'unit_id', 'warehouse_id', 'batch_id', 'tax_cat_id'] as $k) {
                $l[$k] = $l[$k] === null ? null : (int) $l[$k];
            }

            return $l;
        }, Db::all('SELECT * FROM purchase_return_lines WHERE return_id = :id AND cmp_id = :cmp ORDER BY line_no', ['id' => $returnId, 'cmp' => $this->ctx->cmpId]));
    }

    /** @return list<array<string, mixed>> */
    private function normaliseReturnLines(mixed $raw, string $kind): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $lines = [];
        $lineNo = 0;
        foreach ($raw as $line) {
            if (!is_array($line)) {
                continue;
            }
            $qty = round((float) ($line['return_qty'] ?? $line['qty'] ?? ($kind === 'financial' ? 1 : 0)), 4);
            if ($qty <= 0) {
                continue;
            }
            $rate = round((float) ($line['rate'] ?? 0), 4);
            if ($kind === 'financial' && $rate <= 0) {
                Http::validationFailed('Each line of a financial return needs an amount.', ['field' => 'lines']);
            }

            $lines[] = [
                'line_no'      => ++$lineNo,
                'po_line_id'   => self::id($line['po_line_id'] ?? null),
                'item_id'      => $kind === 'financial' ? null : self::id($line['item_id'] ?? null),
                'unit_id'      => self::id($line['unit_id'] ?? null),
                'warehouse_id' => self::id($line['warehouse_id'] ?? null),
                'batch_id'     => self::id($line['batch_id'] ?? null),
                'return_qty'   => $qty,
                'rate'         => $rate,
                'line_amount'  => round($qty * $rate, 4),
                'reason_code'  => self::text($line['reason_code'] ?? $line['description'] ?? null),
                'tax_cat_id'   => self::id($line['tax_cat_id'] ?? null),
                'hsn_sac'      => self::text($line['hsn_sac'] ?? null),
            ];
        }

        return $lines;
    }

    private static function num(float $value): string
    {
        return rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');
    }

    private static function truthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_string($value)) {
            return in_array(strtolower($value), ['t', 'true', '1', 'yes', 'on'], true);
        }

        return (bool) $value;
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

    private static function date(mixed $value): string
    {
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($value)) === 1) {
            return trim($value);
        }

        return gmdate('Y-m-d');
    }

    private static function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }
}
