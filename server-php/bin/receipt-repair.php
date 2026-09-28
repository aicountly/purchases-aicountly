<?php

declare(strict_types=1);

/**
 * What the receipt and bill fixes of 2026-10 cannot repair by themselves — reported,
 * planned for review, and applied only on request.
 *
 *   php bin/receipt-repair.php [--cmp=ID] [--inventory] [--json]
 *       Report. Reads only. --inventory also asks Inventory what it holds for each
 *       order (service key, read-only calls).
 *
 *   php bin/receipt-repair.php --plan=FILE [--cmp=ID] [--inventory]
 *       Write the report and the actions it proposes to FILE, for a person to review.
 *       Reads only.
 *
 *   php bin/receipt-repair.php --apply=FILE --actor=UUID --reason="…"
 *       Apply a reviewed plan. Each action is re-checked against the database as it is
 *       now and skipped if anything it was planned against has changed. Each applied
 *       action is audited against the person named, with the reason given.
 *
 * NOTHING HERE POSTS TO INVENTORY OR BOOKS. Goods counted by an order but missing from
 * Inventory are a stock question for a person: they are reported with what each side
 * holds, and the fix is a GRN recorded through the application, under its own
 * identity, once somebody has confirmed the goods are really there. The only actions
 * this applies are local bookkeeping: an order line's counter brought back into line
 * with the receipts it is made of, a command withdrawn whose document was cancelled,
 * and the duplicate-invoice index created once the data allows it.
 *
 * What it looks for:
 *   legacy_identity         several receipts of one order recorded under the ORDER's
 *                           identity (before receipts had their own). Inventory kept
 *                           one document per source, so later deliveries were answered
 *                           as duplicates of the first and never reached stock.
 *   inventory_differs       (--inventory) what Inventory holds for an order differs from
 *                           what the order counts.
 *   counter_drift           an order line's received_qty differs from the sum of its
 *                           applied receipts.
 *   duplicate_invoices      bills sharing a supplier and invoice number; while any
 *                           exist, uq_purchase_bills_supplier_invoice cannot be created.
 *   open_commands           integration work not finished: failed, uncertain, blocked,
 *                           or an attempt whose lease expired.
 *   orphan_commands         open commands whose receipt or bill was cancelled.
 *   renumbered_commands     extra rows of one operation from before the operation was
 *                           unique, kept as later revisions for history.
 */

namespace Aicountly\Api;

require __DIR__ . '/../src/Env.php';
require __DIR__ . '/../src/Autoload.php';

Env::load(__DIR__ . '/../.env');

use Aicountly\Api\Domain\PoProgress;
use Aicountly\Api\Domain\ReceiptLedger;
use Aicountly\Api\Domain\ReceiptService;

$opts = getopt('', ['cmp:', 'inventory', 'json', 'plan:', 'apply:', 'actor:', 'reason:', 'help']);
if (isset($opts['help'])) {
    fwrite(STDOUT, "See the header of bin/receipt-repair.php.\n");
    exit(0);
}

try {
    Db::connect();
} catch (\Throwable $e) {
    fwrite(STDERR, "Cannot connect: {$e->getMessage()}\n");
    exit(1);
}

if (isset($opts['apply'])) {
    exit(applyPlan((string) $opts['apply'], (string) ($opts['actor'] ?? ''), (string) ($opts['reason'] ?? '')));
}

$cmpFilter = isset($opts['cmp']) ? (int) $opts['cmp'] : null;
$report = buildReport($cmpFilter, isset($opts['inventory']));

if (isset($opts['plan'])) {
    $plan = [
        'generated_at' => gmdate('c'),
        'cmp_id'       => $cmpFilter,
        'findings'     => $report,
        'actions'      => proposeActions($report),
        'note'         => 'Review every action. Remove any you do not want applied, then run --apply with this file.',
    ];
    file_put_contents((string) $opts['plan'], json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    fwrite(STDOUT, sprintf("Plan written to %s: %d finding(s), %d proposed action(s).\n", $opts['plan'], countFindings($report), count($plan['actions'])));
    exit(0);
}

if (isset($opts['json'])) {
    fwrite(STDOUT, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
} else {
    printReport($report);
}
exit(countFindings($report) > 0 ? 3 : 0);

// ---------------------------------------------------------------------------

/** @return array<string, list<array<string, mixed>>> */
function buildReport(?int $cmpFilter, bool $askInventory): array
{
    $cmpSql = $cmpFilter === null ? '' : ' AND %s.cmp_id = ' . $cmpFilter;
    $out = [
        'legacy_identity' => [], 'inventory_differs' => [], 'counter_drift' => [], 'duplicate_invoices' => [],
        'open_commands' => [], 'orphan_commands' => [], 'renumbered_commands' => [],
    ];

    foreach (Db::all(
        "SELECT r.cmp_id, r.po_id, o.po_no, COUNT(*) AS receipts, array_agg(r.request_id ORDER BY r.request_id) AS request_ids
           FROM purchase_receipt_requests r JOIN purchase_orders o ON o.po_id = r.po_id
          WHERE r.source_document_type = '" . ReceiptService::LEGACY_SOURCE_TYPE . "' AND r.applied_at IS NOT NULL" . sprintf($cmpSql, 'r') . '
          GROUP BY r.cmp_id, r.po_id, o.po_no HAVING COUNT(*) > 1 ORDER BY r.cmp_id, r.po_id',
    ) as $row) {
        $out['legacy_identity'][] = [
            'cmp_id' => (int) $row['cmp_id'], 'po_id' => (int) $row['po_id'], 'po_no' => $row['po_no'],
            'receipts' => (int) $row['receipts'], 'request_ids' => pgArray($row['request_ids']),
            'what_to_do' => 'Confirm physically what arrived. Inventory holds one document for this order; for goods that are really on hand and not in it, record a GRN through the application (it posts under its own identity). Then run this again: counter_drift will show whether the order still over-counts.',
        ];
    }

    // The order's counter against the receipts it is made of.
    foreach (Db::all(
        "SELECT l.cmp_id, l.po_id, o.po_no, l.line_id, l.line_no, l.received_qty, l.rejected_qty
           FROM purchase_order_lines l JOIN purchase_orders o ON o.po_id = l.po_id
          WHERE l.item_id IS NOT NULL" . sprintf($cmpSql, 'l') . ' ORDER BY l.cmp_id, l.po_id, l.line_no',
    ) as $line) {
        $fromReceipts = receiptTotals((int) $line['po_id'])[(int) $line['line_id']] ?? ['qty' => 0.0, 'rejected' => 0.0];
        if (abs((float) $line['received_qty'] - $fromReceipts['qty']) > 0.0001) {
            $out['counter_drift'][] = [
                'cmp_id' => (int) $line['cmp_id'], 'po_id' => (int) $line['po_id'], 'po_no' => $line['po_no'],
                'line_id' => (int) $line['line_id'], 'line_no' => (int) $line['line_no'],
                'order_counts' => (float) $line['received_qty'], 'receipts_add_up_to' => $fromReceipts['qty'],
            ];
        }
    }

    if ($askInventory) {
        $orders = Db::all(
            "SELECT DISTINCT r.cmp_id, r.fy_id, r.po_id FROM purchase_receipt_requests r
              WHERE r.applied_at IS NOT NULL" . sprintf($cmpSql, 'r') . ' ORDER BY r.cmp_id, r.po_id',
        );
        foreach ($orders as $o) {
            $ctx = Context::of((int) $o['cmp_id'], (int) $o['fy_id'], 0);
            $ledger = (new ReceiptLedger($ctx, Auth::operator('receipt-repair')))->forOrder((int) $o['po_id']);
            if (!$ledger['reachable']) {
                $out['inventory_differs'][] = ['cmp_id' => (int) $o['cmp_id'], 'po_id' => (int) $o['po_id'], 'kind' => 'unreachable', 'detail' => 'Inventory could not be asked.'];
                continue;
            }
            foreach ($ledger['discrepancies'] as $d) {
                $out['inventory_differs'][] = ['cmp_id' => (int) $o['cmp_id'], 'po_id' => (int) $o['po_id']] + $d;
            }
        }
    }

    foreach (Db::all(
        "SELECT cmp_id, supplier_account_id, lower(supplier_invoice_no) AS invoice, array_agg(request_id ORDER BY request_id) AS request_ids,
                array_agg(status ORDER BY request_id) AS statuses
           FROM purchase_bill_requests b
          WHERE status <> 'CANCELLED' AND supplier_invoice_no IS NOT NULL" . sprintf($cmpSql, 'b') . '
          GROUP BY cmp_id, supplier_account_id, lower(supplier_invoice_no) HAVING COUNT(*) > 1',
    ) as $row) {
        $out['duplicate_invoices'][] = [
            'cmp_id' => (int) $row['cmp_id'], 'supplier_account_id' => (int) $row['supplier_account_id'], 'supplier_invoice_no' => $row['invoice'],
            'request_ids' => pgArray($row['request_ids']), 'statuses' => pgArray($row['statuses']),
            'what_to_do' => 'Keep the bill that is (or should be) in Books; cancel the others in the application. A POSTED duplicate needs a debit note in Books, not a cancellation here.',
        ];
    }

    foreach (Db::all(
        "SELECT c.command_id, c.cmp_id, c.command_type, c.entity_type, c.entity_id, c.revision, c.status, c.attempts, c.last_error, c.updated_at,
                (c.status = 'POSTING' AND c.lease_expires_at < NOW()) AS lease_expired
           FROM purchase_integration_commands c
          WHERE (c.status IN ('PENDING', 'FAILED', 'UNCERTAIN', 'BLOCKED') OR (c.status = 'POSTING' AND (c.lease_expires_at IS NULL OR c.lease_expires_at < NOW())))"
          . sprintf($cmpSql, 'c') . ' ORDER BY c.updated_at',
    ) as $c) {
        $entityStatus = entityStatus((string) $c['entity_type'], (int) $c['entity_id']);
        $row = [
            'command_id' => (int) $c['command_id'], 'cmp_id' => (int) $c['cmp_id'], 'command_type' => $c['command_type'],
            'entity_type' => $c['entity_type'], 'entity_id' => (int) $c['entity_id'], 'revision' => (int) $c['revision'],
            'status' => $c['status'], 'attempts' => (int) $c['attempts'], 'last_error' => $c['last_error'], 'updated_at' => $c['updated_at'],
            'entity_status' => $entityStatus,
        ];
        if ($entityStatus === 'CANCELLED' && in_array($c['status'], ['PENDING', 'FAILED', 'BLOCKED'], true)) {
            $out['orphan_commands'][] = $row;
        } else {
            $out['open_commands'][] = $row + ['what_to_do' => match ((string) $c['status']) {
                'UNCERTAIN' => 'Reconcile it from its document in the application: the other product may already hold it.',
                'BLOCKED'   => 'Refused by the other product. Read last_error; revise or cancel the document.',
                default     => 'Retry it from its document in the application.',
            }];
        }
    }

    foreach (Db::all(
        "SELECT c.command_id, c.cmp_id, c.command_type, c.entity_type, c.entity_id, c.revision, c.status
           FROM purchase_integration_commands c
          WHERE c.revision > 0 AND c.entity_type <> 'bill_request'" . sprintf($cmpSql, 'c') . ' ORDER BY c.command_id',
    ) as $c) {
        $out['renumbered_commands'][] = [
            'command_id' => (int) $c['command_id'], 'cmp_id' => (int) $c['cmp_id'], 'command_type' => $c['command_type'],
            'entity_type' => $c['entity_type'], 'entity_id' => (int) $c['entity_id'], 'revision' => (int) $c['revision'], 'status' => $c['status'],
            'what_to_do' => 'History only: an earlier attempt of the same operation made before operations were unique. If it is COMPLETED, check the other product for a duplicate document.',
        ];
    }

    return $out;
}

/** @return array<int, array{qty: float, rejected: float}> by order line */
function receiptTotals(int $poId): array
{
    static $cache = [];
    if (isset($cache[$poId])) {
        return $cache[$poId];
    }
    $totals = [];
    foreach (Db::all(
        'SELECT applied_lines, requested_lines FROM purchase_receipt_requests WHERE po_id = :po AND applied_at IS NOT NULL',
        ['po' => $poId],
    ) as $r) {
        // Receipts applied before 006 recorded no applied_lines; what they asked for is what was added.
        $lines = Db::jsonColumn($r['applied_lines']) ?: Db::jsonColumn($r['requested_lines']);
        foreach ($lines as $l) {
            $id = (int) ($l['line_id'] ?? 0);
            $totals[$id]['qty'] = ($totals[$id]['qty'] ?? 0.0) + (float) ($l['qty'] ?? 0);
            $totals[$id]['rejected'] = ($totals[$id]['rejected'] ?? 0.0) + (float) ($l['rejected_qty'] ?? 0);
        }
    }

    return $cache[$poId] = $totals;
}

function entityStatus(string $entityType, int $entityId): ?string
{
    $table = match ($entityType) {
        'receipt_request' => ['purchase_receipt_requests', 'request_id'],
        'bill_request'    => ['purchase_bill_requests', 'request_id'],
        'purchase_return' => ['purchase_returns', 'return_id'],
        'purchase_order'  => ['purchase_orders', 'po_id'],
        default           => null,
    };
    if ($table === null) {
        return null;
    }
    $status = Db::scalar('SELECT status FROM ' . $table[0] . ' WHERE ' . $table[1] . ' = :id', ['id' => $entityId]);

    return $status === null ? null : (string) $status;
}

/** @return list<array<string, mixed>> */
function proposeActions(array $report): array
{
    $actions = [];
    foreach ($report['counter_drift'] as $d) {
        // Only where no legacy identity is involved: there the receipts themselves may
        // count goods Inventory never received, and the person has to decide first.
        $legacy = array_filter($report['legacy_identity'], static fn ($l) => $l['po_id'] === $d['po_id']);
        if ($legacy !== []) {
            continue;
        }
        $actions[] = [
            'action' => 'recount_order_line', 'cmp_id' => $d['cmp_id'], 'po_id' => $d['po_id'], 'line_id' => $d['line_id'],
            'expect_received_qty' => $d['order_counts'], 'set_received_qty' => $d['receipts_add_up_to'],
            'why' => sprintf('PO %s line %d counts %s received; its applied receipts add up to %s.', $d['po_no'], $d['line_no'], $d['order_counts'], $d['receipts_add_up_to']),
        ];
    }
    foreach ($report['orphan_commands'] as $c) {
        $actions[] = [
            'action' => 'withdraw_command', 'cmp_id' => $c['cmp_id'], 'command_id' => $c['command_id'], 'expect_status' => $c['status'],
            'why' => sprintf('%s %d is cancelled; its %s command can never be needed.', $c['entity_type'], $c['entity_id'], $c['status']),
        ];
    }
    $indexExists = Db::scalar("SELECT 1 FROM pg_indexes WHERE indexname = 'uq_purchase_bills_supplier_invoice'") !== null;
    if (!$indexExists) {
        $actions[] = [
            'action' => 'create_invoice_index',
            'why' => $report['duplicate_invoices'] === []
                ? 'No duplicate supplier invoices remain; the index that stops new ones can be created.'
                : 'Resolve the duplicate_invoices first; applying this before then is refused.',
        ];
    }

    return $actions;
}

function applyPlan(string $file, string $actor, string $reason): int
{
    if ($actor === '' || trim($reason) === '') {
        fwrite(STDERR, "--apply needs --actor=<your uuid> and --reason=\"…\"\n");

        return 1;
    }
    $plan = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
    if (!is_array($plan) || !is_array($plan['actions'] ?? null)) {
        fwrite(STDERR, "Not a plan written by --plan: {$file}\n");

        return 1;
    }
    $auth = Auth::operator($actor);
    $applied = 0;
    $skipped = 0;

    foreach ($plan['actions'] as $i => $a) {
        try {
            $result = Db::transaction(static function () use ($a, $auth, $reason): string {
                switch ($a['action'] ?? '') {
                    case 'recount_order_line':
                        $line = Db::first(
                            'SELECT l.*, o.fy_id, o.bo_id FROM purchase_order_lines l JOIN purchase_orders o ON o.po_id = l.po_id
                              WHERE l.line_id = :id AND l.po_id = :po AND l.cmp_id = :cmp FOR UPDATE OF l',
                            ['id' => (int) $a['line_id'], 'po' => (int) $a['po_id'], 'cmp' => (int) $a['cmp_id']],
                        );
                        PoProgress::lock((int) $a['po_id'], (int) $a['cmp_id']);
                        if ($line === null || abs((float) $line['received_qty'] - (float) $a['expect_received_qty']) > 0.0001) {
                            return 'skipped: the line changed since the plan was made';
                        }
                        $now = receiptTotals((int) $a['po_id'])[(int) $a['line_id']]['qty'] ?? 0.0;
                        if (abs($now - (float) $a['set_received_qty']) > 0.0001) {
                            return 'skipped: its receipts changed since the plan was made';
                        }
                        Db::run('UPDATE purchase_order_lines SET received_qty = :q, updated_at = NOW() WHERE line_id = :id', ['q' => $now, 'id' => (int) $a['line_id']]);
                        PoProgress::recompute((int) $a['po_id'], (int) $a['cmp_id'], $auth->uuid);
                        Audit::record(Context::of((int) $a['cmp_id'], (int) $line['fy_id'], (int) $line['bo_id']), $auth, 'repair.recount_order_line', 'purchase_order_line', (int) $a['line_id'],
                            ['received_qty' => (float) $line['received_qty']], ['received_qty' => $now], $reason);

                        return 'applied';

                    case 'withdraw_command':
                        $c = IntegrationCommand::byId((int) $a['command_id']);
                        if ($c === null || (int) $c['cmp_id'] !== (int) $a['cmp_id'] || $c['status'] !== $a['expect_status']) {
                            return 'skipped: the command changed since the plan was made';
                        }
                        if (entityStatus((string) $c['entity_type'], (int) $c['entity_id']) !== 'CANCELLED') {
                            return 'skipped: its document is no longer cancelled';
                        }
                        if (!IntegrationCommand::withdraw((int) $c['command_id'], 'Withdrawn by repair: ' . $reason, 'repair')) {
                            return 'skipped: it is no longer in a state that can be withdrawn';
                        }
                        Audit::record(Context::of((int) $c['cmp_id'], (int) $c['fy_id'], (int) $c['bo_id']), $auth, 'repair.withdraw_command', 'integration_command', (int) $c['command_id'],
                            ['status' => $c['status']], ['status' => IntegrationCommand::CANCELLED], $reason);

                        return 'applied';

                    case 'create_invoice_index':
                        $dupes = Db::scalar(
                            "SELECT COUNT(*) FROM (SELECT 1 FROM purchase_bill_requests WHERE status <> 'CANCELLED' AND supplier_invoice_no IS NOT NULL
                              GROUP BY cmp_id, supplier_account_id, lower(supplier_invoice_no) HAVING COUNT(*) > 1) d",
                        );
                        if ((int) $dupes > 0) {
                            return 'skipped: ' . $dupes . ' duplicate supplier invoice group(s) remain';
                        }
                        Db::run("CREATE UNIQUE INDEX IF NOT EXISTS uq_purchase_bills_supplier_invoice
                                   ON purchase_bill_requests (cmp_id, supplier_account_id, lower(supplier_invoice_no))
                                   WHERE status <> 'CANCELLED' AND supplier_invoice_no IS NOT NULL");

                        return 'applied';

                    default:
                        return 'skipped: not an action this tool applies';
                }
            });
        } catch (\Throwable $e) {
            $result = 'skipped: ' . $e->getMessage();
        }
        str_starts_with($result, 'applied') ? $applied++ : $skipped++;
        fwrite(STDOUT, sprintf("%3d  %-22s %s\n", $i + 1, (string) ($a['action'] ?? '?'), $result));
    }
    fwrite(STDOUT, sprintf("%d applied, %d skipped.\n", $applied, $skipped));

    return 0;
}

function countFindings(array $report): int
{
    return array_sum(array_map('count', $report));
}

function printReport(array $report): void
{
    foreach ($report as $kind => $rows) {
        fwrite(STDOUT, sprintf("%-22s %d\n", $kind, count($rows)));
        foreach ($rows as $row) {
            $what = $row['what_to_do'] ?? null;
            unset($row['what_to_do']);
            fwrite(STDOUT, '    ' . json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
            if ($what !== null) {
                fwrite(STDOUT, '      → ' . $what . "\n");
            }
        }
    }
}

/** @return list<string> */
function pgArray(mixed $value): array
{
    if (is_array($value)) {
        return array_map('strval', $value);
    }
    $inner = trim((string) $value, '{}');

    return $inner === '' ? [] : array_map(static fn ($v) => trim($v, '"'), explode(',', $inner));
}
