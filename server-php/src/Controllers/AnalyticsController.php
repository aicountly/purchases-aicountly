<?php

declare(strict_types=1);

namespace Aicountly\Api\Controllers;

use Aicountly\Api\Analytics\InsightsRequest;
use Aicountly\Api\Analytics\PurchasesAnalytics;
use Aicountly\Api\Http;

/**
 * Read-only operational summaries for AICOUNTLY Insights (CONTRACTS.md §7). Documented, with
 * envelopes captured from these handlers, in docs/contracts/insights/.
 */
final class AnalyticsController extends Controller
{
    public const PERMISSION = 'reports.view';

    /** GET v1/analytics/open-commitment?cmp_id&fy_id&bo_id — right now; takes no period. */
    public static function openCommitment(): void
    {
        $ctx = self::open(false);
        $rows = (new PurchasesAnalytics($ctx))->openCommitment();

        InsightsRequest::respond($ctx, ['rows' => $rows], InsightsRequest::listMeta($rows) + [
            'as_of'            => gmdate('Y-m-d\TH:i:s\Z'),
            'currency'         => null,
            'currency_per_row' => true,
            'basis'            => [
                'accounting'     => 'operational',
                'kind'           => 'balance (as at the request, not a past date)',
                'tax_inclusive'  => false,
                'returns_netted' => false,
                'posted_only'    => false,
                'rows'           => PurchasesAnalytics::COMMITMENT_BASIS,
            ],
            'source'           => 'purchases',
        ]);
    }

    /** GET v1/analytics/po-to-bill?cmp_id&fy_id&bo_id&from&to */
    public static function poToBill(): void
    {
        $ctx = self::open(true);
        $period = InsightsRequest::period();
        $rows = (new PurchasesAnalytics($ctx))->poToBill($period['from'], $period['to']);

        InsightsRequest::respond($ctx, ['rows' => $rows], InsightsRequest::listMeta($rows) + [
            'period'           => $period,
            'currency'         => null,
            'currency_per_row' => true,
            'basis'            => [
                'accounting'     => 'operational',
                'tax_inclusive'  => false,
                'returns_netted' => false,
                'posted_only'    => false,
                'rows'           => PurchasesAnalytics::PO_TO_BILL_BASIS,
            ],
            'source'           => 'purchases',
        ]);
    }

    private static function open(bool $period): \Aicountly\Api\Context
    {
        [$auth, $ctx] = self::enter();
        if ($auth->isService()) {
            Http::forbidden('These summaries are read as the signed-in person; a product\'s service key cannot read them.');
        }
        InsightsRequest::onlyParams([], $period);
        InsightsRequest::requirePermission($ctx, $auth, self::PERMISSION);

        return $ctx;
    }
}
