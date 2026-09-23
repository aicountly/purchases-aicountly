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

    /**
     * The kinds of claim this product recognises, and what to call them.
     *
     * ONE LIST, HERE, AND THE SCREEN READS IT. The kinds used to be written
     * twice — once in this validator and once in a dropdown in the React
     * bundle — which is how a build ships with an option the API refuses. The
     * UI now asks `/v1/claims/meta` what the options are, so adding a kind is
     * one edit in one file.
     *
     * The first eight are the original list and their stored values have not
     * changed. The last three are commercial events that were previously all
     * being recorded as `other`, which made them invisible to every report.
     *
     * @var array<string, string>
     */
    public const CLAIM_KINDS = [
        'shortage'       => 'Shortage',
        'damage'         => 'Damaged in transit',
        'rate_difference' => 'Rate difference',
        'scheme'         => 'Scheme or discount not passed',
        'rebate'         => 'Rebate due',
        'quality'        => 'Quality issue',
        'late_delivery'  => 'Late delivery',
        'wrong_item'     => 'Wrong item supplied',
        'excess_billed'  => 'Excess billed',
        'service_issue'  => 'Service issue',
        'other'          => 'Other',
    ];

    /** What the buyer is asking the supplier for. @var array<string, string> */
    public const CLAIM_RESOLUTIONS = [
        'credit_note'       => 'Credit note',
        'refund'            => 'Refund',
        'replacement'       => 'Replacement',
        'rate_adjustment'   => 'Rate adjustment',
        'future_adjustment' => 'Adjust against a future invoice',
        'other'             => 'Other',
    ];

    /** @var array<string, string> */
    public const CLAIM_PRIORITIES = [
        'low'    => 'Low',
        'normal' => 'Normal',
        'high'   => 'High',
        'urgent' => 'Urgent',
    ];

    /** How a claim line says which document it argues from. */
    private const REFERENCE_KINDS = ['po', 'bill', 'grn', 'return', 'none'];

    public const SUBJECT_MAX = 160;
    public const DESCRIPTION_MAX = 1000;
    private const MAX_LINES = 200;
    private const MAX_TAGS = 10;

    /**
     * Everything the New Claim screen needs to render itself.
     *
     * The screen has no hard-coded option list and no hard-coded limit: it asks
     * for them, which is what keeps the two halves from disagreeing after a
     * deploy. `capabilities` is the honest part — it says what this deployment
     * can actually do, so the UI can explain rather than offer a button that
     * fails.
     *
     * @return array<string, mixed>
     */
    public function claimMeta(): array
    {
        return [
            'kinds'       => self::options(self::CLAIM_KINDS),
            'resolutions' => self::options(self::CLAIM_RESOLUTIONS),
            'priorities'  => self::options(self::CLAIM_PRIORITIES),
            'limits'      => [
                'subject_max'     => self::SUBJECT_MAX,
                'description_max' => self::DESCRIPTION_MAX,
                'lines_max'       => self::MAX_LINES,
                'tags_max'        => self::MAX_TAGS,
            ],
            'permissions' => [
                'create' => Permissions::allows($this->ctx, $this->auth, 'claim.create'),
                'settle' => Permissions::allows($this->ctx, $this->auth, 'claim.settle'),
            ],
            'capabilities' => [
                // Purchases stores no files. ImportController reads an upload
                // and deletes it in the same request, deliberately, and there is
                // no document store behind this product to put a claim's
                // evidence in. The screen is told so rather than being given an
                // upload button that quietly loses what is dropped on it.
                'attachments' => [
                    'available' => false,
                    'reason'    => 'Supporting documents cannot be stored yet — this deployment has no document store for Purchases.',
                ],
                'autosave' => [
                    'available' => false,
                    'reason'    => 'There is no draft autosave endpoint. Save as draft writes the claim.',
                ],
            ],
        ];
    }

    /** @param array<string, string> $map @return list<array{value:string, label:string}> */
    private static function options(array $map): array
    {
        $out = [];
        foreach ($map as $value => $label) {
            $out[] = ['value' => $value, 'label' => $label];
        }

        return $out;
    }

    /** @param array<string, mixed> $input */
    public function createClaim(array $input): array
    {
        Permissions::assert($this->ctx, $this->auth, 'claim.create');

        $supplierId = (int) ($input['supplier_account_id'] ?? 0);
        if ($supplierId <= 0) {
            Http::validationFailed('Say which supplier this claim is against.', ['field' => 'supplier_account_id']);
        }

        $kind = self::text($input['claim_kind'] ?? null) ?? 'other';
        if (!array_key_exists($kind, self::CLAIM_KINDS)) {
            Http::validationFailed(
                'Claim kind must be one of: ' . implode(', ', array_keys(self::CLAIM_KINDS)) . '.',
                ['field' => 'claim_kind'],
            );
        }

        $subject = self::text($input['subject'] ?? null);
        if ($subject !== null && mb_strlen($subject) > self::SUBJECT_MAX) {
            Http::validationFailed('The subject is longer than ' . self::SUBJECT_MAX . ' characters.', ['field' => 'subject']);
        }

        $description = self::text($input['description'] ?? null);
        if ($description !== null && mb_strlen($description) > self::DESCRIPTION_MAX) {
            Http::validationFailed('The description is longer than ' . self::DESCRIPTION_MAX . ' characters.', ['field' => 'description']);
        }

        $resolution = self::text($input['requested_resolution'] ?? null);
        if ($resolution !== null && !array_key_exists($resolution, self::CLAIM_RESOLUTIONS)) {
            Http::validationFailed('That is not a resolution this product offers.', ['field' => 'requested_resolution']);
        }

        $priority = self::text($input['priority'] ?? null) ?? 'normal';
        if (!array_key_exists($priority, self::CLAIM_PRIORITIES)) {
            Http::validationFailed('That is not a priority this product offers.', ['field' => 'priority']);
        }

        // The references are checked against THIS company and THIS supplier
        // before anything is written. A po_id from the request is a number a
        // browser sent: unchecked, it is a way to attach a claim to another
        // company's order and read its number back out on the claim.
        $references = $this->resolveClaimReferences($input, $supplierId);

        $lines = $this->normaliseClaimLines($input['lines'] ?? []);

        // The total is computed here, from the lines, and the amount the
        // browser sent is ignored when there are lines to add up. A claim whose
        // header says one thing and whose lines say another is a claim the
        // supplier will reject on sight.
        $amount = $lines === []
            ? round((float) ($input['claimed_amount'] ?? 0), 4)
            : round(array_sum(array_map(static fn (array $l): float => (float) $l['claim_amount'], $lines)), 4);

        if ($amount <= 0) {
            Http::validationFailed('A claim needs an amount.', ['field' => 'claimed_amount']);
        }

        $submitNow = ($input['submit'] ?? false) === true || ($input['submit'] ?? '') === '1';

        return Db::transaction(function () use ($input, $supplierId, $kind, $amount, $subject, $description, $resolution, $priority, $references, $lines, $submitNow) {
            $no = NumberSeries::next($this->ctx, 'claim');

            $claimId = (int) Db::insert('purchase_claims', [
                'cmp_id'              => $this->ctx->cmpId,
                'fy_id'               => $this->ctx->fyId,
                'claim_no'            => $no,
                'claim_date'          => self::date($input['claim_date'] ?? null),
                'supplier_account_id' => $supplierId,
                'po_id'               => $references['po_id'],
                'bill_request_id'     => $references['bill_request_id'],
                'receipt_request_id'  => $references['receipt_request_id'],
                'return_id'           => $references['return_id'],
                'claim_kind'          => $kind,
                'status'              => $submitNow ? 'SUBMITTED' : 'DRAFT',
                'claimed_amount'      => $amount,
                'subject'             => $subject ?? '',
                'description'         => $description,
                'requested_resolution' => $resolution,
                'expected_resolution_date' => self::optionalDate($input['expected_resolution_date'] ?? null),
                'supplier_contact'    => self::text($input['supplier_contact'] ?? null),
                'internal_owner'      => self::text($input['internal_owner'] ?? null),
                'priority'            => $priority,
                'internal_notes'      => self::text($input['internal_notes'] ?? null),
                'supplier_notes'      => self::text($input['supplier_notes'] ?? null),
                'tags'                => self::normaliseTags($input['tags'] ?? []),
                'notify_supplier'     => ($input['notify_supplier'] ?? false) === true,
                'created_by'          => $this->auth->uuid,
            ], 'claim_id');

            foreach ($lines as $line) {
                Db::insert('purchase_claim_lines', [
                    'cmp_id'   => $this->ctx->cmpId,
                    'claim_id' => $claimId,
                ] + $line, 'line_id');
            }

            Audit::record($this->ctx, $this->auth, 'claim.created', 'claim', $claimId, null, [
                'claim_no' => $no, 'claim_kind' => $kind, 'claimed_amount' => $amount, 'lines' => count($lines),
            ]);

            // Raising and submitting in one action is one audit entry per
            // event, not one for both: the trail has to show that this claim
            // was submitted, whether that happened a second or a week after it
            // was raised.
            if ($submitNow) {
                Audit::record($this->ctx, $this->auth, 'claim.submit', 'claim', $claimId, ['status' => 'DRAFT'], ['status' => 'SUBMITTED']);
            }

            return $this->findClaim($claimId);
        });
    }

    /**
     * Check every reference against this company and this supplier.
     *
     * A reference that belongs to somebody else, or to a different supplier, is
     * refused rather than quietly dropped: dropping it means the buyer submits
     * a claim believing the invoice is attached to it.
     *
     * @param array<string, mixed> $input
     * @return array{po_id: ?int, bill_request_id: ?int, receipt_request_id: ?int, return_id: ?int}
     */
    private function resolveClaimReferences(array $input, int $supplierId): array
    {
        $cmp = $this->ctx->cmpId;

        $poId = self::id($input['po_id'] ?? null);
        if ($poId !== null) {
            $owner = Db::first(
                'SELECT supplier_account_id FROM purchase_orders WHERE po_id = :id AND cmp_id = :cmp',
                ['id' => $poId, 'cmp' => $cmp],
            );
            if ($owner === null) {
                Http::validationFailed('That purchase order does not exist.', ['field' => 'po_id']);
            }
            if ((int) $owner['supplier_account_id'] !== $supplierId) {
                Http::validationFailed('That purchase order is against a different supplier.', ['field' => 'po_id']);
            }
        }

        $billId = self::id($input['bill_request_id'] ?? null);
        if ($billId !== null) {
            $owner = Db::first(
                'SELECT supplier_account_id FROM purchase_bill_requests WHERE request_id = :id AND cmp_id = :cmp',
                ['id' => $billId, 'cmp' => $cmp],
            );
            if ($owner === null) {
                Http::validationFailed('That purchase bill does not exist.', ['field' => 'bill_request_id']);
            }
            if ((int) $owner['supplier_account_id'] !== $supplierId) {
                Http::validationFailed('That bill is against a different supplier.', ['field' => 'bill_request_id']);
            }
        }

        // A receipt has no supplier of its own — it belongs to an order, and the
        // order has the supplier. So the check goes through the join.
        $receiptId = self::id($input['receipt_request_id'] ?? null);
        if ($receiptId !== null) {
            $owner = Db::first(
                'SELECT o.supplier_account_id
                   FROM purchase_receipt_requests r
                   JOIN purchase_orders o ON o.po_id = r.po_id
                  WHERE r.request_id = :id AND r.cmp_id = :cmp',
                ['id' => $receiptId, 'cmp' => $cmp],
            );
            if ($owner === null) {
                Http::validationFailed('That delivery does not exist.', ['field' => 'receipt_request_id']);
            }
            if ((int) $owner['supplier_account_id'] !== $supplierId) {
                Http::validationFailed('That delivery is against a different supplier.', ['field' => 'receipt_request_id']);
            }
        }

        $returnId = self::id($input['return_id'] ?? null);
        if ($returnId !== null) {
            $owner = Db::first(
                'SELECT supplier_account_id FROM purchase_returns WHERE return_id = :id AND cmp_id = :cmp',
                ['id' => $returnId, 'cmp' => $cmp],
            );
            if ($owner === null) {
                Http::validationFailed('That return does not exist.', ['field' => 'return_id']);
            }
            if ((int) $owner['supplier_account_id'] !== $supplierId) {
                Http::validationFailed('That return is against a different supplier.', ['field' => 'return_id']);
            }
        }

        return [
            'po_id'              => $poId,
            'bill_request_id'    => $billId,
            'receipt_request_id' => $receiptId,
            'return_id'          => $returnId,
        ];
    }

    /**
     * Claim lines, cleaned.
     *
     * THE AMOUNT IS NOT ALWAYS QUANTITY x RATE, and this is the one place that
     * matters. A shortage is: ten pieces short at the agreed rate. A scheme
     * that was not passed on is an amount with no quantity behind it at all.
     * Forcing the multiplication would mean inventing a quantity for the second
     * case, so an amount that is sent explicitly is kept, and the product is
     * used only when there is none.
     *
     * @return list<array<string, mixed>>
     */
    private function normaliseClaimLines(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        if (count($raw) > self::MAX_LINES) {
            Http::validationFailed('A claim cannot carry more than ' . self::MAX_LINES . ' lines.', ['field' => 'lines']);
        }

        $lines = [];
        $lineNo = 0;

        foreach ($raw as $line) {
            if (!is_array($line)) {
                continue;
            }

            $claimQty = round((float) ($line['claim_qty'] ?? 0), 4);
            $rate = round((float) ($line['rate'] ?? 0), 4);
            $amount = array_key_exists('claim_amount', $line) && $line['claim_amount'] !== '' && $line['claim_amount'] !== null
                ? round((float) $line['claim_amount'], 4)
                : round($claimQty * $rate, 4);

            if ($claimQty < 0 || $rate < 0 || $amount < 0) {
                Http::validationFailed('A claim line cannot carry a negative quantity, rate or amount.', ['field' => 'lines']);
            }

            // An empty row is the row the table always has at the bottom, not a
            // line somebody meant to claim.
            if ($amount <= 0 && $claimQty <= 0) {
                continue;
            }

            $referenceKind = self::text($line['reference_kind'] ?? null) ?? 'none';
            if (!in_array($referenceKind, self::REFERENCE_KINDS, true)) {
                $referenceKind = 'none';
            }

            $lines[] = [
                'line_no'        => ++$lineNo,
                'item_id'        => self::id($line['item_id'] ?? null),
                'description'    => self::clip(self::text($line['description'] ?? null), 500),
                'reference_kind' => $referenceKind,
                'reference_no'   => self::clip(self::text($line['reference_no'] ?? null), 64),
                'ordered_qty'    => max(0.0, round((float) ($line['ordered_qty'] ?? 0), 4)),
                'received_qty'   => max(0.0, round((float) ($line['received_qty'] ?? 0), 4)),
                'claim_qty'      => $claimQty,
                'rate'           => $rate,
                'claim_amount'   => $amount,
                'reason'         => self::clip(self::text($line['reason'] ?? null), 500),
            ];
        }

        return $lines;
    }

    /** @return list<string> */
    private static function normaliseTags(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $tags = [];
        foreach ($raw as $tag) {
            $text = self::text(is_string($tag) ? $tag : null);
            if ($text === null) {
                continue;
            }
            $text = mb_substr($text, 0, 32);
            if (!in_array($text, $tags, true)) {
                $tags[] = $text;
            }
            if (count($tags) >= self::MAX_TAGS) {
                break;
            }
        }

        return $tags;
    }

    private static function clip(?string $value, int $max): ?string
    {
        return $value === null ? null : mb_substr($value, 0, $max);
    }

    private static function optionalDate(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($value)) === 1 ? trim($value) : null;
    }

    /** @param array<string, mixed> $input */
    public function updateClaim(int $claimId, string $action, array $input): array
    {
        $claim = $this->findClaim($claimId);
        if ($claim === []) {
            Http::notFound('That claim does not exist.');
        }

        $transitions = [
            'submit'   => ['claim.create', ['DRAFT'], 'SUBMITTED'],
            'respond'  => ['claim.create', ['SUBMITTED'], 'SUPPLIER_RESPONDED'],
            'approve'  => ['claim.settle', ['SUBMITTED', 'SUPPLIER_RESPONDED'], 'APPROVED'],
            'reject'   => ['claim.settle', ['SUBMITTED', 'SUPPLIER_RESPONDED'], 'REJECTED'],
            'settle'   => ['claim.settle', ['APPROVED'], 'SETTLED'],
        ];

        if (!isset($transitions[$action])) {
            Http::notFound('There is no such action on a claim.');
        }

        [$permission, $from, $to] = $transitions[$action];
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
        $claim = Db::first('SELECT * FROM purchase_claims WHERE claim_id = :id AND cmp_id = :cmp', ['id' => $claimId, 'cmp' => $this->ctx->cmpId]);
        if ($claim === null) {
            return [];
        }

        $claim['tags'] = Db::jsonColumn($claim['tags'] ?? null);
        $claim['lines'] = Db::all(
            'SELECT * FROM purchase_claim_lines WHERE claim_id = :id AND cmp_id = :cmp ORDER BY line_no',
            ['id' => $claimId, 'cmp' => $this->ctx->cmpId],
        );

        return $claim;
    }

    /**
     * Open claims that look like the one being raised.
     *
     * READ-ONLY AND ADVISORY. It answers "has somebody already claimed this",
     * which on a shortage against a delivery three people saw is a real
     * question. It does not refuse anything: two genuine claims against one
     * order happen, and a screen that blocked the second would be teaching
     * people to raise it against the wrong order instead.
     *
     * @param array<string, mixed> $filters
     * @return list<array<string, mixed>>
     */
    public function similarClaims(array $filters, int $limit = 5): array
    {
        Permissions::assert($this->ctx, $this->auth, 'claim.create');

        $supplierId = (int) ($filters['supplier_account_id'] ?? 0);
        if ($supplierId <= 0) {
            return [];
        }

        $where = [
            'c.cmp_id = :cmp',
            'c.supplier_account_id = :supplier',
            "c.status NOT IN ('SETTLED', 'CLOSED', 'REJECTED')",
        ];
        $params = ['cmp' => $this->ctx->cmpId, 'supplier' => $supplierId];

        // A claim is "similar" when it is against the same document, or — with
        // no document to go on — the same kind of problem with the same
        // supplier in the last ninety days.
        $poId = self::id($filters['po_id'] ?? null);
        $billId = self::id($filters['bill_request_id'] ?? null);

        if ($poId !== null || $billId !== null) {
            $documentClauses = [];
            if ($poId !== null) {
                $documentClauses[] = 'c.po_id = :po';
                $params['po'] = $poId;
            }
            if ($billId !== null) {
                $documentClauses[] = 'c.bill_request_id = :bill';
                $params['bill'] = $billId;
            }
            $where[] = '(' . implode(' OR ', $documentClauses) . ')';
        } else {
            $kind = self::text($filters['claim_kind'] ?? null);
            if ($kind === null || !array_key_exists($kind, self::CLAIM_KINDS)) {
                return [];
            }
            $where[] = 'c.claim_kind = :kind';
            $where[] = "c.claim_date >= (CURRENT_DATE - INTERVAL '90 days')";
            $params['kind'] = $kind;
        }

        $limit = max(1, min(20, $limit));

        return Db::all(
            'SELECT c.claim_id, c.claim_no, c.claim_date, c.claim_kind, c.subject, c.status, c.claimed_amount, c.po_id, c.bill_request_id
               FROM purchase_claims c
              WHERE ' . implode(' AND ', $where) . "
              ORDER BY c.claim_date DESC, c.claim_id DESC
              LIMIT {$limit}",
            $params,
        );
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
        // "My claims" is the ones this session raised. Resolved from the signed-in
        // session, never from a uuid the caller sends — otherwise "mine" is a
        // way to read somebody else's.
        if (!empty($filters['mine'])) {
            $where[] = 'c.created_by = :me';
            $params['me'] = $this->auth->uuid;
        }
        if (!empty($filters['q'])) {
            $where[] = '(c.claim_no ILIKE :q OR c.subject ILIKE :q OR c.description ILIKE :q)';
            $params['q'] = '%' . str_replace(['%', '_'], ['\%', '\_'], (string) $filters['q']) . '%';
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
