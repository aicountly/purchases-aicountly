<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain;

use Aicountly\Api\Auth;
use Aicountly\Api\Clients\BooksClient;
use Aicountly\Api\Context;
use Aicountly\Api\Db;
use Aicountly\Api\Http;

/**
 * Which of the supplier's invoices a debit note settles, in Books' terms (launch readiness E8-PUR:
 * F246).
 *
 * A return's or a claim's debit note used to reach Books unlinked: it became its own Dr bill on
 * the supplier's account while the invoices it corrects stayed fully open, so a payment run could
 * pay the whole invoice and Purchase's planner counted the note as one more payable. Books settles
 * a note "Agst Ref" from `against_bills` ([{bill_id, amount}], BillAllocationService) — each no more
 * than the bill still has pending or the note has left — and keeps the note as a New Ref only for
 * what they do not take; `original_document.vch_txn_id` names the purchase it is against.
 *
 * The invoices are this order's bills Purchase posted, oldest first, as Books holds them now (its
 * bill-by-bill rows: bill_id, source_vch_txn_id, the payable side dr_cr 2, pending_amount). Read as
 * the person, before the note is first sent (its body is then stored and replayed). Books unreadable
 * is a retryable 503 — a note sent unlinked would stay unlinked for ever.
 */
final class DebitNoteLink
{
    /**
     * @return array{original_document?: array{vch_txn_id: int}, against_bills?: list<array{bill_id: int, amount: float}>}
     */
    public static function forOrder(Context $scope, Auth $auth, int $supplierAccId, ?int $poId): array
    {
        if ($poId === null) {
            return [];
        }
        $vouchers = array_map(static fn ($v) => (int) $v, array_column(Db::all(
            "SELECT books_voucher_id FROM purchase_bill_requests
              WHERE cmp_id = :cmp AND po_id = :po AND supplier_account_id = :supplier
                AND status = 'POSTED' AND books_voucher_id IS NOT NULL
              ORDER BY request_id",
            ['cmp' => $scope->cmpId, 'po' => $poId, 'supplier' => $supplierAccId],
        ), 'books_voucher_id'));
        if ($vouchers === []) {
            return [];
        }

        $result = (new BooksClient())->withSession($auth->sesKey())->billByBill($scope, ['acc_id' => $supplierAccId]);
        $data = $result['ok'] && is_array($result['body'] ?? null) ? ($result['body']['data'] ?? null) : null;
        $rows = is_array($data) ? ($data['rows'] ?? $data['bills'] ?? $data) : null;
        if (!is_array($rows)) {
            Http::error(503, 'books_unavailable', 'Smart Books could not say which of the supplier\'s invoices are still open, so the debit note was not sent: it would not settle the invoice it corrects. Try again.', ['retryable' => true]);
        }

        $byVoucher = [];
        foreach ($rows as $row) {
            if (!is_array($row) || (int) ($row['bill_id'] ?? 0) <= 0 || (int) ($row['dr_cr'] ?? 2) !== 2
                || !empty($row['is_on_account']) || !empty($row['is_undefined_reference'])) {
                continue;
            }
            $pending = round((float) ($row['pending_amount'] ?? 0), 4);
            if ($pending <= 0) {
                continue;
            }
            $byVoucher[(int) ($row['source_vch_txn_id'] ?? 0)][] = ['bill_id' => (int) $row['bill_id'], 'amount' => $pending];
        }

        $against = [];
        foreach ($vouchers as $voucherId) {
            foreach ($byVoucher[$voucherId] ?? [] as $bill) {
                $against[] = $bill;
            }
        }

        return array_filter([
            'original_document' => ['vch_txn_id' => $vouchers[0]],
            'against_bills'     => $against === [] ? null : $against,
        ], static fn ($v) => $v !== null);
    }
}
