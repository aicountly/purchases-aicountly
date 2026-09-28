<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain;

use Aicountly\Api\Db;

/**
 * Where a purchase order stands: received, billed, closed — three facts, kept apart.
 *
 * Per line:
 *   wanted        ordered − short-closed        what is still expected at all
 *   net received  received − returned           what arrived and stayed
 *   net billed    billed − debited              what the supplier has billed and not credited back
 *
 * A stock line's receipt is complete when net received reaches wanted. A line's billing is
 * complete when net billed reaches net received and nothing wanted is still to arrive — a
 * service line has no receipt, so for it billing alone decides. The order closes itself
 * only when every line is complete on both counts and no bill against it is still in
 * matching, in exception or on its way to Books.
 *
 * Whether the supplier has been paid is not here. That is the payable, and it is Books'.
 *
 * Called inside the transaction that changed a quantity, after locking the order row, so
 * two receipts or a receipt and a cancellation cannot interleave their reads and writes.
 * CANCELLED is never overwritten, and a closed order is reopened only by the same rule
 * that closed it.
 */
final class PoProgress
{
    private const EPSILON = 0.00005;

    /** States in which receiving and billing move the order along. */
    private const PROGRESSABLE = ['APPROVED', 'ISSUED', 'ACKNOWLEDGED', 'PARTIALLY_RECEIVED', 'RECEIVED', 'CLOSED'];

    /**
     * Lock the order row for the rest of the caller's transaction.
     *
     * @return array<string, mixed>|null
     */
    public static function lock(int $poId, int $cmpId): ?array
    {
        return Db::first(
            'SELECT * FROM purchase_orders WHERE po_id = :id AND cmp_id = :cmp FOR UPDATE',
            ['id' => $poId, 'cmp' => $cmpId],
        );
    }

    /**
     * Recompute and store the order's receipt status, billing status and workflow status.
     *
     * @return array{receipt_status: string, billing_status: string, status: string}
     */
    public static function recompute(int $poId, int $cmpId, ?string $actor = null): array
    {
        $po = self::lock($poId, $cmpId);
        if ($po === null) {
            return ['receipt_status' => 'NOT_STARTED', 'billing_status' => 'NOT_BILLED', 'status' => ''];
        }

        $figures = self::figures($poId, $cmpId);
        $status = (string) $po['status'];
        $next = $status;
        $closure = null;

        if (in_array($status, self::PROGRESSABLE, true)) {
            $done = $figures['receipt_status'] === 'COMPLETE'
                && $figures['billing_status'] === 'COMPLETE'
                && !$figures['bill_work_open']
                && !$figures['return_work_open'];

            if ($done) {
                $next = 'CLOSED';
                $closure = $figures['short_closed'] ? 'short_close' : 'auto';
            } elseif ($figures['has_stock_lines'] && $figures['receipt_status'] === 'COMPLETE') {
                $next = 'RECEIVED';
            } elseif ($figures['receipt_status'] === 'PARTIAL') {
                $next = 'PARTIALLY_RECEIVED';
            } elseif (in_array($status, ['PARTIALLY_RECEIVED', 'RECEIVED', 'CLOSED'], true)) {
                // Everything that had arrived went back: the order is waiting again.
                $next = $po['acknowledged_at'] !== null ? 'ACKNOWLEDGED' : ($po['issued_at'] !== null ? 'ISSUED' : 'APPROVED');
            }
        }

        $values = [
            'receipt_status' => $figures['receipt_status'],
            'billing_status' => $figures['billing_status'],
            'status'         => $next,
            'updated_at'     => gmdate('Y-m-d H:i:s'),
        ];
        if ($next === 'CLOSED' && $status !== 'CLOSED') {
            $values['closed_at'] = gmdate('Y-m-d H:i:s');
            $values['closed_by'] = $actor;
            $values['closure_kind'] = $closure;
        } elseif ($next !== 'CLOSED' && $status === 'CLOSED') {
            $values['closed_at'] = null;
            $values['closed_by'] = null;
            $values['closure_kind'] = null;
        }

        Db::update('purchase_orders', $values, ['po_id' => $poId, 'cmp_id' => $cmpId]);

        return ['receipt_status' => $figures['receipt_status'], 'billing_status' => $figures['billing_status'], 'status' => $next];
    }

    /**
     * The figures behind the statuses, without writing anything.
     *
     * @return array{receipt_status: string, billing_status: string, bill_work_open: bool, return_work_open: bool, short_closed: bool, has_stock_lines: bool, lines: list<array<string, mixed>>}
     */
    public static function figures(int $poId, int $cmpId): array
    {
        $lines = Db::all(
            'SELECT line_id, line_no, item_id, is_service, ordered_qty, short_closed_qty, received_qty,
                    returned_qty, billed_qty, debited_qty
               FROM purchase_order_lines WHERE po_id = :id AND cmp_id = :cmp ORDER BY line_no',
            ['id' => $poId, 'cmp' => $cmpId],
        );

        $hasStock = false;
        $receiptComplete = true;
        $anyReceived = false;
        $billingComplete = $lines !== [];
        $anyBilled = false;
        $shortClosed = false;
        $out = [];

        foreach ($lines as $line) {
            $wanted = (float) $line['ordered_qty'] - (float) $line['short_closed_qty'];
            $netReceived = (float) $line['received_qty'] - (float) $line['returned_qty'];
            $netBilled = (float) $line['billed_qty'] - (float) $line['debited_qty'];
            $isStock = !self::truthy($line['is_service']) && $line['item_id'] !== null;

            if ((float) $line['short_closed_qty'] > self::EPSILON) {
                $shortClosed = true;
            }
            if ((float) $line['billed_qty'] > self::EPSILON) {
                $anyBilled = true;
            }

            if ($isStock) {
                $hasStock = true;
                if ((float) $line['received_qty'] > self::EPSILON) {
                    $anyReceived = true;
                }
                $lineReceived = $netReceived >= $wanted - self::EPSILON;
                $receiptComplete = $receiptComplete && $lineReceived;
                $lineBilled = $lineReceived && $netBilled >= $netReceived - self::EPSILON;
            } else {
                $lineReceived = true;
                $lineBilled = $netBilled >= $wanted - self::EPSILON;
            }
            $billingComplete = $billingComplete && $lineBilled;

            $out[] = [
                'line_id'           => (int) $line['line_id'],
                'line_no'           => (int) $line['line_no'],
                'is_stock'          => $isStock,
                'wanted_qty'        => round($wanted, 4),
                'net_received_qty'  => round($netReceived, 4),
                'net_billed_qty'    => round($netBilled, 4),
                'to_receive_qty'    => $isStock ? round(max(0.0, $wanted - $netReceived), 4) : 0.0,
                'to_bill_qty'       => round(max(0.0, ($isStock ? $netReceived : $wanted) - $netBilled), 4),
                'receipt_complete'  => $lineReceived,
                'billing_complete'  => $lineBilled,
            ];
        }

        $billWorkOpen = (bool) Db::scalar(
            "SELECT EXISTS (
                 SELECT 1 FROM purchase_bill_requests b
                  WHERE b.po_id = :id AND b.cmp_id = :cmp AND b.status NOT IN ('POSTED', 'CANCELLED')
             )",
            ['id' => $poId, 'cmp' => $cmpId],
        );

        // A return not yet settled by its debit note (or cancelled, or recalled) is money still
        // to be agreed with the supplier: the order is not finished while one is open.
        $returnWorkOpen = (bool) Db::scalar(
            "SELECT EXISTS (
                 SELECT 1 FROM purchase_returns r
                  WHERE r.po_id = :id AND r.cmp_id = :cmp AND r.status IN ('DRAFT', 'APPROVED', 'DISPATCHED')
             )",
            ['id' => $poId, 'cmp' => $cmpId],
        );

        return [
            'receipt_status'  => !$hasStock || $receiptComplete ? 'COMPLETE' : ($anyReceived ? 'PARTIAL' : 'NOT_STARTED'),
            'billing_status'  => $billingComplete ? 'COMPLETE' : ($anyBilled ? 'PARTIAL' : 'NOT_BILLED'),
            'bill_work_open'  => $billWorkOpen,
            'return_work_open' => $returnWorkOpen,
            'short_closed'    => $shortClosed,
            'has_stock_lines' => $hasStock,
            'lines'           => $out,
        ];
    }

    private static function truthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_string($value)) {
            return in_array(strtolower($value), ['t', 'true', '1', 'yes'], true);
        }

        return (bool) $value;
    }
}
