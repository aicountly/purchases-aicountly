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
