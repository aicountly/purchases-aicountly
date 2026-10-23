<?php

declare(strict_types=1);

/**
 * GET v1/suppliers/{id}/position — one supplier's open orders and Smart Books dues, for the
 * phone app (mobile/). Through the real controller, on real rows and the stub's Books
 * bill-by-bill report: what is listed, what is withheld from whom, and that Books being
 * unreachable is said as such rather than shown as "nothing owed".
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

use Aicountly\Api\Controllers\SupplierPositionController;

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

function person(string $uuid, ?int $acsType): Auth
{
    $r = new \ReflectionClass(Auth::class);
    $auth = $r->newInstanceWithoutConstructor();
    foreach (['uuid' => $uuid, 'kind' => 'user', 'sourceApp' => 'purchases', 'sesKey' => 'stub-ses-key.role-' . ($acsType ?? 'silent'), 'session' => ['name' => $uuid]] as $prop => $value) {
        $r->getProperty($prop)->setValue($auth, $value);
    }
    $auth->noteCompanyAccess(88, $acsType);

    return $auth;
}

/** A member who is not the owner (Manage access type 3), holding the named permissions. */
function member(string $uuid, array $permissions): Auth
{
    $id = (int) Db::insert('purchase_permission_profiles', ['cmp_id' => 88, 'profile_name' => 'Profile of ' . $uuid, 'permissions' => $permissions, 'is_active' => true], 'profile_id');
    Db::run('INSERT INTO purchase_permission_assignments (cmp_id, user_uuid, profile_id) VALUES (:c, :u, :p)', ['c' => 88, 'u' => $uuid, 'p' => $id]);
    Permissions::forget();

    return person($uuid, 3);
}

function reset(): void
{
    require_once __DIR__ . '/reset.php';
    resetPurchaseTables();
    Permissions::forget();
}

/** @return array{0: int, 1: array<string, mixed>} */
function position(Auth $auth, int $supplierId, array $query = []): array
{
    $_GET = $query + ['cmp_id' => '88', 'fy_id' => '6', 'bo_id' => '0'];
    Auth::adopt($auth);
    Context::forgetVerified();
    try {
        SupplierPositionController::show((string) $supplierId);
    } catch (ResponseSent $sent) {
        return [$sent->status, $sent->payload];
    } finally {
        Auth::adopt(null);
        $_GET = [];
    }
    throw new \RuntimeException('the endpoint returned without responding');
}

function order(string $no, int $supplier, string $status, ?string $promised = null, int $fy = 6, string $total = '1000'): int
{
    return (int) Db::insert('purchase_orders', [
        'cmp_id' => 88, 'fy_id' => $fy, 'po_no' => $no, 'po_date' => '2026-09-01', 'supplier_account_id' => $supplier,
        'supplier_name_snapshot' => 'Supplier ' . $supplier, 'status' => $status, 'promised_date' => $promised,
        'total_amount' => $total, 'created_by' => 'user-owner',
    ], 'po_id');
}

function seed(): array
{
    reset();
    @unlink(sys_get_temp_dir() . '/stub-control.json');

    return [
        'late'      => order('PO-1', 601, 'ISSUED', '2026-01-05', 6, '1500.50'),
        'pending'   => order('PO-2', 601, 'APPROVAL_PENDING', '2099-01-01'),
        'partial'   => order('PO-3', 601, 'PARTIALLY_RECEIVED'),
        'draft'     => order('PO-4', 601, 'DRAFT'),
        'closed'    => order('PO-5', 601, 'CLOSED'),
        'cancelled' => order('PO-6', 601, 'CANCELLED'),
        'other'     => order('PO-7', 602, 'ISSUED'),
        'otherYear' => order('PO-8', 601, 'ISSUED', null, 5),
    ];
}

echo "\nSupplier position (v1/suppliers/{id}/position)\n";

check('the owner: open orders of this supplier in this year only, overdue first, values and Books dues', function () {
    $po = seed();
    [$status, $body] = position(person('user-owner', 1), 601);
    same(200, $status, 'answered');
    $data = $body['data'];
    same(601, $data['supplier_account_id'], 'supplier');
    same('Supplier 601', $data['supplier_name'], 'the name from the order snapshot');
    same([$po['late'], $po['pending'], $po['partial']], array_map(static fn ($o) => $o['po_id'], $data['open_orders']), 'open orders, by promised date');
    same(3, $data['open_order_count'], 'count');
    same(true, $data['open_orders'][0]['overdue'], 'past its promised date while issued');
    same(false, $data['open_orders'][1]['overdue'], 'awaiting approval is never overdue');
    same('1500.5', rtrim(rtrim((string) $data['open_orders'][0]['total_amount'], '0'), '.'), 'value shown');
    same([], $data['withheld'], 'nothing withheld from the owner');

    $dues = $data['dues'];
    same(true, $dues['available'], 'Books answered');
    same(3, $dues['bill_count'], 'the settled bill and the ON ACCOUNT row are not dues');
    same(0, bccomp_safe($dues['net_payable'], '175000.75'), 'net payable = 120000.50 + 45000.25 + 10000');
    same(0, bccomp_safe($dues['overdue'], '120000.5'), 'only the bill past its due date is overdue');
});

check('a member with supplier.view and po.view: orders without values, dues withheld and named', function () {
    seed();
    [$status, $body] = position(member('user-buyer', ['supplier.view', 'po.view']), 601);
    same(200, $status, 'answered');
    same(null, $body['data']['open_orders'][0]['total_amount'], 'no value without cost.view');
    same(false, $body['data']['dues']['available'], 'no dues');
    same(['cost.view'], $body['data']['withheld'], 'named');
});

check('a member with supplier.view only: the orders are withheld too, not shown empty', function () {
    seed();
    [$status, $body] = position(member('user-viewer', ['supplier.view', 'reports.view']), 601);
    same(200, $status, 'answered');
    same(null, $body['data']['open_orders'], 'orders withheld');
    same(null, $body['data']['open_order_count'], 'count withheld');
    same(['po.view'], $body['data']['withheld'], 'named');
    same(true, $body['data']['dues']['available'], 'reports.view sees the dues');
});

check('a member without supplier.view is refused', function () {
    seed();
    [$status] = position(member('user-receiver', ['receipt.request', 'po.view']), 601);
    same(403, $status, 'refused');
});

check('Books unreachable: the dues say so (retryable), the orders still answer', function () {
    seed();
    file_put_contents(sys_get_temp_dir() . '/stub-control.json', json_encode(['path' => 'reports/bill-by-bill', 'status' => 503]));
    try {
        [$status, $body] = position(person('user-owner', 1), 601);
    } finally {
        @unlink(sys_get_temp_dir() . '/stub-control.json');
    }
    same(200, $status, 'answered');
    same(false, $body['data']['dues']['available'], 'not available');
    same(true, $body['data']['dues']['retryable'], 'retryable');
    same(3, count($body['data']['open_orders']), 'orders listed');
});

check('a supplier with nothing open: an empty list, not an error', function () {
    seed();
    [$status, $body] = position(person('user-owner', 1), 999);
    same(200, $status, 'answered');
    same([], $body['data']['open_orders'], 'empty');
    same(0, $body['data']['open_order_count'], 'zero');
    same(null, $body['data']['profile'], 'no procurement profile');
});

function bccomp_safe(string $a, string $b): int
{
    $x = round((float) $a, 4);
    $y = round((float) $b, 4);

    return $x <=> $y;
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
