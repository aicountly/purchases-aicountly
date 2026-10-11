<?php

declare(strict_types=1);

/**
 * Launch readiness 2026-10 (package E8-PUR), proved against real PostgreSQL and the stub.
 *
 * Each check is a finding of the October 2026 inspection (A09, A13, A26, A31, F007 ...),
 * written so that it fails on the code before the correction. Cross-product contracts are
 * proved again against the REAL receivers by tests/contract_receivers.php.
 *
 *   server-php/tests/run.sh
 */

namespace Aicountly\Api;

require __DIR__ . '/../src/Env.php';
require __DIR__ . '/../src/Autoload.php';

Env::load(__DIR__ . '/../.env');

use Aicountly\Api\Domain\BillService;
use Aicountly\Api\Domain\ClaimResolutionService;
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

function profile(string $uuid, string $name, array $permissions, int $cmpId = 88): void
{
    $id = (int) Db::insert('purchase_permission_profiles', ['cmp_id' => $cmpId, 'profile_name' => $name, 'permissions' => $permissions, 'is_active' => true], 'profile_id');
    Db::run('INSERT INTO purchase_permission_assignments (cmp_id, user_uuid, profile_id) VALUES (:c, :u, :p)', ['c' => $cmpId, 'u' => $uuid, 'p' => $id]);
}

/** A member who is not the owner (Manage access type 3), holding the named permissions. */
function member(string $uuid, array $permissions): Auth
{
    profile($uuid, 'Profile of ' . $uuid, $permissions);
    Permissions::forget();

    return person($uuid, 3);
}

function bill(Context $ctx, Auth $auth, int $poId, array $lines, string $invoice = 'INV-1', array $extra = []): array
{
    return (new BillService($ctx, $auth))->enter($extra + [
        'supplier_account_id' => 601, 'po_id' => $poId, 'supplier_invoice_no' => $invoice, 'supplier_invoice_date' => '2026-09-19',
        'lines' => $lines,
    ]);
}

/** @return list<array<string, mixed>> exceptions of the bill's latest match */
function latestExceptions(int $billId): array
{
    $match = (int) Db::scalar('SELECT match_id FROM purchase_match_results WHERE bill_request_id = :b ORDER BY match_id DESC LIMIT 1', ['b' => $billId]);

    return Db::all('SELECT * FROM purchase_match_exceptions WHERE match_id = :m ORDER BY exception_id', ['m' => $match]);
}

function vouchersPosted(): int
{
    return count(booksVouchers());
}

$ctx = ctx();
$owner = person();

// ---------------------------------------------------------------------------
echo "\nA26 / A31 / F228 / F265 / F497 — match decisions bind\n";

check('A26: rejecting an exception stops the bill — Post is refused and nothing reaches Books; only Revise or Cancel remain', function () use ($ctx, $owner) {
    reset();
    $po = orderOf($ctx, $owner);
    $lineId = (int) $po['lines'][0]['line_id'];
    receive($ctx, $owner, (int) $po['po_id'], $lineId, 10);
    $clerk = member('user-clerk', ['bill.enter', 'bill.post', 'match.view']);
    $b = bill($ctx, $clerk, (int) $po['po_id'], [['po_line_id' => $lineId, 'qty' => 10, 'rate' => 300]]);
    same('EXCEPTION', $b['status'], 'billed above the agreed rate');
    $exception = latestExceptions((int) $b['request_id'])[0];
    same('rate', $exception['exception_kind'], 'a rate exception');

    $after = (new BillService($ctx, $owner))->resolveException((int) $exception['exception_id'], 'reject', ['note' => 'The supplier agreed 250.']);
    same('REJECTED', $after['status'], 'the bill is rejected');
    $before = vouchersPosted();
    $r = refused(fn () => (new BillService($ctx, $owner))->post((int) $b['request_id']), 'match_exception_rejected', 'posting a rejected bill');
    same(409, $r['status'], '409');
    same($before, vouchersPosted(), 'nothing was sent to Books');
    refused(fn () => (new BillService($ctx, $clerk))->rematch((int) $b['request_id']), 'Revise the bill', 'a rejected bill is not matched again as it stands');

    // Revised to the agreed rate: a new revision, matched afresh, and posts.
    $revised = (new BillService($ctx, $clerk))->revise((int) $b['request_id'], ['lines' => [['po_line_id' => $lineId, 'qty' => 10, 'rate' => 250]], 'note' => 'Corrected to the agreed rate.']);
    same('MATCHED', $revised['status'], 'the revision matches');
    same(1, (int) $revised['revision'], 'as revision 1');
    same('POSTED', (new BillService($ctx, $owner))->post((int) $b['request_id'])['status'], 'and posts');
});

check('A26: accepting still allows the post; a revision brings fresh OPEN exceptions, not the old decision', function () use ($ctx, $owner) {
    reset();
    $po = orderOf($ctx, $owner);
    $lineId = (int) $po['lines'][0]['line_id'];
    receive($ctx, $owner, (int) $po['po_id'], $lineId, 10);
    $clerk = member('user-clerk', ['bill.enter', 'match.view']);
    $b = bill($ctx, $clerk, (int) $po['po_id'], [['po_line_id' => $lineId, 'qty' => 10, 'rate' => 260]]);
    $exception = latestExceptions((int) $b['request_id'])[0];
    $accepted = (new BillService($ctx, $owner))->resolveException((int) $exception['exception_id'], 'accept', ['note' => 'Freight surcharge agreed by phone.']);
    same('MATCHED', $accepted['status'], 'every exception decided: ready to post');
    same('POSTED', (new BillService($ctx, $owner))->post((int) $b['request_id'])['status'], 'an accepted variance posts');

    // A bill Books refuses is revised; its new revision is matched afresh.
    reset();
    $po = orderOf($ctx, $owner);
    $lineId = (int) $po['lines'][0]['line_id'];
    receive($ctx, $owner, (int) $po['po_id'], $lineId, 10);
    $clerk = member('user-clerk', ['bill.enter', 'match.view']);
    $b = bill($ctx, $clerk, (int) $po['po_id'], [['po_line_id' => $lineId, 'qty' => 10, 'rate' => 260]], 'INV-2');
    $exception = latestExceptions((int) $b['request_id'])[0];
    (new BillService($ctx, $owner))->resolveException((int) $exception['exception_id'], 'reject', ['note' => 'No.']);
    $revised = (new BillService($ctx, $clerk))->revise((int) $b['request_id'], ['lines' => [['po_line_id' => $lineId, 'qty' => 10, 'rate' => 270]]]);
    same('EXCEPTION', $revised['status'], 'a still-higher revision is an exception again');
    same(['OPEN'], array_column(latestExceptions((int) $b['request_id']), 'status'), 'fresh and OPEN — the rejection of revision 0 does not decide revision 1');
});

check('A31: a bill at the gross rate against a 10%-discounted order line raises an exception at 0% tolerance', function () use ($ctx, $owner) {
    reset();
    $po = orderOf($ctx, $owner, [['item_id' => 201, 'unit_id' => 1, 'ordered_qty' => 10, 'agreed_rate' => 200, 'discount_pc' => 10, 'warehouse_id' => 3]]);
    $lineId = (int) $po['lines'][0]['line_id'];
    receive($ctx, $owner, (int) $po['po_id'], $lineId, 10);
    $gross = bill($ctx, $owner, (int) $po['po_id'], [['po_line_id' => $lineId, 'qty' => 10, 'rate' => 200]], 'GROSS-1');
    same('BLOCKED', $gross['match']['verdict'], 'billed 2000 against an agreed net 1800');
    same('rate', $gross['match']['exceptions'][0]['exception_kind'] ?? null, 'as a rate exception on the net price');
    same(180.0, (float) $gross['match']['exceptions'][0]['po_value'], 'compared with the net agreed rate (180)');

    $net = bill($ctx, $owner, (int) $po['po_id'], [['po_line_id' => $lineId, 'qty' => 10, 'rate' => 200, 'discount_pc' => 10]], 'NET-1');
    same('MATCHED', $net['match']['verdict'], 'the same discount on the bill matches');
});

check('A31: two bill lines on one order line are aggregated; an inflated line is caught in either order', function () use ($ctx, $owner) {
    foreach ([[[6, 300], [4, 250]], [[4, 250], [6, 300]]] as $round => $pair) {
        reset();
        $po = orderOf($ctx, $owner);
        $lineId = (int) $po['lines'][0]['line_id'];
        receive($ctx, $owner, (int) $po['po_id'], $lineId, 10);
        $b = bill($ctx, $owner, (int) $po['po_id'], [
            ['po_line_id' => $lineId, 'qty' => $pair[0][0], 'rate' => $pair[0][1]],
            ['po_line_id' => $lineId, 'qty' => $pair[1][0], 'rate' => $pair[1][1]],
        ], 'SPLIT-' . $round);
        same('BLOCKED', $b['match']['verdict'], 'round ' . $round . ': the inflated line is found');
    }
    // Quantity is judged on the aggregate: 6 + 6 against 10 received is over-billed.
    reset();
    $po = orderOf($ctx, $owner);
    $lineId = (int) $po['lines'][0]['line_id'];
    receive($ctx, $owner, (int) $po['po_id'], $lineId, 10);
    $b = bill($ctx, $owner, (int) $po['po_id'], [
        ['po_line_id' => $lineId, 'qty' => 6, 'rate' => 250],
        ['po_line_id' => $lineId, 'qty' => 6, 'rate' => 250],
    ], 'SPLIT-Q');
    same('over_billed', $b['match']['exceptions'][0]['exception_kind'] ?? null, '12 billed against 10 received');
    // Billed down still passes.
    reset();
    $po = orderOf($ctx, $owner);
    $lineId = (int) $po['lines'][0]['line_id'];
    receive($ctx, $owner, (int) $po['po_id'], $lineId, 10);
    same('WITHIN_TOLERANCE', bill($ctx, $owner, (int) $po['po_id'], [['po_line_id' => $lineId, 'qty' => 10, 'rate' => 240]], 'DOWN')['match']['verdict'], 'a supplier charging less is noted and passed');
});

check('F228: a cancelled bill cannot be re-matched back to life or posted, and post refuses anything with cancelled_at', function () use ($ctx, $owner) {
    reset();
    $po = orderOf($ctx, $owner);
    $lineId = (int) $po['lines'][0]['line_id'];
    receive($ctx, $owner, (int) $po['po_id'], $lineId, 10);
    $b = bill($ctx, $owner, (int) $po['po_id'], [['po_line_id' => $lineId, 'qty' => 10, 'rate' => 250]]);
    (new BillService($ctx, $owner))->cancel((int) $b['request_id'], ['reason' => 'Entered twice.']);
    $r = refused(fn () => (new BillService($ctx, $owner))->rematch((int) $b['request_id']), 'cancelled', 'rematch of a cancelled bill');
    same(409, $r['status'], '409');
    same('CANCELLED', Db::scalar('SELECT status FROM purchase_bill_requests WHERE request_id = :id', ['id' => (int) $b['request_id']]), 'the status is unchanged');
    // Even a row whose status was put back by hand is never posted.
    Db::run("UPDATE purchase_bill_requests SET status = 'MATCHED' WHERE request_id = :id", ['id' => (int) $b['request_id']]);
    $before = vouchersPosted();
    refused(fn () => (new BillService($ctx, $owner))->post((int) $b['request_id']), 'cancelled', 'post of a row with cancelled_at');
    same($before, vouchersPosted(), 'nothing reached Books');
    foreach (['POSTING', 'UNCERTAIN', 'BLOCKED', 'POSTED'] as $status) {
        Db::run('UPDATE purchase_bill_requests SET status = :s, cancelled_at = NULL WHERE request_id = :id', ['s' => $status, 'id' => (int) $b['request_id']]);
        refused(fn () => (new BillService($ctx, $owner))->rematch((int) $b['request_id']), '', 'rematch of a ' . $status . ' bill');
        same($status, Db::scalar('SELECT status FROM purchase_bill_requests WHERE request_id = :id', ['id' => (int) $b['request_id']]), $status . ' is left as it was');
    }
});

check('F497: re-running the match needs bill.enter, and never reopens an accepted variance on the same revision', function () use ($ctx, $owner) {
    reset();
    $po = orderOf($ctx, $owner);
    $lineId = (int) $po['lines'][0]['line_id'];
    receive($ctx, $owner, (int) $po['po_id'], $lineId, 10);
    $b = bill($ctx, $owner, (int) $po['po_id'], [['po_line_id' => $lineId, 'qty' => 10, 'rate' => 260]]);
    $viewer = member('user-viewer', ['match.view', 'po.view']);
    $r = refused(fn () => (new BillService($ctx, $viewer))->rematch((int) $b['request_id']), 'permission', 'a viewer re-running the match');
    same(403, $r['status'], '403');

    $exception = latestExceptions((int) $b['request_id'])[0];
    $approver = member('user-approver', ['match.resolve']);
    (new BillService($ctx, $approver))->resolveException((int) $exception['exception_id'], 'accept', ['note' => 'Agreed.']);
    $clerk = member('user-clerk', ['bill.enter']);
    $again = (new BillService($ctx, $clerk))->rematch((int) $b['request_id']);
    same(['ACCEPTED'], array_column(latestExceptions((int) $b['request_id']), 'status'), 'the identical finding keeps its acceptance');
    same((int) $exception['exception_id'], (int) latestExceptions((int) $b['request_id'])[0]['carried_from_exception_id'], 'and says where it came from');
    same('MATCHED', $again['status'], 'so the bill stays ready to post');
    // An exception of an earlier match cannot be decided any more.
    Db::run("UPDATE purchase_match_exceptions SET status = 'OPEN' WHERE exception_id = :id", ['id' => (int) $exception['exception_id']]);
    refused(fn () => (new BillService($ctx, $approver))->resolveException((int) $exception['exception_id'], 'reject', ['note' => 'late']), 'earlier match', 'deciding a superseded exception');
});

check('F265: nobody accepts a variance on a bill they entered; a financial-adjustment resolution needs return.financial_adjustment', function () use ($ctx, $owner) {
    reset();
    $po = orderOf($ctx, $owner);
    $lineId = (int) $po['lines'][0]['line_id'];
    receive($ctx, $owner, (int) $po['po_id'], $lineId, 10);
    $clerk = member('user-clerk', ['bill.enter', 'match.resolve', 'bill.post']);
    $b = bill($ctx, $clerk, (int) $po['po_id'], [['po_line_id' => $lineId, 'qty' => 10, 'rate' => 260]]);
    $exception = latestExceptions((int) $b['request_id'])[0];
    $r = refused(fn () => (new BillService($ctx, $clerk))->resolveException((int) $exception['exception_id'], 'accept', ['note' => 'Mine, fine.']), 'sod_self_decision', 'self-accepting');
    same(403, $r['status'], '403');
    // The owner may, saying why (owner_with_reason); under strict nobody may.
    $ownBill = bill($ctx, $owner, (int) $po['po_id'], [['po_line_id' => $lineId, 'qty' => 10, 'rate' => 260]], 'INV-OWN');
    Db::run("UPDATE purchase_settings SET sod_policy = 'strict' WHERE cmp_id = 88");
    refused(fn () => (new BillService($ctx, $owner))->resolveException((int) latestExceptions((int) $ownBill['request_id'])[0]['exception_id'], 'accept', ['note' => 'Owner.']), 'sod_self_decision', 'strict: not even the owner');
    Db::run("UPDATE purchase_settings SET sod_policy = 'owner_with_reason' WHERE cmp_id = 88");

    // Claim: approving one's own claim, and a financial adjustment without the authority.
    $claims = new Domain\ReturnClaimService($ctx, $clerk);
    $settler = member('user-settler', ['claim.create', 'claim.settle']);
    $claim = (new Domain\ReturnClaimService($ctx, $settler))->createClaim(['supplier_account_id' => 601, 'claim_kind' => 'damage', 'claimed_amount' => 500]);
    (new Domain\ReturnClaimService($ctx, $settler))->updateClaim((int) $claim['claim_id'], 'submit', []);
    refused(fn () => (new Domain\ReturnClaimService($ctx, $settler))->updateClaim((int) $claim['claim_id'], 'approve', []), 'sod_self_decision', 'approving one\'s own claim');
    (new Domain\ReturnClaimService($ctx, $owner))->updateClaim((int) $claim['claim_id'], 'approve', []);
    $proposer = member('user-proposer', ['claim.create']);
    $res = (new Domain\ClaimResolutionService($ctx, $proposer))->propose((int) $claim['claim_id'], ['kind' => 'financial_adjustment', 'amount' => 500, 'adjustment_acc_id' => 701]);
    $r = refused(fn () => (new Domain\ClaimResolutionService($ctx, $settler))->approve((int) $res['resolution_id']), 'permission', 'claim.settle alone');
    same(403, $r['status'], 'a debit note with nothing going back needs return.financial_adjustment');
    unset($claims);
});

echo "\n" . str_repeat('-', 60) . "\n";
echo "{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
