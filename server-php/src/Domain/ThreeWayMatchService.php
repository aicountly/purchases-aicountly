<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain;

use Aicountly\Api\Auth;
use Aicountly\Api\Clients\InventoryClient;
use Aicountly\Api\Context;
use Aicountly\Api\Db;
use Aicountly\Api\Http;

/**
 * Three-way match: purchase order vs goods receipt vs vendor bill.
 *
 * THE THREE DOCUMENTS LIVE IN THREE PRODUCTS AND STAY THERE.
 *
 *   the purchase order   ours
 *   the goods receipt    Inventory's — fetched here, live, by its uuid
 *   the vendor bill      what the buyer has entered, on its way to Books
 *
 * This engine COMPOSES those three at the moment somebody asks. It stores the
 * verdict and the human decision about an exception, and nothing else. There is
 * no copied GRN table and no copied invoice table, which is precisely why this
 * product has no reconciliation job: there is no second copy to drift.
 *
 * That is not a purity argument. A copied receipt quantity is a number that was
 * true when it was copied. A short delivery corrected in Inventory an hour later
 * would leave this engine matching a bill against a quantity nobody believes,
 * and passing it.
 */
final class ThreeWayMatchService
{
    public const MATCHED           = 'MATCHED';
    public const WITHIN_TOLERANCE  = 'WITHIN_TOLERANCE';
    public const REVIEW_REQUIRED   = 'REVIEW_REQUIRED';
    public const BLOCKED           = 'BLOCKED';

    public function __construct(
        private readonly Context $ctx,
        private readonly Auth $auth,
    ) {
    }

    /**
     * Match a bill against its purchase order and the receipts behind it.
     *
     * @param array<string, mixed> $bill the bill request row
     * @return array{verdict:string, variances:list<array<string, mixed>>, exceptions:list<array<string, mixed>>, receipts_reachable:bool}
     */
    public function evaluate(array $bill): array
    {
        $poId = $bill['po_id'] === null ? null : (int) $bill['po_id'];
        $billLines = Db::jsonColumn($bill['requested_lines'] ?? null);

        if ($poId === null) {
            // A bill with no purchase order behind it cannot be three-way
            // matched. Saying so is honest; inventing a pass is not.
            return [
                'verdict'    => self::REVIEW_REQUIRED,
                'variances'  => [],
                'exceptions' => [[
                    'exception_kind' => 'missing_receipt',
                    'detail'         => 'This bill is not against a purchase order, so it cannot be matched automatically.',
                ]],
                'receipts_reachable' => true,
            ];
        }

        $policy = $this->policy();
        $billTotal = array_sum(array_map(static fn (array $l) => (float) ($l['amount'] ?? 0), $billLines));

        if ((float) $policy['auto_match_below_amt'] > 0 && $billTotal < (float) $policy['auto_match_below_amt']) {
            return [
                'verdict'    => self::MATCHED,
                'variances'  => [['note' => 'Below the auto-match threshold for this company.']],
                'exceptions' => [],
                'receipts_reachable' => true,
            ];
        }

        $poLines = Db::all(
            'SELECT * FROM purchase_order_lines WHERE po_id = :po AND cmp_id = :cmp ORDER BY line_no',
            ['po' => $poId, 'cmp' => $this->ctx->cmpId],
        );
        if ($poLines === []) {
            Http::notFound('That purchase order has no lines to match against.');
        }

        // --- Leg two: what Inventory says actually arrived. LIVE. -----------
        [$receivedByLine, $receiptsReachable] = $this->receivedQuantities($poId);

        $variances = [];
        $exceptions = [];
        $worst = self::MATCHED;

        $billByPoLine = [];
        foreach ($billLines as $line) {
            $poLineId = (int) ($line['po_line_id'] ?? 0);
            if ($poLineId > 0) {
                $billByPoLine[$poLineId] = $line;
            }
        }

        foreach ($poLines as $poLine) {
            $poLineId = (int) $poLine['line_id'];
            $billLine = $billByPoLine[$poLineId] ?? null;
            if ($billLine === null) {
                continue; // Not on this bill. A partial bill is normal.
            }

            $orderedQty = (float) $poLine['ordered_qty'];
            $agreedRate = (float) $poLine['agreed_rate'];
            $billedQty  = (float) ($billLine['qty'] ?? 0);
            $billedRate = (float) ($billLine['rate'] ?? 0);
            // Already billed on earlier bills, so an over-bill is caught across
            // the whole PO rather than one bill at a time.
            $alreadyBilled = (float) $poLine['billed_qty'];

            $receivedQty = $receivedByLine[$poLineId] ?? null;

            // --- Quantity: billed against RECEIVED, not against ordered -----
            if ($receivedQty === null) {
                if ($receiptsReachable) {
                    $exceptions[] = [
                        'exception_kind' => 'missing_receipt',
                        'detail'         => sprintf('Line %d is billed but nothing has been received against it.', (int) $poLine['line_no']),
                        'po_value'       => $orderedQty,
                        'receipt_value'  => 0.0,
                        'bill_value'     => $billedQty,
                        'variance_value' => $billedQty,
                    ];
                    $worst = self::worse($worst, self::BLOCKED);
                }
            } else {
                $billableQty = $receivedQty - $alreadyBilled;
                $qtyVariance = $billedQty - $billableQty;
                $qtyTolerance = $billableQty * ((float) $policy['qty_tolerance_pc'] / 100);

                if ($qtyVariance > 0.0001) {
                    $withinTolerance = $qtyVariance <= $qtyTolerance;
                    $variances[] = [
                        'line_no'  => (int) $poLine['line_no'],
                        'kind'     => 'quantity',
                        'ordered'  => $orderedQty,
                        'received' => $receivedQty,
                        'billed'   => $billedQty + $alreadyBilled,
                        'variance' => round($qtyVariance, 4),
                        'within_tolerance' => $withinTolerance,
                    ];
                    if (!$withinTolerance) {
                        $exceptions[] = [
                            'exception_kind' => 'over_billed',
                            'detail'         => sprintf(
                                'Line %d is billed for %s but only %s has been received.',
                                (int) $poLine['line_no'],
                                self::num($billedQty + $alreadyBilled),
                                self::num($receivedQty),
                            ),
                            'po_value'       => $orderedQty,
                            'receipt_value'  => $receivedQty,
                            'bill_value'     => $billedQty + $alreadyBilled,
                            'variance_value' => round($qtyVariance, 4),
                        ];
                        $worst = self::worse($worst, self::BLOCKED);
                    } else {
                        $worst = self::worse($worst, self::WITHIN_TOLERANCE);
                    }
                }
            }

            // --- Rate: billed against the rate we AGREED -------------------
            if ($agreedRate > 0) {
                $rateVariance = $billedRate - $agreedRate;
                $ratePc = ($rateVariance / $agreedRate) * 100;

                if (abs($ratePc) > 0.0001) {
                    $withinTolerance = abs($ratePc) <= (float) $policy['rate_tolerance_pc'];
                    $variances[] = [
                        'line_no'     => (int) $poLine['line_no'],
                        'kind'        => 'rate',
                        'agreed_rate' => $agreedRate,
                        'billed_rate' => $billedRate,
                        'variance_pc' => round($ratePc, 3),
                        'within_tolerance' => $withinTolerance,
                    ];
                    // Three outcomes, and the ordering matters:
                    //   inside tolerance          -> tolerated, verdict softened
                    //   over tolerance, billed up -> an exception; somebody pays more
                    //   over tolerance, billed down -> in OUR favour, so noted and
                    //                                  passed. Blocking a supplier
                    //                                  for charging less is a
                    //                                  support ticket waiting to
                    //                                  happen.
                    if ($withinTolerance) {
                        $worst = self::worse($worst, self::WITHIN_TOLERANCE);
                    } elseif ($rateVariance > 0) {
                        $exceptions[] = [
                            'exception_kind' => 'rate',
                            'detail'         => sprintf(
                                'Line %d is billed at %s against an agreed %s (%+.2f%%).',
                                (int) $poLine['line_no'],
                                self::num($billedRate),
                                self::num($agreedRate),
                                $ratePc,
                            ),
                            'po_value'       => $agreedRate,
                            'bill_value'     => $billedRate,
                            'variance_value' => round($rateVariance, 4),
                        ];
                        $worst = self::worse($worst, self::BLOCKED);
                    } else {
                        $worst = self::worse($worst, self::WITHIN_TOLERANCE);
                    }
                }
            }
        }

        // --- A bill line that names no purchase order line -------------------
        // A supplier adding something nobody ordered is the classic procurement
        // fraud, so it is never quietly absorbed into the total.
        $knownPoLineIds = array_map(static fn (array $row) => (int) $row['line_id'], $poLines);
        foreach ($billLines as $line) {
            $poLineId = (int) ($line['po_line_id'] ?? 0);
            if (in_array($poLineId, $knownPoLineIds, true)) {
                continue;
            }
            $exceptions[] = [
                'exception_kind' => 'value',
                'detail'         => 'The bill contains a line that is not on the purchase order.',
                'bill_value'     => (float) ($line['amount'] ?? 0),
                'variance_value' => (float) ($line['amount'] ?? 0),
            ];
            $worst = self::worse($worst, self::REVIEW_REQUIRED);
        }

        // --- Inventory unreachable: review, never pass ----------------------
        if (!$receiptsReachable) {
            $exceptions[] = [
                'exception_kind' => 'missing_receipt',
                'detail'         => 'Inventory could not be reached, so what was actually received is unknown. The bill has not been matched.',
            ];
            $worst = self::worse($worst, self::REVIEW_REQUIRED);
        }

        return [
            'verdict'    => $worst,
            'variances'  => $variances,
            'exceptions' => $exceptions,
            'receipts_reachable' => $receiptsReachable,
        ];
    }

    /**
     * Run the match, store the verdict and its exceptions, and return it.
     *
     * @param array<string, mixed> $bill
     */
    public function run(array $bill): array
    {
        $result = $this->evaluate($bill);
        $policy = $this->policy();

        $matchId = (int) Db::insert('purchase_match_results', [
            'cmp_id'          => $this->ctx->cmpId,
            'fy_id'           => $this->ctx->fyId,
            'po_id'           => $bill['po_id'] === null ? null : (int) $bill['po_id'],
            'bill_request_id' => (int) $bill['request_id'],
            'policy_id'       => isset($policy['policy_id']) ? (int) $policy['policy_id'] : null,
            'verdict'         => $result['verdict'],
            'variances'       => $result['variances'],
            // References to what was compared, so the result can be explained
            // later without this table holding a copy of any of it.
            'compared_references' => [
                'po_id'              => $bill['po_id'],
                'bill_request_id'    => $bill['request_id'],
                'receipt_references' => Db::jsonColumn($bill['receipt_references'] ?? null),
                'evaluated_at'       => gmdate('c'),
            ],
            'matched_by'      => $this->auth->uuid,
        ], 'match_id');

        foreach ($result['exceptions'] as $exception) {
            Db::insert('purchase_match_exceptions', [
                'match_id'       => $matchId,
                'cmp_id'         => $this->ctx->cmpId,
                'exception_kind' => $exception['exception_kind'],
                'detail'         => $exception['detail'] ?? null,
                'po_value'       => $exception['po_value'] ?? null,
                'receipt_value'  => $exception['receipt_value'] ?? null,
                'bill_value'     => $exception['bill_value'] ?? null,
                'variance_value' => $exception['variance_value'] ?? null,
            ], 'exception_id');
        }

        $result['match_id'] = $matchId;

        return $result;
    }

    /**
     * What Inventory says has been received against this purchase order, per PO line.
     *
     * LIVE, every time. Inventory is asked for the documents whose source is this
     * purchase order, and the quantities come from its answer.
     *
     * @return array{0: array<int, float>, 1: bool} received-by-line, and whether Inventory answered
     */
    private function receivedQuantities(int $poId): array
    {
        $po = Db::first('SELECT po_uuid FROM purchase_orders WHERE po_id = :id AND cmp_id = :cmp', ['id' => $poId, 'cmp' => $this->ctx->cmpId]);
        if ($po === null) {
            return [[], true];
        }

        $client = (new InventoryClient());
        $client = $this->auth->isService()
            ? $client->withService($this->auth->uuid)
            : $client->withSession($this->auth->sesKey());

        $response = $client->documentBySource($this->ctx, 'purchases', 'purchases.order', $poId);

        if (!$response['ok']) {
            // Unreachable is not "nothing was received". Report it and let the
            // verdict be REVIEW_REQUIRED, never MATCHED.
            return [[], false];
        }

        $received = [];
        $documents = $response['body']['data'] ?? [];
        // by-source may answer with one document or a list, depending on how
        // many receipts a purchase order has had. Both shapes are handled.
        if (isset($documents['document_id'])) {
            $documents = [$documents];
        }

        foreach ((array) $documents as $document) {
            if (!is_array($document)) {
                continue;
            }
            if (in_array((string) ($document['status'] ?? ''), ['CANCELLED', 'REVERSED', 'DRAFT'], true)) {
                continue;
            }
            foreach ((array) ($document['lines'] ?? []) as $line) {
                $ref = (int) ($line['source_line_ref'] ?? 0);
                if ($ref <= 0) {
                    continue;
                }
                $received[$ref] = ($received[$ref] ?? 0.0) + (float) ($line['qty'] ?? 0);
            }
        }

        return [$received, true];
    }

    /** @return array<string, mixed> */
    private function policy(): array
    {
        $row = Db::first(
            'SELECT * FROM purchase_match_policies WHERE cmp_id = :cmp AND is_active = TRUE
             ORDER BY is_default DESC, policy_id LIMIT 1',
            ['cmp' => $this->ctx->cmpId],
        );

        // No policy configured means exact matching, which is the safe default:
        // a company that has not thought about tolerances has not agreed to any.
        return $row ?? [
            'qty_tolerance_pc'      => 0,
            'rate_tolerance_pc'     => 0,
            'value_tolerance_amt'   => 0,
            'freight_tolerance_amt' => 0,
            'auto_match_below_amt'  => 0,
        ];
    }

    private static function worse(string $current, string $candidate): string
    {
        $rank = [self::MATCHED => 0, self::WITHIN_TOLERANCE => 1, self::REVIEW_REQUIRED => 2, self::BLOCKED => 3];

        return $rank[$candidate] > $rank[$current] ? $candidate : $current;
    }

    private static function num(float $value): string
    {
        return rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');
    }
}
