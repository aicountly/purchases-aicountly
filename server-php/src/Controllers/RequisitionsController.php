<?php

declare(strict_types=1);

namespace Aicountly\Api\Controllers;

use Aicountly\Api\Domain\RequisitionService;
use Aicountly\Api\Http;
use Aicountly\Api\Permissions;

final class RequisitionsController extends Controller
{
    public static function index(): void
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'requisition.view');

        $params = Http::listParams(['requisition_date', 'requisition_no', 'estimated_value', 'status', 'created_at'], 'requisition_date');
        $result = (new RequisitionService($ctx, $auth))->search(
            self::filters($params['q']),
            $params['limit'],
            $params['offset'],
            $params['sort'],
            $params['order'],
        );

        Http::list($result['rows'], $result['total'], $params['limit'], $params['offset']);
    }

    /**
     * The figures above the table: KPI cards, tab counts, insight signals.
     *
     * Separate from the list because it answers a different question. The list
     * is one page; these are statements about everything the filters match, and
     * counting them from the page on screen would give a total that changes
     * when you turn the page.
     */
    public static function summary(): void
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'requisition.view');

        Http::data((new RequisitionService($ctx, $auth))->summary(self::filters(trim((string) (Http::param('q') ?? '')))));
    }

    /**
     * The filtered list as a CSV file.
     *
     * Same filters, same order, no pagination — what somebody exports is what
     * they were looking at. Under CLI the rows come back as data so the test
     * suite can assert on them without a browser.
     */
    public static function export(): void
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'requisition.view');

        $rows = (new RequisitionService($ctx, $auth))->exportRows(self::filters(trim((string) (Http::param('q') ?? ''))));
        $stem = 'requisitions-' . gmdate('Y-m-d');

        if (PHP_SAPI === 'cli') {
            Http::data(['csv' => self::csv($rows)]);
        }

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $stem . '.csv"');
        header('Cache-Control: no-store');
        echo self::csv($rows);
        exit;
    }

    /**
     * Every filter this screen can apply, read once.
     *
     * The list, the summary and the export all read the same set, which is what
     * stops an exported file quietly holding different rows from the table it
     * was exported from.
     *
     * @return array<string, mixed>
     */
    private static function filters(string $q): array
    {
        return [
            'status'             => Http::param('status'),
            'requester_uuid'     => Http::param('requester_uuid'),
            'department'         => Http::param('department'),
            'priority'           => Http::param('priority'),
            'date_from'          => self::date(Http::param('date_from')),
            'date_to'            => self::date(Http::param('date_to')),
            'required_by_before' => self::date(Http::param('required_by_before')),
            'min_value'          => Http::param('min_value'),
            'max_value'          => Http::param('max_value'),
            'exception'          => in_array(Http::param('exception'), ['emergency', 'single_source', 'non_preferred_vendor'], true)
                ? Http::param('exception')
                : null,
            'awaiting_approval'  => Http::param('awaiting_approval') === '1',
            'q'                  => $q,
        ];
    }

    /** A date from the query string, or null. Anything else is not a date. */
    private static function date(?string $value): ?string
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : null;
    }

    /** @param list<list<string>> $rows */
    private static function csv(array $rows): string
    {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            return '';
        }

        // A leading BOM so Excel opens the rupee sign and non-Latin department
        // names without mangling them — the same as every other export here.
        fwrite($handle, "\xEF\xBB\xBF");
        foreach ($rows as $row) {
            fputcsv($handle, array_map(static fn ($cell) => self::safeCell((string) $cell), $row), ',', '"', '\\');
        }
        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    /**
     * Stop a spreadsheet treating a cell as a formula.
     *
     * A justification a buyer typed beginning with = is a formula injection
     * waiting for whoever opens the export.
     */
    private static function safeCell(string $value): string
    {
        return preg_match('/^[=+\-@\t\r]/', $value) === 1 ? "'" . $value : $value;
    }

    public static function show(string $id): void
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'requisition.view');

        $requisition = (new RequisitionService($ctx, $auth))->find((int) $id);
        if ($requisition === []) {
            Http::notFound('That requisition does not exist.');
        }

        Http::data($requisition);
    }

    public static function create(): void
    {
        [$auth, $ctx] = self::enter();
        Http::data((new RequisitionService($ctx, $auth))->create(Http::body()), 201);
    }

    public static function submit(string $id): void
    {
        [$auth, $ctx] = self::enter();
        Http::data((new RequisitionService($ctx, $auth))->submit((int) $id));
    }

    public static function approve(string $id): void
    {
        [$auth, $ctx] = self::enter();
        Http::data((new RequisitionService($ctx, $auth))->decide((int) $id, 'approve', Http::body()));
    }

    public static function reject(string $id): void
    {
        [$auth, $ctx] = self::enter();
        Http::data((new RequisitionService($ctx, $auth))->decide((int) $id, 'reject', Http::body()));
    }

    /** Inventory's replenishment suggestions, read live and stored nowhere. */
    public static function replenishment(): void
    {
        [$auth, $ctx] = self::enter();
        Http::data((new RequisitionService($ctx, $auth))->replenishmentSuggestions([
            'warehouse_id' => Http::intParam('warehouse_id'),
            'item_grp_id'  => Http::intParam('item_grp_id'),
            'limit'        => Http::intParam('limit', 100),
        ]));
    }
}
