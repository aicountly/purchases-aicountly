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
 * Three products again, three responsibilities:
 *
 *   Purchases  the commercial decision — may this go back, on what terms
 *   Inventory  the stock moving out of the warehouse
 *   Books      the debit note and what it does to the payable
 *
 * A claim is different from a return: a shortage or a rate difference has no
 * goods to send back, only money to recover. Both belong here because both are
 * negotiations with a supplier, and neither is an accounting entry until Books
 * says so.
 */
final class ReturnClaimService
{
    public const COMMAND_RETURN_DISPATCH = 'purchases.return.dispatch';
    public const COMMAND_DEBIT_NOTE      = 'purchases.return.debit_note';

    public function __construct(
        private readonly Context $ctx,
        private readonly Auth $auth,
    ) {
    }

    // -----------------------------------------------------------------------
    // Returns
    // -----------------------------------------------------------------------

    /** @param array<string, mixed> $input */
    public function createReturn(array $input): array
    {
        Permissions::assert($this->ctx, $this->auth, 'return.create');

        $supplierId = (int) ($input['supplier_account_id'] ?? 0);
        if ($supplierId <= 0) {
            Http::validationFailed('Say which supplier this return goes back to.', ['field' => 'supplier_account_id']);
        }

        $lines = $this->normaliseReturnLines($input['lines'] ?? []);
        if ($lines === []) {
            Http::validationFailed('A return needs at least one line.', ['field' => 'lines']);
        }

        return Db::transaction(function () use ($input, $supplierId, $lines) {
            $no = NumberSeries::next($this->ctx, 'return');

            $returnId = (int) Db::insert('purchase_returns', [
                'cmp_id'              => $this->ctx->cmpId,
                'fy_id'               => $this->ctx->fyId,
                'bo_id'               => $this->ctx->boId,
                'return_no'           => $no,
                'return_date'         => self::date($input['return_date'] ?? null),
                'po_id'               => self::id($input['po_id'] ?? null),
                'supplier_account_id' => $supplierId,
                'status'              => 'DRAFT',
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
                ], 'line_id');
            }

            Audit::record($this->ctx, $this->auth, 'return.created', 'purchase_return', $returnId, null, ['return_no' => $no]);

            return $this->findReturn($returnId);
        });
    }

    public function approveReturn(int $returnId, array $input): array
    {
        Permissions::assert($this->ctx, $this->auth, 'return.approve');

        $return = $this->findReturn($returnId);
        if ($return === []) {
            Http::notFound('That return does not exist.');
        }
        if ($return['status'] !== 'DRAFT') {
            Http::conflict('This return is already ' . strtolower((string) $return['status']) . '.');
        }

        Db::update('purchase_returns', ['status' => 'APPROVED', 'updated_at' => self::now()], [
            'return_id' => $returnId, 'cmp_id' => $this->ctx->cmpId,
        ]);

        Audit::record($this->ctx, $this->auth, 'return.approved', 'purchase_return', $returnId, ['status' => 'DRAFT'], ['status' => 'APPROVED'], self::text($input['note'] ?? null) ?? '');

        return $this->findReturn($returnId);
    }

    /**
     * Record the goods leaving for the supplier: a DELIVERY_CHALLAN (challan_only) in Inventory —
     * goods out on challan, pending the debit note.
     *
     * ONE OWNER PER MOVEMENT. The stock leaves once, with the debit note: Books sends its item
     * lines to Inventory as a PURCHASE_RETURN that settles this challan (from_challan). The
     * dispatch used to be a PURCHASE_RETURN of its own, and the debit note issued the same goods
     * again.
     */
    public function dispatchReturn(int $returnId): array
    {
        Permissions::assert($this->ctx, $this->auth, 'return.approve');

        $return = $this->findReturn($returnId);
        if ($return === []) {
            Http::notFound('That return does not exist.');
        }
        if ($return['status'] !== 'APPROVED') {
            Http::conflict('Approve the return before sending the goods back.');
        }

        $stockLines = array_values(array_filter($return['lines'], static fn (array $l) => $l['item_id'] !== null && (float) $l['return_qty'] > 0));
        if ($stockLines === []) {
            Http::validationFailed('There is nothing on this return to send back.');
        }

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
                'source_line_ref' => (string) $line['line_id'],
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
            return $this->findReturn($returnId);
        }
        if ($attempt['outcome'] !== 'completed') {
            $message = (string) ($attempt['message'] ?? 'Inventory did not accept the return.');
            Http::error(
                in_array($attempt['outcome'], ['blocked', 'already_blocked', 'withdrawn'], true) ? 409 : 502,
                match ($attempt['outcome']) {
                    'blocked', 'already_blocked', 'withdrawn' => 'inventory_refused',
                    'in_progress' => 'return_in_progress',
                    'uncertain' => 'inventory_uncertain',
                    default => 'inventory_unavailable',
                },
                match ($attempt['outcome']) {
                    'blocked', 'already_blocked', 'withdrawn' => $message,
                    'in_progress' => 'This return is being sent to Inventory right now. Wait a moment and refresh.',
                    'uncertain' => 'Inventory did not confirm the dispatch. It may have been recorded — Retry cannot record it twice.',
                    default => 'Could not reach Inventory to send these goods back. Nothing has moved — press Retry.',
                },
                ['retryable' => !in_array($attempt['outcome'], ['blocked', 'already_blocked', 'withdrawn'], true), 'detail' => $message],
            );
        }

        $document = $attempt['response']['body']['data'] ?? [];
        IntegrationCommand::complete((int) $command['command_id'], (string) $attempt['lease'], [
            'inventory_document_id'   => $document['document_id'] ?? null,
            'inventory_document_uuid' => $document['document_uuid'] ?? null,
            // What the dispatch is in Inventory: the debit note settles a DELIVERY_CHALLAN. One
            // recorded before this was a PURCHASE_RETURN — the goods already left stock.
            'document_type'           => 'DELIVERY_CHALLAN',
        ], !empty($document['duplicate']) ? 'replay' : 'response', (int) $attempt['response']['status']);

        Db::transaction(function () use ($returnId, $document, $stockLines) {
            Db::update('purchase_returns', [
                'status' => 'DISPATCHED',
                'inventory_document_uuid' => self::text($document['document_uuid'] ?? null),
                'updated_at' => self::now(),
            ], ['return_id' => $returnId, 'cmp_id' => $this->ctx->cmpId]);

            foreach ($stockLines as $line) {
                if ($line['po_line_id'] !== null) {
                    Db::run(
                        'UPDATE purchase_order_lines SET returned_qty = returned_qty + :qty, updated_at = :now WHERE line_id = :id',
                        ['qty' => (float) $line['return_qty'], 'now' => self::now(), 'id' => (int) $line['po_line_id']],
                    );
                }
            }
        });

        Audit::record($this->ctx, $this->auth, 'return.dispatched', 'purchase_return', $returnId, null, [
            'inventory_document_uuid' => $document['document_uuid'] ?? null,
        ]);

        return $this->findReturn($returnId);
    }

    /**
     * How the debit note's goods leave stock: settling the dispatch challan, or issued by the
     * debit note itself when nothing was dispatched through here.
     *
     * @param list<array<string, mixed>> $stockLines
     * @return array{stock_effect: string, challan_settlements: list<array<string, mixed>>}
     */
    private function debitNoteStockEffect(int $returnId, array $stockLines): array
    {
        $dispatch = Db::first(
            "SELECT status, external_reference FROM purchase_integration_commands
             WHERE cmp_id = :cmp AND entity_type = 'purchase_return' AND entity_id = :id AND command_type = :type
             ORDER BY command_id DESC LIMIT 1",
            ['cmp' => $this->ctx->cmpId, 'id' => $returnId, 'type' => self::COMMAND_RETURN_DISPATCH],
        );
        if ($dispatch === null || $dispatch['status'] !== IntegrationCommand::COMPLETED) {
            return ['stock_effect' => 'on_invoice', 'challan_settlements' => []];
        }
        $reference = Db::jsonColumn($dispatch['external_reference'] ?? null);
        if (($reference['document_type'] ?? null) !== 'DELIVERY_CHALLAN' || (int) ($reference['inventory_document_id'] ?? 0) <= 0) {
            // Dispatched before the dispatch was a challan: those goods already left stock.
            Http::conflict('These goods left stock when they were dispatched, before returns went out on a challan; raising the debit note here would issue them again. Raise it in Books, where the stock effect can be chosen.');
        }
        $documentId = (int) $reference['inventory_document_id'];

        return [
            'stock_effect'        => 'from_challan',
            'challan_settlements' => array_map(static fn (array $line) => [
                'source_document_id' => $documentId,
                'item_id'            => (int) $line['item_id'],
                'qty'                => (float) $line['return_qty'],
                'mc_id'              => $line['warehouse_id'] === null ? null : (int) $line['warehouse_id'],
            ], $stockLines),
        ];
    }

    /** Ask Books for the debit note. Books owns the credit against the payable. */
    public function requestDebitNote(int $returnId): array
    {
        Permissions::assert($this->ctx, $this->auth, 'return.approve');

        $return = $this->findReturn($returnId);
        if ($return === []) {
            Http::notFound('That return does not exist.');
        }
        if (!in_array($return['status'], ['DISPATCHED', 'APPROVED'], true)) {
            Http::conflict('Send the goods back before raising the debit note.');
        }
        if ($return['books_debit_note_uuid'] !== null) {
            Http::conflict('A debit note has already been raised for this return.');
        }

        // Decided before the command is opened, so a refusal leaves nothing POSTING.
        $stockLines = array_values(array_filter($return['lines'], static fn (array $l) => $l['item_id'] !== null));
        $stock = $this->debitNoteStockEffect($returnId, $stockLines);

        $payload = [
            'vch_date'     => (string) $return['return_date'],
            // party.acc_id is what Books composes a debit note from; the flat party_acc_id was
            // never read.
            'party'        => ['acc_id' => (int) $return['supplier_account_id']],
            'narration'    => 'Debit note against purchase return ' . $return['return_no'],
            'reference_no' => (string) $return['return_no'],
            'bo_id'        => (int) $return['bo_id'],

            'source_app'           => 'purchases',
            'source_document_type' => 'purchases.return',
            'source_document_id'   => $returnId,
            'source_document_uuid' => (string) $return['return_uuid'],

            // The goods leave once, with this debit note: settling the dispatch challan when the
            // goods went back on one, or issuing them here when the debit note comes first.
            'stock_effect'        => $stock['stock_effect'],
            'challan_settlements' => $stock['challan_settlements'],

            'inventory_lines' => array_values(array_map(static fn (array $line) => [
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
            ], $stockLines)),
        ];

        $command = IntegrationCommand::ensure($this->ctx, 'books', self::COMMAND_DEBIT_NOTE, 'purchase_return', $returnId, $payload, ['return_no' => $return['return_no']]);
        $scope = Context::of((int) $command['cmp_id'], (int) $command['fy_id'], (int) $command['bo_id']);
        // Books is written as the person raising the debit note, on their session.
        $books = (new BooksClient())->withSession($this->auth->sesKey());
        $attempt = IntegrationCommand::attempt(
            $command,
            static fn (array $body, string $key) => $books->createAndPostVoucher($scope, BooksClient::VCH_DEBIT_NOTE, $body, $key),
        );

        if ($attempt['outcome'] === 'already_completed') {
            $voucher = [
                'vch_txn_id' => $attempt['command']['external_reference']['books_debit_note_id'] ?? null,
                'vch_uuid'   => $attempt['command']['external_reference']['books_debit_note_uuid'] ?? null,
            ];
        } elseif ($attempt['outcome'] !== 'completed') {
            $message = (string) ($attempt['message'] ?? 'Books did not accept the debit note.');
            Http::error(
                in_array($attempt['outcome'], ['blocked', 'already_blocked', 'withdrawn'], true) ? 409 : 502,
                in_array($attempt['outcome'], ['blocked', 'already_blocked', 'withdrawn'], true) ? 'books_refused' : ($attempt['outcome'] === 'uncertain' ? 'books_uncertain' : 'books_unavailable'),
                match ($attempt['outcome']) {
                    'blocked', 'already_blocked', 'withdrawn' => $message,
                    'in_progress' => 'This debit note is being posted right now. Wait a moment and refresh.',
                    'uncertain' => 'Smart Books did not confirm the debit note. It may have been posted — Retry cannot post it twice.',
                    default => 'Could not reach Books to raise the debit note. Nothing has been posted — press Retry.',
                },
                ['retryable' => !in_array($attempt['outcome'], ['blocked', 'already_blocked', 'withdrawn'], true), 'detail' => $message],
            );
        } else {
            $voucher = $attempt['response']['body']['data'] ?? [];
            IntegrationCommand::complete((int) $command['command_id'], (string) $attempt['lease'], [
                'books_debit_note_id'   => $voucher['vch_txn_id'] ?? null,
                'books_debit_note_uuid' => $voucher['vch_uuid'] ?? null,
            ], 'response', (int) $attempt['response']['status']);
        }

        Db::update('purchase_returns', [
            'status'                => 'DEBITED',
            'books_debit_note_id'   => self::id($voucher['vch_txn_id'] ?? $voucher['voucher_id'] ?? null),
            'books_debit_note_uuid' => self::text($voucher['vch_uuid'] ?? $voucher['voucher_uuid'] ?? null),
            'updated_at'            => self::now(),
        ], ['return_id' => $returnId, 'cmp_id' => $this->ctx->cmpId]);

        Audit::record($this->ctx, $this->auth, 'return.debited', 'purchase_return', $returnId, null, [
            'books_debit_note_id' => $voucher['vch_txn_id'] ?? null,
        ]);

        return $this->findReturn($returnId);
    }

    /** @return array<string, mixed> */
    public function findReturn(int $returnId): array
    {
        $row = Db::first('SELECT * FROM purchase_returns WHERE return_id = :id AND cmp_id = :cmp', ['id' => $returnId, 'cmp' => $this->ctx->cmpId]);
        if ($row === null) {
            return [];
        }
        $row['lines'] = Db::all('SELECT * FROM purchase_return_lines WHERE return_id = :id ORDER BY line_no', ['id' => $returnId]);
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

    /** @param array<string, mixed> $input */
    public function updateClaim(int $claimId, string $action, array $input): array
    {
        $claim = $this->findClaim($claimId);
        if ($claim === []) {
            Http::notFound('That claim does not exist.');
        }

        [$permission, $from, $to] = match ($action) {
            'submit'   => ['claim.create', ['DRAFT'], 'SUBMITTED'],
            'respond'  => ['claim.create', ['SUBMITTED'], 'SUPPLIER_RESPONDED'],
            'approve'  => ['claim.settle', ['SUBMITTED', 'SUPPLIER_RESPONDED'], 'APPROVED'],
            'reject'   => ['claim.settle', ['SUBMITTED', 'SUPPLIER_RESPONDED'], 'REJECTED'],
            'settle'   => ['claim.settle', ['APPROVED'], 'SETTLED'],
            default    => Http::validationFailed('Unknown action "' . $action . '".'),
        };

        Permissions::assert($this->ctx, $this->auth, $permission);

        if (!in_array($claim['status'], $from, true)) {
            Http::conflict(sprintf(
                'A claim that is %s cannot be %sed.',
                strtolower(str_replace('_', ' ', (string) $claim['status'])),
                $action,
            ));
        }

        $changes = ['status' => $to, 'updated_at' => self::now()];
        if ($action === 'respond') {
            $changes['supplier_response'] = self::text($input['supplier_response'] ?? null);
        }
        if ($action === 'settle') {
            $settled = round((float) ($input['settled_amount'] ?? $claim['claimed_amount']), 4);
            if ($settled > (float) $claim['claimed_amount']) {
                Http::validationFailed('A claim cannot settle for more than was claimed.', ['field' => 'settled_amount']);
            }
            $changes['settled_amount'] = $settled;
            $changes['settled_at'] = self::now();
        }

        Db::update('purchase_claims', $changes, ['claim_id' => $claimId, 'cmp_id' => $this->ctx->cmpId]);

        Audit::record($this->ctx, $this->auth, 'claim.' . $action, 'claim', $claimId, ['status' => $claim['status']], ['status' => $to], self::text($input['note'] ?? null) ?? '');

        return $this->findClaim($claimId);
    }

    /** @return array<string, mixed> */
    public function findClaim(int $claimId): array
    {
        return Db::first('SELECT * FROM purchase_claims WHERE claim_id = :id AND cmp_id = :cmp', ['id' => $claimId, 'cmp' => $this->ctx->cmpId]) ?? [];
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

    /** @return list<array<string, mixed>> */
    private function normaliseReturnLines(mixed $raw): array
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
            $qty = round((float) ($line['return_qty'] ?? $line['qty'] ?? 0), 4);
            if ($qty <= 0) {
                continue;
            }
            $rate = round((float) ($line['rate'] ?? 0), 4);

            $lines[] = [
                'line_no'      => ++$lineNo,
                'po_line_id'   => self::id($line['po_line_id'] ?? null),
                'item_id'      => self::id($line['item_id'] ?? null),
                'unit_id'      => self::id($line['unit_id'] ?? null),
                'warehouse_id' => self::id($line['warehouse_id'] ?? null),
                'batch_id'     => self::id($line['batch_id'] ?? null),
                'return_qty'   => $qty,
                'rate'         => $rate,
                'line_amount'  => round($qty * $rate, 4),
                'reason_code'  => self::text($line['reason_code'] ?? null),
            ];
        }

        return $lines;
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
