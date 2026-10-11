<?php

declare(strict_types=1);

namespace Aicountly\Api\Ai;

use Aicountly\Api\Auth;
use Aicountly\Api\Context;
use Aicountly\Api\Dashboards\BooksReader;
use Aicountly\Api\Dashboards\Decimal;
use Aicountly\Api\Dashboards\Format;
use Aicountly\Api\Dashboards\InventoryReader;
use Aicountly\Api\Dashboards\Period;
use Aicountly\Api\Db;
use Aicountly\Api\LocalDate;
use Aicountly\Api\Permissions;

/**
 * "Ask Purchases", built out of approved questions rather than free SQL.
 *
 * A model that writes its own queries is a model with the database's
 * permissions, and no amount of prompt engineering fixes that. Here the
 * questions are a fixed catalogue: each one names the permission it needs, the
 * parameterised query behind it and how its answer is calculated. The model —
 * reached through AI Pulse, when Pulse has one for Purchases — only helps pick
 * which question was meant, and writes the sentence at the top. Everything
 * factual comes from the query.
 *
 * Without a model the keyword matcher picks the intent and the answer is
 * identical apart from the summary line, which is then marked as rules-based.
 */
final class AskEngine
{
    /**
     * The catalogue. Order matters: the keyword matcher takes the first intent
     * whose terms all appear, so narrower questions come first.
     *
     * @return list<array{id: string, question: string, description: string, permission: ?string, keywords: list<list<string>>}>
     */
    public static function catalogue(): array
    {
        return [
            [
                'id'          => 'delayed_orders',
                'question'    => 'Which orders are delayed?',
                'description' => 'Purchase orders past their promised date with quantity still to arrive.',
                'permission'  => 'po.view',
                'keywords'    => [['delay'], ['late'], ['overdue', 'order'], ['behind', 'schedule']],
            ],
            [
                'id'          => 'price_increases',
                'question'    => 'Which suppliers increased prices for the same items?',
                'description' => 'Items whose agreed rate rose between the first and the last order in the period, same unit and currency.',
                'permission'  => 'cost.view',
                'keywords'    => [['price'], ['rate', 'increase'], ['cost', 'up'], ['expensive']],
            ],
            [
                'id'          => 'bills_to_review',
                'question'    => 'Which bills need review before payment?',
                'description' => 'Bills with an open three-way match exception, and bills awaiting matching.',
                'permission'  => 'match.view',
                'keywords'    => [['bill', 'review'], ['exception'], ['mismatch'], ['bill', 'check'], ['invoice', 'review']],
            ],
            [
                'id'          => 'concentration',
                'question'    => 'What purchases are concentrated with one supplier?',
                'description' => 'Share of ordered value by supplier, and items bought from only one of them.',
                'permission'  => 'cost.view',
                'keywords'    => [['concentrat'], ['depend'], ['share', 'supplier'], ['sole', 'source'], ['single', 'supplier']],
            ],
            [
                'id'          => 'reorder',
                'question'    => 'Which reorder suggestions are supported by current stock and lead times?',
                'description' => 'Inventory\'s replenishment signal, less what is already on order here.',
                'permission'  => 'po.view',
                'keywords'    => [['reorder'], ['restock'], ['stock', 'low'], ['replenish'], ['run', 'out']],
            ],
            [
                'id'          => 'approvals',
                'question'    => 'What is waiting for approval?',
                'description' => 'Pending approval requests, and which of them you can decide.',
                'permission'  => null,
                'keywords'    => [['approv'], ['waiting', 'sign'], ['authoris'], ['authoriz']],
            ],
            [
                'id'          => 'spend',
                'question'    => 'What have we spent, and with whom?',
                'description' => 'Net posted purchases from Smart Books, with the largest suppliers.',
                'permission'  => 'reports.view',
                'keywords'    => [['spend'], ['spent'], ['purchase', 'total'], ['how', 'much'], ['cost', 'month']],
            ],
        ];
    }

    /**
     * Answer a question within the caller's own permissions and scope.
     *
     * @return array<string, mixed>
     */
    public static function answer(
        Context $ctx,
        Auth $auth,
        Period $period,
        string $question,
        ?string $forcedIntent = null,
    ): array {
        $catalogue = self::catalogue();
        $method = 'rules';
        $aiError = null;

        $intentId = $forcedIntent;
        if ($intentId !== null && !self::isKnown($intentId, $catalogue)) {
            $intentId = null;
        }

        if ($intentId === null) {
            $intentId = self::matchByKeyword($question, $catalogue);
        }

        // The model is asked only when the keywords did not decide it, and only
        // to pick from this same list — through AI Pulse, as this user.
        if ($intentId === null) {
            $classified = AiClient::classify($ctx, $auth, $question, array_map(
                static fn (array $i) => ['id' => $i['id'], 'description' => $i['description']],
                $catalogue,
            ));
            if ($classified['ok'] && $classified['intent'] !== null) {
                $intentId = $classified['intent'];
                $method = 'ai_routed';
            } else {
                $aiError = $classified['error'];
            }
        }

        if ($intentId === null) {
            return [
                'understood'  => false,
                'method'      => 'rules',
                'method_label' => 'No model was used.',
                'question'    => $question,
                'answer'      => 'That question is not one this screen can answer from your purchase records.',
                'suggestions' => array_map(static fn (array $i) => ['id' => $i['id'], 'question' => $i['question']], $catalogue),
                'scope'       => self::scope($ctx, $period),
                'sources'     => [],
                'records'     => [],
                'calculation' => null,
                'uncertainty' => $aiError ?? 'The question did not match any of the approved questions listed below.',
                'next_action' => ['label' => 'Open the AI Insights questions', 'route' => '/dashboard/ai-insights'],
            ];
        }

        $intent = self::find($intentId, $catalogue);

        if ($intent['permission'] !== null && !Permissions::allows($ctx, $auth, $intent['permission'])) {
            // Checked BEFORE retrieval, not before display: a permission test
            // that runs after the rows are fetched has already fetched them.
            return [
                'understood'  => true,
                'intent'      => $intentId,
                'method'      => 'rules',
                'method_label' => 'No model was used.',
                'question'    => $question,
                'answer'      => 'You do not have permission to see that (' . $intent['permission'] . ').',
                'scope'       => self::scope($ctx, $period),
                'sources'     => [],
                'records'     => [],
                'calculation' => null,
                'uncertainty' => null,
                'next_action' => ['label' => 'Ask an administrator for the ' . $intent['permission'] . ' permission', 'route' => null],
            ];
        }

        $result = match ($intentId) {
            'delayed_orders'  => self::delayedOrders($ctx, $period),
            'price_increases' => self::priceIncreases($ctx, $auth, $period),
            'bills_to_review' => self::billsToReview($ctx),
            'concentration'   => self::concentration($ctx, $period),
            'reorder'         => self::reorder($ctx, $auth),
            'approvals'       => self::approvals($ctx, $auth),
            'spend'           => self::spend($ctx, $auth, $period),
            default           => null,
        };

        if ($result === null) {
            return ['understood' => false, 'method' => 'rules', 'answer' => 'That question could not be answered.', 'scope' => self::scope($ctx, $period), 'records' => [], 'sources' => []];
        }

        // Said as it happened: when the model picked the question, the label says
        // so even if it wrote nothing else.
        $methodLabel = $method === 'ai_routed'
            ? 'Answered from your purchase records by a fixed rule. AI, through AI Pulse, only chose which approved question you meant.'
            : 'Answered from your purchase records by a fixed rule. No AI model was consulted.';

        // Where AI Pulse has a model, it writes a narrative over rows we already have — BESIDE the
        // factual sentence, never instead of it (F305), and only when every figure it states is
        // one the rows hold. A model sentence with a number we did not give it is dropped.
        $narrative = null;
        if ($result['records'] !== []) {
            $narrated = AiClient::narrate($ctx, $auth, $intent['question'], [
                'scope'   => self::scope($ctx, $period),
                'summary' => $result['summary_facts'] ?? [],
                'records' => array_slice($result['records'], 0, 15),
            ]);
            if ($narrated['ok']) {
                $ungrounded = self::ungroundedFigures((string) $narrated['text'], $result);
                if ($ungrounded === []) {
                    $narrative = (string) $narrated['text'];
                    $methodLabel = 'Figures and the summary come from your purchase records by a fixed rule; the "AI commentary" beneath was written by AI, through AI Pulse, from those same figures, and checked to state no figure they do not hold.'
                        . ($method === 'ai_routed' ? ' AI also chose which approved question you meant.' : '');
                    $method = 'ai_narrated';
                } else {
                    $aiError = 'The AI commentary was left out: it stated figures that are not in these records (' . implode(', ', array_slice($ungrounded, 0, 3)) . ').';
                }
            } else {
                $aiError = $narrated['error'];
            }
        }

        return [
            'understood'  => true,
            'intent'      => $intentId,
            'question'    => $question,
            'method'      => $method,
            'method_label' => $methodLabel,
            'answer'      => $result['answer'],
            'narrative'   => $narrative,
            'scope'       => self::scope($ctx, $period),
            'sources'     => $result['sources'],
            'records'     => $result['records'],
            'calculation' => $result['calculation'],
            'uncertainty' => $result['uncertainty'] ?? $aiError,
            'next_action' => $result['next_action'],
        ];
    }

    // -----------------------------------------------------------------------
    // The approved answers. Every one is a parameterised query.
    // -----------------------------------------------------------------------

    /** @return array<string, mixed> */
    private static function delayedOrders(Context $ctx, Period $period): array
    {
        [$bo, $boParams] = self::branch($ctx, 'p.bo_id');
        $rows = Db::all(
            "SELECT p.po_id, p.po_no, p.supplier_name_snapshot, p.promised_date,
                    COUNT(*) AS open_lines,
                    SUM(GREATEST(l.ordered_qty - l.received_qty, 0) * l.agreed_rate)::text AS remaining_value,
                    MAX(p.currency_code) AS currency_code
             FROM purchase_orders p
             JOIN purchase_order_lines l ON l.po_id = p.po_id
             WHERE p.cmp_id = :cmp AND p.fy_id = :fy{$bo}
               AND p.status IN ('ISSUED', 'ACKNOWLEDGED', 'PARTIALLY_RECEIVED')
               AND l.ordered_qty > l.received_qty
               AND COALESCE(l.promised_date, p.promised_date) < CURRENT_DATE
             GROUP BY p.po_id, p.po_no, p.supplier_name_snapshot, p.promised_date
             ORDER BY p.promised_date ASC
             LIMIT 25",
            ['cmp' => $ctx->cmpId, 'fy' => $ctx->fyId] + $boParams,
        );

        $today = LocalDate::today();
        $records = array_map(static fn (array $r) => [
            'reference'  => $r['po_no'],
            'supplier'   => $r['supplier_name_snapshot'],
            'promised'   => Format::date((string) $r['promised_date']),
            'days_late'  => BooksReader::daysBetween((string) $r['promised_date'], $today),
            'open_lines' => (int) $r['open_lines'],
            'remaining'  => Format::money(Decimal::of($r['remaining_value']), (string) ($r['currency_code'] ?? 'INR')),
            'route'      => '/purchase-orders/' . $r['po_id'],
        ], $rows);

        $count = count($records);

        return [
            'answer'  => $count === 0
                ? 'No purchase order in this company and financial year is past its promised date with quantity still to arrive.'
                : $count . ' order' . ($count === 1 ? ' is' : 's are') . ' past the promised date with quantity still to arrive. The oldest is '
                    . ($records[0]['reference'] ?? '') . ', ' . Format::days((string) ($records[0]['days_late'] ?? 0)) . ' late.',
            'records' => $records,
            'sources' => [['id' => 'purchases', 'label' => 'Purchases records', 'as_of' => gmdate('c')]],
            'calculation' => 'Order lines where ordered quantity exceeds received quantity, and the line\'s promised date — or the order\'s, where the line has none — is before today. Counted per order; the remaining value is remaining quantity × agreed rate, before tax.',
            'uncertainty' => 'Received quantity is this application\'s record of what Inventory confirmed. A receipt recorded in Inventory but not through this application would not be reflected.',
            'next_action' => ['label' => 'Open the procurement workbench', 'route' => '/dashboard/procurement', 'filters' => ['view' => 'delayed']],
            'summary_facts' => ['delayed_orders' => $count],
        ];
    }

    /** @return array<string, mixed> */
    private static function priceIncreases(Context $ctx, Auth $auth, Period $period): array
    {
        [$bo, $boParams] = self::branch($ctx, 'p.bo_id');
        $rows = Db::all(
            "WITH observations AS (
                SELECT l.item_id, l.unit_id, p.currency_code, l.agreed_rate, p.po_date,
                       p.supplier_account_id, p.supplier_name_snapshot,
                       ROW_NUMBER() OVER (PARTITION BY l.item_id, l.unit_id, p.currency_code, p.supplier_account_id ORDER BY p.po_date ASC,  l.line_id ASC)  AS first_seen,
                       ROW_NUMBER() OVER (PARTITION BY l.item_id, l.unit_id, p.currency_code, p.supplier_account_id ORDER BY p.po_date DESC, l.line_id DESC) AS last_seen,
                       COUNT(*)    OVER (PARTITION BY l.item_id, l.unit_id, p.currency_code, p.supplier_account_id) AS observations
                FROM purchase_order_lines l
                JOIN purchase_orders p ON p.po_id = l.po_id
                WHERE p.cmp_id = :cmp AND p.fy_id = :fy{$bo}
                  AND p.po_date BETWEEN :from AND :to
                  AND p.status <> 'CANCELLED' AND l.item_id IS NOT NULL AND l.agreed_rate > 0
            )
            SELECT f.item_id, f.supplier_account_id, f.supplier_name_snapshot, f.currency_code, f.observations,
                   f.agreed_rate::text AS first_rate, f.po_date AS first_date,
                   t.agreed_rate::text AS last_rate,  t.po_date AS last_date
            FROM observations f
            JOIN observations t
              ON t.item_id = f.item_id AND t.unit_id IS NOT DISTINCT FROM f.unit_id
             AND t.currency_code = f.currency_code AND t.supplier_account_id = f.supplier_account_id
             AND t.last_seen = 1
            WHERE f.first_seen = 1 AND f.observations >= 2 AND t.agreed_rate > f.agreed_rate
            ORDER BY (t.agreed_rate - f.agreed_rate) / f.agreed_rate DESC
            LIMIT 20",
            ['cmp' => $ctx->cmpId, 'fy' => $ctx->fyId] + $period->params() + $boParams,
        );

        // Item names come from Inventory, in one bulk call, under this user's
        // own session. When it cannot be reached the ids stand in rather than
        // the answer disappearing.
        $items = [];
        $itemIds = array_values(array_filter(array_map(static fn ($r) => (int) $r['item_id'], $rows)));
        $itemsAvailable = true;
        if ($itemIds !== []) {
            $lookup = (new InventoryReader($ctx, $auth->sesKey()))->items($itemIds);
            $items = $lookup['items'];
            $itemsAvailable = $lookup['ok'];
        }

        $records = array_map(static fn (array $r) => [
            'item'      => $items[(int) $r['item_id']]['name'] ?? ('Item ' . $r['item_id']),
            'supplier'  => $r['supplier_name_snapshot'],
            'from_rate' => Format::money(Decimal::of($r['first_rate']), (string) $r['currency_code']),
            'to_rate'   => Format::money(Decimal::of($r['last_rate']), (string) $r['currency_code']),
            'change_pc' => Decimal::percentChange(Decimal::of($r['first_rate']), Decimal::of($r['last_rate']), 1),
            'observations' => (int) $r['observations'],
            'from_date' => Format::date((string) $r['first_date']),
            'to_date'   => Format::date((string) $r['last_date']),
            'route'     => '/dashboard/suppliers',
        ], $rows);

        $count = count($records);

        return [
            'answer'  => $count === 0
                ? 'No item bought more than once from the same supplier in ' . $period->label() . ' rose in rate.'
                : $count . ' item–supplier pair' . ($count === 1 ? '' : 's') . ' rose in agreed rate during ' . $period->label()
                    . '. The largest is ' . ($records[0]['item'] ?? '') . ' from ' . ($records[0]['supplier'] ?? 'a supplier')
                    . ', up ' . Format::percent((string) ($records[0]['change_pc'] ?? '0'), 1) . '.',
            'records' => $records,
            'sources' => [
                ['id' => 'purchases', 'label' => 'Purchases order lines', 'as_of' => gmdate('c'), 'status' => 'ready'],
                ['id' => 'inventory', 'label' => InventoryReader::LABEL, 'as_of' => $itemsAvailable ? gmdate('c') : null, 'status' => $itemsAvailable ? 'ready' : 'unavailable'],
            ],
            'calculation' => 'First and last agreed rate for the same item, unit, currency and supplier, over at least two orders in the period. Rates are before line discount, freight and tax.',
            'uncertainty' => 'A rate change can reflect a different quantity break, specification or delivery term rather than a price rise. Open the orders before raising it.'
                . ($itemsAvailable ? '' : ' Inventory could not be reached, so items are shown by id rather than by name.'),
            'next_action' => ['label' => 'Open price movement', 'route' => '/dashboard/suppliers', 'filters' => ['panel' => 'price']],
            'summary_facts' => ['items_with_rate_increase' => $count, 'period' => $period->label()],
        ];
    }

    /** @return array<string, mixed> */
    private static function billsToReview(Context $ctx): array
    {
        [$bo, $boParams] = self::branch($ctx, 'COALESCE(b.bo_id, p.bo_id, 0)');
        [$boBills] = self::branch($ctx, 'bo_id');
        $rows = Db::all(
            "SELECT e.exception_id, e.exception_kind, e.detail, e.variance_value::text AS variance_value,
                    b.request_id, b.supplier_invoice_no, p.supplier_name_snapshot, p.currency_code
             FROM purchase_match_exceptions e
             JOIN purchase_match_results m ON m.match_id = e.match_id
             LEFT JOIN purchase_bill_requests b ON b.request_id = m.bill_request_id
             LEFT JOIN purchase_orders p ON p.po_id = m.po_id
             WHERE e.cmp_id = :cmp AND e.status = 'OPEN'{$bo}
             ORDER BY ABS(COALESCE(e.variance_value, 0)) DESC
             LIMIT 25",
            ['cmp' => $ctx->cmpId] + $boParams,
        );

        $awaiting = (int) Db::scalar(
            "SELECT COUNT(*) FROM purchase_bill_requests
             WHERE cmp_id = :cmp AND fy_id = :fy AND status IN ('DRAFT', 'MATCHING'){$boBills}",
            ['cmp' => $ctx->cmpId, 'fy' => $ctx->fyId] + $boParams,
        );

        $records = array_map(static fn (array $r) => [
            'reference' => $r['supplier_invoice_no'] ?? ('#' . $r['request_id']),
            'supplier'  => $r['supplier_name_snapshot'],
            'rule'      => str_replace('_', ' ', (string) $r['exception_kind']),
            'detail'    => $r['detail'],
            'variance'  => $r['variance_value'] === null ? null : Format::money(Decimal::of($r['variance_value']), (string) ($r['currency_code'] ?? 'INR')),
            'route'     => '/bills/' . $r['request_id'],
        ], $rows);

        $count = count($records);

        return [
            'answer'  => $count === 0 && $awaiting === 0
                ? 'No bill is held by a match exception, and none are waiting to be matched.'
                : $count . ' bill' . ($count === 1 ? '' : 's') . ' ' . ($count === 1 ? 'has' : 'have') . ' an open match exception'
                    . ($awaiting > 0 ? ', and ' . $awaiting . ' more ' . ($awaiting === 1 ? 'is' : 'are') . ' waiting to be matched' : '')
                    . '. None of them reach Smart Books until somebody decides.',
            'records' => $records,
            'sources' => [['id' => 'purchases', 'label' => 'Purchases match results', 'as_of' => gmdate('c')]],
            'calculation' => 'Open exceptions from the three-way match: the purchase order against the goods receipt Inventory recorded against the bill as entered, at line level and within the configured tolerances.',
            'uncertainty' => 'Where Inventory could not be reached at match time the verdict is REVIEW_REQUIRED rather than MATCHED — "we could not check" is never recorded as "we checked and it was fine".',
            'next_action' => ['label' => 'Open the matching workbench', 'route' => '/dashboard/bills-payables', 'filters' => ['panel' => 'matching']],
            'summary_facts' => ['open_exceptions' => $count, 'awaiting_match' => $awaiting],
        ];
    }

    /** @return array<string, mixed> */
    private static function concentration(Context $ctx, Period $period): array
    {
        // The same base and scope as the dashboards (F028): ordered value of every issued
        // order in the period in the person's branch scope — not the sum of the ten listed, which
        // overstated every share.
        [$bo, $boParams] = self::branch($ctx, 'p.bo_id');
        $params = ['cmp' => $ctx->cmpId, 'fy' => $ctx->fyId] + $period->params() + $boParams;
        $where = "p.cmp_id = :cmp AND p.fy_id = :fy{$bo} AND p.po_date BETWEEN :from AND :to AND p.status IN ('ISSUED', 'ACKNOWLEDGED', 'PARTIALLY_RECEIVED', 'RECEIVED', 'CLOSED')";
        $rows = Db::all(
            "SELECT p.supplier_account_id, p.supplier_name_snapshot,
                    SUM(p.total_amount)::text AS value, COUNT(*) AS orders,
                    COUNT(DISTINCT p.currency_code) AS currencies, MAX(p.currency_code) AS currency_code
             FROM purchase_orders p
             WHERE {$where}
             GROUP BY p.supplier_account_id, p.supplier_name_snapshot
             ORDER BY SUM(p.total_amount) DESC
             LIMIT 10",
            $params,
        );
        $base = Db::first(
            "SELECT COALESCE(SUM(p.total_amount), 0)::text AS total, COUNT(DISTINCT p.currency_code) AS currencies, MAX(p.currency_code) AS currency_code
             FROM purchase_orders p WHERE {$where}",
            $params,
        ) ?? ['total' => '0', 'currencies' => 0, 'currency_code' => 'INR'];

        $total = Decimal::of($base['total']);
        $currency = (string) ($base['currency_code'] ?? 'INR');
        $mixed = (int) $base['currencies'] > 1;

        $records = array_map(static fn (array $r) => [
            'supplier' => $r['supplier_name_snapshot'] ?? ('Account ' . $r['supplier_account_id']),
            'value'    => Format::money(Decimal::of($r['value']), (string) ($r['currency_code'] ?? 'INR')),
            'share_pc' => Decimal::percentOf(Decimal::of($r['value']), $total, 1),
            'orders'   => (int) $r['orders'],
            'route'    => '/suppliers',
        ], $rows);

        $topShare = $records[0]['share_pc'] ?? null;

        return [
            'answer'  => $records === []
                ? 'No issued orders were raised in ' . $period->label() . ', so there is no concentration to report.'
                : ($records[0]['supplier'] ?? 'The largest supplier') . ' accounts for '
                    . ($topShare === null ? 'an unstated share' : Format::percent($topShare, 1))
                    . ' of ' . Format::money($total, $currency) . ' ordered in ' . $period->label() . '.',
            'records' => $records,
            'sources' => [['id' => 'purchases', 'label' => 'Purchases orders', 'as_of' => gmdate('c')]],
            'calculation' => 'Ordered value per supplier as a share of the total ordered value of every issued order in the period (not just the suppliers listed; drafts and orders awaiting approval are not counted), in the branch scope shown — the same base the dashboards use. The base is ordered value, not posted purchases — it measures commitment, which is what a buyer can still act on.',
            'uncertainty' => $mixed
                ? 'Orders in this period are in more than one currency. The shares combine amounts that are not comparable; treat them as indicative only, or filter to one currency on the dashboard.'
                : null,
            'next_action' => ['label' => 'Open supplier concentration', 'route' => '/dashboard/suppliers', 'filters' => ['panel' => 'concentration']],
            'summary_facts' => ['supplier_count' => count($records), 'top_share_pc' => $topShare, 'base_total' => $total],
        ];
    }

    /** @return array<string, mixed> */
    private static function reorder(Context $ctx, Auth $auth): array
    {
        $reader = new InventoryReader($ctx, $auth->sesKey());
        $signal = $reader->replenishment(null, 20);

        if (!$signal['ok']) {
            return [
                'answer'  => 'Inventory could not be reached, so there is no reorder signal to check. ' . (string) $signal['error'],
                'records' => [],
                'sources' => [['id' => 'inventory', 'label' => InventoryReader::LABEL, 'as_of' => null, 'status' => 'unavailable']],
                'calculation' => null,
                'uncertainty' => 'Nothing is estimated from purchase history in place of live stock: a reorder suggestion built on the wrong stock figure is worse than no suggestion.',
                'next_action' => ['label' => 'Open reorder review', 'route' => '/dashboard/procurement', 'filters' => ['panel' => 'reorder']],
            ];
        }

        $rows = $signal['rows'];
        $itemIds = array_map(static fn ($r) => (int) $r['item_id'], $rows);
        $onOrder = [];

        if ($itemIds !== []) {
            $placeholders = [];
            $params = ['cmp' => $ctx->cmpId, 'fy' => $ctx->fyId] + self::branch($ctx, 'p.bo_id')[1];
            foreach (array_values(array_unique($itemIds)) as $index => $itemId) {
                $placeholders[] = ':item' . $index;
                $params['item' . $index] = $itemId;
            }
            foreach (Db::all(
                "SELECT l.item_id, COALESCE(SUM(GREATEST(l.ordered_qty - l.received_qty, 0)), 0)::text AS on_order
                 FROM purchase_order_lines l
                 JOIN purchase_orders p ON p.po_id = l.po_id
                 WHERE p.cmp_id = :cmp AND p.fy_id = :fy" . self::branch($ctx, 'p.bo_id')[0] . "
                   AND p.status IN ('ISSUED', 'ACKNOWLEDGED', 'PARTIALLY_RECEIVED')
                   AND l.item_id IN (" . implode(', ', $placeholders) . ')
                 GROUP BY l.item_id',
                $params,
            ) as $row) {
                $onOrder[(int) $row['item_id']] = Decimal::of($row['on_order']);
            }
        }

        $records = [];
        $supported = 0;
        foreach ($rows as $row) {
            $itemId = (int) $row['item_id'];
            $ordered = $onOrder[$itemId] ?? Decimal::ZERO;
            $suggested = $row['suggested_qty'];
            $net = $suggested === null ? null : Decimal::sub($suggested, $ordered);
            if ($net !== null && Decimal::isNegative($net)) {
                $net = Decimal::ZERO;
            }
            $isSupported = $net !== null && !Decimal::isZero($net) && $row['lead_days'] !== null;
            if ($isSupported) {
                $supported++;
            }

            $records[] = [
                'item'      => $row['item_name'] ?? ('Item ' . $itemId),
                'available' => $row['available_qty'] === null ? 'Not reported' : Format::quantity($row['available_qty'], $row['uom']),
                'on_order'  => Format::quantity($ordered, $row['uom']),
                'lead_days' => $row['lead_days'],
                'suggested' => $net === null ? 'No suggestion' : Format::quantity($net, $row['uom']),
                'supported' => $isSupported,
                'route'     => '/dashboard/procurement',
            ];
        }

        return [
            'answer'  => $supported === 0
                ? 'Inventory reported ' . count($records) . ' item' . (count($records) === 1 ? '' : 's') . ' as short, but none has both a suggested quantity and a lead time, so none is fully supported yet.'
                : $supported . ' of ' . count($records) . ' reorder suggestion' . (count($records) === 1 ? '' : 's') . ' has live stock, a suggested quantity and a lead time behind it, after deducting what is already on order.',
            'records' => $records,
            'sources' => [
                ['id' => 'inventory', 'label' => InventoryReader::LABEL, 'as_of' => $signal['as_of'], 'status' => 'ready'],
                ['id' => 'purchases', 'label' => 'Purchases open orders', 'as_of' => gmdate('c'), 'status' => 'ready'],
            ],
            'calculation' => 'Inventory\'s replenishment report, with the quantity already on unreceived purchase orders subtracted. A suggestion counts as supported when it has a suggested quantity, a lead time, and a remaining need after that subtraction.',
            'uncertainty' => 'Suggestions are drafts for review. Nothing here creates a requisition or an order, and the consumption assumptions behind the signal are Inventory\'s.',
            'next_action' => ['label' => 'Review reorder drafts', 'route' => '/dashboard/procurement', 'filters' => ['panel' => 'reorder']],
            'summary_facts' => ['short_items' => count($records), 'supported' => $supported],
        ];
    }

    /** @return array<string, mixed> */
    private static function approvals(Context $ctx, Auth $auth): array
    {
        $granted = Permissions::granted($ctx, $auth);
        $placeholders = [];
        [$bo, $boParams] = self::branch($ctx, 'COALESCE(p.bo_id, r.bo_id, 0)');
        $params = ['cmp' => $ctx->cmpId, 'fy' => $ctx->fyId, 'me' => $auth->uuid] + $boParams;
        foreach (array_values($granted) as $index => $permission) {
            $placeholders[] = ':perm' . $index;
            $params['perm' . $index] = $permission;
        }
        $mineClause = $granted === []
            ? ' AND FALSE'
            : ' AND a.requested_by <> :me AND (a.required_permission IS NULL OR a.required_permission IN (' . implode(', ', $placeholders) . '))';

        $rows = Db::all(
            "SELECT a.approval_id, a.entity_type, a.entity_id, a.reason_detail, a.reason_kind,
                    a.actual_value::text AS actual_value, a.created_at,
                    r.requisition_no, p.po_no, p.supplier_name_snapshot, p.currency_code
             FROM purchase_approval_requests a
             LEFT JOIN purchase_requisitions r ON a.entity_type = 'requisition'    AND r.requisition_id = a.entity_id
             LEFT JOIN purchase_orders       p ON a.entity_type = 'purchase_order' AND p.po_id          = a.entity_id
             WHERE a.cmp_id = :cmp AND a.fy_id = :fy AND a.status = 'PENDING'" . $bo . $mineClause . '
             ORDER BY a.created_at ASC LIMIT 25',
            $params,
        );

        $total = (int) Db::scalar(
            "SELECT COUNT(*) FROM purchase_approval_requests a
               LEFT JOIN purchase_requisitions r ON a.entity_type = 'requisition'    AND r.requisition_id = a.entity_id
               LEFT JOIN purchase_orders       p ON a.entity_type = 'purchase_order' AND p.po_id          = a.entity_id
              WHERE a.cmp_id = :cmp AND a.fy_id = :fy AND a.status = 'PENDING'" . $bo,
            ['cmp' => $ctx->cmpId, 'fy' => $ctx->fyId] + $boParams,
        );

        $records = array_map(static fn (array $r) => [
            'reference' => $r['po_no'] ?? $r['requisition_no'] ?? ('#' . $r['entity_id']),
            'type'      => str_replace('_', ' ', (string) $r['entity_type']),
            'supplier'  => $r['supplier_name_snapshot'],
            'reason'    => $r['reason_detail'] ?? $r['reason_kind'],
            'value'     => $r['actual_value'] === null ? null : Format::money(Decimal::of($r['actual_value']), (string) ($r['currency_code'] ?? 'INR')),
            'waiting_days' => BooksReader::daysBetween(substr((string) $r['created_at'], 0, 10), gmdate('Y-m-d')),
            'route'     => $r['entity_type'] === 'purchase_order' ? '/purchase-orders/' . $r['entity_id'] : '/requisitions/' . $r['entity_id'],
        ], $rows);

        $mine = count($records);

        return [
            'answer'  => $total === 0
                ? 'Nothing is waiting for approval in this company and financial year.'
                : $total . ' document' . ($total === 1 ? ' is' : 's are') . ' waiting for approval, of which '
                    . ($mine === 0 ? 'none is yours to decide' : $mine . ($mine === 1 ? ' is' : ' are') . ' yours to decide') . '.',
            'records' => $records,
            'sources' => [['id' => 'purchases', 'label' => 'Purchases approval requests', 'as_of' => gmdate('c')]],
            'calculation' => 'Pending approval requests. "Yours to decide" means the stage\'s required permission is one you hold and you did not raise the document — nobody approves their own, whatever permissions they have.',
            'uncertainty' => null,
            'next_action' => ['label' => 'Open approvals', 'route' => '/approvals'],
            'summary_facts' => ['pending_total' => $total, 'pending_mine' => $mine],
        ];
    }

    /** @return array<string, mixed> */
    private static function spend(Context $ctx, Auth $auth, Period $period): array
    {
        $books = (new BooksReader($ctx, $auth->sesKey()))->purchaseSummary($period);

        if (!$books['ok']) {
            return [
                'answer'  => 'Smart Books could not be reached, so posted purchases for ' . $period->label() . ' cannot be stated. ' . (string) $books['error'],
                'records' => [],
                'sources' => [['id' => 'books', 'label' => BooksReader::LABEL, 'as_of' => null, 'status' => 'unavailable']],
                'calculation' => null,
                'uncertainty' => 'This application holds no purchase total of its own. What it could show instead is ordered value, which is a commitment and not a posted purchase — the two are different figures and are not substituted for one another.',
                'next_action' => ['label' => 'Open the overview', 'route' => '/dashboard/overview'],
            ];
        }

        $kpis = (array) $books['kpis'];
        $currency = 'INR';
        $records = array_map(static fn (array $r) => [
            'supplier' => $r['supplier_name'] ?? ('Account ' . ($r['supplier_account_id'] ?? '?')),
            'value'    => Format::money((string) $r['amount'], $currency),
            'route'    => '/suppliers',
        ], (array) $books['top_suppliers']);

        return [
            'answer'  => 'Net posted purchases for ' . $period->label() . ' are '
                . Format::money($kpis['total_purchases'] ?? '0', $currency) . ', after '
                . Format::money($kpis['purchase_returns'] ?? '0', $currency) . ' of returns. Input GST in the period is '
                . Format::money($kpis['input_gst'] ?? '0', $currency) . '.',
            'records' => $records,
            'sources' => [['id' => 'books', 'label' => BooksReader::LABEL, 'as_of' => gmdate('c'), 'status' => 'ready']],
            'calculation' => 'Posted purchase vouchers less posted debit notes for the period, inclusive of tax, on the accounting date. Smart Books is the authority for this figure; this application stores no copy of it.',
            'uncertainty' => null,
            'next_action' => ['label' => 'Open the overview', 'route' => '/dashboard/overview'],
            'summary_facts' => [
                'net_purchases' => Format::money($kpis['total_purchases'] ?? '0', $currency),
                'period'        => $period->label(),
            ],
        ];
    }

    // -----------------------------------------------------------------------

    /** @param list<array<string, mixed>> $catalogue */
    private static function matchByKeyword(string $question, array $catalogue): ?string
    {
        $text = mb_strtolower(trim($question));
        if ($text === '') {
            return null;
        }

        foreach ($catalogue as $intent) {
            foreach ($intent['keywords'] as $group) {
                $all = true;
                foreach ($group as $term) {
                    if (!str_contains($text, $term)) {
                        $all = false;
                        break;
                    }
                }
                if ($all) {
                    return $intent['id'];
                }
            }
        }

        return null;
    }

    /** @param list<array<string, mixed>> $catalogue */
    private static function isKnown(string $id, array $catalogue): bool
    {
        foreach ($catalogue as $intent) {
            if ($intent['id'] === $id) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<array<string, mixed>> $catalogue
     * @return array<string, mixed>
     */
    private static function find(string $id, array $catalogue): array
    {
        foreach ($catalogue as $intent) {
            if ($intent['id'] === $id) {
                return $intent;
            }
        }

        return $catalogue[0];
    }

    /**
     * The figures in a model's text that the answer does not hold (F305). Every number the text
     * states — "45.2", "1,905", "3" — must appear among the answer's own sentence, records or
     * summary facts, compared without grouping separators.
     *
     * @param array<string, mixed> $result
     * @return list<string>
     */
    public static function ungroundedFigures(string $text, array $result): array
    {
        $haystack = json_encode([$result['answer'] ?? '', $result['records'] ?? [], $result['summary_facts'] ?? []], JSON_UNESCAPED_UNICODE) ?: '';
        $known = [];
        if (preg_match_all('/\d[\d,]*(?:\.\d+)?/', $haystack, $m) > 0) {
            foreach ($m[0] as $n) {
                $plain = str_replace(',', '', $n);
                $known[$plain] = true;
                if (str_contains($plain, '.')) {
                    $known[rtrim(rtrim($plain, '0'), '.')] = true;
                }
            }
        }
        $missing = [];
        if (preg_match_all('/\d[\d,]*(?:\.\d+)?/', $text, $m) > 0) {
            foreach ($m[0] as $n) {
                $plain = rtrim(str_replace(',', '', $n), '.');
                $trimmed = str_contains($plain, '.') ? rtrim(rtrim($plain, '0'), '.') : $plain;
                if (!isset($known[$plain]) && !isset($known[$trimmed])) {
                    $missing[] = $n;
                }
            }
        }

        return array_values(array_unique($missing));
    }

    /**
     * The branch a branch-scoped person sees, the same rule as the dashboards' scopeClause: their
     * branch and company-level (bo_id 0) records. Nothing for a company-level context (F028: the
     * answers used to include every branch while the panel said "Branch N").
     *
     * @return array{0: string, 1: array<string, int>}
     */
    private static function branch(Context $ctx, string $column): array
    {
        if ($ctx->boId <= 0) {
            return ['', []];
        }

        return [' AND (' . $column . ' = :ask_bo_id OR ' . $column . ' = 0)', ['ask_bo_id' => $ctx->boId]];
    }

    /** @return array<string, mixed> */
    private static function scope(Context $ctx, Period $period): array
    {
        return [
            'company_id'        => $ctx->cmpId,
            'financial_year_id' => $ctx->fyId,
            'branch_id'         => $ctx->boId,
            'branch_label'      => $ctx->boId > 0 ? 'Branch ' . $ctx->boId : 'All branches',
            'from'              => $period->from,
            'to'                => $period->to,
            'period_label'      => $period->label(),
            'timezone'          => $period->timezone,
        ];
    }
}
