<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain;

use Aicountly\Api\Audit;
use Aicountly\Api\Auth;
use Aicountly\Api\Clients\InventoryClient;
use Aicountly\Api\Context;
use Aicountly\Api\Db;
use Aicountly\Api\Http;
use Aicountly\Api\Permissions;

/**
 * Purchase requisitions — somebody needs something.
 *
 * The estimated rate on a line is what the requester expects it to cost, used
 * only to route the approval. It is not a price (that is agreed with a supplier
 * later) and not a valuation (that is Inventory's), and nothing downstream reads
 * it as either.
 */
final class RequisitionService
{
    public function __construct(
        private readonly Context $ctx,
        private readonly Auth $auth,
    ) {
    }

    /** @param array<string, mixed> $input */
    public function create(array $input): array
    {
        Permissions::assert($this->ctx, $this->auth, 'requisition.create');

        $lines = $this->normaliseLines($input['lines'] ?? []);
        if ($lines === []) {
            Http::validationFailed('A requisition needs at least one line.', ['field' => 'lines']);
        }

        return Db::transaction(function () use ($input, $lines) {
            $no = NumberSeries::next($this->ctx, 'requisition');
            $estimated = array_sum(array_map(
                static fn (array $line) => (float) $line['required_qty'] * (float) $line['estimated_rate'],
                $lines,
            ));

            $flags = [];
            foreach (['emergency', 'single_source', 'non_preferred_vendor'] as $flag) {
                if (!empty($input[$flag])) {
                    $flags[] = $flag;
                }
            }

            $requisitionId = (int) Db::insert('purchase_requisitions', [
                'cmp_id'          => $this->ctx->cmpId,
                'fy_id'           => $this->ctx->fyId,
                'bo_id'           => $this->ctx->boId,
                'requisition_no'  => $no,
                'requisition_date' => self::date($input['requisition_date'] ?? null),
                'status'          => 'DRAFT',
                'requester_uuid'  => $this->auth->uuid,
                'department'      => self::text($input['department'] ?? null),
                'cost_centre_id'  => self::id($input['cost_centre_id'] ?? null),
                'project_id'      => self::id($input['project_id'] ?? null),
                'required_by'     => self::text($input['required_by'] ?? null),
                'priority'        => self::text($input['priority'] ?? null) ?? 'normal',
                'justification'   => self::text($input['justification'] ?? null),
                'exception_flags' => $flags,
                'estimated_value' => round($estimated, 4),
            ], 'requisition_id');

            foreach ($lines as $line) {
                Db::insert('purchase_requisition_lines', [
                    'requisition_id' => $requisitionId,
                    'cmp_id'         => $this->ctx->cmpId,
                    'line_no'        => $line['line_no'],
                    'item_id'        => $line['item_id'],
                    'unit_id'        => $line['unit_id'],
                    'warehouse_id'   => $line['warehouse_id'],
                    'is_service'     => $line['is_service'],
                    'description'    => $line['description'],
                    'required_qty'   => $line['required_qty'],
                    'estimated_rate' => $line['estimated_rate'],
                    'required_by'    => $line['required_by'],
                    'notes'          => $line['notes'],
                ], 'line_id');
            }

            Audit::record($this->ctx, $this->auth, 'requisition.created', 'requisition', $requisitionId, null, [
                'requisition_no'  => $no,
                'estimated_value' => $estimated,
            ]);

            return $this->find($requisitionId);
        });
    }

    /** Submit for approval, raising the approval stages the rules call for. */
    public function submit(int $requisitionId): array
    {
        Permissions::assert($this->ctx, $this->auth, 'requisition.create');

        $requisition = $this->find($requisitionId);
        if ($requisition === []) {
            Http::notFound('That requisition does not exist.');
        }
        if ($requisition['status'] !== 'DRAFT') {
            Http::conflict('This requisition has already been submitted.');
        }

        $threshold = (float) (Db::scalar(
            'SELECT requisition_approval_above_amount FROM purchase_settings WHERE cmp_id = :cmp',
            ['cmp' => $this->ctx->cmpId],
        ) ?? 0);

        $value = (float) $requisition['estimated_value'];
        $flags = Db::jsonColumn($requisition['exception_flags']);
        $needsApproval = ($threshold > 0 && $value > $threshold) || $flags !== [];

        Db::transaction(function () use ($requisitionId, $needsApproval, $value, $threshold, $flags) {
            Db::update('purchase_requisitions', [
                'status'     => $needsApproval ? 'APPROVAL_PENDING' : 'APPROVED',
                'approved_by' => $needsApproval ? null : $this->auth->uuid,
                'approved_at' => $needsApproval ? null : self::now(),
                'updated_at' => self::now(),
            ], ['requisition_id' => $requisitionId, 'cmp_id' => $this->ctx->cmpId]);

            if (!$needsApproval) {
                return;
            }

            if ($threshold > 0 && $value > $threshold) {
                Db::insert('purchase_approval_requests', [
                    'cmp_id'              => $this->ctx->cmpId,
                    'fy_id'               => $this->ctx->fyId,
                    'entity_type'         => 'requisition',
                    'entity_id'           => $requisitionId,
                    'required_permission' => 'requisition.approve',
                    'reason_kind'         => 'value',
                    'reason_detail'       => sprintf('Estimated at %s, above the %s approval threshold.', self::money($value), self::money($threshold)),
                    'threshold_value'     => $threshold,
                    'actual_value'        => $value,
                    'requested_by'        => $this->auth->uuid,
                ], 'approval_id');
            }

            foreach ($flags as $flag) {
                Db::insert('purchase_approval_requests', [
                    'cmp_id'              => $this->ctx->cmpId,
                    'fy_id'               => $this->ctx->fyId,
                    'entity_type'         => 'requisition',
                    'entity_id'           => $requisitionId,
                    'required_permission' => 'requisition.approve',
                    'reason_kind'         => (string) $flag,
                    'reason_detail'       => self::describeFlag((string) $flag),
                    'requested_by'        => $this->auth->uuid,
                ], 'approval_id');
            }
        });

        Audit::record($this->ctx, $this->auth, 'requisition.submitted', 'requisition', $requisitionId, ['status' => 'DRAFT'], [
            'status' => $needsApproval ? 'APPROVAL_PENDING' : 'APPROVED',
        ]);

        return $this->find($requisitionId);
    }

    /** @param array<string, mixed> $input */
    public function decide(int $requisitionId, string $action, array $input): array
    {
        Permissions::assert($this->ctx, $this->auth, 'requisition.approve');

        $requisition = $this->find($requisitionId);
        if ($requisition === []) {
            Http::notFound('That requisition does not exist.');
        }
        if ($requisition['status'] !== 'APPROVAL_PENDING') {
            Http::conflict('This requisition is not waiting for approval.');
        }

        // Segregation of duties. The person who raised it may not approve it,
        // however senior they are — that is the point of the control, and a
        // permission that lets you approve does not let you approve your own.
        if ((string) $requisition['requester_uuid'] === $this->auth->uuid && !$this->auth->ownsCompany($this->ctx->cmpId)) {
            Http::forbidden('You raised this requisition, so somebody else has to approve it.');
        }

        $approved = $action === 'approve';
        $note = self::text($input['note'] ?? null);

        if (!$approved && $note === null) {
            Http::validationFailed('Say why this requisition is being rejected.', ['field' => 'note']);
        }

        Db::transaction(function () use ($requisitionId, $approved, $note) {
            Db::update('purchase_requisitions', [
                'status'      => $approved ? 'APPROVED' : 'REJECTED',
                'approved_by' => $approved ? $this->auth->uuid : null,
                'approved_at' => $approved ? self::now() : null,
                'updated_at'  => self::now(),
            ], ['requisition_id' => $requisitionId, 'cmp_id' => $this->ctx->cmpId]);

            Db::run(
                "UPDATE purchase_approval_requests
                 SET status = :status, decided_by = :by, decided_at = :at, decision_note = :note
                 WHERE cmp_id = :cmp AND entity_type = 'requisition' AND entity_id = :id AND status = 'PENDING'",
                [
                    'status' => $approved ? 'APPROVED' : 'REJECTED',
                    'by'     => $this->auth->uuid,
                    'at'     => self::now(),
                    'note'   => $note,
                    'cmp'    => $this->ctx->cmpId,
                    'id'     => $requisitionId,
                ],
            );
        });

        Audit::record($this->ctx, $this->auth, 'requisition.' . $action, 'requisition', $requisitionId, ['status' => 'APPROVAL_PENDING'], [
            'status' => $approved ? 'APPROVED' : 'REJECTED',
        ], $note ?? '');

        return $this->find($requisitionId);
    }

    /**
     * Requisition lines that Inventory's own replenishment report suggests.
     *
     * READ from Inventory, turned into a requisition the buyer can edit. Nothing
     * about the suggestion is stored: the reorder point, the safety stock and
     * the suggested quantity are Inventory's and stay there.
     *
     * @return array<string, mixed>
     */
    public function replenishmentSuggestions(array $filters = []): array
    {
        Permissions::assert($this->ctx, $this->auth, 'requisition.create');

        $client = (new InventoryClient())->withSession($this->auth->sesKey());
        $response = $client->replenishment($this->ctx, $filters);

        if (!$response['ok']) {
            Http::error(503, 'upstream_unavailable', 'Could not reach Inventory for replenishment suggestions. Please retry.');
        }

        return [
            'source' => 'inventory',
            'note'   => 'Read from Inventory on this request. Nothing here is stored — edit and save to raise a requisition.',
            'rows'   => $response['body']['data'] ?? [],
        ];
    }

    /** @return array<string, mixed> */
    public function find(int $requisitionId): array
    {
        $row = Db::first(
            'SELECT * FROM purchase_requisitions WHERE requisition_id = :id AND cmp_id = :cmp',
            ['id' => $requisitionId, 'cmp' => $this->ctx->cmpId],
        );
        if ($row === null) {
            return [];
        }

        $row['lines'] = Db::all('SELECT * FROM purchase_requisition_lines WHERE requisition_id = :id ORDER BY line_no', ['id' => $requisitionId]);
        $row['approvals'] = Db::all(
            "SELECT * FROM purchase_approval_requests
             WHERE cmp_id = :cmp AND entity_type = 'requisition' AND entity_id = :id ORDER BY approval_id",
            ['cmp' => $this->ctx->cmpId, 'id' => $requisitionId],
        );

        return $row;
    }

    /**
     * The four buckets the list screen groups by.
     *
     * The table holds nine statuses; a buyer thinks in four. SOURCING, ORDERED
     * and CLOSED all mean "approved, and it has moved on", so they count as
     * approved rather than earning three tabs nobody asked for. CANCELLED
     * belongs to none of them and is counted separately — it appears under All
     * and nowhere else, which is the honest place for it.
     *
     * @var array<string, list<string>>
     */
    private const BUCKETS = [
        'draft'    => ['DRAFT'],
        'pending'  => ['SUBMITTED', 'APPROVAL_PENDING'],
        'approved' => ['APPROVED', 'SOURCING', 'ORDERED', 'CLOSED'],
        'rejected' => ['REJECTED'],
    ];

    /**
     * The WHERE every list and every figure on the list screen is built from.
     *
     * One function so a KPI card and the table beneath it can never disagree
     * about what is being counted: the summary runs this, the search runs this,
     * and the export runs this.
     *
     * `status` takes either a stored status (APPROVAL_PENDING — what the
     * dashboard drill-downs have always linked with) or a bucket name (pending
     * — what the tabs use). Both are supported deliberately; dropping the first
     * would break every existing link into this screen.
     *
     * @param array<string, mixed> $filters
     * @return array{0:string, 1:array<string, mixed>}
     */
    private function listClause(array $filters, bool $withStatus = true): array
    {
        [$scope, $params] = $this->ctx->scopeClause('r');
        $where = [$scope];

        if ($withStatus && !empty($filters['status'])) {
            $status = (string) $filters['status'];
            $bucket = self::BUCKETS[strtolower($status)] ?? null;

            if ($bucket !== null) {
                $names = [];
                foreach ($bucket as $index => $value) {
                    $names[] = ':bucket' . $index;
                    $params['bucket' . $index] = $value;
                }
                $where[] = 'r.status IN (' . implode(', ', $names) . ')';
            } else {
                $where[] = 'r.status = :status';
                $params['status'] = $status;
            }
        }
        if (!empty($filters['requester_uuid'])) {
            $where[] = 'r.requester_uuid = :requester';
            $params['requester'] = (string) $filters['requester_uuid'];
        }
        if (!empty($filters['q'])) {
            // The number, the department and the justification, as before — and
            // now the line descriptions too, because "search items" is what a
            // buyer means when they type a part name into a requisition list.
            $where[] = '(r.requisition_no ILIKE :term OR r.justification ILIKE :term OR r.department ILIKE :term
                        OR EXISTS (SELECT 1 FROM purchase_requisition_lines rl
                                   WHERE rl.requisition_id = r.requisition_id AND rl.description ILIKE :term))';
            $params['term'] = '%' . $filters['q'] . '%';
        }
        if (!empty($filters['department'])) {
            $where[] = 'r.department = :department';
            $params['department'] = (string) $filters['department'];
        }
        if (!empty($filters['priority'])) {
            $where[] = 'r.priority = :priority';
            $params['priority'] = (string) $filters['priority'];
        }
        if (!empty($filters['date_from'])) {
            $where[] = 'r.requisition_date >= :date_from';
            $params['date_from'] = (string) $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $where[] = 'r.requisition_date <= :date_to';
            $params['date_to'] = (string) $filters['date_to'];
        }
        if (!empty($filters['required_by_before'])) {
            $where[] = 'r.required_by IS NOT NULL AND r.required_by <= :required_before';
            $params['required_before'] = (string) $filters['required_by_before'];
        }
        if (isset($filters['min_value']) && $filters['min_value'] !== null && $filters['min_value'] !== '') {
            $where[] = 'r.estimated_value >= :min_value';
            $params['min_value'] = (float) $filters['min_value'];
        }
        if (isset($filters['max_value']) && $filters['max_value'] !== null && $filters['max_value'] !== '') {
            $where[] = 'r.estimated_value <= :max_value';
            $params['max_value'] = (float) $filters['max_value'];
        }
        if (!empty($filters['exception'])) {
            // One of the three routing flags, held as a JSONB array.
            $where[] = 'r.exception_flags @> CAST(:exception AS jsonb)';
            $params['exception'] = json_encode([(string) $filters['exception']]);
        }
        if (!empty($filters['awaiting_approval'])) {
            $where[] = "r.status = 'APPROVAL_PENDING'";
        }

        return [implode(' AND ', $where), $params];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{rows:list<array<string, mixed>>, total:int}
     */
    public function search(array $filters, int $limit, int $offset, string $sort, string $order): array
    {
        [$clause, $params] = $this->listClause($filters);
        $sortColumn = in_array($sort, ['requisition_date', 'requisition_no', 'estimated_value', 'status', 'created_at'], true) ? $sort : 'requisition_date';

        /*
         * The counts the row draws, fetched with the row.
         *
         * A list of 50 requisitions that each needs its line count and its
         * approval progress is 101 queries if the screen asks per row, and the
         * screen genuinely needs both: "5 items" and "2/3 approved" are two of
         * the columns. Lateral joins keep it at one query, and the approval
         * chain comes back as JSON so the progress popover has real stages in
         * it rather than a second round trip per row.
         */
        $rows = Db::all(
            "SELECT r.*,
                    COALESCE(l.line_count, 0) AS line_count,
                    l.first_description,
                    COALESCE(a.stages, 0)   AS approval_stages,
                    COALESCE(a.approved, 0) AS approval_approved,
                    COALESCE(a.rejected, 0) AS approval_rejected,
                    COALESCE(a.pending, 0)  AS approval_pending,
                    a.chain AS approval_chain
             FROM purchase_requisitions r
             LEFT JOIN LATERAL (
                 SELECT COUNT(*) AS line_count,
                        MIN(rl.description) FILTER (WHERE rl.line_no = 1) AS first_description
                 FROM purchase_requisition_lines rl
                 WHERE rl.requisition_id = r.requisition_id
             ) l ON TRUE
             LEFT JOIN LATERAL (
                 SELECT COUNT(*) AS stages,
                        COUNT(*) FILTER (WHERE ar.status = 'APPROVED') AS approved,
                        COUNT(*) FILTER (WHERE ar.status = 'REJECTED') AS rejected,
                        COUNT(*) FILTER (WHERE ar.status = 'PENDING')  AS pending,
                        json_agg(json_build_object(
                            'stage_no', ar.stage_no,
                            'stage_name', ar.stage_name,
                            'reason_kind', ar.reason_kind,
                            'reason_detail', ar.reason_detail,
                            'status', ar.status,
                            'decided_at', ar.decided_at
                        ) ORDER BY ar.stage_no, ar.approval_id) AS chain
                 FROM purchase_approval_requests ar
                 WHERE ar.cmp_id = r.cmp_id AND ar.entity_type = 'requisition' AND ar.entity_id = r.requisition_id
             ) a ON TRUE
             WHERE {$clause}
             ORDER BY r.{$sortColumn} {$order}, r.requisition_id {$order}
             LIMIT {$limit} OFFSET {$offset}",
            $params,
        );

        foreach ($rows as &$row) {
            $row['line_count'] = (int) $row['line_count'];
            $row['approval_stages'] = (int) $row['approval_stages'];
            $row['approval_approved'] = (int) $row['approval_approved'];
            $row['approval_rejected'] = (int) $row['approval_rejected'];
            $row['approval_pending'] = (int) $row['approval_pending'];
            $row['approval_chain'] = Db::jsonColumn($row['approval_chain'] ?? null);
            // Who raised it, answered for the one person this API can answer it
            // for. There is no people directory in this product — see the
            // comment on requesterLabel() below.
            $row['is_mine'] = (string) $row['requester_uuid'] === $this->auth->uuid;
        }
        unset($row);

        return [
            'rows'  => $rows,
            'total' => (int) Db::scalar("SELECT COUNT(*) FROM purchase_requisitions r WHERE {$clause}", $params),
        ];
    }

    /**
     * Every figure the list screen puts above the table, in one call.
     *
     * WHY THIS EXISTS. The KPI cards, the tab counts and the insight banner are
     * statements about the whole company, and the table under them is one page
     * of fifty. Deriving "12 requisitions, ₹12,48,500" from the page on screen
     * gives a number that changes when you turn the page, which is worse than
     * showing nothing. So it is counted here, over the same WHERE the table
     * uses, with the status filter lifted — the tabs are what applies that.
     *
     * Nothing here is stored or rolled up nightly. It is counted on the request
     * that draws it, like every other figure in this product.
     *
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function summary(array $filters): array
    {
        Permissions::assert($this->ctx, $this->auth, 'requisition.view');

        [$clause, $params] = $this->listClause($filters, false);

        $pending  = self::inList(self::BUCKETS['pending']);
        $approved = self::inList(self::BUCKETS['approved']);

        $totals = Db::first(
            "SELECT COUNT(*) AS total,
                    COUNT(*) FILTER (WHERE r.status = 'DRAFT')      AS draft,
                    COUNT(*) FILTER (WHERE r.status IN {$pending})  AS pending,
                    COUNT(*) FILTER (WHERE r.status IN {$approved}) AS approved,
                    COUNT(*) FILTER (WHERE r.status = 'REJECTED')   AS rejected,
                    COUNT(*) FILTER (WHERE r.status = 'CANCELLED')  AS cancelled,
                    COALESCE(SUM(r.estimated_value), 0) AS estimated_value,
                    COALESCE(SUM(r.estimated_value) FILTER (WHERE r.status IN {$pending}), 0) AS pending_value
             FROM purchase_requisitions r WHERE {$clause}",
            $params,
        ) ?? [];

        // Month over month, on the same filters. Calendar months rather than a
        // rolling window because "vs last month" is what the card says, and a
        // card that says one thing and counts another is a card that lies.
        $thisMonth = gmdate('Y-m-01');
        $nextMonth = gmdate('Y-m-01', strtotime($thisMonth . ' +1 month'));
        $lastMonth = gmdate('Y-m-01', strtotime($thisMonth . ' -1 month'));
        $today     = gmdate('Y-m-d');

        $months = Db::first(
            "SELECT
                COUNT(*) FILTER (WHERE r.requisition_date >= :m0 AND r.requisition_date < :m1) AS now_total,
                COUNT(*) FILTER (WHERE r.requisition_date >= :m0 AND r.requisition_date < :m1 AND r.status IN {$pending}) AS now_pending,
                COUNT(*) FILTER (WHERE r.requisition_date >= :m0 AND r.requisition_date < :m1 AND r.status IN {$approved}) AS now_approved,
                COUNT(*) FILTER (WHERE r.requisition_date >= :m0 AND r.requisition_date < :m1 AND r.status = 'REJECTED') AS now_rejected,
                COALESCE(SUM(r.estimated_value) FILTER (WHERE r.requisition_date >= :m0 AND r.requisition_date < :m1), 0) AS now_value,
                COUNT(*) FILTER (WHERE r.requisition_date >= :p0 AND r.requisition_date < :m0) AS prev_total,
                COUNT(*) FILTER (WHERE r.requisition_date >= :p0 AND r.requisition_date < :m0 AND r.status IN {$pending}) AS prev_pending,
                COUNT(*) FILTER (WHERE r.requisition_date >= :p0 AND r.requisition_date < :m0 AND r.status IN {$approved}) AS prev_approved,
                COUNT(*) FILTER (WHERE r.requisition_date >= :p0 AND r.requisition_date < :m0 AND r.status = 'REJECTED') AS prev_rejected,
                COALESCE(SUM(r.estimated_value) FILTER (WHERE r.requisition_date >= :p0 AND r.requisition_date < :m0), 0) AS prev_value,
                COUNT(*) FILTER (WHERE r.requisition_date = :today) AS raised_today,
                COUNT(*) FILTER (WHERE r.requisition_date = :today AND r.status IN {$pending}) AS pending_today
             FROM purchase_requisitions r WHERE {$clause}",
            $params + ['m0' => $thisMonth, 'm1' => $nextMonth, 'p0' => $lastMonth, 'today' => $today],
        ) ?? [];

        // Twelve monthly buckets for the sparklines. Months with nothing in them
        // are filled in here rather than left out, so a flat line reads as a
        // quiet month instead of the chart silently compressing time.
        $seriesFrom = gmdate('Y-m-01', strtotime($thisMonth . ' -11 months'));
        $counted = [];
        foreach (Db::all(
            "SELECT to_char(date_trunc('month', r.requisition_date), 'YYYY-MM') AS bucket,
                    COUNT(*) AS total,
                    COUNT(*) FILTER (WHERE r.status IN {$pending})  AS pending,
                    COUNT(*) FILTER (WHERE r.status IN {$approved}) AS approved,
                    COUNT(*) FILTER (WHERE r.status = 'REJECTED')   AS rejected,
                    COALESCE(SUM(r.estimated_value), 0) AS value
             FROM purchase_requisitions r
             WHERE {$clause} AND r.requisition_date >= :series_from
             GROUP BY 1 ORDER BY 1",
            $params + ['series_from' => $seriesFrom],
        ) as $bucket) {
            $counted[(string) $bucket['bucket']] = $bucket;
        }

        $series = [];
        for ($back = 11; $back >= 0; $back--) {
            $key = gmdate('Y-m', strtotime($thisMonth . ' -' . $back . ' months'));
            $row = $counted[$key] ?? null;
            $series[] = [
                'bucket'   => $key,
                'total'    => (int) ($row['total'] ?? 0),
                'pending'  => (int) ($row['pending'] ?? 0),
                'approved' => (int) ($row['approved'] ?? 0),
                'rejected' => (int) ($row['rejected'] ?? 0),
                'value'    => (float) ($row['value'] ?? 0),
            ];
        }

        // The department list the filter offers. Deliberately NOT narrowed by
        // the department filter itself — a dropdown that drops every option but
        // the chosen one cannot be used to choose a different one.
        [$deptClause, $deptParams] = $this->listClause(array_diff_key($filters, ['department' => null]), false);
        $departments = array_map(
            static fn (array $row) => [
                'department' => (string) $row['department'],
                'total'      => (int) $row['total'],
                'pending'    => (int) $row['pending'],
            ],
            Db::all(
                "SELECT r.department, COUNT(*) AS total, COUNT(*) FILTER (WHERE r.status IN {$pending}) AS pending
                 FROM purchase_requisitions r
                 WHERE {$deptClause} AND r.department IS NOT NULL AND btrim(r.department) <> ''
                 GROUP BY r.department ORDER BY COUNT(*) DESC, r.department",
                $deptParams,
            ),
        );

        return [
            'totals' => [
                'total'           => (int) ($totals['total'] ?? 0),
                'draft'           => (int) ($totals['draft'] ?? 0),
                'pending'         => (int) ($totals['pending'] ?? 0),
                'approved'        => (int) ($totals['approved'] ?? 0),
                'rejected'        => (int) ($totals['rejected'] ?? 0),
                'cancelled'       => (int) ($totals['cancelled'] ?? 0),
                'estimated_value' => (float) ($totals['estimated_value'] ?? 0),
                'pending_value'   => (float) ($totals['pending_value'] ?? 0),
            ],
            'month' => [
                'from'     => $thisMonth,
                'total'    => (int) ($months['now_total'] ?? 0),
                'pending'  => (int) ($months['now_pending'] ?? 0),
                'approved' => (int) ($months['now_approved'] ?? 0),
                'rejected' => (int) ($months['now_rejected'] ?? 0),
                'value'    => (float) ($months['now_value'] ?? 0),
            ],
            'previous_month' => [
                'from'     => $lastMonth,
                'total'    => (int) ($months['prev_total'] ?? 0),
                'pending'  => (int) ($months['prev_pending'] ?? 0),
                'approved' => (int) ($months['prev_approved'] ?? 0),
                'rejected' => (int) ($months['prev_rejected'] ?? 0),
                'value'    => (float) ($months['prev_value'] ?? 0),
            ],
            'today' => [
                'date'    => $today,
                'raised'  => (int) ($months['raised_today'] ?? 0),
                'pending' => (int) ($months['pending_today'] ?? 0),
            ],
            'series'      => $series,
            'departments' => $departments,
            'signals'     => $this->signals($clause, $params),
        ];
    }

    /**
     * The facts an insight can be built from. Facts only — no sentences.
     *
     * Every one of these is a count this screen could act on: something waiting
     * too long, something about to be needed, something raised and then left.
     * The wording is the frontend's, and there is no model behind any of it —
     * an "AI insight" that invents a number is worse than no insight at all.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function signals(string $clause, array $params): array
    {
        $pending = self::inList(self::BUCKETS['pending']);
        $open    = self::inList(['DRAFT', 'SUBMITTED', 'APPROVAL_PENDING']);

        // The company's own approval threshold, which is what makes a
        // requisition "high value" here. Not a number this screen invented.
        $threshold = (float) (Db::scalar(
            'SELECT requisition_approval_above_amount FROM purchase_settings WHERE cmp_id = :cmp',
            ['cmp' => $this->ctx->cmpId],
        ) ?? 0);

        // No threshold configured means there is no such thing as "high value"
        // in this company, so the clause is a constant false rather than a
        // comparison against zero that would flag every requisition raised.
        $highValue = $threshold > 0
            ? 'r.estimated_value > CAST(:threshold AS numeric)'
            : 'FALSE';
        $thresholdParam = $threshold > 0 ? ['threshold' => $threshold] : [];

        $row = Db::first(
            "SELECT
                COUNT(*) FILTER (WHERE r.status IN {$pending} AND CURRENT_DATE - r.requisition_date > 5) AS aged_pending,
                COALESCE(MAX(CURRENT_DATE - r.requisition_date) FILTER (WHERE r.status IN {$pending}), 0) AS oldest_pending_days,
                COUNT(*) FILTER (WHERE r.status IN {$pending} AND {$highValue}) AS high_value_pending,
                COALESCE(SUM(r.estimated_value) FILTER (WHERE r.status IN {$pending} AND {$highValue}), 0) AS high_value_amount,
                COUNT(*) FILTER (WHERE r.status IN {$open} AND r.required_by IS NOT NULL
                                 AND r.required_by BETWEEN CURRENT_DATE AND CURRENT_DATE + 7) AS due_soon,
                COUNT(*) FILTER (WHERE r.status IN {$open} AND r.required_by IS NOT NULL AND r.required_by < CURRENT_DATE) AS overdue,
                COUNT(*) FILTER (WHERE r.status = 'DRAFT' AND CURRENT_DATE - r.requisition_date > 7) AS stalled_drafts,
                COUNT(*) FILTER (WHERE r.status = 'REJECTED' AND CURRENT_DATE - r.requisition_date <= 30) AS rejected_30d,
                COUNT(*) FILTER (WHERE r.status = 'DRAFT' AND (r.department IS NULL OR btrim(r.department) = '')) AS missing_department
             FROM purchase_requisitions r WHERE {$clause}",
            $params + $thresholdParam,
        ) ?? [];

        $busiest = Db::first(
            "SELECT r.department, COUNT(*) AS pending
             FROM purchase_requisitions r
             WHERE {$clause} AND r.status IN {$pending} AND r.department IS NOT NULL AND btrim(r.department) <> ''
             GROUP BY r.department ORDER BY COUNT(*) DESC, r.department LIMIT 1",
            $params,
        );

        return [
            'aged_pending_days'   => 5,
            'aged_pending'        => (int) ($row['aged_pending'] ?? 0),
            'oldest_pending_days' => (int) ($row['oldest_pending_days'] ?? 0),
            'high_value_pending'  => (int) ($row['high_value_pending'] ?? 0),
            'high_value_amount'   => (float) ($row['high_value_amount'] ?? 0),
            'high_value_threshold' => $threshold,
            'due_soon_days'       => 7,
            'due_soon'            => (int) ($row['due_soon'] ?? 0),
            'overdue'             => (int) ($row['overdue'] ?? 0),
            'stalled_drafts'      => (int) ($row['stalled_drafts'] ?? 0),
            'stalled_draft_days'  => 7,
            'rejected_30d'        => (int) ($row['rejected_30d'] ?? 0),
            'missing_department'  => (int) ($row['missing_department'] ?? 0),
            'busiest_department'  => $busiest === null ? null : [
                'department' => (string) $busiest['department'],
                'pending'    => (int) $busiest['pending'],
            ],
        ];
    }

    /** A literal IN (...) list. Every value here is one of this class's own constants. */
    private static function inList(array $statuses): string
    {
        return "('" . implode("', '", array_map(static fn (string $s) => str_replace("'", '', $s), $statuses)) . "')";
    }

    /**
     * The filtered list as CSV rows, header first.
     *
     * The same WHERE the table uses, so an export is what is on screen rather
     * than a second opinion about it. Capped, because an export is a file a
     * browser has to hold in memory and "every requisition this company has
     * ever raised" is not a useful one.
     *
     * @param array<string, mixed> $filters
     * @return list<list<string>>
     */
    public function exportRows(array $filters): array
    {
        Permissions::assert($this->ctx, $this->auth, 'requisition.view');

        $result = $this->search($filters, 5000, 0, 'requisition_date', 'DESC');

        $rows = [['Requisition no', 'Date', 'Status', 'Department', 'Priority', 'Items', 'Required by', 'Estimated value', 'Approvals approved', 'Approval stages', 'Justification']];
        foreach ($result['rows'] as $row) {
            $rows[] = [
                (string) $row['requisition_no'],
                (string) $row['requisition_date'],
                (string) $row['status'],
                (string) ($row['department'] ?? ''),
                (string) ($row['priority'] ?? ''),
                (string) $row['line_count'],
                (string) ($row['required_by'] ?? ''),
                (string) $row['estimated_value'],
                (string) $row['approval_approved'],
                (string) $row['approval_stages'],
                (string) ($row['justification'] ?? ''),
            ];
        }

        return $rows;
    }

    /** @return list<array<string, mixed>> */
    private function normaliseLines(mixed $raw): array
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
            $isService = (bool) ($line['is_service'] ?? false);
            $itemId = self::id($line['item_id'] ?? null);
            if (!$isService && $itemId === null) {
                Http::validationFailed('Every stock line needs an item.', ['field' => 'lines', 'line_no' => $lineNo + 1]);
            }
            $quantity = round((float) ($line['required_qty'] ?? $line['quantity'] ?? 0), 4);
            if ($quantity <= 0) {
                Http::validationFailed('Quantity must be more than zero.', ['field' => 'lines', 'line_no' => $lineNo + 1]);
            }

            $lines[] = [
                'line_no'        => ++$lineNo,
                'item_id'        => $itemId,
                'unit_id'        => self::id($line['unit_id'] ?? null),
                'warehouse_id'   => self::id($line['warehouse_id'] ?? null),
                'is_service'     => $isService,
                'description'    => self::text($line['description'] ?? null),
                'required_qty'   => $quantity,
                'estimated_rate' => round((float) ($line['estimated_rate'] ?? 0), 4),
                'required_by'    => self::text($line['required_by'] ?? null),
                'notes'          => self::text($line['notes'] ?? null),
            ];
        }

        return $lines;
    }

    private static function describeFlag(string $flag): string
    {
        return match ($flag) {
            'emergency'           => 'Raised as an emergency purchase.',
            'single_source'       => 'Single-source procurement — no competing quotes will be sought.',
            'non_preferred_vendor' => 'Names a supplier who is not on the approved list.',
            default               => 'Requires approval.',
        };
    }

    private static function money(float $value): string
    {
        return '₹' . number_format($value, 2);
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
