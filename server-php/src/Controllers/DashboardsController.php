<?php

declare(strict_types=1);

namespace Aicountly\Api\Controllers;

use Aicountly\Api\Dashboards\BillsDashboard;
use Aicountly\Api\Dashboards\Dashboard;
use Aicountly\Api\Dashboards\Filters;
use Aicountly\Api\Dashboards\InsightsDashboard;
use Aicountly\Api\Dashboards\OverviewDashboard;
use Aicountly\Api\Dashboards\Period;
use Aicountly\Api\Dashboards\ProcurementDashboard;
use Aicountly\Api\Dashboards\SuppliersDashboard;
use Aicountly\Api\Http;
use Aicountly\Api\Permissions;

/**
 * The five purchase dashboards.
 *
 * One route each, one class each, one envelope. The view is part of the URL so
 * a link to a dashboard is a link to that dashboard with those filters, and the
 * browser's Back button does what a person expects.
 */
final class DashboardsController extends Controller
{
    /** The switcher's contents, and the only views this controller will serve. */
    public const VIEWS = ['overview', 'procurement', 'suppliers', 'bills-payables', 'ai-insights'];

    public static function show(string $view): void
    {
        [$auth, $ctx] = self::enter();

        if (!in_array($view, self::VIEWS, true)) {
            Http::notFound('There is no "' . $view . '" dashboard. Try one of: ' . implode(', ', self::VIEWS) . '.');
        }

        Http::data(self::dashboard($view, $auth, $ctx)->build());
    }

    /**
     * Open payable per supplier, for the Overview's top-supplier table.
     *
     * Its own endpoint rather than a panel, because it is one Smart Books call
     * per supplier: the dashboard paints first and this column arrives after.
     */
    public static function supplierPayables(): void
    {
        [$auth, $ctx] = self::enter();

        $dashboard = new OverviewDashboard($ctx, $auth, Period::fromRequest(), Filters::fromRequest());
        Http::data($dashboard->supplierPayables());
    }

    /** The question box on AI Insights. */
    public static function ask(): void
    {
        [$auth, $ctx] = self::enter();

        $dashboard = new InsightsDashboard($ctx, $auth, Period::fromRequest(), Filters::fromRequest());
        Http::data($dashboard->ask());
    }

    /**
     * CSV of what is on screen.
     *
     * The same period, the same filters and the same calculations as the
     * dashboard that produced it — it calls the same class. An export built
     * from a second query is an export that disagrees with the screen the first
     * time either one changes.
     */
    public static function export(string $view): void
    {
        [$auth, $ctx] = self::enter();

        if (!in_array($view, self::VIEWS, true)) {
            Http::notFound('There is no "' . $view . '" dashboard to export.');
        }
        Permissions::assert($ctx, $auth, 'reports.view');

        $payload = self::dashboard($view, $auth, $ctx)->build();
        $rows = self::flatten($payload);

        if (PHP_SAPI === 'cli') {
            // Under test the CSV is returned as data so it can be asserted on.
            Http::data(['view' => $view, 'csv' => self::csv($rows)]);
        }

        $filename = 'purchases-' . $view . '-' . ($payload['period']['from'] ?? 'from') . '-to-' . ($payload['period']['to'] ?? 'to') . '.csv';

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-store');
        echo self::csv($rows);
        exit;
    }

    private static function dashboard(string $view, \Aicountly\Api\Auth $auth, \Aicountly\Api\Context $ctx): Dashboard
    {
        $period = Period::fromRequest();
        $filters = Filters::fromRequest();

        return match ($view) {
            'procurement'    => new ProcurementDashboard($ctx, $auth, $period, $filters),
            'suppliers'      => new SuppliersDashboard($ctx, $auth, $period, $filters),
            'bills-payables' => new BillsDashboard($ctx, $auth, $period, $filters),
            'ai-insights'    => new InsightsDashboard($ctx, $auth, $period, $filters),
            default          => new OverviewDashboard($ctx, $auth, $period, $filters),
        };
    }

    /**
     * The metric cards as rows, with their basis.
     *
     * The export carries the basis text as a column: a spreadsheet of figures
     * whose definitions were left behind on the screen is how two people end up
     * arguing about the same number.
     *
     * @param array<string, mixed> $payload
     * @return list<list<string>>
     */
    private static function flatten(array $payload): array
    {
        $rows = [[
            'Dashboard', 'Metric', 'Value', 'Raw value', 'Unit or currency',
            'Comparison', 'Basis', 'Higher is', 'Status',
        ]];

        $scope = (array) ($payload['scope'] ?? []);
        $period = (array) ($payload['period'] ?? []);
        $header = ucfirst(str_replace('-', ' ', (string) ($payload['view'] ?? 'dashboard')));

        $rows[] = ['Scope', 'Company', (string) ($scope['company_id'] ?? ''), '', '', '', 'Company id', '', ''];
        $rows[] = ['Scope', 'Financial year', (string) ($scope['financial_year_id'] ?? ''), '', '', '', 'Financial year id', '', ''];
        $rows[] = ['Scope', 'Branch', (string) ($scope['branch_label'] ?? ''), '', '', '', 'Branch scope', '', ''];
        $rows[] = ['Scope', 'Period', (string) ($period['label'] ?? ''), '', '', (string) ($period['comparison_label'] ?? ''), 'Date range these figures cover', '', ''];
        $rows[] = ['Scope', 'Generated at', (string) ($payload['generated_at'] ?? ''), '', '', '', 'UTC timestamp of this export', '', ''];

        foreach ((array) ($payload['sources'] ?? []) as $source) {
            $rows[] = [
                'Source',
                (string) ($source['label'] ?? ''),
                (string) ($source['status_label'] ?? ''),
                '',
                '',
                (string) ($source['as_of'] ?? ''),
                (string) ($source['message'] ?? 'Live read at the time of export'),
                '',
                (string) ($source['status'] ?? ''),
            ];
        }

        foreach ((array) ($payload['metrics'] ?? []) as $metric) {
            $rows[] = [
                $header,
                (string) ($metric['label'] ?? ''),
                // An unavailable figure exports as the word, never as 0 — a
                // zero in a spreadsheet gets summed.
                (string) ($metric['formatted_value'] ?? 'Unavailable'),
                (string) ($metric['raw_value'] ?? ''),
                (string) ($metric['currency'] ?? $metric['unit'] ?? ''),
                (string) ($metric['comparison_text'] ?? ''),
                (string) ($metric['basis'] ?? ''),
                match ((string) ($metric['direction'] ?? '')) {
                    'higher_is_better' => 'better',
                    'lower_is_better'  => 'worse',
                    default            => 'neither',
                },
                (string) ($metric['status'] ?? ''),
            ];
        }

        return $rows;
    }

    /** @param list<list<string>> $rows */
    private static function csv(array $rows): string
    {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            return '';
        }

        // A leading BOM so Excel opens ₹ and supplier names in other scripts
        // without mangling them.
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
     * A supplier name beginning with = is a formula injection waiting for
     * somebody to open the export.
     */
    private static function safeCell(string $value): string
    {
        return preg_match('/^[=+\-@\t\r]/', $value) === 1 ? "'" . $value : $value;
    }
}
