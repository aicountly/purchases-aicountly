<?php

declare(strict_types=1);

/**
 * Integration tests for the Purchases domain.
 *
 * Against a REAL PostgreSQL database and a stub standing in for Books and
 * Inventory, so what is tested is the actual SQL, the actual HTTP client and the
 * actual idempotency behaviour.
 *
 *   server-php/tests/run.sh
 */

namespace Aicountly\Api;

require __DIR__ . '/../src/Env.php';
require __DIR__ . '/../src/Autoload.php';

Env::load(__DIR__ . '/../.env');

use Aicountly\Api\Domain\BillService;
use Aicountly\Api\Domain\PurchaseOrderService;
use Aicountly\Api\Domain\ReceiptService;
use Aicountly\Api\Domain\RequisitionService;
use Aicountly\Api\Domain\ReturnClaimService;
use Aicountly\Api\Domain\SourcingService;
use Aicountly\Api\Domain\ThreeWayMatchService;

$passed = 0;
$failed = 0;

function check(string $name, callable $fn): void
{
    global $passed, $failed;
    try {
        $fn();
        echo "  ok    {$name}\n";
        $passed++;
    } catch (\Throwable $e) {
        echo "  FAIL  {$name}\n        {$e->getMessage()}\n";
        if (getenv('VERBOSE')) {
            echo '        ' . $e->getFile() . ':' . $e->getLine() . "\n";
        }
        $failed++;
    }
}

function assertSame(mixed $expected, mixed $actual, string $what): void
{
    if ($expected !== $actual) {
        throw new \RuntimeException(sprintf('%s: expected %s, got %s', $what, var_export($expected, true), var_export($actual, true)));
    }
}

function assertTrue(bool $condition, string $what): void
{
    if (!$condition) {
        throw new \RuntimeException($what);
    }
}

function assertThrows(callable $fn, string $expectFragment, string $what): void
{
    try {
        $fn();
    } catch (\Throwable $e) {
        if ($expectFragment !== '' && !str_contains($e->getMessage(), $expectFragment)) {
            throw new \RuntimeException($what . ': wrong error — ' . $e->getMessage());
        }

        return;
    }
    throw new \RuntimeException($what . ': expected a failure, none was thrown');
}

/** Context has readonly properties, so each is set exactly once. */
function freshContext(int $cmpId = 88, int $fyId = 6, int $boId = 0): Context
{
    $r = new \ReflectionClass(Context::class);
    $ctx = $r->newInstanceWithoutConstructor();
    foreach (['cmpId' => $cmpId, 'fyId' => $fyId, 'boId' => $boId] as $prop => $value) {
        $p = $r->getProperty($prop);
        $p->setAccessible(true);
        $p->setValue($ctx, $value);
    }

    return $ctx;
}

function authFor(string $uuid = 'user-owner', ?int $acsType = 1): Auth
{
    $r = new \ReflectionClass(Auth::class);
    $auth = $r->newInstanceWithoutConstructor();
    foreach ([
        'uuid'      => $uuid,
        'kind'      => 'user',
        'sourceApp' => 'purchases',
        'sesKey'    => 'stub-ses-key',
        'session'   => ['acs_type' => $acsType, 'name' => $uuid],
    ] as $prop => $value) {
        $p = $r->getProperty($prop);
        $p->setAccessible(true);
        $p->setValue($auth, $value);
    }

    return $auth;
}

function resetDatabase(): void
{
    $tables = [
        'purchase_match_exceptions', 'purchase_match_results', 'purchase_match_policies',
        'purchase_bill_requests', 'purchase_receipt_requests',
        'purchase_return_lines', 'purchase_returns', 'purchase_claims',
        'purchase_delivery_schedules', 'purchase_order_lines', 'purchase_orders',
        'purchase_agreement_lines', 'purchase_agreements',
        'purchase_bid_awards', 'purchase_quote_lines', 'purchase_quotes',
        'purchase_rfq_invitations', 'purchase_rfq_lines', 'purchase_rfqs',
        'purchase_requisition_lines', 'purchase_requisitions',
        'purchase_approval_requests', 'purchase_approval_rules',
        'purchase_supplier_scorecards', 'purchase_supplier_profiles',
        'purchase_integration_commands', 'purchase_permission_assignments',
        'purchase_permission_profiles', 'purchase_settings', 'purchase_user_preferences',
    ];
    Db::connect()->exec('TRUNCATE ' . implode(', ', $tables) . ', purchase_audit_log RESTART IDENTITY CASCADE');
    @unlink(sys_get_temp_dir() . '/stub-idempotency.json');
    @unlink(sys_get_temp_dir() . '/stub-requests.jsonl');
    @unlink(sys_get_temp_dir() . '/stub-documents.json');
    stubRecover();
}

function stubFail(string $pathFragment, int $status): void
{
    file_put_contents(sys_get_temp_dir() . '/stub-control.json', json_encode(['path' => $pathFragment, 'status' => $status]));
}

function stubRecover(): void
{
    @unlink(sys_get_temp_dir() . '/stub-control.json');
}

/** @return list<array<string, mixed>> */
function stubRequests(): array
{
    $log = sys_get_temp_dir() . '/stub-requests.jsonl';
    if (!is_file($log)) {
        return [];
    }
    $out = [];
    foreach (explode("\n", trim((string) file_get_contents($log))) as $line) {
        if ($line !== '') {
            $out[] = json_decode($line, true);
        }
    }

    return $out;
}

function poInput(array $overrides = []): array
{
    return $overrides + [
        'supplier_account_id' => 601,
        'supplier_name'       => 'Deccan Steel Traders',
        'po_date'             => '2026-09-01',
        'promised_date'       => '2026-09-20',
        'delivery_warehouse_id' => 3,
        'lines' => [
            ['item_id' => 201, 'unit_id' => 1, 'ordered_qty' => 100, 'agreed_rate' => 250, 'estimated_tax_pc' => 18, 'warehouse_id' => 3],
            ['item_id' => 202, 'unit_id' => 1, 'ordered_qty' => 40,  'agreed_rate' => 900, 'estimated_tax_pc' => 18, 'warehouse_id' => 3],
        ],
    ];
}

/** A purchase order taken all the way to RECEIVED, for the matching tests. */
function receivedOrder(Context $ctx, Auth $auth): array
{
    $orders = new PurchaseOrderService($ctx, $auth);
    $po = $orders->create(poInput());
    $orders->submit((int) $po['po_id']);
    $orders->issue((int) $po['po_id']);
    (new ReceiptService($ctx, $auth))->request((int) $po['po_id'], ['received_at' => '2026-09-18']);

    return $orders->find((int) $po['po_id']);
}

// ---------------------------------------------------------------------------

$ctx = freshContext();
$auth = authFor();

echo "\nRequisitions\n";

check('creates a requisition and totals its estimated value', function () use ($ctx, $auth) {
    resetDatabase();
    $requisition = (new RequisitionService($ctx, $auth))->create([
        'department' => 'Production',
        'lines' => [
            ['item_id' => 201, 'required_qty' => 100, 'estimated_rate' => 240],
            ['item_id' => 202, 'required_qty' => 40, 'estimated_rate' => 880],
        ],
    ]);

    assertTrue(str_starts_with((string) $requisition['requisition_no'], 'PR/6/'), 'number carries prefix and year');
    // 100 x 240 = 24000 ; 40 x 880 = 35200 → 59200
    assertSame('59200.0000', (string) $requisition['estimated_value'], 'estimated value');
    assertSame('DRAFT', $requisition['status'], 'starts as a draft');
});

check('an emergency flag sends it to approval even under the threshold', function () use ($ctx, $auth) {
    resetDatabase();
    $service = new RequisitionService($ctx, $auth);
    $requisition = $service->create([
        'emergency' => true,
        'lines' => [['item_id' => 201, 'required_qty' => 1, 'estimated_rate' => 10]],
    ]);
    $submitted = $service->submit((int) $requisition['requisition_id']);

    assertSame('APPROVAL_PENDING', $submitted['status'], 'status');
    assertSame('emergency', $submitted['approvals'][0]['reason_kind'], 'reason');
});

check('the requester cannot approve their own requisition', function () use ($ctx) {
    resetDatabase();
    // A delegated user, not the owner — an owner bypasses every check by design.
    $buyer = authFor('buyer-anita', 0);
    Db::insert('purchase_permission_profiles', [
        'cmp_id' => $ctx->cmpId, 'profile_name' => 'Buyer',
        'permissions' => ['requisition.create', 'requisition.view', 'requisition.approve'],
    ], 'profile_id');
    $profileId = (int) Db::scalar('SELECT profile_id FROM purchase_permission_profiles WHERE cmp_id = :cmp', ['cmp' => $ctx->cmpId]);
    Db::insert('purchase_permission_assignments', [
        'cmp_id' => $ctx->cmpId, 'user_uuid' => 'buyer-anita', 'profile_id' => $profileId,
    ], 'assignment_id');

    $service = new RequisitionService($ctx, $buyer);
    $requisition = $service->create([
        'emergency' => true,
        'lines' => [['item_id' => 201, 'required_qty' => 1, 'estimated_rate' => 10]],
    ]);
    $service->submit((int) $requisition['requisition_id']);

    assertThrows(
        static fn () => $service->decide((int) $requisition['requisition_id'], 'approve', ['note' => 'fine']),
        'somebody else has to approve',
        'self-approval',
    );
});

check('rejecting requires a reason', function () use ($ctx, $auth) {
    resetDatabase();
    $service = new RequisitionService($ctx, $auth);
    $requisition = $service->create(['emergency' => true, 'lines' => [['item_id' => 201, 'required_qty' => 1, 'estimated_rate' => 10]]]);
    $service->submit((int) $requisition['requisition_id']);

    assertThrows(
        static fn () => $service->decide((int) $requisition['requisition_id'], 'reject', []),
        'Say why',
        'reasonless rejection',
    );
});

echo "\nSourcing\n";

check('records competing quotes and compares them on landed cost', function () use ($ctx, $auth) {
    resetDatabase();
    $service = new SourcingService($ctx, $auth);
    $rfq = $service->createRfq([
        'title' => 'Q3 steel',
        'supplier_account_ids' => [601, 602],
        'lines' => [['item_id' => 201, 'required_qty' => 100]],
    ]);
    $rfqLineId = (int) $rfq['lines'][0]['line_id'];
    $service->issueRfq((int) $rfq['rfq_id']);

    // 601 is cheaper per unit but charges heavy freight; 602 is dearer but free.
    $service->recordQuote((int) $rfq['rfq_id'], [
        'supplier_account_id' => 601,
        'freight_amount' => 9000,
        'lines' => [['rfq_line_id' => $rfqLineId, 'item_id' => 201, 'quoted_qty' => 100, 'quoted_rate' => 240]],
    ]);
    $service->recordQuote((int) $rfq['rfq_id'], [
        'supplier_account_id' => 602,
        'freight_amount' => 0,
        'lines' => [['rfq_line_id' => $rfqLineId, 'item_id' => 201, 'quoted_qty' => 100, 'quoted_rate' => 250]],
    ]);

    $comparison = $service->compare((int) $rfq['rfq_id']);
    assertSame(2, count($comparison['quotes']), 'both quotes compared');

    // 601: 24000 + 9000 = 33000. 602: 25000 + 0 = 25000. The cheapest unit rate
    // is NOT the cheapest purchase, which is the whole point of the column.
    $bySupplier = [];
    foreach ($comparison['quotes'] as $quote) {
        $bySupplier[$quote['supplier_account_id']] = $quote['estimated_landed_total'];
    }
    assertSame(33000.0, $bySupplier[601], 'supplier 601 landed total');
    assertSame(25000.0, $bySupplier[602], 'supplier 602 landed total');
    assertSame(25000.0, $comparison['lowest_landed'], 'lowest landed total');

    // The per-line "best" is still the lowest unit rate, which is the number a
    // split award works from.
    assertSame(601, $comparison['best_by_line'][$rfqLineId]['supplier_account_id'], 'cheapest unit rate');
});

check('a revised quote is a new row and the original survives', function () use ($ctx, $auth) {
    resetDatabase();
    $service = new SourcingService($ctx, $auth);
    $rfq = $service->createRfq(['supplier_account_ids' => [601], 'lines' => [['item_id' => 201, 'required_qty' => 10]]]);
    $rfqLineId = (int) $rfq['lines'][0]['line_id'];

    $service->recordQuote((int) $rfq['rfq_id'], [
        'supplier_account_id' => 601,
        'lines' => [['rfq_line_id' => $rfqLineId, 'item_id' => 201, 'quoted_qty' => 10, 'quoted_rate' => 300]],
    ]);
    $after = $service->recordQuote((int) $rfq['rfq_id'], [
        'supplier_account_id' => 601,
        'lines' => [['rfq_line_id' => $rfqLineId, 'item_id' => 201, 'quoted_qty' => 10, 'quoted_rate' => 280]],
    ]);

    assertSame(2, count($after['quotes']), 'both revisions kept');
    // Only the latest revision is compared.
    $comparison = $service->compare((int) $rfq['rfq_id']);
    assertSame(1, count($comparison['quotes']), 'the comparison uses one revision per supplier');
    assertSame(1, $comparison['quotes'][0]['revision_no'], 'and it is the latest');
});

check('awarding records the rationale and rejects the losing quotes', function () use ($ctx, $auth) {
    resetDatabase();
    $service = new SourcingService($ctx, $auth);
    $rfq = $service->createRfq(['supplier_account_ids' => [601, 602], 'lines' => [['item_id' => 201, 'required_qty' => 100]]]);
    $rfqLineId = (int) $rfq['lines'][0]['line_id'];

    $service->recordQuote((int) $rfq['rfq_id'], ['supplier_account_id' => 601, 'lines' => [['rfq_line_id' => $rfqLineId, 'item_id' => 201, 'quoted_qty' => 100, 'quoted_rate' => 240]]]);
    $service->recordQuote((int) $rfq['rfq_id'], ['supplier_account_id' => 602, 'lines' => [['rfq_line_id' => $rfqLineId, 'item_id' => 201, 'quoted_qty' => 100, 'quoted_rate' => 250]]]);

    $quotes = Db::all('SELECT quote_id, supplier_account_id FROM purchase_quotes WHERE rfq_id = :rfq', ['rfq' => (int) $rfq['rfq_id']]);
    $winner = null;
    foreach ($quotes as $quote) {
        if ((int) $quote['supplier_account_id'] === 602) {
            $winner = (int) $quote['quote_id'];
        }
    }

    $awarded = $service->award((int) $rfq['rfq_id'], [
        'awards' => [[
            'rfq_line_id' => $rfqLineId, 'quote_id' => $winner, 'qty' => 100, 'rate' => 250,
            'rationale' => 'Dearer per unit but delivers free, and 601 is two weeks out.',
        ]],
    ]);

    assertSame('AWARDED', $awarded['status'], 'RFQ status');
    assertSame(1, count($awarded['awards']), 'one award recorded');
    assertTrue(str_contains((string) $awarded['awards'][0]['rationale'], 'delivers free'), 'the rationale is kept');

    $statuses = [];
    foreach ($awarded['quotes'] as $quote) {
        $statuses[(int) $quote['supplier_account_id']] = $quote['status'];
    }
    assertSame('AWARDED', $statuses[602], 'winner');
    assertSame('REJECTED', $statuses[601], 'loser');
});

echo "\nPurchase orders and receiving\n";

check('creates a purchase order and totals it', function () use ($ctx, $auth) {
    resetDatabase();
    $po = (new PurchaseOrderService($ctx, $auth))->create(poInput());

    assertTrue(str_starts_with((string) $po['po_no'], 'PO/6/'), 'number');
    // 100 x 250 = 25000 ; 40 x 900 = 36000 → 61000, tax 18% = 10980
    assertSame('61000.0000', (string) $po['subtotal_amount'], 'subtotal');
    assertSame('10980.0000', (string) $po['estimated_tax_amount'], 'estimated tax');
    assertSame('71980.0000', (string) $po['total_amount'], 'total');
});

check('refuses an unapproved supplier when the company enforces it', function () use ($ctx, $auth) {
    resetDatabase();
    Db::insert('purchase_settings', ['cmp_id' => $ctx->cmpId, 'enforce_approved_vendors' => true], 'cmp_id');

    assertThrows(
        static fn () => (new PurchaseOrderService($ctx, $auth))->create(poInput()),
        'only orders from approved suppliers',
        'unapproved vendor',
    );
});

check('allows an approved supplier when the company enforces it', function () use ($ctx, $auth) {
    resetDatabase();
    Db::insert('purchase_settings', ['cmp_id' => $ctx->cmpId, 'enforce_approved_vendors' => true], 'cmp_id');
    Db::insert('purchase_supplier_profiles', [
        'cmp_id' => $ctx->cmpId, 'supplier_account_id' => 601, 'qualification_status' => 'approved',
    ], 'profile_id');

    $po = (new PurchaseOrderService($ctx, $auth))->create(poInput());
    assertTrue((int) $po['po_id'] > 0, 'the order was created');
});

check('receiving asks Inventory and records progress from its answer', function () use ($ctx, $auth) {
    resetDatabase();
    $po = receivedOrder($ctx, $auth);

    assertSame('RECEIVED', $po['status'], 'everything outstanding arrived');
    assertSame('100.0000', (string) $po['lines'][0]['received_qty'], 'received quantity');
    assertSame('ACCEPTED', $po['receipts'][0]['status'], 'the request was accepted');
    assertTrue($po['receipts'][0]['inventory_document_uuid'] !== null, 'the GRN reference was kept');
});

check('refuses to receive more than was ordered', function () use ($ctx, $auth) {
    resetDatabase();
    $orders = new PurchaseOrderService($ctx, $auth);
    $po = $orders->create(poInput());
    $orders->submit((int) $po['po_id']);
    $orders->issue((int) $po['po_id']);
    $lineId = (int) $po['lines'][0]['line_id'];

    assertThrows(
        static fn () => (new ReceiptService($ctx, $auth))->request((int) $po['po_id'], [
            'lines' => [['line_id' => $lineId, 'qty' => 5000]],
        ]),
        'Amend the purchase order',
        'over-receipt',
    );
});

check('an unreachable Inventory leaves the receipt retryable on the same key', function () use ($ctx, $auth) {
    resetDatabase();
    $orders = new PurchaseOrderService($ctx, $auth);
    $po = $orders->create(poInput());
    $orders->submit((int) $po['po_id']);
    $orders->issue((int) $po['po_id']);

    stubFail('inventory-documents', 500);
    assertThrows(
        static fn () => (new ReceiptService($ctx, $auth))->request((int) $po['po_id'], []),
        'press Retry',
        'receipt during an outage',
    );
    stubRecover();

    $command = Db::first(
        'SELECT * FROM purchase_integration_commands WHERE command_type = :t ORDER BY command_id DESC LIMIT 1',
        ['t' => ReceiptService::COMMAND_RECEIPT],
    );
    assertSame('FAILED', $command['status'], 'FAILED, so it can be retried');
    $firstKey = $command['idempotency_key'];

    $requestId = (int) Db::scalar("SELECT request_id FROM purchase_receipt_requests WHERE status = 'FAILED' ORDER BY request_id DESC LIMIT 1");
    (new ReceiptService($ctx, $auth))->retry($requestId);

    $keys = array_values(array_unique(array_filter(array_map(
        static fn ($r) => $r['headers']['idempotency-key'] ?? null,
        array_filter(stubRequests(), static fn ($r) => str_contains($r['path'], 'inventory-documents')),
    ))));
    assertSame(1, count($keys), 'both attempts presented the same key');
    assertSame($firstKey, $keys[0], 'and it is the original one');
});

echo "\nThree-way match\n";

check('a clean bill matches', function () use ($ctx, $auth) {
    resetDatabase();
    $po = receivedOrder($ctx, $auth);
    $lines = $po['lines'];

    $bill = (new BillService($ctx, $auth))->enter([
        'supplier_account_id'   => 601,
        'po_id'                 => (int) $po['po_id'],
        'supplier_invoice_no'   => 'DST/2026/0912',
        'supplier_invoice_date' => '2026-09-19',
        'lines' => [
            ['po_line_id' => (int) $lines[0]['line_id'], 'qty' => 100, 'rate' => 250],
            ['po_line_id' => (int) $lines[1]['line_id'], 'qty' => 40, 'rate' => 900],
        ],
    ]);

    assertSame(ThreeWayMatchService::MATCHED, $bill['match']['verdict'], 'verdict');
    assertSame(0, count($bill['match']['exceptions']), 'no exceptions');
    assertSame('MATCHED', $bill['status'], 'bill status');
});

check('billing more than was received is BLOCKED', function () use ($ctx, $auth) {
    resetDatabase();
    $po = receivedOrder($ctx, $auth);

    $bill = (new BillService($ctx, $auth))->enter([
        'supplier_account_id' => 601,
        'po_id'               => (int) $po['po_id'],
        'supplier_invoice_no' => 'DST/2026/0913',
        'lines' => [['po_line_id' => (int) $po['lines'][0]['line_id'], 'qty' => 150, 'rate' => 250]],
    ]);

    assertSame(ThreeWayMatchService::BLOCKED, $bill['match']['verdict'], 'verdict');
    assertSame('over_billed', $bill['match']['exceptions'][0]['exception_kind'], 'exception kind');
    assertSame('EXCEPTION', $bill['status'], 'bill status');
});

check('billing above the agreed rate is BLOCKED', function () use ($ctx, $auth) {
    resetDatabase();
    $po = receivedOrder($ctx, $auth);

    $bill = (new BillService($ctx, $auth))->enter([
        'supplier_account_id' => 601,
        'po_id'               => (int) $po['po_id'],
        'supplier_invoice_no' => 'DST/2026/0914',
        // 250 agreed, 275 billed — a 10% increase.
        'lines' => [['po_line_id' => (int) $po['lines'][0]['line_id'], 'qty' => 100, 'rate' => 275]],
    ]);

    assertSame(ThreeWayMatchService::BLOCKED, $bill['match']['verdict'], 'verdict');
    assertSame('rate', $bill['match']['exceptions'][0]['exception_kind'], 'exception kind');
});

check('billing BELOW the agreed rate is noted, not blocked', function () use ($ctx, $auth) {
    resetDatabase();
    $po = receivedOrder($ctx, $auth);

    $bill = (new BillService($ctx, $auth))->enter([
        'supplier_account_id' => 601,
        'po_id'               => (int) $po['po_id'],
        'supplier_invoice_no' => 'DST/2026/0915',
        'lines' => [['po_line_id' => (int) $po['lines'][0]['line_id'], 'qty' => 100, 'rate' => 230]],
    ]);

    // A supplier charging less is in our favour. Blocking it is a support ticket.
    assertSame(0, count($bill['match']['exceptions']), 'no exception raised');
    assertTrue(count($bill['match']['variances']) > 0, 'but the variance is recorded');
});

check('a rate tolerance lets a small increase through', function () use ($ctx, $auth) {
    resetDatabase();
    Db::insert('purchase_match_policies', [
        'cmp_id' => $ctx->cmpId, 'policy_name' => 'Standard',
        'rate_tolerance_pc' => 5, 'is_default' => true,
    ], 'policy_id');

    $po = receivedOrder($ctx, $auth);
    $bill = (new BillService($ctx, $auth))->enter([
        'supplier_account_id' => 601,
        'po_id'               => (int) $po['po_id'],
        'supplier_invoice_no' => 'DST/2026/0916',
        // 250 → 255 is 2%, inside the 5% tolerance.
        'lines' => [['po_line_id' => (int) $po['lines'][0]['line_id'], 'qty' => 100, 'rate' => 255]],
    ]);

    assertSame(ThreeWayMatchService::WITHIN_TOLERANCE, $bill['match']['verdict'], 'verdict');
    assertSame(0, count($bill['match']['exceptions']), 'no exception');
});

check('billing against nothing received is BLOCKED', function () use ($ctx, $auth) {
    resetDatabase();
    $orders = new PurchaseOrderService($ctx, $auth);
    $po = $orders->create(poInput());
    $orders->submit((int) $po['po_id']);
    $orders->issue((int) $po['po_id']);

    $bill = (new BillService($ctx, $auth))->enter([
        'supplier_account_id' => 601,
        'po_id'               => (int) $po['po_id'],
        'supplier_invoice_no' => 'DST/2026/0917',
        'lines' => [['po_line_id' => (int) $po['lines'][0]['line_id'], 'qty' => 100, 'rate' => 250]],
    ]);

    assertSame(ThreeWayMatchService::BLOCKED, $bill['match']['verdict'], 'verdict');
    assertSame('missing_receipt', $bill['match']['exceptions'][0]['exception_kind'], 'exception kind');
});

check('a line not on the purchase order is flagged', function () use ($ctx, $auth) {
    resetDatabase();
    $po = receivedOrder($ctx, $auth);

    $bill = (new BillService($ctx, $auth))->enter([
        'supplier_account_id' => 601,
        'po_id'               => (int) $po['po_id'],
        'supplier_invoice_no' => 'DST/2026/0918',
        'lines' => [
            ['po_line_id' => (int) $po['lines'][0]['line_id'], 'qty' => 100, 'rate' => 250],
            // A line naming no PO line at all — the classic padded invoice.
            ['po_line_id' => 0, 'item_id' => 999, 'qty' => 5, 'rate' => 1000],
        ],
    ]);

    $kinds = array_column($bill['match']['exceptions'], 'exception_kind');
    assertTrue(in_array('value', $kinds, true), 'the unordered line was flagged');
});

check('an unreachable Inventory means REVIEW_REQUIRED, never MATCHED', function () use ($ctx, $auth) {
    resetDatabase();
    $po = receivedOrder($ctx, $auth);

    stubFail('by-source', 500);
    $bill = (new BillService($ctx, $auth))->enter([
        'supplier_account_id' => 601,
        'po_id'               => (int) $po['po_id'],
        'supplier_invoice_no' => 'DST/2026/0919',
        'lines' => [['po_line_id' => (int) $po['lines'][0]['line_id'], 'qty' => 100, 'rate' => 250]],
    ]);
    stubRecover();

    assertSame(ThreeWayMatchService::REVIEW_REQUIRED, $bill['match']['verdict'], 'verdict');
    assertSame(false, $bill['match']['receipts_reachable'], 'and it says why');
});

check('posting is refused while an exception is open, and allowed once resolved', function () use ($ctx, $auth) {
    resetDatabase();
    $po = receivedOrder($ctx, $auth);
    $bills = new BillService($ctx, $auth);

    $bill = $bills->enter([
        'supplier_account_id' => 601,
        'po_id'               => (int) $po['po_id'],
        'supplier_invoice_no' => 'DST/2026/0920',
        'lines' => [['po_line_id' => (int) $po['lines'][0]['line_id'], 'qty' => 100, 'rate' => 275]],
    ]);
    $requestId = (int) $bill['request_id'];

    assertThrows(
        static fn () => $bills->post($requestId),
        'unresolved match exception',
        'posting a blocked bill',
    );

    $exceptionId = (int) $bill['matches'][0]['exceptions'][0]['exception_id'];
    assertThrows(
        static fn () => $bills->resolveException($exceptionId, 'accept', []),
        'Say why',
        'accepting without a reason',
    );

    $bills->resolveException($exceptionId, 'accept', ['note' => 'Agreed with the supplier by email on 20 Sep.']);
    $posted = $bills->post($requestId);

    assertSame('POSTED', $posted['status'], 'the bill posted once the variance was accepted');
    assertTrue($posted['books_voucher_id'] !== null, 'and Books gave it a voucher id');
});

check('a duplicate supplier invoice number is refused', function () use ($ctx, $auth) {
    resetDatabase();
    $po = receivedOrder($ctx, $auth);
    $bills = new BillService($ctx, $auth);

    $payload = [
        'supplier_account_id' => 601,
        'po_id'               => (int) $po['po_id'],
        'supplier_invoice_no' => 'DST/2026/0921',
        'lines' => [['po_line_id' => (int) $po['lines'][0]['line_id'], 'qty' => 100, 'rate' => 250]],
    ];
    $bills->enter($payload);

    assertThrows(
        static fn () => $bills->enter($payload),
        'already been entered',
        'duplicate invoice number',
    );
});

check('posting a bill twice is refused, so Books gets one voucher', function () use ($ctx, $auth) {
    resetDatabase();
    $po = receivedOrder($ctx, $auth);
    $bills = new BillService($ctx, $auth);

    $bill = $bills->enter([
        'supplier_account_id' => 601,
        'po_id'               => (int) $po['po_id'],
        'supplier_invoice_no' => 'DST/2026/0922',
        'lines' => [['po_line_id' => (int) $po['lines'][0]['line_id'], 'qty' => 100, 'rate' => 250]],
    ]);
    $requestId = (int) $bill['request_id'];
    $bills->post($requestId);

    assertThrows(static fn () => $bills->post($requestId), 'already been posted', 'second post');

    assertSame(1, (int) Db::scalar("SELECT COUNT(*) FROM purchase_bill_requests WHERE status = 'POSTED'"), 'one posted bill');
});

echo "\nReturns and claims\n";

check('a return runs approve, dispatch and debit note, keeping only references', function () use ($ctx, $auth) {
    resetDatabase();
    $po = receivedOrder($ctx, $auth);
    $returns = new ReturnClaimService($ctx, $auth);

    $return = $returns->createReturn([
        'supplier_account_id' => 601,
        'po_id'               => (int) $po['po_id'],
        'reason_code'         => 'quality',
        'lines' => [[
            'po_line_id' => (int) $po['lines'][0]['line_id'],
            'item_id' => 201, 'warehouse_id' => 3, 'return_qty' => 10, 'rate' => 250,
        ]],
    ]);

    $returns->approveReturn((int) $return['return_id'], []);
    $dispatched = $returns->dispatchReturn((int) $return['return_id']);
    assertSame('DISPATCHED', $dispatched['status'], 'status after dispatch');
    assertTrue($dispatched['inventory_document_uuid'] !== null, 'Inventory reference kept');

    $debited = $returns->requestDebitNote((int) $return['return_id']);
    assertSame('DEBITED', $debited['status'], 'status after the debit note');
    assertTrue($debited['books_debit_note_uuid'] !== null, 'Books reference kept');
});

check('a claim moves through its lifecycle and cannot over-settle', function () use ($ctx, $auth) {
    resetDatabase();
    $service = new ReturnClaimService($ctx, $auth);

    $claim = $service->createClaim([
        'supplier_account_id' => 601,
        'claim_kind'          => 'shortage',
        'claimed_amount'      => 5000,
        'description'         => 'Four bundles short against DC 88213.',
    ]);
    $claimId = (int) $claim['claim_id'];

    $service->updateClaim($claimId, 'submit', []);
    $service->updateClaim($claimId, 'approve', []);

    assertThrows(
        static fn () => $service->updateClaim($claimId, 'settle', ['settled_amount' => 9000]),
        'cannot settle for more',
        'over-settlement',
    );

    $settled = $service->updateClaim($claimId, 'settle', ['settled_amount' => 4500]);
    assertSame('SETTLED', $settled['status'], 'status');
    assertSame('4500.0000', (string) $settled['settled_amount'], 'settled amount');
});

check('an unknown claim kind is refused', function () use ($ctx, $auth) {
    resetDatabase();
    assertThrows(
        static fn () => (new ReturnClaimService($ctx, $auth))->createClaim([
            'supplier_account_id' => 601, 'claim_kind' => 'made_up', 'claimed_amount' => 10,
        ]),
        'Claim kind must be',
        'unknown claim kind',
    );
});

echo "\nData ownership (release-blocking)\n";

check('no table mirrors an item, supplier, GRN, stock or invoice', function () {
    $forbidden = Db::all(
        "SELECT table_name FROM information_schema.tables
         WHERE table_schema = 'public'
           AND (table_name LIKE '%item%master%' OR table_name = 'purchase_items'
                OR table_name LIKE '%supplier_ledger%' OR table_name LIKE '%vendor_ledger%'
                OR table_name LIKE '%stock%' OR table_name LIKE '%grn%'
                OR table_name LIKE '%mirror%' OR table_name LIKE '%_cache'
                OR table_name LIKE '%payable%' OR table_name LIKE '%warehouse%'
                OR table_name LIKE '%invoice%')",
    );
    assertSame(0, count($forbidden), 'forbidden mirror tables exist: ' . json_encode($forbidden));
});

check('no column caches a remote name, stock figure, balance or valuation', function () {
    $suspicious = Db::all(
        "SELECT table_name, column_name FROM information_schema.columns
         WHERE table_schema = 'public'
           AND (column_name IN ('item_name', 'item_sku', 'warehouse_name', 'supplier_name',
                                'stock_qty', 'available_qty', 'on_hand', 'valuation_rate',
                                'cost_rate', 'outstanding', 'payable_balance', 'last_synced_at', 'synced_at')
                OR column_name LIKE 'sync%')",
    );
    assertSame(0, count($suspicious), 'cached remote fields exist: ' . json_encode($suspicious));
});

check('every remote reference is stored as an id, uuid or document number', function () {
    $references = Db::all(
        "SELECT table_name, column_name FROM information_schema.columns
         WHERE table_schema = 'public'
           AND (column_name LIKE 'books_%' OR column_name LIKE 'inventory_%'
                OR column_name IN ('item_id', 'unit_id', 'warehouse_id', 'batch_id',
                                   'supplier_account_id', 'tax_cat_id', 'cost_centre_id',
                                   'project_id', 'contract_uuid'))",
    );
    foreach ($references as $ref) {
        $name = $ref['column_name'];
        $isIdentifier = str_ends_with($name, '_id') || str_ends_with($name, '_uuid') || str_ends_with($name, '_no');
        assertTrue($isIdentifier, "{$ref['table_name']}.{$name} is a remote reference that is not an id/uuid/no");
    }
    assertTrue(count($references) > 0, 'the schema does hold remote references');
});

check('the three-way match stores its verdict, not the documents it compared', function () use ($ctx, $auth) {
    resetDatabase();
    $po = receivedOrder($ctx, $auth);
    (new BillService($ctx, $auth))->enter([
        'supplier_account_id' => 601,
        'po_id'               => (int) $po['po_id'],
        'supplier_invoice_no' => 'DST/2026/0930',
        'lines' => [['po_line_id' => (int) $po['lines'][0]['line_id'], 'qty' => 100, 'rate' => 250]],
    ]);

    $match = Db::first('SELECT * FROM purchase_match_results ORDER BY match_id DESC LIMIT 1');
    $compared = Db::jsonColumn($match['compared_references']);

    // References only: ids and a timestamp. No GRN lines, no invoice body.
    foreach (array_keys($compared) as $key) {
        assertTrue(
            in_array($key, ['po_id', 'bill_request_id', 'receipt_references', 'evaluated_at'], true),
            "compared_references holds '{$key}', which is more than a reference",
        );
    }
});

check('the audit log refuses UPDATE and DELETE', function () {
    Db::run(
        'INSERT INTO purchase_audit_log (cmp_id, fy_id, actor_uuid, actor_kind, source_app, action, entity_type)
         VALUES (88, 6, :u, :k, :a, :act, :e)',
        ['u' => 'tester', 'k' => 'user', 'a' => 'purchases', 'act' => 'test', 'e' => 'purchase_order'],
    );
    assertThrows(static fn () => Db::run("UPDATE purchase_audit_log SET action = 'tampered'"), 'append-only', 'audit UPDATE');
    assertThrows(static fn () => Db::run('DELETE FROM purchase_audit_log'), 'append-only', 'audit DELETE');
});

echo "\nTenant isolation\n";

check('a query for another company returns nothing', function () use ($ctx, $auth) {
    resetDatabase();
    (new PurchaseOrderService($ctx, $auth))->create(poInput());

    $other = freshContext(999);
    $result = (new PurchaseOrderService($other, $auth))->search([], 50, 0, 'po_date', 'DESC');
    assertSame(0, $result['total'], 'company 999 sees none of company 88 rows');
});

echo "\n" . str_repeat('-', 60) . "\n";
echo "{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
