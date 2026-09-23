<?php

declare(strict_types=1);

namespace Aicountly\Api\Controllers;

use Aicountly\Api\Auth;
use Aicountly\Api\Context;
use Aicountly\Api\Dashboards\Decimal;
use Aicountly\Api\Dashboards\Format;
use Aicountly\Api\Dashboards\Metric;
use Aicountly\Api\Dashboards\Period;
use Aicountly\Api\Db;
use Aicountly\Api\Http;
use Aicountly\Api\Permissions;

/**
 * The approvals inbox.
 *
 * ONE SCREEN, THREE READS. The queue is what somebody acts on, the summary is
 * what they check it against, and the requester list is what narrows both. They
 * are separate endpoints because they change at different rates: a decision
 * reloads the queue and the summary, typing in the search box reloads only the
 * queue.
 *
 * WHAT THIS ENDPOINT WILL NOT DO. It never invents a figure. Every number below
 * is counted out of `purchase_approval_requests` and the two documents that
 * table points at; where a judgement is made — how risky an approval is, how
 * long it has waited — the rule is stated in the payload beside the answer, so
 * a reader can disagree with it. There is no score, no model and no stored
 * roll-up: an approval queue summarised from a nightly table is a queue that is
 * wrong for most of the day.
 *
 * THE AUTHORITY STAYS IN THE DOMAIN SERVICES. `may_approve` here is what the UI
 * uses to grey a button; RequisitionService and PurchaseOrderService are what
 * actually refuse the request, including the segregation-of-duties rule that
 * the person who raised a document may not approve it.
 */
final class ApprovalsController extends Controller
{
    /** Which document each approval kind points at, and what to call it. */
    private const ENTITY = [
        'requisition'    => ['label' => 'Requisition',    'permission' => 'requisition.approve', 'route' => '/requisitions'],
        'purchase_order' => ['label' => 'Purchase order', 'permission' => 'po.approve',          'route' => '/purchase-orders'],
    ];

    /** Waiting-time bands. Display only: this product has no approval SLA. */
    private const AGE_WARN = 3;
    private const AGE_LATE = 7;

    private const SELECT = <<<'SQL'
        SELECT a.approval_id, a.entity_type, a.entity_id, a.reason_kind, a.reason_detail,
               a.threshold_value, a.actual_value, a.status, a.requested_by,
               a.decided_by, a.decided_at, a.decision_note, a.created_at,
               a.stage_name, a.required_permission,
               r.requisition_no, r.requisition_date, r.estimated_value, r.department,
               r.requester_uuid, r.priority, r.exception_flags, r.justification,
               p.po_no, p.po_date, p.supplier_name_snapshot, p.supplier_account_id,
               p.total_amount, p.currency_code, p.created_by AS po_created_by,
               p.payment_terms, p.notes,
               sp.qualification_status, sp.risk_flag, sp.is_preferred,
               lbl.member_label
        SQL;

    private const FROM = <<<'SQL'
        FROM purchase_approval_requests a
        LEFT JOIN purchase_requisitions r
               ON a.entity_type = 'requisition' AND r.requisition_id = a.entity_id
        LEFT JOIN purchase_orders p
               ON a.entity_type = 'purchase_order' AND p.po_id = a.entity_id
        LEFT JOIN purchase_supplier_profiles sp
               ON sp.cmp_id = a.cmp_id AND sp.supplier_account_id = p.supplier_account_id
        LEFT JOIN LATERAL (
            SELECT pa.member_label
            FROM purchase_permission_assignments pa
            WHERE pa.cmp_id = a.cmp_id
              AND pa.user_uuid = a.requested_by
              AND pa.member_label IS NOT NULL
            ORDER BY pa.updated_at DESC
            LIMIT 1
        ) lbl ON TRUE
        SQL;

    /** Whoever actually raised the document, falling back to the request itself. */
    private const RAISED_BY = "COALESCE(p.created_by, r.requester_uuid, a.requested_by)";

    /** The value under approval, from the document rather than from the request. */
    private const VALUE = "COALESCE(a.actual_value, p.total_amount, r.estimated_value)";

    // -----------------------------------------------------------------------
    // The queue
    // -----------------------------------------------------------------------

    /**
     * Everything matching the filters, one page at a time.
     *
     * Paged on the SERVER. An approvals inbox is one of the few screens that
     * can genuinely hold thousands of rows in a busy month, and fetching all of
     * them to slice five off in the browser is how a screen that was fast in
     * testing becomes a screen nobody opens.
     */
    public static function queue(): void
    {
        [$auth, $ctx] = self::enter();

        $params = Http::listParams(['created_at', 'value', 'age'], 'created_at');
        $scopeName = self::scopeName();
        $decidable = self::decidableTypes($ctx, $auth);

        [$where, $binds] = self::filters($ctx, $auth, $scopeName, $decidable);

        $order = match ($params['sort']) {
            'value' => self::VALUE . ' ' . $params['order'] . ' NULLS LAST',
            'age'   => 'a.created_at ' . ($params['order'] === 'ASC' ? 'DESC' : 'ASC'),
            default => 'a.created_at ' . $params['order'],
        };

        $rows = Db::all(
            self::SELECT . "\n" . self::FROM . "\nWHERE " . implode("\n  AND ", $where)
                . "\nORDER BY " . $order . ', a.approval_id DESC'
                . "\nLIMIT " . $params['limit'] . ' OFFSET ' . $params['offset'],
            $binds,
        );

        $total = (int) Db::scalar(
            'SELECT COUNT(*) ' . self::FROM . "\nWHERE " . implode("\n  AND ", $where),
            $binds,
        );

        $settings = self::settings($ctx->cmpId);
        $shaped = array_map(
            static fn (array $row): array => self::shape($row, $auth->uuid, $decidable, $settings, $auth->ownsCompany($ctx->cmpId)),
            $rows,
        );

        Http::list($shaped, $total, $params['limit'], $params['offset'], [
            'scope'        => $scopeName,
            'sort'         => $params['sort'],
            'order'        => strtolower($params['order']),
            // The period narrows DECIDED documents only. A pending approval is
            // pending whatever month it was raised in, and hiding an aged one
            // behind a date filter defeats the entire point of the screen.
            'period_applies_to' => self::isPendingScope($scopeName) ? 'nothing' : 'decision_date',
            'can_approve'  => $decidable !== [],
            'decidable_types' => $decidable,
        ]);
    }

    /** The people who have raised something, for the Requester filter. */
    public static function requesters(): void
    {
        [$auth, $ctx] = self::enter();

        $rows = Db::all(
            'SELECT ' . self::RAISED_BY . ' AS user_uuid,
                    MAX(lbl.member_label) AS member_label,
                    COUNT(*) FILTER (WHERE a.status = \'PENDING\') AS pending,
                    COUNT(*) AS total
             ' . self::FROM . '
             WHERE a.cmp_id = :cmp AND a.fy_id = :fy
             GROUP BY 1
             ORDER BY pending DESC, total DESC
             LIMIT 100',
            ['cmp' => $ctx->cmpId, 'fy' => $ctx->fyId],
        );

        Http::data(array_map(static fn (array $row): array => [
            'user_uuid' => (string) $row['user_uuid'],
            'label'     => self::personLabel((string) $row['user_uuid'], $row['member_label'], $auth->uuid),
            'is_you'    => (string) $row['user_uuid'] === $auth->uuid,
            'pending'   => (int) $row['pending'],
            'total'     => (int) $row['total'],
        ], $rows));
    }

    // -----------------------------------------------------------------------
    // The summary
    // -----------------------------------------------------------------------

    /**
     * The six figures, the two charts and the two lists above the queue.
     *
     * The period applies to DECISIONS. Counts of what is still pending are a
     * position as at now and say so — comparing "waiting on you today" against
     * "waiting on you last month" would be comparing a photograph with a
     * different photograph.
     */
    public static function summary(): void
    {
        [$auth, $ctx] = self::enter();

        $period = Period::fromRequest();
        $binds = ['cmp' => $ctx->cmpId, 'fy' => $ctx->fyId];
        $currency = self::documentCurrency($ctx->cmpId, $ctx->fyId);
        $canSeeExceptions = Permissions::allows($ctx, $auth, 'match.view');

        // --- Position now -------------------------------------------------
        $position = Db::first(
            'SELECT COUNT(*) AS pending,
                    COUNT(*) FILTER (WHERE a.created_at >= date_trunc(\'day\', NOW())) AS raised_today,
                    COUNT(*) FILTER (WHERE a.created_at < NOW() - INTERVAL \'' . self::AGE_LATE . ' days\') AS waiting_long,
                    COUNT(*) FILTER (WHERE ' . self::RAISED_BY . ' = :me) AS raised_by_me,
                    COALESCE(SUM(' . self::VALUE . '), 0) AS pending_value
             ' . self::FROM . '
             WHERE a.cmp_id = :cmp AND a.fy_id = :fy AND a.status = \'PENDING\'',
            $binds + ['me' => $auth->uuid],
        ) ?? [];

        // --- Decisions, this period and the one before --------------------
        $decided = self::decisions($binds, $period->from, $period->to);
        $previous = $period->compareFrom === null
            ? ['approved' => null, 'rejected' => null, 'avg_days' => null]
            : self::decisions($binds, $period->compareFrom, (string) $period->compareTo);

        // What is waiting on THIS reader: pending, of a kind they may decide,
        // and not raised by them. The same three conditions the "Pending for
        // me" tab applies, so the tab's count and its contents agree.
        $decidable = self::decidableTypes($ctx, $auth);
        $mine = 0;
        if ($decidable !== []) {
            $mineBinds = $binds;
            $typeList = self::inList($decidable, 'dt', $mineBinds);
            $selfClause = '';
            if (!$auth->ownsCompany($ctx->cmpId)) {
                $selfClause = ' AND ' . self::RAISED_BY . ' <> :me';
                $mineBinds['me'] = $auth->uuid;
            }
            $mine = (int) Db::scalar(
                'SELECT COUNT(*) ' . self::FROM . '
                 WHERE a.cmp_id = :cmp AND a.fy_id = :fy AND a.status = \'PENDING\'
                   AND a.entity_type IN (' . $typeList . ')' . $selfClause,
                $mineBinds,
            );
        }

        $exceptions = $canSeeExceptions
            ? (int) Db::scalar(
                "SELECT COUNT(*) FROM purchase_match_exceptions WHERE cmp_id = :cmp AND status = 'OPEN'",
                ['cmp' => $ctx->cmpId],
            )
            : null;

        $trend = self::monthlyTrend($binds, $period->timezone);

        $metrics = [
            Metric::ready(
                'approvals_pending',
                'Pending approvals',
                (string) ((int) ($position['pending'] ?? 0)),
                'Approval requests still open in this company and financial year.',
                [
                    'direction'   => Metric::LOWER_IS_BETTER,
                    'explanation' => 'Every approval request that has not been approved or rejected, whoever it is waiting on.',
                    'comparison_unavailable_reason' => 'A pending count is a position as at now, not a total for a period.',
                    'footer'      => ((int) ($position['raised_today'] ?? 0)) . ' raised today',
                    'drilldown'   => ['route' => '/approvals', 'filters' => ['scope' => 'all_pending']],
                ],
            ),
            Metric::ready(
                'approvals_approved',
                'Approved this period',
                (string) $decided['approved'],
                'Approval requests approved between ' . $period->from . ' and ' . $period->to . '.',
                [
                    'direction' => Metric::HIGHER_IS_BETTER,
                    'previous'  => $previous['approved'] === null ? null : (string) $previous['approved'],
                    'comparison_label' => $period->comparisonLabel(),
                    'trend'     => array_map(
                        static fn (array $point): array => ['period' => $point['period'], 'value' => (string) $point['approved']],
                        $trend,
                    ),
                    'drilldown' => ['route' => '/approvals', 'filters' => ['scope' => 'actioned', 'status' => 'approved']],
                ],
            ),
            Metric::ready(
                'approvals_rejected',
                'Rejected this period',
                (string) $decided['rejected'],
                'Approval requests rejected between ' . $period->from . ' and ' . $period->to . '.',
                [
                    'direction' => Metric::LOWER_IS_BETTER,
                    'previous'  => $previous['rejected'] === null ? null : (string) $previous['rejected'],
                    'comparison_label' => $period->comparisonLabel(),
                    'trend'     => array_map(
                        static fn (array $point): array => ['period' => $point['period'], 'value' => (string) $point['rejected']],
                        $trend,
                    ),
                    'drilldown' => ['route' => '/approvals', 'filters' => ['scope' => 'actioned', 'status' => 'rejected']],
                ],
            ),
            $decided['avg_days'] === null
                ? Metric::unavailable(
                    'approval_time',
                    'Average decision time',
                    'Nothing was decided in this period, so there is no time to average.',
                    'Time from an approval request being raised to it being decided.',
                    ['format' => 'quantity', 'unit' => 'days'],
                )
                : Metric::ready(
                    'approval_time',
                    'Average decision time',
                    $decided['avg_days'],
                    'Time from an approval request being raised to it being decided, averaged over ' . ($decided['approved'] + $decided['rejected']) . ' decisions.',
                    [
                        'format'    => 'quantity',
                        'unit'      => 'days',
                        'direction' => Metric::LOWER_IS_BETTER,
                        'previous'  => $previous['avg_days'],
                        'comparison_label' => $period->comparisonLabel(),
                        'trend'     => array_map(
                            static fn (array $point): array => ['period' => $point['period'], 'value' => $point['avg_days']],
                            $trend,
                        ),
                    ],
                ),
            $currency === null
                ? Metric::unavailable(
                    'approvals_pending_value',
                    'Value awaiting approval',
                    'Documents awaiting approval are in more than one currency, so they are not added together.',
                    'The value of every document still awaiting approval.',
                    ['format' => 'currency'],
                )
                : Metric::ready(
                    'approvals_pending_value',
                    'Value awaiting approval',
                    Decimal::of((string) ($position['pending_value'] ?? '0')),
                    'The value of every document still awaiting approval, taken from the document itself.',
                    [
                        'format'    => 'currency',
                        'currency'  => $currency,
                        'compact'   => true,
                        'direction' => Metric::NEUTRAL,
                        'comparison_unavailable_reason' => 'A pending value is a position as at now, not a total for a period.',
                        'footer'    => ((int) ($position['waiting_long'] ?? 0)) . ' waiting more than ' . self::AGE_LATE . ' days',
                        'drilldown' => ['route' => '/approvals', 'filters' => ['scope' => 'all_pending']],
                    ],
                ),
            $exceptions === null
                ? Metric::unavailable(
                    'match_exceptions_open',
                    'Match exceptions open',
                    'Seeing match exceptions needs the match.view permission.',
                    'Three-way match exceptions still open against a bill.',
                    [],
                )
                : Metric::ready(
                    'match_exceptions_open',
                    'Match exceptions open',
                    (string) $exceptions,
                    'Three-way match exceptions still open against a bill in this company.',
                    [
                        'direction' => Metric::LOWER_IS_BETTER,
                        'comparison_unavailable_reason' => 'An open exception count is a position as at now.',
                        'footer'    => $exceptions === 0 ? 'Every bill agrees with its order and its receipt' : 'Blocking a bill from being posted',
                        'drilldown' => ['route' => '/approvals', 'filters' => ['scope' => 'exceptions']],
                    ],
                ),
        ];

        Http::data([
            'scope' => [
                'company_id'         => $ctx->cmpId,
                'financial_year_id'  => $ctx->fyId,
                'branch_id'          => $ctx->boId,
                'branch_label'       => $ctx->boId > 0 ? 'Branch ' . $ctx->boId : 'All branches',
                'reporting_currency' => $currency,
            ],
            'period'       => $period->toArray(),
            'generated_at' => gmdate('c'),
            'sources'      => [[
                'id'           => 'purchases',
                'label'        => 'Purchases',
                'status'       => 'ready',
                'status_label' => 'Live',
                'as_of'        => gmdate('c'),
                'message'      => null,
            ]],
            'metrics'    => $metrics,
            'counts'     => [
                'mine'         => $mine,
                'pending'      => (int) ($position['pending'] ?? 0),
                'raised_today' => (int) ($position['raised_today'] ?? 0),
                'raised_by_me' => (int) ($position['raised_by_me'] ?? 0),
                'waiting_long' => (int) ($position['waiting_long'] ?? 0),
                'exceptions'   => $exceptions,
            ],
            'by_type'    => self::pendingByType($binds, $currency),
            'trend'      => self::trendPanel($trend),
            'exceptions' => self::exceptionPanel($ctx->cmpId, $canSeeExceptions, $currency),
            'risks'      => self::risks($ctx->cmpId, $ctx->fyId, $auth->uuid, $exceptions, $currency),
            'insights'   => self::insights($ctx->cmpId, $ctx->fyId, $currency, $decided, $previous, $period->comparisonLabel()),
        ]);
    }

    // -----------------------------------------------------------------------
    // Filters
    // -----------------------------------------------------------------------

    private static function scopeName(): string
    {
        $scope = (string) (Http::param('scope') ?? 'mine');

        return in_array($scope, ['mine', 'raised_by_me', 'all_pending', 'actioned', 'all'], true) ? $scope : 'mine';
    }

    private static function isPendingScope(string $scope): bool
    {
        return in_array($scope, ['mine', 'raised_by_me', 'all_pending'], true);
    }

    /**
     * Which kinds of approval this user may actually decide.
     *
     * Used to filter the "Pending for me" tab in SQL rather than in PHP, so the
     * total under the table is the total of the rows in it. A page that says
     * "1–10 of 48" and then hides six of the ten is worse than no count.
     *
     * @return list<string>
     */
    private static function decidableTypes(Context $ctx, Auth $auth): array
    {
        $types = [];
        foreach (self::ENTITY as $type => $meta) {
            if (Permissions::allows($ctx, $auth, $meta['permission'])) {
                $types[] = $type;
            }
        }

        return $types;
    }

    /**
     * @param list<string> $decidable
     * @return array{0: list<string>, 1: array<string, mixed>}
     */
    private static function filters(Context $ctx, Auth $auth, string $scope, array $decidable): array
    {
        $where = ['a.cmp_id = :cmp', 'a.fy_id = :fy'];
        $binds = ['cmp' => $ctx->cmpId, 'fy' => $ctx->fyId];

        switch ($scope) {
            case 'mine':
                $where[] = "a.status = 'PENDING'";
                // The company owner may approve their own documents; everybody
                // else may not, which is the rule the domain services enforce.
                if (!$auth->ownsCompany($ctx->cmpId)) {
                    $where[] = self::RAISED_BY . ' <> :me';
                    $binds['me'] = $auth->uuid;
                }
                if ($decidable === []) {
                    $where[] = 'FALSE';
                } else {
                    $where[] = 'a.entity_type IN (' . self::inList($decidable, 'dt', $binds) . ')';
                }
                break;

            case 'raised_by_me':
                $where[] = "a.status = 'PENDING'";
                $where[] = self::RAISED_BY . ' = :me';
                $binds['me'] = $auth->uuid;
                break;

            case 'all_pending':
                $where[] = "a.status = 'PENDING'";
                break;

            case 'actioned':
                $where[] = "a.status IN ('APPROVED', 'REJECTED')";
                break;
        }

        // An explicit status narrows further, and is what the decided tabs use
        // to split approved from rejected.
        $status = strtoupper((string) (Http::param('status') ?? ''));
        if (in_array($status, ['PENDING', 'APPROVED', 'REJECTED', 'WITHDRAWN', 'SKIPPED'], true)) {
            $where[] = 'a.status = :status';
            $binds['status'] = $status;
        }

        $type = (string) (Http::param('type') ?? '');
        if (isset(self::ENTITY[$type])) {
            $where[] = 'a.entity_type = :type';
            $binds['type'] = $type;
        }

        $requester = trim((string) (Http::param('requester') ?? ''));
        if ($requester !== '') {
            $where[] = self::RAISED_BY . ' = :requester';
            $binds['requester'] = $requester;
        }

        if (!self::isPendingScope($scope)) {
            $period = Period::fromRequest();
            $where[] = 'a.decided_at IS NOT NULL AND (a.decided_at AT TIME ZONE :tz)::date BETWEEN :from AND :to';
            $binds['tz'] = $period->timezone;
            $binds['from'] = $period->from;
            $binds['to'] = $period->to;
        }

        $q = trim((string) (Http::param('q') ?? ''));
        if ($q !== '') {
            $where[] = '(p.po_no ILIKE :q OR r.requisition_no ILIKE :q OR p.supplier_name_snapshot ILIKE :q'
                . ' OR lbl.member_label ILIKE :q OR a.reason_detail ILIKE :q OR r.department ILIKE :q)';
            $binds['q'] = '%' . $q . '%';
        }

        return [$where, $binds];
    }

    /**
     * @param list<string> $values
     * @param array<string, mixed> $binds
     */
    private static function inList(array $values, string $prefix, array &$binds): string
    {
        $names = [];
        foreach (array_values($values) as $index => $value) {
            $name = $prefix . $index;
            $names[] = ':' . $name;
            $binds[$name] = $value;
        }

        return implode(', ', $names);
    }

    // -----------------------------------------------------------------------
    // Shaping one row
    // -----------------------------------------------------------------------

    /**
     * @param array<string, mixed> $row
     * @param list<string> $decidable
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    private static function shape(array $row, string $me, array $decidable, array $settings, bool $isOwner): array
    {
        $type = (string) $row['entity_type'];
        $meta = self::ENTITY[$type] ?? ['label' => ucfirst(str_replace('_', ' ', $type)), 'permission' => null, 'route' => null];

        $isOrder = $type === 'purchase_order';
        $documentNo = $isOrder
            ? ($row['po_no'] ?? null)
            : ($row['requisition_no'] ?? null);
        $documentDate = $isOrder ? ($row['po_date'] ?? null) : ($row['requisition_date'] ?? null);

        $raisedBy = (string) ($row['po_created_by'] ?? $row['requester_uuid'] ?? $row['requested_by']);
        $selfRaised = $raisedBy === $me;

        $value = Decimal::parse($row['actual_value'] ?? null)
            ?? Decimal::parse($row['total_amount'] ?? null)
            ?? Decimal::parse($row['estimated_value'] ?? null);
        $currency = (string) ($row['currency_code'] ?? 'INR');

        $ageDays = self::ageInDays((string) $row['created_at']);
        [$risk, $riskReasons] = self::risk($row, $settings);

        $permitted = $meta['permission'] !== null && in_array($type, $decidable, true);
        $blocked = null;
        if (!$permitted) {
            $blocked = 'You do not have permission to approve a ' . strtolower((string) $meta['label']) . '.';
        } elseif ($selfRaised && !$isOwner) {
            $blocked = 'You raised this document, so somebody else has to approve it.';
        } elseif ((string) $row['status'] !== 'PENDING') {
            $blocked = 'This request has already been decided.';
        }

        return [
            'approval_id'   => (int) $row['approval_id'],
            'entity_type'   => $type,
            'entity_id'     => (int) $row['entity_id'],
            'type_label'    => $meta['label'],

            'document_no'   => $documentNo === null ? null : (string) $documentNo,
            'document_label' => $documentNo === null
                ? $meta['label'] . ' #' . (int) $row['entity_id']
                : (string) $documentNo,
            'document_title' => self::documentTitle($row, $isOrder),
            'document_date'  => $documentDate === null ? null : (string) $documentDate,
            'document_date_label' => Format::date($documentDate === null ? null : (string) $documentDate),
            'route'         => $meta['route'] === null ? null : $meta['route'] . '/' . (int) $row['entity_id'],

            'supplier_name' => $row['supplier_name_snapshot'] === null ? null : (string) $row['supplier_name_snapshot'],
            'supplier_account_id' => $row['supplier_account_id'] === null ? null : (int) $row['supplier_account_id'],
            // GSTIN and the rest of the supplier's identity belong to Contacts
            // and are not copied here. What this product knows about a supplier
            // is its own procurement profile, so that is what is shown.
            'supplier_note' => self::supplierNote($row),

            'amount'           => $value,
            'amount_formatted' => $value === null ? null : Format::money($value, $currency),
            'currency'         => $value === null ? null : $currency,

            'raised_by'         => $raisedBy,
            'requester_label'   => self::personLabel($raisedBy, $row['member_label'] ?? null, $me),
            'requester_initials' => self::initials(self::personLabel($raisedBy, $row['member_label'] ?? null, $me)),
            'requester_department' => $row['department'] === null ? null : (string) $row['department'],

            'requested_at'       => (string) $row['created_at'],
            'requested_at_label' => Format::date(substr((string) $row['created_at'], 0, 10)),
            'age_days'           => $ageDays,
            'age_label'          => $ageDays === 0 ? 'Today' : $ageDays . 'd',
            'age_band'           => $ageDays >= self::AGE_LATE ? 'late' : ($ageDays >= self::AGE_WARN ? 'waiting' : 'fresh'),
            'age_note'           => $ageDays === 0
                ? 'Raised today'
                : 'Waiting ' . $ageDays . ' day' . ($ageDays === 1 ? '' : 's'),

            'risk'         => $risk,
            'risk_label'   => self::RISK_LABEL[$risk],
            'risk_reasons' => $riskReasons,

            'status'       => (string) $row['status'],
            'status_label' => ucfirst(strtolower((string) $row['status'])),

            'reason_kind'   => (string) $row['reason_kind'],
            'reason_detail' => $row['reason_detail'] === null ? null : (string) $row['reason_detail'],
            'stage_name'    => $row['stage_name'] === null ? null : (string) $row['stage_name'],
            'threshold_formatted' => ($t = Decimal::parse($row['threshold_value'] ?? null)) === null
                ? null
                : Format::money($t, $currency),

            'is_self_raised' => $selfRaised,
            'may_approve'    => $blocked === null,
            'block_reason'   => $blocked,

            'decided_by'       => $row['decided_by'] === null ? null : (string) $row['decided_by'],
            'decided_at'       => $row['decided_at'] === null ? null : (string) $row['decided_at'],
            'decided_at_label' => $row['decided_at'] === null ? null : Format::date(substr((string) $row['decided_at'], 0, 10)),
            'decision_note'    => $row['decision_note'] === null ? null : (string) $row['decision_note'],
        ];
    }

    private const RISK_LABEL = [
        'high'         => 'High',
        'medium'       => 'Medium',
        'low'          => 'Low',
        'not_assessed' => 'Not assessed',
    ];

    /**
     * How far outside the ordinary this document is.
     *
     * NOT A SCORE AND NOT A MODEL. Four facts this product already records, each
     * of which a buyer would raise in a review anyway: how far the value is over
     * the threshold that forced the approval, the exception flags the requester
     * themselves ticked, whether the supplier has been qualified, and whether
     * somebody has flagged that supplier as a risk. The reasons travel with the
     * verdict so a reader can see what it was built from, and where none of the
     * four can be judged the answer is "Not assessed" rather than "Low".
     *
     * WHY THE VALUE NEVER REACHES HIGH ON ITS OWN. Everything in this queue is
     * here BECAUSE it broke the value rule, and a company that sets its
     * threshold low enough to see everything would then see a column of red and
     * stop reading it. Size is a matter of degree, so it tops out at medium;
     * High is kept for the three facts that are somebody's recorded judgement —
     * a flagged supplier, a supplier the company's own setting says may not be
     * ordered from, and a requester's own single-source or non-preferred-vendor
     * declaration. A warning that is always on is not a warning.
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed> $settings
     * @return array{0: string, 1: list<string>}
     */
    private static function risk(array $row, array $settings): array
    {
        $reasons = [];
        $level = 0;
        $judged = false;

        $threshold = Decimal::parse($row['threshold_value'] ?? null);
        $actual = Decimal::parse($row['actual_value'] ?? null)
            ?? Decimal::parse($row['total_amount'] ?? null)
            ?? Decimal::parse($row['estimated_value'] ?? null);

        if ($threshold !== null && $actual !== null && !Decimal::isZero($threshold)) {
            $judged = true;
            $times = Decimal::div($actual, $threshold, 2);
            if ($times !== null && Decimal::cmp($times, '3') >= 0) {
                $level = max($level, 2);
                $reasons[] = 'Value is ' . Decimal::fixed($times, 1) . '× the approval threshold.';
            } else {
                $level = max($level, 1);
            }
        }

        foreach (Db::jsonColumn($row['exception_flags'] ?? null) as $flag) {
            if (!is_string($flag) || $flag === '') {
                continue;
            }
            $judged = true;
            $level = max($level, in_array($flag, ['single_source', 'non_preferred_vendor'], true) ? 3 : 2);
            $reasons[] = 'Raised as ' . str_replace('_', ' ', $flag) . '.';
        }

        if ($row['supplier_account_id'] !== null) {
            $judged = true;
            $qualification = (string) ($row['qualification_status'] ?? 'none');
            $enforced = (bool) ($settings['enforce_approved_vendors'] ?? false);

            if ($row['risk_flag'] !== null && (string) $row['risk_flag'] !== '') {
                $level = max($level, 3);
                $reasons[] = 'Supplier is flagged: ' . (string) $row['risk_flag'] . '.';
            }
            if ($row['qualification_status'] === null) {
                $level = max($level, $enforced ? 3 : 2);
                $reasons[] = 'Supplier has no procurement profile in Purchases'
                    . ($enforced ? ', and this company only orders from approved suppliers.' : '.');
            } elseif ($qualification !== 'approved') {
                $level = max($level, $enforced ? 3 : 2);
                $reasons[] = 'Supplier is ' . str_replace('_', ' ', $qualification) . ', not approved'
                    . ($enforced ? ', and this company only orders from approved suppliers.' : '.');
            } else {
                $level = max($level, 1);
            }
        }

        if (!$judged) {
            return ['not_assessed', []];
        }

        return [match ($level) { 3 => 'high', 2 => 'medium', default => 'low' }, $reasons];
    }

    /**
     * The line under the document number.
     *
     * Never the supplier: that sits in the very next column, and a cell that
     * repeats its neighbour costs a line of row height for nothing. What goes
     * here is what the reader does not already have — what the order says about
     * itself, or what the requisition was raised for.
     *
     * @param array<string, mixed> $row
     */
    private static function documentTitle(array $row, bool $isOrder): ?string
    {
        if ($isOrder) {
            $notes = trim((string) ($row['notes'] ?? ''));
            if ($notes !== '') {
                return mb_strimwidth($notes, 0, 60, '…');
            }
            $terms = trim((string) ($row['payment_terms'] ?? ''));

            return $terms === '' ? null : $terms;
        }

        $justification = trim((string) ($row['justification'] ?? ''));
        if ($justification !== '') {
            return mb_strimwidth($justification, 0, 60, '…');
        }

        $department = trim((string) ($row['department'] ?? ''));

        return $department === '' ? null : $department;
    }

    /** @param array<string, mixed> $row */
    private static function supplierNote(array $row): ?string
    {
        if ($row['supplier_account_id'] === null) {
            return $row['department'] === null ? null : 'Department: ' . (string) $row['department'];
        }

        $status = $row['qualification_status'] === null
            ? 'No procurement profile'
            : ucfirst(str_replace('_', ' ', (string) $row['qualification_status']));

        return ((bool) ($row['is_preferred'] ?? false) ? 'Preferred · ' : '') . $status;
    }

    private static function ageInDays(string $createdAt): int
    {
        $raised = strtotime($createdAt);
        if ($raised === false) {
            return 0;
        }

        return max(0, (int) floor((time() - $raised) / 86400));
    }

    /**
     * A person, named where this product legitimately knows the name.
     *
     * Identity belongs to my.aicountly.com. What Purchases holds is the label an
     * administrator typed against a permission assignment, so that is what is
     * used; with none, the uuid is shortened rather than invented.
     */
    private static function personLabel(string $uuid, mixed $label, string $me): string
    {
        if ($uuid === $me) {
            return 'You';
        }
        $typed = trim((string) ($label ?? ''));
        if ($typed !== '') {
            return $typed;
        }

        return strlen($uuid) > 12 ? substr($uuid, 0, 8) . '…' : ($uuid === '' ? 'Unknown' : $uuid);
    }

    private static function initials(string $label): string
    {
        $parts = preg_split('/\s+/', trim($label)) ?: [];
        $parts = array_values(array_filter($parts, static fn (string $p): bool => $p !== ''));
        if ($parts === []) {
            return '—';
        }
        if (count($parts) === 1) {
            return mb_strtoupper(mb_substr($parts[0], 0, 2));
        }

        return mb_strtoupper(mb_substr($parts[0], 0, 1) . mb_substr($parts[count($parts) - 1], 0, 1));
    }

    // -----------------------------------------------------------------------
    // Summary parts
    // -----------------------------------------------------------------------

    /**
     * @param array<string, mixed> $binds
     * @return array{approved: int, rejected: int, avg_days: string|null}
     */
    private static function decisions(array $binds, string $from, string $to): array
    {
        $row = Db::first(
            "SELECT COUNT(*) FILTER (WHERE status = 'APPROVED') AS approved,
                    COUNT(*) FILTER (WHERE status = 'REJECTED') AS rejected,
                    AVG(EXTRACT(EPOCH FROM (decided_at - created_at)) / 86400.0) AS avg_days
             FROM purchase_approval_requests
             WHERE cmp_id = :cmp AND fy_id = :fy
               AND status IN ('APPROVED', 'REJECTED')
               AND decided_at IS NOT NULL
               AND (decided_at AT TIME ZONE 'Asia/Kolkata')::date BETWEEN :from AND :to",
            $binds + ['from' => $from, 'to' => $to],
        ) ?? [];

        return [
            'approved' => (int) ($row['approved'] ?? 0),
            'rejected' => (int) ($row['rejected'] ?? 0),
            'avg_days' => $row['avg_days'] === null ? null : Decimal::round(Decimal::of((string) $row['avg_days']), 1),
        ];
    }

    /**
     * Six months of decisions, for the sparklines and the trend chart.
     *
     * @param array<string, mixed> $binds
     * @return list<array{period: string, label: string, approved: int, rejected: int, avg_days: string|null}>
     */
    private static function monthlyTrend(array $binds, string $timezone): array
    {
        $rows = Db::all(
            "SELECT to_char(date_trunc('month', (decided_at AT TIME ZONE :tz)), 'YYYY-MM') AS period,
                    COUNT(*) FILTER (WHERE status = 'APPROVED') AS approved,
                    COUNT(*) FILTER (WHERE status = 'REJECTED') AS rejected,
                    AVG(EXTRACT(EPOCH FROM (decided_at - created_at)) / 86400.0) AS avg_days
             FROM purchase_approval_requests
             WHERE cmp_id = :cmp AND fy_id = :fy
               AND status IN ('APPROVED', 'REJECTED')
               AND decided_at IS NOT NULL
               AND decided_at >= (date_trunc('month', NOW()) - INTERVAL '5 months')
             GROUP BY 1
             ORDER BY 1",
            $binds + ['tz' => $timezone],
        );

        $found = [];
        foreach ($rows as $row) {
            $found[(string) $row['period']] = $row;
        }

        // Every month in the window, so a quiet month is drawn as a quiet month
        // rather than skipped and the line reading as if it never happened.
        $out = [];
        $cursor = new \DateTimeImmutable('first day of this month', new \DateTimeZone($timezone));
        for ($back = 5; $back >= 0; $back--) {
            $month = $cursor->modify('-' . $back . ' months');
            $key = $month->format('Y-m');
            $row = $found[$key] ?? null;
            $out[] = [
                'period'   => $key,
                'label'    => $month->format('M Y'),
                'approved' => (int) ($row['approved'] ?? 0),
                'rejected' => (int) ($row['rejected'] ?? 0),
                'avg_days' => $row === null || $row['avg_days'] === null
                    ? null
                    : Decimal::round(Decimal::of((string) $row['avg_days']), 1),
            ];
        }

        return $out;
    }

    /**
     * @param list<array{period: string, label: string, approved: int, rejected: int, avg_days: string|null}> $trend
     * @return array<string, mixed>
     */
    private static function trendPanel(array $trend): array
    {
        $decisions = array_sum(array_map(static fn (array $point): int => $point['approved'] + $point['rejected'], $trend));

        if ($decisions === 0) {
            return [
                'available' => true,
                'points'    => [],
                'basis'     => 'Approval requests decided in each of the last six months.',
                'note'      => 'Nothing has been decided in the last six months, so there is no trend to draw.',
            ];
        }

        return [
            'available' => true,
            'points'    => $trend,
            'basis'     => 'Approval requests decided in each of the last six months, by the month of the decision.',
            'note'      => null,
        ];
    }

    /**
     * @param array<string, mixed> $binds
     * @return array<string, mixed>
     */
    private static function pendingByType(array $binds, ?string $currency): array
    {
        if ($currency === null) {
            return [
                'available' => false,
                'reason'    => 'Documents awaiting approval are in more than one currency, so their values are not added together.',
                'kind'      => 'source',
            ];
        }

        $rows = Db::all(
            'SELECT a.entity_type, COUNT(*) AS documents, COALESCE(SUM(' . self::VALUE . '), 0) AS value
             ' . self::FROM . '
             WHERE a.cmp_id = :cmp AND a.fy_id = :fy AND a.status = \'PENDING\'
             GROUP BY a.entity_type
             ORDER BY value DESC',
            $binds,
        );

        $total = Decimal::sum(array_map(static fn (array $row): string => Decimal::of((string) $row['value']), $rows));

        return [
            'available'       => true,
            'currency'        => $currency,
            'total'           => $total,
            'total_formatted' => Format::compactMoney($total, $currency),
            'total_exact'     => Format::money($total, $currency),
            'rows'            => array_map(static function (array $row) use ($total, $currency): array {
                $value = Decimal::of((string) $row['value']);
                $type = (string) $row['entity_type'];

                return [
                    'id'        => $type,
                    'label'     => self::ENTITY[$type]['label'] ?? ucfirst(str_replace('_', ' ', $type)),
                    'documents' => (int) $row['documents'],
                    'value'     => $value,
                    'formatted' => Format::money($value, $currency),
                    'share_pc'  => Decimal::isZero($total) ? null : Decimal::percentOf($value, $total, 1),
                ];
            }, $rows),
            'basis' => 'The value of every document still awaiting approval, grouped by what kind of document it is.',
        ];
    }

    /** @return array<string, mixed> */
    private static function exceptionPanel(int $cmpId, bool $allowed, ?string $currency): array
    {
        if (!$allowed) {
            return [
                'available' => false,
                'reason'    => 'Seeing match exceptions needs the match.view permission.',
                'kind'      => 'permission',
            ];
        }

        $rows = Db::all(
            "SELECT exception_kind, COUNT(*) AS documents, COALESCE(SUM(ABS(variance_value)), 0) AS variance
             FROM purchase_match_exceptions
             WHERE cmp_id = :cmp AND status = 'OPEN'
             GROUP BY exception_kind
             ORDER BY documents DESC",
            ['cmp' => $cmpId],
        );

        return [
            'available' => true,
            'total'     => array_sum(array_map(static fn (array $row): int => (int) $row['documents'], $rows)),
            'rows'      => array_map(static function (array $row) use ($currency): array {
                $variance = Decimal::of((string) $row['variance']);

                return [
                    'id'        => (string) $row['exception_kind'],
                    'label'     => self::exceptionLabel((string) $row['exception_kind']),
                    'documents' => (int) $row['documents'],
                    'variance'  => $variance,
                    // A variance has no meaning across currencies, so it is
                    // only stated where every document in scope shares one.
                    'variance_formatted' => $currency === null || Decimal::isZero($variance)
                        ? null
                        : Format::money($variance, $currency),
                ];
            }, $rows),
            'basis' => 'Three-way match exceptions still open, grouped by what did not agree.',
        ];
    }

    private static function exceptionLabel(string $kind): string
    {
        return match ($kind) {
            'quantity'        => 'Receipt quantity does not match',
            'rate'            => 'Billed rate does not match the order',
            'value'           => 'Bill value outside tolerance',
            'tax'             => 'Tax does not match the order',
            'freight'         => 'Freight outside tolerance',
            'missing_receipt' => 'Billed with no goods receipt',
            'over_billed'     => 'Billed for more than was received',
            default           => ucfirst(str_replace('_', ' ', $kind)),
        };
    }

    /**
     * What is actually wrong right now, one row per rule that matched.
     *
     * Every row is a count of records this screen can open. Nothing is listed
     * because it might be interesting; a rule that matches nothing produces no
     * row, and a list with no rows is the answer the reader was hoping for.
     *
     * @return array<string, mixed>
     */
    private static function risks(int $cmpId, int $fyId, string $me, ?int $exceptions, ?string $currency): array
    {
        $binds = ['cmp' => $cmpId, 'fy' => $fyId, 'me' => $me];

        $row = Db::first(
            'SELECT
                COUNT(*) FILTER (WHERE a.created_at < NOW() - INTERVAL \'' . self::AGE_LATE . ' days\') AS waiting_long,
                COUNT(*) FILTER (WHERE ' . self::RAISED_BY . ' = :me) AS raised_by_me,
                COUNT(*) FILTER (WHERE p.supplier_account_id IS NOT NULL
                                   AND COALESCE(sp.qualification_status, \'none\') <> \'approved\') AS unqualified,
                COUNT(*) FILTER (WHERE sp.risk_flag IS NOT NULL AND sp.risk_flag <> \'\') AS flagged,
                COALESCE(SUM(' . self::VALUE . ') FILTER (
                    WHERE a.threshold_value IS NOT NULL
                      AND a.threshold_value > 0
                      AND ' . self::VALUE . ' >= a.threshold_value * 3
                ), 0) AS far_over_value,
                COUNT(*) FILTER (
                    WHERE a.threshold_value IS NOT NULL
                      AND a.threshold_value > 0
                      AND ' . self::VALUE . ' >= a.threshold_value * 3
                ) AS far_over
             ' . self::FROM . '
             WHERE a.cmp_id = :cmp AND a.fy_id = :fy AND a.status = \'PENDING\'',
            $binds,
        ) ?? [];

        $items = [];

        if ((int) ($row['far_over'] ?? 0) > 0) {
            $value = Decimal::of((string) ($row['far_over_value'] ?? '0'));
            $items[] = [
                'id'       => 'far_over_threshold',
                'severity' => 'critical',
                'title'    => self::plural((int) $row['far_over'], 'approval', 'approvals') . ' far above the threshold',
                'detail'   => ($currency === null ? 'Each is' : Format::money($value, $currency) . ' in total, each')
                    . ' at least three times the value that forced the approval.',
                'basis'    => 'Pending approvals whose document value is three or more times the threshold recorded on the request.',
                'route'    => '/approvals',
                'filters'  => ['scope' => 'all_pending', 'sort' => 'value', 'order' => 'desc'],
            ];
        }

        if ((int) ($row['waiting_long'] ?? 0) > 0) {
            $items[] = [
                'id'       => 'waiting_long',
                'severity' => 'warning',
                'title'    => self::plural((int) $row['waiting_long'], 'approval has', 'approvals have') . ' waited over a week',
                'detail'   => 'Raised more than ' . self::AGE_LATE . ' days ago and still not decided.',
                'basis'    => 'Pending approval requests raised more than ' . self::AGE_LATE . ' days ago. This product has no approval SLA; the band is a reading aid.',
                'route'    => '/approvals',
                'filters'  => ['scope' => 'all_pending', 'sort' => 'age', 'order' => 'desc'],
            ];
        }

        if ((int) ($row['flagged'] ?? 0) > 0) {
            $items[] = [
                'id'       => 'supplier_flagged',
                'severity' => 'critical',
                'title'    => self::plural((int) $row['flagged'], 'order', 'orders') . ' on a flagged supplier',
                'detail'   => 'Somebody has recorded a risk against the supplier on this order.',
                'basis'    => 'Pending approvals whose supplier profile carries a risk flag.',
                'route'    => '/approvals',
                'filters'  => ['scope' => 'all_pending', 'type' => 'purchase_order'],
            ];
        }

        if ((int) ($row['unqualified'] ?? 0) > 0) {
            $items[] = [
                'id'       => 'supplier_unqualified',
                'severity' => 'warning',
                'title'    => self::plural((int) $row['unqualified'], 'order is', 'orders are') . ' on an unapproved supplier',
                'detail'   => 'The supplier has no approved procurement profile in Purchases.',
                'basis'    => 'Pending purchase orders whose supplier profile is missing or not in the approved state.',
                'route'    => '/approvals',
                'filters'  => ['scope' => 'all_pending', 'type' => 'purchase_order'],
            ];
        }

        if ((int) ($row['raised_by_me'] ?? 0) > 0) {
            $items[] = [
                'id'       => 'raised_by_me',
                'severity' => 'info',
                'title'    => self::plural((int) $row['raised_by_me'], 'document you raised is', 'documents you raised are') . ' waiting',
                'detail'   => 'You cannot approve your own document, so these are waiting on somebody else.',
                'basis'    => 'Pending approvals where you are the person who raised the document.',
                'route'    => '/approvals',
                'filters'  => ['scope' => 'raised_by_me'],
            ];
        }

        if ($exceptions !== null && $exceptions > 0) {
            $items[] = [
                'id'       => 'match_exceptions',
                'severity' => 'warning',
                'title'    => self::plural($exceptions, 'match exception is', 'match exceptions are') . ' unresolved',
                'detail'   => 'A bill does not agree with its order or its receipt and cannot be posted.',
                'basis'    => 'Open rows in the three-way match exception table.',
                'route'    => '/approvals',
                'filters'  => ['scope' => 'exceptions'],
            ];
        }

        return ['available' => true, 'rows' => $items];
    }

    /**
     * The short reads over the queue.
     *
     * NOT WRITTEN BY A MODEL, and the payload says so. Each row is one SQL
     * question with the rule behind it stated in `basis`, which is how the rest
     * of this product treats an "insight" — a sentence somebody can check,
     * rather than a sentence somebody has to trust.
     *
     * @param array{approved: int, rejected: int, avg_days: string|null} $decided
     * @param array{approved: int|null, rejected: int|null, avg_days: string|null} $previous
     * @return array<string, mixed>
     */
    private static function insights(
        int $cmpId,
        int $fyId,
        ?string $currency,
        array $decided,
        array $previous,
        string $comparisonLabel,
    ): array {
        $binds = ['cmp' => $cmpId, 'fy' => $fyId];
        $items = [];

        // The single largest thing waiting, because it is the one decision on
        // the screen that is worth reading the detail of.
        $largest = Db::first(
            'SELECT ' . self::VALUE . ' AS value, p.po_no, r.requisition_no, p.supplier_name_snapshot,
                    p.currency_code, a.entity_type, a.entity_id
             ' . self::FROM . '
             WHERE a.cmp_id = :cmp AND a.fy_id = :fy AND a.status = \'PENDING\' AND ' . self::VALUE . ' IS NOT NULL
             ORDER BY ' . self::VALUE . ' DESC
             LIMIT 1',
            $binds,
        );

        if ($largest !== null) {
            $value = Decimal::of((string) $largest['value']);
            $document = (string) ($largest['po_no'] ?? $largest['requisition_no'] ?? ('#' . (int) $largest['entity_id']));
            $items[] = [
                'id'    => 'largest_pending',
                'tone'  => 'info',
                'kind'  => 'observation',
                'kind_label' => 'Observed',
                'title' => 'Largest approval waiting',
                'detail' => $document . ' at ' . Format::money($value, (string) ($largest['currency_code'] ?? $currency ?? 'INR'))
                    . ((string) ($largest['supplier_name_snapshot'] ?? '') === '' ? '.' : ' for ' . (string) $largest['supplier_name_snapshot'] . '.'),
                'basis' => 'The highest document value among pending approval requests.',
                'route' => '/approvals',
                'filters' => ['scope' => 'all_pending', 'sort' => 'value', 'order' => 'desc'],
            ];
        }

        // Two pending orders, same supplier, same total. Not proof of a
        // duplicate — a reason to look at both before approving either.
        $duplicate = Db::first(
            "SELECT p.supplier_name_snapshot, p.total_amount, p.currency_code, COUNT(*) AS documents
             FROM purchase_approval_requests a
             JOIN purchase_orders p ON p.po_id = a.entity_id AND a.entity_type = 'purchase_order'
             WHERE a.cmp_id = :cmp AND a.fy_id = :fy AND a.status = 'PENDING' AND p.total_amount > 0
             GROUP BY p.supplier_account_id, p.supplier_name_snapshot, p.total_amount, p.currency_code
             HAVING COUNT(*) > 1
             ORDER BY COUNT(*) DESC, p.total_amount DESC
             LIMIT 1",
            $binds,
        );

        if ($duplicate !== null) {
            $items[] = [
                'id'    => 'possible_duplicate',
                'tone'  => 'warning',
                'kind'  => 'observation',
                'kind_label' => 'Observed',
                'title' => 'Orders waiting at the same value',
                'detail' => (int) $duplicate['documents'] . ' orders for '
                    . ((string) ($duplicate['supplier_name_snapshot'] ?? 'the same supplier'))
                    . ' are waiting at exactly ' . Format::money(Decimal::of((string) $duplicate['total_amount']), (string) ($duplicate['currency_code'] ?? 'INR'))
                    . '. Worth opening both before approving either.',
                'basis' => 'Pending purchase orders grouped by supplier and total value, where more than one shares a total.',
                'route' => '/approvals',
                'filters' => ['scope' => 'all_pending', 'type' => 'purchase_order'],
            ];
        }

        // Where the queue is concentrated. A single supplier holding most of
        // the pending value is a fact worth stating before it is approved.
        if ($currency !== null) {
            $top = Db::first(
                "SELECT p.supplier_name_snapshot, SUM(p.total_amount) AS value,
                        SUM(SUM(p.total_amount)) OVER () AS whole
                 FROM purchase_approval_requests a
                 JOIN purchase_orders p ON p.po_id = a.entity_id AND a.entity_type = 'purchase_order'
                 WHERE a.cmp_id = :cmp AND a.fy_id = :fy AND a.status = 'PENDING'
                 GROUP BY p.supplier_account_id, p.supplier_name_snapshot
                 ORDER BY value DESC
                 LIMIT 1",
                $binds,
            );

            $whole = $top === null ? null : Decimal::of((string) $top['whole']);
            $share = $top === null || $whole === null || Decimal::isZero($whole)
                ? null
                : Decimal::percentOf(Decimal::of((string) $top['value']), $whole, 1);

            if ($share !== null && Decimal::cmp($share, '60') >= 0) {
                $items[] = [
                    'id'    => 'concentration',
                    'tone'  => 'warning',
                    'kind'  => 'observation',
                    'kind_label' => 'Observed',
                    'title' => 'One supplier holds most of the queue',
                    'detail' => ((string) ($top['supplier_name_snapshot'] ?? 'One supplier')) . ' accounts for '
                        . Format::percent($share, 1) . ' of the value waiting for approval.',
                    'basis' => 'Pending purchase order value grouped by supplier, as a share of all pending order value.',
                    'route' => '/approvals',
                    'filters' => ['scope' => 'all_pending', 'type' => 'purchase_order'],
                ];
            }
        }

        // Are decisions getting slower? Only where both periods have one.
        if ($decided['avg_days'] !== null && $previous['avg_days'] !== null) {
            $change = Decimal::sub($decided['avg_days'], $previous['avg_days']);
            if (!Decimal::isZero($change) && Decimal::cmp(Decimal::isNegative($change) ? Decimal::negate($change) : $change, '0.5') >= 0) {
                $slower = !Decimal::isNegative($change);
                $items[] = [
                    'id'    => 'decision_speed',
                    'tone'  => $slower ? 'warning' : 'success',
                    'kind'  => 'observation',
                    'kind_label' => 'Observed',
                    'title' => $slower ? 'Decisions are taking longer' : 'Decisions are getting quicker',
                    'detail' => Format::quantity(Decimal::isNegative($change) ? Decimal::negate($change) : $change, 'days')
                        . ($slower ? ' longer' : ' quicker') . ' on average than ' . $comparisonLabel . '.',
                    'basis' => 'Average time from an approval request being raised to being decided, this period against the one before.',
                    'route' => '/approvals',
                    'filters' => ['scope' => 'actioned'],
                ];
            }
        }

        return [
            'available'    => true,
            'rows'         => $items,
            'method_label' => 'Derived from the approval records themselves by the rules stated on each row. No model writes these.',
        ];
    }

    private static function plural(int $count, string $one, string $many): string
    {
        return $count . ' ' . ($count === 1 ? $one : $many);
    }

    /** The one currency every pending document shares, or null where they do not. */
    private static function documentCurrency(int $cmpId, int $fyId): ?string
    {
        $codes = Db::all(
            "SELECT DISTINCT p.currency_code
             FROM purchase_approval_requests a
             JOIN purchase_orders p ON p.po_id = a.entity_id AND a.entity_type = 'purchase_order'
             WHERE a.cmp_id = :cmp AND a.fy_id = :fy AND a.status = 'PENDING'
             LIMIT 5",
            ['cmp' => $cmpId, 'fy' => $fyId],
        );

        if ($codes === []) {
            return 'INR';
        }
        if (count($codes) > 1) {
            return null;
        }

        return (string) ($codes[0]['currency_code'] ?? 'INR');
    }

    /** @return array<string, mixed> */
    private static function settings(int $cmpId): array
    {
        return Db::first('SELECT * FROM purchase_settings WHERE cmp_id = :cmp', ['cmp' => $cmpId]) ?? [];
    }
}
