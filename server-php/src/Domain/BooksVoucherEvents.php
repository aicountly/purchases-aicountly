<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain;

use Aicountly\Api\Audit;
use Aicountly\Api\Auth;
use Aicountly\Api\Context;
use Aicountly\Api\Db;

/**
 * Books told us it cancelled or edited a purchase voucher it holds for one of our bills (A16).
 *
 *   voucher.cancelled  the bill becomes CANCELLED (cancelled_in_books_at says why): the supplier's
 *                      invoice number can be entered again — the duplicate guard ignores
 *                      cancelled bills — and the quantities it billed go back to the PO lines,
 *                      whose progress is recomputed under the PO lock (PoProgress).
 *   voucher.revised    the revision is recorded; changed quantities are said on the bill.
 *
 * Applied once per event id, in one transaction with the record of it. A voucher none of our bills
 * holds is answered "ignored" with the reason, so it stays on Books' exception list.
 */
final class BooksVoucherEvents
{
    /**
     * @param array<string, mixed> $event
     * @return array{status: string, reference: string, reason?: string, duplicate?: bool}
     */
    public static function apply(array $event, Auth $auth): array
    {
        $eventId = trim((string) ($event['event_id'] ?? ''));
        $type = (string) ($event['event_type'] ?? '');
        $cmpId = (int) ($event['cmp_id'] ?? 0);
        $vchTxnId = (int) ($event['vch_txn_id'] ?? 0);
        $revision = (int) ($event['revision'] ?? 0);

        return Db::transaction(static function () use ($event, $auth, $eventId, $type, $cmpId, $vchTxnId, $revision): array {
            $inserted = Db::run(
                'INSERT INTO purchase_books_event_inbox (event_id, cmp_id, vch_txn_id, event_type, revision, outcome, reference, payload)
                 VALUES (:id, :cmp, :vch, :type, :rev, :o, :r, CAST(:p AS JSONB)) ON CONFLICT (event_id) DO NOTHING',
                ['id' => $eventId, 'cmp' => $cmpId, 'vch' => $vchTxnId, 'type' => $type, 'rev' => $revision, 'o' => 'pending', 'r' => '',
                    'p' => json_encode($event, JSON_UNESCAPED_SLASHES)],
            )->rowCount();
            if ($inserted === 0) {
                $seen = Db::first('SELECT outcome, reference, reason, cmp_id FROM purchase_books_event_inbox WHERE event_id = :id', ['id' => $eventId]);
                if ($seen === null || (int) $seen['cmp_id'] !== $cmpId) {
                    return ['status' => 'ignored', 'reference' => 'purchases:event:' . $eventId, 'reason' => 'event id known for another company'];
                }

                return ['status' => (string) $seen['outcome'], 'reference' => (string) $seen['reference'], 'reason' => (string) ($seen['reason'] ?? ''), 'duplicate' => true];
            }

            $bill = Db::first(
                'SELECT * FROM purchase_bill_requests WHERE cmp_id = :cmp AND books_voucher_id = :vch ORDER BY request_id DESC LIMIT 1 FOR UPDATE',
                ['cmp' => $cmpId, 'vch' => $vchTxnId],
            );
            $result = match (true) {
                $bill === null => ['status' => 'ignored', 'reference' => 'purchases:voucher:' . $vchTxnId . ':unknown',
                    'reason' => 'No Purchases bill holds Books voucher ' . $vchTxnId . ' in company ' . $cmpId . '.'],
                $type === 'voucher.cancelled' => self::cancelled($bill, $event, $auth),
                $type === 'voucher.revised' => self::revised($bill, $event, $revision, $auth),
                default => ['status' => 'ignored', 'reference' => 'purchases:event:' . $eventId, 'reason' => 'Unknown event type ' . $type . '.'],
            };
            Db::run('UPDATE purchase_books_event_inbox SET outcome = :o, reference = :r, reason = :why WHERE event_id = :id',
                ['o' => $result['status'], 'r' => $result['reference'], 'why' => $result['reason'] ?? null, 'id' => $eventId]);
            if ($result['status'] === 'ignored') {
                // Nothing of ours changed, but Books told us something about this company: who sent
                // what, and why it was set aside, belongs in the trail as well as in the inbox.
                Audit::record(
                    $bill !== null ? Context::of($cmpId, (int) $bill['fy_id'], (int) $bill['bo_id']) : Context::of($cmpId, 0, 0),
                    $auth,
                    'bill.books_event_ignored',
                    $bill !== null ? 'bill_request' : 'books_voucher',
                    $bill !== null ? (int) $bill['request_id'] : $vchTxnId,
                    null,
                    ['event_id' => $eventId, 'event_type' => $type, 'vch_txn_id' => $vchTxnId, 'revision' => $revision],
                    (string) ($result['reason'] ?? ''),
                );
            }

            return $result;
        });
    }

    /**
     * @param array<string, mixed> $bill
     * @param array<string, mixed> $event
     * @return array{status: string, reference: string, reason?: string}
     */
    private static function cancelled(array $bill, array $event, Auth $auth): array
    {
        $requestId = (int) $bill['request_id'];
        $reference = 'purchases:bill:' . $requestId . ':cancelled_in_books';
        if (!empty($bill['cancelled_in_books_at'])) {
            return ['status' => 'applied', 'reference' => $reference];
        }
        if ((string) $bill['status'] !== 'POSTED') {
            return ['status' => 'ignored', 'reference' => 'purchases:bill:' . $requestId . ':' . strtolower((string) $bill['status']),
                'reason' => 'The bill is ' . $bill['status'] . ', not posted.'];
        }
        $cmpId = (int) $bill['cmp_id'];
        $poId = $bill['po_id'] !== null ? (int) $bill['po_id'] : null;
        if ($poId !== null) {
            PoProgress::lock($poId, $cmpId);
        }
        Db::run(
            "UPDATE purchase_bill_requests SET status = 'CANCELLED', cancelled_in_books_at = NOW(), last_error = :why, updated_at = NOW()
              WHERE request_id = :id",
            ['id' => $requestId, 'why' => sprintf('Bill voucher %s was cancelled in Smart Books%s. The supplier\'s invoice %s can be entered again.',
                (string) ($event['vch_no'] ?? $bill['books_voucher_no'] ?? ''),
                trim((string) ($event['reason'] ?? '')) !== '' ? ': ' . trim((string) $event['reason']) : '',
                (string) ($bill['supplier_invoice_no'] ?? ''))],
        );
        if ($poId !== null) {
            foreach (Db::jsonColumn($bill['requested_lines']) as $line) {
                $poLineId = (int) ($line['po_line_id'] ?? 0);
                if ($poLineId > 0) {
                    Db::run(
                        'UPDATE purchase_order_lines SET billed_qty = GREATEST(billed_qty - :qty, 0), updated_at = NOW()
                          WHERE line_id = :id AND po_id = :po AND cmp_id = :cmp',
                        ['qty' => (float) ($line['qty'] ?? 0), 'id' => $poLineId, 'po' => $poId, 'cmp' => $cmpId],
                    );
                }
            }
            PoProgress::recompute($poId, $cmpId, 'service:books');
        }
        // In the event's transaction (fail-closed): a bill Books cancelled is never undone here without a trace.
        Audit::material(Context::of($cmpId, (int) $bill['fy_id'], (int) $bill['bo_id']), $auth, 'bill.cancelled_in_books', 'bill_request', $requestId,
            ['status' => 'POSTED'], [
                'status' => 'CANCELLED', 'books_event_id' => $event['event_id'] ?? null, 'supplier_invoice_no' => $bill['supplier_invoice_no'] ?? null,
                'books_voucher_no' => $event['vch_no'] ?? $bill['books_voucher_no'] ?? null,
            ], (string) ($event['reason'] ?? ''));

        return ['status' => 'applied', 'reference' => $reference];
    }

    /**
     * @param array<string, mixed> $bill
     * @param array<string, mixed> $event
     * @return array{status: string, reference: string, reason?: string}
     */
    private static function revised(array $bill, array $event, int $revision, Auth $auth): array
    {
        $requestId = (int) $bill['request_id'];
        if ($revision > 0 && (int) ($bill['revision_in_books'] ?? 0) >= $revision) {
            return ['status' => 'applied', 'reference' => 'purchases:bill:' . $requestId . ':revised:' . (int) $bill['revision_in_books']];
        }
        $changed = !empty($event['quantities_changed']);
        Db::run(
            'UPDATE purchase_bill_requests SET revision_in_books = :rev, last_error = :why, updated_at = NOW() WHERE request_id = :id',
            ['rev' => $revision, 'id' => $requestId, 'why' => $changed
                ? sprintf('The bill voucher %s was edited in Smart Books and its quantities changed (revision %d). Compare the PO and receipts with it.', (string) ($event['vch_no'] ?? ''), $revision)
                : ($bill['last_error'] ?? null)],
        );
        Audit::record(Context::of((int) $bill['cmp_id'], (int) $bill['fy_id'], (int) $bill['bo_id']), $auth, 'bill.revised_in_books', 'bill_request', $requestId,
            ['revision' => (int) ($bill['revision_in_books'] ?? 0)], ['revision' => $revision, 'quantities_changed' => $changed, 'after' => $event['after'] ?? null]);

        return ['status' => 'applied', 'reference' => 'purchases:bill:' . $requestId . ':revised:' . $revision];
    }
}
