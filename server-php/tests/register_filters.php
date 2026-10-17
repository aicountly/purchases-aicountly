<?php

declare(strict_types=1);

/**
 * Register filters (RegisterFilter): the GST quick filters and the advanced amount / supplier
 * filters on the bills, orders, returns, claims and requisitions lists — parsed, and then applied
 * by the real controllers to real rows in PostgreSQL, so what is proved is the SQL: the count,
 * the page and the rows all describe the filtered set, and a GST key the bills list cannot answer
 * comes back as unavailable instead of being ignored.
 *
 *   server-php/tests/run.sh
 */

namespace Aicountly\Api;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../src/Env.php';
require __DIR__ . '/../src/Autoload.php';

Env::load(__DIR__ . '/../.env');

use Aicountly\Api\Controllers\BillsController;
use Aicountly\Api\Controllers\ClaimsController;
use Aicountly\Api\Controllers\PurchaseOrdersController;
use Aicountly\Api\Controllers\RequisitionsController;
use Aicountly\Api\Controllers\ReturnsController;
use Aicountly\Api\Domain\BillService;
use Aicountly\Api\Domain\RegisterFilter;

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

function owner(): Auth
{
    $r = new \ReflectionClass(Auth::class);
    $auth = $r->newInstanceWithoutConstructor();
    foreach (['uuid' => 'user-owner', 'kind' => 'user', 'sourceApp' => 'purchases', 'sesKey' => 'stub-ses-key.role-1', 'session' => ['name' => 'user-owner']] as $prop => $value) {
        $r->getProperty($prop)->setValue($auth, $value);
    }
    $auth->noteCompanyAccess(88, 1);

    return $auth;
}

function reset(): void
{
    require_once __DIR__ . '/reset.php';
    resetPurchaseTables();
    Permissions::forget();
}

/**
 * A list endpoint, through its real controller, with this query on top of the company scope.
 *
 * @param array<string, string> $query
 * @return array{0: int, 1: array<string, mixed>}
 */
function listed(callable $action, array $query = []): array
{
    $_GET = $query + ['cmp_id' => '88', 'fy_id' => '6', 'bo_id' => '0'];
    Auth::adopt(owner());
    Context::forgetVerified();
    try {
        $action();
    } catch (ResponseSent $sent) {
        return [$sent->status, $sent->payload];
    } finally {
        Auth::adopt(null);
        $_GET = [];
    }
    throw new \RuntimeException('the endpoint returned without responding');
}

/** @return list<int> one id column of a list response, in the order listed */
function ids(array $payload, string $column): array
{
    return array_map(static fn (array $row) => (int) $row[$column], $payload['data']);
}

/**
 * A bill as stored, without the match and Books round trip entering one makes: the list reads
 * only these columns.
 *
 * @param list<string> $amounts line amounts as text, the way requested_lines holds them
 */
function storedBill(string $invoice, int $supplier, array $amounts, bool $reverseCharge = false, string $date = '2026-09-10'): int
{
    return (int) Db::insert('purchase_bill_requests', [
        'cmp_id'                => 88,
        'fy_id'                 => 6,
        'supplier_account_id'   => $supplier,
        'supplier_invoice_no'   => $invoice,
        'supplier_invoice_date' => $date,
        'status'                => 'MATCHED',
        'requested_lines'       => array_map(static fn (string $a, int $i) => ['line_no' => $i + 1, 'is_service' => true, 'description' => 'Service', 'amount' => $a], $amounts, array_keys($amounts)),
        'is_reverse_charge'     => $reverseCharge,
        'requested_by'          => 'user-owner',
    ], 'request_id');
}

// ---------------------------------------------------------------------------
echo "\nRegisterFilter — reading the request\n";

check('GST keys: known keys only, in declared order, each once; a typo is dropped', function () {
    same(['local', 'rcm'], RegisterFilter::parseKeys('RCM, local,rcm,,nonsense', RegisterFilter::gstKeys()), 'parsed');
    same([], RegisterFilter::parseKeys(['x' => ['nested']], RegisterFilter::gstKeys()), 'an array of arrays is nothing');
    same([], RegisterFilter::parseKeys(null, RegisterFilter::gstKeys()), 'absent');
    same(['local', 'interstate', 'overseas', 'registered', 'unregistered', 'composition', 'sez', 'exempt', 'rcm'], RegisterFilter::gstKeys(), 'Books\' keys, in Books\' order');
});

check('a key the register answers is applied; any other known key is reported unavailable, not ignored', function () {
    $filter = RegisterFilter::fromParams(['gst' => 'local,rcm,registered,typo'], BillService::GST_PREDICATES);
    same(['rcm'], $filter->gst, 'applied');
    same(['local', 'registered'], $filter->gstUnavailable, 'unavailable');
    $none = RegisterFilter::fromParams(['gst' => 'rcm'], []);
    same([], $none->gst, 'a register with no GST facts applies none');
    same(['rcm'], $none->gstUnavailable, 'and says so');
    same(['gst' => ['rcm'], 'gst_unavailable' => ['local', 'registered'], 'amount_min' => null, 'amount_max' => null], $filter->toMeta(), 'the meta the screen reads');
});

check('amounts: non-negative decimals, commas and spaces allowed, garbage dropped, reversed bounds swapped', function () {
    same('150000', RegisterFilter::parseAmount('1,50,000'), 'Indian grouping');
    same('2500.5', RegisterFilter::parseAmount(' 2 500.50 '), 'spaces and a trailing zero');
    same('0', RegisterFilter::parseAmount('000'), 'zero is a bound');
    same('7', RegisterFilter::parseAmount('007'), 'leading zeros');
    foreach (['-5', 'abc', '5e3', '1.', '.5', '1.2.3', '', '1;DROP TABLE x', "1'"] as $garbage) {
        same(null, RegisterFilter::parseAmount($garbage), 'garbage "' . $garbage . '"');
    }
    same(null, RegisterFilter::parseAmount(['5']), 'an array is not an amount');
    same(['10000', '50000'], RegisterFilter::parseAmountRange('50,000', '10000'), 'swapped');
    same(['9.5', '10'], RegisterFilter::parseAmountRange('10', '9.50'), 'swapped by value, not by text');
    same(['99999999999999999999.01', '100000000000000000000'], RegisterFilter::parseAmountRange('100000000000000000000', '99999999999999999999.01'), 'compared without floats');
    same([null, '5'], RegisterFilter::parseAmountRange('junk', '5'), 'one bound');
});

check('chips in one group are OR, groups are AND', function () {
    $filter = RegisterFilter::fromParams(['gst' => 'local,interstate,registered'], [
        'local' => 'L', 'interstate' => 'I', 'registered' => 'R',
    ]);
    $where = [];
    $params = [];
    $filter->apply($where, $params, 'AMOUNT');
    same(['((L) OR (I))', '((R))'], $where, 'one condition per group, OR inside');
    same([], $params, 'no binding without an amount');
    $ranged = RegisterFilter::fromParams(['amount_min' => '10', 'amount_max' => '5']);
    $where = [];
    $ranged->apply($where, $params, 'AMOUNT');
    same(['(AMOUNT) >= CAST(:rf_amount_min AS NUMERIC)', '(AMOUNT) <= CAST(:rf_amount_max AS NUMERIC)'], $where, 'bounds on the shown amount');
    same(['rf_amount_min' => '5', 'rf_amount_max' => '10'], $params, 'bound as text, swapped');
});

// ---------------------------------------------------------------------------
echo "\nBills list — against real rows\n";

check('reverse charge, the amount range and the supplier narrow the count, the page and the rows', function () {
    reset();
    $a = storedBill('A-1', 601, ['1000', '500']);            // 1500, not RCM
    $b = storedBill('B-1', 601, ['20000'], true);           // 20000, RCM
    $c = storedBill('C-1', 602, ['49999.99', '0.01'], true); // 50000, RCM
    $d = storedBill('D-1', 602, ['0.10']);                  // 0.1, not RCM

    [$status, $all] = listed(fn () => BillsController::index());
    same(200, $status, 'listed');
    same(4, $all['meta']['total'], 'unfiltered');
    $values = array_column($all['data'], 'subtotal', 'request_id');
    same(1500.0, (float) $values[$a], 'the value shown is the sum of the lines');
    same(50000.0, (float) $values[$c], 'to the paisa');

    [, $rcm] = listed(fn () => BillsController::index(), ['gst' => 'rcm', 'sort' => 'supplier_invoice_no', 'order' => 'asc']);
    same(2, $rcm['meta']['total'], 'reverse charge only');
    same([$b, $c], ids($rcm, 'request_id'), 'the reverse-charge bills');
    same(['rcm'], $rcm['meta']['filters']['gst'], 'meta says what was applied');
    same([], $rcm['meta']['filters']['gst_unavailable'], 'and that nothing was refused');

    [, $ranged] = listed(fn () => BillsController::index(), ['amount_min' => '1,500', 'amount_max' => '50,000', 'sort' => 'supplier_invoice_no', 'order' => 'asc']);
    same([$a, $b, $c], ids($ranged, 'request_id'), 'bounds are inclusive, on the value shown');
    same('1500', $ranged['meta']['filters']['amount_min'], 'the bound as applied');

    [, $swapped] = listed(fn () => BillsController::index(), ['amount_min' => '50000', 'amount_max' => '1500', 'sort' => 'supplier_invoice_no', 'order' => 'asc']);
    same([$a, $b, $c], ids($swapped, 'request_id'), 'reversed bounds are the same range');

    [, $tiny] = listed(fn () => BillsController::index(), ['amount_max' => '0.1']);
    same([$d], ids($tiny, 'request_id'), 'compared as NUMERIC: 0.10 is not above 0.1');

    [, $garbage] = listed(fn () => BillsController::index(), ['amount_min' => "1'; DROP TABLE purchase_bill_requests; --", 'gst' => "rcm'"]);
    same(4, $garbage['meta']['total'], 'garbage is no filter, never an error');
    same(null, $garbage['meta']['filters']['amount_min'], 'and is reported as no bound');

    [, $combined] = listed(fn () => BillsController::index(), ['gst' => 'rcm', 'supplier_account_id' => '602', 'amount_min' => '100']);
    same([$c], ids($combined, 'request_id'), 'chips, supplier and amount together');
    same(602, $combined['meta']['filters']['supplier_account_id'], 'the supplier as applied');

    [, $paged] = listed(fn () => BillsController::index(), ['gst' => 'rcm', 'limit' => '1', 'offset' => '1', 'sort' => 'supplier_invoice_no', 'order' => 'asc']);
    same(2, $paged['meta']['total'], 'the total is the filtered total on every page');
    same([$c], ids($paged, 'request_id'), 'the second page of the filtered set');
});

check('a GST key the bills list cannot answer is reported unavailable and left off the query', function () {
    reset();
    storedBill('A-1', 601, ['100']);
    storedBill('B-1', 601, ['200'], true);

    [, $local] = listed(fn () => BillsController::index(), ['gst' => 'local']);
    same(2, $local['meta']['total'], 'nothing is guessed: the list is not narrowed by a key it cannot answer');
    same([], $local['meta']['filters']['gst'], 'nothing applied');
    same(['local'], $local['meta']['filters']['gst_unavailable'], 'and the screen is told');

    [, $mixed] = listed(fn () => BillsController::index(), ['gst' => 'exempt,rcm,sez,overseas']);
    same(1, $mixed['meta']['total'], 'the answerable key still applies');
    same(['rcm'], $mixed['meta']['filters']['gst'], 'applied');
    same(['overseas', 'sez', 'exempt'], $mixed['meta']['filters']['gst_unavailable'], 'unavailable, in display order');
});

// ---------------------------------------------------------------------------
echo "\nOrders, returns, claims and requisitions — the amount they show, and the supplier\n";

check('purchase orders: amount range on the order total, supplier, filtered count', function () {
    reset();
    $po = static fn (string $no, int $supplier, string $total) => (int) Db::insert('purchase_orders', [
        'cmp_id' => 88, 'fy_id' => 6, 'po_no' => $no, 'po_date' => '2026-09-01', 'supplier_account_id' => $supplier,
        'total_amount' => $total, 'created_by' => 'user-owner',
    ], 'po_id');
    $small = $po('PO-1', 601, '999.99');
    $mid = $po('PO-2', 601, '25000');
    $big = $po('PO-3', 602, '100000');

    [$status, $ranged] = listed(fn () => PurchaseOrdersController::index(), ['amount_min' => '1000', 'sort' => 'po_no', 'order' => 'asc']);
    same(200, $status, 'listed');
    same([$mid, $big], ids($ranged, 'po_id'), 'from 1,000');
    same(2, $ranged['meta']['total'], 'the count');
    [, $supplier] = listed(fn () => PurchaseOrdersController::index(), ['supplier_account_id' => '601', 'amount_max' => '25,000', 'sort' => 'po_no', 'order' => 'asc']);
    same([$small, $mid], ids($supplier, 'po_id'), 'supplier and an upper bound');
    same(['gst' => [], 'gst_unavailable' => [], 'amount_min' => null, 'amount_max' => '25000', 'supplier_account_id' => 601], $supplier['meta']['filters'], 'meta');
});

check('returns: amount range on the value listed (the sum of the lines), supplier', function () {
    reset();
    $return = static function (string $no, int $supplier, array $amounts): int {
        $id = (int) Db::insert('purchase_returns', [
            'cmp_id' => 88, 'fy_id' => 6, 'return_no' => $no, 'return_date' => '2026-09-05', 'supplier_account_id' => $supplier, 'created_by' => 'user-owner',
        ], 'return_id');
        foreach ($amounts as $i => $amount) {
            Db::insert('purchase_return_lines', ['return_id' => $id, 'cmp_id' => 88, 'line_no' => $i + 1, 'return_qty' => '1', 'rate' => $amount, 'line_amount' => $amount], 'line_id');
        }

        return $id;
    };
    $one = $return('RET-1', 601, ['400', '600']);
    $two = $return('RET-2', 602, ['5000']);
    $return('RET-3', 602, []);

    [$status, $all] = listed(fn () => ReturnsController::index(), ['sort' => 'return_no', 'order' => 'asc']);
    same(200, $status, 'listed');
    same([1000.0, 5000.0, 0.0], array_map(static fn ($r) => (float) $r['return_value'], $all['data']), 'the value shown');
    [, $ranged] = listed(fn () => ReturnsController::index(), ['amount_min' => '1000', 'amount_max' => '1000']);
    same([$one], ids($ranged, 'return_id'), 'exactly 1,000');
    [, $supplier] = listed(fn () => ReturnsController::index(), ['supplier_account_id' => '602', 'amount_min' => '1']);
    same([$two], ids($supplier, 'return_id'), 'a supplier with a value');
});

check('claims: amount range on the amount claimed, supplier', function () {
    reset();
    $claim = static fn (string $no, int $supplier, string $amount) => (int) Db::insert('purchase_claims', [
        'cmp_id' => 88, 'fy_id' => 6, 'claim_no' => $no, 'claim_date' => '2026-09-06', 'supplier_account_id' => $supplier,
        'claim_kind' => 'shortage', 'claimed_amount' => $amount, 'created_by' => 'user-owner',
    ], 'claim_id');
    $claim('CL-1', 601, '300');
    $b = $claim('CL-2', 601, '3000');
    $c = $claim('CL-3', 602, '30000');

    [$status, $ranged] = listed(fn () => ClaimsController::index(), ['amount_min' => '3,000', 'sort' => 'claim_no', 'order' => 'asc']);
    same(200, $status, 'listed');
    same([$b, $c], ids($ranged, 'claim_id'), 'from 3,000');
    [, $supplier] = listed(fn () => ClaimsController::index(), ['supplier_account_id' => '601', 'amount_min' => '1000']);
    same([$b], ids($supplier, 'claim_id'), 'supplier and amount');
    same(1, $supplier['meta']['total'], 'the count');
});

check('requisitions: amount range on the estimated value', function () {
    reset();
    $req = static fn (string $no, string $value) => (int) Db::insert('purchase_requisitions', [
        'cmp_id' => 88, 'fy_id' => 6, 'requisition_no' => $no, 'requisition_date' => '2026-09-02', 'requester_uuid' => 'user-owner', 'estimated_value' => $value,
    ], 'requisition_id');
    $req('REQ-1', '10');
    $b = $req('REQ-2', '7500');

    [$status, $ranged] = listed(fn () => RequisitionsController::index(), ['amount_min' => '100']);
    same(200, $status, 'listed');
    same([$b], ids($ranged, 'requisition_id'), 'from 100');
    same(1, $ranged['meta']['total'], 'the count');
    same('100', $ranged['meta']['filters']['amount_min'], 'meta');
});

echo "\n" . str_repeat('-', 60) . "\n";
echo "{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
