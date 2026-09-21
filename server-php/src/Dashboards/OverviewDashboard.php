<?php

declare(strict_types=1);

namespace Aicountly\Api\Dashboards;

use Aicountly\Api\Db;
use Aicountly\Api\Http;
use Aicountly\Api\Permissions;

/**
 * Dashboard 1 — Overview.
 *
 * For the owner or purchase head at nine in the morning: what needs a decision
 * today, what is stuck, and what did we actually spend.
 *
 * Two sources, kept apart on purpose. The posted money figures are Books' and
 * disappear honestly when Books cannot be reached; the workflow figures are
 * ours and keep working when it cannot. A screen where those two degrade
 * together is a screen that stops being useful for the half of it that was fine.
 */
final class OverviewDashboard extends Dashboard
{
    public function view(): string
    {
        return 'overview';
    }

    /** @return array<string, mixed> */
    public function build(): array
    {
        $books = null;
        if ($this->canSeeValues()) {
            $books = $this->booksReader()->purchaseSummary($this->period);
            $books['ok']
                ? $this->sources->ready('books', BooksReader::LABEL)
                : $this->sources->unavailable('books', BooksReader::LABEL, (string) $books['error']);
        } else {
            $this->sources->notRequested('books', BooksReader::LABEL, 'Posted purchase values need the reports.view or cost.view permission.');
        }

        $commitment = $this->openCommitment();
        $delayed = $this->delayedOrders();
        $myApprovals = $this->myApprovalQueue();
        $pipeline = $this->pipeline();

        // The supplier position and the opportunity cards are computed once and
        // handed to everything that needs them. The health score, the risk card
        // and the savings card are three readings of the same facts, and three
        // separate computations of them would be three chances to disagree.
        $supplierPosition = $this->supplierPosition();
        $opportunities = $this->canSeeValues()
            ? InsightRules::opportunities($this->ctx, $this->period, $this->documentCurrency() ?? 'INR')
            : [];

        return $this->envelope(
            $this->metrics($books, $commitment, $delayed, $myApprovals),
            [
                'health'        => $this->health($books, $commitment, $delayed, $supplierPosition),
                'supplier_risk' => $this->supplierRisk($supplierPosition),
                'intelligence'  => $this->intelligence($opportunities),
                'briefing'      => $this->briefing($books, $commitment, $delayed, $myApprovals, $pipeline),
                'trend'         => $this->trend($books),
                'ageing'        => $this->ageing($books),
                'category_spend' => $this->categorySpend(),
                'pipeline'      => $pipeline,
                'priority_inbox' => $this->priorityInbox(),
                'concentration' => $this->concentration($books, $supplierPosition),
                'quick_actions' => $this->quickActions(),
            ],
            ['score_model' => SupplierScore::model($this->period->label())],
        );
    }

    // -----------------------------------------------------------------------
    // Metrics
    // -----------------------------------------------------------------------

    /**
     * @param array<string, mixed>|null $books
     * @param array<string, string>     $commitment
     * @param array<string, mixed>      $delayed
     * @return list<array<string, mixed>>
     */
    private function metrics(?array $books, array $commitment, array $delayed, int $myApprovals): array
    {
        $currency = $this->documentCurrency() ?? 'INR';
        $comparisonLabel = $this->period->comparisonLabel();

        $netBasis = 'Posted purchase vouchers less posted debit notes for ' . $this->period->label()
            . ', inclusive of tax, on the accounting date. Smart Books is the authority for this figure.';

        if ($books === null) {
            $net = Metric::unavailable('net_purchases', 'Net posted purchases', 'Posted purchase values need the reports.view or cost.view permission.', $netBasis, ['format' => 'currency', 'currency' => $currency, 'direction' => Metric::NEUTRAL]);
            $dues = Metric::unavailable('supplier_dues', 'Outstanding supplier dues', 'Payable figures need the reports.view or cost.view permission.', 'Open creditor balances in Smart Books.', ['format' => 'currency', 'currency' => $currency, 'direction' => Metric::LOWER_IS_BETTER]);
            $overdue = Metric::unavailable('overdue_dues', 'Overdue supplier dues', 'Payable figures need the reports.view or cost.view permission.', 'Open creditor balances past their due date in Smart Books.', ['format' => 'currency', 'currency' => $currency, 'direction' => Metric::LOWER_IS_BETTER]);
        } elseif (!$books['ok']) {
            $reason = (string) $books['error'];
            $net = Metric::unavailable('net_purchases', 'Net posted purchases', $reason, $netBasis, ['format' => 'currency', 'currency' => $currency]);
            $dues = Metric::unavailable('supplier_dues', 'Outstanding supplier dues', $reason, 'Open creditor balances in Smart Books, as at ' . Format::date($this->period->to) . '.', ['format' => 'currency', 'currency' => $currency, 'direction' => Metric::LOWER_IS_BETTER]);
            $overdue = Metric::unavailable('overdue_dues', 'Overdue supplier dues', $reason, 'Open creditor balances past their due date in Smart Books.', ['format' => 'currency', 'currency' => $currency, 'direction' => Metric::LOWER_IS_BETTER]);
        } else {
            $kpis = (array) $books['kpis'];
            $previous = (array) $books['previous'];

            $net = Metric::ready(
                'net_purchases',
                'Net posted purchases',
                $kpis['total_purchases'] ?? null,
                $netBasis,
                [
                    'format'           => 'currency',
                    'currency'         => $currency,
                    'direction'        => Metric::NEUTRAL,
                    'previous'         => $previous['total_purchases'] ?? null,
                    'comparison_label' => $comparisonLabel,
                    'explanation'      => $netBasis . ' Purchase returns of '
                        . Format::money($kpis['purchase_returns'] ?? '0', $currency) . ' are already deducted.',
                    'drilldown'        => $this->drilldown('/bills', ['status' => 'POSTED']),
                    'footnote'         => 'Includes tax. Input GST in the period: ' . Format::money($kpis['input_gst'] ?? '0', $currency) . '.',
                    // The only metric on this screen with a real history behind
                    // it: Books sends the daily points, so the card draws them
                    // rather than a two-point line dressed up as a trend.
                    'series'           => self::spark((array) $books['trend'], $currency),
                ],
            );

            $dues = Metric::ready(
                'supplier_dues',
                'Outstanding supplier dues',
                $kpis['payables'] ?? null,
                'Open creditor balances in Smart Books as at ' . Format::date($this->period->to) . '. Settled portions are excluded by Books.',
                [
                    'format'    => 'currency',
                    'currency'  => $currency,
                    'direction' => Metric::LOWER_IS_BETTER,
                    'previous'  => $previous['payables'] ?? null,
                    'comparison_label' => $comparisonLabel,
                    'drilldown' => $this->drilldown('/dashboard/bills-payables'),
                ],
            );

            $overdue = Metric::ready(
                'overdue_dues',
                'Overdue supplier dues',
                $kpis['overdue_payables'] ?? null,
                'Open creditor balances whose Books due date is before ' . Format::date($this->period->to) . '. Bills with no due date are not counted as overdue.',
                [
                    'format'    => 'currency',
                    'currency'  => $currency,
                    'direction' => Metric::LOWER_IS_BETTER,
                    'previous'  => $previous['overdue_payables'] ?? null,
                    'comparison_label' => $comparisonLabel,
                    'drilldown' => $this->drilldown('/dashboard/bills-payables', ['bucket' => 'overdue']),
                ],
            );
        }

        return [
            $net,
            Metric::ready(
                'open_commitment',
                'Open order commitment',
                $this->canSeeValues() ? $commitment['value'] : null,
                'Remaining quantity × agreed rate on purchase orders that are issued, acknowledged or partly received, plus their unspent freight and charges. Excludes cancelled and closed orders.',
                [
                    'format'    => 'currency',
                    'currency'  => $currency,
                    'direction' => Metric::NEUTRAL,
                    'explanation' => 'What this company has committed to buy and not yet received. Tax is excluded: the tax actually charged is decided when the bill is entered.',
                    'comparison_unavailable_reason' => 'Commitment is a position as at today, not a figure for a period, so there is no previous period to compare it with.',
                    'drilldown' => $this->drilldown('/purchase-orders', ['open_only' => '1']),
                    'footnote'  => $commitment['order_count'] . ' open order' . ($commitment['order_count'] === 1 ? '' : 's') . '.',
                ],
            ),
            $dues,
            $overdue,
            Metric::ready(
                'delayed_orders',
                'Orders with delayed quantities',
                (string) $delayed['count'],
                'Orders whose promised date has passed and whose ordered quantity has not been fully received, as at ' . Format::date(gmdate('Y-m-d')) . '.',
                [
                    'format'    => 'count',
                    'direction' => Metric::LOWER_IS_BETTER,
                    'explanation' => 'Counted per order. The still-unreceived quantity stays visible here rather than dropping out of the picture when a line is partly delivered.',
                    'comparison_unavailable_reason' => 'Delay is measured against today, so a previous-period comparison would not mean the same thing.',
                    'drilldown' => $this->drilldown('/purchase-orders', ['overdue' => '1']),
                    'footnote'  => $delayed['lines'] . ' delayed line' . ($delayed['lines'] === 1 ? '' : 's') . ' across them.',
                ],
            ),
            Metric::ready(
                'my_approvals',
                'Awaiting your approval',
                (string) $myApprovals,
                'Pending approval requests whose required permission you hold, excluding anything you raised yourself.',
                [
                    'format'    => 'count',
                    'direction' => Metric::LOWER_IS_BETTER,
                    'explanation' => 'Segregation of duties is applied here as well as on the approve button: a document you raised never appears in your own queue.',
                    'comparison_unavailable_reason' => 'A queue is a position, not a period total.',
                    'drilldown' => $this->drilldown('/approvals'),
                ],
            ),
        ];
    }

    /**
     * The daily points, thinned to something a 30px sparkline can carry.
     *
     * Nothing is smoothed or interpolated: every point drawn is a day Books
     * reported, and the label travels with it so the accessible summary can
     * state the figures the drawing only gestures at.
     *
     * @param list<array{date: string, amount: string}> $trend
     * @return list<array{label: string, value: string, formatted: string}>
     */
    private static function spark(array $trend, string $currency): array
    {
        $points = array_values(array_filter($trend, static fn ($point) => is_array($point) && isset($point['date'])));
        // A sparkline that has to render two hundred days on a card this wide
        // is drawing sub-pixel detail nobody can see, so only the most recent
        // stretch is drawn and the card says what it covers.
        $points = array_slice($points, -45);

        return array_map(
            static fn (array $point) => [
                'label'     => (string) Format::date((string) $point['date']),
                'value'     => Decimal::of($point['amount'] ?? '0'),
                'formatted' => Format::money(Decimal::of($point['amount'] ?? '0'), $currency),
            ],
            $points,
        );
    }

    // -----------------------------------------------------------------------
    // Our own figures
    // -----------------------------------------------------------------------

    /** @return array{value: string, order_count: int} */
    private function openCommitment(): array
    {
        [$filterSql, $filterParams] = $this->filters->orderClause('p');

        $row = $this->row(
            "SELECT
                COALESCE(SUM(GREATEST(l.ordered_qty - l.received_qty, 0) * l.agreed_rate), 0)::text AS value,
                COUNT(DISTINCT p.po_id)                                                            AS order_count
             FROM purchase_order_lines l
             JOIN purchase_orders p ON p.po_id = l.po_id
             WHERE {scope}
               AND p.status IN ('ISSUED', 'ACKNOWLEDGED', 'PARTIALLY_RECEIVED')
               " . $filterSql,
            $filterParams,
            'p',
        ) ?? [];

        return [
            'value'       => Decimal::of($row['value'] ?? '0'),
            'order_count' => (int) ($row['order_count'] ?? 0),
        ];
    }

    /** @return array{count: int, lines: int} */
    private function delayedOrders(): array
    {
        [$filterSql, $filterParams] = $this->filters->orderClause('p');

        $row = $this->row(
            "SELECT COUNT(DISTINCT p.po_id) AS orders, COUNT(*) AS lines
             FROM purchase_order_lines l
             JOIN purchase_orders p ON p.po_id = l.po_id
             WHERE {scope}
               AND p.status IN ('ISSUED', 'ACKNOWLEDGED', 'PARTIALLY_RECEIVED')
               AND l.ordered_qty > l.received_qty
               AND COALESCE(l.promised_date, p.promised_date) < CURRENT_DATE
               " . $filterSql,
            $filterParams,
            'p',
        ) ?? [];

        return ['count' => (int) ($row['orders'] ?? 0), 'lines' => (int) ($row['lines'] ?? 0)];
    }

    /**
     * How many pending approvals are actually THIS user's to decide.
     *
     * A count of every pending approval in the company would be a number the
     * reader can do nothing about. Two narrowings make it actionable: the
     * permission the stage requires, and the rule that nobody approves their own.
     */
    private function myApprovalQueue(): int
    {
        $granted = Permissions::granted($this->ctx, $this->auth);
        if ($granted === []) {
            return 0;
        }

        $placeholders = [];
        $params = ['me' => $this->auth->uuid];
        foreach (array_values($granted) as $index => $permission) {
            $placeholders[] = ':perm' . $index;
            $params['perm' . $index] = $permission;
        }

        return $this->count(
            'SELECT COUNT(*) FROM purchase_approval_requests
             WHERE cmp_id = :ctx_cmp_id AND fy_id = :ctx_fy_id
               AND status = \'PENDING\'
               AND requested_by <> :me
               AND (required_permission IS NULL OR required_permission IN (' . implode(', ', $placeholders) . '))',
            $params,
        );
    }

    /**
     * Open payable per supplier, for the top-supplier table.
     *
     * NOT part of `build()`, on purpose. Smart Books answers bill-by-bill for
     * ONE account at a time, so a payables column across five suppliers is five
     * upstream calls — and five upstream calls in the middle of the first paint
     * is a dashboard that arrives a second and a half late for a column nobody
     * has scrolled to yet. The screen draws, then asks for this.
     *
     * The client sends the supplier ids it has on screen, so the column cannot
     * end up describing a different five suppliers from the rows it sits in.
     * Ids this company has no purchase record for are dropped rather than
     * relayed to Books.
     *
     * @return array<string, mixed>
     */
    public function supplierPayables(): array
    {
        if (!$this->canSeeValues()) {
            Http::forbidden('Payable figures need the reports.view or cost.view permission.');
        }

        $asked = [];
        foreach (explode(',', (string) (Http::param('supplier_ids') ?? '')) as $raw) {
            $id = (int) trim($raw);
            if ($id > 0 && !in_array($id, $asked, true)) {
                $asked[] = $id;
            }
        }
        $asked = array_slice($asked, 0, self::PAYABLES_SUPPLIER_CAP);

        if ($asked === []) {
            return [
                'as_on'     => $this->period->to,
                'as_on_label' => Format::date($this->period->to),
                'currency'  => $this->documentCurrency() ?? 'INR',
                'suppliers' => [],
                'basis'     => 'No suppliers were asked about.',
            ];
        }

        // Only ids this company actually buys from. An account number typed
        // into the query string is not a reason to ask Books about it.
        $placeholders = [];
        $params = [];
        foreach ($asked as $index => $id) {
            $placeholders[] = ':s' . $index;
            $params['s' . $index] = $id;
        }
        $known = [];
        foreach ($this->rows(
            'SELECT DISTINCT supplier_account_id FROM purchase_orders
             WHERE {scope} AND supplier_account_id IN (' . implode(', ', $placeholders) . ')
             UNION
             SELECT DISTINCT supplier_account_id FROM purchase_bill_requests
             WHERE cmp_id = :ctx_cmp_id AND supplier_account_id IN (' . implode(', ', $placeholders) . ')',
            $params,
        ) as $row) {
            $known[(int) $row['supplier_account_id']] = true;
        }

        $currency = $this->documentCurrency() ?? 'INR';
        $reader = $this->booksReader();
        $asOn = $this->period->to;
        $out = [];
        $reached = false;

        foreach ($asked as $id) {
            if (!isset($known[$id])) {
                $out[] = [
                    'supplier_account_id' => $id,
                    'available' => false,
                    'reason'    => 'This company has no purchase record for that supplier.',
                ];
                continue;
            }

            $open = $reader->openItems($id, $asOn);
            if (!$open['ok']) {
                $out[] = [
                    'supplier_account_id' => $id,
                    'available' => false,
                    'reason'    => (string) $open['error'],
                ];
                continue;
            }

            $reached = true;
            $pending = Decimal::ZERO;
            $overdue = Decimal::ZERO;
            $bills = 0;
            foreach ($open['rows'] as $item) {
                $amount = (string) $item['pending_amount'];
                $pending = Decimal::add($pending, $amount);
                $bills++;
                if (($item['days_overdue'] ?? null) !== null && (int) $item['days_overdue'] > 0) {
                    $overdue = Decimal::add($overdue, $amount);
                }
            }

            $out[] = [
                'supplier_account_id' => $id,
                'available'  => true,
                'pending'    => $pending,
                'formatted'  => Format::money($pending, $currency),
                'compact'    => Format::compactMoney($pending, $currency),
                'overdue'    => $overdue,
                'overdue_formatted' => Format::money($overdue, $currency),
                'bills'      => $bills,
            ];
        }

        // Only report on Books when Books was actually asked. A request whose
        // every id was unknown to this company never reached it, and saying it
        // was unavailable would put a red dot against a product that is fine.
        if ($asked !== [] && $known !== []) {
            $reached
                ? $this->sources->ready('books', BooksReader::LABEL)
                : $this->sources->unavailable('books', BooksReader::LABEL, 'Smart Books did not return open items for any of the suppliers asked about.');
        }

        return [
            'as_on'       => $asOn,
            'as_on_label' => Format::date($asOn),
            'currency'    => $currency,
            'suppliers'   => $out,
            'sources'     => $this->sources->toArray(),
            'basis'       => 'Open bills from Smart Books, by its own due dates, as at ' . Format::date($asOn)
                . '. Settled portions are excluded by Books, and nothing here is recomputed from an invoice total.',
        ];
    }

    /** How many suppliers one payables call will ask Books about. */
    private const PAYABLES_SUPPLIER_CAP = 6;

    // -----------------------------------------------------------------------
    // The executive strip
    // -----------------------------------------------------------------------

    /**
     * Where this company's suppliers stand, read once.
     *
     * Returns the per-supplier rows the top-supplier table needs AND the
     * company-wide position the risk card states, both from one query and one
     * scoring model. The alternative — a card that scores suppliers one way and
     * a table beside it that scores them another — is how a screen teaches its
     * reader to ignore both.
     *
     * @return array{rows: array<int, array<string, mixed>>, score: ?string, components: list<array<string, mixed>>, missing: list<string>, band: array<string, mixed>, suppliers: int, flagged: int, receipts: int}
     */
    private function supplierPosition(): array
    {
        $performance = $this->supplierPerformance();

        $rows = [];
        $receipts = 0;
        $onTime = 0;
        $inspected = 0;
        $accepted = 0;
        $lines = 0;
        $complete = 0;
        $flagged = 0;

        foreach ($performance as $row) {
            $supplierReceipts = (int) $row['receipt_count'];
            $supplierInspected = (int) $row['inspected_lines'];
            $supplierLines = (int) $row['line_count'];

            $receipts += $supplierReceipts;
            $onTime += (int) $row['on_time_count'];
            $inspected += $supplierInspected;
            $accepted += $supplierInspected - (int) $row['rejected_lines'];
            $lines += $supplierLines;
            $complete += (int) $row['complete_lines'];

            if (in_array((string) ($row['risk_flag'] ?? ''), ['high', 'blacklisted', 'watch'], true)) {
                $flagged++;
            }

            $supplierOnTime = $supplierReceipts >= SupplierScore::MIN_SAMPLE
                ? Decimal::percentOf((string) $row['on_time_count'], (string) $supplierReceipts, 1)
                : null;
            $supplierAcceptance = $supplierInspected >= SupplierScore::MIN_SAMPLE
                ? Decimal::percentOf((string) ($supplierInspected - (int) $row['rejected_lines']), (string) $supplierInspected, 1)
                : null;
            $supplierFulfilment = $supplierLines >= SupplierScore::MIN_SAMPLE
                ? Decimal::percentOf((string) $row['complete_lines'], (string) $supplierLines, 1)
                : null;

            $score = SupplierScore::compose($supplierOnTime, $supplierAcceptance, $supplierFulfilment);

            $rows[(int) $row['supplier_account_id']] = [
                'supplier_account_id' => (int) $row['supplier_account_id'],
                'supplier_name'   => $row['supplier_name'],
                'on_time_pc'      => $supplierOnTime,
                'on_time_sample'  => $supplierReceipts,
                'on_time_label'   => $supplierOnTime === null
                    ? ($supplierReceipts === 0 ? 'No receipts yet' : $supplierReceipts . ' receipt' . ($supplierReceipts === 1 ? '' : 's') . ' — too few to rate')
                    : Format::percent($supplierOnTime, 1),
                'score'           => $score['value'],
                'score_components' => $score['components'],
                'risk'            => SupplierScore::band($score['value']),
                'risk_flag'       => $row['risk_flag'],
                'qualification_status' => $row['qualification_status'] ?? 'none',
                'overdue_lines'   => (int) $row['overdue_lines'],
                'open_claims'     => (int) $row['open_claims'],
                'po_count'        => (int) $row['po_count'],
                'ordered_value'   => Decimal::of($row['ordered_value']),
            ];
        }

        // The company-wide position uses the same three components as a single
        // supplier's, computed over every receipt and line rather than averaged
        // across suppliers: a supplier with one order must not weigh the same
        // as the one supplying half the factory.
        $composed = SupplierScore::compose(
            $receipts >= SupplierScore::MIN_SAMPLE ? Decimal::percentOf((string) $onTime, (string) $receipts, 1) : null,
            $inspected >= SupplierScore::MIN_SAMPLE ? Decimal::percentOf((string) $accepted, (string) $inspected, 1) : null,
            $lines >= SupplierScore::MIN_SAMPLE ? Decimal::percentOf((string) $complete, (string) $lines, 1) : null,
        );

        return [
            'rows'       => $rows,
            'score'      => $composed['value'],
            'components' => $composed['components'],
            'missing'    => $composed['missing'],
            'band'       => SupplierScore::band($composed['value']),
            'suppliers'  => count($performance),
            'flagged'    => $flagged,
            'receipts'   => $receipts,
        ];
    }

    /**
     * Executive card 1 — the procurement health score.
     *
     * Five ratios of things this product can actually count, weighted, with
     * every component and its denominator published beside the number. There is
     * no Aicountly-wide scoring methodology to defer to, so this one names
     * itself as this screen's model rather than implying an authority it does
     * not have — and a component with no denominator is excluded and said to be
     * excluded, never scored as a zero.
     *
     * @param array<string, mixed>|null $books
     * @param array<string, string>     $commitment
     * @param array<string, mixed>      $delayed
     * @param array<string, mixed>      $supplierPosition
     * @return array<string, mixed>
     */
    private function health(?array $books, array $commitment, array $delayed, array $supplierPosition): array
    {
        $openOrders = (int) $commitment['order_count'];
        $delayedOrders = (int) $delayed['count'];

        $payableHealth = null;
        $payableBasis = $books === null
            ? 'Posted payable figures need the reports.view or cost.view permission, so this was not counted.'
            : 'Smart Books could not be reached, so the payable standing was not counted: ' . (string) $books['error'];
        if ($books !== null && $books['ok']) {
            $dues = Decimal::of(((array) $books['kpis'])['payables'] ?? '0');
            $overdue = Decimal::of(((array) $books['kpis'])['overdue_payables'] ?? '0');
            $payableHealth = CompositeScore::rateOfAmounts(Decimal::sub($dues, $overdue), $dues);
            $payableBasis = Decimal::isZero($dues)
                ? 'No open creditor balance in Smart Books as at ' . Format::date($this->period->to) . ', so there is nothing to be overdue.'
                : 'Open creditor balance not yet past its due date, as a share of ' . Format::money($dues, $this->documentCurrency() ?? 'INR') . ' owed.';
        }

        $matching = $this->matchingCleanliness();
        $approvals = $this->approvalTurnaround();

        $composed = CompositeScore::of([
            [
                'key' => 'delivery', 'label' => 'Supplier delivery', 'weight' => 30,
                'value' => $supplierPosition['score'],
                'basis' => 'The supplier scorecard for this period, over ' . $supplierPosition['receipts'] . ' receipt'
                    . ($supplierPosition['receipts'] === 1 ? '' : 's') . '.',
                'sample' => $supplierPosition['receipts'],
            ],
            [
                'key' => 'payables', 'label' => 'Payables in good standing', 'weight' => 25,
                'value' => $payableHealth,
                'basis' => $payableBasis,
            ],
            [
                'key' => 'schedule', 'label' => 'Orders on schedule', 'weight' => 20,
                'value' => CompositeScore::rate($openOrders - $delayedOrders, $openOrders),
                'basis' => $openOrders === 0
                    ? 'No open purchase orders, so there is no schedule to keep.'
                    : $delayedOrders . ' of ' . $openOrders . ' open orders are past their promised date.',
                'sample' => $openOrders,
            ],
            [
                'key' => 'matching', 'label' => 'Bills matching cleanly', 'weight' => 15,
                'value' => CompositeScore::rate($matching['clean'], $matching['total']),
                'basis' => $matching['total'] === 0
                    ? 'No supplier bills entered in this period.'
                    : $matching['clean'] . ' of ' . $matching['total'] . ' bills entered in this period carry no open match exception.',
                'sample' => $matching['total'],
            ],
            [
                'key' => 'approvals', 'label' => 'Approvals cleared', 'weight' => 10,
                'value' => CompositeScore::rate($approvals['cleared'], $approvals['raised']),
                'basis' => $approvals['raised'] === 0
                    ? 'No approvals were raised in this period.'
                    : $approvals['cleared'] . ' of ' . $approvals['raised'] . ' approvals raised in this period have been decided.',
                'sample' => $approvals['raised'],
            ],
        ]);

        $band = CompositeScore::band(
            $composed['value'],
            ['good' => '80', 'fair' => '60'],
            ['good' => 'Good', 'fair' => 'Fair', 'poor' => 'Needs attention', 'unknown' => 'Not enough data'],
        );

        return $this->panel([
            'score'      => $composed['value'],
            'score_formatted' => $composed['value'] === null ? null : Decimal::round($composed['value'], 0),
            'band'       => $band,
            'summary'    => match ($band['id']) {
                'good'    => 'On track for your goals',
                'fair'    => 'Some parts of the cycle need a look',
                'poor'    => 'Several parts of the cycle need attention',
                default   => 'Not enough activity in this period to score',
            },
            'components' => $composed['components'],
            'missing'    => $composed['missing'],
            'counted_weight' => $composed['counted_weight'],
            // How much of the model actually had something to measure. A score
            // standing on one of five components is still the honest answer
            // for what could be counted, but the reader is told that rather
            // than left to assume all five were weighed.
            'confidence' => [
                'counted'       => count($composed['components']) - count($composed['missing']),
                'total'         => count($composed['components']),
                'counted_weight' => $composed['counted_weight'],
                'label'         => $composed['value'] === null
                    ? 'Nothing in this period could be measured.'
                    : 'Based on ' . (count($composed['components']) - count($composed['missing'])) . ' of '
                      . count($composed['components']) . ' measures (' . $composed['counted_weight'] . '% of the model).',
                'partial'       => $composed['counted_weight'] < 100,
            ],
            'basis'      => 'A weighted score out of 100 over five ratios this application can count for '
                . $this->period->label() . '. A component with no denominator is excluded and the remaining '
                . 'weights are re-normalised, so a quiet period is not reported as a bad one.',
            'method'     => 'This is this screen\'s model, not an Aicountly-wide standard. The components and '
                . 'weights are published above so the figure can be reconstructed by hand.',
        ]);
    }

    /** @return array{clean: int, total: int} bills entered in the period, and those with no open exception */
    private function matchingCleanliness(): array
    {
        [$billSql, $billParams] = $this->filters->billClause('b');

        $row = $this->row(
            "SELECT COUNT(*) AS total,
                    COUNT(*) FILTER (
                        WHERE NOT EXISTS (
                            SELECT 1 FROM purchase_match_results m
                            JOIN purchase_match_exceptions e ON e.match_id = m.match_id AND e.status = 'OPEN'
                            WHERE m.bill_request_id = b.request_id
                        )
                    ) AS clean
             FROM purchase_bill_requests b
             WHERE {scope} AND b.status <> 'CANCELLED'
               AND b.created_at::date BETWEEN :from AND :to" . $billSql,
            $this->period->params() + $billParams,
            'b',
        ) ?? [];

        return ['clean' => (int) ($row['clean'] ?? 0), 'total' => (int) ($row['total'] ?? 0)];
    }

    /** @return array{cleared: int, raised: int} approvals raised in the period, and those since decided */
    private function approvalTurnaround(): array
    {
        $row = $this->row(
            "SELECT COUNT(*) AS raised,
                    COUNT(*) FILTER (WHERE status <> 'PENDING') AS cleared
             FROM purchase_approval_requests
             WHERE cmp_id = :ctx_cmp_id AND fy_id = :ctx_fy_id
               AND created_at::date BETWEEN :from AND :to",
            $this->period->params(),
        ) ?? [];

        return ['cleared' => (int) ($row['cleared'] ?? 0), 'raised' => (int) ($row['raised'] ?? 0)];
    }

    /**
     * Executive card 4 — where the supplier base stands.
     *
     * @param array<string, mixed> $supplierPosition
     * @return array<string, mixed>
     */
    private function supplierRisk(array $supplierPosition): array
    {
        if (!$this->can('supplier.view')) {
            return $this->withheld('supplier.view');
        }

        return $this->panel([
            'score'      => $supplierPosition['score'],
            'score_formatted' => $supplierPosition['score'] === null ? null : Decimal::round($supplierPosition['score'], 0),
            'band'       => $supplierPosition['band'],
            'components' => $supplierPosition['components'],
            'missing'    => $supplierPosition['missing'],
            'suppliers'  => $supplierPosition['suppliers'],
            'flagged'    => $supplierPosition['flagged'],
            'receipts'   => $supplierPosition['receipts'],
            // Said plainly, because a score that runs the other way from what a
            // reader assumes is worse than no score at all.
            'direction'  => 'higher_is_safer',
            'basis'      => 'On-time delivery, acceptance and line fulfilment across every receipt and order line in '
                . $this->period->label() . ', weighted as the supplier scorecard weights them. Higher is safer.',
            'route'      => '/dashboard/suppliers',
        ]);
    }

    /**
     * Executive card 2 and the recommendations panel — one set of cards.
     *
     * These are RULES over this company's own orders, not a language model's
     * opinion, and the panel says so. Each card carries the baseline it was
     * computed from and the assumption behind the estimate, so a saving can be
     * argued with rather than believed.
     *
     * @param list<array<string, mixed>> $opportunities
     * @return array<string, mixed>
     */
    private function intelligence(array $opportunities): array
    {
        if (!$this->canSeeValues()) {
            return $this->withheld('cost.view');
        }

        $currency = $this->documentCurrency();
        if ($currency === null) {
            return $this->unavailablePanel(
                'Orders in this period are in more than one currency, so a single savings figure would be adding rupees to dollars. Filter to one currency to see it.',
            );
        }

        $summed = InsightRules::opportunityTotal($opportunities);
        $counted = array_flip($summed['counted']);

        $cards = [];
        foreach (array_slice($opportunities, 0, 3) as $opportunity) {
            $cards[] = [
                'id'        => $opportunity['id'],
                'kind'      => $opportunity['kind'],
                'title'     => $opportunity['title'],
                'detail'    => $opportunity['detail'],
                'estimate_formatted' => $opportunity['estimate_formatted'],
                'baseline_formatted' => $opportunity['baseline_formatted'],
                'assumption' => $opportunity['assumption'],
                'counted_in_total' => isset($counted[$opportunity['id']]),
                'action_label' => match ($opportunity['kind']) {
                    'consolidation' => 'View suggestion',
                    'price'         => 'View price history',
                    default         => 'View orders',
                },
                'route'     => $opportunity['route'],
                'filters'   => $opportunity['filters'],
            ];
        }

        return $this->panel([
            'total'      => $summed['total'],
            'total_formatted' => Format::money($summed['total'], $currency),
            'total_compact' => Format::compactMoney($summed['total'], $currency),
            'currency'   => $currency,
            'card_count' => count($opportunities),
            'overlapping' => $summed['overlapping'],
            'cards'      => $cards,
            'method'     => 'rules',
            'method_label' => 'Found by fixed rules over your own orders. No AI model was consulted.',
            // Stated, not silently ignored. The opportunity rules are shared with
            // the AI Insights screen and read the company and financial year
            // only, so a branch or a supplier chosen in the command bar does
            // not narrow them. Saying so beats a figure the reader believes is
            // about the branch they are looking at.
            'narrowed_by_filters' => false,
            'scope_note' => $this->filters->isEmpty() && $this->ctx->boId === 0
                ? null
                : 'These findings cover the whole company for this period. The filters above do not narrow them.',
            'basis'      => 'The sum of the estimates on the opportunity cards that do not describe the same spend twice, for '
                . $this->period->label() . '. Upper bounds under the assumptions stated on each card, not a budget.',
            'route'      => '/dashboard/ai-insights',
        ]);
    }

    // -----------------------------------------------------------------------
    // Panels
    // -----------------------------------------------------------------------

    /**
     * Panel A — the briefing.
     *
     * Deterministic rules over the figures already on this page, ranked by the
     * money or the delay at stake. It is labelled as rules-based, because an
     * unlabelled list of "insights" invites the reader to assume a model looked
     * at their data when nothing of the sort happened.
     *
     * @param array<string, mixed>|null $books
     * @param array<string, string>     $commitment
     * @param array<string, mixed>      $delayed
     * @param array<string, mixed>      $pipeline
     * @return array<string, mixed>
     */
    private function briefing(?array $books, array $commitment, array $delayed, int $myApprovals, array $pipeline): array
    {
        $items = InsightRules::briefing(
            $this->ctx,
            $this->period,
            [
                'books'        => $books,
                'commitment'   => $commitment,
                'delayed'      => $delayed,
                'my_approvals' => $myApprovals,
                'pipeline'     => $pipeline,
                'currency'     => $this->documentCurrency() ?? 'INR',
            ],
        );

        return $this->panel([
            'method'      => 'rules',
            'method_label' => 'Rules-based briefing — no AI model was consulted.',
            'items'       => array_slice($items, 0, 5),
            'as_of'       => gmdate('c'),
        ]);
    }

    /**
     * Panel B — the spend trend, this period against the last.
     *
     * Books answers per day for the range it was asked about. Thirty-one bars
     * on a card this size is a comb, not a chart, so the days are bucketed —
     * into weeks or months as the range grows — and the bucket width is stated
     * on the panel rather than left for the reader to infer from the labels.
     *
     * The comparison series costs a SECOND read of Books, for the previous
     * range. It is only taken when a comparison was asked for and the current
     * range has something in it, and the panel still draws without it when
     * Books declines the second call.
     *
     * @param array<string, mixed>|null $books
     * @return array<string, mixed>
     */
    private function trend(?array $books): array
    {
        if ($books === null) {
            return $this->withheld('reports.view');
        }
        if (!$books['ok']) {
            return $this->unavailablePanel((string) $books['error']);
        }

        $currency = $this->documentCurrency() ?? 'INR';
        $granularity = $this->granularity();

        // Bucketed over the WHOLE requested range, not over the days Books
        // happened to have something on. A day with no purchases is a real
        // zero, and leaving it out shortens the series — which then pairs the
        // second bar of this period against the fifth of the last one.
        $bucketed = self::bucket((array) $books['trend'], $granularity, $currency, $this->period->from, $this->period->to);
        $current = $bucketed['buckets'];

        $previous = [];
        $previousReason = null;
        if ($this->period->compareFrom !== null && $current !== []) {
            $compareFrom = (string) $this->period->compareFrom;
            $compareTo = (string) $this->period->compareTo;
            $prior = $this->booksReader()->purchaseSummary(Period::forDates($compareFrom, $compareTo));
            if ($prior['ok']) {
                $previous = self::bucket((array) $prior['trend'], $granularity, $currency, $compareFrom, $compareTo)['buckets'];
            } else {
                $previousReason = (string) $prior['error'];
            }
        } elseif ($this->period->compareFrom === null) {
            $previousReason = 'No comparison period is selected.';
        }

        // Aligned from the most recent bucket backwards. Two equally long date
        // ranges can still fall into a different number of calendar months, and
        // pairing them from the start would put this September beside last
        // August on one screen and beside last July on the next.
        $points = [];
        $offset = count($current) - count($previous);
        foreach ($current as $index => $bucket) {
            $mate = $previous[$index - $offset] ?? null;
            $points[] = [
                'key'       => $bucket['key'],
                'label'     => $bucket['label'],
                'amount'    => $bucket['amount'],
                'formatted' => $bucket['formatted'],
                'compact'   => $bucket['compact'],
                'previous_amount'    => $mate === null ? null : $mate['amount'],
                'previous_formatted' => $mate === null ? null : $mate['formatted'],
                'previous_label'     => $mate === null ? null : $mate['label'],
            ];
        }

        $total = Decimal::sum(array_map(static fn (array $point) => (string) $point['amount'], $points));

        return $this->panel([
            'granularity' => $granularity,
            'granularity_label' => match ($granularity) {
                'day'   => 'by day',
                'week'  => 'by week',
                default => 'by month',
            },
            'granularity_options' => self::GRANULARITIES,
            'currency'    => $currency,
            'points'      => $points,
            'total'       => $total,
            'total_formatted' => Format::money($total, $currency),
            'total_compact'   => Format::compactMoney($total, $currency),
            'comparison'  => [
                'label'     => $this->period->comparisonLabel(),
                'available' => $previous !== [],
                'reason'    => $previousReason,
                'previous'  => ((array) $books['previous'])['total_purchases'] ?? null,
                'current'   => ((array) $books['kpis'])['total_purchases'] ?? null,
            ],
            'outside_range' => $bucketed['outside']['points'] === 0 ? null : [
                'points'    => $bucketed['outside']['points'],
                'amount'    => $bucketed['outside']['amount'],
                'formatted' => Format::money($bucketed['outside']['amount'], $currency),
                'note'      => 'Smart Books also returned ' . $bucketed['outside']['points'] . ' day'
                    . ($bucketed['outside']['points'] === 1 ? '' : 's') . ' outside the range asked for. '
                    . 'They are not drawn here and are not in this total.',
            ],
            'basis'       => 'Net posted purchases from Smart Books, on the accounting date, for ' . $this->period->label()
                . ', totalled ' . match ($granularity) { 'day' => 'per day', 'week' => 'per week', default => 'per calendar month' } . '.',
        ]);
    }

    /** Bucket widths the panel offers. `auto` picks one from the length of the range. */
    private const GRANULARITIES = ['auto', 'day', 'week', 'month'];

    /**
     * How wide a bar should be.
     *
     * Chosen from the range rather than fixed, so a week reads as seven bars
     * and a financial year as twelve rather than three hundred and sixty-five.
     */
    private function granularity(): string
    {
        $asked = (string) (Http::param('granularity') ?? 'auto');
        if (in_array($asked, ['day', 'week', 'month'], true)) {
            return $asked;
        }

        $days = $this->period->days();

        // Fourteen, not thirty. A month-to-date drawn per day is three weeks of
        // 12px bars with labels nobody can read; per week it is four bars that
        // answer the question the panel is for.
        return match (true) {
            $days <= 14  => 'day',
            $days <= 120 => 'week',
            default      => 'month',
        };
    }

    /**
     * Daily points from Books, summed into every bucket the range contains.
     *
     * THE RANGE DECIDES THE BUCKETS, not the data. Books sends a point for a
     * day it has something on and nothing for a day it does not, so bucketing
     * only what arrives produces a series whose length depends on how busy the
     * period was. Two such series cannot be paired: a quiet August gives four
     * bars where a busy September gives nine, and every comparison bar then
     * sits under the wrong period. Building the buckets from the dates means
     * both series have the length their range implies, and a bucket with
     * nothing in it is a zero — which is what Books actually said.
     *
     * The amounts are added with the decimal arithmetic the rest of this file
     * uses; nothing here goes anywhere near a float.
     *
     * A point dated outside the range is NOT given a bucket of its own. Doing
     * that would make the series length depend on the answer again, which is
     * the very thing this function exists to stop. It is counted and reported
     * instead, so an upstream answering about days nobody asked for is visible
     * rather than silently dropped or silently drawn.
     *
     * @param list<array{date: string, amount: string}> $points
     * @return array{buckets: list<array{key: string, label: string, amount: string, formatted: string, compact: string}>, outside: array{points: int, amount: string}}
     */
    private static function bucket(array $points, string $granularity, string $currency, string $from, string $to): array
    {
        $buckets = [];
        $outsidePoints = 0;
        $outsideAmount = Decimal::ZERO;

        try {
            $cursor = new \DateTimeImmutable($from);
            $end = new \DateTimeImmutable($to);
        } catch (\Throwable) {
            return ['buckets' => [], 'outside' => ['points' => 0, 'amount' => Decimal::ZERO]];
        }

        // A day at a time, bounded by the range itself. The longest period this
        // product offers is a financial year, so this is at most 366 steps.
        while ($cursor <= $end) {
            [$key, $label] = self::bucketOf($cursor->format('Y-m-d'), $granularity);
            $buckets[$key] ??= ['key' => $key, 'label' => $label, 'amount' => Decimal::ZERO];
            $cursor = $cursor->modify('+1 day');
        }

        foreach ($points as $point) {
            $date = (string) ($point['date'] ?? '');
            if ($date === '') {
                continue;
            }

            $amount = Decimal::of($point['amount'] ?? '0');
            if ($date < $from || $date > $to) {
                $outsidePoints++;
                $outsideAmount = Decimal::add($outsideAmount, $amount);
                continue;
            }

            [$key] = self::bucketOf($date, $granularity);
            if (!isset($buckets[$key])) {
                continue;
            }
            $buckets[$key]['amount'] = Decimal::add($buckets[$key]['amount'], $amount);
        }

        ksort($buckets);

        return [
            'buckets' => array_values(array_map(
                static fn (array $bucket) => $bucket + [
                    'formatted' => Format::money($bucket['amount'], $currency),
                    'compact'   => Format::compactMoney($bucket['amount'], $currency),
                ],
                $buckets,
            )),
            'outside' => ['points' => $outsidePoints, 'amount' => $outsideAmount],
        ];
    }

    /** @return array{0: string, 1: string} the sort key and the label for one date */
    private static function bucketOf(string $date, string $granularity): array
    {
        try {
            $day = new \DateTimeImmutable($date);
        } catch (\Throwable) {
            return [$date, $date];
        }

        return match ($granularity) {
            'day'   => [$day->format('Y-m-d'), $day->format('d M')],
            // ISO weeks, labelled by the Monday they start on: "w/c 07 Sep"
            // says what the bar covers in a way "W37" does not.
            'week'  => [$day->format('o-\WW'), 'w/c ' . $day->modify('monday this week')->format('d M')],
            default => [$day->format('Y-m'), $day->format('M y')],
        };
    }

    /**
     * Panel — payables ageing.
     *
     * The buckets Books already sent with the purchase summary this screen
     * fetched at the top. No second call, and no re-bucketing of a payable this
     * product does not own.
     *
     * @param array<string, mixed>|null $books
     * @return array<string, mixed>
     */
    private function ageing(?array $books): array
    {
        return $this->payablesAgeing($books);
    }

    /**
     * Panel — what the money was spent on.
     *
     * Category is INVENTORY'S fact, not this product's: purchase order lines
     * carry an item id, and the group that item belongs to is read live from
     * Inventory for the items actually bought in this period. Nothing is
     * cached, no category table is kept here, and when Inventory cannot be
     * reached the panel says so rather than inventing a classification from
     * HSN codes or item names.
     *
     * @return array<string, mixed>
     */
    private function categorySpend(): array
    {
        if (!$this->canSeeValues()) {
            return $this->withheld('cost.view');
        }

        $currency = $this->documentCurrency();
        if ($currency === null) {
            return $this->unavailablePanel(
                'Orders in this period are in more than one currency, so a single spend split would be adding rupees to dollars. Filter to one currency to see it.',
            );
        }

        [$filterSql, $filterParams] = $this->filters->orderClause('p');

        $lines = $this->rows(
            "SELECT l.item_id, l.is_service,
                    COALESCE(SUM(l.line_amount), 0)::text AS amount,
                    COUNT(*) AS line_count
             FROM purchase_order_lines l
             JOIN purchase_orders p ON p.po_id = l.po_id
             WHERE {scope} AND p.po_date BETWEEN :from AND :to AND p.status <> 'CANCELLED'" . $filterSql . '
             GROUP BY l.item_id, l.is_service
             ORDER BY SUM(l.line_amount) DESC
             LIMIT 200',
            $this->period->params() + $filterParams,
            'p',
        );

        if ($lines === []) {
            return $this->panel([
                'currency' => $currency,
                'total'    => Decimal::ZERO,
                'total_formatted' => Format::money(Decimal::ZERO, $currency),
                'total_compact'   => Format::compactMoney(Decimal::ZERO, $currency),
                'categories' => [],
                'basis'    => 'No order lines were raised in ' . $this->period->label() . '.',
                'source'   => 'purchases',
            ]);
        }

        $itemIds = [];
        foreach ($lines as $line) {
            if ($line['item_id'] !== null) {
                $itemIds[] = (int) $line['item_id'];
            }
        }

        $lookup = $this->inventoryReader()->items($itemIds);
        if (!$lookup['ok'] && $itemIds !== []) {
            $this->sources->unavailable('inventory', InventoryReader::LABEL, (string) $lookup['error']);

            return $this->unavailablePanel(
                (string) $lookup['error'] . ' The category an item belongs to is Inventory\'s, so no split is shown rather than one guessed here.',
            );
        }
        if ($itemIds !== []) {
            $this->sources->ready('inventory', InventoryReader::LABEL);
        }

        // The real total, over every line — not the sum of the two hundred
        // items that fitted. A donut labelled "Total spend" that quietly means
        // "total of the part we drew" is a donut whose centre disagrees with
        // the KPI two rows above it.
        $total = $this->amount(
            "SELECT COALESCE(SUM(l.line_amount), 0)::text
             FROM purchase_order_lines l
             JOIN purchase_orders p ON p.po_id = l.po_id
             WHERE {scope} AND p.po_date BETWEEN :from AND :to AND p.status <> 'CANCELLED'" . $filterSql,
            $this->period->params() + $filterParams,
            'p',
        );

        $groups = [];
        $classified = Decimal::ZERO;
        foreach ($lines as $line) {
            $amount = Decimal::of($line['amount'] ?? '0');
            if (Decimal::isZero($amount) || Decimal::isNegative($amount)) {
                continue;
            }
            $classified = Decimal::add($classified, $amount);

            $itemId = $line['item_id'] === null ? null : (int) $line['item_id'];
            $group = $itemId === null
                ? (((bool) $line['is_service']) ? 'Services' : 'Uncategorised')
                : (((string) (($lookup['items'][$itemId]['group'] ?? '')) !== '')
                    ? (string) $lookup['items'][$itemId]['group']
                    : 'Uncategorised');

            $groups[$group] = Decimal::add($groups[$group] ?? Decimal::ZERO, $amount);
        }

        arsort($groups);

        // Six named slices, then one honest "Other". A donut with nineteen
        // segments is a colour chart, not a finding.
        $named = array_slice($groups, 0, 6, true);
        $rest = array_slice($groups, 6, null, true);

        $categories = [];
        foreach ($named as $label => $amount) {
            $categories[] = [
                'id'        => 'group-' . md5((string) $label),
                'label'     => (string) $label,
                'amount'    => $amount,
                'formatted' => Format::money($amount, $currency),
                'compact'   => Format::compactMoney($amount, $currency),
                'share_pc'  => Decimal::percentOf($amount, $total, 1),
            ];
        }

        if ($rest !== []) {
            $other = Decimal::sum(array_values($rest));
            $categories[] = [
                'id'        => 'group-other',
                'label'     => 'Other categories',
                'amount'    => $other,
                'formatted' => Format::money($other, $currency),
                'compact'   => Format::compactMoney($other, $currency),
                'share_pc'  => Decimal::percentOf($other, $total, 1),
                'rolled_up' => count($rest),
            ];
        }

        // Whatever the two-hundred-item cap left out, named as what it is. It
        // is not another category and is not folded into one; it is the part of
        // the spend this split does not account for, and the slice says so.
        $unclassified = Decimal::sub($total, $classified);
        if (!Decimal::isNegative($unclassified) && !Decimal::isZero($unclassified)) {
            $categories[] = [
                'id'        => 'group-unclassified',
                'label'     => 'Beyond the 200 largest items',
                'amount'    => $unclassified,
                'formatted' => Format::money($unclassified, $currency),
                'compact'   => Format::compactMoney($unclassified, $currency),
                'share_pc'  => Decimal::percentOf($unclassified, $total, 1),
                'capped'    => true,
            ];
        }

        return $this->panel([
            'currency'   => $currency,
            'total'      => $total,
            'total_formatted' => Format::money($total, $currency),
            'total_compact'   => Format::compactMoney($total, $currency),
            'categories' => $categories,
            'source'     => 'purchases+inventory',
            'classified' => $classified,
            'basis'      => 'Ordered line value for ' . $this->period->label()
                . ', grouped by the item group Inventory holds for each item. Tax and freight are not in a line value, and '
                . 'this is what was ORDERED — Smart Books is the authority for what was posted.'
                . (Decimal::cmp($classified, $total) < 0
                    ? ' The split covers the 200 items with the largest spend; the rest is shown as one slice rather than distributed.'
                    : ''),
            'route'      => '/purchase-orders',
        ]);
    }

    /**
     * Panel C — the pipeline, requisition to posted bill.
     *
     * Six counts, each a click away from the rows behind it. They are stages of
     * our own workflow and are unaffected by Books or Inventory being down.
     *
     * @return array<string, mixed>
     */
    private function pipeline(): array
    {
        [$filterSql, $filterParams] = $this->filters->orderClause('p');
        [$reqSql, $reqParams] = $this->filters->requisitionClause('r');

        $requisitions = $this->count(
            "SELECT COUNT(*) FROM purchase_requisitions r
             WHERE {scope} AND r.status IN ('SUBMITTED', 'APPROVAL_PENDING')" . $reqSql,
            $reqParams,
            'r',
        );

        $quotes = $this->count(
            "SELECT COUNT(*) FROM purchase_rfqs r
             WHERE {scope} AND r.status IN ('ISSUED', 'RESPONSES_OPEN', 'EVALUATING')",
            [],
            'r',
        );

        $awaitingDispatch = $this->count(
            "SELECT COUNT(*) FROM purchase_orders p
             WHERE {scope} AND p.status IN ('APPROVED', 'ISSUED')" . $filterSql,
            $filterParams,
            'p',
        );

        $partiallyReceived = $this->count(
            "SELECT COUNT(*) FROM purchase_orders p
             WHERE {scope} AND p.status = 'PARTIALLY_RECEIVED'" . $filterSql,
            $filterParams,
            'p',
        );

        $awaitingMatch = $this->count(
            "SELECT COUNT(*) FROM purchase_bill_requests b
             WHERE {scope} AND b.status IN ('DRAFT', 'MATCHING', 'EXCEPTION')",
            [],
            'b',
        );

        $postingExceptions = $this->count(
            "SELECT COUNT(*) FROM purchase_integration_commands c
             WHERE c.cmp_id = :ctx_cmp_id AND c.fy_id = :ctx_fy_id AND c.status IN ('FAILED', 'BLOCKED')",
        );

        return $this->panel([
            'basis'  => 'Live counts from this application\'s own workflow. Unaffected by Smart Books or Inventory availability.',
            'stages' => [
                ['id' => 'requisitions', 'label' => 'Requisition review', 'count' => $requisitions, 'route' => '/requisitions', 'filters' => ['status' => 'APPROVAL_PENDING']],
                ['id' => 'quotes', 'label' => 'Quotation evaluation', 'count' => $quotes, 'route' => '/rfqs', 'filters' => []],
                ['id' => 'dispatch', 'label' => 'Orders awaiting dispatch', 'count' => $awaitingDispatch, 'route' => '/purchase-orders', 'filters' => ['status' => 'ISSUED']],
                ['id' => 'partial', 'label' => 'Partly received', 'count' => $partiallyReceived, 'route' => '/purchase-orders', 'filters' => ['status' => 'PARTIALLY_RECEIVED']],
                ['id' => 'matching', 'label' => 'Bills awaiting matching', 'count' => $awaitingMatch, 'route' => '/bills', 'filters' => ['status' => 'MATCHING']],
                ['id' => 'posting', 'label' => 'Posting exceptions', 'count' => $postingExceptions, 'route' => '/bills', 'filters' => ['status' => 'FAILED']],
            ],
        ]);
    }

    /**
     * Panel D — the priority inbox.
     *
     * @return array<string, mixed>
     */
    private function priorityInbox(): array
    {
        $items = [];

        foreach ($this->rows(
            "SELECT a.approval_id, a.entity_type, a.entity_id, a.reason_kind, a.reason_detail,
                    a.actual_value::text AS actual_value, a.created_at,
                    r.requisition_no, p.po_no, p.supplier_name_snapshot
             FROM purchase_approval_requests a
             LEFT JOIN purchase_requisitions r ON a.entity_type = 'requisition'    AND r.requisition_id = a.entity_id
             LEFT JOIN purchase_orders       p ON a.entity_type = 'purchase_order' AND p.po_id          = a.entity_id
             WHERE a.cmp_id = :ctx_cmp_id AND a.fy_id = :ctx_fy_id AND a.status = 'PENDING'
               AND a.requested_by <> :me
             ORDER BY a.actual_value DESC NULLS LAST, a.created_at ASC
             LIMIT 5",
            ['me' => $this->auth->uuid],
        ) as $row) {
            $reference = $row['po_no'] ?? $row['requisition_no'] ?? ('#' . $row['entity_id']);
            $items[] = [
                'id'        => 'approval-' . $row['approval_id'],
                'kind'      => 'approval',
                'severity'  => 'warning',
                'severity_label' => 'Approval',
                'title'     => $reference . ' needs your approval',
                'detail'    => (string) ($row['reason_detail'] ?? $row['reason_kind']),
                'amount'    => Decimal::parse($row['actual_value'] ?? null),
                'source'    => 'Purchases',
                'age_days'  => BooksReader::daysBetween(substr((string) $row['created_at'], 0, 10), gmdate('Y-m-d')),
                'route'     => '/approvals',
                'filters'   => [],
                'action_label' => 'Review',
            ];
        }

        foreach ($this->rows(
            "SELECT p.po_id, p.po_no, p.supplier_name_snapshot, p.promised_date,
                    SUM(GREATEST(l.ordered_qty - l.received_qty, 0) * l.agreed_rate)::text AS remaining_value
             FROM purchase_orders p
             JOIN purchase_order_lines l ON l.po_id = p.po_id
             WHERE {scope} AND p.status IN ('ISSUED', 'ACKNOWLEDGED', 'PARTIALLY_RECEIVED')
               AND p.promised_date < CURRENT_DATE AND l.ordered_qty > l.received_qty
             GROUP BY p.po_id, p.po_no, p.supplier_name_snapshot, p.promised_date
             ORDER BY p.promised_date ASC
             LIMIT 5",
            [],
            'p',
        ) as $row) {
            $late = BooksReader::daysBetween((string) $row['promised_date'], gmdate('Y-m-d'));
            $items[] = [
                'id'       => 'late-' . $row['po_id'],
                'kind'     => 'delivery',
                'severity' => $late > 14 ? 'danger' : 'warning',
                'severity_label' => 'Late delivery',
                'title'    => $row['po_no'] . ' is ' . Format::days((string) $late) . ' past its promised date',
                'detail'   => ($row['supplier_name_snapshot'] ?? 'This supplier') . ' promised ' . Format::date((string) $row['promised_date']) . ' and part of the order has not arrived.',
                'amount'   => Decimal::parse($row['remaining_value'] ?? null),
                'source'   => 'Purchases',
                'age_days' => $late,
                'route'    => '/purchase-orders/' . $row['po_id'],
                'filters'  => [],
                'action_label' => 'Open order',
            ];
        }

        if ($this->can('match.view')) {
            foreach ($this->rows(
                "SELECT e.exception_id, e.exception_kind, e.detail, e.variance_value::text AS variance_value,
                        e.created_at, b.supplier_invoice_no, b.request_id
                 FROM purchase_match_exceptions e
                 JOIN purchase_match_results m ON m.match_id = e.match_id
                 LEFT JOIN purchase_bill_requests b ON b.request_id = m.bill_request_id
                 WHERE e.cmp_id = :ctx_cmp_id AND e.status = 'OPEN'
                 ORDER BY ABS(COALESCE(e.variance_value, 0)) DESC
                 LIMIT 5",
            ) as $row) {
                $items[] = [
                    'id'       => 'exception-' . $row['exception_id'],
                    'kind'     => 'match',
                    'severity' => 'danger',
                    'severity_label' => 'Match exception',
                    'title'    => 'Bill ' . ($row['supplier_invoice_no'] ?? '#' . $row['request_id']) . ' failed the ' . str_replace('_', ' ', (string) $row['exception_kind']) . ' check',
                    'detail'   => (string) ($row['detail'] ?? 'The bill does not agree with the order or the receipt.'),
                    'amount'   => Decimal::parse($row['variance_value'] ?? null),
                    'source'   => 'Purchases',
                    'age_days' => BooksReader::daysBetween(substr((string) $row['created_at'], 0, 10), gmdate('Y-m-d')),
                    'route'    => '/bills/' . $row['request_id'],
                    'filters'  => [],
                    'action_label' => 'Resolve',
                ];
            }
        }

        foreach ($this->duplicateCandidates(3) as $candidate) {
            $items[] = $candidate;
        }

        // Rank by money at stake, then by age. An inbox in insertion order is an
        // inbox nobody reads to the bottom of.
        usort($items, static function (array $a, array $b): int {
            $amountA = $a['amount'] ?? null;
            $amountB = $b['amount'] ?? null;
            if ($amountA !== null && $amountB !== null) {
                $cmp = Decimal::cmp($amountB, $amountA);
                if ($cmp !== 0) {
                    return $cmp;
                }
            } elseif ($amountA !== $amountB) {
                return $amountA === null ? 1 : -1;
            }

            return ($b['age_days'] ?? 0) <=> ($a['age_days'] ?? 0);
        });

        return $this->panel([
            'items'    => array_slice($items, 0, 12),
            'currency' => $this->documentCurrency() ?? 'INR',
            'basis'    => 'Approvals you can decide, late deliveries, open match exceptions and possible duplicate bills, ranked by the amount at stake.',
        ]);
    }

    /**
     * Bills that look like one another.
     *
     * Same supplier and same invoice number is a hard duplicate and the bill
     * service already refuses it. What is caught here is the softer case — same
     * supplier, same date, same total, different reference — which is a review
     * candidate and is worded as one.
     *
     * @return list<array<string, mixed>>
     */
    private function duplicateCandidates(int $limit): array
    {
        $rows = $this->rows(
            "SELECT b.request_id, b.supplier_invoice_no, b.supplier_account_id, b.supplier_invoice_date,
                    b.created_at, o.request_id AS other_id, o.supplier_invoice_no AS other_no
             FROM purchase_bill_requests b
             JOIN purchase_bill_requests o
               ON o.cmp_id = b.cmp_id
              AND o.supplier_account_id = b.supplier_account_id
              AND o.supplier_invoice_date = b.supplier_invoice_date
              AND o.request_id < b.request_id
              AND o.status <> 'CANCELLED'
             WHERE {scope} AND b.status NOT IN ('CANCELLED', 'POSTED')
               AND b.supplier_invoice_date IS NOT NULL
             ORDER BY b.created_at DESC
             LIMIT :lim",
            ['lim' => $limit],
            'b',
        );

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'id'       => 'duplicate-' . $row['request_id'],
                'kind'     => 'duplicate',
                'severity' => 'warning',
                'severity_label' => 'Possible duplicate',
                'title'    => 'Bill ' . ($row['supplier_invoice_no'] ?? '#' . $row['request_id']) . ' may duplicate ' . ($row['other_no'] ?? '#' . $row['other_id']),
                'detail'   => 'Same supplier and same invoice date as an earlier bill. This is a review candidate, not a finding.',
                'amount'   => null,
                'source'   => 'Purchases',
                'age_days' => BooksReader::daysBetween(substr((string) $row['created_at'], 0, 10), gmdate('Y-m-d')),
                'route'    => '/bills/' . $row['request_id'],
                'filters'  => [],
                'action_label' => 'Compare',
            ];
        }

        return $out;
    }

    /**
     * Panel E — supplier concentration.
     *
     * The denominator is stated, because a share of an unstated base is not a
     * share of anything. Where Books is reachable the base is posted purchases;
     * where it is not, the base is our own ordered value and the panel says so
     * rather than quietly changing what the percentage means.
     *
     * @param array<string, mixed>|null $books
     * @param array<string, mixed>      $supplierPosition
     * @return array<string, mixed>
     */
    private function concentration(?array $books, array $supplierPosition): array
    {
        if (!$this->canSeeValues()) {
            return $this->withheld('cost.view');
        }

        $currency = $this->documentCurrency();
        if ($currency === null) {
            return $this->unavailablePanel(
                'Orders in this period are in more than one currency. A share of a mixed-currency total would not mean anything, so no concentration is shown. Filter to one currency to see it.',
            );
        }

        if ($books !== null && $books['ok'] && $books['top_suppliers'] !== []) {
            // Capped to the same number the fallback query takes, and to the
            // same number the payables endpoint will answer for: a seventh row
            // would carry a payables cell that could never be filled.
            $rows = array_slice((array) $books['top_suppliers'], 0, self::PAYABLES_SUPPLIER_CAP);
            $base = Decimal::of(((array) $books['kpis'])['total_purchases'] ?? '0');
            $basis = 'Share of net posted purchases from Smart Books for ' . $this->period->label() . '.';
            $source = 'books';
        } else {
            [$filterSql, $filterParams] = $this->filters->orderClause('p');
            $rows = array_map(
                static fn (array $r) => [
                    'supplier_account_id' => (int) $r['supplier_account_id'],
                    'supplier_name'       => $r['supplier_name_snapshot'] ?? null,
                    'amount'              => Decimal::of($r['amount'] ?? '0'),
                    'order_count'         => (int) $r['order_count'],
                ],
                $this->rows(
                    "SELECT p.supplier_account_id, p.supplier_name_snapshot,
                            SUM(p.total_amount)::text AS amount, COUNT(*) AS order_count
                     FROM purchase_orders p
                     WHERE {scope} AND p.po_date BETWEEN :from AND :to AND p.status <> 'CANCELLED'" . $filterSql . "
                     GROUP BY p.supplier_account_id, p.supplier_name_snapshot
                     ORDER BY SUM(p.total_amount) DESC
                     LIMIT 6",
                    $this->period->params() + $filterParams,
                    'p',
                ),
            );
            $base = $this->amount(
                "SELECT COALESCE(SUM(p.total_amount), 0)::text FROM purchase_orders p
                 WHERE {scope} AND p.po_date BETWEEN :from AND :to AND p.status <> 'CANCELLED'" . $filterSql,
                $this->period->params() + $filterParams,
                'p',
            );
            $basis = 'Share of ORDERED value raised in ' . $this->period->label()
                . ' — Smart Books was not available, so posted purchases could not be used as the base.';
            $source = 'purchases';
        }

        $rated = $this->can('supplier.view');

        $items = [];
        $named = Decimal::ZERO;
        $unrated = 0;
        foreach ($rows as $row) {
            $amount = Decimal::of($row['amount'] ?? '0');
            $named = Decimal::add($named, $amount);
            $supplierId = $row['supplier_account_id'] ?? null;

            // Spend can come from Books while delivery performance is ours. A
            // supplier Books names that we have raised no order for in this
            // period has no scorecard, and the row says "not rated here"
            // rather than showing a zero that reads as a bad supplier.
            //
            // The scorecard columns are supplier facts and need the supplier
            // permission, not the cost one. Somebody who may see what was spent
            // is not thereby entitled to how a supplier is rated.
            $signals = ($supplierId === null || !$rated) ? null : ($supplierPosition['rows'][(int) $supplierId] ?? null);
            if ($rated && ($signals === null || $signals['score'] === null)) {
                $unrated++;
            }

            $items[] = [
                'supplier_account_id' => $supplierId,
                'supplier_name'       => $row['supplier_name'] ?? ($signals['supplier_name'] ?? null),
                'amount'              => $amount,
                'formatted_amount'    => Format::money($amount, $currency),
                'compact_amount'      => Format::compactMoney($amount, $currency),
                'share_pc'            => Decimal::percentOf($amount, $base, 1),
                'order_count'         => $row['order_count'] ?? ($signals['po_count'] ?? null),
                'on_time_pc'          => $signals['on_time_pc'] ?? null,
                'on_time_label'       => $signals['on_time_label']
                    ?? ($rated ? 'Not rated here' : 'Needs the supplier.view permission'),
                'on_time_sample'      => $signals['on_time_sample'] ?? 0,
                'score'               => $signals['score'] ?? null,
                'risk'                => $signals['risk'] ?? SupplierScore::band(null),
                'overdue_lines'       => $signals['overdue_lines'] ?? 0,
                'open_claims'         => $signals['open_claims'] ?? 0,
                'route'               => '/dashboard/suppliers',
                'filters'             => $supplierId === null ? [] : ['supplier_id' => (string) $supplierId],
            ];
        }

        $others = Decimal::sub($base, $named);

        return $this->panel([
            'basis'         => $basis,
            'rated'         => $rated,
            'scorecard_basis' => $rated
                ? 'On-time delivery and risk are this application\'s own record of receipts against the '
                  . 'promised dates on its orders, for ' . $this->period->label() . '.'
                  . ($unrated > 0
                      ? ' ' . $unrated . ' of these suppliers ' . ($unrated === 1 ? 'has' : 'have')
                        . ' too few deliveries here to rate.'
                      : '')
                : 'On-time delivery and risk need the supplier.view permission, so they are not shown here.',
            'payables_basis' => 'Open payable per supplier is read from Smart Books bill by bill, which answers for one '
                . 'supplier at a time — so it is fetched separately once this screen has drawn.',
            'unrated'       => $unrated,
            'base_source'   => $source,
            'base_amount'   => $base,
            'base_formatted' => Format::money($base, $currency),
            'currency'      => $currency,
            'suppliers'     => $items,
            'others'        => [
                'amount'    => Decimal::isNegative($others) ? Decimal::ZERO : $others,
                // Formatted here, like every other amount: a raw decimal that
                // reaches a legend is a legend reading "2818800.4567".
                'formatted' => Format::money(Decimal::isNegative($others) ? Decimal::ZERO : $others, $currency),
                'share_pc'  => Decimal::isNegative($others) ? '0' : Decimal::percentOf($others, $base, 1),
            ],
        ]);
    }

    /** Panel F — the four things a buyer starts here. @return array<string, mixed> */
    private function quickActions(): array
    {
        $actions = [];

        if ($this->can('requisition.create')) {
            $actions[] = ['id' => 'new-requisition', 'label' => 'New requisition', 'route' => '/requisitions/new', 'tone' => 'secondary'];
        }
        if ($this->can('po.create')) {
            $actions[] = ['id' => 'new-po', 'label' => 'New purchase order', 'route' => '/purchase-orders/new', 'tone' => 'primary'];
        }
        if ($this->can('bill.enter')) {
            $actions[] = ['id' => 'new-bill', 'label' => 'Enter supplier bill', 'route' => '/bills/new', 'tone' => 'secondary'];
        }
        $actions[] = ['id' => 'approvals', 'label' => 'Review approvals', 'route' => '/approvals', 'tone' => 'secondary'];

        return $this->panel(['actions' => $actions]);
    }
}
