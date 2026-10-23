<?php

declare(strict_types=1);

namespace Aicountly\Api\Controllers;

use Aicountly\Api\Dashboards\BooksReader;
use Aicountly\Api\Dashboards\Decimal;
use Aicountly\Api\Db;
use Aicountly\Api\Domain\SupplierNames;
use Aicountly\Api\Http;
use Aicountly\Api\LocalDate;
use Aicountly\Api\Permissions;

/**
 * One supplier's position, for the phone (mobile/): who they are to procurement, the orders
 * still open with them, and what Smart Books says the company owes them today.
 *
 * Built from what already exists — the procurement profile, our purchase orders and Books' open
 * items (BooksReader::openItems, the same read the Suppliers dashboard's drawer makes) — and
 * under the same rules as that drawer:
 *
 *   supplier.view               needed at all
 *   po.view                     the open orders; without it they are withheld, and named
 *   cost.view or reports.view   order values and the Books dues; without them, withheld
 *
 * The Suppliers dashboard answers the same question, but only after computing the whole
 * company's matrix, price movement and concentration first. A person standing at the gate with
 * a supplier's van wants one supplier, now. Read-only: nothing is written.
 */
final class SupplierPositionController extends Controller
{
    private const OPEN_LIMIT = 50;

    public static function show(string $id): void
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'supplier.view');

        $supplierId = (int) $id;
        if ($supplierId <= 0) {
            Http::validationFailed('Say which supplier.', ['field' => 'supplier_account_id']);
        }

        $seesValues = Permissions::allows($ctx, $auth, 'cost.view') || Permissions::allows($ctx, $auth, 'reports.view');
        $seesOrders = Permissions::allows($ctx, $auth, 'po.view');

        $profile = Db::first(
            'SELECT qualification_status, is_preferred, operational_lead_days, payment_terms, risk_flag
               FROM purchase_supplier_profiles WHERE cmp_id = :cmp AND supplier_account_id = :id',
            ['cmp' => $ctx->cmpId, 'id' => $supplierId],
        );

        $orders = null;
        $openCount = null;
        if ($seesOrders) {
            [$scope, $params] = $ctx->scopeClause('p');
            $where = "{$scope} AND p.supplier_account_id = :supplier AND p.status NOT IN ('CANCELLED', 'CLOSED', 'DRAFT')";
            $params['supplier'] = $supplierId;
            $rows = Db::all(
                "SELECT p.po_id, p.po_no, p.po_date, p.status, p.promised_date, p.currency_code,
                        p.total_amount::text AS total_amount, p.receipt_status,
                        (p.promised_date IS NOT NULL AND p.promised_date < :today
                           AND p.status IN ('ISSUED', 'ACKNOWLEDGED', 'PARTIALLY_RECEIVED')) AS overdue
                   FROM purchase_orders p
                  WHERE {$where}
                  ORDER BY p.promised_date ASC NULLS LAST, p.po_id DESC
                  LIMIT " . self::OPEN_LIMIT,
                $params + ['today' => LocalDate::today()],
            );
            $openCount = (int) Db::scalar("SELECT COUNT(*) FROM purchase_orders p WHERE {$where}", $params);
            $orders = array_map(static fn (array $o): array => [
                'po_id'          => (int) $o['po_id'],
                'po_no'          => $o['po_no'],
                'po_date'        => $o['po_date'],
                'status'         => $o['status'],
                'receipt_status' => $o['receipt_status'],
                'promised_date'  => $o['promised_date'],
                'overdue'        => $o['overdue'] === true || $o['overdue'] === 't' || $o['overdue'] === 1 || $o['overdue'] === '1',
                'currency_code'  => $o['currency_code'],
                'total_amount'   => $seesValues ? Decimal::of($o['total_amount']) : null,
            ], $rows);
        }

        $dues = ['available' => false, 'reason' => 'Supplier balances need the reports.view or cost.view permission.'];
        if ($seesValues) {
            $asOn = LocalDate::today();
            $open = (new BooksReader($ctx, $auth->sesKey()))->openItems($supplierId, $asOn);
            if ($open['ok']) {
                $overdue = Decimal::ZERO;
                foreach ($open['rows'] as $item) {
                    if (($item['days_overdue'] ?? 0) > 0) {
                        $overdue = Decimal::add($overdue, (string) $item['pending_amount']);
                    }
                }
                $dues = [
                    'available' => true,
                    'as_of'     => $asOn,
                    // Books' net: their bills less their debit notes and advances (F274).
                    'net_payable' => (string) ($open['net_payable'] ?? Decimal::ZERO),
                    'overdue'   => $overdue,
                    'bills'     => array_slice($open['rows'], 0, 25),
                    'bill_count' => count($open['rows']),
                    'credits_total' => Decimal::sum(array_map(static fn ($r) => (string) $r['pending_amount'], $open['credits'] ?? [])),
                    'basis'     => 'Open items from Smart Books today. Purchases keeps no payable figure of its own.',
                ];
            } else {
                // Books unreachable is said as such, never shown as "nothing owed".
                $dues = ['available' => false, 'reason' => (string) $open['error'], 'retryable' => true];
            }
        }

        $name = SupplierNames::for($ctx, $auth, [$supplierId])[$supplierId] ?? null;

        Http::data([
            'supplier_account_id' => $supplierId,
            'supplier_name'       => $name,
            'profile'             => $profile === null ? null : [
                'qualification_status'  => $profile['qualification_status'],
                'is_preferred'          => $profile['is_preferred'] === true || $profile['is_preferred'] === 't' || $profile['is_preferred'] === 1 || $profile['is_preferred'] === '1',
                'operational_lead_days' => $profile['operational_lead_days'] === null ? null : (int) $profile['operational_lead_days'],
                'payment_terms'         => $profile['payment_terms'],
                'risk_flag'             => $profile['risk_flag'],
            ],
            'open_orders'         => $orders,
            'open_order_count'    => $openCount,
            'withheld'            => array_values(array_filter([
                $seesOrders ? null : 'po.view',
                $seesValues ? null : 'cost.view',
            ])),
            'dues'                => $dues,
        ]);
    }
}
