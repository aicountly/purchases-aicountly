<?php

declare(strict_types=1);

namespace Aicountly\Api\Controllers;

use Aicountly\Api\Clients\BooksClient;
use Aicountly\Api\Clients\InventoryClient;
use Aicountly\Api\Dashboards\Format;
use Aicountly\Api\Db;
use Aicountly\Api\Domain\ReturnAnalytics;
use Aicountly\Api\Domain\ReturnClaimService;
use Aicountly\Api\Http;
use Aicountly\Api\Import\DocumentReader;
use Aicountly\Api\Import\ReturnImport;
use Aicountly\Api\Import\Upload;
use Aicountly\Api\Permissions;

final class ReturnsController extends Controller
{
    /** Columns a caller may sort the register by. */
    private const SORTABLE = ['return_date', 'return_no', 'status', 'created_at', 'return_value', 'supplier'];

    public static function index(): void
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'return.create');

        $params = Http::listParams(self::SORTABLE, 'return_date');
        $filters = self::filters($params['q']);

        $result = (new ReturnClaimService($ctx, $auth))
            ->searchReturns($filters, $params['limit'], $params['offset'], $params['sort'], $params['order']);

        Http::list(
            array_map(self::present(...), $result['rows']),
            $result['total'],
            $params['limit'],
            $params['offset'],
            // Echoed back so a client can tell a stale response from a current
            // one when filters change faster than the network answers.
            ['filters' => $filters, 'sort' => $params['sort'], 'order' => strtolower($params['order'])],
        );
    }

    /**
     * The cards, the trend, the reason split and the insights — over the SAME
     * filters as the register beneath them.
     *
     * A separate call from the list on purpose: the register pages, and paging
     * must not re-run the analytics. The filters are what they share.
     */
    public static function summary(): void
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'return.create');

        Http::data((new ReturnAnalytics($ctx, $auth))->summary(self::filters(trim((string) (Http::param('q') ?? '')))));
    }

    /**
     * The vocabularies the workspace filters by.
     *
     * Served rather than hard-coded in the client so a reason added here does
     * not need the browser bundle rebuilt to appear in the filter.
     */
    public static function options(): void
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'return.create');

        $reasons = [];
        foreach (ReturnClaimService::REASONS as $code => $label) {
            $reasons[] = ['value' => $code, 'label' => $label];
        }

        // Codes already in this company's data that are not in the vocabulary —
        // imported, or from before it was fixed. They still filter.
        foreach (Db::all(
            "SELECT DISTINCT reason_code FROM purchase_returns
              WHERE cmp_id = :cmp AND reason_code IS NOT NULL AND reason_code <> ''",
            ['cmp' => $ctx->cmpId],
        ) as $row) {
            $code = (string) $row['reason_code'];
            if (!isset(ReturnClaimService::REASONS[$code])) {
                $reasons[] = ['value' => $code, 'label' => ucfirst(str_replace('_', ' ', $code))];
            }
        }

        Http::data([
            'statuses' => array_map(
                static fn (string $status) => ['value' => $status, 'label' => self::STATUS_LABELS[$status] ?? $status],
                ReturnClaimService::STATUSES,
            ),
            'credit_statuses' => array_map(
                static fn (string $status) => ['value' => $status, 'label' => self::CREDIT_LABELS[$status] ?? $status],
                ReturnClaimService::CREDIT_STATUSES,
            ),
            'reasons' => $reasons,
            'can'     => [
                'create'  => Permissions::allows($ctx, $auth, 'return.create'),
                'approve' => Permissions::allows($ctx, $auth, 'return.approve'),
                'export'  => Permissions::allows($ctx, $auth, 'reports.view'),
            ],
        ]);
    }

    /**
     * The register as a CSV, under the filters currently applied.
     *
     * The same clause the screen used, so an export cannot disagree with what
     * somebody was looking at when they asked for it.
     */
    public static function export(): void
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'return.create');
        Permissions::assert($ctx, $auth, 'reports.view');

        $params = Http::listParams(self::SORTABLE, 'return_date');
        $filters = self::filters($params['q']);

        // Bounded, not unbounded: an export is still one query, and a register
        // with a hundred thousand rows is a timeout rather than a spreadsheet.
        $result = (new ReturnClaimService($ctx, $auth))
            ->searchReturns($filters, 5000, 0, $params['sort'], $params['order']);

        $rows = [[
            'Return No.', 'Date', 'Supplier', 'Supplier account', 'Reference', 'Items',
            'Return value', 'Status', 'Reason', 'Supplier credit', 'Credit reference',
            'Stock movement (Inventory)', 'Debit note (Books)',
        ]];

        foreach ($result['rows'] as $row) {
            $view = self::present($row);
            $rows[] = [
                (string) $view['return_no'],
                (string) $view['return_date'],
                (string) ($view['supplier_name'] ?? ''),
                (string) $view['supplier_account_id'],
                (string) ($view['source']['number'] ?? ''),
                (string) $view['item_count'],
                (string) $view['total_value'],
                self::STATUS_LABELS[$view['status']] ?? (string) $view['status'],
                (string) ($view['reason']['label'] ?? ''),
                self::CREDIT_LABELS[$view['supplier_credit']['status']] ?? '',
                (string) ($view['supplier_credit']['reference'] ?? ''),
                (string) ($view['inventory']['reference'] ?? ''),
                (string) ($view['books']['reference'] ?? ''),
            ];
        }

        $csv = self::csv($rows);
        $stem = 'purchase-returns-' . gmdate('Y-m-d');

        if (PHP_SAPI === 'cli') {
            // Under test the CSV comes back as data so it can be asserted on.
            Http::data(['rows' => count($result['rows']), 'csv' => $csv]);
        }

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $stem . '.csv"');
        header('Cache-Control: no-store');
        echo $csv;
        exit;
    }

    public static function show(string $id): void
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'return.create');

        $return = (new ReturnClaimService($ctx, $auth))->findReturn((int) $id);
        if ($return === []) {
            Http::notFound('That return does not exist.');
        }

        // The presented view first, then everything the row already carried.
        // A caller written against the older shape — `inventory_document_uuid`,
        // `books_debit_note_uuid`, the raw lines — still finds all of it.
        Http::data(self::present($return) + $return);
    }

    /**
     * Where this return actually got to in Inventory and in Smart Books.
     *
     * ASKED, NEVER STORED. This product keeps two references and nothing else;
     * what those documents say is read from the products that own them, at the
     * moment somebody looks. When one of them cannot be reached the answer says
     * so — `available: false` with the reason — because "unavailable" and
     * "nothing posted" are different facts and a screen that shows the second
     * when it means the first is lying about the company's stock and books.
     */
    public static function integration(string $id): void
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'return.create');

        $return = (new ReturnClaimService($ctx, $auth))->findReturn((int) $id);
        if ($return === []) {
            Http::notFound('That return does not exist.');
        }

        $inventory = ['status' => 'NOT_SENT', 'available' => true, 'reason' => null, 'reference' => null, 'document' => null];
        if ($return['inventory_document_uuid'] !== null) {
            $response = (new InventoryClient())
                ->withSession($auth->sesKey())
                ->documentByUuid($ctx, (string) $return['inventory_document_uuid']);

            $inventory = $response['ok']
                ? [
                    'status'    => 'POSTED',
                    'available' => true,
                    'reason'    => null,
                    'reference' => $return['inventory_document_uuid'],
                    'document'  => $response['body']['data'] ?? null,
                ]
                : [
                    'status'    => 'UNKNOWN',
                    'available' => false,
                    'reason'    => $response['error'] ?? 'Inventory could not be reached.',
                    'reference' => $return['inventory_document_uuid'],
                    'document'  => null,
                ];
        }

        $books = ['status' => 'NOT_RAISED', 'available' => true, 'reason' => null, 'reference' => null, 'voucher' => null];
        if ($return['books_debit_note_uuid'] !== null) {
            $voucherId = (int) ($return['books_debit_note_id'] ?? 0);
            $response = $voucherId > 0
                ? (new BooksClient())->withSession($auth->sesKey())->voucher($ctx, $voucherId)
                : ['ok' => true, 'body' => ['data' => null], 'error' => null];

            $books = $response['ok']
                ? [
                    'status'    => 'POSTED',
                    'available' => true,
                    'reason'    => null,
                    'reference' => $return['books_debit_note_uuid'],
                    'voucher'   => $response['body']['data'] ?? null,
                ]
                : [
                    'status'    => 'UNKNOWN',
                    'available' => false,
                    'reason'    => $response['error'] ?? 'Smart Books could not be reached.',
                    'reference' => $return['books_debit_note_uuid'],
                    'voucher'   => null,
                ];
        }

        Http::data([
            'return_id' => (int) $return['return_id'],
            'inventory' => $inventory,
            'books'     => $books,
        ]);
    }

    public static function create(): void
    {
        [$auth, $ctx] = self::enter();
        $return = (new ReturnClaimService($ctx, $auth))->createReturn(Http::body());
        Http::data(self::present($return) + $return, 201);
    }

    public static function approve(string $id): void
    {
        [$auth, $ctx] = self::enter();
        $return = (new ReturnClaimService($ctx, $auth))->approveReturn((int) $id, Http::body());
        Http::data(self::present($return) + $return);
    }

    public static function dispatch(string $id): void
    {
        [$auth, $ctx] = self::enter();
        $return = (new ReturnClaimService($ctx, $auth))->dispatchReturn((int) $id);
        Http::data(self::present($return) + $return);
    }

    public static function debitNote(string $id): void
    {
        [$auth, $ctx] = self::enter();
        $return = (new ReturnClaimService($ctx, $auth))->requestDebitNote((int) $id);
        Http::data(self::present($return) + $return);
    }

    public static function cancel(string $id): void
    {
        [$auth, $ctx] = self::enter();
        $return = (new ReturnClaimService($ctx, $auth))->cancelReturn((int) $id, Http::body());
        Http::data(self::present($return) + $return);
    }

    /** The supplier's own credit note reference — ours to track, Books' to account for. */
    public static function supplierCredit(string $id): void
    {
        [$auth, $ctx] = self::enter();
        $return = (new ReturnClaimService($ctx, $auth))->recordSupplierCredit((int) $id, Http::body());
        Http::data(self::present($return) + $return);
    }

    /**
     * Read a spreadsheet of returns and say what is in it. NOTHING IS WRITTEN.
     *
     * Deliberately a separate call from the one that creates. A file with a
     * mis-read quantity column imports confidently and completely wrongly, and
     * the only defence is showing somebody what was read before anything is
     * created. The upload is parsed in memory and the temporary file is deleted
     * before the response is written — no copy of it is kept.
     */
    public static function importPreview(): void
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'return.create');

        [$path, $name, $cleanup] = Upload::file();

        try {
            $result = DocumentReader::read($path, $name);
            $table = $result['table'];

            if ($table->isEmpty()) {
                Http::data([
                    'file'    => ['name' => $name, 'kind' => $result['kind'], 'readable' => false],
                    'notes'   => $table->notes,
                    'summary' => ['rows' => 0, 'valid' => 0, 'errors' => 0, 'returns' => 0],
                    'rows'    => [],
                    'returns' => [],
                    'guidance' => 'Nothing could be read from this file. The notes above say why.',
                ]);
            }

            $reader = ReturnImport::detect($table);
            $read = $reader->read($table, $ctx);

            Http::data([
                'file' => ['name' => $name, 'kind' => $result['kind'], 'readable' => true],
            ] + $read + [
                'guidance' => 'Check the mapping and the rows below before importing. Every return created is a DRAFT — '
                    . 'nothing goes to Inventory or Smart Books until somebody approves it.',
                'retention' => 'The file was read and discarded. Only the returns you create are kept.',
            ]);
        } finally {
            $cleanup();
        }
    }

    /**
     * Create drafts from rows a person has already seen on the preview.
     *
     * The rows come back from the client rather than the file being re-read, so
     * what is created is what was reviewed. Every one goes through
     * createReturn(), so the same numbering series, the same validation and the
     * same audit entry apply as when one is raised by hand — an import is not a
     * side door into the table.
     *
     * EACH RETURN IS CHECKED BEFORE IT IS ATTEMPTED. The refusals in
     * createReturn() answer the request and stop, which is right for one return
     * and wrong for a file of forty: the first bad row would end the response
     * and the thirty-nine good ones would never be tried. So the same
     * conditions are tested here first, and a draft that would be refused is
     * REPORTED rather than attempted.
     */
    public static function importCommit(): void
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'return.create');

        $body = Http::body();
        $drafts = is_array($body['returns'] ?? null) ? $body['returns'] : [];
        if ($drafts === []) {
            Http::validationFailed('There is nothing to import.', ['field' => 'returns']);
        }
        if (count($drafts) > 200) {
            Http::validationFailed('Import at most 200 returns at a time.', ['field' => 'returns']);
        }

        $service = new ReturnClaimService($ctx, $auth);
        $created = [];
        $failed = [];

        foreach ($drafts as $index => $draft) {
            $position = (int) $index;

            if (!is_array($draft)) {
                $failed[] = ['index' => $position, 'message' => 'That row could not be read.'];
                continue;
            }

            $problem = self::draftProblem($draft);
            if ($problem !== null) {
                $failed[] = ['index' => $position, 'message' => $problem];
                continue;
            }

            try {
                // Each return is its own transaction. A fault on one leaves the
                // rest created rather than throwing the whole file away, and the
                // response names the ones that did not make it.
                $created[] = self::present($service->createReturn($draft));
            } catch (\Throwable $error) {
                error_log('[returns.import] row ' . $position . ' failed: ' . $error->getMessage());
                $failed[] = ['index' => $position, 'message' => 'That return could not be created.'];
            }
        }

        Http::data([
            'created'       => $created,
            'created_count' => count($created),
            'failed'        => $failed,
        ], $created === [] ? 422 : 201);
    }

    /**
     * Why createReturn() would refuse this draft, or null if it would not.
     *
     * Deliberately the same three conditions, in the same order. They are
     * duplicated rather than shared because the service answers a refusal by
     * ending the request, which a batch cannot allow; if a fourth condition is
     * added there it belongs here too.
     *
     * @param array<string, mixed> $draft
     */
    private static function draftProblem(array $draft): ?string
    {
        if ((int) ($draft['supplier_account_id'] ?? 0) <= 0) {
            return 'Say which supplier this return goes back to.';
        }

        $lines = is_array($draft['lines'] ?? null) ? $draft['lines'] : [];
        $usable = 0;
        foreach ($lines as $line) {
            if (is_array($line) && (float) ($line['return_qty'] ?? $line['qty'] ?? 0) > 0) {
                $usable++;
            }
        }
        if ($usable === 0) {
            return 'A return needs at least one line with a quantity above zero.';
        }

        $reason = $draft['reason_code'] ?? null;
        if (is_string($reason) && trim($reason) !== '' && !isset(ReturnClaimService::REASONS[trim($reason)])) {
            return 'Return reason "' . trim($reason) . '" is not one this product knows.';
        }

        return null;
    }

    // -----------------------------------------------------------------------

    /** @var array<string, string> */
    private const STATUS_LABELS = [
        'DRAFT'      => 'Draft',
        'APPROVED'   => 'Approved',
        'DISPATCHED' => 'Goods returned',
        'DEBITED'    => 'Debit note raised',
        'CLOSED'     => 'Completed',
        'CANCELLED'  => 'Cancelled',
    ];

    /** @var array<string, string> */
    private const CREDIT_LABELS = [
        'PENDING'      => 'Credit pending',
        'RECEIVED'     => 'Credit received',
        'NOT_REQUIRED' => 'No credit due',
    ];

    /**
     * Every filter the register understands, read off the request once.
     *
     * @return array<string, mixed>
     */
    private static function filters(string $q): array
    {
        return [
            'q'                   => $q,
            'status'              => Http::param('status'),
            'supplier_account_id' => Http::intParam('supplier_account_id'),
            'po_id'               => Http::intParam('po_id'),
            'reason_code'         => Http::param('reason'),
            'from'                => self::date(Http::param('from')),
            'to'                  => self::date(Http::param('to')),
            'supplier_credit'     => Http::param('supplier_credit'),
            'inventory'           => Http::param('inventory'),
            'books'               => Http::param('books'),
            'min_value'           => Http::param('min_value'),
            'max_value'           => Http::param('max_value'),
            'created_by'          => Http::param('created_by'),
        ];
    }

    private static function date(?string $value): ?string
    {
        return ($value !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) ? $value : null;
    }

    /**
     * One return, in the shape the workspace reads.
     *
     * The mapping lives here rather than in the browser so the register, the
     * detail and the export all describe a return the same way, and so a column
     * renamed in the database is one edit rather than a hunt through the client.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function present(array $row): array
    {
        $status = (string) ($row['status'] ?? 'DRAFT');
        $credit = (string) ($row['supplier_credit_status'] ?? 'PENDING');
        $reason = $row['reason_code'] === null || $row['reason_code'] === '' ? null : (string) $row['reason_code'];
        $currency = (string) ($row['currency_code'] ?? 'INR');
        $value = (string) ($row['return_value'] ?? '0');

        return [
            // The original row is kept alongside so nothing an existing caller
            // reads has been taken away.
            'return_id'           => (int) $row['return_id'],
            'return_uuid'         => (string) $row['return_uuid'],
            'return_no'           => (string) $row['return_no'],
            'return_date'         => (string) $row['return_date'],
            'expected_pickup_date' => $row['expected_pickup_date'] ?? null,
            'status'              => $status,
            'status_label'        => self::STATUS_LABELS[$status] ?? $status,
            'supplier_account_id' => (int) $row['supplier_account_id'],
            'supplier_name'       => $row['supplier_name'] ?? $row['supplier_name_snapshot'] ?? null,
            'source'              => $row['po_id'] === null ? null : [
                'type'   => 'PURCHASE_ORDER',
                'id'     => (int) $row['po_id'],
                'number' => $row['po_no'] ?? null,
            ],
            'po_id'               => $row['po_id'] === null ? null : (int) $row['po_id'],
            'item_count'          => (int) ($row['line_count'] ?? 0),
            'total_value'         => $value,
            'total_value_formatted' => Format::money($value, $currency),
            'currency'            => $currency,
            'reason'              => $reason === null ? null : [
                'code'  => $reason,
                'label' => ReturnClaimService::REASONS[$reason] ?? ucfirst(str_replace('_', ' ', $reason)),
            ],
            'reason_note'         => $row['reason_note'] ?? null,
            'cancel_reason'       => $row['cancel_reason'] ?? null,
            'supplier_credit'     => [
                'status'    => $credit,
                'label'     => self::CREDIT_LABELS[$credit] ?? $credit,
                'reference' => $row['supplier_credit_ref'] ?? null,
                'date'      => $row['supplier_credit_date'] ?? null,
                'amount'    => $row['supplier_credit_amount'] ?? null,
            ],
            // What this product KNOWS about the other two: whether it holds a
            // reference. What those documents say is read from them, live, by
            // the integration endpoint above.
            'inventory'           => [
                'status'    => $row['inventory_document_uuid'] !== null ? 'POSTED' : ($status === 'CANCELLED' ? 'NOT_REQUIRED' : 'PENDING'),
                'reference' => $row['inventory_document_uuid'] ?? null,
            ],
            'books'               => [
                'status'    => $row['books_debit_note_uuid'] !== null ? 'POSTED' : ($status === 'CANCELLED' ? 'NOT_REQUIRED' : 'PENDING'),
                'reference' => $row['books_debit_note_uuid'] ?? null,
            ],
            'created_by'          => $row['created_by'] ?? null,
            'created_at'          => $row['created_at'] ?? null,
            'updated_at'          => $row['updated_at'] ?? null,
        ];
    }

    /** @param list<list<string>> $rows */
    private static function csv(array $rows): string
    {
        $out = '';
        foreach ($rows as $row) {
            $cells = array_map(static function (string $cell): string {
                // A leading =, +, - or @ makes a spreadsheet treat the cell as a
                // formula. Supplier names are not formulas.
                if ($cell !== '' && in_array($cell[0], ['=', '+', '-', '@'], true)) {
                    $cell = "'" . $cell;
                }

                return '"' . str_replace('"', '""', $cell) . '"';
            }, $row);
            $out .= implode(',', $cells) . "\r\n";
        }

        return $out;
    }
}
