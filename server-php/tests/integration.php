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
use Aicountly\Api\Ai\AskEngine;
use Aicountly\Api\Controllers\AccessController;
use Aicountly\Api\Controllers\DashboardsController;
use Aicountly\Api\Dashboards\BillsDashboard;
use Aicountly\Api\Dashboards\BooksReader;
use Aicountly\Api\Dashboards\Decimal;
use Aicountly\Api\Dashboards\Filters;
use Aicountly\Api\Dashboards\InsightsDashboard;
use Aicountly\Api\Dashboards\OverviewDashboard;
use Aicountly\Api\Dashboards\Period;
use Aicountly\Api\Dashboards\ProcurementDashboard;
use Aicountly\Api\Dashboards\SuppliersDashboard;
use Aicountly\Api\Import\ColumnMap;
use Aicountly\Api\Import\CsvReader;
use Aicountly\Api\Import\DocumentReader;
use Aicountly\Api\Import\PdfTextReader;
use Aicountly\Api\Import\StatementReconciler;
use Aicountly\Api\Import\Values;
use Aicountly\Api\Import\XlsxReader;
use Aicountly\Api\Pdf\PdfDocument;
use Aicountly\Api\Pdf\ReportRenderer;

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

/**
 * A caller, with the role Manage would have reported for the company.
 *
 * NOT `session['acs_type']`, which is where this used to put it. The portal has
 * never sent that field, so a fixture that supplies it is testing a payload that
 * does not exist — and that is exactly how an owner bypass shipped that could
 * not fire in production. The role now arrives the way production supplies it:
 * noted against a company, the way Context::assertAllowed notes it from Manage's
 * companyinfo answer.
 */
function authFor(string $uuid = 'user-owner', ?int $acsType = 1, int $cmpId = 88): Auth
{
    // The ses key carries the role for the stub's benefit: it is the only thing
    // about this caller that reaches Manage, and tests that go through a real
    // controller resolve their role over that call rather than from what is set
    // here. Both are set so a test works either way round.
    $auth = rawAuth($uuid, 'stub-ses-key.role-' . ($acsType === null ? 'silent' : $acsType));
    $auth->noteCompanyAccess($cmpId, $acsType);

    return $auth;
}

/** An Auth with no role noted — what Auth::require() alone can actually produce. */
function rawAuth(string $uuid, string $sesKey = 'stub-ses-key.role-1'): Auth
{
    $r = new \ReflectionClass(Auth::class);
    $auth = $r->newInstanceWithoutConstructor();
    foreach ([
        'uuid'      => $uuid,
        'kind'      => 'user',
        'sourceApp' => 'purchases',
        'sesKey'    => $sesKey,
        'session'   => ['name' => $uuid],
    ] as $prop => $value) {
        $p = $r->getProperty($prop);
        $p->setAccessible(true);
        $p->setValue($auth, $value);
    }

    return $auth;
}

function resetDatabase(): void
{
    // The table list lives in tests/reset.php, which the browser suite runs too.
    // Kept in one place on purpose: the copy that drifts is the copy that leaves
    // a table behind, and a suite passing on state nobody meant to leave is
    // worse than one that fails.
    require_once __DIR__ . '/reset.php';

    resetPurchaseTables();
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

/**
 * Build a dashboard with an explicit request, the way an HTTP call would.
 *
 * The query string is what Period and Filters read, so setting it here is what
 * makes these tests exercise the same code path a browser does.
 *
 * @param array<string, string> $query
 */
function dashboardFor(string $view, Context $ctx, Auth $auth, array $query = []): array
{
    $_GET = $query + ['cmp_id' => (string) $ctx->cmpId, 'fy_id' => (string) $ctx->fyId, 'bo_id' => (string) $ctx->boId];

    $period = Period::fromRequest();
    $filters = Filters::fromRequest();

    $dashboard = match ($view) {
        'procurement'    => new ProcurementDashboard($ctx, $auth, $period, $filters),
        'suppliers'      => new SuppliersDashboard($ctx, $auth, $period, $filters),
        'bills-payables' => new BillsDashboard($ctx, $auth, $period, $filters),
        'ai-insights'    => new InsightsDashboard($ctx, $auth, $period, $filters),
        default          => new OverviewDashboard($ctx, $auth, $period, $filters),
    };

    return $dashboard->build();
}

/** @param array<string, mixed> $payload */
function metric(array $payload, string $id): array
{
    foreach ($payload['metrics'] as $candidate) {
        if ($candidate['id'] === $id) {
            return $candidate;
        }
    }
    throw new \RuntimeException('no metric "' . $id . '" on the ' . $payload['view'] . ' dashboard');
}

/** @param array<string, mixed> $payload */
function sourceStatus(array $payload, string $id): string
{
    foreach ($payload['sources'] as $source) {
        if ($source['id'] === $id) {
            return (string) $source['status'];
        }
    }

    return 'absent';
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

echo "\nDashboards — the metric contract\n";

check('unavailable is never rendered as zero', function () use ($ctx, $auth) {
    resetDatabase();
    stubFail('dashboard/purchase', 503);

    $overview = dashboardFor('overview', $ctx, $auth);
    $net = metric($overview, 'net_purchases');

    assertSame('unavailable', $net['status'], 'net purchases with Books down');
    assertSame(null, $net['raw_value'], 'an unavailable metric has no value');
    assertSame(null, $net['formatted_value'], 'an unavailable metric has no formatted value');
    assertTrue(str_contains((string) $net['unavailable_reason'], 'Smart Books'), 'the reason names the product that did not answer');
    assertSame('Comparison unavailable', $net['comparison_text'], 'no comparison without a value');
    assertSame('unavailable', sourceStatus($overview, 'books'), 'the source list says Books is unavailable');

    stubRecover();
});

check('our own figures keep working when Books is down', function () use ($ctx, $auth) {
    resetDatabase();
    $orders = new PurchaseOrderService($ctx, $auth);
    $po = $orders->create(poInput());
    $orders->submit((int) $po['po_id']);
    $orders->issue((int) $po['po_id']);

    stubFail('dashboard/purchase', 503);
    $overview = dashboardFor('overview', $ctx, $auth);
    stubRecover();

    $commitment = metric($overview, 'open_commitment');
    assertSame('ready', $commitment['status'], 'commitment is ours and survives Books being down');
    // 100 x 250 + 40 x 900 = 61000, exactly.
    assertSame('61000', $commitment['raw_value'], 'open commitment is exact');
    assertSame('ready', sourceStatus($overview, 'purchases'), 'our own source stays ready');
});

check('money crosses the wire as an exact decimal string, never a float', function () use ($ctx, $auth) {
    resetDatabase();
    $overview = dashboardFor('overview', $ctx, $auth);
    $net = metric($overview, 'net_purchases');

    assertSame('ready', $net['status'], 'Books answered');
    // The stub sends 4860000.4567 as a JSON number. If it were carried as a
    // PHP float this would come back as 4860000.4567000002 or similar.
    assertSame('4860000.4567', $net['raw_value'], 'the paise survive the round trip');
    assertTrue(is_string($net['raw_value']), 'raw values are strings');
    assertSame('₹48,60,000.46', $net['formatted_value'], 'Indian grouping, two places');
});

check('a comparison states its direction and its baseline', function () use ($ctx, $auth) {
    resetDatabase();
    $overview = dashboardFor('overview', $ctx, $auth, ['preset' => 'this_month']);

    $net = metric($overview, 'net_purchases');
    assertTrue($net['comparison']['available'], 'a previous period was compared');
    assertSame('4339285', $net['comparison']['previous_raw'], 'the baseline is carried, not just the delta');
    assertTrue(str_contains((string) $net['comparison_text'], '12.0%'), 'the change reads 12.0%, got: ' . $net['comparison_text']);

    // Overdue dues fell, and falling is good news, so the tone is positive even
    // though the number went down.
    $overdue = metric($overview, 'overdue_dues');
    assertSame('lower_is_better', $overdue['direction'], 'overdue dues are better when lower');
    assertSame('is-positive', $overdue['change_tone'], 'a fall in overdue dues reads as good');
});

check('a zero baseline gives an absolute change, not a percentage', function () {
    assertSame(null, Decimal::percentChange('0', '500', 1), 'no percentage against nothing');
    assertSame('25', Decimal::percentChange('200', '250', 1), 'a real baseline still gives one');
});

check('every metric explains itself and can be opened', function () use ($ctx, $auth) {
    resetDatabase();
    foreach (DashboardsController::VIEWS as $view) {
        $payload = dashboardFor($view, $ctx, $auth);
        assertSame($view, $payload['view'], 'the payload names its view');
        assertTrue($payload['metrics'] !== [], $view . ' has metrics');

        foreach ($payload['metrics'] as $m) {
            assertTrue(($m['basis'] ?? '') !== '', $view . '/' . $m['id'] . ' states its basis');
            assertTrue(in_array($m['direction'], ['higher_is_better', 'lower_is_better', 'neutral'], true), $view . '/' . $m['id'] . ' says which way is better');
            assertTrue(($m['comparison_text'] ?? '') !== '', $view . '/' . $m['id'] . ' has a comparison line or says it has none');
            if ($m['status'] === 'unavailable') {
                assertTrue(($m['unavailable_reason'] ?? '') !== '', $view . '/' . $m['id'] . ' says why it is unavailable');
            }
        }
    }
});

check('the dashboard scope never leaks another company', function () use ($auth) {
    resetDatabase();
    $ours = freshContext(88);
    $orders = new PurchaseOrderService($ours, $auth);
    $po = $orders->create(poInput());
    $orders->submit((int) $po['po_id']);
    $orders->issue((int) $po['po_id']);

    // Owner of the other company too, deliberately: this test is about the scope
    // clause, and a caller who simply lacked permission over there would make it
    // pass for the wrong reason — an "Unavailable" that proves nothing about
    // isolation. Give them every right in company 999 and they must still see
    // none of company 88's orders.
    $auth->noteCompanyAccess(999, 1);
    $theirs = dashboardFor('overview', freshContext(999), $auth);
    assertSame('0', metric($theirs, 'open_commitment')['raw_value'], 'company 999 sees none of company 88 commitment');
});

echo "\nDashboard 1 — Overview\n";

check('the briefing is ranked, actionable and labelled as rules-based', function () use ($ctx, $auth) {
    resetDatabase();
    $orders = new PurchaseOrderService($ctx, $auth);
    $po = $orders->create(poInput(['promised_date' => '2020-01-01']));
    $orders->submit((int) $po['po_id']);
    $orders->issue((int) $po['po_id']);

    $overview = dashboardFor('overview', $ctx, $auth);
    $briefing = $overview['panels']['briefing'];

    assertSame('rules', $briefing['method'], 'no model was claimed');
    assertTrue(str_contains($briefing['method_label'], 'no AI model'), 'the label says so in words');
    assertTrue(count($briefing['items']) <= 5, 'at most five issues');
    assertTrue($briefing['items'] !== [], 'a late order produced an issue');

    foreach ($briefing['items'] as $item) {
        assertTrue($item['explanation'] !== '', 'each issue explains itself');
        assertTrue($item['action_label'] !== '', 'each issue offers a next action');
        assertTrue($item['route'] !== '', 'each issue can be opened');
    }
});

check('supplier concentration states the denominator it used', function () use ($ctx, $auth) {
    resetDatabase();
    (new PurchaseOrderService($ctx, $auth))->create(poInput());

    $overview = dashboardFor('overview', $ctx, $auth);
    $concentration = $overview['panels']['concentration'];

    assertTrue($concentration['available'], 'the panel rendered');
    assertSame('books', $concentration['base_source'], 'posted purchases are the base when Books answers');
    assertTrue(str_contains($concentration['basis'], 'net posted purchases'), 'the base is named in the basis text');
});

check('concentration refuses to add two currencies together', function () use ($ctx, $auth) {
    resetDatabase();
    $orders = new PurchaseOrderService($ctx, $auth);
    $orders->create(poInput());
    $orders->create(poInput(['currency_code' => 'USD', 'exchange_rate' => 83]));

    $overview = dashboardFor('overview', $ctx, $auth);
    $concentration = $overview['panels']['concentration'];

    assertSame(false, $concentration['available'], 'a mixed-currency base is not shown');
    assertTrue(str_contains($concentration['reason'], 'more than one currency'), 'and it says why');
    assertSame(null, $overview['scope']['reporting_currency'], 'no single reporting currency is claimed');
});

echo "\nDashboard 2 — Procurement\n";

check('the workbench separates delayed from merely open', function () use ($ctx, $auth) {
    resetDatabase();
    $orders = new PurchaseOrderService($ctx, $auth);

    $late = $orders->create(poInput(['promised_date' => '2020-01-01']));
    $orders->submit((int) $late['po_id']);
    $orders->issue((int) $late['po_id']);

    $future = $orders->create(poInput(['promised_date' => '2099-01-01']));
    $orders->submit((int) $future['po_id']);
    $orders->issue((int) $future['po_id']);

    $delayed = dashboardFor('procurement', $ctx, $auth, ['view' => 'delayed']);
    assertSame(1, $delayed['panels']['workbench']['total'], 'one delayed order');
    assertSame('delayed', $delayed['panels']['workbench']['view'], 'the view is echoed back');
    assertSame('Chase the supplier', $delayed['panels']['workbench']['rows'][0]['next_action'], 'the row says what to do');

    $open = dashboardFor('procurement', $ctx, $auth, ['view' => 'open_orders']);
    assertSame(2, $open['panels']['workbench']['total'], 'both orders are open');

    assertSame('1', metric($delayed, 'overdue_orders')['raw_value'], 'the card agrees with the table');
});

check('quantities keep their unit and are never added across units', function () use ($ctx, $auth) {
    resetDatabase();
    $orders = new PurchaseOrderService($ctx, $auth);
    // Inside the delivery horizon, which is capped at 90 days: the timeline is
    // "what is coming soon", not the whole order book.
    $po = $orders->create(poInput(['promised_date' => gmdate('Y-m-d', strtotime('+10 days'))]));
    $orders->submit((int) $po['po_id']);
    $orders->issue((int) $po['po_id']);

    $procurement = dashboardFor('procurement', $ctx, $auth, ['horizon' => '30']);
    $groups = $procurement['panels']['delivery_timeline']['groups'];
    assertTrue($groups !== [], 'the timeline has a group');

    foreach ($groups as $group) {
        foreach ($group['lines'] as $line) {
            assertTrue(array_key_exists('unit', $line), 'every line carries its unit');
            assertTrue(str_contains((string) $line['remaining_label'], 'Nos'), 'the label carries the unit: ' . $line['remaining_label']);
        }
    }
    // There is no total quantity anywhere on the panel, by design.
    assertTrue(!array_key_exists('total_qty', $procurement['panels']['delivery_timeline']), 'no cross-unit quantity total');
});

check('reorder suggestions deduct what is already on order', function () use ($ctx, $auth) {
    resetDatabase();
    $orders = new PurchaseOrderService($ctx, $auth);
    $po = $orders->create(poInput([
        'promised_date' => '2099-01-01',
        'lines' => [['item_id' => 7001, 'unit_id' => 1, 'ordered_qty' => 400, 'agreed_rate' => 300]],
    ]));
    $orders->submit((int) $po['po_id']);
    $orders->issue((int) $po['po_id']);

    $reorder = dashboardFor('procurement', $ctx, $auth)['panels']['reorder'];
    assertTrue($reorder['available'], 'Inventory answered');

    $cement = null;
    foreach ($reorder['rows'] as $row) {
        if ($row['item_id'] === 7001) {
            $cement = $row;
        }
    }
    assertTrue($cement !== null, 'the short item is listed');
    assertSame('1000', $cement['inventory_suggested_qty'], 'Inventory suggested 1000');
    assertSame('400', $cement['on_order_qty'], '400 is already on order');
    assertSame('600', $cement['suggested_qty'], 'so 600 is what remains to order');
    assertTrue(str_contains($cement['basis'], 'already on order'), 'and the row explains the deduction');
});

check('reorder degrades honestly when Inventory is unreachable', function () use ($ctx, $auth) {
    resetDatabase();
    stubFail('replenishment', 503);
    $procurement = dashboardFor('procurement', $ctx, $auth);
    stubRecover();

    $reorder = $procurement['panels']['reorder'];
    assertSame(false, $reorder['available'], 'no suggestions without live stock');
    assertTrue(str_contains($reorder['reason'], 'Inventory'), 'the reason names Inventory');
    assertTrue(str_contains($reorder['reason'], 'nothing is estimated'), 'and refuses to guess from purchase history');
    assertSame('unavailable', sourceStatus($procurement, 'inventory'), 'the source list agrees');
});

check('the approval inbox never shows a document you raised', function () use ($ctx) {
    resetDatabase();
    $raiser = authFor('user-raiser', 2);
    Db::run(
        "INSERT INTO purchase_permission_profiles (cmp_id, profile_name, permissions)
         VALUES (88, 'Buyer', '[\"po.view\",\"po.create\",\"po.approve\"]'::jsonb)",
    );
    $profileId = (int) Db::scalar('SELECT profile_id FROM purchase_permission_profiles LIMIT 1');
    Db::run(
        'INSERT INTO purchase_permission_assignments (cmp_id, user_uuid, profile_id) VALUES (88, :u, :p)',
        ['u' => 'user-raiser', 'p' => $profileId],
    );
    Db::run('UPDATE purchase_settings SET po_approval_above_amount = 1 WHERE cmp_id = 88');
    Db::run('INSERT INTO purchase_settings (cmp_id, po_approval_above_amount) VALUES (88, 1) ON CONFLICT (cmp_id) DO UPDATE SET po_approval_above_amount = 1');

    $orders = new PurchaseOrderService($ctx, $raiser);
    $po = $orders->create(poInput());
    $orders->submit((int) $po['po_id']);

    $pending = (int) Db::scalar("SELECT COUNT(*) FROM purchase_approval_requests WHERE status = 'PENDING'");
    assertTrue($pending > 0, 'an approval was raised');

    $inbox = dashboardFor('procurement', $ctx, $raiser)['panels']['approval_inbox'];
    assertSame(0, $inbox['total'], 'the raiser does not see their own document');
    assertSame('0', metric(dashboardFor('overview', $ctx, $raiser), 'my_approvals')['raw_value'], 'nor on the overview card');
});

echo "\nDashboard 3 — Suppliers\n";

check('a rate is not stated from too small a sample', function () use ($ctx, $auth) {
    resetDatabase();
    $orders = new PurchaseOrderService($ctx, $auth);
    $po = $orders->create(poInput());
    $orders->submit((int) $po['po_id']);
    $orders->issue((int) $po['po_id']);
    (new ReceiptService($ctx, $auth))->request((int) $po['po_id'], ['received_at' => '2026-09-18']);

    // An explicit window, because the fixture's receipt date sits inside it —
    // "this year to date" would end before the receipt and prove nothing.
    $suppliers = dashboardFor('suppliers', $ctx, $auth, ['from' => '2026-09-01', 'to' => '2026-09-30']);
    $onTime = metric($suppliers, 'on_time_delivery');

    assertSame('unavailable', $onTime['status'], 'one receipt is not an on-time rate');
    assertTrue(str_contains((string) $onTime['unavailable_reason'], 'fewer than the 3'), 'and it says how many are needed');

    $row = $suppliers['panels']['matrix']['rows'][0];
    assertSame(null, $row['on_time_pc'], 'the matrix agrees');
    assertSame(1, $row['on_time_sample'], 'and shows the sample it had');
    assertTrue(str_contains($row['on_time_label'], 'too few to rate'), 'in words as well');
});

check('a sparkline month with too few deliveries is a gap, never a zero', function () use ($ctx, $auth) {
    resetDatabase();
    $orders = new PurchaseOrderService($ctx, $auth);
    $receipts = new ReceiptService($ctx, $auth);

    // August: three deliveries, all on time — enough to rate.
    // September: one delivery — not enough, and that is the whole point.
    foreach ([['2026-08-05', '2026-08-20', '2026-08-18'], ['2026-08-05', '2026-08-20', '2026-08-18'],
              ['2026-08-05', '2026-08-20', '2026-08-18'], ['2026-09-05', '2026-09-20', '2026-09-18']] as [$poDate, $promised, $received]) {
        $po = $orders->create(poInput(['po_date' => $poDate, 'promised_date' => $promised]));
        $orders->submit((int) $po['po_id']);
        $orders->issue((int) $po['po_id']);
        $receipts->request((int) $po['po_id'], ['received_at' => $received]);
    }

    $rows = dashboardFor('suppliers', $ctx, $auth, ['from' => '2026-08-01', 'to' => '2026-09-30'])['panels']['matrix']['rows'];
    $points = [];
    foreach ($rows[0]['trend_points'] as $point) {
        $points[$point['period']] = $point;
    }

    assertSame('100', $points['2026-08']['on_time_pc'] ?? null, 'three on-time deliveries rate the month');
    assertSame(3, $points['2026-08']['sample'] ?? null, 'with its sample stated');

    // The month happened and had a delivery. It is reported, with its count,
    // and WITHOUT a rate — a null the chart leaves as a gap. A 0 here would
    // draw a collapse that did not happen.
    assertTrue(array_key_exists('2026-09', $points), 'the thin month is still reported');
    assertSame(null, $points['2026-09']['on_time_pc'], 'but carries no rate');
    assertSame(1, $points['2026-09']['sample'], 'only its count');
});

check('a price path is indexed to 100 at the first month, in exact decimal', function () use ($ctx, $auth) {
    resetDatabase();
    $orders = new PurchaseOrderService($ctx, $auth);

    // The same item at 200, then 250, then 300 — a path, not two endpoints.
    foreach ([['2026-07-05', 200], ['2026-08-05', 250], ['2026-09-05', 300]] as [$date, $rate]) {
        $po = $orders->create(poInput([
            'po_date' => $date,
            'lines'   => [['item_id' => 201, 'unit_id' => 1, 'ordered_qty' => 10, 'agreed_rate' => $rate, 'estimated_tax_pc' => 18, 'warehouse_id' => 3]],
        ]));
        $orders->submit((int) $po['po_id']);
    }

    $price = dashboardFor('suppliers', $ctx, $auth, ['from' => '2026-07-01', 'to' => '2026-09-30'])['panels']['price_movement'];
    assertTrue(count($price['rows']) > 0, 'the item qualifies');

    $points = $price['rows'][0]['points'];
    assertSame(3, count($points), 'one point per month it was bought in');

    // 100, 125, 150 — exact, because the arithmetic is decimal on the server
    // rather than floating point in the browser. Items priced per tonne and per
    // coil can then share one axis instead of needing one each.
    assertSame(['100', '125', '150'], array_map(static fn ($p) => $p['index'], $points), 'indexed to 100 at the first month');
    assertSame(['2026-07', '2026-08', '2026-09'], array_map(static fn ($p) => $p['period'], $points), 'in month order');
    assertTrue(str_contains($points[2]['formatted'], '300'), 'and each point keeps its real rate');
});

check('the composite score publishes its weights and what was missing', function () use ($ctx, $auth) {
    resetDatabase();
    (new PurchaseOrderService($ctx, $auth))->create(poInput());

    $suppliers = dashboardFor('suppliers', $ctx, $auth, ['preset' => 'this_year']);
    assertSame(40, $suppliers['score_model']['weights']['on_time'], 'weights are published');
    assertSame(3, $suppliers['score_model']['min_sample'], 'so is the minimum sample');

    $row = $suppliers['panels']['matrix']['rows'][0];
    assertTrue($row['score_components'] !== [], 'the components are itemised');
    assertTrue($row['score_missing'] !== [], 'and what could not be scored is named');
    foreach ($row['score_components'] as $component) {
        assertTrue(array_key_exists('weight', $component), 'each component carries its weight');
        assertTrue(array_key_exists('counted', $component), 'and whether it counted');
    }
});

check('overdue lines that never arrived cannot vanish from the picture', function () use ($ctx, $auth) {
    resetDatabase();
    $orders = new PurchaseOrderService($ctx, $auth);
    $po = $orders->create(poInput(['promised_date' => '2020-01-01']));
    $orders->submit((int) $po['po_id']);
    $orders->issue((int) $po['po_id']);

    $trend = dashboardFor('suppliers', $ctx, $auth, ['preset' => 'this_year'])['panels']['delivery_trend'];
    assertSame(2, $trend['still_waiting']['lines'], 'both lines are still waiting');
    assertSame(1, $trend['still_waiting']['orders'], 'on one order');
    assertTrue(str_contains($trend['still_waiting']['note'], 'cannot appear in an on-time rate'), 'and the note explains why they are counted separately');
});

check('price movement compares like with like', function () use ($ctx, $auth) {
    resetDatabase();
    $orders = new PurchaseOrderService($ctx, $auth);
    // Same item, same unit, three orders, rising rate.
    foreach ([['2026-09-01', 250], ['2026-09-05', 265], ['2026-09-09', 290]] as [$date, $rate]) {
        $orders->create(poInput([
            'po_date' => $date,
            'lines'   => [['item_id' => 201, 'unit_id' => 1, 'ordered_qty' => 10, 'agreed_rate' => $rate]],
        ]));
    }

    $panel = dashboardFor('suppliers', $ctx, $auth, ['preset' => 'this_year'])['panels']['price_movement'];
    assertTrue($panel['rows'] !== [], 'a movement was found');

    $row = $panel['rows'][0];
    assertSame(201, $row['item_id'], 'the right item');
    assertSame('250', $row['first_rate'], 'first observed rate');
    assertSame('290', $row['last_rate'], 'last observed rate');
    assertSame('16', $row['change_pc'], 'a 16% rise');
    assertSame(3, $row['observations'], 'over three observations');
    assertTrue(str_contains($panel['basis'], 'before line discount, freight and tax'), 'the basis names what is excluded');
    assertTrue(str_contains($panel['basis'], 'inventory cost'), 'and separates commercial rate from inventory valuation');
});

echo "\nDashboard 4 — Bills & Payables\n";

check('payables ageing is Books own, with the missing-due-date caveat stated', function () use ($ctx, $auth) {
    resetDatabase();
    $ageing = dashboardFor('bills-payables', $ctx, $auth)['panels']['ageing'];

    assertTrue($ageing['available'], 'the panel rendered');
    assertSame('2240000.75', $ageing['total'], 'the total is Books figure, to the paisa');
    assertSame('620000.75', $ageing['buckets'][4]['amount'], 'the over-90 bucket is carried through unchanged');
    assertTrue(str_contains($ageing['caveat'], 'no due date'), 'the caveat names the limitation');
    assertTrue(str_contains($ageing['caveat'], 'does not substitute the invoice date'), 'and refuses the usual shortcut');
});

check('due-in-N-days says why it cannot be answered rather than showing zero', function () use ($ctx, $auth) {
    resetDatabase();
    $due = metric(dashboardFor('bills-payables', $ctx, $auth), 'due_windows');

    assertSame('unavailable', $due['status'], 'not answerable company-wide');
    assertSame(null, $due['raw_value'], 'and certainly not zero');
    assertTrue(str_contains((string) $due['unavailable_reason'], 'acc_id'), 'the reason names the exact upstream limitation');
});

check('payment planning reads real due dates and holds disputed bills back', function () use ($ctx, $auth) {
    resetDatabase();
    $po = receivedOrder($ctx, $auth);
    $bills = new BillService($ctx, $auth);
    $bill = $bills->enter([
        'supplier_account_id' => 601,
        'po_id' => (int) $po['po_id'],
        'supplier_invoice_no' => 'DST/2026/1001',
        'supplier_invoice_date' => '2026-09-20',
        'lines' => [['po_line_id' => (int) $po['lines'][0]['line_id'], 'qty' => 100, 'rate' => 250]],
    ]);
    Db::run("UPDATE purchase_bill_requests SET status = 'POSTED' WHERE request_id = :id", ['id' => (int) $bill['request_id']]);

    $planning = dashboardFor('bills-payables', $ctx, $auth)['panels']['payment_planning'];
    assertTrue($planning['available'], 'the planner ran');
    assertTrue($planning['rows'] !== [], 'open items came back from Books');

    // The stub returns one settled bill; it must not be planned for payment.
    foreach ($planning['rows'] as $row) {
        assertTrue($row['pending'] !== '0', 'a settled bill is not on the plan');
        assertSame('not_proposed', $row['state'], 'nothing is proposed automatically');
    }

    $undated = array_values(array_filter($planning['rows'], static fn ($r) => $r['due_date'] === null));
    assertTrue($undated !== [], 'the bill with no due date is still shown');
    assertSame('No due date recorded', $undated[0]['due_label'], 'and is labelled honestly');
    assertSame($undated[0]['pending'], $planning['windows']['undated']['amount'], 'undated money is kept out of the due windows');

    assertTrue(str_contains($planning['pay_note'], 'Aicountly Pay is not integrated'), 'Pay is not claimed');
    assertTrue(str_contains($planning['pay_note'], 'three separate states'), 'proposal, recorded and executed stay apart');
    assertTrue(str_contains($planning['scope_note'], 'NOT the whole creditors ledger'), 'the bounded scope is stated');
});

check('the matching workbench keeps service and non-PO purchases out of failure', function () use ($ctx, $auth) {
    resetDatabase();
    $matching = dashboardFor('bills-payables', $ctx, $auth)['panels']['matching'];

    assertTrue(isset($matching['counts']['service']), 'service purchases are their own category');
    assertTrue(isset($matching['counts']['non_po']), 'so are non-PO purchases');
    assertTrue(str_contains($matching['basis'], 'never blocked'), 'billing less than agreed is not an exception');
    assertTrue(str_contains($matching['basis'], 'not a failure'), 'and a service bill is not a failed match');
});

check('an open exception carries the rule that failed and both endpoints to resolve it', function () use ($ctx, $auth) {
    resetDatabase();
    $po = receivedOrder($ctx, $auth);
    (new BillService($ctx, $auth))->enter([
        'supplier_account_id' => 601,
        'po_id' => (int) $po['po_id'],
        'supplier_invoice_no' => 'DST/2026/1002',
        'supplier_invoice_date' => '2026-09-21',
        // Billed above the agreed rate: an exception by policy.
        'lines' => [['po_line_id' => (int) $po['lines'][0]['line_id'], 'qty' => 100, 'rate' => 400]],
    ]);

    $payload = dashboardFor('bills-payables', $ctx, $auth);
    assertSame('1', metric($payload, 'bills_with_exceptions')['raw_value'], 'the card counts it');

    $rows = $payload['panels']['matching']['rows'];
    assertTrue($rows !== [], 'and the table lists it');
    assertTrue($rows[0]['rule'] !== '', 'the failed rule is spelled out');
    assertTrue(str_contains($rows[0]['accept_endpoint'], 'match-exceptions'), 'accept is offered');
    assertTrue(str_contains($rows[0]['reject_endpoint'], 'match-exceptions'), 'reject is offered');
});

echo "\nDashboard 5 — AI Insights\n";

check('with no model configured the screen still works and says so', function () use ($ctx, $auth) {
    resetDatabase();
    $insights = dashboardFor('ai-insights', $ctx, $auth);

    assertSame(false, $insights['ai']['available'], 'no model is configured in this deployment');
    assertTrue(str_contains((string) $insights['ai']['reason'], 'AI insights are currently unavailable'), 'the exact wording is used');
    assertSame('rules', $insights['panels']['opportunities']['method'], 'opportunities are rules-based');
    assertSame('rules', $insights['panels']['anomalies']['method'], 'so are anomalies');
    assertTrue($insights['panels']['ask']['questions'] !== [], 'the questions still work');
    assertTrue(str_contains($insights['panels']['ask']['security'], 'never writes a query'), 'the security position is on the screen');
});

check('an opportunity carries its baseline and its assumption', function () use ($ctx, $auth) {
    resetDatabase();
    $orders = new PurchaseOrderService($ctx, $auth);
    // The same item from two suppliers at different rates.
    $orders->create(poInput(['supplier_account_id' => 601, 'lines' => [['item_id' => 201, 'unit_id' => 1, 'ordered_qty' => 100, 'agreed_rate' => 250]]]));
    $orders->create(poInput(['supplier_account_id' => 602, 'lines' => [['item_id' => 201, 'unit_id' => 1, 'ordered_qty' => 100, 'agreed_rate' => 300]]]));

    $cards = dashboardFor('ai-insights', $ctx, $auth, ['preset' => 'this_year'])['panels']['opportunities']['cards'];
    assertTrue($cards !== [], 'an opportunity was found');

    $card = $cards[0];
    assertSame('consolidation', $card['kind'], 'fragmented buying');
    // 200 units bought for 55,000; at the lowest rate it would have been 50,000.
    assertSame('55000', $card['baseline'], 'the baseline is what was actually spent');
    assertSame('5000', $card['estimate'], 'and the estimate is the difference at the best rate');
    assertTrue(str_contains($card['assumption'], 'Upper bound'), 'the assumption is stated as an upper bound');
    assertTrue(str_contains($card['assumption'], 'not the inventory valuation'), 'and separated from inventory cost');
});

check('overlapping opportunities are not added into one misleading total', function () use ($ctx, $auth) {
    resetDatabase();
    $orders = new PurchaseOrderService($ctx, $auth);
    foreach ([[601, '2026-09-01', 250], [602, '2026-09-05', 300], [601, '2026-09-09', 350]] as [$supplier, $date, $rate]) {
        $orders->create(poInput([
            'supplier_account_id' => $supplier,
            'po_date' => $date,
            'lines' => [['item_id' => 201, 'unit_id' => 1, 'ordered_qty' => 100, 'agreed_rate' => $rate]],
        ]));
    }

    $insights = dashboardFor('ai-insights', $ctx, $auth, ['preset' => 'this_year']);
    $total = metric($insights, 'opportunity_value');
    $cards = $insights['panels']['opportunities']['cards'];

    $consolidation = null;
    foreach ($cards as $card) {
        if ($card['kind'] === 'consolidation') {
            $consolidation = $card;
        }
    }
    assertTrue($consolidation !== null, 'the consolidation card is there');
    // Only the consolidation card carries an estimate; the price card overlaps
    // it and deliberately carries none, so the headline equals the one estimate.
    assertSame($consolidation['estimate'], $total['raw_value'], 'the headline counts each rupee once');
    assertTrue(str_contains($total['explanation'], 'upper bounds'), 'and says what kind of number it is');
});

check('a forecast is refused rather than extrapolated from too little history', function () use ($ctx, $auth) {
    resetDatabase();
    (new PurchaseOrderService($ctx, $auth))->create(poInput());

    $forecast = dashboardFor('ai-insights', $ctx, $auth)['panels']['forecast']['spend_forecast'];
    assertSame(false, $forecast['available'], 'one month is not a forecast');
    assertTrue(str_contains($forecast['reason'], 'at least 4 complete months'), 'and it says how much is needed');
    assertTrue(str_contains($forecast['reason'], 'Nothing is extrapolated'), 'and refuses to extrapolate');

    // The trend card carries the same refusal, in the same words, rather than
    // drawing a projected point the panel has just declined to state.
    $trend = dashboardFor('ai-insights', $ctx, $auth)['panels']['spend_trend'];
    assertSame(false, $trend['projection']['available'], 'the trend card agrees');
    assertTrue(str_contains($trend['projection']['reason'], 'at least 4 complete months'), 'and says the same thing');
});

check('the six figures at the top are the ones the screen is about', function () use ($ctx, $auth) {
    resetDatabase();
    (new PurchaseOrderService($ctx, $auth))->create(poInput([
        'lines' => [['item_id' => 201, 'unit_id' => 1, 'ordered_qty' => 10, 'agreed_rate' => 1000]],
    ]));

    $insights = dashboardFor('ai-insights', $ctx, $auth, ['preset' => 'this_year']);
    $ids = array_map(static fn (array $m) => $m['id'], $insights['metrics']);
    assertSame(
        ['purchase_value', 'purchase_orders', 'avg_po_value', 'price_anomalies', 'opportunity_value', 'purchase_risks_open'],
        $ids,
        'six cards, in the order the screen reads them',
    );

    $orders = metric($insights, 'purchase_orders');
    assertSame('1', $orders['raw_value'], 'one order was raised');
    // The mean of one order is that order, and the card says so exactly.
    assertSame(metric($insights, 'purchase_value')['raw_value'], metric($insights, 'avg_po_value')['raw_value'], 'one order is its own average');
});

check('a card carries the short form and the exact figure, never only the short one', function () use ($ctx, $auth) {
    resetDatabase();
    (new PurchaseOrderService($ctx, $auth))->create(poInput([
        'lines' => [['item_id' => 201, 'unit_id' => 1, 'ordered_qty' => 1, 'agreed_rate' => 2845000]],
    ]));

    $value = metric(dashboardFor('ai-insights', $ctx, $auth, ['preset' => 'this_year']), 'purchase_value');
    assertSame('₹28.45L', $value['formatted_value'], 'the card reads in lakhs');
    assertSame('₹28,45,000.00', $value['exact_value'], 'and the exact figure travels with it');
});

check('the sparkline fills the months that had nothing, and never invents one', function () use ($ctx, $auth) {
    resetDatabase();
    (new PurchaseOrderService($ctx, $auth))->create(poInput());

    $trend = metric(dashboardFor('ai-insights', $ctx, $auth), 'purchase_orders')['trend'];
    assertSame(6, count($trend), 'six months of shape');
    foreach ($trend as $point) {
        assertTrue($point['value'] !== null, 'a month with no orders is zero, not a gap, for a count');
    }

    // The figures the rules cannot produce a monthly series for carry none at
    // all, rather than a flat line drawn from one number.
    assertSame([], metric(dashboardFor('ai-insights', $ctx, $auth), 'purchase_risks_open')['trend'], 'no series is invented for a position');
});

check('an opportunity is triaged by its own counts, never by a confidence percentage', function () use ($ctx, $auth) {
    resetDatabase();
    $orders = new PurchaseOrderService($ctx, $auth);
    $orders->create(poInput(['supplier_account_id' => 601, 'lines' => [['item_id' => 201, 'unit_id' => 1, 'ordered_qty' => 100, 'agreed_rate' => 250]]]));
    $orders->create(poInput(['supplier_account_id' => 602, 'lines' => [['item_id' => 201, 'unit_id' => 1, 'ordered_qty' => 100, 'agreed_rate' => 300]]]));

    $card = dashboardFor('ai-insights', $ctx, $auth, ['preset' => 'this_year'])['panels']['opportunities']['cards'][0];
    assertSame('supplier', $card['area'], 'fragmented buying is a supplier question');
    assertSame('Compare', $card['status_label'], 'and the next step is to compare the rates');
    assertTrue(in_array($card['priority'], ['low', 'medium', 'high'], true), 'priority is one of three');
    assertTrue(str_contains($card['evidence_strength']['basis'], 'not a probability'), 'strength is a count of evidence, and says so');
    assertTrue(!isset($card['confidence']), 'no confidence percentage is manufactured');
});

check('a rate is compared against its own median, not against the last one', function () use ($ctx, $auth) {
    resetDatabase();
    $orders = new PurchaseOrderService($ctx, $auth);
    // Three ordinary rates, then one well above the median of the others.
    foreach ([['2026-09-01', 100], ['2026-09-02', 100], ['2026-09-03', 104], ['2026-09-10', 150]] as [$date, $rate]) {
        $orders->create(poInput([
            'po_date' => $date,
            'lines' => [['item_id' => 201, 'unit_id' => 1, 'ordered_qty' => 10, 'agreed_rate' => $rate]],
        ]));
    }

    $insights = dashboardFor('ai-insights', $ctx, $auth, ['preset' => 'this_year']);
    assertSame('1', metric($insights, 'price_anomalies')['raw_value'], 'one rate stands out');

    $rows = $insights['panels']['risks']['rows'];
    $found = null;
    foreach ($rows as $row) {
        if ($row['id'] === 'price-anomalies') {
            $found = $row;
        }
    }
    assertTrue($found !== null, 'and it reaches the risk list');
    assertTrue(str_contains($found['basis'], 'median'), 'with the comparison named');
});

check('a risk row opens the records behind it', function () use ($ctx, $auth) {
    resetDatabase();
    $orders = new PurchaseOrderService($ctx, $auth);
    $po = $orders->create(poInput(['promised_date' => '2020-01-01']));
    $orders->submit((int) $po['po_id']);
    $orders->issue((int) $po['po_id']);

    $rows = dashboardFor('ai-insights', $ctx, $auth)['panels']['risks']['rows'];
    $late = null;
    foreach ($rows as $row) {
        if ($row['id'] === 'late-receipts') {
            $late = $row;
        }
    }
    assertTrue($late !== null, 'a promised date long past is a risk');
    assertSame('critical', $late['severity'], 'and it needs attention');
    assertSame('/dashboard/procurement', $late['route'], 'the row opens the delayed orders');
    assertTrue($late['basis'] !== '', 'and states the rule behind it');
});

check('every insight row says what kind of statement it is', function () use ($ctx, $auth) {
    resetDatabase();
    $orders = new PurchaseOrderService($ctx, $auth);
    $orders->create(poInput(['supplier_account_id' => 601, 'lines' => [['item_id' => 201, 'unit_id' => 1, 'ordered_qty' => 100, 'agreed_rate' => 250]]]));
    $orders->create(poInput(['supplier_account_id' => 602, 'lines' => [['item_id' => 201, 'unit_id' => 1, 'ordered_qty' => 100, 'agreed_rate' => 300]]]));

    $panel = dashboardFor('ai-insights', $ctx, $auth, ['preset' => 'this_year'])['panels']['insights'];
    assertTrue($panel['rows'] !== [], 'there is something to say');
    foreach ($panel['rows'] as $row) {
        assertTrue(in_array($row['kind'], ['observation', 'estimate', 'projection'], true), 'one of three kinds');
        assertTrue($row['basis'] !== '', 'each states its basis');
        assertTrue($row['route'] !== '', 'and each opens something');
    }
});

check('the category split refuses rather than grouping the spend by something else', function () use ($ctx, $auth) {
    resetDatabase();
    (new PurchaseOrderService($ctx, $auth))->create(poInput([
        'lines' => [['item_id' => 201, 'unit_id' => 1, 'ordered_qty' => 10, 'agreed_rate' => 100]],
    ]));

    $panel = dashboardFor('ai-insights', $ctx, $auth, ['preset' => 'this_year'])['panels']['categories'];
    if ($panel['available']) {
        assertSame('inventory', $panel['source'], 'categories are Inventory\'s item groups');
        assertTrue(str_contains($panel['basis'], 'item groups'), 'and the basis says so');
        $shares = 0;
        foreach ($panel['categories'] as $category) {
            assertTrue($category['formatted'] !== '', 'each row carries its own value');
            $shares++;
        }
        assertTrue($shares <= 6, 'five named categories and one tail at most');
    } else {
        assertSame('source', $panel['kind'], 'or it says Inventory did not answer');
        assertTrue(str_contains($panel['reason'], 'Inventory'), 'and names the product that did not');
    }
});

check('obligations and projections are never mixed together', function () use ($ctx, $auth) {
    resetDatabase();
    $orders = new PurchaseOrderService($ctx, $auth);
    $po = $orders->create(poInput(['promised_date' => '2099-06-01']));
    $orders->submit((int) $po['po_id']);
    $orders->issue((int) $po['po_id']);

    $forecast = dashboardFor('ai-insights', $ctx, $auth)['panels']['forecast'];
    assertSame('contractual', $forecast['obligations']['kind'], 'commitments are labelled as such');
    assertSame('statistical', $forecast['spend_forecast']['kind'], 'the projection is labelled separately');
    assertTrue(str_contains($forecast['obligations']['basis'], 'not a prediction'), 'obligations are not a forecast');
    assertSame(false, $forecast['commentary']['available'], 'and commentary is absent with no model');
});

check('an anomaly is a review candidate, never an accusation', function () use ($ctx, $auth) {
    resetDatabase();
    $insights = dashboardFor('ai-insights', $ctx, $auth);
    $panel = $insights['panels']['anomalies'];

    assertTrue(str_contains($panel['disclaimer'], 'not a finding'), 'the disclaimer is explicit');
    assertTrue(str_contains($panel['disclaimer'], 'not an accusation'), 'and says so twice for a reason');
    foreach ($panel['rows'] as $row) {
        assertTrue(str_contains($row['note'], 'not a finding'), 'so does each row');
    }
});

check('near-identical invoice numbers are found without a database extension', function () use ($ctx, $auth) {
    resetDatabase();
    $po = receivedOrder($ctx, $auth);
    $bills = new BillService($ctx, $auth);
    foreach (['INV-2026-4471', 'INV-2026-4472'] as $reference) {
        $bills->enter([
            'supplier_account_id' => 601,
            'po_id' => (int) $po['po_id'],
            'supplier_invoice_no' => $reference,
            'supplier_invoice_date' => '2026-09-20',
            'lines' => [['po_line_id' => (int) $po['lines'][0]['line_id'], 'qty' => 1, 'rate' => 250]],
        ]);
    }

    $rows = dashboardFor('ai-insights', $ctx, $auth)['panels']['anomalies']['rows'];
    $found = false;
    foreach ($rows as $row) {
        if ($row['kind'] === 'duplicate') {
            $found = true;
            assertTrue(str_contains($row['detail'], 'single character'), 'the rule is stated');
        }
    }
    assertTrue($found, 'the pair one character apart was found');
});

check('every proposed action opens a screen rather than doing something', function () use ($ctx, $auth) {
    resetDatabase();
    $actions = dashboardFor('ai-insights', $ctx, $auth)['panels']['actions'];

    assertTrue(str_contains($actions['notice'], 'Nothing on this dashboard issues an order'), 'the notice is unambiguous');
    foreach ($actions['actions'] as $action) {
        assertTrue($action['route'] !== '', 'each action has a destination');
        assertTrue($action['effect'] !== '', 'and says exactly what it will do');
    }
});

echo "\nAsk Purchases\n";

check('a permission is checked before any record is fetched', function () use ($ctx) {
    resetDatabase();
    $clerk = authFor('user-clerk', 2);
    $_GET = ['cmp_id' => '88', 'fy_id' => '6', 'bo_id' => '0'];

    $answer = AskEngine::answer($ctx, $clerk, Period::fromRequest(), 'which suppliers increased prices?');
    assertTrue($answer['understood'], 'the question was understood');
    assertTrue(str_contains($answer['answer'], 'do not have permission'), 'and refused on permission');
    assertSame([], $answer['records'], 'with no records fetched');
});

check('an answer carries its scope, sources, calculation and next action', function () use ($ctx, $auth) {
    resetDatabase();
    $orders = new PurchaseOrderService($ctx, $auth);
    $po = $orders->create(poInput(['promised_date' => '2020-01-01']));
    $orders->submit((int) $po['po_id']);
    $orders->issue((int) $po['po_id']);

    $_GET = ['cmp_id' => '88', 'fy_id' => '6', 'bo_id' => '0'];
    $answer = AskEngine::answer($ctx, $auth, Period::fromRequest(), 'which orders are delayed this week?');

    assertSame('delayed_orders', $answer['intent'], 'the right question was matched');
    assertSame('rules', $answer['method'], 'no model was used');
    assertSame(88, $answer['scope']['company_id'], 'the scope is stated');
    assertTrue($answer['records'] !== [], 'supporting records are returned');
    assertTrue($answer['calculation'] !== null, 'the calculation is explained');
    assertTrue($answer['uncertainty'] !== null, 'and what might be missing is named');
    assertTrue($answer['next_action']['route'] !== '', 'with a safe next action');
});

check('a question outside the catalogue is refused with the list of what works', function () use ($ctx, $auth) {
    resetDatabase();
    $_GET = ['cmp_id' => '88', 'fy_id' => '6', 'bo_id' => '0'];
    $answer = AskEngine::answer($ctx, $auth, Period::fromRequest(), 'delete every purchase order');

    assertSame(false, $answer['understood'], 'it was not understood, and nothing was run');
    assertTrue($answer['suggestions'] !== [], 'the approved questions are offered instead');
});

echo "\nExport\n";

check('an export says Unavailable rather than exporting a zero', function () use ($ctx, $auth) {
    resetDatabase();
    stubFail('dashboard/purchase', 503);
    $payload = dashboardFor('overview', $ctx, $auth);
    stubRecover();

    $flatten = new \ReflectionMethod(DashboardsController::class, 'flatten');
    $flatten->setAccessible(true);
    $rows = $flatten->invoke(null, $payload);

    $net = null;
    foreach ($rows as $row) {
        if (($row[1] ?? '') === 'Net posted purchases') {
            $net = $row;
        }
    }
    assertTrue($net !== null, 'the metric is in the export');
    assertSame('Unavailable', $net[2], 'exported as the word, so nobody sums it');
    assertSame('', $net[3], 'with no raw value');
    assertTrue($net[6] !== '', 'and the basis travels with it');
});

check('an export cannot smuggle a spreadsheet formula', function () {
    $csv = new \ReflectionMethod(DashboardsController::class, 'csv');
    $csv->setAccessible(true);
    $out = $csv->invoke(null, [['Supplier'], ['=cmd|/c calc']]);

    assertTrue(str_contains($out, "'=cmd"), 'a leading = is neutralised');
});

check('the export agrees with the dashboard it came from', function () use ($ctx, $auth) {
    resetDatabase();
    $orders = new PurchaseOrderService($ctx, $auth);
    $po = $orders->create(poInput());
    $orders->submit((int) $po['po_id']);
    $orders->issue((int) $po['po_id']);

    $payload = dashboardFor('overview', $ctx, $auth);
    $flatten = new \ReflectionMethod(DashboardsController::class, 'flatten');
    $flatten->setAccessible(true);
    $rows = $flatten->invoke(null, $payload);

    $onScreen = metric($payload, 'open_commitment');
    $inExport = null;
    foreach ($rows as $row) {
        if (($row[1] ?? '') === 'Open order commitment') {
            $inExport = $row;
        }
    }
    assertSame($onScreen['formatted_value'], $inExport[2], 'the same formatted figure');
    assertSame($onScreen['raw_value'], $inExport[3], 'and the same exact value');
});

echo "\nBooks contract\n";

check('open items are never asked for without an account id', function () use ($ctx, $auth) {
    resetDatabase();
    $reader = new BooksReader($ctx, $auth->sesKey());

    // Books answers 400 to bill-by-bill with no acc_id, and the stub enforces
    // that. Asking for one supplier must therefore succeed.
    $result = $reader->openItems(601, '2026-09-30');
    assertTrue($result['ok'], 'a request with acc_id is accepted: ' . (string) $result['error']);
    assertTrue($result['rows'] !== [], 'and returns open items');
    assertSame('120000.5', $result['rows'][0]['pending_amount'], 'to the paisa');
});

check('a settled bill is excluded and an undated one is kept visible', function () use ($ctx, $auth) {
    resetDatabase();
    $rows = (new BooksReader($ctx, $auth->sesKey()))->openItems(601, '2026-09-30')['rows'];

    foreach ($rows as $row) {
        assertTrue($row['pending_amount'] !== '0', 'nothing settled is carried');
    }
    $undated = array_values(array_filter($rows, static fn ($r) => !$r['has_due_date']));
    assertSame(1, count($undated), 'the bill with no due date is still there');
    assertSame(null, $undated[0]['days_overdue'], 'and is not called overdue');
});

echo "\nAccess administration\n";

/**
 * Call a controller action the way the router would, and hand back the payload.
 *
 * Controllers answer by throwing ResponseSent under CLI, which is what lets a
 * test assert on what a real endpoint produced rather than on a service method
 * the endpoint happens to call.
 *
 * @param array<string, mixed> $body
 * @return array{status:int, data:mixed, message:?string}
 */
function callAccess(string $action, Context $ctx, Auth $auth, array $body = [], array $args = []): array
{
    $_GET = ['cmp_id' => (string) $ctx->cmpId, 'fy_id' => (string) $ctx->fyId, 'bo_id' => (string) $ctx->boId] + $body;

    // Http::body() memoises php://input, which is empty under CLI, so the body
    // travels in $_GET — Http::param() reads either.
    $reflection = new \ReflectionClass(Http::class);
    $cached = $reflection->getProperty('body');
    $cached->setAccessible(true);
    $cached->setValue(null, $body);

    Auth::adopt($auth);
    Permissions::forget();

    try {
        AccessController::$action(...$args);
    } catch (ResponseSent $sent) {
        $payload = $sent->payload;

        return [
            'status'  => $sent->status,
            'data'    => $payload['data'] ?? null,
            'message' => $payload['message'] ?? null,
        ];
    } finally {
        Auth::adopt(null);
        $cached->setValue(null, null);
    }

    throw new \RuntimeException("AccessController::{$action} returned without responding");
}

/** Give a user a profile directly, for tests that need a non-owner administrator. */
function grantProfile(Context $ctx, string $uuid, string $name, array $permissions): int
{
    $profileId = (int) Db::insert('purchase_permission_profiles', [
        'cmp_id'       => $ctx->cmpId,
        'profile_name' => $name,
        'permissions'  => json_encode($permissions),
        'is_active'    => true,
    ], 'profile_id');

    Db::insert('purchase_permission_assignments', [
        'cmp_id'     => $ctx->cmpId,
        'user_uuid'  => $uuid,
        'profile_id' => $profileId,
    ], 'assignment_id');

    Permissions::forget();

    return $profileId;
}

check('a company with no profiles can be bootstrapped, once', function () use ($ctx, $auth) {
    resetDatabase();

    $first = callAccess('bootstrap', $ctx, $auth);
    assertSame(4, count($first['data']['created']), 'four starter profiles');
    assertSame([], $first['data']['skipped'], 'nothing skipped on a clean company');

    // The starters separate the jobs this product separates: a buyer cannot
    // approve their own order.
    $names = array_column($first['data']['created'], 'profile_name');
    assertTrue(in_array('Buyer', $names, true), 'a buyer profile');
    assertTrue(in_array('Purchase approver', $names, true), 'an approver profile');

    $buyer = null;
    foreach ($first['data']['created'] as $profile) {
        if ($profile['profile_name'] === 'Buyer') {
            $buyer = $profile;
        }
    }
    assertTrue(in_array('po.create', $buyer['permissions'], true), 'the buyer can raise an order');
    assertTrue(!in_array('po.approve', $buyer['permissions'], true), 'and deliberately cannot approve one');

    $second = callAccess('bootstrap', $ctx, $auth);
    assertSame([], $second['data']['created'], 'a second bootstrap creates nothing');
    assertSame(4, count($second['data']['skipped']), 'and says what it skipped');
});

check('a profile must name permissions this product actually has', function () use ($ctx, $auth) {
    resetDatabase();

    $result = callAccess('saveProfile', $ctx, $auth, [
        'profile_name' => 'Invented',
        'permissions'  => ['po.view', 'po.destroy_everything'],
    ]);

    assertSame(422, $result['status'], 'refused');
    assertTrue(str_contains((string) $result['message'], 'po.destroy_everything'), 'and names the one it did not recognise');
});

check('a profile with no permissions is refused', function () use ($ctx, $auth) {
    resetDatabase();
    $result = callAccess('saveProfile', $ctx, $auth, ['profile_name' => 'Empty', 'permissions' => []]);

    assertSame(422, $result['status'], 'refused');
    assertTrue(str_contains((string) $result['message'], 'grants nothing'), 'and says why');
});

check('an administrator cannot grant a permission they do not hold', function () use ($ctx) {
    resetDatabase();

    // A non-owner administrator: they can manage access, and view orders.
    $admin = authFor('user-admin', 2);
    grantProfile($ctx, 'user-admin', 'Access admin', ['access.manage', 'po.view']);

    $escalation = callAccess('saveProfile', $ctx, $admin, [
        'profile_name' => 'Quietly powerful',
        'permissions'  => ['po.view', 'bill.post'],
    ]);

    assertSame(403, $escalation['status'], 'refused: bill.post is beyond them');
    assertTrue(str_contains((string) $escalation['message'], 'bill.post'), 'and names it');

    // What they DO hold, they may hand out.
    $allowed = callAccess('saveProfile', $ctx, $admin, [
        'profile_name' => 'Order viewer',
        'permissions'  => ['po.view'],
    ]);
    assertSame(200, $allowed['status'], 'granting what they hold is allowed');
});

check('the owner can grant anything', function () use ($ctx, $auth) {
    resetDatabase();

    $result = callAccess('saveProfile', $ctx, $auth, [
        'profile_name' => 'Everything',
        'permissions'  => ['bill.post', 'match.resolve', 'access.manage'],
    ]);

    assertSame(200, $result['status'], 'the owner is not bound by the escalation rule');
    assertSame(3, $result['data']['permission_count'], 'all three granted');
});

check('editing a profile cannot smuggle a permission past the escalation rule', function () use ($ctx, $auth) {
    resetDatabase();

    // The owner makes a powerful profile.
    $powerful = callAccess('saveProfile', $ctx, $auth, [
        'profile_name' => 'Payables lead',
        'permissions'  => ['bill.post', 'match.resolve', 'po.view'],
    ]);
    $profileId = $powerful['data']['profile_id'];

    // A lesser administrator tries to strip what they cannot grant, which would
    // let them rewrite a profile they do not fully hold.
    $admin = authFor('user-admin', 2);
    grantProfile($ctx, 'user-admin', 'Access admin', ['access.manage', 'po.view']);

    $rewrite = callAccess('saveProfile', $ctx, $admin, [
        'profile_id'   => $profileId,
        'profile_name' => 'Payables lead',
        'permissions'  => ['po.view'],
    ]);

    assertSame(403, $rewrite['status'], 'refused');
    assertTrue(str_contains((string) $rewrite['message'], 'cannot remove'), 'and explains that the removal is the problem');
});

check('assigning a profile is bound by the same rule as writing one', function () use ($ctx, $auth) {
    resetDatabase();

    $powerful = callAccess('saveProfile', $ctx, $auth, [
        'profile_name' => 'Poster',
        'permissions'  => ['bill.post'],
    ]);

    $admin = authFor('user-admin', 2);
    grantProfile($ctx, 'user-admin', 'Access admin', ['access.manage', 'po.view']);

    $result = callAccess('assign', $ctx, $admin, [
        'user_uuid'  => 'user-newcomer',
        'profile_id' => $powerful['data']['profile_id'],
    ]);

    assertSame(403, $result['status'], 'handing out a profile is handing out its permissions');
});

check('an assignment grants exactly what the profile says', function () use ($ctx, $auth) {
    resetDatabase();

    $profile = callAccess('saveProfile', $ctx, $auth, [
        'profile_name' => 'Buyer',
        'permissions'  => ['po.view', 'po.create'],
    ]);

    $clerk = authFor('user-clerk', 2);
    assertTrue(!Permissions::allows($ctx, $clerk, 'po.create'), 'nothing before the assignment');

    callAccess('assign', $ctx, $auth, [
        'user_uuid'    => 'user-clerk',
        'profile_id'   => $profile['data']['profile_id'],
        'member_label' => 'Priya, production buyer',
    ]);

    Permissions::forget();
    assertTrue(Permissions::allows($ctx, $clerk, 'po.create'), 'and the permission afterwards');
    assertTrue(!Permissions::allows($ctx, $clerk, 'po.approve'), 'but nothing the profile did not name');
});

check('the members list groups by person and unions their profiles', function () use ($ctx, $auth) {
    resetDatabase();

    $a = callAccess('saveProfile', $ctx, $auth, ['profile_name' => 'Viewer', 'permissions' => ['po.view']]);
    $b = callAccess('saveProfile', $ctx, $auth, ['profile_name' => 'Biller', 'permissions' => ['bill.enter']]);

    callAccess('assign', $ctx, $auth, ['user_uuid' => 'user-two-hats', 'profile_id' => $a['data']['profile_id'], 'member_label' => 'Arun']);
    callAccess('assign', $ctx, $auth, ['user_uuid' => 'user-two-hats', 'profile_id' => $b['data']['profile_id']]);

    $members = callAccess('members', $ctx, $auth)['data'];
    assertSame(1, count($members), 'one person, not two rows');
    assertSame('Arun', $members[0]['label'], 'the label the administrator typed');
    assertSame(2, count($members[0]['assignments']), 'both profiles listed');
    assertSame(2, $members[0]['permission_count'], 'and the union of what they grant');
});

check('a label is a note the administrator typed, never a name fetched from the portal', function () use ($ctx, $auth) {
    resetDatabase();

    $profile = callAccess('saveProfile', $ctx, $auth, ['profile_name' => 'Viewer', 'permissions' => ['po.view']]);
    callAccess('assign', $ctx, $auth, [
        'user_uuid'    => 'user-labelled',
        'profile_id'   => $profile['data']['profile_id'],
        'member_label' => 'Sunil (night shift)',
    ]);

    $stored = Db::first('SELECT member_label FROM purchase_permission_assignments WHERE user_uuid = :u', ['u' => 'user-labelled']);
    assertSame('Sunil (night shift)', $stored['member_label'], 'stored as typed');

    // No outbound call was made to fetch it: the stub log has no portal lookup
    // for this user.
    foreach (stubRequests() as $request) {
        assertTrue(
            !str_contains((string) $request['path'], 'user-labelled'),
            'no call went looking for this user: ' . $request['path'],
        );
    }
});

check('you cannot remove your own last grant of access management', function () use ($ctx) {
    resetDatabase();

    $admin = authFor('user-admin', 2);
    grantProfile($ctx, 'user-admin', 'Access admin', ['access.manage']);
    $assignment = Db::first("SELECT assignment_id FROM purchase_permission_assignments WHERE user_uuid = 'user-admin'");

    $result = callAccess('unassign', $ctx, $admin, [], [(string) $assignment['assignment_id']]);

    assertSame(409, $result['status'], 'refused');
    assertTrue(str_contains((string) $result['message'], 'your own last grant'), 'and says exactly what it is protecting');
    assertTrue(Permissions::allows($ctx, $admin, 'access.manage'), 'and the grant survives');
});

check('you can remove your own grant when somebody else still has one', function () use ($ctx) {
    resetDatabase();

    $admin = authFor('user-admin', 2);
    grantProfile($ctx, 'user-admin', 'Access admin', ['access.manage']);
    // A second grant to the same person, through another profile.
    grantProfile($ctx, 'user-admin', 'Deputy admin', ['access.manage', 'po.view']);

    $assignment = Db::first(
        "SELECT a.assignment_id FROM purchase_permission_assignments a
         JOIN purchase_permission_profiles p ON p.profile_id = a.profile_id
         WHERE a.user_uuid = 'user-admin' AND p.profile_name = 'Access admin'",
    );

    $result = callAccess('unassign', $ctx, $admin, [], [(string) $assignment['assignment_id']]);
    assertSame(200, $result['status'], 'allowed, because the other grant remains');
});

check('the owner is never locked out by this rule', function () use ($ctx, $auth) {
    resetDatabase();

    grantProfile($ctx, $auth->uuid, 'Access admin', ['access.manage']);
    $assignment = Db::first('SELECT assignment_id FROM purchase_permission_assignments WHERE user_uuid = :u', ['u' => $auth->uuid]);

    $result = callAccess('unassign', $ctx, $auth, [], [(string) $assignment['assignment_id']]);
    assertSame(200, $result['status'], 'the owner may remove it: their access comes from the portal');
    assertTrue(Permissions::allows($ctx, $auth, 'access.manage'), 'and they still have it');
});

check('a profile in use cannot be deleted out from under people', function () use ($ctx, $auth) {
    resetDatabase();

    $profile = callAccess('saveProfile', $ctx, $auth, ['profile_name' => 'Viewer', 'permissions' => ['po.view']]);
    callAccess('assign', $ctx, $auth, ['user_uuid' => 'user-someone', 'profile_id' => $profile['data']['profile_id']]);

    $result = callAccess('deleteProfile', $ctx, $auth, [], [(string) $profile['data']['profile_id']]);
    assertSame(409, $result['status'], 'refused while somebody holds it');
    assertTrue(str_contains((string) $result['message'], '1 person'), 'and says how many');
});

check('every access change is written to the audit log', function () use ($ctx, $auth) {
    resetDatabase();

    $profile = callAccess('saveProfile', $ctx, $auth, ['profile_name' => 'Viewer', 'permissions' => ['po.view']]);
    callAccess('assign', $ctx, $auth, ['user_uuid' => 'user-audited', 'profile_id' => $profile['data']['profile_id']]);

    $actions = array_column(
        Db::all("SELECT action FROM purchase_audit_log WHERE entity_type LIKE 'permission%' ORDER BY audit_id"),
        'action',
    );

    assertTrue(in_array('access.profile_created', $actions, true), 'the profile creation');
    assertTrue(in_array('access.profile_assigned', $actions, true), 'and the assignment');
});

check('access administration needs the access.manage permission', function () use ($ctx) {
    resetDatabase();

    $nobody = authFor('user-nobody', 2);
    foreach (['profiles', 'members', 'people', 'catalogue'] as $action) {
        assertSame(403, callAccess($action, $ctx, $nobody)['status'], $action . ' is refused');
    }
    assertSame(403, callAccess('saveProfile', $ctx, $nobody, ['profile_name' => 'X', 'permissions' => ['po.view']])['status'], 'and so is writing');
});

check('the catalogue tells an administrator what they may hand out', function () use ($ctx) {
    resetDatabase();

    $admin = authFor('user-admin', 2);
    grantProfile($ctx, 'user-admin', 'Access admin', ['access.manage', 'po.view']);

    $result = callAccess('catalogue', $ctx, $admin)['data'];
    assertSame(false, $result['is_owner'], 'not the owner');
    sort($result['grantable']);
    assertSame(['access.manage', 'po.view'], $result['grantable'], 'grantable is exactly what they hold');
    assertTrue(count($result['catalog']) > 0, 'the full catalogue is still shown, so the rest is visibly out of reach');
});

check('candidate people come from our own audit log, not a user directory', function () use ($ctx, $auth) {
    resetDatabase();

    // Somebody acts in this company, which is how we know they exist.
    (new PurchaseOrderService($ctx, authFor('user-seen', 1)))->create(poInput());

    $people = callAccess('people', $ctx, $auth)['data'];
    $uuids = array_column($people, 'user_uuid');
    assertTrue(in_array('user-seen', $uuids, true), 'they are offered as a candidate');

    // Once assigned they are no longer a candidate: the list answers "who could
    // I add", not "who exists".
    $profile = callAccess('saveProfile', $ctx, $auth, ['profile_name' => 'Viewer', 'permissions' => ['po.view']]);
    callAccess('assign', $ctx, $auth, ['user_uuid' => 'user-seen', 'profile_id' => $profile['data']['profile_id']]);

    $after = array_column(callAccess('people', $ctx, $auth)['data'], 'user_uuid');
    assertTrue(!in_array('user-seen', $after, true), 'and drop off once assigned');
});

// ---------------------------------------------------------------------------
// Who the company owner is, and where that answer comes from.
//
// This product spent its whole life reading `acs_type` off the portal session.
// my.aicountly.com has never sent that field — validatesession answers status,
// uuid_aictly, aic_auth_id and aic_ses_id and stops — so the owner bypass could
// not fire for any human, every company owner resolved to zero permissions, and
// the app came up with an empty sidebar and every Books figure reading "needs
// reports.view". The tests passed throughout, because the fixture supplied the
// field the portal does not.
//
// These run the real ManageClient against the stub's real Manage payload.
// ---------------------------------------------------------------------------

/** Drive Context::fromRequest + assertAllowed exactly as an HTTP request would. */
function resolveContext(int $cmpId, Auth $auth): Context
{
    $_GET = ['cmp_id' => (string) $cmpId, 'fy_id' => '6', 'bo_id' => '0'];
    Context::forgetVerified();
    Permissions::forget();

    $ctx = Context::fromRequest();
    $ctx->assertAllowed($auth);

    return $ctx;
}

/** A caller whose role only Manage can settle, asking as the given role. */
function unresolvedAuth(string $uuid = 'user-owner', string $role = '1'): Auth
{
    return rawAuth($uuid, 'stub-ses-key.role-' . $role);
}

check('the portal session alone never makes anybody an owner', function () {
    $auth = unresolvedAuth();

    // The regression in one line: before Manage is asked, nothing is known. The
    // old code read a session field here and got null forever, and null silently
    // meant "not the owner".
    assertSame(null, $auth->accessTypeFor(88), 'no role until Manage is asked');
    assertSame(false, $auth->ownsCompany(88), 'and no ownership claimed from silence');
    assertSame(false, $auth->companyAccessResolved(88), 'reported as unresolved, not as a denial');
});

check('Manage names the owner, and the whole catalogue follows', function () {
    $auth = unresolvedAuth();
    $ctx = resolveContext(88, $auth);

    assertSame(1, $auth->accessTypeFor(88), 'the role came back from companyinfo');
    assertSame(true, $auth->ownsCompany(88), 'and it is ownership');

    $granted = Permissions::granted($ctx, $auth);
    sort($granted);
    $all = Permissions::all();
    sort($all);
    assertSame($all, $granted, 'the owner holds every permission');
    assertTrue(Permissions::allows($ctx, $auth, 'reports.view'), 'including the one the dashboards need');
    assertTrue(Permissions::allows($ctx, $auth, 'access.manage'), 'and the one that lets them grant the rest');
});

check('a delegate gets nothing until somebody grants it here', function () {
    $auth = unresolvedAuth('user-delegate', '0');
    $ctx = resolveContext(88, $auth);

    assertSame(0, $auth->accessTypeFor(88), 'Manage says shared, not owner');
    assertSame(false, $auth->ownsCompany(88), 'so no bypass');
    assertSame([], Permissions::granted($ctx, $auth), 'and no permissions, because Purchases owns its own');
    assertSame(true, $auth->companyAccessResolved(88), 'this is a real answer, not a missing one');
});

check('a Manage that names no role is unresolved, never a zero', function () {
    $auth = unresolvedAuth('user-unknown', 'silent');
    $ctx = resolveContext(88, $auth);

    // The company checked out — the tenant gate passed — but nothing said what
    // this person is. That has to stay distinguishable: it is a fault in this
    // code path, and reporting it as "not the owner" is what made the last one
    // invisible for a whole release.
    assertSame(null, $auth->accessTypeFor(88), 'unknown stays null');
    assertSame(false, $auth->companyAccessResolved(88), 'and is reported as unresolved');
    assertSame(false, $auth->ownsCompany(88), 'refusing on unknown, which is the safe reading');
    assertSame([], Permissions::granted($ctx, $auth), 'so no permissions are invented');
});

check('the second endpoint in a request still knows the role', function () {
    $auth = unresolvedAuth();
    $ctx = resolveContext(88, $auth);
    assertSame(true, $auth->ownsCompany(88), 'first call resolves it');

    // assertAllowed memoises the Manage round trip. A memo that returns early
    // without re-noting the role would leave a caller looking like a stranger
    // from the second endpoint of the request onwards.
    $fresh = unresolvedAuth();
    $ctx->assertAllowed($fresh);
    assertSame(true, $fresh->ownsCompany(88), 'the memo replays the answer, it does not swallow it');
});

check('CompanyAccess reads every shape Manage sends, and refuses shapes it does not', function () {
    // Manage's companyinfo sends all three at once; its companies list sends
    // ownership and is_creator without access_type. Both have to work.
    assertSame(1, CompanyAccess::fromRow(['access_type' => 1]), 'access_type');
    assertSame(0, CompanyAccess::fromRow(['access_type' => 0]), 'access_type delegated');
    assertSame(1, CompanyAccess::fromRow(['ownership' => 'owner']), 'ownership label');
    assertSame(0, CompanyAccess::fromRow(['ownership' => 'shared']), 'shared label');
    assertSame(1, CompanyAccess::fromRow(['is_creator' => true]), 'is_creator');
    assertSame(1, CompanyAccess::fromPayload(['data' => ['ownership' => 'owner']]), 'through the data envelope');

    // is_creator false is not a denial: "you did not create this" and "you do not
    // own this" are different claims, and only one of them is being made.
    assertSame(null, CompanyAccess::fromRow(['is_creator' => false]), 'is_creator false says nothing');
    assertSame(null, CompanyAccess::fromRow(['cmp_id' => 7, 'cmp_name' => 'X']), 'an unrecognised shape is unknown');
    assertSame(null, CompanyAccess::fromRow([]), 'and so is an empty one');
});

check('the session endpoint reports ownership the client can act on', function () {
    resetDatabase();
    $auth = unresolvedAuth();
    $ctx = resolveContext(88, $auth);

    $_GET = ['cmp_id' => '88', 'fy_id' => '6', 'bo_id' => '0'];
    Auth::adopt($auth);
    try {
        \Aicountly\Api\Controllers\SettingsController::session();
        throw new \RuntimeException('session() returned without responding');
    } catch (ResponseSent $sent) {
        $data = $sent->payload['data'] ?? [];
        assertSame(true, $data['is_owner'], 'the client is told they are the owner');
        assertTrue(in_array('reports.view', $data['permissions'] ?? [], true), 'and handed the permissions that prove it');
    } finally {
        Auth::adopt(null);
    }
});

echo "\nImport, reconciliation and print\n";

check('an amount is read the way its own notation means it', function () {
    // The two that matter most and are most often wrong. Indian lakh grouping
    // is not a decimal point, and European notation is the mirror image of
    // Western — read either one naively and the figure is out by 100x.
    assertSame('125000', Values::amount('1,25,000.00'), 'Indian grouping');
    assertSame('1250', Values::amount('1.250,00'), 'European separators');
    assertSame('98400.5', Values::amount('₹ 98,400.50'), 'a currency symbol and Western grouping');

    // Accounting writes a negative three different ways and means one thing.
    assertSame('-2500', Values::amount('(2,500.00)'), 'parentheses');
    assertSame('-2500', Values::amount('2500.00-'), 'trailing minus');
    assertSame('-2500', Values::amount('-2,500'), 'leading minus');

    // NOT ZERO. A parser that answers 0 for text it cannot read hands back a
    // figure that looks like an answer and reconciles against nothing.
    assertSame(null, Values::amount('subtotal'), 'unreadable text is null');
    assertSame(null, Values::amount(''), 'and so is blank');
});

check('a date is read day-first, and refuses what it cannot tell', function () {
    assertSame('2026-04-05', Values::date('05/04/2026'), 'day-first, as every statement here is written');
    assertSame('2026-04-13', Values::date('13/04/2026'), 'a first part above 12 can only be a day');
    assertSame('2026-04-05', Values::date('5 Apr 2026'), 'a month name is unambiguous');
    assertSame('2026-04-05', Values::date('2026-04-05'), 'ISO passes through');
    assertSame(null, Values::date('last Tuesday'), 'and prose is refused');

    // Reduced for matching only; the original is what any screen shows.
    assertSame(Values::reference('INV-4460'), Values::reference('inv/004460'), 'one reference, two spellings');
});

check('a delimited file is read by counting, not by assuming commas', function () {
    $table = CsvReader::read("Date;Invoice;Amount\n2026-04-05;INV-4460;1.250,00\n2026-05-05;INV-4461;98.400,50\n");

    assertSame(['Date', 'Invoice', 'Amount'], $table->headers, 'semicolons were found');
    assertSame(2, count($table->rows), 'both rows survived');
    assertSame('1.250,00', $table->rows[0][2], 'and the cell is untouched — interpretation is a separate step');
    assertTrue(str_contains(implode(' ', $table->notes), 'semicolon'), 'and the reader says what it decided');
});

check('a workbook survives shared strings, date serials and skipped columns', function () {
    $path = sys_get_temp_dir() . '/purchases-test-book.xlsx';
    @unlink($path);

    $zip = new \ZipArchive();
    assertTrue($zip->open($path, \ZipArchive::CREATE) === true, 'the fixture workbook was created');
    $zip->addFromString('xl/workbook.xml',
        '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<sheets><sheet name="Statement" sheetId="1"/></sheets></workbook>');
    $zip->addFromString('xl/sharedStrings.xml',
        '<?xml version="1.0"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<si><t>Date</t></si><si><t>Invoice</t></si><si><t>Narration</t></si><si><t>Amount</t></si><si><t>Cement</t></si></sst>');
    $zip->addFromString('xl/styles.xml',
        '<?xml version="1.0"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<numFmts count="1"><numFmt numFmtId="164" formatCode="dd-mm-yyyy"/></numFmts>'
        . '<cellXfs count="2"><xf numFmtId="0"/><xf numFmtId="164"/></cellXfs></styleSheet>');
    // Row 3 has NO column C. A reader that appends values in order shifts the
    // amount one column left and imports the narration as money.
    $zip->addFromString('xl/worksheets/sheet1.xml',
        '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
        . '<row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c><c r="C1" t="s"><v>2</v></c><c r="D1" t="s"><v>3</v></c></row>'
        . '<row r="2"><c r="A2" s="1"><v>46117</v></c><c r="B2" t="inlineStr"><is><t>INV-4460</t></is></c><c r="C2" t="s"><v>4</v></c><c r="D2"><v>125000.00</v></c></row>'
        . '<row r="3"><c r="A3" s="1"><v>46147</v></c><c r="B3" t="inlineStr"><is><t>INV-4461</t></is></c><c r="D3"><v>98400.5</v></c></row>'
        . '</sheetData></worksheet>');
    $zip->close();

    $table = XlsxReader::read($path);
    @unlink($path);

    assertSame(['Date', 'Invoice', 'Narration', 'Amount'], $table->headers, 'shared strings were resolved');
    // 46117 is 5 April 2026 once Excel's 1900 leap-year bug is accounted for.
    assertSame('2026-04-05', $table->rows[0][0], 'a date serial became a date');
    assertSame('Cement', $table->rows[0][2], 'an inline string was read');
    assertSame('', $table->rows[1][2], 'the skipped column is BLANK');
    assertSame('98400.5', $table->rows[1][3], 'so the amount stayed in its own column');
});

check('a PDF this product writes is a PDF this product can read back', function () {
    $pdf = new PdfDocument();
    $pdf->text('Supplier statement', 40, 14, PdfDocument::FONT_BOLD);
    $pdf->advance(24);
    $pdf->text('Date', 40, 9, PdfDocument::FONT_BOLD);
    $pdf->text('Invoice', 150, 9, PdfDocument::FONT_BOLD);
    $pdf->textRight('Amount', 540, 9, PdfDocument::FONT_BOLD);
    $pdf->advance(18);
    foreach ([['2026-04-05', 'INV-4460', '1,25,000.00'], ['2026-05-05', 'INV-4461', '98,400.50']] as $row) {
        $pdf->text($row[0], 40, 9);
        $pdf->text($row[1], 150, 9);
        $pdf->textRight($row[2], 540, 9);
        $pdf->advance(16);
    }

    $bytes = $pdf->render('Statement');
    assertTrue(str_starts_with($bytes, '%PDF-'), 'it is a PDF');
    assertTrue(str_contains($bytes, 'startxref'), 'with a cross-reference table');

    $table = PdfTextReader::read($bytes);
    $flat = array_map(static fn (array $r) => implode('|', $r), $table->rows);

    assertTrue(in_array('2026-04-05|INV-4460|1,25,000.00', $flat, true), 'the row came back whole: ' . implode(' / ', $flat));
    assertTrue(in_array('Date|Invoice|Amount', $flat, true), 'and so did the heading');
});

check('a scan is reported as a scan, not as an empty document', function () {
    // A PDF with a page and no text operators — what a scanner produces.
    $bare = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";
    $table = PdfTextReader::read($bare);

    assertTrue($table->isEmpty(), 'nothing was read');
    // The distinction that matters: "there is no text in this file" is a
    // different problem from "this file is empty", and only one of them is
    // fixed by exporting a CSV instead.
    assertTrue(
        str_contains(implode(' ', $table->notes), 'no text layer') || str_contains(implode(' ', $table->notes), 'decompressed'),
        'and the reason says the file has no text to read: ' . implode(' ', $table->notes),
    );
});

check('the header row is found below a letterhead, not assumed to be first', function () {
    $table = CsvReader::read(
        "Shree Cement Ltd.,,,\n"
        . "Statement of account,,,\n"
        . "01 Apr 2026 to 30 Sep 2026,,,\n"
        . "Bill Date,Invoice No,Particulars,Amount\n"
        . "05/04/2026,INV-4460,Cement,1,25,000.00\n"
    );

    $map = ColumnMap::detect($table);
    assertSame(3, $map->headerRow, 'the fourth row is the heading');
    assertSame(0, $map->columns['date'], 'Bill Date is the date');
    assertSame(1, $map->columns['reference'], 'Invoice No is the reference');
    assertSame(2, $map->columns['description'], 'Particulars is the narration');
    assertTrue(str_contains(implode(' ', $map->notes), 'letterhead'), 'and it says it skipped a letterhead');
});

check('a reconciliation sorts every line into one of four answers', function () use ($ctx, $auth) {
    $statement = CsvReader::read(
        "Date,Invoice,Amount\n"
        . "2026-08-01,INV-0001,200000.00\n"    // agrees with Books
        . "2026-08-10,INV/0002,45000.25\n"     // agrees, written differently
        . "2026-08-15,INV-0003,11000.00\n"     // Books says 10,000
        . "2026-08-22,INV-9999,7500.00\n"      // Books has never seen this
    );
    $map = ColumnMap::detect($statement);

    $ledger = (new \Aicountly\Api\Dashboards\BooksReader($ctx, $auth->sesKey()))->supplierLedger(601, '2026-08-01', '2026-08-31');
    assertTrue($ledger['ok'], 'the ledger was read');
    assertSame(4, count($ledger['rows']), 'including the settled bill, which openItems() would have dropped');

    $report = StatementReconciler::reconcile($map->dataRows($statement), $map, $ledger['rows'], 'INR');
    $buckets = [];
    foreach ($report['buckets'] as $bucket) {
        $buckets[$bucket['id']] = $bucket;
    }

    assertSame(2, $buckets['agreed']['count'], 'two lines agree');
    assertSame(1, $buckets['differs']['count'], 'one is the same bill for different money');
    assertSame(1, $buckets['only_statement']['count'], 'one is billed to us and unknown to Books');
    assertSame(1, $buckets['only_books']['count'], 'and one bill is ours and not on their statement');

    // INV/0002 and INV-0002 are the same document, and matching them is the
    // difference between a clean reconciliation and four false exceptions.
    $refs = array_map(static fn (array $r) => $r['statement']['reference'], $buckets['agreed']['rows']);
    assertTrue(in_array('INV/0002', $refs, true), 'punctuation did not break the match');

    assertSame('INV-9999', $buckets['only_statement']['rows'][0]['reference'], 'the unknown one is named');
});

check('a reconciliation reports and never writes', function () use ($ctx, $auth) {
    // The whole safety property in one assertion: Books owns the ledger, and a
    // statement is the supplier's opinion of it. Nothing about reconciling may
    // change a bill, a payable or a voucher.
    resetDatabase();
    $before = (int) Db::scalar('SELECT COUNT(*) FROM purchase_bill_requests');

    $statement = CsvReader::read("Date,Invoice,Amount\n2026-08-01,INV-0001,999999.00\n");
    $map = ColumnMap::detect($statement);
    $ledger = (new \Aicountly\Api\Dashboards\BooksReader($ctx, $auth->sesKey()))->supplierLedger(601, '2026-08-01', '2026-08-31');

    $report = StatementReconciler::reconcile($map->dataRows($statement), $map, $ledger['rows'], 'INR');

    assertTrue($report['lines_read'] === 1, 'the line was read');
    assertSame($before, (int) Db::scalar('SELECT COUNT(*) FROM purchase_bill_requests'), 'and nothing was created');
});

check('a dashboard prints, and an unavailable figure prints as Unavailable', function () use ($ctx, $auth) {
    resetDatabase();
    $payload = dashboardFor('overview', $ctx, $auth, ['preset' => 'this_year']);
    $pdf = ReportRenderer::render($payload, 'Purchase overview', 'Company 88');

    assertTrue(str_starts_with($pdf, '%PDF-'), 'a PDF was produced');
    assertTrue(strlen($pdf) > 1200, 'with content in it');
    assertTrue(str_contains($pdf, 'Purchase overview'), 'titled with the report, not the route');

    // A printout is circulated, filed and quoted months later. A zero standing
    // in for "we could not ask" becomes a fact the moment it is printed.
    $hasUnavailable = false;
    foreach ($payload['metrics'] as $metric) {
        if ($metric['status'] !== 'ready') {
            $hasUnavailable = true;
            break;
        }
    }
    if ($hasUnavailable) {
        assertTrue(str_contains($pdf, 'Unavailable'), 'and it says so on the page');
    }
});

check('the export endpoint answers with a real PDF when asked for one', function () use ($ctx, $auth) {
    resetDatabase();
    $_GET = ['cmp_id' => (string) $ctx->cmpId, 'fy_id' => (string) $ctx->fyId, 'bo_id' => '0', 'format' => 'pdf', 'preset' => 'this_year'];
    Auth::adopt($auth);

    try {
        \Aicountly\Api\Controllers\DashboardsController::export('overview');
        throw new \RuntimeException('export returned without responding');
    } catch (ResponseSent $sent) {
        $data = $sent->payload['data'] ?? [];
        assertSame('pdf', $data['format'] ?? null, 'the PDF branch answered');
        $bytes = base64_decode((string) ($data['pdf'] ?? ''), true);
        assertTrue(is_string($bytes) && str_starts_with($bytes, '%PDF-'), 'and the bytes are a PDF');
    } finally {
        Auth::adopt(null);
    }
});

echo "\n" . str_repeat('-', 60) . "\n";
echo "{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
