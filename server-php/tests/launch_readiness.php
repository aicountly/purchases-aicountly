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

/** Any stub control: path, status, after, code, message, html, bare. */
function stubControl(array $control): void
{
    file_put_contents(sys_get_temp_dir() . '/stub-control.json', json_encode($control));
}

function receiptRow(int $requestId): array
{
    return Db::first('SELECT * FROM purchase_receipt_requests WHERE request_id = :id', ['id' => $requestId]) ?? [];
}

function receiptCommand(int $requestId): array
{
    return IntegrationCommand::find(88, ReceiptService::COMMAND_RECEIPT, 'receipt_request', $requestId) ?? [];
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

// ---------------------------------------------------------------------------
echo "\nF007 / F283 / F212 / F234 / F301 — what an answer proves\n";

check('F283: classify() — 5xx after a write is UNCERTAIN, a pre-write gate or stock_busy is FAILED, a non-JSON 2xx proves nothing', function () {
    $r = static fn (bool $ok, int $status, ?array $body = null, ?string $error = null) => ['ok' => $ok, 'status' => $status, 'body' => $body, 'error' => $error];
    $err = static fn (string $code, array $details = []) => ['error' => ['code' => $code, 'message' => 'x'] + ($details === [] ? [] : ['details' => $details])];
    $table = [
        ['completed', $r(true, 200, ['data' => []])],
        ['uncertain', $r(true, 200, null)],                                   // splash page / WAF
        ['uncertain', $r(false, 0)],                                          // no answer
        ['uncertain', $r(false, 504, $err('gateway_timeout'))],
        ['uncertain', $r(false, 500, $err('internal_error'))],                // fatal after commit
        ['uncertain', $r(false, 500, $err('books_handoff_unresolved'))],      // Inventory committed, Books did not
        ['uncertain', $r(false, 502, null, 'HTTP 502')],                      // gateway HTML
        ['uncertain', $r(false, 503, null, 'HTTP 503')],                      // bare 503
        ['failed', $r(false, 503, $err('company_access_unavailable'))],      // gate before any write
        ['failed', $r(false, 409, $err('stock_busy', ['retryable' => true]))], // F212: nothing changed
        ['failed', $r(false, 409, $err('some_lock', ['retryable' => true]))],
        ['uncertain', $r(false, 409, $err('request_in_progress'))],
        ['uncertain', $r(false, 409, ['status' => 409, 'error' => 409, 'messages' => ['error' => 'This voucher is already being posted. Refresh in a moment and try again.']], 'This voucher is already being posted. Refresh in a moment and try again.')],
        ['failed', $r(false, 401, $err('unauthorised'))],
        ['failed', $r(false, 403, $err('forbidden'))],
        ['failed', $r(false, 429, $err('rate_limited'))],
        ['blocked', $r(false, 409, $err('conflict'))],
        ['blocked', $r(false, 422, $err('period_locked'))],
        ['blocked', $r(false, 409, ['status' => 409, 'error' => 409, 'messages' => ['error' => 'Duplicate supplier invoice']], 'Duplicate supplier invoice')],
    ];
    foreach ($table as $i => [$expected, $response]) {
        same($expected, IntegrationCommand::classify($response), 'row ' . $i . ' (' . $response['status'] . ' ' . json_encode($response['body']) . ')');
    }
});

check('F007: Inventory commits the GRN and answers 500 — the receipt is UNCERTAIN, cannot be withdrawn, and Reconcile counts it once', function () use ($ctx, $owner) {
    reset();
    $po = orderOf($ctx, $owner);
    $lineId = (int) $po['lines'][0]['line_id'];
    stubControl(['path' => 'inventory-documents/post', 'status' => 500, 'after' => true, 'code' => 'books_handoff_unresolved', 'message' => 'Recorded; the Books hand-off did not finish.']);
    $r = refused(fn () => receive($ctx, $owner, (int) $po['po_id'], $lineId, 10), 'may have been recorded', 'a 500 after commit');
    stubRecover();
    same('inventory_uncertain', $r['code'], 'reported as not confirmed, never "nothing received"');
    $requestId = (int) Db::scalar('SELECT request_id FROM purchase_receipt_requests ORDER BY request_id DESC LIMIT 1');
    same('UNCERTAIN', receiptRow($requestId)['status'], 'the receipt says so');
    refused(fn () => (new ReceiptService($ctx, $owner))->cancel($requestId, ['reason' => 'Try again']), 'Reconcile it first', 'Withdraw while the outcome is unknown');
    $after = (new ReceiptService($ctx, $owner))->reconcile($requestId);
    same('10.0000', (string) $after['lines'][0]['received_qty'], 'counted once from what Inventory holds');
    same(1, count(inventoryState()['by_id']), 'one GRN in Inventory');
});

check('F007: a receipt recorded FAILED under the old rules while Inventory holds its GRN is not withdrawn — it is counted once instead', function () use ($ctx, $owner) {
    reset();
    $po = orderOf($ctx, $owner);
    $lineId = (int) $po['lines'][0]['line_id'];
    stubControl(['path' => 'inventory-documents/post', 'status' => 500, 'after' => true]);
    refused(fn () => receive($ctx, $owner, (int) $po['po_id'], $lineId, 10), '', 'the lost answer');
    stubRecover();
    $requestId = (int) Db::scalar('SELECT request_id FROM purchase_receipt_requests ORDER BY request_id DESC LIMIT 1');
    // What the classification before October 2026 left behind: a 5xx recorded as FAILED.
    Db::run("UPDATE purchase_integration_commands SET status = 'FAILED' WHERE entity_type = 'receipt_request' AND entity_id = :id", ['id' => $requestId]);
    Db::run("UPDATE purchase_receipt_requests SET status = 'FAILED' WHERE request_id = :id", ['id' => $requestId]);
    $r = refused(fn () => (new ReceiptService($ctx, $owner))->cancel($requestId, ['reason' => 'Supplier will resend']), 'receipt_in_inventory', 'withdrawing a receipt Inventory holds');
    same(409, $r['status'], '409');
    same('ACCEPTED', receiptRow($requestId)['status'], 'it is counted on the order now');
    same('COMPLETED', receiptCommand($requestId)['status'], 'and its command is settled by reconcile');
    same('10.0000', (string) Db::scalar('SELECT received_qty FROM purchase_order_lines WHERE line_id = :id', ['id' => $lineId]), 'once');
    // Inventory unreadable: nothing is withdrawn either.
    reset();
    $po = orderOf($ctx, $owner);
    stubControl(['path' => 'inventory-documents/post', 'status' => 503, 'code' => 'company_access_unavailable']);
    refused(fn () => receive($ctx, $owner, (int) $po['po_id'], (int) $po['lines'][0]['line_id'], 5), 'press Retry', 'a gate refusal');
    $requestId = (int) Db::scalar('SELECT request_id FROM purchase_receipt_requests ORDER BY request_id DESC LIMIT 1');
    stubControl(['path' => 'inventory-documents/by-source', 'status' => 200, 'html' => true]);
    same(503, refused(fn () => (new ReceiptService($ctx, $owner))->cancel($requestId, ['reason' => 'x']), 'could not confirm', 'by-source answered HTML')['status'], 'unreadable: 503');
    stubRecover();
    same('CANCELLED', (new ReceiptService($ctx, $owner))->cancel($requestId, ['reason' => 'Supplier will resend'])['receipt']['status'], 'Inventory says it holds nothing: withdrawn');
});

check('F234: Reconcile against a splash page or a bare 404 leaves the receipt UNCERTAIN and answers 503; Inventory\'s own not_found sets FAILED', function () use ($ctx, $owner) {
    reset();
    $po = orderOf($ctx, $owner);
    stubControl(['path' => 'inventory-documents/post', 'status' => 504]);
    refused(fn () => receive($ctx, $owner, (int) $po['po_id'], (int) $po['lines'][0]['line_id'], 5), '', 'timeout');
    $requestId = (int) Db::scalar('SELECT request_id FROM purchase_receipt_requests ORDER BY request_id DESC LIMIT 1');
    foreach ([['status' => 200, 'html' => true], ['status' => 404, 'bare' => true], ['status' => 502, 'html' => true]] as $shape) {
        stubControl(['path' => 'inventory-documents/by-source'] + $shape);
        same(503, refused(fn () => (new ReceiptService($ctx, $owner))->reconcile($requestId), 'cannot be read', json_encode($shape))['status'], json_encode($shape) . ' is unreadable');
        same('UNCERTAIN', receiptRow($requestId)['status'], json_encode($shape) . ' leaves it UNCERTAIN');
    }
    stubRecover();
    same('FAILED', (new ReceiptService($ctx, $owner))->reconcile($requestId)['receipt']['status'], 'Inventory\'s JSON 404 not_found: never recorded, Retry will send it');
});

check('F212: Inventory\'s 409 stock_busy leaves the receipt retryable (FAILED, not BLOCKED), and a retry on the same key records it', function () use ($ctx, $owner) {
    reset();
    $po = orderOf($ctx, $owner);
    stubControl(['path' => 'inventory-documents/post', 'status' => 409, 'code' => 'stock_busy', 'message' => 'This document is being changed by another operation right now. Nothing was changed — try again in a moment.']);
    $r = refused(fn () => receive($ctx, $owner, (int) $po['po_id'], (int) $po['lines'][0]['line_id'], 5), 'press Retry', 'a busy lock');
    stubRecover();
    $requestId = (int) Db::scalar('SELECT request_id FROM purchase_receipt_requests ORDER BY request_id DESC LIMIT 1');
    same('FAILED', receiptCommand($requestId)['status'], 'retryable');
    $key = receiptCommand($requestId)['idempotency_key'];
    $after = (new ReceiptService($ctx, $owner))->retry($requestId);
    same('5.0000', (string) $after['lines'][0]['received_qty'], 'recorded on retry');
    same($key, receiptCommand($requestId)['idempotency_key'], 'on the same key');
    unset($r);
});

check('F007 (bills): Books posts and answers 500 — the bill is UNCERTAIN, cannot be cancelled, and Retry settles it from the one voucher', function () use ($ctx, $owner) {
    reset();
    $po = orderOf($ctx, $owner);
    $lineId = (int) $po['lines'][0]['line_id'];
    receive($ctx, $owner, (int) $po['po_id'], $lineId, 10);
    $b = bill($ctx, $owner, (int) $po['po_id'], [['po_line_id' => $lineId, 'qty' => 10, 'rate' => 250]]);
    stubControl(['path' => '/post', 'status' => 500, 'after' => true]);
    refused(fn () => (new BillService($ctx, $owner))->post((int) $b['request_id']), 'may have been posted', 'a 500 after Books posted');
    stubRecover();
    same('UNCERTAIN', Db::scalar('SELECT status FROM purchase_bill_requests WHERE request_id = :id', ['id' => (int) $b['request_id']]), 'UNCERTAIN');
    refused(fn () => (new BillService($ctx, $owner))->cancel((int) $b['request_id'], ['reason' => 'x']), 'Retry it to learn', 'cancel while the outcome is unknown');
    same('POSTED', (new BillService($ctx, $owner))->post((int) $b['request_id'])['status'], 'Retry replays the post');
    same(1, vouchersPosted(), 'one voucher');

    // A bill FAILED under the old rules while Books holds its voucher is not cancelled.
    reset();
    $po = orderOf($ctx, $owner);
    $lineId = (int) $po['lines'][0]['line_id'];
    receive($ctx, $owner, (int) $po['po_id'], $lineId, 10);
    $b = bill($ctx, $owner, (int) $po['po_id'], [['po_line_id' => $lineId, 'qty' => 10, 'rate' => 250]], 'LEGACY-1');
    stubControl(['path' => '/post', 'status' => 500, 'after' => true]);
    refused(fn () => (new BillService($ctx, $owner))->post((int) $b['request_id']), '', 'lost answer');
    stubRecover();
    Db::run("UPDATE purchase_integration_commands SET status = 'FAILED' WHERE entity_type = 'bill_request'");
    Db::run("UPDATE purchase_bill_requests SET status = 'FAILED' WHERE request_id = :id", ['id' => (int) $b['request_id']]);
    $r = refused(fn () => (new BillService($ctx, $owner))->cancel((int) $b['request_id'], ['reason' => 'Entered twice']), 'books_holds_bill', 'cancelling a bill Books holds');
    same(409, $r['status'], '409');
    same('POSTED', (new BillService($ctx, $owner))->post((int) $b['request_id'])['status'], 'posting again settles it from the voucher Books recorded');
    same(1, vouchersPosted(), 'still one voucher');
});

check('F301: a receipt left POSTING by a dead attempt is offered Reconcile and Retry once its lease has run out, and resolves', function () use ($ctx, $owner) {
    reset();
    $po = orderOf($ctx, $owner);
    $lineId = (int) $po['lines'][0]['line_id'];
    stubControl(['path' => 'inventory-documents/post', 'status' => 504, 'after' => true]);
    refused(fn () => receive($ctx, $owner, (int) $po['po_id'], $lineId, 7), '', 'lost');
    stubRecover();
    $requestId = (int) Db::scalar('SELECT request_id FROM purchase_receipt_requests ORDER BY request_id DESC LIMIT 1');
    // The worker died between claiming the command and hearing back: POSTING with a lease.
    Db::run("UPDATE purchase_integration_commands SET status = 'POSTING', lease_token = 'dead', lease_expires_at = NOW() + INTERVAL '60 seconds' WHERE entity_type = 'receipt_request'");
    Db::run("UPDATE purchase_receipt_requests SET status = 'POSTING' WHERE request_id = :id", ['id' => $requestId]);
    $view = (new Domain\PurchaseOrderService($ctx, $owner))->find((int) $po['po_id']);
    same(false, $view['receipts'][0]['recoverable'], 'not while the lease is live');
    Db::run("UPDATE purchase_integration_commands SET lease_expires_at = NOW() - INTERVAL '1 second' WHERE entity_type = 'receipt_request'");
    $view = (new Domain\PurchaseOrderService($ctx, $owner))->find((int) $po['po_id']);
    same(true, $view['receipts'][0]['recoverable'], 'offered once the lease has run out');
    $after = (new ReceiptService($ctx, $owner))->reconcile($requestId);
    same('7.0000', (string) $after['lines'][0]['received_qty'], 'Reconcile finds the GRN and counts it once');
});

// ---------------------------------------------------------------------------
echo "\nA09 / F010 — a direct bill over goods already received\n";

function directBill(Context $ctx, Auth $auth, string $invoice, float $qty, array $extra = []): array
{
    return (new BillService($ctx, $auth))->enter($extra + [
        'supplier_account_id' => 601, 'supplier_invoice_no' => $invoice, 'supplier_invoice_date' => '2026-09-19',
        'lines' => [['item_id' => 201, 'unit_id' => 1, 'warehouse_id' => 3, 'qty' => $qty, 'rate' => 250]],
    ]);
}

function physicalGrns(): void
{
    Db::run('INSERT INTO purchase_settings (cmp_id, receive_stock_at_grn) VALUES (88, TRUE) ON CONFLICT (cmp_id) DO UPDATE SET receive_stock_at_grn = TRUE');
}

check('A09: a direct bill for goods an unbilled receipt of the supplier already brought in is refused, naming the receipt and its order', function () use ($ctx, $owner) {
    reset();
    physicalGrns();
    $po = orderOf($ctx, $owner);
    receive($ctx, $owner, (int) $po['po_id'], (int) $po['lines'][0]['line_id'], 10);
    $r = refused(fn () => directBill($ctx, $owner, 'DIRECT-1', 10), 'open_goods_receipts', 'a direct bill over the GRN');
    same(409, $r['status'], '409');
    truthy(str_contains($r['message'], (string) $po['po_no']), 'it says which order to bill instead: ' . $r['message']);
    same(0, (int) Db::scalar("SELECT COUNT(*) FROM purchase_bill_requests WHERE supplier_invoice_no = 'DIRECT-1'"), 'nothing was entered');
    // The bill against the order settles the GRN and receives nothing again.
    $b = bill($ctx, $owner, (int) $po['po_id'], [['po_line_id' => (int) $po['lines'][0]['line_id'], 'qty' => 10, 'rate' => 250]], 'DIRECT-1');
    $posted = (new BillService($ctx, $owner))->post((int) $b['request_id']);
    same('from_challan', $posted['stock_effect'], 'settles the receipt');
    same(1, vouchersPosted(), 'one voucher');
});

check('A09: confirmed different goods are entered with the confirmation, shown to the reviewer, and carried to Books and Inventory', function () use ($ctx, $owner) {
    reset();
    physicalGrns();
    $po = orderOf($ctx, $owner);
    receive($ctx, $owner, (int) $po['po_id'], (int) $po['lines'][0]['line_id'], 10);
    refused(fn () => directBill($ctx, $owner, 'COUNTER-1', 4, ['confirm_no_open_grn' => true, 'confirm_no_open_grn_reason' => 'short']), 'Say in a sentence', 'a reason that says nothing');
    $b = directBill($ctx, $owner, 'COUNTER-1', 4, ['confirm_no_open_grn' => true, 'confirm_no_open_grn_reason' => 'Bought at the trade counter, not the PO delivery']);
    $kept = Db::jsonColumn(Db::scalar('SELECT open_grn_confirmation FROM purchase_bill_requests WHERE request_id = :id', ['id' => (int) $b['request_id']]));
    same(true, $kept['confirmed'], 'kept on the bill');
    same('user-owner', $kept['confirmed_by'], 'by whom');
    same((string) $po['po_no'], $kept['receipts'][0]['po_no'], 'against which receipts');
    $exception = latestExceptions((int) $b['request_id'])[0];
    truthy(str_contains((string) $exception['detail'], 'trade counter'), 'the reviewer sees the decision: ' . $exception['detail']);
    $approver = member('user-approver', ['match.resolve']);
    (new BillService($ctx, $approver))->resolveException((int) $exception['exception_id'], 'accept', ['note' => 'Checked the counter receipt.']);
    same('POSTED', (new BillService($ctx, $owner))->post((int) $b['request_id'])['status'], 'posts');
    $sent = IntegrationCommand::latest(88, BillService::COMMAND_BILL, 'bill_request', (int) $b['request_id'])['request_payload'];
    same(true, $sent['confirm_no_open_grn'] ?? null, 'Books is told');
    same('Bought at the trade counter, not the PO delivery', $sent['confirm_no_open_grn_reason'] ?? null, 'with the reason');
});

check('A09: a direct bill entered before the check is refused by Books/Inventory at post (BLOCKED), and revised with the confirmation it posts', function () use ($ctx, $owner) {
    reset();
    physicalGrns();
    $po = orderOf($ctx, $owner);
    $b = directBill($ctx, $owner, 'LEGACY-9', 6);  // no receipt yet: entered cleanly
    receive($ctx, $owner, (int) $po['po_id'], (int) $po['lines'][0]['line_id'], 10);
    $exception = latestExceptions((int) $b['request_id'])[0];
    (new BillService($ctx, member('user-approver', ['match.resolve'])))->resolveException((int) $exception['exception_id'], 'accept', ['note' => 'ok']);
    $r = refused(fn () => (new BillService($ctx, $owner))->post((int) $b['request_id']), 'already put these items on hand', 'Books relays Inventory\'s refusal');
    same('books_refused', $r['code'], 'a refusal, not an outage');
    same('BLOCKED', Db::scalar('SELECT status FROM purchase_bill_requests WHERE request_id = :id', ['id' => (int) $b['request_id']]), 'BLOCKED');
    refused(fn () => (new BillService($ctx, $owner))->revise((int) $b['request_id'], []), 'open_goods_receipts', 'revised as it stands');
    $revised = (new BillService($ctx, $owner))->revise((int) $b['request_id'], ['confirm_no_open_grn' => true, 'confirm_no_open_grn_reason' => 'A separate delivery of spare valves']);
    $exception = latestExceptions((int) $b['request_id'])[0];
    (new BillService($ctx, member('user-approver2', ['match.resolve'])))->resolveException((int) $exception['exception_id'], 'accept', ['note' => 'ok']);
    same('POSTED', (new BillService($ctx, $owner))->post((int) $b['request_id'])['status'], 'the confirmed revision posts');
    unset($revised);
});

check('F010: a genuine direct purchase (no open receipt) still posts on_invoice; services are never checked; an unreadable Inventory is 503', function () use ($ctx, $owner) {
    reset();
    $b = directBill($ctx, $owner, 'GENUINE-1', 3);
    $exception = latestExceptions((int) $b['request_id'])[0];
    (new BillService($ctx, member('user-approver', ['match.resolve'])))->resolveException((int) $exception['exception_id'], 'accept', ['note' => 'Direct purchase, no PO.']);
    $posted = (new BillService($ctx, $owner))->post((int) $b['request_id']);
    same('on_invoice', $posted['stock_effect'], 'received by the bill');
    stubControl(['path' => 'pending-quantities', 'status' => 503, 'html' => true]);
    same(503, refused(fn () => directBill($ctx, $owner, 'BLIND-1', 1), 'not entered', 'Inventory unreadable')['status'], '503');
    $service = (new BillService($ctx, $owner))->enter(['supplier_account_id' => 601, 'supplier_invoice_no' => 'SVC-1', 'supplier_invoice_date' => '2026-09-19', 'lines' => [['description' => 'Freight', 'is_service' => true, 'purchase_acc_id' => 7302, 'qty' => 1, 'rate' => 500]]]);
    stubRecover();
    same('service', $service['bill_kind'], 'a service bill does not ask Inventory');
});

// ---------------------------------------------------------------------------
echo "\nF176 / F420 / F430 / F449 / F266 / F025 / F167 / F482 — the order and its masters\n";

function poInput(array $extra = []): array
{
    return $extra + [
        'supplier_account_id' => 601, 'supplier_name' => 'Ghost Supplier Pvt Ltd', 'po_date' => '2026-09-01',
        'delivery_warehouse_id' => 3, 'lines' => [['item_id' => 201, 'unit_id' => 1, 'ordered_qty' => 10, 'agreed_rate' => 100, 'warehouse_id' => 3]],
    ];
}

check('F176: the supplier is confirmed in Books — a ghost ledger, a customer\'s ledger or another tenant\'s is refused 422, and the name is Books\' whatever the client sent', function () use ($ctx, $owner) {
    reset();
    $orders = new Domain\PurchaseOrderService($ctx, $owner);
    $po = $orders->create(poInput());
    same('Deccan Steel Traders', $po['supplier_name_snapshot'], 'the name printed on the order is Books\' ledger name, not "Ghost Supplier"');
    foreach ([[999999, 'supplier_not_found'], [88, 'not_a_supplier']] as [$acc, $why]) {
        $r = refused(fn () => $orders->create(poInput(['supplier_account_id' => $acc])), '', 'supplier ' . $acc);
        same(422, $r['status'], $acc . ' is 422');
        truthy(str_contains($r['message'], 'Smart Books') || str_contains($r['message'], 'not a supplier'), $why . ': ' . $r['message']);
    }
    stubControl(['path' => '/masters/accounts/', 'status' => 502, 'html' => true]);
    same(503, refused(fn () => $orders->create(poInput()), 'could not confirm', 'Books unreadable')['status'], 'an unreadable Books is 503, nothing stored');
    stubRecover();
    same(1, (int) Db::scalar('SELECT COUNT(*) FROM purchase_orders'), 'only the good order was stored');
});

check('F420/F449: an item or warehouse that is not this company\'s in Inventory is refused at create', function () use ($ctx, $owner) {
    reset();
    $orders = new Domain\PurchaseOrderService($ctx, $owner);
    $r = refused(fn () => $orders->create(poInput(['lines' => [['item_id' => 90005, 'unit_id' => 1, 'ordered_qty' => 1, 'agreed_rate' => 10]]])), 'has no item', 'another company\'s item');
    same(422, $r['status'], '422');
    same(422, refused(fn () => $orders->create(poInput(['delivery_warehouse_id' => 96])), 'has no warehouse', 'another company\'s warehouse')['status'], '422');
    same(0, (int) Db::scalar('SELECT COUNT(*) FROM purchase_orders'), 'nothing stored');
});

check('F430/F266: negative rates, discounts over 100%, absurd quantities, zero FX and impossible dates are 422 — never 201 and never a 503', function () use ($ctx, $owner) {
    reset();
    $orders = new Domain\PurchaseOrderService($ctx, $owner);
    $line = ['item_id' => 201, 'unit_id' => 1, 'ordered_qty' => 10, 'agreed_rate' => 100];
    $cases = [
        'negative rate'      => poInput(['lines' => [['agreed_rate' => -50] + $line]]),
        'discount 150%'      => poInput(['lines' => [['discount_pc' => 150] + $line]]),
        'quantity 1e15'      => poInput(['lines' => [['ordered_qty' => 1e15] + $line]]),
        'negative freight'   => poInput(['freight_amount' => -100000]),
        'USD at rate 0'      => poInput(['currency_code' => 'USD', 'exchange_rate' => 0]),
        'INR at rate 83'     => poInput(['exchange_rate' => 83]),
        'February 30'        => poInput(['po_date' => '2026-02-30']),
        'not a date'         => poInput(['po_date' => 'not-a-date']),
        'promised garbage'   => poInput(['promised_date' => '31/12/2026']),
    ];
    foreach ($cases as $what => $input) {
        same(422, refused(fn () => $orders->create($input), '', $what)['status'], $what . ' is 422');
    }
    // Outside the financial year the request is in (Manage confirmed it: 2026-04-01 to 2027-03-31).
    $inYear = Context::of(88, 6, 0);
    $inYear->assertAllowed($owner);
    same(422, refused(fn () => (new Domain\PurchaseOrderService($inYear, $owner))->create(poInput(['po_date' => '2019-01-01'])), 'outside the financial year', 'dated 2019')['status'], 'a 2019 order in FY 2026-27 is 422');
    same(0, (int) Db::scalar('SELECT COUNT(*) FROM purchase_orders'), 'none of them was stored');
    // And a dashboard asked for a date that does not exist is 422, not "database unavailable".
    [$status] = endpoint(fn () => Controllers\SuppliersController::scorecard('601'), $owner, ['cmp_id' => '88', 'fy_id' => '6', 'bo_id' => '0', 'from' => 'garbage']);
    same(422, $status, 'scorecard from=garbage');
    [$status] = endpoint(fn () => Controllers\DashboardsController::show('overview'), $owner, ['cmp_id' => '88', 'fy_id' => '6', 'bo_id' => '0', 'from' => '2026-02-30']);
    same(422, $status, 'dashboards/overview from=2026-02-30');
    [$status] = endpoint(fn () => Controllers\BillsController::payables(), $owner, ['cmp_id' => '88', 'fy_id' => '6', 'bo_id' => '0', 'from' => 'garbage']);
    same(422, $status, 'bills/payables from=garbage');
});

check('F025: a blacklisted or suspended supplier is refused at create, submit, approve and issue — and re-checked when its standing changes after the draft', function () use ($ctx, $owner) {
    reset();
    $orders = new Domain\PurchaseOrderService($ctx, $owner);
    $draft = $orders->create(poInput());
    Db::run("INSERT INTO purchase_supplier_profiles (cmp_id, supplier_account_id, qualification_status) VALUES (88, 601, 'blacklisted')");
    same(409, refused(fn () => $orders->submit((int) $draft['po_id']), 'vendor_not_approved', 'submitting to a supplier blacklisted since')['status'], 'submit re-checks');
    same(409, refused(fn () => $orders->create(poInput()), 'vendor_not_approved', 'creating')['status'], 'create refuses');
    // An override by somebody who may approve suppliers, with a reason, is audited.
    $overridden = $orders->create(poInput(['vendor_override_reason' => 'Sole source for this spare; cleared by the plant head.']));
    same('DRAFT', $overridden['status'], 'created under override');
    same(1, (int) Db::scalar("SELECT COUNT(*) FROM purchase_audit_log WHERE action = 'po.vendor_override'"), 'and the override is audited');
    $buyer = member('user-buyer', ['po.create', 'po.view']);
    same(409, refused(fn () => (new Domain\PurchaseOrderService($ctx, $buyer))->create(poInput(['vendor_override_reason' => 'I want to'])), 'vendor_not_approved', 'a buyer cannot override')['status'], 'no override without supplier.approve');
    // Approved-supplier enforcement switched on after a draft was made is applied at issue.
    Db::run("UPDATE purchase_supplier_profiles SET qualification_status = 'draft' WHERE supplier_account_id = 601");
    $po = $orders->create(poInput());
    $orders->submit((int) $po['po_id']);
    Db::run('INSERT INTO purchase_settings (cmp_id, enforce_approved_vendors) VALUES (88, TRUE) ON CONFLICT (cmp_id) DO UPDATE SET enforce_approved_vendors = TRUE');
    same(409, refused(fn () => $orders->issue((int) $po['po_id']), 'only orders from approved suppliers', 'issue after enforcement started')['status'], 'issue re-checks');
});

check('F482: cancelling an order waiting for approval withdraws its approval request — the inbox has nothing to approve into a 409', function () use ($ctx, $owner) {
    reset();
    Db::run('INSERT INTO purchase_settings (cmp_id, po_approval_above_amount) VALUES (88, 500) ON CONFLICT (cmp_id) DO UPDATE SET po_approval_above_amount = 500');
    $orders = new Domain\PurchaseOrderService($ctx, $owner);
    $po = $orders->create(poInput());
    same('APPROVAL_PENDING', $orders->submit((int) $po['po_id'])['status'], 'above the threshold');
    $orders->cancel((int) $po['po_id'], ['reason' => 'Supplier withdrew the quote.']);
    same('WITHDRAWN', Db::scalar("SELECT status FROM purchase_approval_requests WHERE entity_id = :id", ['id' => (int) $po['po_id']]), 'withdrawn with the order');
});

check('F167: an issued order is amended as a new version; a higher rate needs price.override; above the threshold it waits for another person\'s approval; GRNs and bills follow the amended terms', function () use ($ctx, $owner) {
    reset();
    $po = orderOf($ctx, $owner, [['item_id' => 201, 'unit_id' => 1, 'ordered_qty' => 10, 'agreed_rate' => 100, 'warehouse_id' => 3]]);
    $lineId = (int) $po['lines'][0]['line_id'];
    receive($ctx, $owner, (int) $po['po_id'], $lineId, 4);
    $orders = new Domain\PurchaseOrderService($ctx, $owner);
    refused(fn () => $orders->amend((int) $po['po_id'], ['reason' => 'x', 'lines' => [['line_id' => $lineId, 'ordered_qty' => 3]]]), 'cannot be cut', 'below what arrived');
    $buyer = member('user-buyer', ['po.amend', 'po.view']);
    same(403, refused(fn () => (new Domain\PurchaseOrderService($ctx, $buyer))->amend((int) $po['po_id'], ['reason' => 'Supplier raised the price', 'lines' => [['line_id' => $lineId, 'agreed_rate' => 110]]]), 'permission', 'a higher rate')['status'], 'needs price.override');
    // Within the threshold: applied at once, as version 1.
    $amended = $orders->amend((int) $po['po_id'], ['reason' => 'Agreed 2 more at the same rate', 'lines' => [['line_id' => $lineId, 'ordered_qty' => 12]]]);
    same(1, (int) $amended['version_no'], 'version 1');
    same('12.0000', (string) $amended['lines'][0]['ordered_qty'], 'the line says 12');
    same('APPLIED', $amended['amendments'][0]['status'], 'recorded with before and after');
    same(10.0, (float) $amended['amendments'][0]['before_terms']['lines'][(string) $lineId]['ordered_qty'], 'the old terms are kept');
    receive($ctx, $owner, (int) $po['po_id'], $lineId, 8);  // 4 + 8 = the amended 12

    // Above the threshold: waits; the order keeps its current terms meanwhile; the requester cannot approve.
    Db::run('INSERT INTO purchase_settings (cmp_id, po_approval_above_amount) VALUES (88, 1000) ON CONFLICT (cmp_id) DO UPDATE SET po_approval_above_amount = 1000');
    $pending = $orders->amend((int) $po['po_id'], ['reason' => 'New list price from the supplier', 'lines' => [['line_id' => $lineId, 'agreed_rate' => 125]]]);
    same('PENDING_APPROVAL', $pending['amendments'][0]['status'], 'waiting');
    same('100.0000', (string) $pending['lines'][0]['agreed_rate'], 'still the old rate until approved');
    refused(fn () => $orders->amend((int) $po['po_id'], ['reason' => 'again', 'lines' => [['line_id' => $lineId, 'agreed_rate' => 126]]]), 'waiting for approval', 'a second amendment while one waits');
    $clerk = member('user-clerk', ['po.amend', 'price.override', 'po.view', 'po.approve']);
    $clerkPo = (new Domain\PurchaseOrderService($ctx, $clerk));
    unset($clerkPo);
    $approver = member('user-approver', ['po.approve', 'po.view']);
    $approved = (new Domain\PurchaseOrderService($ctx, $approver))->decide((int) $po['po_id'], 'approve', []);
    same('125.0000', (string) $approved['lines'][0]['agreed_rate'], 'applied on approval');
    same(2, (int) $approved['version_no'], 'version 2');
    same('APPROVED', Db::scalar("SELECT status FROM purchase_approval_requests WHERE reason_kind = 'amendment'"), 'its approval row is closed');
    // The bill is matched against the amended rate: 125 is clean, 130 is a variance.
    $b = bill($ctx, $owner, (int) $po['po_id'], [['po_line_id' => $lineId, 'qty' => 12, 'rate' => 125]], 'AMEND-1');
    same('MATCHED', $b['match']['verdict'], 'the amended rate matches');
});

check('F420: what a short-close stopped waiting for can be reopened, and the order expects it again', function () use ($ctx, $owner) {
    reset();
    $po = orderOf($ctx, $owner, [['item_id' => 201, 'unit_id' => 1, 'ordered_qty' => 10, 'agreed_rate' => 100, 'warehouse_id' => 3]]);
    $lineId = (int) $po['lines'][0]['line_id'];
    receive($ctx, $owner, (int) $po['po_id'], $lineId, 6);
    $orders = new Domain\PurchaseOrderService($ctx, $owner);
    $orders->shortClose((int) $po['po_id'], ['reason' => 'Supplier out of stock']);
    refused(fn () => receive($ctx, $owner, (int) $po['po_id'], $lineId, 1), 'would exceed the order', 'nothing to receive after the short-close');
    $reopened = $orders->reopen((int) $po['po_id'], ['reason' => 'Supplier found the stock']);
    same('0.0000', (string) $reopened['lines'][0]['short_closed_qty'], 'expected again');
    receive($ctx, $owner, (int) $po['po_id'], $lineId, 4);
    same('RECEIVED', (new Domain\PurchaseOrderService($ctx, $owner))->find((int) $po['po_id'])['status'], 'and received in full');
});

// ---------------------------------------------------------------------------
echo "\nA13 — a GST bill entered under All branches\n";

check('A13: under All branches Purchase tells Books the company\'s GST state (Manage\'s registered office), so the bill posts; with none known the refusal says what to do, never "branch #0"', function () use ($owner) {
    reset();
    $scope = Context::of(88, 6, 0);
    $scope->assertAllowed($owner);
    same('29', $scope->companyStateCode(), 'Manage\'s ro_state, read from the same answer that confirmed access');
    $po = orderOf($scope, $owner);
    $lineId = (int) $po['lines'][0]['line_id'];
    receive($scope, $owner, (int) $po['po_id'], $lineId, 10);
    stubMode(['books_company_state_unknown' => true]);
    $b = (new BillService($scope, $owner))->enter(['supplier_account_id' => 601, 'po_id' => (int) $po['po_id'], 'supplier_invoice_no' => 'BO0-1', 'supplier_invoice_date' => '2026-09-19',
        'lines' => [['po_line_id' => $lineId, 'qty' => 10, 'rate' => 250, 'tax_cat_id' => 18]]]);
    same('POSTED', (new BillService($scope, $owner))->post((int) $b['request_id'])['status'], 'posts at bo_id 0');
    same('29', IntegrationCommand::latest(88, BillService::COMMAND_BILL, 'bill_request', (int) $b['request_id'])['request_payload']['company_state_code'] ?? null, 'with company_state_code');

    // A company that never gave Manage a state or GSTIN: refused by Books, said as what to do.
    reset();
    Context::forgetVerified();
    stubMode(['books_company_state_unknown' => true, 'manage_no_ro_state' => true]);
    $scope = Context::of(88, 6, 0);
    $scope->assertAllowed($owner);
    same(null, $scope->companyStateCode(), 'nothing reported');
    $po = orderOf($scope, $owner);
    $lineId = (int) $po['lines'][0]['line_id'];
    receive($scope, $owner, (int) $po['po_id'], $lineId, 10);
    $b = (new BillService($scope, $owner))->enter(['supplier_account_id' => 601, 'po_id' => (int) $po['po_id'], 'supplier_invoice_no' => 'BO0-2', 'supplier_invoice_date' => '2026-09-19',
        'lines' => [['po_line_id' => $lineId, 'qty' => 10, 'rate' => 250, 'tax_cat_id' => 18]]]);
    $r = refused(fn () => (new BillService($scope, $owner))->post((int) $b['request_id']), 'GST state', 'no company state anywhere');
    stubMode([]);
    truthy(!str_contains($r['message'], 'branch #0'), 'never names branch #0: ' . $r['message']);
    truthy(str_contains($r['message'], 'Manage') && str_contains($r['message'], 'branch it belongs to'), 'names the remedies');
    // A refused bill holds nothing and cancels in one step.
    same('CANCELLED', (new BillService($scope, $owner))->cancel((int) $b['request_id'], ['reason' => 'Re-enter under the Pune branch'])['status'], 'cancelled in one step');
});

// ---------------------------------------------------------------------------
echo "\nF122 / F220 — reverse charge and the supplier GSTIN\n";

function ledgers(array $byAccount): void
{
    file_put_contents(sys_get_temp_dir() . '/stub-accounts.json', json_encode($byAccount));
}

function serviceBill(Context $ctx, Auth $auth, string $invoice, array $extra = []): array
{
    $bills = new BillService($ctx, $auth);
    $b = $bills->enter($extra + ['supplier_account_id' => 601, 'supplier_invoice_no' => $invoice, 'supplier_invoice_date' => '2026-09-19',
        'lines' => [['description' => 'Software subscription', 'is_service' => true, 'purchase_acc_id' => 7301, 'tax_cat_id' => 18, 'qty' => 1, 'rate' => 10000]]]);
    $approver = member('user-approver-' . substr(md5($invoice), 0, 6), ['match.resolve']);
    foreach (latestExceptions((int) $b['request_id']) as $e) {
        if ($e['status'] === 'OPEN') {
            (new BillService($ctx, $approver))->resolveException((int) $e['exception_id'], 'accept', ['note' => 'Service bill, reviewed.']);
        }
    }

    return $b;
}

check('F220: services from a supplier abroad default to reverse charge and reach Books as is_reverse_charge; the buyer can mark RCM; posting_check flags an import of services booked as ordinary', function () use ($ctx, $owner) {
    reset();
    ledgers(['601' => ['gstin' => '', 'gst_reg_type' => 'overseas', 'country_code' => 'US', 'state_code' => '']]);
    $b = serviceBill($ctx, $owner, 'IMP-1');
    same(true, (bool) Db::scalar('SELECT is_reverse_charge FROM purchase_bill_requests WHERE request_id = :id', ['id' => (int) $b['request_id']]), 'defaulted from the ledger');
    same('import_of_services', Db::scalar('SELECT rcm_basis FROM purchase_bill_requests WHERE request_id = :id', ['id' => (int) $b['request_id']]), 'with its basis');
    $posted = (new BillService($ctx, $owner))->post((int) $b['request_id']);
    same(1, IntegrationCommand::latest(88, BillService::COMMAND_BILL, 'bill_request', (int) $b['request_id'])['request_payload']['is_reverse_charge'] ?? null, 'sent to Books');
    same(true, $posted['posting_check']['verified'], 'and booked as such: ' . json_encode($posted['posting_check']['problems']));

    // Marked not RCM: booked as ordinary, and the read-back says so.
    $plain = serviceBill($ctx, $owner, 'IMP-2', ['reverse_charge' => false]);
    $checked = (new BillService($ctx, $owner))->post((int) $plain['request_id']);
    truthy(str_contains(implode(' ', $checked['posting_check']['problems'] ?? []), 'import of services'), 'flagged: ' . json_encode($checked['posting_check']));

    // A domestic supply the buyer marks RCM (a notified service).
    ledgers([]);
    $marked = serviceBill($ctx, $owner, 'RCM-3', ['reverse_charge' => true]);
    (new BillService($ctx, $owner))->post((int) $marked['request_id']);
    same(1, IntegrationCommand::latest(88, BillService::COMMAND_BILL, 'bill_request', (int) $marked['request_id'])['request_payload']['is_reverse_charge'] ?? null, 'marked by the buyer');
});

check('F122: a typed GSTIN must pass its check digit, and one that differs from the ledger\'s needs a reason', function () use ($ctx, $owner) {
    reset();
    $bills = new BillService($ctx, $owner);
    $input = ['supplier_account_id' => 601, 'supplier_invoice_date' => '2026-09-19', 'lines' => [['description' => 'Repairs', 'is_service' => true, 'purchase_acc_id' => 7301, 'qty' => 1, 'rate' => 500]]];
    same(422, refused(fn () => $bills->enter($input + ['supplier_invoice_no' => 'G-1', 'supplier_gstin' => '29ABCDE1234F1Z5']), 'check digit', 'an invented GSTIN')['status'], 'refused');
    $r = refused(fn () => $bills->enter($input + ['supplier_invoice_no' => 'G-2', 'supplier_gstin' => '29AABCU9603R1ZJ']), 'gstin_differs_from_ledger', 'another registration, unexplained');
    same(422, $r['status'], '422');
    $ok = $bills->enter($input + ['supplier_invoice_no' => 'G-3', 'supplier_gstin' => '29AABCU9603R1ZJ', 'supplier_gstin_reason' => 'Invoiced from their Bengaluru unit.']);
    same('Invoiced from their Bengaluru unit.', Db::scalar('SELECT supplier_gstin_reason FROM purchase_bill_requests WHERE request_id = :id', ['id' => (int) $ok['request_id']]), 'kept');
    $same = $bills->enter($input + ['supplier_invoice_no' => 'G-4', 'supplier_gstin' => '27AAPFU0939F1ZV']);
    same(null, Db::scalar('SELECT supplier_gstin_reason FROM purchase_bill_requests WHERE request_id = :id', ['id' => (int) $same['request_id']]), 'the ledger\'s own GSTIN needs nothing');
});

// ---------------------------------------------------------------------------
echo "\nF246 / F274 — debit notes settle the invoice; payables are Books' net\n";

function billedOrder(Context $ctx, Auth $auth, float $qty = 10): array
{
    $po = orderOf($ctx, $auth);
    receive($ctx, $auth, (int) $po['po_id'], (int) $po['lines'][0]['line_id'], $qty);
    $bills = new BillService($ctx, $auth);
    $bill = $bills->enter(['supplier_account_id' => 601, 'po_id' => (int) $po['po_id'], 'supplier_invoice_no' => 'RB-' . $po['po_id'], 'supplier_invoice_date' => '2026-09-19', 'lines' => [['po_line_id' => (int) $po['lines'][0]['line_id'], 'qty' => $qty, 'rate' => 250]]]);
    $bills->post((int) $bill['request_id']);

    return (new Domain\PurchaseOrderService($ctx, $auth))->find((int) $po['po_id']);
}

/** @return list<array<string, mixed>> Books' bill-by-bill rows for supplier 601, as the stub keeps them */
function booksBills(): array
{
    $r = (new Clients\BooksClient())->withSession('stub-ses-key.role-1')->billByBill(ctx(), ['acc_id' => 601]);

    return (array) ($r['body']['data']['rows'] ?? []);
}

check('F246: a return\'s debit note names the invoice it corrects (original_document, against_bills), so Books settles it — no separate Dr bill, the invoice pending falls', function () use ($ctx, $owner) {
    reset();
    stubMode(['books_live_bills' => true]);
    $po = billedOrder($ctx, $owner, 10);
    $invoiceVoucher = (int) Db::scalar('SELECT books_voucher_id FROM purchase_bill_requests WHERE po_id = :po', ['po' => (int) $po['po_id']]);
    same(2500.0, (float) booksBills()[0]['pending_amount'], 'the invoice is owed in full');
    $returns = new Domain\ReturnClaimService($ctx, $owner);
    $return = $returns->createReturn(['supplier_account_id' => 601, 'po_id' => (int) $po['po_id'], 'reason_code' => 'quality', 'lines' => [['po_line_id' => (int) $po['lines'][0]['line_id'], 'return_qty' => 4]]]);
    $id = (int) $return['return_id'];
    $returns->approveReturn($id, ['note' => 'Owner approves.']);
    $returns->dispatchReturn($id);
    $returns->requestDebitNote($id);
    $sent = IntegrationCommand::latest(88, Domain\ReturnClaimService::COMMAND_DEBIT_NOTE, 'purchase_return', $id)['request_payload'];
    same($invoiceVoucher, (int) ($sent['original_document']['vch_txn_id'] ?? 0), 'against the purchase it corrects');
    same($invoiceVoucher, (int) ($sent['against_bills'][0]['bill_id'] ?? 0), 'settling its bill Agst Ref');
    $rows = booksBills();
    stubMode([]);
    same(1, count($rows), 'one open bill, no Dr row for the note: ' . json_encode($rows));
    same(1500.0, (float) $rows[0]['pending_amount'], 'the invoice now owes 2500 - 1000');
});

check('F274: open items keep debit notes, advances and ON ACCOUNT apart; the total is Books\' net and the planner never schedules a Dr row', function () use ($ctx, $owner) {
    reset();
    stubMode(['books_live_bills' => true]);
    $po = billedOrder($ctx, $owner, 10);
    // A debit note settling no bill (a claim with no order): a Dr row on the supplier's account.
    $claims = new Domain\ReturnClaimService($ctx, $owner);
    $claim = $claims->createClaim(['supplier_account_id' => 601, 'claim_kind' => 'rebate', 'claimed_amount' => 300]);
    $claims->updateClaim((int) $claim['claim_id'], 'submit', []);
    $claims->updateClaim((int) $claim['claim_id'], 'approve', ['note' => 'Owner approves the rebate claim.']);
    $res = (new Domain\ClaimResolutionService($ctx, $owner))->propose((int) $claim['claim_id'], ['kind' => 'financial_adjustment', 'amount' => 300, 'adjustment_acc_id' => 7301]);
    (new Domain\ClaimResolutionService($ctx, $owner))->approve((int) $res['resolution_id'], ['note' => 'Owner approves.']);
    $open = (new Dashboards\BooksReader($ctx, $owner->sesKey()))->openItems(601, '2026-12-31');
    same(1, count($open['rows']), 'one payable: the invoice');
    same(1, count($open['credits']), 'the unallocated debit note is a credit, not a payable');
    same('2200.0000', Dashboards\Decimal::fixed((string) $open['net_payable'], 4), 'net 2500 - 300, as the supplier ledger says');
    $_GET = ["cmp_id" => (string) $ctx->cmpId, "fy_id" => (string) $ctx->fyId, "bo_id" => (string) $ctx->boId];
    $planner = (new Dashboards\BillsDashboard($ctx, $owner, Dashboards\Period::fromRequest(), Dashboards\Filters::fromRequest()))->build();
    stubMode([]);
    $json = json_encode($planner);
    truthy(!str_contains($json, '"pending":"300'), 'the planner schedules no Dr row');
    unset($po);
});

echo "\n" . str_repeat('-', 60) . "\n";
echo "{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
