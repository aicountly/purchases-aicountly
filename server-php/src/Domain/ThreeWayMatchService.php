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
            $detail = 'This bill is not against a purchase order, so it cannot be matched automatically.';
            $confirmation = $bill['open_grn_confirmation'] ?? null;
            $confirmation = is_string($confirmation) ? Db::jsonColumn($confirmation) : $confirmation;
            if (is_array($confirmation) && !empty($confirmation['confirmed'])) {
                // The reviewer is told what the person entering it decided (A09): the goods of
                // open receipts of this supplier, and why these are said to be different.
                $names = array_values(array_unique(array_map(
                    static fn ($r) => is_array($r) ? (string) ($r['receipt_no'] ?? $r['document_no'] ?? ('#' . ($r['inventory_document_id'] ?? '?'))) : '',
                    (array) ($confirmation['receipts'] ?? []),
                )));
                $detail .= sprintf(
                    ' It receives goods although receipt%s %s of this supplier %s open and unbilled; %s confirmed these are different goods: "%s".',
                    count($names) === 1 ? '' : 's',
                    implode(', ', $names),
                    count($names) === 1 ? 'is' : 'are',
                    (string) ($confirmation['confirmed_by'] ?? 'the person entering it'),
                    (string) ($confirmation['reason'] ?? ''),
                );
            }

            return [
                'verdict'    => self::REVIEW_REQUIRED,
                'variances'  => [],
                'exceptions' => [[
                    'exception_kind' => 'missing_receipt',
                    'detail'         => $detail,
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

        // Every bill line naming an order line, AGGREGATED per order line: quantities and net
        // amounts (after each bill line's own discount) added up. Keyed by the order line alone,
        // the last bill line used to overwrite the others, so an inflated earlier line was never
        // checked (A31).
        $billByPoLine = [];
        foreach ($billLines as $line) {
            $poLineId = (int) ($line['po_line_id'] ?? 0);
            if ($poLineId <= 0) {
                continue;
            }
            $qty = (float) ($line['qty'] ?? 0);
            $amount = self::netAmount($line);
            $billByPoLine[$poLineId]['qty'] = ($billByPoLine[$poLineId]['qty'] ?? 0.0) + $qty;
            $billByPoLine[$poLineId]['amount'] = ($billByPoLine[$poLineId]['amount'] ?? 0.0) + $amount;
            $billByPoLine[$poLineId]['lines'][] = ['line_no' => (int) ($line['line_no'] ?? 0), 'qty' => $qty, 'amount' => $amount];
        }

        foreach ($poLines as $poLine) {
            $poLineId = (int) $poLine['line_id'];
            $billLine = $billByPoLine[$poLineId] ?? null;
            if ($billLine === null) {
                continue; // Not on this bill. A partial bill is normal.
            }

            $orderedQty = (float) $poLine['ordered_qty'];
            // The rate AGREED is what the order line costs per unit after its discount — its line
            // amount over its quantity — not the gross list rate: a bill at the gross rate against
            // a discounted line charges more than was agreed (A31).
            $agreedRate = ReceiptService::netRate($poLine);
            $billedQty  = round((float) $billLine['qty'], 4);
            $billedAmount = round((float) $billLine['amount'], 4);
            $billedRate = $billedQty > 0 ? round($billedAmount / $billedQty, 4) : 0.0;
            // Already billed on earlier bills, so an over-bill is caught across
            // the whole PO rather than one bill at a time.
            $alreadyBilled = (float) $poLine['billed_qty'];

            $receivedQty = $receivedByLine[$poLineId] ?? null;

            // --- Quantity: billed against RECEIVED, not against ordered -----
            if ($receivedQty === null) {
                if ($receiptsReachable) {
                    $exceptions[] = [
                        'exception_kind' => 'missing_receipt',
                        'po_line_id'     => $poLineId,
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
                            'po_line_id'     => $poLineId,
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

            // --- Rate: each bill line's NET unit price against the NET rate we AGREED ---
            // Every bill line on its own, so one inflated line is found whichever order the lines
            // are in, and then the order line's bill lines together (their weighted rate).
            if ($agreedRate > 0) {
                $checks = [];
                foreach ($billLine['lines'] as $piece) {
                    if ($piece['qty'] > 0) {
                        $checks[] = ['line_no' => $piece['line_no'], 'rate' => round($piece['amount'] / $piece['qty'], 4)];
                    }
                }
                if (count($checks) > 1) {
                    $checks[] = ['line_no' => null, 'rate' => $billedRate];
                }
                $raisedRate = false;
                foreach ($checks as $check) {
                    $rateVariance = $check['rate'] - $agreedRate;
                    $ratePc = ($rateVariance / $agreedRate) * 100;
                    if (abs($ratePc) <= 0.0001) {
                        continue;
                    }
                    $withinTolerance = abs($ratePc) <= (float) $policy['rate_tolerance_pc'];
                    $variances[] = [
                        'line_no'     => (int) $poLine['line_no'],
                        'bill_line_no' => $check['line_no'],
                        'kind'        => 'rate',
                        'agreed_rate' => $agreedRate,
                        'billed_rate' => $check['rate'],
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
                        if ($raisedRate) {
                            continue; // one rate exception per order line; the variances list them all
                        }
                        $raisedRate = true;
                        $exceptions[] = [
                            'exception_kind' => 'rate',
                            'po_line_id'     => $poLineId,
                            'detail'         => sprintf(
                                'Line %d is billed at a net %s%s against an agreed net %s (%+.2f%%).',
                                (int) $poLine['line_no'],
                                self::num($check['rate']),
                                $check['line_no'] !== null && count($billLine['lines']) > 1 ? sprintf(' on bill line %d', (int) $check['line_no']) : '',
                                self::num($agreedRate),
                                $ratePc,
                            ),
                            'po_value'       => $agreedRate,
                            'bill_value'     => $check['rate'],
                            'variance_value' => round($rateVariance, 4),
                        ];
                        $worst = self::worse($worst, self::BLOCKED);
                    } else {
                        $worst = self::worse($worst, self::WITHIN_TOLERANCE);
                    }
                }

                // --- Value: what this order line costs on the bill against what the same
                // quantity costs at the agreed net rate. A company that sets an absolute value
                // tolerance caps what a rate tolerated in percent may add up to on one line.
                $agreedValue = round($billedQty * $agreedRate, 4);
                $valueVariance = round($billedAmount - $agreedValue, 4);
                $valueTolerance = (float) ($policy['value_tolerance_amt'] ?? 0);
                if ($valueTolerance > 0 && $valueVariance > $valueTolerance && !$raisedRate) {
                    $exceptions[] = [
                        'exception_kind' => 'value',
                        'po_line_id'     => $poLineId,
                        'detail'         => sprintf(
                            'Line %d costs %s on the bill against %s at the agreed net rate.',
                            (int) $poLine['line_no'],
                            self::num($billedAmount),
                            self::num($agreedValue),
                        ),
                        'po_value'       => $agreedValue,
                        'bill_value'     => $billedAmount,
                        'variance_value' => $valueVariance,
                    ];
                    $worst = self::worse($worst, self::BLOCKED);
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
        $revision = (int) ($bill['revision'] ?? 0);

        // Decisions already taken on THIS revision of the bill: an identical finding keeps its
        // decision. Re-running the match therefore never reopens an accepted variance nor
        // launders a rejected one; a finding that changed is new, and OPEN (F497, A26).
        $decided = [];
        foreach (Db::all(
            "SELECT e.* FROM purchase_match_exceptions e
               JOIN purchase_match_results m ON m.match_id = e.match_id
              WHERE m.bill_request_id = :bill AND m.cmp_id = :cmp AND m.bill_revision = :rev
                AND e.status IN ('ACCEPTED', 'REJECTED') AND e.fingerprint IS NOT NULL
              ORDER BY e.exception_id",
            ['bill' => (int) $bill['request_id'], 'cmp' => $this->ctx->cmpId, 'rev' => $revision],
        ) as $row) {
            $decided[(string) $row['fingerprint']] = $row;
        }

        $matchId = (int) Db::insert('purchase_match_results', [
            'cmp_id'          => $this->ctx->cmpId,
            'fy_id'           => $this->ctx->fyId,
            'po_id'           => $bill['po_id'] === null ? null : (int) $bill['po_id'],
            'bill_request_id' => (int) $bill['request_id'],
            'bill_revision'   => $revision,
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

        foreach ($result['exceptions'] as $index => $exception) {
            $fingerprint = self::fingerprint($exception);
            $earlier = $decided[$fingerprint] ?? null;
            $row = [
                'match_id'       => $matchId,
                'cmp_id'         => $this->ctx->cmpId,
                'exception_kind' => $exception['exception_kind'],
                'detail'         => $exception['detail'] ?? null,
                'po_value'       => $exception['po_value'] ?? null,
                'receipt_value'  => $exception['receipt_value'] ?? null,
                'bill_value'     => $exception['bill_value'] ?? null,
                'variance_value' => $exception['variance_value'] ?? null,
                'po_line_id'     => $exception['po_line_id'] ?? null,
                'fingerprint'    => $fingerprint,
            ];
            if ($earlier !== null) {
                $row += [
                    'status'                    => $earlier['status'],
                    'decided_by'                => $earlier['decided_by'],
                    'decided_at'                => $earlier['decided_at'],
                    'decision_note'             => $earlier['decision_note'],
                    'carried_from_exception_id' => (int) $earlier['exception_id'],
                ];
                $result['exceptions'][$index]['status'] = $earlier['status'];
                $result['exceptions'][$index]['carried_from_exception_id'] = (int) $earlier['exception_id'];
            }
            Db::insert('purchase_match_exceptions', $row, 'exception_id');
        }

        $result['match_id'] = $matchId;

        return $result;
    }

    /**
     * The bill status a stored match leaves the bill in:
     *   REJECTED   somebody rejected one of its exceptions — only Revise or Cancel remain
     *   EXCEPTION  an exception is still open
     *   MATCHED    nothing open: clean, within tolerance, or every exception accepted
     */
    public static function billStatusFor(int $matchId): string
    {
        $row = Db::first(
            "SELECT COUNT(*) FILTER (WHERE status = 'REJECTED') AS rejected,
                    COUNT(*) FILTER (WHERE status = 'OPEN') AS open
               FROM purchase_match_exceptions WHERE match_id = :id",
            ['id' => $matchId],
        ) ?? [];
        if ((int) ($row['rejected'] ?? 0) > 0) {
            return 'REJECTED';
        }

        return (int) ($row['open'] ?? 0) > 0 ? 'EXCEPTION' : 'MATCHED';
    }

    /**
     * What an exception found, as a key: its kind, the order line, and the values compared. Two
     * runs that find the same thing on the same revision produce the same key.
     *
     * @param array<string, mixed> $exception
     */
    public static function fingerprint(array $exception): string
    {
        $value = static fn ($v) => $v === null ? '-' : number_format((float) $v, 4, '.', '');

        return implode('|', [
            (string) ($exception['exception_kind'] ?? ''),
            (string) (int) ($exception['po_line_id'] ?? 0),
            $value($exception['po_value'] ?? null),
            $value($exception['receipt_value'] ?? null),
            $value($exception['bill_value'] ?? null),
            md5((string) ($exception['detail'] ?? '')),
        ]);
    }

    /**
     * What Inventory says has been received against this purchase order, per PO line.
     *
     * LIVE, every time, and ALL of it: every GRN of the order, each read from Inventory
     * by its own document, reversed ones excluded (ReceiptLedger). An order received in
     * three deliveries is matched against three GRNs, not against whichever one a lookup
     * by the order's identity happened to find.
     *
     * @return array{0: array<int, float>, 1: bool} received-by-line, and whether Inventory answered
     */
    private function receivedQuantities(int $poId): array
    {
        $ledger = (new ReceiptLedger($this->ctx, $this->auth))->forOrder($poId);

        // Unreachable is not "nothing was received": the verdict becomes
        // REVIEW_REQUIRED, never MATCHED.
        return [$ledger['received_by_line'], $ledger['reachable']];
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

    /**
     * A bill line's amount after its own discount — as stored (BillService::normaliseLines), or
     * from its quantity, rate and discount when an older row has none.
     *
     * @param array<string, mixed> $line
     */
    private static function netAmount(array $line): float
    {
        if (isset($line['amount']) && is_numeric($line['amount'])) {
            return (float) $line['amount'];
        }
        $gross = (float) ($line['qty'] ?? 0) * (float) ($line['rate'] ?? 0);

        return round($gross - $gross * (float) ($line['discount_pc'] ?? 0) / 100, 4);
    }

    private static function num(float $value): string
    {
        return rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');
    }
}
