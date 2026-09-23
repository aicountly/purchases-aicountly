<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain;

use Aicountly\Api\Audit;
use Aicountly\Api\Auth;
use Aicountly\Api\Clients\BooksClient;
use Aicountly\Api\Clients\InventoryClient;
use Aicountly\Api\Context;
use Aicountly\Api\Dashboards\Decimal;
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

    /**
     * Every state a purchase return can be in, in the order it passes through
     * them. The migration comments the same list on the column; this is the one
     * the code filters and validates against.
     */
    public const STATUSES = ['DRAFT', 'APPROVED', 'DISPATCHED', 'DEBITED', 'CLOSED', 'CANCELLED'];

    /** The supplier's own credit note against a return — never our accounting. */
    public const CREDIT_STATUSES = ['PENDING', 'RECEIVED', 'NOT_REQUIRED'];

    /**
     * Why goods go back.
     *
     * A fixed vocabulary rather than free text, because the reason is what the
     * analytics group by: "damaged", "Damaged" and "dmgd" are three columns on
     * a chart that should have one. `reason_note` carries the detail.
     *
     * @var array<string, string>
     */
    public const REASONS = [
        'damaged'          => 'Damaged goods',
        'wrong_item'       => 'Wrong item',
        'quality'          => 'Quality issue',
        'short_supply'     => 'Short supply',
        'price_difference' => 'Price difference',
        'expired'          => 'Expired or near expiry',
        'excess'           => 'Excess delivery',
        'other'            => 'Other',
    ];

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

        // The reason is what every chart on the workspace groups by, so it is a
        // vocabulary rather than free text. The detail goes in `reason_note`.
        $reason = self::text($input['reason_code'] ?? null);
        if ($reason !== null && !isset(self::REASONS[$reason])) {
            Http::validationFailed(
                'Return reason must be one of: ' . implode(', ', array_keys(self::REASONS)) . '.',
                ['field' => 'reason_code'],
            );
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
                // The supplier's name as it reads today. A label kept beside the
                // id so a register can list five hundred returns without asking
                // Books for five hundred ledger names; the account itself is
                // still Books', and is still read from Books wherever it matters.
                'supplier_name_snapshot' => self::text($input['supplier_name'] ?? null),
                'status'              => 'DRAFT',
                'reason_code'         => self::text($input['reason_code'] ?? null),
                'reason_note'         => self::text($input['reason_note'] ?? null),
                'expected_pickup_date' => self::optionalDate($input['expected_pickup_date'] ?? null),
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

    /** Ask Inventory to move the goods out. */
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

        $command = IntegrationCommand::open($this->ctx, 'inventory', self::COMMAND_RETURN_DISPATCH, 'purchase_return', $returnId, ['lines' => count($stockLines)]);
        $commandId = (int) $command['command_id'];
        if (($command['status'] ?? '') === IntegrationCommand::COMPLETED) {
            return $this->findReturn($returnId);
        }
        IntegrationCommand::markPosting($commandId);

        $payload = [
            'document_type'        => 'PURCHASE_RETURN',
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

        $response = (new InventoryClient())->withService($this->auth->uuid)->postDocument($this->ctx, $payload, (string) $command['idempotency_key']);

        if (!$response['ok']) {
            $message = $response['error'] ?? 'Inventory did not accept the return.';
            in_array($response['status'], [409, 422], true)
                ? IntegrationCommand::block($commandId, $message)
                : IntegrationCommand::fail($commandId, $message);
            Http::error(502, 'inventory_unavailable', 'Could not reach Inventory to send these goods back. Nothing has moved — press Retry.', ['retryable' => true, 'detail' => $message]);
        }

        $document = $response['body']['data'] ?? [];
        IntegrationCommand::complete($commandId, ['inventory_document_uuid' => $document['document_uuid'] ?? null]);

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

        $command = IntegrationCommand::open($this->ctx, 'books', self::COMMAND_DEBIT_NOTE, 'purchase_return', $returnId, ['return_no' => $return['return_no']]);
        $commandId = (int) $command['command_id'];
        IntegrationCommand::markPosting($commandId);

        $payload = [
            'vch_date'     => (string) $return['return_date'],
            'party_acc_id' => (int) $return['supplier_account_id'],
            'narration'    => 'Debit note against purchase return ' . $return['return_no'],
            'reference_no' => (string) $return['return_no'],
            'bo_id'        => (int) $return['bo_id'],

            'source_app'           => 'purchases',
            'source_document_type' => 'purchases.return',
            'source_document_id'   => $returnId,
            'source_document_uuid' => (string) $return['return_uuid'],

            'inventory_lines' => array_values(array_map(static fn (array $line) => [
                'source_line_ref' => (string) $line['line_id'],
                'item_id'         => $line['item_id'] === null ? null : (int) $line['item_id'],
                'unit_id'         => $line['unit_id'] === null ? null : (int) $line['unit_id'],
                'mc_id'           => $line['warehouse_id'] === null ? null : (int) $line['warehouse_id'],
                'qty'             => (float) $line['return_qty'],
                'rate'            => (float) $line['rate'],
                'amount'          => (float) $line['line_amount'],
            ], array_filter($return['lines'], static fn (array $l) => $l['item_id'] !== null))),
        ];

        $response = (new BooksClient())
            ->withService($this->auth->uuid)
            ->createAndPostVoucher($this->ctx, BooksClient::VCH_DEBIT_NOTE, $payload, (string) $command['idempotency_key']);

        if (!$response['ok']) {
            $message = $response['error'] ?? 'Books did not accept the debit note.';
            in_array($response['status'], [409, 422], true)
                ? IntegrationCommand::block($commandId, $message)
                : IntegrationCommand::fail($commandId, $message);
            Http::error(502, 'books_unavailable', 'Could not reach Books to raise the debit note. Nothing has been posted — press Retry.', ['retryable' => true, 'detail' => $message]);
        }

        $voucher = $response['body']['data'] ?? [];
        IntegrationCommand::complete($commandId, [
            'books_debit_note_id'   => $voucher['vch_txn_id'] ?? null,
            'books_debit_note_uuid' => $voucher['vch_uuid'] ?? null,
        ]);

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

    /**
     * Record the supplier's own credit note against a return.
     *
     * THIS IS NOT AN ACCOUNTING ENTRY. It is the buyer's note of a document the
     * supplier issued — the reference, the date, the amount they agreed to —
     * which is the same class of fact as the return reason and belongs to the
     * procurement workflow. What that credit does to the payable is Smart Books'
     * and is posted there, by Books, through the debit note above.
     *
     * @param array<string, mixed> $input
     */
    public function recordSupplierCredit(int $returnId, array $input): array
    {
        Permissions::assert($this->ctx, $this->auth, 'return.approve');

        $return = $this->findReturn($returnId);
        if ($return === []) {
            Http::notFound('That return does not exist.');
        }

        $status = strtoupper((string) ($input['supplier_credit_status'] ?? 'RECEIVED'));
        if (!in_array($status, self::CREDIT_STATUSES, true)) {
            Http::validationFailed(
                'Supplier credit status must be one of: ' . implode(', ', self::CREDIT_STATUSES) . '.',
                ['field' => 'supplier_credit_status'],
            );
        }

        $reference = self::text($input['supplier_credit_ref'] ?? null);
        if ($status === 'RECEIVED' && $reference === null) {
            Http::validationFailed('Give the credit note reference the supplier issued.', ['field' => 'supplier_credit_ref']);
        }

        $amount = $input['supplier_credit_amount'] ?? null;
        $amount = ($amount === null || $amount === '') ? null : round((float) $amount, 4);
        if ($amount !== null && $amount < 0) {
            Http::validationFailed('A supplier credit cannot be negative.', ['field' => 'supplier_credit_amount']);
        }

        Db::update('purchase_returns', [
            'supplier_credit_status' => $status,
            // Cleared rather than kept when the credit is withdrawn: a reference
            // left behind on a PENDING return reads as though it arrived.
            'supplier_credit_ref'    => $status === 'RECEIVED' ? $reference : null,
            'supplier_credit_date'   => $status === 'RECEIVED' ? self::optionalDate($input['supplier_credit_date'] ?? null) ?? gmdate('Y-m-d') : null,
            'supplier_credit_amount' => $status === 'RECEIVED' ? $amount : null,
            'updated_at'             => self::now(),
        ], ['return_id' => $returnId, 'cmp_id' => $this->ctx->cmpId]);

        Audit::record($this->ctx, $this->auth, 'return.supplier_credit', 'purchase_return', $returnId, [
            'supplier_credit_status' => $return['supplier_credit_status'] ?? null,
        ], ['supplier_credit_status' => $status, 'supplier_credit_ref' => $reference]);

        return $this->findReturn($returnId);
    }

    /**
     * Abandon a return that has not moved anything yet.
     *
     * Deliberately refused once Inventory has taken the goods out or Books has
     * raised the debit note: cancelling here would leave this product saying a
     * return never happened while two others hold documents that say it did.
     * Reversing one of those is their operation, not a status change here.
     *
     * @param array<string, mixed> $input
     */
    public function cancelReturn(int $returnId, array $input): array
    {
        Permissions::assert($this->ctx, $this->auth, 'return.approve');

        $return = $this->findReturn($returnId);
        if ($return === []) {
            Http::notFound('That return does not exist.');
        }
        if (!in_array($return['status'], ['DRAFT', 'APPROVED'], true)) {
            Http::conflict('A return that is ' . strtolower(str_replace('_', ' ', (string) $return['status'])) . ' cannot be cancelled here. Reverse it in the product that holds the document.');
        }

        $reason = self::text($input['reason'] ?? $input['cancel_reason'] ?? null);
        if ($reason === null) {
            Http::validationFailed('Say why this return is being cancelled.', ['field' => 'reason']);
        }

        Db::update('purchase_returns', [
            'status'        => 'CANCELLED',
            'cancel_reason' => $reason,
            'updated_at'    => self::now(),
        ], ['return_id' => $returnId, 'cmp_id' => $this->ctx->cmpId]);

        Audit::record($this->ctx, $this->auth, 'return.cancelled', 'purchase_return', $returnId, ['status' => $return['status']], ['status' => 'CANCELLED'], $reason);

        return $this->findReturn($returnId);
    }

    /** @return array<string, mixed> */
    public function findReturn(int $returnId): array
    {
        $row = Db::first(
            'SELECT r.*, p.po_no, p.currency_code
               FROM purchase_returns r
               LEFT JOIN purchase_orders p ON p.po_id = r.po_id
              WHERE r.return_id = :id AND r.cmp_id = :cmp',
            ['id' => $returnId, 'cmp' => $this->ctx->cmpId],
        );
        if ($row === null) {
            return [];
        }
        $row['lines'] = Db::all('SELECT * FROM purchase_return_lines WHERE return_id = :id ORDER BY line_no', ['id' => $returnId]);
        $row['commands'] = IntegrationCommand::forEntity($this->ctx, 'purchase_return', $returnId);
        $row += self::totals($row['lines']);

        return $row;
    }

    /**
     * The register query.
     *
     * Every filter the workspace offers is applied HERE, in SQL, against the
     * whole table — never to the page that happened to be fetched. A screen that
     * filters its own current page tells the user there are three matching
     * returns when there are ninety.
     *
     * @param array<string, mixed> $filters
     * @return array{rows:list<array<string, mixed>>, total:int}
     */
    public function searchReturns(array $filters, int $limit, int $offset, string $sort, string $order): array
    {
        [$clause, $params] = $this->registerClause($filters);

        // The line aggregate is lateral rather than a grouped subquery: grouping
        // the whole table to draw twenty-five rows is a sort of every return
        // line in the company on every page turn.
        $from = 'FROM purchase_returns r
                 LEFT JOIN purchase_orders p ON p.po_id = r.po_id
                 LEFT JOIN LATERAL (
                     SELECT COUNT(*) AS line_count, COALESCE(SUM(l.line_amount), 0) AS return_value
                     FROM purchase_return_lines l WHERE l.return_id = r.return_id
                 ) agg ON TRUE';

        $sortable = [
            'return_date'  => 'r.return_date',
            'return_no'    => 'r.return_no',
            'status'       => 'r.status',
            'created_at'   => 'r.created_at',
            'return_value' => 'agg.return_value',
            'supplier'     => 'supplier_name',
        ];
        $sortColumn = $sortable[$sort] ?? 'r.return_date';

        $select = "SELECT r.*,
                          agg.line_count::int AS line_count,
                          agg.return_value::text AS return_value,
                          p.po_no,
                          p.currency_code,
                          COALESCE(r.supplier_name_snapshot, p.supplier_name_snapshot) AS supplier_name";

        return [
            'rows'  => Db::all("{$select} {$from} WHERE {$clause} ORDER BY {$sortColumn} {$order}, r.return_id {$order} LIMIT {$limit} OFFSET {$offset}", $params),
            'total' => (int) Db::scalar("SELECT COUNT(*) {$from} WHERE {$clause}", $params),
        ];
    }

    /**
     * The WHERE clause the register, its export and its totals all share.
     *
     * One clause, three callers. An export built from a second set of
     * conditions is an export that disagrees with the screen the first time
     * either one changes.
     *
     * @param array<string, mixed> $filters
     * @return array{0:string, 1:array<string, mixed>}
     */
    public function registerClause(array $filters): array
    {
        [$scope, $params] = $this->ctx->scopeClause('r');
        $where = [$scope];

        // A comma-separated list, because the workspace filters by "everything
        // still open" as readily as by one status.
        $statuses = self::statusList($filters['status'] ?? null);
        if ($statuses !== []) {
            $names = [];
            foreach ($statuses as $index => $status) {
                $names[] = ':status' . $index;
                $params['status' . $index] = $status;
            }
            $where[] = 'r.status IN (' . implode(', ', $names) . ')';
        }

        if (!empty($filters['supplier_account_id'])) {
            $where[] = 'r.supplier_account_id = :supplier';
            $params['supplier'] = (int) $filters['supplier_account_id'];
        }
        if (!empty($filters['po_id'])) {
            $where[] = 'r.po_id = :po_id';
            $params['po_id'] = (int) $filters['po_id'];
        }
        if (!empty($filters['reason_code'])) {
            $where[] = 'r.reason_code = :reason';
            $params['reason'] = (string) $filters['reason_code'];
        }
        if (!empty($filters['created_by'])) {
            $where[] = 'r.created_by = :created_by';
            $params['created_by'] = (string) $filters['created_by'];
        }
        if (!empty($filters['from'])) {
            $where[] = 'r.return_date >= :from_date';
            $params['from_date'] = (string) $filters['from'];
        }
        if (!empty($filters['to'])) {
            $where[] = 'r.return_date <= :to_date';
            $params['to_date'] = (string) $filters['to'];
        }

        $credit = strtoupper((string) ($filters['supplier_credit'] ?? ''));
        if (in_array($credit, self::CREDIT_STATUSES, true)) {
            $where[] = 'r.supplier_credit_status = :credit';
            $params['credit'] = $credit;
        }

        // Inventory and Books are asked whether the document EXISTS, which is
        // what this product knows. What state it is in over there is read from
        // them, live, on the return itself.
        $inventory = strtoupper((string) ($filters['inventory'] ?? ''));
        if ($inventory === 'POSTED') {
            $where[] = 'r.inventory_document_uuid IS NOT NULL';
        } elseif ($inventory === 'PENDING') {
            $where[] = 'r.inventory_document_uuid IS NULL';
        }

        $books = strtoupper((string) ($filters['books'] ?? ''));
        if ($books === 'POSTED') {
            $where[] = 'r.books_debit_note_uuid IS NOT NULL';
        } elseif ($books === 'PENDING') {
            $where[] = 'r.books_debit_note_uuid IS NULL';
        }

        if (isset($filters['min_value']) && $filters['min_value'] !== '' && $filters['min_value'] !== null) {
            $where[] = 'agg.return_value >= :min_value';
            $params['min_value'] = round((float) $filters['min_value'], 4);
        }
        if (isset($filters['max_value']) && $filters['max_value'] !== '' && $filters['max_value'] !== null) {
            $where[] = 'agg.return_value <= :max_value';
            $params['max_value'] = round((float) $filters['max_value'], 4);
        }

        if (!empty($filters['q'])) {
            $where[] = '(r.return_no ILIKE :term
                         OR r.reason_note ILIKE :term
                         OR r.supplier_name_snapshot ILIKE :term
                         OR r.supplier_credit_ref ILIKE :term
                         OR p.po_no ILIKE :term
                         OR p.supplier_name_snapshot ILIKE :term)';
            $params['term'] = '%' . $filters['q'] . '%';
        }

        return [implode(' AND ', $where), $params];
    }

    /**
     * Line count and value, added up from the lines themselves.
     *
     * @param list<array<string, mixed>> $lines
     * @return array{line_count:int, return_value:string}
     */
    private static function totals(array $lines): array
    {
        $total = '0';
        foreach ($lines as $line) {
            $total = Decimal::add($total, Decimal::of($line['line_amount'] ?? '0'));
        }

        return ['line_count' => count($lines), 'return_value' => $total];
    }

    /**
     * @return list<string>
     */
    private static function statusList(mixed $raw): array
    {
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        $statuses = [];
        foreach (explode(',', $raw) as $candidate) {
            $status = strtoupper(trim($candidate));
            if ($status !== '' && in_array($status, self::STATUSES, true)) {
                $statuses[] = $status;
            }
        }

        return array_values(array_unique($statuses));
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

    /** A date the caller may legitimately leave unset, unlike the return date. */
    private static function optionalDate(mixed $value): ?string
    {
        return (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($value)) === 1) ? trim($value) : null;
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
