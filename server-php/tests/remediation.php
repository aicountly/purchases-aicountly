<?php

declare(strict_types=1);

/**
 * The procurement-to-payment remediation, proved against real PostgreSQL.
 *
 * Receipt identity and exactly-once application, retry-safe integration commands, the
 * purchase order's receipt / billing / closure, and bills that post what they say — each
 * journey the audit of 2026-09-27 found broken, as a test that fails without the fix.
 * The races are real: several PHP processes, several connections, started together.
 *
 *   server-php/tests/run.sh
 */

namespace Aicountly\Api;

require __DIR__ . '/../src/Env.php';
require __DIR__ . '/../src/Autoload.php';

Env::load(__DIR__ . '/../.env');

use Aicountly\Api\Clients\ProducerCapabilities;
use Aicountly\Api\Domain\BillService;
use Aicountly\Api\Domain\PoProgress;
use Aicountly\Api\Domain\PurchaseOrderService;
use Aicountly\Api\Domain\ReceiptService;

$passed = 0;
$failed = 0;

function check(string $name, callable $fn): void
{
    global $passed, $failed;
    Context::forgetVerified();
    ProducerCapabilities::forget();
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

function same(mixed $expected, mixed $actual, string $what): void
{
    if ($expected !== $actual) {
        throw new \RuntimeException(sprintf('%s: expected %s, got %s', $what, var_export($expected, true), var_export($actual, true)));
    }
}

function truthy(bool $condition, string $what): void
{
    if (!$condition) {
        throw new \RuntimeException($what);
    }
}

/** @return array{status:int, code:?string, message:string} */
function refused(callable $fn, string $fragment, string $what): array
{
    try {
        $fn();
    } catch (ResponseSent $e) {
        if ($fragment !== '' && !str_contains($e->getMessage(), $fragment) && !str_contains((string) ($e->payload['error']['code'] ?? ''), $fragment)) {
            throw new \RuntimeException($what . ': wrong refusal — ' . $e->getMessage());
        }

        return ['status' => $e->status, 'code' => $e->payload['error']['code'] ?? null, 'message' => $e->getMessage()];
    }
    throw new \RuntimeException($what . ': expected a refusal, none came');
}

function ctx(int $cmpId = 88): Context
{
    return Context::of($cmpId, 6, 0);
}

function person(string $uuid = 'user-owner', ?int $acsType = 1, int $cmpId = 88): Auth
{
    $r = new \ReflectionClass(Auth::class);
    $auth = $r->newInstanceWithoutConstructor();
    foreach (['uuid' => $uuid, 'kind' => 'user', 'sourceApp' => 'purchases', 'sesKey' => 'stub-ses-key.role-' . ($acsType ?? 'silent'), 'session' => ['name' => $uuid]] as $prop => $value) {
        $p = $r->getProperty($prop);
        $p->setValue($auth, $value);
    }
    $auth->noteCompanyAccess($cmpId, $acsType);

    return $auth;
}

function reset(): void
{
    require_once __DIR__ . '/reset.php';
    resetPurchaseTables();
    Permissions::forget();
}

function stubFail(string $path, int $status, bool $after = false, string $code = 'stub_forced'): void
{
    file_put_contents(sys_get_temp_dir() . '/stub-control.json', json_encode(['path' => $path, 'status' => $status, 'after' => $after, 'code' => $code]));
}

function stubRecover(): void
{
    @unlink(sys_get_temp_dir() . '/stub-control.json');
}

function stubMode(array $modes): void
{
    file_put_contents(sys_get_temp_dir() . '/stub-mode.json', json_encode($modes));
}

/** @return list<array<string, mixed>> */
function stubRequests(string $fragment = ''): array
{
    $log = sys_get_temp_dir() . '/stub-requests.jsonl';
    if (!is_file($log)) {
        return [];
    }
    $out = [];
    foreach (explode("\n", trim((string) file_get_contents($log))) as $line) {
        if ($line === '') {
            continue;
        }
        $row = json_decode($line, true);
        if ($fragment === '' || str_contains((string) $row['path'], $fragment)) {
            $out[] = $row;
        }
    }

    return $out;
}

/** Inventory's documents as the stub holds them. @return array<string, mixed> */
function inventoryState(): array
{
    $file = sys_get_temp_dir() . '/stub-documents.json';

    return is_file($file) ? (json_decode((string) file_get_contents($file), true) ?: []) + ['by_id' => [], 'by_source' => []] : ['by_id' => [], 'by_source' => []];
}

/** Books' posted vouchers as the stub holds them. @return array<string, mixed> */
function booksVouchers(): array
{
    $file = sys_get_temp_dir() . '/stub-vouchers.json';

    return is_file($file) ? (json_decode((string) file_get_contents($file), true) ?: []) : [];
}

function orderOf(Context $ctx, Auth $auth, array $lines = [['item_id' => 201, 'unit_id' => 1, 'ordered_qty' => 100, 'agreed_rate' => 250, 'warehouse_id' => 3]]): array
{
    $orders = new PurchaseOrderService($ctx, $auth);
    $po = $orders->create([
        'supplier_account_id' => 601, 'supplier_name' => 'Deccan Steel Traders', 'po_date' => '2026-09-01',
        'delivery_warehouse_id' => 3, 'lines' => $lines,
    ]);
    $orders->submit((int) $po['po_id']);
    $orders->issue((int) $po['po_id']);

    return $orders->find((int) $po['po_id']);
}

function receive(Context $ctx, Auth $auth, int $poId, int $lineId, float $qty, array $extra = []): array
{
    return (new ReceiptService($ctx, $auth))->request($poId, $extra + ['received_at' => '2026-09-18', 'lines' => [['line_id' => $lineId, 'qty' => $qty]]]);
}

/**
 * Run workers together and collect what each printed.
 *
 * @param list<array{0: string, 1: array<string, mixed>}> $jobs
 * @return list<array<string, mixed>>
 */
function race(array $jobs): array
{
    $startAt = microtime(true) + 0.6;
    $procs = [];
    foreach ($jobs as $i => [$action, $args]) {
        $args['start_at'] = $startAt;
        $cmd = sprintf('php %s %s %s', escapeshellarg(__DIR__ . '/support/worker.php'), escapeshellarg($action), escapeshellarg(json_encode($args)));
        $procs[$i] = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i]);
    }
    $out = [];
    foreach ($procs as $i => $proc) {
        $stdout = stream_get_contents($pipes[$i][1]);
        $stderr = stream_get_contents($pipes[$i][2]);
        proc_close($proc);
        $lines = array_values(array_filter(explode("\n", trim((string) $stdout))));
        $decoded = json_decode((string) end($lines), true);
        $out[$i] = is_array($decoded) ? $decoded : ['ok' => false, 'status' => -1, 'message' => trim($stdout . ' ' . $stderr)];
    }

    return $out;
}

$ctx = ctx();
$owner = person();

// ---------------------------------------------------------------------------
echo "\nReceipts: identity, exactly once, cumulative limits\n";

check('two deliveries against one order are two GRNs, and both count', function () use ($ctx, $owner) {
    reset();
    $po = orderOf($ctx, $owner);
    $lineId = (int) $po['lines'][0]['line_id'];

    receive($ctx, $owner, (int) $po['po_id'], $lineId, 60);
    $after = receive($ctx, $owner, (int) $po['po_id'], $lineId, 40);

    $docs = array_values(inventoryState()['by_id']);
    same(2, count($docs), 'Inventory holds two documents');
    truthy($docs[0]['source_document_id'] !== $docs[1]['source_document_id'], 'each filed under its own receipt');
    same('purchases.receipt', $docs[1]['source_document_type'], 'the receipt is the source identity');
    same((int) $po['po_id'], (int) $docs[1]['metadata']['purchase_order_id'], 'the order is a reference beside it');
    same('100.0000', (string) $after['lines'][0]['received_qty'], 'the order counts 60 + 40, not 60 twice');
    same('RECEIVED', $after['status'], 'and is fully received');
    same(2, count(array_filter($after['receipts'], static fn ($r) => $r['applied_at'] !== null)), 'both receipts applied');
});

check('the same submission twice is one receipt', function () use ($ctx, $owner) {
    reset();
    $po = orderOf($ctx, $owner);
    $lineId = (int) $po['lines'][0]['line_id'];

    receive($ctx, $owner, (int) $po['po_id'], $lineId, 30, ['client_token' => 'form-7']);
    $again = receive($ctx, $owner, (int) $po['po_id'], $lineId, 30, ['client_token' => 'form-7']);

    same(1, count($again['receipts']), 'one receipt');
    same('30.0000', (string) $again['lines'][0]['received_qty'], 'counted once');
    same(1, count(stubRequests('/v1/inventory-documents/post')), 'Inventory was asked once');
});

check('a lost response is recovered by Retry on the same key, and counts once', function () use ($ctx, $owner) {
    reset();
    $po = orderOf($ctx, $owner);
    $lineId = (int) $po['lines'][0]['line_id'];

    // Inventory records the GRN and the answer never arrives.
    stubFail('inventory-documents/post', 504, true);
    $refusal = refused(fn () => receive($ctx, $owner, (int) $po['po_id'], $lineId, 25), 'inventory_uncertain', 'the post whose answer was lost');
    stubRecover();
    same(502, $refusal['status'], 'reported as not confirmed');

    $receipt = Db::first('SELECT * FROM purchase_receipt_requests ORDER BY request_id DESC LIMIT 1');
    same('UNCERTAIN', $receipt['status'], 'the receipt says the outcome is unknown');
    same(null, $receipt['applied_at'], 'and nothing was counted yet');
    same(1, count(inventoryState()['by_id']), 'though Inventory did record it');

    $after = (new ReceiptService($ctx, $owner))->retry((int) $receipt['request_id']);
    same('25.0000', (string) $after['lines'][0]['received_qty'], 'counted once after the retry');
    same(1, count(inventoryState()['by_id']), 'and Inventory still holds one document');
    $keys = array_values(array_unique(array_map(static fn ($r) => $r['headers']['idempotency-key'] ?? '', stubRequests('/v1/inventory-documents/post'))));
    same(1, count($keys), 'both attempts carried one key');
    $bodies = array_values(array_unique(array_map(static fn ($r) => json_encode($r['body']), stubRequests('/v1/inventory-documents/post'))));
    same(1, count($bodies), 'and one body — Inventory refuses a reused key with a different body');
    same('replay', Db::scalar("SELECT resolved_by FROM purchase_integration_commands WHERE entity_type = 'receipt_request'"), 'settled from the replayed answer');

    refused(fn () => (new ReceiptService($ctx, $owner))->retry((int) $receipt['request_id']), 'already been recorded', 'a third attempt');
});

check('a lost response is recovered by Reconcile without sending anything', function () use ($ctx, $owner) {
    reset();
    $po = orderOf($ctx, $owner);
    $lineId = (int) $po['lines'][0]['line_id'];

    stubFail('inventory-documents/post', 504, true);
    refused(fn () => receive($ctx, $owner, (int) $po['po_id'], $lineId, 25), 'inventory_uncertain', 'lost answer');
    stubRecover();
    $requestId = (int) Db::scalar('SELECT request_id FROM purchase_receipt_requests ORDER BY request_id DESC LIMIT 1');

    $posts = count(stubRequests('/v1/inventory-documents/post'));
    $after = (new ReceiptService($ctx, $owner))->reconcile($requestId);
    same($posts, count(stubRequests('/v1/inventory-documents/post')), 'reconcile only asked');
    same('25.0000', (string) $after['lines'][0]['received_qty'], 'counted once from what Inventory holds');
    same('reconcile', Db::scalar("SELECT resolved_by FROM purchase_integration_commands WHERE entity_type = 'receipt_request'"), 'recorded as reconciled');

    $again = (new ReceiptService($ctx, $owner))->reconcile($requestId);
    same('25.0000', (string) $again['lines'][0]['received_qty'], 'a second reconcile adds nothing');
});

check('a document that is not this receipt\'s is refused and counts for nothing', function () use ($ctx, $owner) {
    reset();
    $po = orderOf($ctx, $owner);
    stubMode(['inventory_wrong_source' => true]);
    refused(fn () => receive($ctx, $owner, (int) $po['po_id'], (int) $po['lines'][0]['line_id'], 10), 'receipt_mismatch', 'a foreign document');
    stubMode([]);

    $line = Db::first('SELECT received_qty FROM purchase_order_lines WHERE po_id = :po', ['po' => (int) $po['po_id']]);
    same('0.0000', (string) $line['received_qty'], 'nothing added to the order');
    same('BLOCKED', Db::scalar('SELECT status FROM purchase_receipt_requests'), 'the receipt stops for a person');
});

check('Inventory being down leaves the receipt retryable, and its quantity reserved', function () use ($ctx, $owner) {
    reset();
    $po = orderOf($ctx, $owner);
    $lineId = (int) $po['lines'][0]['line_id'];

    stubFail('inventory-documents/post', 503);
    refused(fn () => receive($ctx, $owner, (int) $po['po_id'], $lineId, 80), 'press Retry', 'Inventory down');
    stubRecover();

    // 80 is still on its way: only 20 more may be received meanwhile.
    refused(fn () => receive($ctx, $owner, (int) $po['po_id'], $lineId, 30), 'already on its way', 'the in-flight quantity counts');
    receive($ctx, $owner, (int) $po['po_id'], $lineId, 20);

    // Withdrawing the failed receipt frees its quantity.
    $failed = (int) Db::scalar("SELECT request_id FROM purchase_receipt_requests WHERE status = 'FAILED'");
    (new ReceiptService($ctx, $owner))->cancel($failed, ['reason' => 'Delivery turned away at the gate.']);
    $after = receive($ctx, $owner, (int) $po['po_id'], $lineId, 80);
    same('100.0000', (string) $after['lines'][0]['received_qty'], 'the order received what really arrived');
});

check('over-receipt is refused by default, allowed within tolerance, and beyond it only by an authorised person with a reason', function () use ($ctx, $owner) {
    reset();
    $po = orderOf($ctx, $owner);
    $lineId = (int) $po['lines'][0]['line_id'];
    $clerk = person('user-clerk', 0);
    Db::insert('purchase_permission_profiles', ['cmp_id' => 88, 'profile_name' => 'Stores', 'permissions' => ['receipt.request', 'po.view'], 'is_active' => true], 'profile_id');
    Db::run("INSERT INTO purchase_permission_assignments (cmp_id, user_uuid, profile_id) SELECT 88, 'user-clerk', profile_id FROM purchase_permission_profiles WHERE profile_name = 'Stores'");

    refused(fn () => receive($ctx, $clerk, (int) $po['po_id'], $lineId, 101), 'would exceed the order', 'one over, no tolerance');

    Db::run('INSERT INTO purchase_settings (cmp_id, over_receipt_tolerance_pc) VALUES (88, 5) ON CONFLICT (cmp_id) DO UPDATE SET over_receipt_tolerance_pc = 5');
    receive($ctx, $clerk, (int) $po['po_id'], $lineId, 104);
    refused(fn () => receive($ctx, $clerk, (int) $po['po_id'], $lineId, 2), 'tolerance', 'beyond the tolerance');
    refused(fn () => receive($ctx, $owner, (int) $po['po_id'], $lineId, 2), 'tolerance', 'even the owner needs a reason');
    $after = receive($ctx, $owner, (int) $po['po_id'], $lineId, 2, ['over_receipt_reason' => 'Supplier shipped a full pallet; accepted by the plant head.']);
    same('106.0000', (string) $after['lines'][0]['received_qty'], 'accepted with a reason');
    same('Supplier shipped a full pallet; accepted by the plant head.', Db::scalar('SELECT over_receipt_reason FROM purchase_receipt_requests ORDER BY request_id DESC LIMIT 1'), 'and the reason is kept');
});

check('concurrent deliveries respect the order\'s quantity (real PostgreSQL race)', function () use ($ctx, $owner) {
    reset();
    $po = orderOf($ctx, $owner);
    $lineId = (int) $po['lines'][0]['line_id'];

    $jobs = [];
    for ($i = 0; $i < 5; $i++) {
        $jobs[] = ['receive', ['po_id' => (int) $po['po_id'], 'input' => ['received_at' => '2026-09-18', 'lines' => [['line_id' => $lineId, 'qty' => 30]]]]];
    }
    $results = race($jobs);
    $accepted = count(array_filter($results, static fn ($r) => $r['ok']));
    $refusedCount = count(array_filter($results, static fn ($r) => !$r['ok'] && ($r['status'] ?? 0) === 422));

    $seen = ' — ' . json_encode(array_map(static fn ($r) => [$r['status'] ?? null, $r['code'] ?? null, $r['message'] ?? null], $results));
    same(3, $accepted, 'three deliveries of 30 fit an order of 100' . $seen);
    same(2, $refusedCount, 'the other two are refused, not squeezed in' . $seen);
    same('90.0000', (string) Db::scalar('SELECT received_qty FROM purchase_order_lines WHERE line_id = :id', ['id' => $lineId]), 'the order counts exactly what was admitted');
    same(3, count(inventoryState()['by_id']), 'and Inventory holds exactly three GRNs');
    same(3, (int) Db::scalar('SELECT COUNT(DISTINCT receipt_no) FROM purchase_receipt_requests'), 'with three distinct GRN numbers');
});

check('receipt status and the three-way match read every GRN, and a reversed one counts for nothing', function () use ($ctx, $owner) {
    reset();
    $po = orderOf($ctx, $owner);
    $lineId = (int) $po['lines'][0]['line_id'];
    receive($ctx, $owner, (int) $po['po_id'], $lineId, 60);
    receive($ctx, $owner, (int) $po['po_id'], $lineId, 40);

    $bill = (new BillService($ctx, $owner))->enter([
        'supplier_account_id' => 601, 'po_id' => (int) $po['po_id'], 'supplier_invoice_no' => 'M-1', 'supplier_invoice_date' => '2026-09-19',
        'lines' => [['po_line_id' => $lineId, 'qty' => 100, 'rate' => 250]],
    ]);
    same('MATCHED', $bill['match']['verdict'], 'billed 100 against two GRNs of 60 and 40');

    // Inventory reverses the second GRN.
    $second = (int) Db::scalar('SELECT inventory_document_id FROM purchase_receipt_requests ORDER BY request_id DESC LIMIT 1');
    (new Clients\InventoryClient())->withService('ops')->reverseDocument($ctx, $second, 'Wrong item', 'reverse-test-1');

    $ledger = (new Domain\ReceiptLedger($ctx, $owner))->forOrder((int) $po['po_id']);
    same(60.0, $ledger['received_by_line'][$lineId] ?? null, 'only the live GRN counts');
    same('reversed_in_inventory', $ledger['discrepancies'][0]['kind'] ?? null, 'and the order\'s own count is reported as disagreeing');

    $rematch = (new BillService($ctx, $owner))->rematch((int) $bill['request_id']);
    same('BLOCKED', $rematch['match']['verdict'], 'the bill for 100 no longer matches 60 received');
});

check('a cancel and a Retry of the same failed receipt never leave a GRN for a cancelled receipt (real race)', function () use ($ctx, $owner) {
    for ($round = 0; $round < 3; $round++) {
        reset();
        $po = orderOf($ctx, $owner);
        stubFail('inventory-documents/post', 503);
        refused(fn () => receive($ctx, $owner, (int) $po['po_id'], (int) $po['lines'][0]['line_id'], 10), 'press Retry', 'first send fails');
        stubRecover();
        $requestId = (int) Db::scalar('SELECT request_id FROM purchase_receipt_requests');

        race([['retry_receipt', ['request_id' => $requestId]], ['cancel_receipt', ['request_id' => $requestId]]]);

        $receipt = Db::first('SELECT status, applied_at FROM purchase_receipt_requests WHERE request_id = :id', ['id' => $requestId]);
        $inInventory = count(inventoryState()['by_id']);
        if ($receipt['status'] === 'CANCELLED') {
            same(0, $inInventory, 'round ' . $round . ': a cancelled receipt reached Inventory');
        } else {
            truthy($receipt['applied_at'] !== null && $inInventory === 1, 'round ' . $round . ': the Retry won, so it is applied once (' . $receipt['status'] . ')');
        }
    }
});

check('a cancelled receipt cannot be sent again, and its command leaves the open work', function () use ($ctx, $owner) {
    reset();
    $po = orderOf($ctx, $owner);
    stubFail('inventory-documents/post', 503);
    refused(fn () => receive($ctx, $owner, (int) $po['po_id'], (int) $po['lines'][0]['line_id'], 10), 'press Retry', 'first send fails');
    stubRecover();
    $requestId = (int) Db::scalar('SELECT request_id FROM purchase_receipt_requests');
    (new ReceiptService($ctx, $owner))->cancel($requestId, ['reason' => 'Turned away.']);

    refused(fn () => (new ReceiptService($ctx, $owner))->retry($requestId), 'cancelled', 'retrying a cancelled receipt');
    same('CANCELLED', Db::scalar("SELECT status FROM purchase_integration_commands WHERE entity_type = 'receipt_request'"), 'the command is withdrawn');
    same(0, count(IntegrationCommand::outstanding($ctx)), 'and is not open work');
    same(0, count(inventoryState()['by_id']), 'nothing reached Inventory');
});

// ---------------------------------------------------------------------------
echo "\nIntegration commands\n";

check('racing processes create one command, one key and one claim', function () {
    reset();
    $jobs = [];
    for ($i = 0; $i < 6; $i++) {
        $jobs[] = ['ensure_claim', ['entity_id' => 4242, 'n' => $i]];
    }
    $results = race($jobs);

    same(1, (int) Db::scalar("SELECT COUNT(*) FROM purchase_integration_commands WHERE entity_type = 'race'"), 'one row for the operation');
    same(1, count(array_unique(array_map(static fn ($r) => $r['result']['key'] ?? '', $results))), 'every process saw the same key');
    same(1, count(array_filter($results, static fn ($r) => !empty($r['result']['claimed']))), 'exactly one attempt holds it');
    same(1, count(array_unique(array_map(static fn ($r) => json_encode($r['result']['payload'] ?? null), $results))), 'and one stored body, the first');
});

check('the key is derived from the operation, with no random part', function () {
    same('purchases:88:purchases.receipt.request:receipt_request:17:r0', IntegrationCommand::key(88, 'purchases.receipt.request', 'receipt_request', 17), 'derived');
    same('purchases:88:purchases.bill.post:bill_request:5:r1', IntegrationCommand::key(88, 'purchases.bill.post', 'bill_request', 5, 1), 'a new revision is a new key');
});

check('an abandoned attempt\'s lease expires, and the next attempt takes it with the same key', function () use ($ctx) {
    reset();
    $command = IntegrationCommand::ensure($ctx, 'inventory', 'test.lease', 'lease', 1, ['a' => 1]);
    $first = IntegrationCommand::claim((int) $command['command_id']);
    truthy($first['claimed'], 'first attempt claims');
    same(false, IntegrationCommand::claim((int) $command['command_id'])['claimed'], 'a live lease is not taken');

    Db::run("UPDATE purchase_integration_commands SET lease_expires_at = NOW() - interval '1 second' WHERE command_id = :id", ['id' => (int) $command['command_id']]);
    $second = IntegrationCommand::claim((int) $command['command_id']);
    truthy($second['claimed'], 'an expired lease is taken');
    same($command['idempotency_key'], $second['command']['idempotency_key'], 'with the same key');
    same(false, IntegrationCommand::complete((int) $command['command_id'], (string) $first['command']['lease_token'], ['x' => 1]), 'the superseded attempt cannot record an outcome');
    truthy(IntegrationCommand::complete((int) $command['command_id'], (string) $second['command']['lease_token'], ['x' => 2]), 'the live one can');
});

check('a purchase order\'s strip shows the commands of its receipts and bills', function () use ($ctx, $owner) {
    reset();
    $po = orderOf($ctx, $owner);
    stubFail('inventory-documents/post', 503);
    refused(fn () => receive($ctx, $owner, (int) $po['po_id'], (int) $po['lines'][0]['line_id'], 10), 'press Retry', 'a failed GRN');
    stubRecover();

    $view = (new PurchaseOrderService($ctx, $owner))->find((int) $po['po_id']);
    $stuck = array_values(array_filter($view['commands'], static fn ($c) => $c['status'] === 'FAILED'));
    same(1, count($stuck), 'the failed GRN is on the order\'s strip');
    same('receipt_request', $stuck[0]['entity_type'], 'filed under its receipt');
});

// ---------------------------------------------------------------------------
echo "\nPurchase order: received, billed, closed\n";

check('PO 100 → receipt 40 → bill 40 stays open for 60', function () use ($ctx, $owner) {
    reset();
    $po = orderOf($ctx, $owner);
    $lineId = (int) $po['lines'][0]['line_id'];
    receive($ctx, $owner, (int) $po['po_id'], $lineId, 40);
    $bills = new BillService($ctx, $owner);
    $bill = $bills->enter(['supplier_account_id' => 601, 'po_id' => (int) $po['po_id'], 'supplier_invoice_no' => 'P-40', 'supplier_invoice_date' => '2026-09-19', 'lines' => [['po_line_id' => $lineId, 'qty' => 40, 'rate' => 250]]]);
    $bills->post((int) $bill['request_id']);

    $after = (new PurchaseOrderService($ctx, $owner))->find((int) $po['po_id']);
    same('PARTIALLY_RECEIVED', $after['status'], 'not closed');
    same('PARTIAL', $after['receipt_status'], '60 still to arrive');
    same('PARTIAL', $after['billing_status'], 'billed so far, not complete');
    same(60.0, $after['progress']['lines'][0]['to_receive_qty'], 'the 60 is still wanted');
    same(1, (int) (new PurchaseOrderService($ctx, $owner))->search(['open_only' => true], 50, 0, 'po_date', 'desc')['total'], 'and it is still on the open list');

    receive($ctx, $owner, (int) $po['po_id'], $lineId, 60);
    $bill2 = $bills->enter(['supplier_account_id' => 601, 'po_id' => (int) $po['po_id'], 'supplier_invoice_no' => 'P-60', 'supplier_invoice_date' => '2026-09-25', 'lines' => [['po_line_id' => $lineId, 'qty' => 60, 'rate' => 250]]]);
    $bills->post((int) $bill2['request_id']);
    $closed = (new PurchaseOrderService($ctx, $owner))->find((int) $po['po_id']);
    same('CLOSED', $closed['status'], 'closed once everything arrived and was billed');
    same('auto', $closed['closure_kind'], 'by the rule, not by hand');
});

check('a short-close records what will never come, with a reason, and the order then closes on its bills', function () use ($ctx, $owner) {
    reset();
    $po = orderOf($ctx, $owner);
    $lineId = (int) $po['lines'][0]['line_id'];
    receive($ctx, $owner, (int) $po['po_id'], $lineId, 40);
    $orders = new PurchaseOrderService($ctx, $owner);

    refused(fn () => $orders->shortClose((int) $po['po_id'], []), 'Say why', 'no reason');
    $clerk = person('user-clerk', 0);
    refused(fn () => (new PurchaseOrderService($ctx, $clerk))->shortClose((int) $po['po_id'], ['reason' => 'x']), 'permission', 'no po.close');

    $after = $orders->shortClose((int) $po['po_id'], ['reason' => 'Supplier discontinued the item.']);
    same('60.0000', (string) $after['lines'][0]['short_closed_qty'], 'the remaining 60 are recorded as never coming');
    same('40.0000', (string) $after['lines'][0]['received_qty'], 'what arrived is untouched');
    same('COMPLETE', $after['receipt_status'], 'nothing more is awaited');
    same('RECEIVED', $after['status'], 'but the 40 still owe a bill, so it is not closed');
    truthy((int) Db::scalar("SELECT COUNT(*) FROM purchase_audit_log WHERE action = 'po.short_closed' AND reason = 'Supplier discontinued the item.'") === 1, 'audited with the reason');

    $bills = new BillService($ctx, $owner);
    $bill = $bills->enter(['supplier_account_id' => 601, 'po_id' => (int) $po['po_id'], 'supplier_invoice_no' => 'SC-40', 'supplier_invoice_date' => '2026-09-19', 'lines' => [['po_line_id' => $lineId, 'qty' => 40, 'rate' => 250]]]);
    $bills->post((int) $bill['request_id']);
    $closed = $orders->find((int) $po['po_id']);
    same('CLOSED', $closed['status'], 'closed once the 40 were billed');
    same('short_close', $closed['closure_kind'], 'recorded as a short-close');
});

check('a cancelled order cannot be billed, and a cancelled order is never overwritten with CLOSED', function () use ($ctx, $owner) {
    reset();
    $orders = new PurchaseOrderService($ctx, $owner);
    $po = orderOf($ctx, $owner);
    $orders->cancel((int) $po['po_id'], ['reason' => 'Ordered in error.']);

    $bills = new BillService($ctx, $owner);
    refused(fn () => $bills->enter(['supplier_account_id' => 601, 'po_id' => (int) $po['po_id'], 'supplier_invoice_no' => 'C-1', 'supplier_invoice_date' => '2026-09-19', 'lines' => [['po_line_id' => (int) $po['lines'][0]['line_id'], 'qty' => 1, 'rate' => 250]]]), 'cancelled', 'bill on a cancelled order');
    PoProgress::recompute((int) $po['po_id'], 88);
    same('CANCELLED', Db::scalar('SELECT status FROM purchase_orders WHERE po_id = :id', ['id' => (int) $po['po_id']]), 'still CANCELLED');
});

check('an order with a bill entered cannot be cancelled, and a cancel racing a bill never leaves both (real race)', function () use ($ctx, $owner) {
    reset();
    $po = orderOf($ctx, $owner);
    $lineId = (int) $po['lines'][0]['line_id'];

    $results = race([
        ['cancel_po', ['po_id' => (int) $po['po_id']]],
        ['enter_bill', ['input' => ['supplier_account_id' => 601, 'po_id' => (int) $po['po_id'], 'supplier_invoice_no' => 'RACE-1', 'supplier_invoice_date' => '2026-09-19', 'lines' => [['po_line_id' => $lineId, 'qty' => 10, 'rate' => 250]]]]],
    ]);
    $status = Db::scalar('SELECT status FROM purchase_orders WHERE po_id = :id', ['id' => (int) $po['po_id']]);
    $bills = (int) Db::scalar("SELECT COUNT(*) FROM purchase_bill_requests WHERE po_id = :id AND status <> 'CANCELLED'", ['id' => (int) $po['po_id']]);
    truthy(!($status === 'CANCELLED' && $bills > 0), 'never a cancelled order with a live bill (status ' . $status . ', bills ' . $bills . ')');
    same(1, count(array_filter($results, static fn ($r) => $r['ok'])), 'exactly one of the two went through');
});

// ---------------------------------------------------------------------------
echo "\nBills: authentication, payload and what Books recorded\n";

check('Books is written on the user\'s session, never on a bare service key', function () use ($ctx, $owner) {
    reset();
    $po = orderOf($ctx, $owner);
    receive($ctx, $owner, (int) $po['po_id'], (int) $po['lines'][0]['line_id'], 100);
    $bills = new BillService($ctx, $owner);
    $bill = $bills->enter(['supplier_account_id' => 601, 'po_id' => (int) $po['po_id'], 'supplier_invoice_no' => 'AUTH-1', 'supplier_invoice_date' => '2026-09-19', 'lines' => [['po_line_id' => (int) $po['lines'][0]['line_id'], 'qty' => 100, 'rate' => 250]]]);
    $bills->post((int) $bill['request_id']);

    foreach (stubRequests('/vouchers/drafts') as $request) {
        truthy(str_starts_with((string) ($request['headers']['authorization'] ?? ''), 'Bearer '), 'a Bearer session on ' . $request['path']);
        truthy(!isset($request['headers']['x-service-key']), 'and no service key on ' . $request['path']);
    }
});

check('a lost Books answer is retried on the same keys and posts one voucher', function () use ($ctx, $owner) {
    reset();
    $po = orderOf($ctx, $owner);
    receive($ctx, $owner, (int) $po['po_id'], (int) $po['lines'][0]['line_id'], 100);
    $bills = new BillService($ctx, $owner);
    $bill = $bills->enter(['supplier_account_id' => 601, 'po_id' => (int) $po['po_id'], 'supplier_invoice_no' => 'LOST-1', 'supplier_invoice_date' => '2026-09-19', 'lines' => [['po_line_id' => (int) $po['lines'][0]['line_id'], 'qty' => 100, 'rate' => 250]]]);

    stubFail('/post', 504, true);
    refused(fn () => $bills->post((int) $bill['request_id']), 'books_uncertain', 'answer lost');
    stubRecover();
    same('UNCERTAIN', Db::scalar('SELECT status FROM purchase_bill_requests'), 'the bill says it may have posted');
    refused(fn () => $bills->cancel((int) $bill['request_id'], ['reason' => 'x']), 'may already hold', 'an uncertain bill cannot be cancelled');

    $posted = $bills->post((int) $bill['request_id']);
    same('POSTED', $posted['status'], 'posted on retry');
    same(1, count(booksVouchers()), 'Books holds one voucher');
    same('100.0000', (string) Db::scalar('SELECT billed_qty FROM purchase_order_lines WHERE po_id = :id', ['id' => (int) $po['po_id']]), 'billed once');
});

check('what Books recorded is checked, and a voucher that is not this bill is flagged, not trusted', function () use ($ctx, $owner) {
    reset();
    $po = orderOf($ctx, $owner);
    receive($ctx, $owner, (int) $po['po_id'], (int) $po['lines'][0]['line_id'], 100);
    stubMode(['books_mangle' => true]);
    $bills = new BillService($ctx, $owner);
    $bill = $bills->enter(['supplier_account_id' => 601, 'po_id' => (int) $po['po_id'], 'supplier_invoice_no' => 'CHK-1', 'supplier_invoice_date' => '2026-09-19', 'lines' => [['po_line_id' => (int) $po['lines'][0]['line_id'], 'qty' => 100, 'rate' => 250]]]);
    $posted = $bills->post((int) $bill['request_id']);
    stubMode([]);

    same(false, $posted['posting_check']['verified'], 'not verified');
    $problems = implode(' | ', $posted['posting_check']['problems']);
    truthy(str_contains($problems, 'not credited'), 'the missing payable is named: ' . $problems);
    truthy(str_contains($problems, 'bill reference'), 'and the missing supplier invoice reference');
});

check('a bill that would settle a physical GRN waits for a Books and an Inventory that can settle one', function () use ($ctx, $owner) {
    reset();
    $po = orderOf($ctx, $owner);
    receive($ctx, $owner, (int) $po['po_id'], (int) $po['lines'][0]['line_id'], 100);
    file_put_contents(sys_get_temp_dir() . '/stub-books-caps.json', 'null');
    $bills = new BillService($ctx, $owner);
    $bill = $bills->enter(['supplier_account_id' => 601, 'po_id' => (int) $po['po_id'], 'supplier_invoice_no' => 'CAP-1', 'supplier_invoice_date' => '2026-09-19', 'lines' => [['po_line_id' => (int) $po['lines'][0]['line_id'], 'qty' => 100, 'rate' => 250]]]);

    $refusal = refused(fn () => $bills->post((int) $bill['request_id']), 'producer_capability_missing', 'an older Books');
    same(409, $refusal['status'], 'refused');
    same(0, count(stubRequests('/vouchers/drafts')), 'nothing was sent — an older Books would have received the goods again');
    @unlink(sys_get_temp_dir() . '/stub-books-caps.json');
    ProducerCapabilities::forget();

    same('POSTED', $bills->post((int) $bill['request_id'])['status'], 'posted once Books can settle a physical GRN');
});

check('a bill Books refused is revised as a new operation with a new key', function () use ($ctx, $owner) {
    reset();
    $po = orderOf($ctx, $owner);
    receive($ctx, $owner, (int) $po['po_id'], (int) $po['lines'][0]['line_id'], 100);
    $bills = new BillService($ctx, $owner);
    $bill = $bills->enter(['supplier_account_id' => 601, 'po_id' => (int) $po['po_id'], 'supplier_invoice_no' => 'REV-1', 'supplier_invoice_date' => '2026-09-19', 'lines' => [['po_line_id' => (int) $po['lines'][0]['line_id'], 'qty' => 100, 'rate' => 250]]]);

    stubFail('/post', 422, false, 'period_locked');
    refused(fn () => $bills->post((int) $bill['request_id']), 'books_refused', 'Books refuses');
    stubRecover();
    same('BLOCKED', Db::scalar('SELECT status FROM purchase_bill_requests'), 'blocked');
    refused(fn () => $bills->post((int) $bill['request_id']), 'books_refused', 'retrying a refusal is refused again, without calling Books');

    $bills->revise((int) $bill['request_id'], ['supplier_invoice_date' => '2026-10-01', 'note' => 'Dated into the open period.']);
    $posted = $bills->post((int) $bill['request_id']);
    same('POSTED', $posted['status'], 'the revision posts');
    $keys = array_values(array_unique(array_map(static fn ($r) => $r['headers']['idempotency-key'] ?? '', stubRequests('/vouchers/drafts'))));
    truthy(count(array_filter($keys, static fn ($k) => str_contains($k, ':r1:'))) > 0, 'on the revision\'s key');
});

check('two people posting the same bill at once make one voucher (real race)', function () use ($ctx, $owner) {
    reset();
    $po = orderOf($ctx, $owner);
    receive($ctx, $owner, (int) $po['po_id'], (int) $po['lines'][0]['line_id'], 100);
    $bill = (new BillService($ctx, $owner))->enter(['supplier_account_id' => 601, 'po_id' => (int) $po['po_id'], 'supplier_invoice_no' => 'TWICE-1', 'supplier_invoice_date' => '2026-09-19', 'lines' => [['po_line_id' => (int) $po['lines'][0]['line_id'], 'qty' => 100, 'rate' => 250]]]);

    $results = race([['post_bill', ['request_id' => (int) $bill['request_id']]], ['post_bill', ['request_id' => (int) $bill['request_id']]], ['post_bill', ['request_id' => (int) $bill['request_id']]]]);
    truthy(count(array_filter($results, static fn ($r) => $r['ok'])) >= 1, 'at least one reports the posting: ' . json_encode(array_map(static fn ($r) => [$r['status'], $r['code'] ?? null], $results)));
    // A retry of an in-flight post is told to wait; it never posts again.
    foreach ($results as $r) {
        truthy($r['ok'] || in_array($r['code'] ?? '', ['bill_in_progress'], true) || str_contains((string) ($r['message'] ?? ''), 'already'), 'the others wait or see it posted: ' . json_encode($r));
    }
    same(1, count(booksVouchers()), 'Books holds one voucher');
    same('100.0000', (string) Db::scalar('SELECT billed_qty FROM purchase_order_lines WHERE po_id = :id', ['id' => (int) $po['po_id']]), 'billed once');
});

check('a cancel and a Post of the same bill never leave a voucher for a cancelled bill (real race)', function () use ($ctx, $owner) {
    for ($round = 0; $round < 3; $round++) {
        reset();
        $po = orderOf($ctx, $owner);
        receive($ctx, $owner, (int) $po['po_id'], (int) $po['lines'][0]['line_id'], 100);
        $bill = (new BillService($ctx, $owner))->enter(['supplier_account_id' => 601, 'po_id' => (int) $po['po_id'], 'supplier_invoice_no' => 'RC-' . $round, 'supplier_invoice_date' => '2026-09-19', 'lines' => [['po_line_id' => (int) $po['lines'][0]['line_id'], 'qty' => 100, 'rate' => 250]]]);

        race([['post_bill', ['request_id' => (int) $bill['request_id']]], ['cancel_bill', ['request_id' => (int) $bill['request_id']]]]);

        $status = Db::scalar('SELECT status FROM purchase_bill_requests WHERE request_id = :id', ['id' => (int) $bill['request_id']]);
        if ($status === 'CANCELLED') {
            same(0, count(booksVouchers()), 'round ' . $round . ': a cancelled bill reached Books');
        } else {
            same('POSTED', $status, 'round ' . $round . ': the post won');
            same(1, count(booksVouchers()), 'round ' . $round . ': once');
        }
    }
});

check('a revised bill\'s refused revision leaves the open work and cannot be sent again', function () use ($ctx, $owner) {
    reset();
    $bills = new BillService($ctx, $owner);
    $bill = $bills->enter(['supplier_account_id' => 601, 'supplier_invoice_no' => 'SUP-1', 'supplier_invoice_date' => '2026-09-19', 'lines' => [['description' => 'Freight', 'is_service' => true, 'purchase_acc_id' => 7302, 'qty' => 1, 'rate' => 5000]]]);
    // A bill with no order is reviewed before it posts.
    $bills->resolveException((int) $bill['matches'][0]['exceptions'][0]['exception_id'], 'accept', ['note' => 'Freight, reviewed against the carrier\'s note.']);
    stubFail('/post', 422, false, 'period_locked');
    refused(fn () => $bills->post((int) $bill['request_id']), 'books_refused', 'Books refuses');
    stubRecover();
    $bills->revise((int) $bill['request_id'], ['supplier_invoice_date' => '2026-10-01']);

    same('CANCELLED', Db::scalar("SELECT status FROM purchase_integration_commands WHERE entity_type = 'bill_request' AND revision = 0"), 'revision 0 withdrawn');
    same('superseded', Db::scalar("SELECT resolved_by FROM purchase_integration_commands WHERE entity_type = 'bill_request' AND revision = 0"), 'as superseded');
    same(0, count(array_filter(IntegrationCommand::outstanding($ctx), static fn ($c) => (int) $c['revision'] === 0)), 'and is not open work');
});

check('the same supplier invoice booked through Billing cannot be posted again from Purchase', function () use ($ctx, $owner) {
    reset();
    // Aicountly Billing has already booked the supplier's invoice DS/2026/118 in Books.
    file_put_contents(sys_get_temp_dir() . '/stub-vouchers.json', json_encode(['4999' => [
        'vch_txn_id' => 4999, 'vch_type_id' => 11, 'vch_number' => 'PUR/0099', 'vch_date' => '2026-09-19', 'source_app' => 'billing',
        'party' => ['acc_id' => 601], 'bill' => ['bill_ref' => 'DS/2026/118', 'bill_date' => '2026-09-19', 'dr_cr' => 2],
        'lines' => [], 'tax_summary' => ['grand_total' => 25000],
    ]]));
    $po = orderOf($ctx, $owner);
    receive($ctx, $owner, (int) $po['po_id'], (int) $po['lines'][0]['line_id'], 100);
    $bills = new BillService($ctx, $owner);
    // Purchase has never seen it, so its own duplicate check passes; the spelling differs too.
    $bill = $bills->enter(['supplier_account_id' => 601, 'po_id' => (int) $po['po_id'], 'supplier_invoice_no' => 'ds/2026/ 118', 'supplier_invoice_date' => '2026-09-19', 'lines' => [['po_line_id' => (int) $po['lines'][0]['line_id'], 'qty' => 100, 'rate' => 250]]]);

    $refusal = refused(fn () => $bills->post((int) $bill['request_id']), 'already booked', 'Books refuses the second booking');
    same(409, $refusal['status'], 'as a refusal to fix, not a failure to retry');
    same('BLOCKED', Db::scalar('SELECT status FROM purchase_bill_requests'), 'the bill stops for a person');
    same(1, count(booksVouchers()), 'Books still holds only Billing\'s voucher');
    same('0.0000', (string) Db::scalar('SELECT billed_qty FROM purchase_order_lines WHERE po_id = :id', ['id' => (int) $po['po_id']]), 'and the order is not billed twice');
});

check('two submissions of the same supplier invoice together make one bill (real race)', function () use ($ctx, $owner) {
    reset();
    $input = ['supplier_account_id' => 601, 'supplier_invoice_no' => 'DUP-9', 'supplier_invoice_date' => '2026-09-19', 'lines' => [['description' => 'Freight', 'is_service' => true, 'purchase_acc_id' => 7302, 'qty' => 1, 'rate' => 5000]]];
    $results = race([['enter_bill', ['input' => $input]], ['enter_bill', ['input' => $input]], ['enter_bill', ['input' => $input]]]);
    same(1, count(array_filter($results, static fn ($r) => $r['ok'])), 'one accepted');
    same(1, (int) Db::scalar("SELECT COUNT(*) FROM purchase_bill_requests WHERE supplier_invoice_no = 'DUP-9'"), 'one bill');
});

// ---------------------------------------------------------------------------
echo "\nHistorical repair\n";

check('the repair tool reports historical damage, plans for review, and applies only local bookkeeping', function () use ($ctx, $owner) {
    reset();
    $tool = static function (string $args): array {
        exec('php ' . escapeshellarg(__DIR__ . '/../bin/receipt-repair.php') . ' ' . $args . ' 2>&1', $out, $code);

        return [$code, implode("\n", $out)];
    };

    // Damage as the old code left it: two receipts of one order under the ORDER's identity.
    $legacy = orderOf($ctx, $owner);
    $legacyLine = (int) $legacy['lines'][0]['line_id'];
    foreach ([30, 20] as $n => $qty) {
        Db::insert('purchase_receipt_requests', [
            'cmp_id' => 88, 'fy_id' => 6, 'bo_id' => 0, 'po_id' => (int) $legacy['po_id'], 'receipt_no' => 'OLD/' . $n,
            'source_document_type' => 'purchases.order', 'status' => 'ACCEPTED', 'applied_at' => '2026-09-01 10:00:00',
            'requested_lines' => [['line_id' => $legacyLine, 'qty' => $qty]], 'requested_by' => 'user-owner',
        ], 'request_id');
    }
    Db::run('UPDATE purchase_order_lines SET received_qty = 50 WHERE line_id = :id', ['id' => $legacyLine]);

    // An order whose counter drifted from its own receipts.
    $drift = orderOf($ctx, $owner);
    receive($ctx, $owner, (int) $drift['po_id'], (int) $drift['lines'][0]['line_id'], 40);
    Db::run('UPDATE purchase_order_lines SET received_qty = 70 WHERE line_id = :id', ['id' => (int) $drift['lines'][0]['line_id']]);

    // A cancelled receipt whose failed command was never withdrawn.
    $orphan = IntegrationCommand::ensure($ctx, 'inventory', ReceiptService::COMMAND_RECEIPT, 'receipt_request', 999, ['x' => 1]);
    Db::insert('purchase_receipt_requests', ['request_id' => 999, 'cmp_id' => 88, 'fy_id' => 6, 'bo_id' => 0, 'po_id' => (int) $drift['po_id'], 'receipt_no' => 'X/1', 'status' => 'CANCELLED', 'requested_lines' => [], 'requested_by' => 'user-owner'], 'request_id');
    Db::run("UPDATE purchase_integration_commands SET status = 'FAILED' WHERE command_id = :id", ['id' => (int) $orphan['command_id']]);

    // Duplicate supplier invoices, entered before the index existed.
    Db::run('DROP INDEX uq_purchase_bills_supplier_invoice');
    foreach ([1, 2] as $n) {
        Db::insert('purchase_bill_requests', ['cmp_id' => 88, 'fy_id' => 6, 'bo_id' => 0, 'supplier_account_id' => 601, 'supplier_invoice_no' => 'INV-7', 'supplier_invoice_date' => '2026-09-01', 'status' => 'MATCHING', 'requested_lines' => [], 'requested_by' => 'user-owner', 'bill_kind' => 'direct'], 'request_id');
    }

    $before = Db::all('SELECT line_id, received_qty FROM purchase_order_lines ORDER BY line_id');
    [$code, $out] = $tool('--json');
    same(3, $code, 'findings are reported with a non-zero exit');
    $report = json_decode($out, true);
    same(1, count($report['legacy_identity']), 'the legacy order is found');
    same(1, count($report['counter_drift']), 'the drifted line is found (the legacy order adds up, so it is not)');
    same(1, count($report['orphan_commands']), 'the orphan command is found');
    same(1, count($report['duplicate_invoices']), 'the duplicate invoice is found');
    same($before, Db::all('SELECT line_id, received_qty FROM purchase_order_lines ORDER BY line_id'), 'reporting changed nothing');

    $planFile = sys_get_temp_dir() . '/repair-plan-test.json';
    $tool('--plan=' . escapeshellarg($planFile));
    $plan = json_decode((string) file_get_contents($planFile), true);
    same(['recount_order_line', 'withdraw_command', 'create_invoice_index'], array_column($plan['actions'], 'action'), 'three local actions proposed, none for the legacy order');

    [$code, $out] = $tool('--apply=' . escapeshellarg($planFile));
    same(1, $code, 'applying needs a named person and a reason');

    [, $out] = $tool('--apply=' . escapeshellarg($planFile) . ' --actor=ops-ravi --reason=' . escapeshellarg('Reviewed with the stores lead.'));
    truthy(str_contains($out, '2 applied, 1 skipped'), 'recount and withdraw applied, the index refused while duplicates remain: ' . $out);
    same('40.0000', (string) Db::scalar('SELECT received_qty FROM purchase_order_lines WHERE line_id = :id', ['id' => (int) $drift['lines'][0]['line_id']]), 'the counter matches its receipts');
    same('CANCELLED', Db::scalar('SELECT status FROM purchase_integration_commands WHERE command_id = :id', ['id' => (int) $orphan['command_id']]), 'the orphan is withdrawn');
    same(2, (int) Db::scalar("SELECT COUNT(*) FROM purchase_audit_log WHERE action LIKE 'repair.%' AND actor_uuid = 'operator:ops-ravi' AND reason = 'Reviewed with the stores lead.'"), 'each applied action audited against the person');
    same('50.0000', (string) Db::scalar('SELECT received_qty FROM purchase_order_lines WHERE line_id = :id', ['id' => $legacyLine]), 'the legacy order is left for a person');
    same(0, count(stubRequests('/v1/inventory-documents/post')) - 1, 'nothing was posted to Inventory by the tool (the one post is the drift order\'s own GRN)');

    [, $out] = $tool('--apply=' . escapeshellarg($planFile) . ' --actor=ops-ravi --reason=again');
    truthy(str_contains($out, '0 applied, 3 skipped'), 'the same plan applied twice changes nothing: ' . $out);

    Db::run("UPDATE purchase_bill_requests SET status = 'CANCELLED' WHERE request_id = (SELECT MAX(request_id) FROM purchase_bill_requests)");
    $tool('--plan=' . escapeshellarg($planFile));
    [, $out] = $tool('--apply=' . escapeshellarg($planFile) . ' --actor=ops-ravi --reason=' . escapeshellarg('Duplicate cancelled.'));
    truthy(str_contains($out, 'create_invoice_index') && str_contains($out, 'applied'), 'the index is created once the duplicate is resolved: ' . $out);
    truthy(Db::scalar("SELECT 1 FROM pg_indexes WHERE indexname = 'uq_purchase_bills_supplier_invoice'") !== null, 'and exists');
    @unlink($planFile);
});

echo "\n" . str_repeat('-', 60) . "\n";
echo "{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
