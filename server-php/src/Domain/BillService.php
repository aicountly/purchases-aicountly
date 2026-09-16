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

    public function __construct(
        private readonly Context $ctx,
        private readonly Auth $auth,
    ) {
    }

    /**
     * Enter a supplier bill and match it. Nothing is posted yet.
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

        // A duplicate supplier invoice number is the oldest payables fraud and
        // the commonest honest mistake. Caught here, before it reaches Books.
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

        $poId = self::id($input['po_id'] ?? null);
        $lines = $this->normaliseLines($input['lines'] ?? [], $poId);
        if ($lines === []) {
            Http::validationFailed('A bill needs at least one line.', ['field' => 'lines']);
        }

        $requestId = (int) Db::insert('purchase_bill_requests', [
            'cmp_id'                => $this->ctx->cmpId,
            'fy_id'                 => $this->ctx->fyId,
            'bo_id'                 => $this->ctx->boId,
            'po_id'                 => $poId,
            'supplier_account_id'   => $supplierId,
            'supplier_invoice_no'   => $invoiceNo,
            'supplier_invoice_date' => self::text($input['supplier_invoice_date'] ?? null),
            'status'                => 'MATCHING',
            'requested_lines'       => $lines,
            'receipt_references'    => is_array($input['receipt_references'] ?? null) ? $input['receipt_references'] : [],
            'requested_by'          => $this->auth->uuid,
        ], 'request_id');

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
            'match_verdict'       => $match['verdict'],
        ]);

        $result = $this->find($requestId);
        $result['match'] = $match;

        return $result;
    }

    /**
     * Post a matched bill to Books.
     *
     * @param array<string, mixed> $input
     */
    public function post(int $requestId, array $input = []): array
    {
        Permissions::assert($this->ctx, $this->auth, 'bill.post');

        $bill = $this->find($requestId);
        if ($bill === []) {
            Http::notFound('That bill does not exist.');
        }
        if ($bill['status'] === 'POSTED') {
            Http::conflict('That bill has already been posted to Smart Books.');
        }

        $latestMatch = Db::first(
            'SELECT * FROM purchase_match_results WHERE bill_request_id = :id ORDER BY match_id DESC LIMIT 1',
            ['id' => $requestId],
        );

        $blockOnFailure = (bool) (Db::scalar(
            'SELECT block_bill_on_match_failure FROM purchase_settings WHERE cmp_id = :cmp',
            ['cmp' => $this->ctx->cmpId],
        ) ?? true);

        if ($latestMatch !== null && $blockOnFailure) {
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

        $command = IntegrationCommand::open(
            $this->ctx,
            'books',
            self::COMMAND_BILL,
            'bill_request',
            $requestId,
            ['supplier_invoice_no' => $bill['supplier_invoice_no'], 'po_id' => $bill['po_id']],
        );
        $commandId = (int) $command['command_id'];
        IntegrationCommand::markPosting($commandId);

        $payload = $this->buildVoucherPayload($bill, $input);

        $response = (new BooksClient())
            ->withService($this->auth->uuid)
            ->createAndPostVoucher($this->ctx, BooksClient::VCH_PURCHASE, $payload, (string) $command['idempotency_key']);

        if (!$response['ok']) {
            $message = $response['error'] ?? 'Books did not accept the bill.';
            $businessRefusal = in_array($response['status'], [409, 422], true);
            $businessRefusal ? IntegrationCommand::block($commandId, $message) : IntegrationCommand::fail($commandId, $message);

            Db::update('purchase_bill_requests', [
                'status' => 'FAILED', 'last_error' => mb_substr($message, 0, 480), 'updated_at' => self::now(),
            ], ['request_id' => $requestId]);

            Http::error(
                $businessRefusal ? 409 : 502,
                $businessRefusal ? 'books_refused' : 'books_unavailable',
                $businessRefusal
                    ? $message
                    : 'Could not reach Books to post this bill. Nothing has been posted — press Retry.',
                ['request_id' => $requestId, 'retryable' => !$businessRefusal, 'detail' => $message],
            );
        }

        $voucher = $response['body']['data'] ?? [];
        IntegrationCommand::complete($commandId, [
            'books_voucher_id'   => $voucher['vch_txn_id'] ?? null,
            'books_voucher_uuid' => $voucher['vch_uuid'] ?? null,
            'books_voucher_no'   => $voucher['vch_no'] ?? null,
        ]);

        Db::transaction(function () use ($requestId, $voucher, $bill) {
            Db::update('purchase_bill_requests', [
                'status'             => 'POSTED',
                'books_voucher_id'   => self::id($voucher['vch_txn_id'] ?? $voucher['voucher_id'] ?? null),
                'books_voucher_uuid' => self::text($voucher['vch_uuid'] ?? $voucher['voucher_uuid'] ?? null),
                'books_voucher_no'   => self::text($voucher['vch_no'] ?? $voucher['voucher_no'] ?? null),
                'updated_at'         => self::now(),
            ], ['request_id' => $requestId]);

            foreach (Db::jsonColumn($bill['requested_lines']) as $line) {
                $poLineId = (int) ($line['po_line_id'] ?? 0);
                if ($poLineId > 0) {
                    Db::run(
                        'UPDATE purchase_order_lines SET billed_qty = billed_qty + :qty, updated_at = :now
                         WHERE line_id = :id AND cmp_id = :cmp',
                        ['qty' => (float) ($line['qty'] ?? 0), 'now' => self::now(), 'id' => $poLineId, 'cmp' => $this->ctx->cmpId],
                    );
                }
            }

            if ($bill['po_id'] !== null) {
                $outstanding = (float) Db::scalar(
                    'SELECT COALESCE(SUM(GREATEST(received_qty - billed_qty, 0)), 0) FROM purchase_order_lines WHERE po_id = :id',
                    ['id' => (int) $bill['po_id']],
                );
                if ($outstanding <= 0) {
                    Db::update('purchase_orders', ['status' => 'CLOSED', 'updated_at' => self::now()], [
                        'po_id' => (int) $bill['po_id'], 'cmp_id' => $this->ctx->cmpId,
                    ]);
                }
            }
        });

        Audit::record($this->ctx, $this->auth, 'bill.posted', 'bill_request', $requestId, null, [
            'books_voucher_id' => $voucher['vch_txn_id'] ?? null,
        ]);

        return $this->find($requestId);
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

    // -----------------------------------------------------------------------

    /**
     * The Books voucher payload.
     *
     * No tax amount, no input-credit decision, no TDS. Books computes all of it:
     * it is the product that files the return, and a second tax engine would
     * eventually disagree with the one that matters.
     *
     * @param array<string, mixed> $bill
     * @return array<string, mixed>
     */
    private function buildVoucherPayload(array $bill, array $input): array
    {
        $inventoryLines = [];
        $serviceLines = [];

        foreach (Db::jsonColumn($bill['requested_lines']) as $line) {
            if (!empty($line['is_service']) || empty($line['item_id'])) {
                $serviceLines[] = [
                    'description'     => $line['description'] ?? 'Service',
                    'amount'          => (float) ($line['amount'] ?? 0),
                    'tax_cat_id'      => $line['tax_cat_id'] ?? null,
                    'source_line_ref' => (string) ($line['po_line_id'] ?? ''),
                ];
                continue;
            }
            $inventoryLines[] = [
                'source_line_ref' => (string) ($line['po_line_id'] ?? ''),
                'item_id'         => (int) $line['item_id'],
                'unit_id'         => $line['unit_id'] ?? null,
                'mc_id'           => $line['warehouse_id'] ?? null,
                'qty'             => (float) ($line['qty'] ?? 0),
                'rate'            => (float) ($line['rate'] ?? 0),
                'discount_pc'     => (float) ($line['discount_pc'] ?? 0),
                'amount'          => (float) ($line['amount'] ?? 0),
                'tax_cat_id'      => $line['tax_cat_id'] ?? null,
                'hsn_sac'         => $line['hsn_sac'] ?? null,
                'description'     => $line['description'] ?? null,
            ];
        }

        return [
            'vch_date'        => (string) ($bill['supplier_invoice_date'] ?? gmdate('Y-m-d')),
            'party_acc_id'    => (int) $bill['supplier_account_id'],
            'supplier_invoice_no'   => $bill['supplier_invoice_no'],
            'supplier_invoice_date' => $bill['supplier_invoice_date'],
            'narration'       => self::text($input['narration'] ?? null)
                ?? ('Supplier bill ' . $bill['supplier_invoice_no']),
            'reference_no'    => $bill['supplier_invoice_no'],
            'bo_id'           => (int) $bill['bo_id'],

            // Where this came from, so anyone auditing can walk back to our
            // purchase order without us copying anything of Books'.
            'source_app'           => 'purchases',
            'source_document_type' => 'purchases.bill',
            'source_document_id'   => (int) $bill['request_id'],
            'source_document_no'   => $bill['supplier_invoice_no'],
            // The receipts this bill settles, as Inventory's own uuids.
            'receipt_references'   => Db::jsonColumn($bill['receipt_references']),

            'inventory_lines' => $inventoryLines,
            'service_lines'   => $serviceLines,
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
        foreach ($raw as $line) {
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
            $discountPc = round((float) ($line['discount_pc'] ?? 0), 3);
            $gross = $qty * $rate;

            $lines[] = [
                'line_no'      => ++$lineNo,
                'po_line_id'   => $poLineId,
                'item_id'      => self::id($line['item_id'] ?? ($poLine['item_id'] ?? null)),
                'unit_id'      => self::id($line['unit_id'] ?? ($poLine['unit_id'] ?? null)),
                'warehouse_id' => self::id($line['warehouse_id'] ?? ($poLine['warehouse_id'] ?? null)),
                'is_service'   => (bool) ($line['is_service'] ?? ($poLine['is_service'] ?? false)),
                'description'  => self::text($line['description'] ?? ($poLine['description'] ?? null)),
                'tax_cat_id'   => self::id($line['tax_cat_id'] ?? ($poLine['tax_cat_id'] ?? null)),
                'hsn_sac'      => self::text($line['hsn_sac'] ?? ($poLine['hsn_sac'] ?? null)),
                'qty'          => $qty,
                'rate'         => $rate,
                'discount_pc'  => $discountPc,
                'amount'       => round($gross - ($gross * $discountPc / 100), 4),
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

    private static function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }
}
