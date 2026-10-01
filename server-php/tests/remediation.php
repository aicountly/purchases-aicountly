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

use Aicountly\Api\Domain\BillService;
use Aicountly\Api\Domain\PoProgress;
use Aicountly\Api\Domain\PurchaseOrderService;
use Aicountly\Api\Domain\ReceiptService;
use Aicountly\Api\ResponseSent;

$passed = 0;
$failed = 0;

function check(string $name, callable $fn): void
{
    global $passed, $failed;
    Context::forgetVerified();
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

function stubFail(string $path, int $status, bool $after = false, string $code = 'stub_forced', string $message = ''): void
{
    file_put_contents(sys_get_temp_dir() . '/stub-control.json', json_encode(['path' => $path, 'status' => $status, 'after' => $after, 'code' => $code] + ($message === '' ? [] : ['message' => $message])));
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

check('each company chooses whether goods go into stock at the goods receipt; the bill settles either kind with from_challan', function () use ($ctx, $owner) {
    reset();
    $po = orderOf($ctx, $owner);
    $line = (int) $po['lines'][0]['line_id'];
    receive($ctx, $owner, (int) $po['po_id'], $line, 40);
    $grns = array_values(array_filter(stubRequests('/v1/inventory-documents/post'), static fn ($r) => ($r['body']['document_type'] ?? '') === 'INWARD_CHALLAN'));
    same('challan_only', end($grns)['body']['stock_effect'] ?? null, 'off by default: the receipt notes the goods, the bill receives them');

    Db::run('INSERT INTO purchase_settings (cmp_id, receive_stock_at_grn) VALUES (88, TRUE) ON CONFLICT (cmp_id) DO UPDATE SET receive_stock_at_grn = TRUE');
    receive($ctx, $owner, (int) $po['po_id'], $line, 30);
    $grns = array_values(array_filter(stubRequests('/v1/inventory-documents/post'), static fn ($r) => ($r['body']['document_type'] ?? '') === 'INWARD_CHALLAN'));
    same('physical', end($grns)['body']['stock_effect'] ?? null, 'on: the goods go into stock at the receipt');
    same(['challan_only', 'physical'], array_column(Db::all('SELECT stock_effect FROM purchase_receipt_requests ORDER BY request_id'), 'stock_effect'), 'each receipt keeps what it did');

    $bills = new BillService($ctx, $owner);
    $mixed = $bills->enter(['supplier_account_id' => 601, 'po_id' => (int) $po['po_id'], 'supplier_invoice_no' => 'MIX-1', 'supplier_invoice_date' => '2026-09-19', 'lines' => [['po_line_id' => $line, 'qty' => 70, 'rate' => 250]]]);
    $refusal = refused(fn () => $bills->post((int) $mixed['request_id']), '', 'a bill over both kinds');
    same(409, $refusal['status'], 'is refused');
    same(0, count(stubRequests('/vouchers/drafts')), 'before Books is asked');
    $bills->cancel((int) $mixed['request_id'], ['reason' => 'Split by receipt.']);

    $first = $bills->enter(['supplier_account_id' => 601, 'po_id' => (int) $po['po_id'], 'supplier_invoice_no' => 'MIX-2', 'supplier_invoice_date' => '2026-09-19', 'lines' => [['po_line_id' => $line, 'qty' => 40, 'rate' => 250]]]);
    same('POSTED', $bills->post((int) $first['request_id'])['status'], 'the challan-only receipt is billed on its own');
    $second = $bills->enter(['supplier_account_id' => 601, 'po_id' => (int) $po['po_id'], 'supplier_invoice_no' => 'MIX-3', 'supplier_invoice_date' => '2026-09-19', 'lines' => [['po_line_id' => $line, 'qty' => 30, 'rate' => 250]]]);
    same('POSTED', $bills->post((int) $second['request_id'])['status'], 'and the physical one on its own');
    $sent = array_values(array_filter(stubRequests('/vouchers/drafts'), static fn ($r) => $r['method'] === 'POST' && (int) ($r['body']['vch_type_id'] ?? 0) === 11));
    same(2, count($sent), 'two purchase vouchers');
    same(['from_challan', 'from_challan'], array_map(static fn ($r) => $r['body']['payload']['stock_effect'] ?? null, $sent), 'Books is sent from_challan either way; Inventory decides from the receipts whether goods still move');
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
echo "\nReturns and claims: one movement, settled only by what happened\n";

/** An order received and billed in full, for returns to go back against. */
function billedOrder(Context $ctx, Auth $auth, float $billQty = 100): array
{
    $po = orderOf($ctx, $auth);
    receive($ctx, $auth, (int) $po['po_id'], (int) $po['lines'][0]['line_id'], 100);
    $bills = new BillService($ctx, $auth);
    $bill = $bills->enter(['supplier_account_id' => 601, 'po_id' => (int) $po['po_id'], 'supplier_invoice_no' => 'RB-' . $po['po_id'], 'supplier_invoice_date' => '2026-09-19', 'lines' => [['po_line_id' => (int) $po['lines'][0]['line_id'], 'qty' => $billQty, 'rate' => 250]]]);
    $bills->post((int) $bill['request_id']);

    return (new PurchaseOrderService($ctx, $auth))->find((int) $po['po_id']);
}

function returnOf(Context $ctx, Auth $auth, array $po, float $qty, array $extra = []): array
{
    return (new Domain\ReturnClaimService($ctx, $auth))->createReturn($extra + [
        'supplier_account_id' => 601, 'po_id' => (int) $po['po_id'], 'reason_code' => 'quality',
        'lines' => [['po_line_id' => (int) $po['lines'][0]['line_id'], 'return_qty' => $qty]],
    ]);
}

check('a physical return\'s debit note waits for Inventory to confirm the dispatch, and the goods leave once', function () use ($ctx, $owner) {
    reset();
    $po = billedOrder($ctx, $owner);
    $returns = new Domain\ReturnClaimService($ctx, $owner);
    $return = returnOf($ctx, $owner, $po, 10);
    $id = (int) $return['return_id'];
    same(250.0, (float) $return['lines'][0]['rate'], 'priced from the order when no rate is given');
    $returns->approveReturn($id, []);

    refused(fn () => $returns->requestDebitNote($id), 'Send the goods back first', 'debit note before the dispatch');
    stubFail('inventory-documents/post', 503);
    refused(fn () => $returns->dispatchReturn($id), 'press Retry', 'Inventory down');
    stubRecover();
    refused(fn () => $returns->requestDebitNote($id), 'Send the goods back first', 'debit note after a failed dispatch');

    $dispatched = $returns->dispatchReturn($id);
    same('DISPATCHED', $dispatched['status'], 'dispatched once Inventory confirmed it');
    $challan = inventoryState()['by_id'][(string) $dispatched['inventory_document_id']] ?? null;
    same('DELIVERY_CHALLAN', $challan['document_type'] ?? null, 'recorded as a delivery challan');
    same('challan_only', $challan['stock_effect'] ?? null, 'which moves nothing on its own');

    $debited = $returns->requestDebitNote($id);
    same('DEBITED', $debited['status'], 'debited');
    $notes = array_values(array_filter(booksVouchers(), static fn ($v) => (int) $v['vch_type_id'] === 3));
    same(1, count($notes), 'one debit note');
    same('from_challan', $notes[0]['stock']['stock_effect'] ?? null, 'which settles the dispatch challan');
    same((int) $dispatched['inventory_document_id'], (int) $notes[0]['challan_settlements'][0]['source_document_id'], 'this return\'s challan');
    $purchasePosts = array_filter(stubRequests('/v1/inventory-documents/post'), static fn ($r) => ($r['body']['document_type'] ?? '') === 'PURCHASE_RETURN');
    same(0, count($purchasePosts), 'Purchase never issues the goods itself: the debit note is the one movement');

    same('DEBITED', $returns->requestDebitNote($id)['status'], 'asking again changes nothing');
    same(1, count(array_filter(booksVouchers(), static fn ($v) => (int) $v['vch_type_id'] === 3)), 'still one debit note');
});

check('what can go back is what was received and billed, less every other return', function () use ($ctx, $owner) {
    reset();
    $po = billedOrder($ctx, $owner, 60);

    refused(fn () => returnOf($ctx, $owner, $po, 70), 'has not been billed yet', 'more than was billed');
    returnOf($ctx, $owner, $po, 40);
    refused(fn () => returnOf($ctx, $owner, $po, 30), 'already on another return', 'the pending return counts');
    returnOf($ctx, $owner, $po, 20);
});

check('two returns raised together cannot send back more than was billed (real race)', function () use ($ctx, $owner) {
    reset();
    $po = billedOrder($ctx, $owner, 60);
    $input = ['supplier_account_id' => 601, 'po_id' => (int) $po['po_id'], 'lines' => [['po_line_id' => (int) $po['lines'][0]['line_id'], 'return_qty' => 40]]];
    $results = race([['create_return', ['input' => $input]], ['create_return', ['input' => $input]], ['create_return', ['input' => $input]]]);
    same(1, count(array_filter($results, static fn ($r) => $r['ok'])), 'one return of 40 fits 60 billed: ' . json_encode(array_map(static fn ($r) => [$r['status'], $r['message'] ?? null], $results)));
    same(40.0, (float) Db::scalar("SELECT COALESCE(SUM(return_qty), 0) FROM purchase_return_lines"), 'and only it was recorded');
});

check('a financial return needs its own authority, a reason and a ledger, and moves no stock', function () use ($ctx, $owner) {
    reset();
    $po = billedOrder($ctx, $owner);
    $clerk = person('user-clerk', 0);
    Db::insert('purchase_permission_profiles', ['cmp_id' => 88, 'profile_name' => 'Returns desk', 'permissions' => ['return.create', 'return.approve', 'po.view'], 'is_active' => true], 'profile_id');
    Db::run("INSERT INTO purchase_permission_assignments (cmp_id, user_uuid, profile_id) SELECT 88, 'user-clerk', profile_id FROM purchase_permission_profiles WHERE profile_name = 'Returns desk'");
    $input = ['supplier_account_id' => 601, 'po_id' => (int) $po['po_id'], 'return_kind' => 'financial', 'adjustment_acc_id' => 7310, 'adjustment_reason' => 'Rate agreed down after quality review.', 'lines' => [['description' => 'Rate difference', 'return_qty' => 1, 'rate' => 1500]]];

    refused(fn () => (new Domain\ReturnClaimService($ctx, $clerk))->createReturn($input), 'permission', 'without return.financial_adjustment');
    refused(fn () => (new Domain\ReturnClaimService($ctx, $owner))->createReturn(['adjustment_reason' => null] + $input), 'Say why', 'without a reason');
    refused(fn () => (new Domain\ReturnClaimService($ctx, $owner))->createReturn(['adjustment_acc_id' => null] + $input), 'ledger', 'without a ledger');

    $returns = new Domain\ReturnClaimService($ctx, $owner);
    $return = $returns->createReturn($input);
    refused(fn () => $returns->dispatchReturn((int) $return['return_id']), 'Nothing goes back', 'a financial return has nothing to dispatch');
    $returns->approveReturn((int) $return['return_id'], []);
    $debited = $returns->requestDebitNote((int) $return['return_id']);
    same('DEBITED', $debited['status'], 'debited');
    $note = array_values(array_filter(booksVouchers(), static fn ($v) => (int) $v['vch_type_id'] === 3))[0];
    same(null, $note['stock'], 'no stock in the debit note');
    same(0, count(array_filter(stubRequests('/v1/inventory-documents/post'), static fn ($r) => ($r['body']['source_document_type'] ?? '') === 'purchases.return')), 'and nothing sent to Inventory');
    same('0.0000', (string) Db::scalar('SELECT debited_qty FROM purchase_order_lines WHERE po_id = :id', ['id' => (int) $po['po_id']]), 'the order\'s quantities are untouched by money-only adjustments');
});

check('a dispatched return can be recalled: Inventory reverses the challan and the order counts the goods again', function () use ($ctx, $owner) {
    reset();
    $po = billedOrder($ctx, $owner);
    $returns = new Domain\ReturnClaimService($ctx, $owner);
    $id = (int) returnOf($ctx, $owner, $po, 10)['return_id'];
    $returns->approveReturn($id, []);
    $dispatched = $returns->dispatchReturn($id);
    same('10.0000', (string) Db::scalar('SELECT returned_qty FROM purchase_order_lines WHERE po_id = :id', ['id' => (int) $po['po_id']]), 'counted as gone');

    refused(fn () => $returns->recallReturn($id, []), 'Say why', 'a recall needs a reason');
    $recalled = $returns->recallReturn($id, ['reason' => 'Supplier refused the consignment at their gate.']);
    same('RECALLED', $recalled['status'], 'recalled');
    same('REVERSED', inventoryState()['by_id'][(string) $dispatched['inventory_document_id']]['status'] ?? null, 'the challan is reversed in Inventory');
    same('0.0000', (string) Db::scalar('SELECT returned_qty FROM purchase_order_lines WHERE po_id = :id', ['id' => (int) $po['po_id']]), 'the goods count as received again');
    refused(fn () => $returns->requestDebitNote($id), 'recalled', 'no debit note for goods that came back');
});

check('goods sent back for good close the order; goods to be replaced keep it waiting', function () use ($ctx, $owner) {
    reset();
    $orders = new PurchaseOrderService($ctx, $owner);
    $returns = new Domain\ReturnClaimService($ctx, $owner);

    $po = billedOrder($ctx, $owner);
    same('CLOSED', $po['status'], 'received and billed in full');
    $id = (int) returnOf($ctx, $owner, $po, 10)['return_id'];
    $returns->approveReturn($id, []);
    $returns->dispatchReturn($id);
    same('RECEIVED', $orders->find((int) $po['po_id'])['status'], 'an open return keeps the order from closing');
    $returns->requestDebitNote($id);
    $after = $orders->find((int) $po['po_id']);
    same('CLOSED', $after['status'], 'no replacement coming: closed once the debit note posts');
    same('10.0000', (string) $after['lines'][0]['short_closed_qty'], 'the returned quantity is no longer awaited');

    reset();
    $po = billedOrder($ctx, $owner);
    $id = (int) returnOf($ctx, $owner, $po, 10, ['expect_replacement' => true])['return_id'];
    $returns->approveReturn($id, []);
    $returns->dispatchReturn($id);
    $returns->requestDebitNote($id);
    $waiting = $orders->find((int) $po['po_id']);
    same('PARTIALLY_RECEIVED', $waiting['status'], 'a replacement is coming: the order waits for it');
    same(10.0, $waiting['progress']['lines'][0]['to_receive_qty'], 'for the 10 sent back');
});

/** A claim approved for an amount, ready for resolutions. */
function approvedClaim(Context $ctx, Auth $auth, float $amount, ?int $poId = null): int
{
    $claims = new Domain\ReturnClaimService($ctx, $auth);
    $claim = $claims->createClaim(['supplier_account_id' => 601, 'claim_kind' => 'shortage', 'claimed_amount' => $amount, 'po_id' => $poId]);
    $claims->updateClaim((int) $claim['claim_id'], 'submit', []);
    $claims->updateClaim((int) $claim['claim_id'], 'approve', []);

    return (int) $claim['claim_id'];
}

check('a claim settles only when its debit note is in Books, partly along the way, and a failure settles nothing', function () use ($ctx, $owner) {
    reset();
    $claimId = approvedClaim($ctx, $owner, 5000);
    $resolutions = new Domain\ClaimResolutionService($ctx, $owner);
    $claims = new Domain\ReturnClaimService($ctx, $owner);

    $r = $resolutions->propose($claimId, ['kind' => 'financial_adjustment', 'amount' => 3000, 'adjustment_acc_id' => 7310]);
    stubFail('/vouchers/drafts', 503);
    refused(fn () => $resolutions->approve((int) $r['resolution_id']), 'press Retry', 'Books down');
    stubRecover();
    same('FAILED', $resolutions->find((int) $r['resolution_id'])['status'], 'the resolution says it failed');
    same('APPROVED', $claims->findClaim($claimId)['status'], 'and the claim is not settled by hope');

    $resolutions->approve((int) $r['resolution_id']);
    $claim = $claims->findClaim($claimId);
    same('PARTIALLY_SETTLED', $claim['status'], '3000 of 5000');
    same('3000.0000', (string) $claim['settled_amount'], 'counted');
    same(1, count(booksVouchers()), 'one debit note for the retried resolution');

    $n = $resolutions->propose($claimId, ['kind' => 'non_financial', 'amount' => 2000, 'note' => 'Supplier will credit the balance against the October order.']);
    $resolutions->approve((int) $n['resolution_id']);
    same('SETTLED', $claims->findClaim($claimId)['status'], 'settled when the resolutions cover the approved amount');
});

check('a refund completes only against a Books receipt that shows the money came from this supplier', function () use ($ctx, $owner) {
    reset();
    file_put_contents(sys_get_temp_dir() . '/stub-vouchers.json', json_encode([
        '5001' => ['vch_txn_id' => 5001, 'vch_type_id' => 3, 'vch_number' => 'DN/9', 'lines' => [['acc_id' => 601, 'dr_cr' => 1, 'amount' => 1000]]],
        '5002' => ['vch_txn_id' => 5002, 'vch_type_id' => 13, 'vch_number' => 'RCT/7', 'lines' => [['acc_id' => 612, 'dr_cr' => 2, 'amount' => 1000], ['acc_id' => 1101, 'dr_cr' => 1, 'amount' => 1000]]],
        '5003' => ['vch_txn_id' => 5003, 'vch_type_id' => 13, 'vch_number' => 'RCT/8', 'vch_date' => '2026-09-26', 'lines' => [['acc_id' => 601, 'dr_cr' => 2, 'amount' => 1000], ['acc_id' => 1101, 'dr_cr' => 1, 'amount' => 1000]]],
    ]));
    $claimId = approvedClaim($ctx, $owner, 1000);
    $resolutions = new Domain\ClaimResolutionService($ctx, $owner);
    $r = $resolutions->propose($claimId, ['kind' => 'refund', 'amount' => 1000]);
    $resolutions->approve((int) $r['resolution_id']);
    same('IN_PROGRESS', $resolutions->find((int) $r['resolution_id'])['status'], 'waits for the money');

    refused(fn () => $resolutions->link((int) $r['resolution_id'], ['books_voucher_id' => 5001]), 'not a receipt', 'a debit note is not money received');
    refused(fn () => $resolutions->link((int) $r['resolution_id'], ['books_voucher_id' => 5002]), 'does not credit this supplier', 'someone else\'s receipt');
    $resolutions->link((int) $r['resolution_id'], ['books_voucher_id' => 5003]);
    same('SETTLED', (new Domain\ReturnClaimService($ctx, $owner))->findClaim($claimId)['status'], 'settled by the verified receipt');
});

check('a replacement completes on a recorded GRN of the claim\'s order, once', function () use ($ctx, $owner) {
    reset();
    $po = orderOf($ctx, $owner);
    $claimId = approvedClaim($ctx, $owner, 2500, (int) $po['po_id']);
    $resolutions = new Domain\ClaimResolutionService($ctx, $owner);
    $r = $resolutions->propose($claimId, ['kind' => 'replacement', 'amount' => 2500]);
    $resolutions->approve((int) $r['resolution_id']);

    stubFail('inventory-documents/post', 503);
    refused(fn () => receive($ctx, $owner, (int) $po['po_id'], (int) $po['lines'][0]['line_id'], 10), 'press Retry', 'a GRN Inventory has not recorded');
    stubRecover();
    $pending = (int) Db::scalar('SELECT request_id FROM purchase_receipt_requests ORDER BY request_id DESC LIMIT 1');
    refused(fn () => $resolutions->link((int) $r['resolution_id'], ['receipt_request_id' => $pending]), 'not been recorded', 'an unapplied GRN is not a replacement');
    (new ReceiptService($ctx, $owner))->retry($pending);
    $resolutions->link((int) $r['resolution_id'], ['receipt_request_id' => $pending]);
    same('SETTLED', (new Domain\ReturnClaimService($ctx, $owner))->findClaim($claimId)['status'], 'settled by the replacement');
});

check('a claim settled by sending goods back completes when that return\'s debit note posts', function () use ($ctx, $owner) {
    reset();
    $po = billedOrder($ctx, $owner);
    $claimId = approvedClaim($ctx, $owner, 2500, (int) $po['po_id']);
    $resolutions = new Domain\ClaimResolutionService($ctx, $owner);
    $r = $resolutions->propose($claimId, ['kind' => 'physical_return', 'amount' => 2500, 'lines' => [['po_line_id' => (int) $po['lines'][0]['line_id'], 'return_qty' => 10]]]);
    $resolutions->approve((int) $r['resolution_id']);
    $returnId = (int) $resolutions->find((int) $r['resolution_id'])['reference']['return_id'];
    same('APPROVED', (new Domain\ReturnClaimService($ctx, $owner))->findClaim($claimId)['status'], 'nothing settled while the goods are still here');

    $returns = new Domain\ReturnClaimService($ctx, $owner);
    $returns->approveReturn($returnId, []);
    $returns->dispatchReturn($returnId);
    same('APPROVED', $returns->findClaim($claimId)['status'], 'nor while they are on their way back');
    $returns->requestDebitNote($returnId);
    same('SETTLED', $returns->findClaim($claimId)['status'], 'settled by the return\'s debit note');
});

// ---------------------------------------------------------------------------
echo "\nCompany, year and branch are Manage's answer, not the request's claim\n";

check('a year or branch that is not the company\'s is refused, from the same Manage answer', function () {
    $owner = person();
    $bad = refused(fn () => Context::of(88, 99, 0)->assertAllowed($owner), 'fy_not_in_company', 'a year Manage does not list');
    same(403, $bad['status'], 'refused, not served empty');
    Context::forgetVerified();
    refused(fn () => Context::of(88, 6, 77)->assertAllowed($owner), 'branch_not_in_company', 'a branch Manage does not list');
    Context::forgetVerified();
    $ok = Context::of(88, 6, 30);
    $ok->assertAllowed($owner);
    same(['from' => '2026-04-01', 'to' => '2027-03-31'], $ok->fyRange(), 'and the year\'s dates are known for the checks that need them');
});

check('an invoice dated last year is booked in this one with its own posting date, keeping the supplier\'s date', function () {
    reset();
    $owner = person();
    $ctx = Context::of(88, 6, 0);
    $ctx->assertAllowed($owner);
    $bills = new BillService($ctx, $owner);
    $input = ['supplier_account_id' => 601, 'supplier_invoice_no' => 'MAR-31', 'supplier_invoice_date' => '2026-03-31', 'lines' => [['description' => 'Annual maintenance', 'is_service' => true, 'purchase_acc_id' => 7302, 'qty' => 1, 'rate' => 12000]]];

    refused(fn () => $bills->enter($input), 'outside the financial year', 'booked on last year\'s date');
    $bill = $bills->enter($input + ['posting_date' => '2026-04-03']);
    $bills->resolveException((int) $bill['matches'][0]['exceptions'][0]['exception_id'], 'accept', ['note' => 'AMC, reviewed.']);
    $bills->post((int) $bill['request_id']);

    $voucher = array_values(booksVouchers())[0];
    same('2026-04-03', $voucher['vch_date'], 'booked on the posting date');
    same('2026-03-31', $voucher['bill']['bill_date'], 'the supplier\'s invoice date travels as the bill\'s date');
});

// ---------------------------------------------------------------------------
echo "\nSuppliers, approvals and the order's document\n";

/** Call a controller as a person, through the real company check; returns [status, payload]. */
function endpoint(callable $action, Auth $auth, array $query = ['cmp_id' => '88', 'fy_id' => '6', 'bo_id' => '0']): array
{
    $_GET = $query;
    Auth::adopt($auth);
    Context::forgetVerified();
    try {
        $action();
    } catch (ResponseSent $sent) {
        return [$sent->status, $sent->payload];
    } finally {
        Auth::adopt(null);
    }
    throw new \RuntimeException('the endpoint returned without responding');
}

function profile(string $uuid, string $name, array $permissions): void
{
    $id = (int) Db::insert('purchase_permission_profiles', ['cmp_id' => 88, 'profile_name' => $name, 'permissions' => $permissions, 'is_active' => true], 'profile_id');
    Db::run('INSERT INTO purchase_permission_assignments (cmp_id, user_uuid, profile_id) VALUES (88, :u, :p)', ['u' => $uuid, 'p' => $id]);
}

check('a supplier\'s contact is the company contact Contacts links to its ledger, and linking it is idempotent', function () use ($ctx, $owner) {
    reset();
    $contacts = new Domain\SupplierContactService($ctx, $owner);
    same(false, $contacts->contactFor(601)['linked'], 'no link yet');
    $candidates = $contacts->candidates('deccan');
    same(1, count($candidates), 'company contacts are searched');

    $linked = $contacts->link(601, ['contact_id' => $candidates[0]['id']]);
    same(true, $linked['linked'], 'linked in Contacts');
    same(['orders@deccansteel.example'], $linked['contact']['emails'], 'read live from Contacts');
    $contacts->link(601, ['contact_id' => $candidates[0]['id']]);
    $keys = array_values(array_unique(array_map(static fn ($r) => $r['headers']['idempotency-key'] ?? '', array_filter(stubRequests('/references'), static fn ($r) => $r['method'] === 'POST'))));
    same(1, count($keys), 'the same link asked twice carries one key');

    $other = $contacts->candidates('konkan')[0]['id'];
    refused(fn () => $contacts->link(601, ['contact_id' => $other]), 'supplier_contact_conflict', 'Contacts already links this ledger elsewhere');
    same(0, count(stubRequests('/api/contacts')), 'a user\'s personal contacts were never asked for');

    same(0, (int) Db::scalar("SELECT COUNT(*) FROM information_schema.columns WHERE table_name = 'purchase_supplier_profiles' AND column_name IN ('email', 'phone', 'contact_name')"), 'and nothing of the contact is copied here');
});

check('a Contacts without company contacts is reported, never replaced by personal ones', function () use ($ctx, $owner) {
    reset();
    stubMode(['contacts_undeployed' => true]);
    $refusal = refused(fn () => (new Domain\SupplierContactService($ctx, $owner))->contactFor(601), 'contacts_unavailable', 'undeployed Contacts');
    stubMode([]);
    same(503, $refusal['status'], 'unavailable, retryable');
    same(0, count(stubRequests('/api/contacts')), 'no personal fallback');
});

check('the approvals inbox shows only what the caller approves, and values only to those who may see the document', function () {
    reset();
    foreach ([['purchase_order', 1, 6], ['requisition', 2, 6], ['purchase_order', 3, 5]] as [$type, $id, $fy]) {
        Db::insert('purchase_approval_requests', ['cmp_id' => 88, 'fy_id' => $fy, 'entity_type' => $type, 'entity_id' => $id, 'reason_kind' => 'value', 'status' => 'PENDING', 'actual_value' => 500000, 'requested_by' => 'user-buyer'], 'approval_id');
    }
    profile('user-viewer', 'Viewer', ['po.view']);
    profile('user-approver', 'Approver', ['po.approve']);

    [$status] = endpoint([Controllers\DashboardController::class, 'approvals'], person('user-viewer', 0));
    same(403, $status, 'a viewer approves nothing');

    [$status, $payload] = endpoint([Controllers\DashboardController::class, 'approvals'], person('user-approver', 0));
    same(200, $status, 'an approver sees the inbox');
    same(1, count($payload['data']), 'only purchase orders, only this year');
    same(null, $payload['data'][0]['actual_value'], 'without po.view the value is withheld');

    [, $payload] = endpoint([Controllers\DashboardController::class, 'approvals'], person());
    same(2, count($payload['data']), 'the owner sees both kinds for this year');
    truthy($payload['data'][0]['actual_value'] !== null, 'with values');
});

check('the first dashboard, which checked no permission, is retired with a pointer', function () {
    [$status, $payload] = endpoint([Controllers\DashboardController::class, 'index'], person());
    same(410, $status, 'gone');
    same('v1/dashboards/overview', $payload['error']['details']['use'] ?? null, 'and says where to go');
});

check('an order is prepared, sent and acknowledged as three facts, each with its evidence', function () use ($ctx, $owner) {
    reset();
    $orders = new PurchaseOrderService($ctx, $owner);
    $po = orderOf($ctx, $owner);

    $doc = $orders->document((int) $po['po_id']);
    truthy(str_starts_with($doc['pdf'], '%PDF'), 'a PDF');
    truthy(str_contains($doc['pdf'], (string) $po['po_no']), 'naming the order');
    truthy(str_contains($doc['pdf'], 'Stub Item 201'), 'with the item\'s name from Inventory, read now');
    $orders->document((int) $po['po_id']);
    same(1, (int) Db::scalar("SELECT COUNT(*) FROM purchase_po_communications WHERE kind = 'prepared'"), 'the same document prepared twice is one fact');

    refused(fn () => $orders->acknowledge((int) $po['po_id'], ['source' => 'supplier_email', 'evidence' => 'Re: PO']), 'sent', 'acknowledged before it was sent');
    refused(fn () => $orders->markSent((int) $po['po_id'], ['channel' => 'email']), 'who at the supplier', 'sent to nobody');
    $orders->markSent((int) $po['po_id'], ['channel' => 'email', 'recipient' => 'orders@deccansteel.example', 'reference' => 'Sent from the buyer\'s mailbox']);
    refused(fn () => $orders->acknowledge((int) $po['po_id'], ['source' => 'supplier_email']), 'evidence', 'an acknowledgement without evidence');
    $acked = $orders->acknowledge((int) $po['po_id'], ['source' => 'supplier_email', 'evidence' => 'Their reply "Confirmed, dispatch 25 Sep", 19 Sep 10:42', 'promised_date' => '2026-09-25']);

    same('ACKNOWLEDGED', $acked['status'], 'acknowledged');
    same('supplier_email', $acked['acknowledgement_source'], 'with its source');
    same(['prepared', 'sent', 'acknowledged'], array_column($acked['communications'], 'kind'), 'three facts, in order');
    same($doc['fingerprint'], $acked['communications'][1]['document_fingerprint'], 'the sent record names the version that went');
});

// ---------------------------------------------------------------------------
echo "\nProcurement decisions: segregation of duties, requisitions, awards\n";

check('segregation of duties is the company\'s stated policy: the owner\'s own approval needs a reason, and strict allows none', function () use ($ctx, $owner) {
    reset();
    Db::run('INSERT INTO purchase_settings (cmp_id, po_approval_above_amount) VALUES (88, 1000) ON CONFLICT (cmp_id) DO UPDATE SET po_approval_above_amount = 1000');
    $orders = new PurchaseOrderService($ctx, $owner);
    $po = $orders->create(['supplier_account_id' => 601, 'po_date' => '2026-09-01', 'lines' => [['item_id' => 201, 'ordered_qty' => 100, 'agreed_rate' => 250]]]);
    $orders->submit((int) $po['po_id']);

    refused(fn () => $orders->decide((int) $po['po_id'], 'approve', []), 'sod_reason_required', 'the owner, silently');
    $orders->decide((int) $po['po_id'], 'approve', ['note' => 'Urgent: plant shutdown on Monday; CFO on leave.']);
    same('APPROVED', Db::scalar('SELECT status FROM purchase_orders WHERE po_id = :id', ['id' => (int) $po['po_id']]), 'approved with a reason');
    same(1, (int) Db::scalar("SELECT COUNT(*) FROM purchase_audit_log WHERE action = 'sod.owner_exception' AND reason LIKE 'Urgent%'"), 'recorded as an exception, with it');

    Db::run("UPDATE purchase_settings SET sod_policy = 'strict' WHERE cmp_id = 88");
    $po2 = $orders->create(['supplier_account_id' => 601, 'po_date' => '2026-09-01', 'lines' => [['item_id' => 201, 'ordered_qty' => 100, 'agreed_rate' => 250]]]);
    $orders->submit((int) $po2['po_id']);
    refused(fn () => $orders->decide((int) $po2['po_id'], 'approve', ['note' => 'Still me.']), 'somebody else', 'strict: not even the owner');
});

check('an RFQ or order may only refer to an approved requisition, and may not order more than it asked for', function () use ($ctx, $owner) {
    reset();
    $requisitions = new \Aicountly\Api\Domain\RequisitionService($ctx, $owner);
    $draft = $requisitions->create(['lines' => [['item_id' => 201, 'required_qty' => 50, 'estimated_rate' => 240]]]);
    refused(fn () => (new \Aicountly\Api\Domain\SourcingService($ctx, $owner))->createRfq(['title' => 'x', 'requisition_id' => (int) $draft['requisition_id'], 'lines' => [['item_id' => 201, 'required_qty' => 50]]]), 'Only an approved requisition', 'sourcing a draft');
    same('DRAFT', Db::scalar('SELECT status FROM purchase_requisitions WHERE requisition_id = :id', ['id' => (int) $draft['requisition_id']]), 'and it is not flipped to sourcing');

    $requisitions->submit((int) $draft['requisition_id']);
    $lineId = (int) Db::scalar('SELECT line_id FROM purchase_requisition_lines WHERE requisition_id = :id', ['id' => (int) $draft['requisition_id']]);
    $orders = new PurchaseOrderService($ctx, $owner);
    refused(fn () => $orders->create(['supplier_account_id' => 601, 'lines' => [['item_id' => 201, 'requisition_line_id' => $lineId, 'ordered_qty' => 60, 'agreed_rate' => 240]]]), 'asked for 50', 'ordering more than was asked for');
    $orders->create(['supplier_account_id' => 601, 'lines' => [['item_id' => 201, 'requisition_line_id' => $lineId, 'ordered_qty' => 50, 'agreed_rate' => 240]]]);
    refused(fn () => $orders->create(['supplier_account_id' => 601, 'lines' => [['item_id' => 201, 'requisition_line_id' => $lineId, 'ordered_qty' => 1, 'agreed_rate' => 240]]]), 'already ordered', 'and nothing after it is fully ordered');
});

/** An RFQ for two items, quoted by two suppliers, line 1 awarded to 601 and line 2 to 602. */
function awardedRfq(Context $ctx, Auth $auth): array
{
    $sourcing = new \Aicountly\Api\Domain\SourcingService($ctx, $auth);
    $rfq = $sourcing->createRfq(['title' => 'Q4 steel', 'delivery_warehouse_id' => 3, 'commercial_terms' => 'Delivered, unloaded.', 'supplier_account_ids' => [601, 602], 'lines' => [['item_id' => 201, 'required_qty' => 100], ['item_id' => 202, 'required_qty' => 40]]]);
    $rfqId = (int) $rfq['rfq_id'];
    [$l1, $l2] = [(int) $rfq['lines'][0]['line_id'], (int) $rfq['lines'][1]['line_id']];
    $sourcing->issueRfq($rfqId);
    $sourcing->recordQuote($rfqId, ['supplier_account_id' => 601, 'quote_ref' => 'DS-Q-17', 'payment_terms' => '30 days', 'freight_amount' => 1200, 'delivery_days' => 7, 'lines' => [['rfq_line_id' => $l1, 'item_id' => 201, 'quoted_qty' => 100, 'quoted_rate' => 245, 'discount_pc' => 2], ['rfq_line_id' => $l2, 'item_id' => 202, 'quoted_qty' => 40, 'quoted_rate' => 900]]]);
    $sourcing->recordQuote($rfqId, ['supplier_account_id' => 602, 'quote_ref' => 'KM-88', 'payment_terms' => '45 days', 'lines' => [['rfq_line_id' => $l1, 'item_id' => 201, 'quoted_qty' => 100, 'quoted_rate' => 260], ['rfq_line_id' => $l2, 'item_id' => 202, 'quoted_qty' => 40, 'quoted_rate' => 860]]]);
    // recordQuote answers with the RFQ; the quotes are found by supplier.
    $quoteOf = static fn (int $supplier) => (int) Db::scalar('SELECT quote_id FROM purchase_quotes WHERE rfq_id = :r AND supplier_account_id = :s', ['r' => $rfqId, 's' => $supplier]);
    $sourcing->award($rfqId, ['awards' => [
        ['rfq_line_id' => $l1, 'quote_id' => $quoteOf(601), 'qty' => 100, 'rate' => 242, 'rationale' => 'Negotiated down'],
        ['rfq_line_id' => $l2, 'quote_id' => $quoteOf(602), 'qty' => 40, 'rate' => 0, 'rationale' => 'Cheapest'],
    ]]);

    return ['rfq_id' => $rfqId];
}

check('an award becomes draft orders carrying the supplier, lines, prices and terms — once', function () use ($ctx, $owner) {
    reset();
    $rfq = awardedRfq($ctx, $owner);
    $sourcing = new \Aicountly\Api\Domain\SourcingService($ctx, $owner);
    $orders = $sourcing->convertAward($rfq['rfq_id']);

    same(2, count($orders), 'one order per winning supplier');
    $bySupplier = array_column($orders, null, 'supplier_account_id');
    $a = $bySupplier[601];
    same('DRAFT', $a['status'], 'a draft, to go through the approval rules');
    same(1, count($a['lines']), 'only the line awarded to it');
    same('242.0000', (string) $a['lines'][0]['agreed_rate'], 'at the awarded rate');
    same('2.000', (string) $a['lines'][0]['discount_pc'], 'with the quoted discount');
    same('30 days', $a['payment_terms'], 'on the quoted terms');
    same('1200.0000', (string) $a['freight_amount'], 'and freight');
    same('860.0000', (string) $bySupplier[602]['lines'][0]['agreed_rate'], 'an award with no rate takes the quoted one');

    refused(fn () => $sourcing->convertAward($rfq['rfq_id']), 'already become purchase order', 'converting it again');
    (new PurchaseOrderService($ctx, $owner))->cancel((int) $a['po_id'], ['reason' => 'Raised against the wrong plant.']);
    same(1, count($sourcing->convertAward($rfq['rfq_id'], ['quote_id' => (int) $a['quote_id']])), 'a cancelled order frees its award');
});

check('two people converting the same award at once raise one set of orders (real race)', function () use ($ctx, $owner) {
    reset();
    $rfq = awardedRfq($ctx, $owner);
    $results = race([['convert_award', ['rfq_id' => $rfq['rfq_id']]], ['convert_award', ['rfq_id' => $rfq['rfq_id']]], ['convert_award', ['rfq_id' => $rfq['rfq_id']]]]);
    same(1, count(array_filter($results, static fn ($r) => $r['ok'])), 'one conversion: ' . json_encode(array_map(static fn ($r) => [$r['status'], $r['message'] ?? null], $results)));
    same(2, (int) Db::scalar('SELECT COUNT(*) FROM purchase_orders WHERE rfq_id = :id', ['id' => $rfq['rfq_id']]), 'two orders, not six');
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

check('a service bill books only to Books\' purchase and expense ledgers, and only for someone who enters bills', function () {
    reset();
    profile('user-viewer', 'Viewer', ['po.view']);
    [$status] = endpoint([Controllers\CatalogController::class, 'ledgers'], person('user-viewer', 0));
    same(403, $status, 'a viewer cannot browse ledgers to book to');

    [$status, $payload] = endpoint([Controllers\CatalogController::class, 'ledgers'], person());
    same(200, $status, 'someone who enters bills can');
    same([7101, 7201, 7301], array_column($payload['data'], 'acc_id'), 'purchase, direct and indirect expense ledgers only — no creditor, no sales ledger');
    $asked = stubRequests('/masters/accounts');
    same('PURCHASE_ACCOUNTS,DIRECT_EXPENSES,INDIRECT_EXPENSES', end($asked)['query']['anchor_code'] ?? null, 'Books is asked for exactly those groups');
    same('1', end($asked)['query']['active_only'] ?? null, 'and only live ledgers');
});

// ---------------------------------------------------------------------------
echo "\nConnect: sharing a document never opens it to anyone\n";

check('Connect may attach an order only for someone who can open it, and learns who else could', function () use ($ctx, $owner) {
    reset();
    $po = orderOf($ctx, $owner);
    profile('user-viewer', 'Viewer', ['po.view']);
    profile('user-clerk', 'Clerk', ['requisition.view']);
    $share = static fn (Auth $who, array $body) => (new Domain\ConnectShareService($ctx, $who))->shareCheck($body);

    $answer = $share(person('user-clerk', 0), ['entity_type' => 'purchase_order', 'entity_id' => $po['po_id'], 'recipient_uuids' => ['user-viewer']]);
    same(false, $answer['allowed'], 'someone who cannot open the order cannot share it');
    same(null, $answer['label'], 'and is told nothing about it');

    $answer = $share(person('user-viewer', 0), ['entity_type' => 'purchase_order', 'entity_id' => (string) $po['po_id'], 'recipient_uuids' => ['USER-CLERK', 'user-viewer', 'user-nobody']]);
    same(true, $answer['allowed'], 'a viewer may share it');
    same('Purchase order ' . $po['po_no'], $answer['label'], 'the label is its kind and number');
    truthy(!str_contains($answer['label'], 'Deccan') && !preg_match('/\\d{3,}\\.\\d/', $answer['label']), 'no supplier and no amount in the label');
    same([['uuid' => 'user-clerk', 'can_view' => false], ['uuid' => 'user-viewer', 'can_view' => true], ['uuid' => 'user-nobody', 'can_view' => false]], $answer['recipients'], 'each person answered from their own Purchases profile');
    same(1, (int) Db::scalar("SELECT COUNT(*) FROM purchase_audit_log WHERE action = 'connect.share_checked'"), 'the share is audited');

    [$status] = endpoint(static fn () => (new Domain\ConnectShareService($ctx, person()))->shareCheck(['entity_type' => 'purchase_order', 'entity_id' => 999999, 'recipient_uuids' => []]), $owner);
    same(404, $status, 'an order of no company of ours is not found');
    [$status, $payload] = endpoint(static fn () => (new Domain\ConnectShareService($ctx, person()))->shareCheck(['entity_type' => 'invoice', 'entity_id' => 1, 'recipient_uuids' => []]), $owner);
    same(422, $status, 'a kind Purchases does not share is refused');
    same('unsupported_entity', $payload['error']['code'] ?? null, 'saying so');
    [$status] = endpoint(static fn () => (new Domain\ConnectShareService($ctx, person()))->shareCheck(['entity_type' => 'purchase_order', 'entity_id' => $po['po_id'], 'recipient_uuids' => 'user-viewer']), $owner);
    same(422, $status, 'recipients must be a list');

    // Through the route, as Connect calls it: a JSON number id and no year when it knows none.
    $body = new \ReflectionProperty(Http::class, 'body');
    $body->setValue(null, ['entity_type' => 'purchase_order', 'entity_id' => (int) $po['po_id'], 'recipient_uuids' => ['user-viewer']]);
    try {
        [$status, $payload] = endpoint([Controllers\ConnectController::class, 'shareCheck'], person('user-viewer', 0), ['cmp_id' => '88']);
    } finally {
        $body->setValue(null, null);
    }
    same(200, $status, 'without the sharer\'s year, the order\'s own year is used');
    same(true, $payload['data']['allowed'] ?? null, 'and a viewer may share it');
});

check('what a viewer sees of a shared document is asked with their own session, every time', function () use ($ctx, $owner) {
    reset();
    $po = orderOf($ctx, $owner);
    profile('user-viewer', 'Viewer', ['po.view']);
    profile('user-clerk', 'Clerk', ['requisition.view']);
    $read = static fn (Auth $who, array $query = ['cmp_id' => '88', 'fy_id' => '6', 'bo_id' => '0']) => endpoint(static fn () => Controllers\ConnectController::context('purchase_order', (string) $po['po_id']), $who, $query);

    [$status, $payload] = $read(person('user-viewer', 0));
    same(200, $status, 'a viewer who may open the order reads it');
    same('Purchase order ' . $po['po_no'], $payload['data']['label'], 'its label');
    same('/purchase-orders/' . $po['po_id'], $payload['data']['open_path'], 'and where it opens in Purchases');
    same('Deccan Steel Traders', $payload['data']['party_name'], 'the supplier, to someone who may see it');
    truthy(is_string($payload['data']['amount']), 'and the amount');

    [$status] = $read(person('user-clerk', 0));
    same(403, $status, 'somebody in the same conversation without the permission sees nothing');

    [$status, $payload] = $read(person('user-viewer', 0), ['cmp_id' => '88']);
    same(200, $status, 'without the viewer\'s year, the order\'s own year is used');

    [$status] = $read(person('user-viewer', 0), ['cmp_id' => '99']);
    same(404, $status, 'another company\'s id is simply not found');

    [$status] = endpoint(static fn () => Controllers\ConnectController::context('purchase_order', '999999'), person('user-viewer', 0));
    same(404, $status, 'nor is one that does not exist');
    [$status] = endpoint(static fn () => Controllers\ConnectController::context('invoice', (string) $po['po_id']), person('user-viewer', 0));
    same(404, $status, 'nor a kind Purchases does not have');
});

// ---------------------------------------------------------------------------
echo "\nLaunch 2026-10-01 — PU1: every key Books receives fits, and what its length blocked is recovered\n";

/** Run bin/books-key-recovery.php; $session, when given, is the person's portal session. @return array{0:int, 1:string} */
function keyRecovery(string $args, string $session = ''): array
{
    $env = $session === '' ? '' : 'RECOVERY_SES_KEY=' . escapeshellarg($session) . ' PORTAL_AUTH_BASE=' . escapeshellarg(Env::get('BOOKS_API_BASE')) . ' ';
    exec($env . 'php ' . escapeshellarg(__DIR__ . '/../bin/books-key-recovery.php') . ' ' . $args . ' 2>&1', $out, $code);

    return [$code, implode("\n", $out)];
}

/** POST straight to the stub's Books, as an older Purchase did — to show what Books makes of a key. */
function rawBooksPost(string $path, string $key): int
{
    $ch = curl_init(Env::get('BOOKS_API_BASE') . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => 'POST', CURLOPT_POSTFIELDS => '{"vch_type_id":3,"payload":{}}',
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer stub-ses-key.role-1', 'Idempotency-Key: ' . $key, 'X-Test-Probe: 1'],
    ]);
    curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    return $status;
}

/** Books' answer to a key it cannot store, as Purchase received it before keys were sized. */
function booksRefusesTheKey(): void
{
    stubFail('/vouchers/drafts', 400, false, 'idempotency_key_too_long', 'Idempotency-Key must be at most 64 characters.');
}

/** @return list<string> every Idempotency-Key Purchase sent to Books' voucher routes (not the tests' own probes) */
function booksKeysSent(): array
{
    return array_values(array_map(
        static fn (array $r) => (string) ($r['headers']['idempotency-key'] ?? ''),
        array_filter(stubRequests('/vouchers/drafts'), static fn (array $r) => $r['method'] === 'POST' && empty($r['headers']['x-test-probe'])),
    ));
}

check('every key Books receives fits its 64 characters: return and claim debit notes, and a large company\'s bill', function () use ($ctx, $owner) {
    reset();
    $short = 'purchases:88:purchases.bill.post:bill_request:5:r0:draft';
    same($short, IdempotencyKey::forWire($short, '', 64), 'a key that fits goes out unchanged, so one Books already holds is still recognised');
    $long = 'purchases:88:purchases.return.debit_note:purchase_return:1:r0';
    $draft = IdempotencyKey::forWire($long, ':draft', 64);
    same(64, strlen($draft), 'a longer one is compressed to exactly the width');
    same($draft, IdempotencyKey::forWire($long, ':draft', 64), 'the same bytes every time');
    truthy($draft !== IdempotencyKey::forWire($long, ':post', 64), 'the draft and its post never share a key');
    truthy(IdempotencyKey::forWire($long . '0', ':draft', 64) !== $draft, 'two keys that share a head stay two keys');
    truthy(str_starts_with($draft, 'purchases:88:'), 'and a person can still read whose it is');
    same(400, rawBooksPost('/vouchers/drafts', $long . ':draft'), 'Books (and now the stub) refuses the uncompressed debit-note key');

    $po = billedOrder($ctx, $owner);
    $returns = new Domain\ReturnClaimService($ctx, $owner);
    $id = (int) returnOf($ctx, $owner, $po, 10)['return_id'];
    $returns->approveReturn($id, []);
    $returns->dispatchReturn($id);
    same('DEBITED', $returns->requestDebitNote($id)['status'], 'a purchase return\'s debit note posts');

    $claimId = approvedClaim($ctx, $owner, 1200);
    $resolutions = new Domain\ClaimResolutionService($ctx, $owner);
    $r = $resolutions->propose($claimId, ['kind' => 'financial_adjustment', 'amount' => 1200, 'adjustment_acc_id' => 7310]);
    same('COMPLETED', $resolutions->approve((int) $r['resolution_id'])['status'], 'a claim\'s debit note posts');

    // A company and a bill with enough digits that the bill's own key passes 64 with its step.
    $bigCompany = Context::of(4321987, 6, 0);
    $bigOwner = person('user-owner', 1, 4321987);
    Db::run("SELECT setval(pg_get_serial_sequence('purchase_bill_requests', 'request_id'), 98765432)");
    $bigPo = billedOrder($bigCompany, $bigOwner);
    same('CLOSED', $bigPo['status'], 'the large company\'s bill posts');
    $bigKey = (string) Db::scalar("SELECT idempotency_key FROM purchase_integration_commands WHERE cmp_id = 4321987 AND command_type = 'purchases.bill.post'");
    truthy(strlen($bigKey . ':draft') > 64, 'whose stored key is longer than Books keeps: ' . $bigKey);

    $keys = booksKeysSent();
    same(8, count($keys), 'two bills, two debit notes: a draft and a post each');
    truthy(max(array_map('strlen', $keys)) <= 64, 'none longer than 64: ' . json_encode($keys));
    same(count($keys), count(array_unique($keys)), 'and each its own key');
    same(2, count(array_filter(booksVouchers(), static fn ($v) => (int) $v['vch_type_id'] === 3)), 'both debit notes are in Books');
});

check('a debit note Books refused for its key length is found, confirmed absent in Books and re-issued once — dry run first, one company, idempotent', function () use ($ctx, $owner) {
    reset();
    $po = billedOrder($ctx, $owner);
    $returns = new Domain\ReturnClaimService($ctx, $owner);
    $id = (int) returnOf($ctx, $owner, $po, 10)['return_id'];
    $returns->approveReturn($id, []);
    $returns->dispatchReturn($id);

    booksRefusesTheKey();
    refused(fn () => $returns->requestDebitNote($id), 'at most 64', 'Books refuses the key, before writing anything');
    stubRecover();
    $command = IntegrationCommand::find(88, Domain\ReturnClaimService::COMMAND_DEBIT_NOTE, 'purchase_return', $id);
    same('BLOCKED', $command['status'], 'the debit note is blocked');
    refused(fn () => $returns->requestDebitNote($id), 'at most 64', 'and pressing the button again never sends it on its own');
    same('DISPATCHED', $returns->findReturn($id)['status'], 'the return waits with the payable overstated');

    // Another company's command in the same state is not this company's business.
    $other = IntegrationCommand::ensure(Context::of(91, 6, 0), 'books', Domain\ReturnClaimService::COMMAND_DEBIT_NOTE, 'purchase_return', 4242, ['bill' => ['bill_ref' => 'PR/91/1']]);
    Db::run("UPDATE purchase_integration_commands SET status = 'BLOCKED', last_status_code = 400, last_error = 'Idempotency-Key must be at most 64 characters.' WHERE command_id = :id", ['id' => (int) $other['command_id']]);

    $sent = count(stubRequests());
    [$code, $out] = keyRecovery('--cmp=88 --json');
    same(3, $code, 'a dry run that finds something says so in its exit status: ' . $out);
    $report = json_decode($out, true);
    same('dry_run', $report['mode'], 'the default is a dry run');
    same(1, count($report['commands']), 'only this company\'s command');
    $found = $report['commands'][0];
    same($sent, count(stubRequests()), 'nothing was sent anywhere');
    same('BLOCKED', IntegrationCommand::byId((int) $command['command_id'])['status'], 'and nothing changed');
    same(null, $found['books_check'], 'Books is not asked in a dry run');
    same($command['idempotency_key'] . ':draft', $found['refused_key'], 'it names the key Books refused');
    same(400, rawBooksPost('/vouchers/drafts', $found['refused_key']), 'which Books does refuse');
    truthy(strlen($found['wire_keys']['draft']) <= 64 && strlen($found['wire_keys']['post']) <= 64, 'and the keys it will go out with');

    [$code] = keyRecovery('--cmp=88 --check');
    same(1, $code, 'asking Books needs a person\'s session');
    [$code, $out] = keyRecovery('--cmp=88 --check --json', 'stub-ses-key.role-1');
    $found = json_decode($out, true)['commands'][0];
    same('no_voucher', $found['books_check']['verdict'], 'Books holds no voucher and no draft for the return: ' . $found['books_check']['detail']);
    same('BLOCKED', IntegrationCommand::byId((int) $command['command_id'])['status'], 'a check changes nothing');
    same(0, count(array_filter(booksVouchers(), static fn ($v) => (int) $v['vch_type_id'] === 3)), 'and posts nothing');

    [$code] = keyRecovery('--cmp=88 --apply', 'stub-ses-key.role-1');
    same(1, $code, 'applying needs a reason');
    [$code, $out] = keyRecovery('--cmp=88 --apply --json --reason=' . escapeshellarg('Debit notes stuck on the key length.'), 'stub-ses-key.role-1');
    same(0, $code, 'everything found was re-issued: ' . $out);
    $found = json_decode($out, true)['commands'][0];
    same('reissued', $found['result']['outcome'], 'the debit note was re-issued: ' . $found['result']['detail']);
    same('COMPLETED', IntegrationCommand::byId((int) $command['command_id'])['status'], 'the command completed');
    same('DEBITED', $returns->findReturn($id)['status'], 'through the same operation the screen runs: the return is debited');
    same('10.0000', (string) Db::scalar('SELECT debited_qty FROM purchase_order_lines WHERE po_id = :id', ['id' => (int) $po['po_id']]), 'and the order counts it');
    same(1, count(array_filter(booksVouchers(), static fn ($v) => (int) $v['vch_type_id'] === 3)), 'one debit note in Books');
    truthy(max(array_map('strlen', booksKeysSent())) <= 64, 'sent under keys Books keeps');
    same(1, (int) Db::scalar("SELECT COUNT(*) FROM purchase_audit_log WHERE action = 'integration.key_reissued' AND actor_uuid = 'user-owner' AND reason = 'Debit notes stuck on the key length.'"), 'audited against the person, with the reason');

    [$code, $out] = keyRecovery('--cmp=88 --apply --reason=again', 'stub-ses-key.role-1');
    same(0, $code, 'running it again finds nothing: ' . $out);
    truthy(str_contains($out, 'No command'), 'and says so');
    same(1, count(array_filter(booksVouchers(), static fn ($v) => (int) $v['vch_type_id'] === 3)), 'still one debit note');
    same('BLOCKED', IntegrationCommand::byId((int) $other['command_id'])['status'], 'the other company\'s command is untouched');
});

check('the recovery leaves alone a debit note Books already holds under the same reference, and re-issues it once that is cleared', function () use ($ctx, $owner) {
    reset();
    $claimId = approvedClaim($ctx, $owner, 900);
    $resolutions = new Domain\ClaimResolutionService($ctx, $owner);
    $r = $resolutions->propose($claimId, ['kind' => 'financial_adjustment', 'amount' => 900, 'adjustment_acc_id' => 7310]);
    booksRefusesTheKey();
    refused(fn () => $resolutions->approve((int) $r['resolution_id']), 'at most 64', 'Books refuses the claim\'s debit note');
    stubRecover();
    same('BLOCKED', $resolutions->find((int) $r['resolution_id'])['status'], 'the resolution is blocked');

    // While it was stuck, an accountant keyed the debit note into Books by hand.
    $reference = Db::scalar('SELECT claim_no FROM purchase_claims WHERE claim_id = :id', ['id' => $claimId]) . '/' . $r['resolution_id'];
    file_put_contents(sys_get_temp_dir() . '/stub-vouchers.json', json_encode(['5101' => [
        'vch_txn_id' => 5101, 'vch_type_id' => 3, 'vch_number' => 'DN/77', 'vch_date' => '2026-09-30',
        'party' => ['acc_id' => 601], 'bill' => ['bill_ref' => strtolower($reference)], 'lines' => [],
    ]]));
    [$code, $out] = keyRecovery('--cmp=88 --apply --json --reason=' . escapeshellarg('Recovery run.'), 'stub-ses-key.role-1');
    same(3, $code, 'something is left for a person');
    $found = json_decode($out, true)['commands'][0];
    same('voucher_found', $found['books_check']['verdict'], 'Books holds a debit note under the claim\'s reference');
    same(5101, $found['books_check']['matches'][0]['vch_txn_id'] ?? null, 'and names it');
    same('left_alone', $found['result']['outcome'], 'so it is not re-issued');
    same('BLOCKED', $resolutions->find((int) $r['resolution_id'])['status'], 'the resolution is as it was');
    same(1, count(booksVouchers()), 'and Books has no second debit note');

    // The accountant cancels the hand-made one; now the claim's own debit note can go.
    file_put_contents(sys_get_temp_dir() . '/stub-vouchers.json', json_encode([]));
    [$code, $out] = keyRecovery('--cmp=88 --apply --json --reason=' . escapeshellarg('Hand-made note cancelled.'), 'stub-ses-key.role-1');
    same(0, $code, 'all re-issued: ' . $out);
    same('COMPLETED', $resolutions->find((int) $r['resolution_id'])['status'], 'the resolution completes');
    same('SETTLED', (new Domain\ReturnClaimService($ctx, $owner))->findClaim($claimId)['status'], 'and settles the claim');
    same(1, count(array_filter(booksVouchers(), static fn ($v) => (int) $v['vch_type_id'] === 3)), 'one debit note');
});

echo "\n" . str_repeat('-', 60) . "\n";
echo "{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
