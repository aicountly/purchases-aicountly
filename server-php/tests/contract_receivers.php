<?php

declare(strict_types=1);

/**
 * Purchases against the REAL Inventory (launch readiness E8-PUR: F222, F223, F212, F402, F009).
 *
 * The other suites prove Purchases against tests/stub/router.php — shapes Purchases wrote. This
 * one starts Inventory-aicountly's own front controller under php -S on a disposable database and
 * drives Purchases' real code at it: a goods receipt recorded through ReceiptService, the one-call
 * document read, the stock_busy refusal (a held company lock, so the answer is Inventory's own),
 * the 404 for a document Inventory does not hold, and the batch/expiry rule. Only Manage (the
 * fiscal calendar), Books and the portal stay the stub.
 *
 *   INVENTORY_CONTRACT_ROOT=/path/to/Inventory-aicountly/server-php \
 *   INVENTORY_CONTRACT_DB=e8pu_inv_test INVENTORY_CONTRACT_DB_USER=postgres INVENTORY_CONTRACT_DB_PASSWORD=postgres \
 *   server-php/tests/run.sh
 *
 * Skipped (exit 0, said plainly) when INVENTORY_CONTRACT_ROOT or INVENTORY_CONTRACT_DB is unset.
 * The database is TRUNCATED: point it only at a disposable Inventory test database whose schema
 * Inventory's own integration suite has migrated. Every outbound base of the Inventory server is
 * set to a local address, so nothing reaches a real AICOUNTLY host.
 */

namespace Aicountly\Api;

require __DIR__ . '/../src/Env.php';
require __DIR__ . '/../src/Autoload.php';

Env::load(__DIR__ . '/../.env');

use Aicountly\Api\Clients\InventoryClient;
use Aicountly\Api\Domain\PurchaseOrderService;
use Aicountly\Api\Domain\ReceiptLedger;
use Aicountly\Api\Domain\ReceiptService;

$invRoot = (string) getenv('INVENTORY_CONTRACT_ROOT');
$invDb = (string) getenv('INVENTORY_CONTRACT_DB');
if ($invRoot === '' || $invDb === '') {
    echo "inventory contract: skipped (INVENTORY_CONTRACT_ROOT / INVENTORY_CONTRACT_DB unset)\n";
    exit(0);
}
if (!is_file($invRoot . '/public/index.php') || !is_file($invRoot . '/vendor/autoload.php')) {
    fwrite(STDERR, "inventory contract: INVENTORY_CONTRACT_ROOT is not an Inventory server-php with vendor/\n");
    exit(1);
}

$passed = 0;
$failed = 0;
function check(string $name, callable $fn): void
{
    global $passed, $failed;
    try {
        $fn();
        $passed++;
        echo "  ok    {$name}\n";
    } catch (\Throwable $e) {
        $failed++;
        echo "  FAIL  {$name}\n        " . $e->getMessage() . "\n";
    }
}
function same(mixed $expected, mixed $actual, string $what): void
{
    if ($expected !== $actual) {
        throw new \RuntimeException($what . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}
function truthy(bool $condition, string $what): void
{
    if (!$condition) {
        throw new \RuntimeException($what);
    }
}

// --- The disposable Inventory database, set up as Inventory's own integration suite does ------
$dbHost = (string) (getenv('INVENTORY_CONTRACT_DB_HOST') ?: '127.0.0.1');
$dbPort = (string) (getenv('INVENTORY_CONTRACT_DB_PORT') ?: '5432');
$dbUser = (string) (getenv('INVENTORY_CONTRACT_DB_USER') ?: 'postgres');
$dbPass = (string) (getenv('INVENTORY_CONTRACT_DB_PASSWORD') ?: '');
$inv = new \PDO("pgsql:host={$dbHost};port={$dbPort};dbname={$invDb}", $dbUser, $dbPass, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
$tables = $inv->query("SELECT table_name FROM information_schema.tables WHERE table_schema = 'public' AND table_name LIKE 'inv\\_%' AND table_name <> 'inv_sql_migrations'")->fetchAll(\PDO::FETCH_COLUMN);
if (!in_array('inv_documents', $tables, true)) {
    fwrite(STDERR, "inventory contract: {$invDb} has no Inventory schema — run Inventory's integration suite against it first\n");
    exit(1);
}
$inv->exec('TRUNCATE ' . implode(', ', $tables) . ' RESTART IDENTITY');
$inv->exec("INSERT INTO inv_fy_ranges (cmp_id, fy_id, fy_start, fy_end) VALUES (88, 6, '2026-04-01', '2027-03-31')");
$inv->exec("INSERT INTO inv_company_settings (cmp_id, default_valuation_method, valuation_scope, negative_stock_policy, created_at) VALUES (88, 'FIFO', 'company', 'allow', NOW())");
$inv->exec("INSERT INTO inv_uom (cmp_id, unit_name, unit_symbol, print_name, uqc_gst, is_active) VALUES (88, 'Nos', 'Nos', 'Nos', 'NOS', 1)");
$inv->exec("INSERT INTO inv_warehouses (cmp_id, warehouse_name, warehouse_type, is_active) VALUES (88, 'Main Store', 'standard', 1)");
$inv->exec("INSERT INTO inv_items (cmp_id, item_name, unit_id, valuation_method, is_active, hsn_sac) VALUES (88, 'TMT Steel 12mm', 1, 'FIFO', 1, '7214')");
$inv->exec("INSERT INTO inv_item_uoms (cmp_id, item_id, unit_id, is_default, conversion_factor) VALUES (88, 1, 1, 1, 1)");
$inv->exec("INSERT INTO inv_items (cmp_id, item_name, unit_id, valuation_method, is_active, track_batch, track_expiry, shelf_life_days) VALUES (88, 'Paint 20L', 1, 'FIFO', 1, 1, 1, 0)");
$inv->exec("INSERT INTO inv_item_uoms (cmp_id, item_id, unit_id, is_default, conversion_factor) VALUES (88, 2, 1, 1, 1)");

// --- The real Inventory, every outbound base local ---------------------------------------------
$stubPort = (int) (parse_url(Env::get('BOOKS_API_BASE'), PHP_URL_PORT) ?: 33510);
$invPort = (int) (getenv('INVENTORY_CONTRACT_PORT') ?: ($stubPort + 10));
$key = 'e8pu-contract-purchases-key-0123456789';
$stub = 'http://127.0.0.1:' . $stubPort;
$serverEnv = [
    'CI_ENVIRONMENT'                 => 'production',
    // Plain HTTP on 127.0.0.1: the deployed config redirects to https://inventory.aicountly.com.
    'app.forceGlobalSecureRequests'  => 'false',
    'app.baseURL'                    => 'http://127.0.0.1:' . $invPort . '/',
    'database.default.hostname'      => $dbHost,
    'database.default.port'          => $dbPort,
    'database.default.database'      => $invDb,
    'database.default.username'      => $dbUser,
    'database.default.password'      => $dbPass,
    'database.default.DBDriver'      => 'Postgre',
    'INVENTORY_SERVICE_KEYS'         => 'purchases:' . $key,
    'MANAGE_PRODUCT_SERVICE_KEY'     => 'e8pu-contract-manage-key',
    'MANAGE_API_BASE'                => $stub,
    'PORTAL_AUTH_BASE'               => $stub,
    'BOOKS_API_BASE'                 => 'http://127.0.0.1:9',
    'PULSE_API_ORIGIN'               => 'http://127.0.0.1:9',
    'CONSOLE_API_BASE'               => 'http://127.0.0.1:9',
    'INVENTORY_LOCK_TIMEOUT_MS'      => '1200',
    'PATH'                           => (string) getenv('PATH'),
    'HOME'                           => (string) getenv('HOME'),
    'TMPDIR'                         => sys_get_temp_dir(),
];
$router = $invRoot . '/vendor/codeigniter4/framework/system/rewrite.php';
$log = sys_get_temp_dir() . '/e8pu-inventory-contract.log';
$server = proc_open(
    ['php', '-S', '127.0.0.1:' . $invPort, '-t', $invRoot . '/public', $router],
    [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'w'], 2 => ['file', $log, 'a']],
    $pipes,
    $invRoot . '/public',
    $serverEnv,
);
register_shutdown_function(static function () use ($server): void {
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
});
$up = false;
for ($i = 0; $i < 40 && !$up; $i++) {
    usleep(150000);
    $up = @fsockopen('127.0.0.1', $invPort) !== false;
}
if (!$up) {
    fwrite(STDERR, "inventory contract: Inventory did not start on {$invPort}\n");
    exit(1);
}

// Purchases now talks to the real Inventory; everything else stays as run.sh configured it.
putenv('INVENTORY_API_BASE=http://127.0.0.1:' . $invPort);
putenv('INVENTORY_SERVICE_KEY=' . $key);

require_once __DIR__ . '/reset.php';
resetPurchaseTables();
$ctx = Context::of(88, 6, 0);
$r = new \ReflectionClass(Auth::class);
$owner = $r->newInstanceWithoutConstructor();
foreach (['uuid' => 'user-owner', 'kind' => 'user', 'sourceApp' => 'purchases', 'sesKey' => 'stub-ses-key.role-1', 'session' => ['name' => 'Owner']] as $prop => $value) {
    $r->getProperty($prop)->setValue($owner, $value);
}
$owner->noteCompanyAccess(88, 1);
$service = (new InventoryClient())->withService('user-owner');

echo "\nPurchases against the real Inventory\n";

$po = null;
check('F222/F223: a goods receipt recorded through ReceiptService is posted by the real Inventory, exactly once, and read back in ONE call (F402)', function () use ($ctx, $owner, $service, &$po) {
    $orders = new PurchaseOrderService($ctx, $owner);
    $created = $orders->create(['supplier_account_id' => 601, 'po_date' => '2026-09-01', 'delivery_warehouse_id' => 1, 'lines' => [['item_id' => 1, 'unit_id' => 1, 'ordered_qty' => 20, 'agreed_rate' => 100, 'warehouse_id' => 1]]]);
    $orders->submit((int) $created['po_id']);
    $orders->issue((int) $created['po_id']);
    $po = $orders->find((int) $created['po_id']);
    (new ReceiptService($ctx, $owner))->request((int) $po['po_id'], ['received_at' => '2026-09-18', 'client_token' => 'contract-1', 'lines' => [['line_id' => (int) $po['lines'][0]['line_id'], 'qty' => 8]]]);
    $row = Db::first('SELECT * FROM purchase_receipt_requests ORDER BY request_id DESC LIMIT 1');
    same('ACCEPTED', $row['status'], 'the real Inventory accepted the GRN (last_error: ' . (string) $row['last_error'] . ')');
    $docId = (int) $row['inventory_document_id'];
    truthy($docId > 0, 'and gave its document id');
    // The same submission again: its receipt, no second document.
    (new ReceiptService($ctx, $owner))->request((int) $po['po_id'], ['received_at' => '2026-09-18', 'client_token' => 'contract-1', 'lines' => [['line_id' => (int) $po['lines'][0]['line_id'], 'qty' => 8]]]);
    same(1, (int) Db::scalar('SELECT COUNT(*) FROM purchase_receipt_requests'), 'one receipt');
    $read = ReceiptLedger::documents($ctx, $service, [$docId]);
    same('ok', $read['state'], 'the bulk read answers');
    truthy(isset($read['by_id'][$docId]['lines'][0]), 'with the document\'s lines in the same call');
    $ledger = (new ReceiptLedger($ctx, $owner))->forOrder((int) $po['po_id']);
    same(8.0, (float) ($ledger['received_by_line'][(int) $po['lines'][0]['line_id']] ?? 0), 'the ledger counts the 8 Inventory holds');
    same([], $ledger['discrepancies'], 'and finds nothing amiss');
});

check('F007/F283: a document the real Inventory does not hold is a definite absence (JSON 404), not an outage', function () use ($service, $ctx) {
    $answer = ReceiptService::sourceAnswer($service->documentBySource($ctx, 'purchases', ReceiptService::SOURCE_TYPE, 999999));
    same('absent', $answer['state'], 'absent: ' . json_encode($answer));
});

check('F212: the real Inventory\'s stock_busy (a held company lock) is retryable — the receipt is FAILED, not BLOCKED — and a retry on the same key is accepted', function () use ($ctx, $owner, $inv, &$po) {
    $lock = new \PDO($GLOBALS['invDsn'] ?? 'pgsql:host=' . $GLOBALS['dbHost'] . ';port=' . $GLOBALS['dbPort'] . ';dbname=' . $GLOBALS['invDb'], $GLOBALS['dbUser'], $GLOBALS['dbPass'], [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
    $companyKey = 0x494E5600000000 + 88; // StockLock::companyKey(88)
    $lock->exec('SELECT pg_advisory_lock(' . $companyKey . ')');
    try {
        $refused = null;
        try {
            (new ReceiptService($ctx, $owner))->request((int) $po['po_id'], ['received_at' => '2026-09-18', 'client_token' => 'contract-busy', 'lines' => [['line_id' => (int) $po['lines'][0]['line_id'], 'qty' => 2]]]);
        } catch (ResponseSent $e) {
            $refused = $e;
        }
        $row = Db::first("SELECT * FROM purchase_receipt_requests WHERE client_token = 'contract-busy'");
        $command = IntegrationCommand::find(88, ReceiptService::COMMAND_RECEIPT, 'receipt_request', (int) $row['request_id']);
        same('FAILED', $command['status'] ?? null, 'stock_busy leaves the command FAILED (retryable): ' . (string) ($command['last_error'] ?? '') . ' / ' . ($refused?->getMessage() ?? 'no refusal'));
        truthy($row['status'] !== 'BLOCKED', 'and the receipt is not BLOCKED');
    } finally {
        $lock->exec('SELECT pg_advisory_unlock(' . $companyKey . ')');
    }
    (new ReceiptService($ctx, $owner))->retry((int) $row['request_id']);
    same('ACCEPTED', Db::scalar('SELECT status FROM purchase_receipt_requests WHERE request_id = :id', ['id' => (int) $row['request_id']]), 'the retry on the same key is accepted once the lock is gone');
});

check('F009: the real Inventory reports track_expiry, and Purchases refuses an expiry-tracked receipt without an expiry before anything is sent', function () use ($ctx, $owner, $service) {
    $lookup = $service->bulkLookupItems($ctx, [2]);
    same(1, (int) ($lookup['body']['data'][0]['track_expiry'] ?? 0), 'track_expiry from the real lookup');
    $orders = new PurchaseOrderService($ctx, $owner);
    $created = $orders->create(['supplier_account_id' => 601, 'po_date' => '2026-09-01', 'delivery_warehouse_id' => 1, 'lines' => [['item_id' => 2, 'unit_id' => 1, 'ordered_qty' => 5, 'agreed_rate' => 900, 'warehouse_id' => 1]]]);
    $orders->submit((int) $created['po_id']);
    $orders->issue((int) $created['po_id']);
    $paint = $orders->find((int) $created['po_id']);
    $before = (int) Db::scalar('SELECT COUNT(*) FROM purchase_receipt_requests');
    try {
        (new ReceiptService($ctx, $owner))->request((int) $paint['po_id'], ['received_at' => '2026-09-18', 'client_token' => 'paint-1', 'lines' => [['line_id' => (int) $paint['lines'][0]['line_id'], 'qty' => 5, 'batch_no' => 'PNT-1']]]);
        throw new \RuntimeException('expected a refusal');
    } catch (ResponseSent $e) {
        same(422, $e->status, 'refused: ' . $e->getMessage());
    }
    same($before, (int) Db::scalar('SELECT COUNT(*) FROM purchase_receipt_requests'), 'nothing recorded');
    (new ReceiptService($ctx, $owner))->request((int) $paint['po_id'], ['received_at' => '2026-09-18', 'client_token' => 'paint-2', 'lines' => [['line_id' => (int) $paint['lines'][0]['line_id'], 'qty' => 5, 'batch_no' => 'PNT-2', 'expiry_date' => '2027-08-31']]]);
    same('ACCEPTED', Db::scalar("SELECT status FROM purchase_receipt_requests WHERE client_token = 'paint-2'"), 'with its expiry the real Inventory accepts it');
    same('2027-08-31', $GLOBALS['inv']->query("SELECT expiry_date FROM inv_batches WHERE batch_no = 'PNT-2'")->fetchColumn(), 'and the batch carries the expiry');
});

check('F120: a USD order at 83 is valued by the real Inventory at the rupee rate (830), not the dollar number', function () use ($ctx, $owner) {
    $orders = new PurchaseOrderService($ctx, $owner);
    $created = $orders->create(['supplier_account_id' => 601, 'po_date' => '2026-09-01', 'delivery_warehouse_id' => 1, 'currency_code' => 'USD', 'exchange_rate' => 83, 'lines' => [['item_id' => 1, 'unit_id' => 1, 'ordered_qty' => 2, 'agreed_rate' => 10, 'warehouse_id' => 1]]]);
    $orders->submit((int) $created['po_id']);
    $orders->issue((int) $created['po_id']);
    $usd = $orders->find((int) $created['po_id']);
    (new ReceiptService($ctx, $owner))->request((int) $usd['po_id'], ['received_at' => '2026-09-18', 'client_token' => 'usd-1', 'lines' => [['line_id' => (int) $usd['lines'][0]['line_id'], 'qty' => 2]]]);
    $docId = (int) Db::scalar("SELECT inventory_document_id FROM purchase_receipt_requests WHERE client_token = 'usd-1'");
    truthy($docId > 0, 'the real Inventory accepted the USD receipt');
    $line = $GLOBALS['inv']->query('SELECT source_transaction_rate, source_fc_rate, source_exchange_rate FROM inv_document_lines WHERE document_id = ' . $docId)->fetch(\PDO::FETCH_ASSOC);
    same(830.0, (float) $line['source_transaction_rate'], 'valued at the rupee rate');
    same(10.0, (float) $line['source_fc_rate'], 'with the dollar rate kept beside it');
    same(83.0, (float) $line['source_exchange_rate'], 'and the exchange rate');
});

echo "\n" . str_repeat('-', 60) . "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
