<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain;

use Aicountly\Api\Ai\AiClient;
use Aicountly\Api\Audit;
use Aicountly\Api\Auth;
use Aicountly\Api\Context;
use Aicountly\Api\Db;
use Aicountly\Api\Http;
use Aicountly\Api\Permissions;

/**
 * RFQ, supplier quotations, comparison and the award decision.
 *
 * All of this is genuinely ours: nobody else records what we asked for, who we
 * asked, what they came back with, or why we chose the supplier we chose. The
 * supplier's identity is Contacts' and their ledger is Books'; we hold the
 * account id and the terms THEY quoted for THIS enquiry.
 */
final class SourcingService
{
    public function __construct(
        private readonly Context $ctx,
        private readonly Auth $auth,
    ) {
    }

    /** @param array<string, mixed> $input */
    public function createRfq(array $input): array
    {
        Permissions::assert($this->ctx, $this->auth, 'rfq.create');

        $lines = $this->normaliseRfqLines($input['lines'] ?? []);
        if ($lines === []) {
            Http::validationFailed('An RFQ needs at least one line.', ['field' => 'lines']);
        }

        return Db::transaction(function () use ($input, $lines) {
            $no = NumberSeries::next($this->ctx, 'rfq');

            $rfqId = (int) Db::insert('purchase_rfqs', [
                'cmp_id'            => $this->ctx->cmpId,
                'fy_id'             => $this->ctx->fyId,
                'bo_id'             => $this->ctx->boId,
                'rfq_no'            => $no,
                'rfq_date'          => self::date($input['rfq_date'] ?? null),
                'title'             => self::text($input['title'] ?? null),
                'status'            => 'DRAFT',
                'response_deadline' => self::text($input['response_deadline'] ?? null),
                'delivery_warehouse_id' => self::id($input['delivery_warehouse_id'] ?? null),
                'required_by'       => self::text($input['required_by'] ?? null),
                'commercial_terms'  => self::text($input['commercial_terms'] ?? null),
                'technical_terms'   => self::text($input['technical_terms'] ?? null),
                'currency_code'     => self::text($input['currency_code'] ?? null) ?? 'INR',
                'created_by'        => $this->auth->uuid,
            ], 'rfq_id');

            foreach ($lines as $line) {
                Db::insert('purchase_rfq_lines', [
                    'rfq_id'              => $rfqId,
                    'cmp_id'              => $this->ctx->cmpId,
                    'line_no'             => $line['line_no'],
                    'requisition_line_id' => $line['requisition_line_id'],
                    'item_id'             => $line['item_id'],
                    'unit_id'             => $line['unit_id'],
                    'is_service'          => $line['is_service'],
                    'description'         => $line['description'],
                    'required_qty'        => $line['required_qty'],
                    'required_by'         => $line['required_by'],
                    'specification'       => $line['specification'],
                ], 'line_id');
            }

            foreach ((array) ($input['supplier_account_ids'] ?? []) as $supplierId) {
                $id = self::id($supplierId);
                if ($id !== null) {
                    $this->inviteSupplier($rfqId, $id);
                }
            }

            // Sourcing has started, so the requisitions behind it are no longer
            // sitting in an approval queue waiting for somebody to act.
            if (($requisitionId = self::id($input['requisition_id'] ?? null)) !== null) {
                Db::update('purchase_requisitions', ['status' => 'SOURCING', 'updated_at' => self::now()], [
                    'requisition_id' => $requisitionId,
                    'cmp_id'         => $this->ctx->cmpId,
                ]);
            }

            Audit::record($this->ctx, $this->auth, 'rfq.created', 'rfq', $rfqId, null, ['rfq_no' => $no]);

            return $this->findRfq($rfqId);
        });
    }

    public function inviteSupplier(int $rfqId, int $supplierAccountId): void
    {
        $existing = Db::first(
            'SELECT invitation_id FROM purchase_rfq_invitations WHERE rfq_id = :rfq AND supplier_account_id = :supplier',
            ['rfq' => $rfqId, 'supplier' => $supplierAccountId],
        );
        if ($existing !== null) {
            return;
        }

        // A portal token is long, random and single-purpose, and only its hash
        // is stored. A guessable link here would expose every competitor's
        // quoted price to whoever tried the next number.
        $token = bin2hex(random_bytes(32));

        Db::insert('purchase_rfq_invitations', [
            'rfq_id'              => $rfqId,
            'cmp_id'              => $this->ctx->cmpId,
            'supplier_account_id' => $supplierAccountId,
            'status'              => 'INVITED',
            'portal_token_hash'   => hash('sha256', $token),
            'token_expires_at'    => gmdate('Y-m-d H:i:s', time() + 30 * 86400),
        ], 'invitation_id');
    }

    public function issueRfq(int $rfqId): array
    {
        Permissions::assert($this->ctx, $this->auth, 'rfq.create');

        $rfq = $this->findRfq($rfqId);
        if ($rfq === []) {
            Http::notFound('That RFQ does not exist.');
        }
        if ($rfq['status'] !== 'DRAFT') {
            Http::conflict('This RFQ has already been issued.');
        }
        if ($rfq['invitations'] === []) {
            Http::validationFailed('Invite at least one supplier before issuing the RFQ.');
        }

        Db::update('purchase_rfqs', ['status' => 'ISSUED', 'updated_at' => self::now()], ['rfq_id' => $rfqId, 'cmp_id' => $this->ctx->cmpId]);
        Audit::record($this->ctx, $this->auth, 'rfq.issued', 'rfq', $rfqId, ['status' => 'DRAFT'], ['status' => 'ISSUED']);

        return $this->findRfq($rfqId);
    }

    /**
     * Record a supplier's quotation.
     *
     * A revision is a new row, not an edit: the previous offer is what we
     * compared against at the time, and overwriting it destroys the record of a
     * negotiation.
     *
     * @param array<string, mixed> $input
     */
    public function recordQuote(int $rfqId, array $input): array
    {
        Permissions::assert($this->ctx, $this->auth, 'quote.enter');

        $rfq = $this->findRfq($rfqId);
        if ($rfq === []) {
            Http::notFound('That RFQ does not exist.');
        }
        if (in_array($rfq['status'], ['AWARDED', 'CANCELLED', 'CLOSED'], true)) {
            Http::conflict('This RFQ is closed to new quotations.');
        }

        $supplierId = (int) ($input['supplier_account_id'] ?? 0);
        if ($supplierId <= 0) {
            Http::validationFailed('Say which supplier this quotation is from.', ['field' => 'supplier_account_id']);
        }

        $lines = $this->normaliseQuoteLines($input['lines'] ?? []);
        if ($lines === []) {
            Http::validationFailed('A quotation needs at least one line.', ['field' => 'lines']);
        }

        return Db::transaction(function () use ($rfqId, $supplierId, $input, $lines) {
            $previous = (int) (Db::scalar(
                'SELECT COALESCE(MAX(revision_no), -1) FROM purchase_quotes WHERE rfq_id = :rfq AND supplier_account_id = :supplier',
                ['rfq' => $rfqId, 'supplier' => $supplierId],
            ) ?? -1);

            $quoteId = (int) Db::insert('purchase_quotes', [
                'cmp_id'              => $this->ctx->cmpId,
                'rfq_id'              => $rfqId,
                'supplier_account_id' => $supplierId,
                'quote_ref'           => self::text($input['quote_ref'] ?? null),
                'quote_date'          => self::text($input['quote_date'] ?? null) ?? gmdate('Y-m-d'),
                'revision_no'         => $previous + 1,
                'status'              => 'RECEIVED',
                'currency_code'       => self::text($input['currency_code'] ?? null) ?? 'INR',
                'exchange_rate'       => (float) ($input['exchange_rate'] ?? 1),
                'payment_terms'       => self::text($input['payment_terms'] ?? null),
                'delivery_days'       => self::id($input['delivery_days'] ?? null),
                'warranty_terms'      => self::text($input['warranty_terms'] ?? null),
                'freight_amount'      => round((float) ($input['freight_amount'] ?? 0), 4),
                'other_charges'       => round((float) ($input['other_charges'] ?? 0), 4),
                'valid_until'         => self::text($input['valid_until'] ?? null),
                'notes'               => self::text($input['notes'] ?? null),
            ], 'quote_id');

            foreach ($lines as $line) {
                Db::insert('purchase_quote_lines', [
                    'quote_id'         => $quoteId,
                    'rfq_line_id'      => $line['rfq_line_id'],
                    'cmp_id'           => $this->ctx->cmpId,
                    'line_no'          => $line['line_no'],
                    'item_id'          => $line['item_id'],
                    'unit_id'          => $line['unit_id'],
                    'quoted_qty'       => $line['quoted_qty'],
                    'quoted_rate'      => $line['quoted_rate'],
                    'discount_pc'      => $line['discount_pc'],
                    'estimated_tax_pc' => $line['estimated_tax_pc'],
                    'moq'              => $line['moq'],
                    'lead_days'        => $line['lead_days'],
                    'line_amount'      => $line['line_amount'],
                    'remarks'          => $line['remarks'],
                ], 'line_id');
            }

            Db::update('purchase_rfq_invitations', ['status' => 'RESPONDED', 'responded_at' => self::now()], [
                'rfq_id' => $rfqId, 'supplier_account_id' => $supplierId,
            ]);
            Db::update('purchase_rfqs', ['status' => 'RESPONSES_OPEN', 'updated_at' => self::now()], ['rfq_id' => $rfqId, 'cmp_id' => $this->ctx->cmpId]);

            Audit::record($this->ctx, $this->auth, 'quote.recorded', 'rfq', $rfqId, null, [
                'quote_id' => $quoteId, 'supplier_account_id' => $supplierId, 'revision_no' => $previous + 1,
            ]);

            return $this->findRfq($rfqId);
        });
    }

    /**
     * The comparative statement.
     *
     * Estimated landed cost is what makes this useful: the cheapest unit rate is
     * routinely not the cheapest purchase once freight and duty are in, and a
     * comparison that hides that is a comparison that picks the wrong supplier.
     *
     * @return array<string, mixed>
     */
    public function compare(int $rfqId): array
    {
        Permissions::assert($this->ctx, $this->auth, 'rfq.view');

        $rfq = $this->findRfq($rfqId);
        if ($rfq === []) {
            Http::notFound('That RFQ does not exist.');
        }

        // Only the latest revision from each supplier: comparing an old offer
        // against a new one is how a negotiated price gets overlooked.
        $quotes = Db::all(
            'SELECT DISTINCT ON (supplier_account_id) *
             FROM purchase_quotes
             WHERE rfq_id = :rfq AND cmp_id = :cmp AND status <> :withdrawn
             ORDER BY supplier_account_id, revision_no DESC',
            ['rfq' => $rfqId, 'cmp' => $this->ctx->cmpId, 'withdrawn' => 'WITHDRAWN'],
        );

        $columns = [];
        foreach ($quotes as $quote) {
            $lines = Db::all('SELECT * FROM purchase_quote_lines WHERE quote_id = :id ORDER BY line_no', ['id' => (int) $quote['quote_id']]);
            $lineTotal = array_sum(array_map(static fn (array $l) => (float) $l['line_amount'], $lines));
            $extras = (float) $quote['freight_amount'] + (float) $quote['other_charges'];

            $columns[] = [
                'quote_id'            => (int) $quote['quote_id'],
                'supplier_account_id' => (int) $quote['supplier_account_id'],
                'revision_no'         => (int) $quote['revision_no'],
                'currency_code'       => $quote['currency_code'],
                'payment_terms'       => $quote['payment_terms'],
                'delivery_days'       => $quote['delivery_days'],
                'warranty_terms'      => $quote['warranty_terms'],
                'valid_until'         => $quote['valid_until'],
                'line_total'          => round($lineTotal, 4),
                'freight_amount'      => (float) $quote['freight_amount'],
                'other_charges'       => (float) $quote['other_charges'],
                // The number that should decide it.
                'estimated_landed_total' => round($lineTotal + $extras, 4),
                'lines' => array_map(static fn (array $l) => [
                    'rfq_line_id' => $l['rfq_line_id'] === null ? null : (int) $l['rfq_line_id'],
                    'item_id'     => $l['item_id'] === null ? null : (int) $l['item_id'],
                    'quoted_qty'  => (float) $l['quoted_qty'],
                    'quoted_rate' => (float) $l['quoted_rate'],
                    'discount_pc' => (float) $l['discount_pc'],
                    'line_amount' => (float) $l['line_amount'],
                    'moq'         => $l['moq'] === null ? null : (float) $l['moq'],
                    'lead_days'   => $l['lead_days'] === null ? null : (int) $l['lead_days'],
                ], $lines),
            ];
        }

        // Cheapest per line, so a split award has something to work from.
        $bestByLine = [];
        foreach ($columns as $column) {
            foreach ($column['lines'] as $line) {
                $rfqLineId = $line['rfq_line_id'];
                if ($rfqLineId === null || (float) $line['quoted_rate'] <= 0) {
                    continue;
                }
                $current = $bestByLine[$rfqLineId] ?? null;
                if ($current === null || $line['quoted_rate'] < $current['quoted_rate']) {
                    $bestByLine[$rfqLineId] = [
                        'quote_id'            => $column['quote_id'],
                        'supplier_account_id' => $column['supplier_account_id'],
                        'quoted_rate'         => $line['quoted_rate'],
                    ];
                }
            }
        }

        return [
            'rfq'            => $rfq,
            'quotes'         => $columns,
            'best_by_line'   => $bestByLine,
            'lowest_landed'  => $columns === [] ? null : min(array_column($columns, 'estimated_landed_total')),
            'note' => 'Estimated landed total is the quoted lines plus freight and other charges. The tax actually charged is computed by Smart Books when the bill arrives.',
        ];
    }

    /**
     * Award the RFQ, whole or line by line.
     *
     * @param array<string, mixed> $input {awards: [{rfq_line_id?, quote_id, qty, rate, rationale}]}
     */
    public function award(int $rfqId, array $input): array
    {
        Permissions::assert($this->ctx, $this->auth, 'rfq.award');

        $rfq = $this->findRfq($rfqId);
        if ($rfq === []) {
            Http::notFound('That RFQ does not exist.');
        }
        if ($rfq['status'] === 'AWARDED') {
            Http::conflict('This RFQ has already been awarded.');
        }

        $awards = $input['awards'] ?? [];
        if (!is_array($awards) || $awards === []) {
            Http::validationFailed('Say which quotation wins, and for what.', ['field' => 'awards']);
        }

        return Db::transaction(function () use ($rfqId, $awards) {
            $awardedQuoteIds = [];

            foreach ($awards as $award) {
                if (!is_array($award)) {
                    continue;
                }
                $quoteId = (int) ($award['quote_id'] ?? 0);
                if ($quoteId <= 0) {
                    continue;
                }
                $quote = Db::first(
                    'SELECT quote_id FROM purchase_quotes WHERE quote_id = :id AND rfq_id = :rfq AND cmp_id = :cmp',
                    ['id' => $quoteId, 'rfq' => $rfqId, 'cmp' => $this->ctx->cmpId],
                );
                if ($quote === null) {
                    Http::validationFailed('One of the awarded quotations does not belong to this RFQ.', ['quote_id' => $quoteId]);
                }

                Db::insert('purchase_bid_awards', [
                    'cmp_id'       => $this->ctx->cmpId,
                    'rfq_id'       => $rfqId,
                    'rfq_line_id'  => self::id($award['rfq_line_id'] ?? null),
                    'quote_id'     => $quoteId,
                    'awarded_qty'  => round((float) ($award['qty'] ?? 0), 4),
                    'awarded_rate' => round((float) ($award['rate'] ?? 0), 4),
                    // Why this supplier and not the cheapest — the single most
                    // useful thing an auditor can read six months later.
                    'rationale'    => self::text($award['rationale'] ?? null),
                    'decided_by'   => $this->auth->uuid,
                ], 'award_id');

                $awardedQuoteIds[$quoteId] = true;
            }

            foreach (array_keys($awardedQuoteIds) as $quoteId) {
                Db::update('purchase_quotes', ['status' => 'AWARDED', 'updated_at' => self::now()], ['quote_id' => $quoteId]);
            }
            Db::run(
                "UPDATE purchase_quotes SET status = 'REJECTED', updated_at = :now
                 WHERE rfq_id = :rfq AND cmp_id = :cmp AND status NOT IN ('AWARDED', 'WITHDRAWN')",
                ['now' => self::now(), 'rfq' => $rfqId, 'cmp' => $this->ctx->cmpId],
            );
            Db::update('purchase_rfqs', ['status' => 'AWARDED', 'updated_at' => self::now()], ['rfq_id' => $rfqId, 'cmp_id' => $this->ctx->cmpId]);

            Audit::record($this->ctx, $this->auth, 'rfq.awarded', 'rfq', $rfqId, ['status' => 'RESPONSES_OPEN'], [
                'status' => 'AWARDED', 'awarded_quotes' => array_keys($awardedQuoteIds),
            ]);

            return $this->findRfq($rfqId);
        });
    }

    /** @return array<string, mixed> */
    public function findRfq(int $rfqId): array
    {
        $row = Db::first('SELECT * FROM purchase_rfqs WHERE rfq_id = :id AND cmp_id = :cmp', ['id' => $rfqId, 'cmp' => $this->ctx->cmpId]);
        if ($row === null) {
            return [];
        }

        $row['lines'] = Db::all('SELECT * FROM purchase_rfq_lines WHERE rfq_id = :id ORDER BY line_no', ['id' => $rfqId]);
        // The portal token hash is never returned: it is a credential, and a
        // credential in an API response is a credential in a browser cache.
        $row['invitations'] = Db::all(
            'SELECT invitation_id, supplier_account_id, status, invited_at, responded_at, token_expires_at
             FROM purchase_rfq_invitations WHERE rfq_id = :id ORDER BY invitation_id',
            ['id' => $rfqId],
        );
        $row['quotes'] = Db::all(
            'SELECT * FROM purchase_quotes WHERE rfq_id = :id ORDER BY supplier_account_id, revision_no DESC',
            ['id' => $rfqId],
        );
        $row['awards'] = Db::all('SELECT * FROM purchase_bid_awards WHERE rfq_id = :id ORDER BY award_id', ['id' => $rfqId]);

        return $row;
    }

    /**
     * The sourcing list, with the three numbers the list is actually read for.
     *
     * A buyer scanning this screen is asking "who did we ask, who answered, and
     * what did they say" — so invitations, responses and quotations are counted
     * in the same query rather than left to a call per row. The counts are
     * derived, never stored: a second copy of "how many quotes" is a second
     * number to disagree with the quotes themselves.
     *
     * Only the LATEST non-withdrawn revision from each supplier counts as a
     * quotation, which is the same rule compare() uses. Counting revisions
     * would say four quotes arrived when one supplier priced twice.
     *
     * @param array<string, mixed> $filters
     * @return array{rows:list<array<string, mixed>>, total:int, status_counts:array<string,int>}
     */
    public function searchRfqs(array $filters, int $limit, int $offset, string $sort, string $order): array
    {
        [$scope, $params] = $this->ctx->scopeClause('r');
        $where = [$scope];

        // Everything except the status itself, so the lifecycle tabs can show
        // how many RFQs each status holds UNDER the current search and dates.
        // Counting them without the other filters would offer the user a tab
        // that lands on an empty table.
        $unfiltered = $where;
        $unfilteredParams = $params;

        if (!empty($filters['status'])) {
            $where[] = 'r.status = :status';
            $params['status'] = (string) $filters['status'];
        }

        foreach (self::listConditions($filters) as [$sql, $bindings]) {
            $where[] = $sql;
            $unfiltered[] = $sql;
            $params += $bindings;
            $unfilteredParams += $bindings;
        }

        $clause = implode(' AND ', $where);
        $sortColumn = in_array($sort, ['rfq_date', 'rfq_no', 'status', 'response_deadline', 'created_at'], true) ? $sort : 'rfq_date';

        $rows = Db::all(
            "SELECT r.*,
                    (SELECT COUNT(*) FROM purchase_rfq_invitations i WHERE i.rfq_id = r.rfq_id)                    AS invited_count,
                    (SELECT COUNT(*) FROM purchase_rfq_invitations i
                      WHERE i.rfq_id = r.rfq_id AND i.status = 'RESPONDED')                                        AS responded_count,
                    (SELECT COUNT(DISTINCT q.supplier_account_id) FROM purchase_quotes q
                      WHERE q.rfq_id = r.rfq_id AND q.status <> 'WITHDRAWN')                                       AS quote_count,
                    (SELECT COUNT(*) FROM purchase_rfq_lines l WHERE l.rfq_id = r.rfq_id)                          AS line_count,
                    (SELECT COUNT(*) FROM purchase_bid_awards a WHERE a.rfq_id = r.rfq_id)                         AS award_count,
                    (SELECT string_agg(head.label, ', ' ORDER BY head.line_no)
                       FROM (SELECT l.line_no,
                                    COALESCE(
                                        NULLIF(btrim(l.description), ''),
                                        CASE WHEN l.item_id IS NOT NULL
                                             THEN 'Inventory item ' || l.item_id
                                             ELSE 'Line ' || l.line_no END
                                    ) AS label
                               FROM purchase_rfq_lines l
                              WHERE l.rfq_id = r.rfq_id
                              ORDER BY l.line_no
                              LIMIT 3) head)                                                                       AS item_summary
               FROM purchase_rfqs r
              WHERE {$clause}
              ORDER BY r.{$sortColumn} {$order}, r.rfq_id {$order}
              LIMIT {$limit} OFFSET {$offset}",
            $params,
        );

        $statusCounts = [];
        foreach (Db::all(
            'SELECT r.status, COUNT(*) AS n FROM purchase_rfqs r WHERE ' . implode(' AND ', $unfiltered) . ' GROUP BY r.status',
            $unfilteredParams,
        ) as $row) {
            $statusCounts[(string) $row['status']] = (int) $row['n'];
        }

        return [
            'rows'          => $rows,
            'total'         => (int) Db::scalar("SELECT COUNT(*) FROM purchase_rfqs r WHERE {$clause}", $params),
            'status_counts' => $statusCounts,
        ];
    }

    /**
     * The figures above the sourcing list.
     *
     * Every one of them is counted from the records themselves at read time.
     * There is no metrics table and no nightly roll-up, for the same reason the
     * dashboards have none: a stored count is a second answer to a question the
     * rows already answer, and it is wrong from the first write that misses it.
     *
     * WHERE A FIGURE CANNOT BE PRODUCED IT IS NULL, NOT ZERO. A month with
     * nothing to compare against does not get a percentage, and a user without
     * `cost.view` does not get quoted values — in both cases the screen says so
     * rather than drawing a confident nought.
     *
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        Permissions::assert($this->ctx, $this->auth, 'rfq.view');

        [$scope, $params] = $this->ctx->scopeClause('r');

        $monthStart = gmdate('Y-m-01');
        $previousStart = gmdate('Y-m-01', strtotime($monthStart . ' -1 month'));
        $months = ['month_start' => $monthStart, 'previous_start' => $previousStart];

        $pipeline = Db::first(
            "SELECT COUNT(*)                                                             AS total,
                    COUNT(*) FILTER (WHERE r.status = 'DRAFT')                           AS draft,
                    COUNT(*) FILTER (WHERE r.status IN ('ISSUED', 'RESPONSES_OPEN'))     AS open,
                    COUNT(*) FILTER (WHERE r.status = 'EVALUATING')                      AS evaluating,
                    COUNT(*) FILTER (WHERE r.status = 'AWARDED')                         AS awarded,
                    COUNT(*) FILTER (WHERE r.status = 'CLOSED')                          AS closed,
                    COUNT(*) FILTER (WHERE r.status = 'CANCELLED')                       AS cancelled,
                    COUNT(*) FILTER (WHERE r.rfq_date >= :month_start)                   AS raised_this_month,
                    COUNT(*) FILTER (WHERE r.rfq_date >= :previous_start
                                       AND r.rfq_date <  :month_start)                   AS raised_last_month,
                    COUNT(*) FILTER (WHERE r.response_deadline IS NOT NULL
                                       AND r.response_deadline < NOW()
                                       AND r.status IN ('ISSUED', 'RESPONSES_OPEN'))     AS overdue,
                    COUNT(*) FILTER (
                        WHERE EXISTS (SELECT 1 FROM purchase_quotes q
                                       WHERE q.rfq_id = r.rfq_id AND q.status <> 'WITHDRAWN')
                    )                                                                    AS quoted,
                    COUNT(*) FILTER (
                        WHERE r.status IN ('ISSUED', 'RESPONSES_OPEN')
                          AND NOT EXISTS (SELECT 1 FROM purchase_quotes q
                                           WHERE q.rfq_id = r.rfq_id AND q.status <> 'WITHDRAWN')
                    )                                                                    AS awaiting_response
               FROM purchase_rfqs r
              WHERE {$scope}",
            $params + $months,
        ) ?? [];

        // Awarded THIS MONTH is the decision date, not the RFQ's own date: an
        // enquiry raised in March and decided in April was decided in April.
        $awardedThisMonth = (int) (Db::scalar(
            "SELECT COUNT(DISTINCT a.rfq_id)
               FROM purchase_bid_awards a
               JOIN purchase_rfqs r ON r.rfq_id = a.rfq_id
              WHERE {$scope} AND a.decided_at >= :month_start",
            $params + ['month_start' => $monthStart],
        ) ?? 0);

        $counts = [
            'total'              => (int) ($pipeline['total'] ?? 0),
            'draft'              => (int) ($pipeline['draft'] ?? 0),
            'open'               => (int) ($pipeline['open'] ?? 0),
            'quoted'             => (int) ($pipeline['quoted'] ?? 0),
            'evaluating'         => (int) ($pipeline['evaluating'] ?? 0),
            'awarded'            => (int) ($pipeline['awarded'] ?? 0),
            'awarded_this_month' => $awardedThisMonth,
            'closed'             => (int) ($pipeline['closed'] ?? 0),
            'cancelled'          => (int) ($pipeline['cancelled'] ?? 0),
            'awaiting_response'  => (int) ($pipeline['awaiting_response'] ?? 0),
            'overdue'            => (int) ($pipeline['overdue'] ?? 0),
            'raised_this_month'  => (int) ($pipeline['raised_this_month'] ?? 0),
            'raised_last_month'  => (int) ($pipeline['raised_last_month'] ?? 0),
        ];

        return [
            'counts'         => $counts,
            // Quoted money is spend, and spend is `cost.view`. Without it the
            // cards keep their place and say what is missing.
            'values_visible' => Permissions::allows($this->ctx, $this->auth, 'cost.view'),
            'values'         => Permissions::allows($this->ctx, $this->auth, 'cost.view')
                ? $this->quoteValues($scope, $params, $months)
                : null,
            // Whether a model is configured at all. The screen offers the same
            // actions either way; it just does not call something "AI" when the
            // answer behind it came from the rules engine.
            'ai'             => ['available' => AiClient::isConfigured()],
        ];
    }

    /**
     * What the quotations on file are worth, and the spread between them.
     *
     * ESTIMATED LANDED COST, not the line total — the same figure compare()
     * puts in front of a buyer, because freight and other charges routinely
     * decide which quotation is actually cheapest.
     *
     * ONE CURRENCY. Quotations are priced in the currency the supplier quoted,
     * and an average across currencies is a number with no meaning. The
     * currency most quotations are in wins, the rest are counted and reported
     * so the screen can say they were left out.
     *
     * @param array<string, mixed> $params
     * @param array{month_start:string, previous_start:string} $months
     * @return array<string, mixed>
     */
    private function quoteValues(string $scope, array $params, array $months): array
    {
        // The latest non-withdrawn revision from each supplier, which is the
        // rule the comparative statement uses. Counting revisions would report
        // four quotations when one supplier priced the same enquiry twice.
        $live = "WITH live AS (
                     SELECT DISTINCT ON (q.rfq_id, q.supplier_account_id)
                            q.quote_id, q.rfq_id, q.currency_code, q.created_at,
                            q.freight_amount, q.other_charges, r.status
                       FROM purchase_quotes q
                       JOIN purchase_rfqs r ON r.rfq_id = q.rfq_id
                      WHERE {$scope} AND q.status <> 'WITHDRAWN'
                      ORDER BY q.rfq_id, q.supplier_account_id, q.revision_no DESC
                 ), landed AS (
                     SELECT live.rfq_id, live.currency_code, live.created_at, live.status,
                            COALESCE((SELECT SUM(ql.line_amount) FROM purchase_quote_lines ql
                                       WHERE ql.quote_id = live.quote_id), 0)
                              + live.freight_amount + live.other_charges AS landed
                       FROM live
                 )";

        $byCurrency = Db::all(
            "{$live}
             SELECT currency_code,
                    COUNT(*)                                                            AS quotes,
                    AVG(landed)                                                         AS average,
                    COUNT(*)    FILTER (WHERE created_at >= :month_start)               AS quotes_this_month,
                    AVG(landed) FILTER (WHERE created_at >= :month_start)               AS average_this_month,
                    COUNT(*)    FILTER (WHERE created_at >= :previous_start
                                          AND created_at <  :month_start)               AS quotes_last_month,
                    AVG(landed) FILTER (WHERE created_at >= :previous_start
                                          AND created_at <  :month_start)               AS average_last_month
               FROM landed
              GROUP BY currency_code
              ORDER BY COUNT(*) DESC, currency_code",
            $params + $months,
        );

        if ($byCurrency === []) {
            return [
                'currency'          => 'INR',
                'quotes'            => 0,
                'average'           => null,
                'average_change_pc' => null,
                'other_currencies'  => 0,
                'savings_potential' => null,
                'open_comparisons'  => 0,
                'comparison_ready'  => 0,
            ];
        }

        $main = $byCurrency[0];
        $currency = (string) $main['currency_code'];

        // The spread between the dearest and the cheapest comparable quotation
        // on an enquiry nobody has decided yet. That difference is what is
        // still on the table — it is not a saving until somebody awards.
        $spread = Db::first(
            "{$live}, per_rfq AS (
                 SELECT rfq_id, currency_code, status,
                        COUNT(*) AS offers, MIN(landed) AS lowest, MAX(landed) AS highest
                   FROM landed
                  GROUP BY rfq_id, currency_code, status
             )
             SELECT COUNT(*) FILTER (WHERE offers >= 2)                                  AS comparison_ready,
                    COUNT(*) FILTER (WHERE offers >= 2
                                       AND status NOT IN ('AWARDED', 'CANCELLED', 'CLOSED')) AS open_comparisons,
                    COALESCE(SUM(highest - lowest) FILTER (
                        WHERE offers >= 2 AND status NOT IN ('AWARDED', 'CANCELLED', 'CLOSED')
                    ), 0)                                                                AS spread
               FROM per_rfq
              WHERE currency_code = :currency",
            $params + ['currency' => $currency],
        ) ?? [];

        $thisMonth = $main['average_this_month'] === null ? null : (float) $main['average_this_month'];
        $lastMonth = $main['average_last_month'] === null ? null : (float) $main['average_last_month'];

        return [
            'currency' => $currency,
            'quotes'   => (int) $main['quotes'],
            'average'  => $main['average'] === null ? null : round((float) $main['average'], 2),
            // No previous month to compare against is no percentage. A change
            // against nothing is not a change.
            'average_change_pc' => ($thisMonth === null || $lastMonth === null || $lastMonth <= 0)
                ? null
                : round((($thisMonth - $lastMonth) / $lastMonth) * 100, 1),
            'other_currencies'  => count($byCurrency) - 1,
            'savings_potential' => round((float) ($spread['spread'] ?? 0), 2),
            'open_comparisons'  => (int) ($spread['open_comparisons'] ?? 0),
            'comparison_ready'  => (int) ($spread['comparison_ready'] ?? 0),
        ];
    }

    /**
     * The optional list filters, as SQL fragments with their bindings.
     *
     * Each one is a question a buyer actually asks of this screen: what did we
     * raise in March, what has this supplier been asked for, what has nobody
     * answered, what is past its deadline. Anything not asked for is absent —
     * a filter the backend cannot honour is worse than no filter, because the
     * UI then lies about what it returned.
     *
     * @param array<string, mixed> $filters
     * @return list<array{0:string, 1:array<string, mixed>}>
     */
    private static function listConditions(array $filters): array
    {
        $conditions = [];

        if (!empty($filters['q'])) {
            $conditions[] = ['(r.rfq_no ILIKE :term OR r.title ILIKE :term)', ['term' => '%' . $filters['q'] . '%']];
        }
        if (!empty($filters['from'])) {
            $conditions[] = ['r.rfq_date >= :from', ['from' => (string) $filters['from']]];
        }
        if (!empty($filters['to'])) {
            $conditions[] = ['r.rfq_date <= :to', ['to' => (string) $filters['to']]];
        }
        if (!empty($filters['supplier_account_id'])) {
            $conditions[] = [
                'EXISTS (SELECT 1 FROM purchase_rfq_invitations i
                          WHERE i.rfq_id = r.rfq_id AND i.supplier_account_id = :supplier)',
                ['supplier' => (int) $filters['supplier_account_id']],
            ];
        }
        if (!empty($filters['created_by'])) {
            $conditions[] = ['r.created_by = :created_by', ['created_by' => (string) $filters['created_by']]];
        }

        $quotes = (string) ($filters['quotes'] ?? '');
        if ($quotes === 'none') {
            $conditions[] = ["NOT EXISTS (SELECT 1 FROM purchase_quotes q WHERE q.rfq_id = r.rfq_id AND q.status <> 'WITHDRAWN')", []];
        } elseif ($quotes === 'any') {
            $conditions[] = ["EXISTS (SELECT 1 FROM purchase_quotes q WHERE q.rfq_id = r.rfq_id AND q.status <> 'WITHDRAWN')", []];
        } elseif ($quotes === 'comparable') {
            // Two or more suppliers priced it, so there is something to compare.
            $conditions[] = [
                "(SELECT COUNT(DISTINCT q.supplier_account_id) FROM purchase_quotes q
                   WHERE q.rfq_id = r.rfq_id AND q.status <> 'WITHDRAWN') >= 2",
                [],
            ];
        }

        if (($filters['deadline'] ?? '') === 'overdue') {
            $conditions[] = [
                "(r.response_deadline IS NOT NULL AND r.response_deadline < NOW()
                  AND r.status IN ('ISSUED', 'RESPONSES_OPEN'))",
                [],
            ];
        } elseif (($filters['deadline'] ?? '') === 'due_soon') {
            $conditions[] = [
                "(r.response_deadline IS NOT NULL
                  AND r.response_deadline BETWEEN NOW() AND NOW() + INTERVAL '7 days'
                  AND r.status IN ('ISSUED', 'RESPONSES_OPEN'))",
                [],
            ];
        }

        return $conditions;
    }

    /** @return list<array<string, mixed>> */
    private function normaliseRfqLines(mixed $raw): array
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
            $quantity = round((float) ($line['required_qty'] ?? 0), 4);
            if ($quantity <= 0) {
                continue;
            }
            $lines[] = [
                'line_no'             => ++$lineNo,
                'requisition_line_id' => self::id($line['requisition_line_id'] ?? null),
                'item_id'             => self::id($line['item_id'] ?? null),
                'unit_id'             => self::id($line['unit_id'] ?? null),
                'is_service'          => (bool) ($line['is_service'] ?? false),
                'description'         => self::text($line['description'] ?? null),
                'required_qty'        => $quantity,
                'required_by'         => self::text($line['required_by'] ?? null),
                'specification'       => self::text($line['specification'] ?? null),
            ];
        }

        return $lines;
    }

    /** @return list<array<string, mixed>> */
    private function normaliseQuoteLines(mixed $raw): array
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
            $quantity = round((float) ($line['quoted_qty'] ?? 0), 4);
            $rate = round((float) ($line['quoted_rate'] ?? 0), 4);
            if ($quantity <= 0) {
                continue;
            }
            $discountPc = round((float) ($line['discount_pc'] ?? 0), 3);
            $gross = $quantity * $rate;

            $lines[] = [
                'line_no'          => ++$lineNo,
                'rfq_line_id'      => self::id($line['rfq_line_id'] ?? null),
                'item_id'          => self::id($line['item_id'] ?? null),
                'unit_id'          => self::id($line['unit_id'] ?? null),
                'quoted_qty'       => $quantity,
                'quoted_rate'      => $rate,
                'discount_pc'      => $discountPc,
                'estimated_tax_pc' => round((float) ($line['estimated_tax_pc'] ?? 0), 3),
                'moq'              => isset($line['moq']) && $line['moq'] !== null ? round((float) $line['moq'], 4) : null,
                'lead_days'        => self::id($line['lead_days'] ?? null),
                'line_amount'      => round($gross - ($gross * $discountPc / 100), 4),
                'remarks'          => self::text($line['remarks'] ?? null),
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
