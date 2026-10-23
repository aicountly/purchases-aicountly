<?php

declare(strict_types=1);

/**
 * The fleet alignment (FLEET_CONTRACT C/D/E) on real PostgreSQL and the stub's Manage:
 *
 *   - plan limits: companyinfo's `plan` (map or list), purchases.max_purchase_orders_per_month → 402
 *     PLAN_LIMIT_REACHED {code, limit, used}; no plan / an absent code = unlimited; nothing deleted;
 *   - workspace-wide access: companyinfo says app_access_source=workspace + app_role_key → the member
 *     holds that starter profile on first entry (unknown key → Read only; never the owner; an existing
 *     seat is never changed);
 *   - POST internal/manage/seats: Manage's signed seat event (a body byte-identical to Manage's),
 *     seated / already / revoked, idempotent by event_id, ROLE_UNKNOWN, 401 / 503 without a good
 *     signature, and a grant never lowers what the person holds.
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

require_once __DIR__ . '/../lib/company-lifecycle/autoload.php';

use Aicountly\Api\Domain\PurchaseOrderService;
use Aicountly\Lifecycle\Signature;

$passed = 0;
$failed = 0;

function check(string $name, callable $fn): void
{
    global $passed, $failed;
    Context::forgetVerified();
    Permissions::forget();
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

function ok(bool $condition, string $what): void
{
    if (!$condition) {
        throw new \RuntimeException($what);
    }
}

/** A caller whose role only the stub's Manage settles (role-1 owner, role-0 member). */
function caller(string $uuid, string $role): Auth
{
    $r = new \ReflectionClass(Auth::class);
    $auth = $r->newInstanceWithoutConstructor();
    foreach (['uuid' => $uuid, 'kind' => 'user', 'sourceApp' => 'purchases', 'sesKey' => 'stub-ses-key.role-' . $role, 'session' => ['name' => $uuid]] as $prop => $value) {
        $r->getProperty($prop)->setValue($auth, $value);
    }

    return $auth;
}

/** Context::fromRequest + assertAllowed, exactly as an HTTP request would. */
function enter(int $cmpId, Auth $auth): Context
{
    $_GET = ['cmp_id' => (string) $cmpId, 'fy_id' => '6', 'bo_id' => '0'];
    Context::forgetVerified();
    Permissions::forget();
    $ctx = Context::fromRequest();
    $ctx->assertAllowed($auth);

    return $ctx;
}

/** What Manage adds to company $cmpId's companyinfo row for Purchases. */
function manageSays(array $byCompany): void
{
    file_put_contents(sys_get_temp_dir() . '/stub-manage-company-extra.json', json_encode($byCompany));
}

function resetAll(): void
{
    require_once __DIR__ . '/reset.php';
    resetPurchaseTables();
    Db::connect()->exec('TRUNCATE purchase_manage_seat_events');
    @unlink(sys_get_temp_dir() . '/stub-manage-company-extra.json');
    Permissions::forget();
}

function poBody(): array
{
    return [
        'supplier_account_id' => 601,
        'supplier_name'       => 'Deccan Steel Traders',
        'po_date'             => '2026-09-01',
        'promised_date'       => '2026-09-20',
        'delivery_warehouse_id' => 3,
        'lines' => [
            ['item_id' => 201, 'unit_id' => 1, 'ordered_qty' => 10, 'agreed_rate' => 250, 'estimated_tax_pc' => 18, 'warehouse_id' => 3],
        ],
    ];
}

/** @return array{0: int, 1: array<string, mixed>} */
function respond(callable $fn): array
{
    try {
        $fn();
    } catch (ResponseSent $sent) {
        return [$sent->status, $sent->payload];
    }
    throw new \RuntimeException('expected a response, none was sent');
}

const SEAT_SECRET = 'test-lifecycle-secret-0123456789abcdef';

/** @return array{0: int, 1: array<string, mixed>} */
function seat(string $raw, ?string $secret = SEAT_SECRET, ?int $ts = null, ?string $signWith = null): array
{
    $ts ??= time();
    $headers = [
        'X-Manage-Timestamp' => (string) $ts,
        'X-Manage-Signature' => Signature::sign($signWith ?? (string) $secret, $ts, $raw),
        'Content-Type'       => 'application/json',
    ];

    return ManageSeats::handle($raw, $headers, static fn (): ?string => $secret);
}

/** The body exactly as Manage builds it (json_encode with JSON_UNESCAPED_SLASHES). */
function manageBody(string $eventId, int $cmpId, string $uuid, ?string $role, string $action = 'grant'): string
{
    return (string) json_encode(['event_id' => $eventId, 'cmp_id' => $cmpId, 'platform_user_id' => $uuid, 'uuid' => $uuid, 'role_key' => $role, 'action' => $action], JSON_UNESCAPED_SLASHES);
}

/** @return list<string> the system keys of the profiles $uuid holds in $cmpId */
function heldKeys(int $cmpId, string $uuid): array
{
    return array_map('strval', array_column(Db::all(
        'SELECT COALESCE(p.system_key, p.profile_name) AS k FROM purchase_permission_assignments a
           JOIN purchase_permission_profiles p ON p.profile_id = a.profile_id
          WHERE a.cmp_id = :cmp AND a.user_uuid = :uuid ORDER BY 1',
        ['cmp' => $cmpId, 'uuid' => $uuid],
    ), 'k'));
}

echo "\nPlans and seats (FLEET_CONTRACT C/D/E)\n";

// --- D. plan limits ---------------------------------------------------------------------------------

check('plan: companyinfo\'s plan is read as a map or a list; a missing plan is no plan', function () {
    $map = PlanEntitlements::fromCompanyInfo(['plan' => ['code' => 'pro', 'label' => 'Pro', 'entitlements' => ['purchases.max_purchase_orders_per_month' => 40]]]);
    same(40, PlanEntitlements::limit($map, PlanEntitlements::MAX_PURCHASE_ORDERS_PER_MONTH), 'map');
    $list = PlanEntitlements::fromCompanyInfo([], ['data' => ['plan' => ['entitlements' => [['code' => 'purchases.max_purchase_orders_per_month', 'value' => '7']]]]]);
    same(7, PlanEntitlements::limit($list, PlanEntitlements::MAX_PURCHASE_ORDERS_PER_MONTH), 'list, numeric string');
    same(null, PlanEntitlements::fromCompanyInfo(['cmp_id' => 88]), 'an older Manage sends no plan');
    same(null, PlanEntitlements::limit(PlanEntitlements::normalize(['entitlements' => ['max_companies' => 1]]), PlanEntitlements::MAX_PURCHASE_ORDERS_PER_MONTH), 'an absent code is unlimited');
    same(true, PlanEntitlements::allows($map, 'feature.purchases.rfq'), 'an absent feature is on');
});

check('plan: the stub Manage\'s plan reaches Context, and the second PO of the month is 402 PLAN_LIMIT_REACHED', function () {
    resetAll();
    manageSays(['88' => ['plan' => ['code' => 'basic', 'label' => 'Basic', 'is_default' => true, 'status' => 'active', 'ends_at' => null,
        'entitlements' => [['code' => 'purchases.max_purchase_orders_per_month', 'value' => 1]]]]]);
    $owner = caller('user-owner', '1');
    $ctx = enter(88, $owner);
    same(1, PlanEntitlements::limit(Context::planFor(88), PlanEntitlements::MAX_PURCHASE_ORDERS_PER_MONTH), 'the limit came from companyinfo');

    (new PurchaseOrderService($ctx, $owner))->create(poBody());
    [$status, $payload] = respond(static fn () => (new PurchaseOrderService($ctx, $owner))->create(poBody()));
    same(402, $status, 'over the limit');
    same('PLAN_LIMIT_REACHED', $payload['error']['code'] ?? null, 'code');
    same(['code' => 'purchases.max_purchase_orders_per_month', 'limit' => 1, 'used' => 1], $payload['error']['details'] ?? null, 'details');
    ok(str_contains((string) ($payload['error']['message'] ?? ''), 'Your plan allows 1 purchase order a month'), 'the sentence');
    same(1, (int) Db::scalar('SELECT COUNT(*) FROM purchase_orders WHERE cmp_id = 88'), 'nothing written, nothing deleted');

    // The same company on an older Manage (no plan): unlimited again.
    manageSays([]);
    $ctx = enter(88, $owner);
    same(null, Context::planFor(88), 'no plan');
    (new PurchaseOrderService($ctx, $owner))->create(poBody());
    same(2, (int) Db::scalar('SELECT COUNT(*) FROM purchase_orders WHERE cmp_id = 88'), 'no plan, no limit');
});

// --- C. workspace-wide access --------------------------------------------------------------------------

check('workspace access: the member holds the role Manage names on first entry, and may use it', function () {
    resetAll();
    manageSays(['88' => ['app_access_source' => 'workspace', 'app_role_key' => 'buyer']]);
    $member = caller('user-ws-buyer', '0');
    $ctx = enter(88, $member);
    same(['buyer'], heldKeys(88, 'user-ws-buyer'), 'seated with the Buyer starter profile');
    ok(Permissions::allows($ctx, $member, 'po.create'), 'and holds what it grants');
    ok(!Permissions::allows($ctx, $member, 'po.approve'), 'and nothing more');
    same('system:manage-workspace', Db::scalar("SELECT assigned_by FROM purchase_permission_assignments WHERE user_uuid = 'user-ws-buyer'"), 'recorded as Manage\'s workspace access');

    // The next request changes nothing.
    enter(88, $member);
    same(1, (int) Db::scalar("SELECT COUNT(*) FROM purchase_permission_assignments WHERE user_uuid = 'user-ws-buyer'"), 'seated once');
});

check('workspace access: an unknown role is the least-privileged one; an existing seat is never changed; owner never seated', function () {
    resetAll();
    manageSays(['88' => ['app_access_source' => 'workspace', 'app_role_key' => 'owner']]);
    enter(88, caller('user-ws-odd', '0'));
    same(['viewer'], heldKeys(88, 'user-ws-odd'), '"owner" from Manage is never seated: Read only');

    // Someone an administrator already gave Accounts payable keeps exactly that.
    manageSays(['88' => ['app_access_source' => 'workspace', 'app_role_key' => 'approver']]);
    enter(88, caller('user-ws-odd', '0'));
    same(['viewer'], heldKeys(88, 'user-ws-odd'), 'an existing seat is not changed by a later workspace role');

    enter(88, caller('user-owner', '1'));
    same([], heldKeys(88, 'user-owner'), 'the owner holds everything already and is never seated');

    manageSays([]);
    enter(88, caller('user-plain', '0'));
    same([], heldKeys(88, 'user-plain'), 'no workspace access (an older Manage): nothing changes');
});

// --- E. Manage seat route ------------------------------------------------------------------------------

check('seat: Manage\'s byte-identical grant seats the starter profile; a redelivery answers the same', function () {
    resetAll();
    $raw = '{"event_id":"0b6f1c3c-5e8d-4c55-9a3e-0e4b7f6b2a10","cmp_id":42,"platform_user_id":"u","uuid":"u","role_key":"buyer","action":"grant"}';
    same($raw, manageBody('0b6f1c3c-5e8d-4c55-9a3e-0e4b7f6b2a10', 42, 'u', 'buyer'), 'the fixture is what Manage sends');
    [$status, $body] = seat($raw);
    same([200, 'seated'], [$status, $body['data']['status'] ?? null], 'seated');
    same(['buyer'], heldKeys(42, 'u'), 'holds Buyer in company 42 (the starter profile was created there)');
    same('buyer', Db::scalar("SELECT system_key FROM purchase_permission_profiles WHERE cmp_id = 42"), 'from the starter, under its system key');
    [$status, $body] = seat($raw);
    same([200, 'seated', true], [$status, $body['data']['status'] ?? null, $body['data']['duplicate'] ?? null], 'the redelivery answers what the first did');
    [, $body] = seat(manageBody('1b6f1c3c-5e8d-4c55-9a3e-0e4b7f6b2a10', 42, 'u', 'buyer'));
    same('already', $body['data']['status'] ?? null, 'a new event for a seat already held: already');
    same(1, (int) Db::scalar("SELECT COUNT(*) FROM purchase_permission_profiles WHERE cmp_id = 42"), 'one Buyer profile');
});

check('seat: a grant never lowers — a Read only grant to an approver adds, takes nothing', function () {
    resetAll();
    seat(manageBody('2b6f1c3c-5e8d-4c55-9a3e-0e4b7f6b2a10', 42, 'appr', 'approver'));
    [, $body] = seat(manageBody('3b6f1c3c-5e8d-4c55-9a3e-0e4b7f6b2a10', 42, 'appr', 'viewer'));
    same('seated', $body['data']['status'] ?? null, 'Read only added');
    same(['approver', 'viewer'], heldKeys(42, 'appr'), 'Purchase approver kept');
});

check('seat: an existing profile of the same name is used, not duplicated', function () {
    resetAll();
    $id = (int) Db::insert('purchase_permission_profiles', ['cmp_id' => 42, 'profile_name' => 'Buyer', 'permissions' => json_encode(['po.view']), 'is_active' => true], 'profile_id');
    seat(manageBody('4b6f1c3c-5e8d-4c55-9a3e-0e4b7f6b2a10', 42, 'u2', 'buyer'));
    same($id, (int) Db::scalar("SELECT profile_id FROM purchase_permission_assignments WHERE user_uuid = 'u2'"), 'the company\'s own Buyer profile');
});

check('seat: revoke releases every Purchases profile the person holds; again is already', function () {
    resetAll();
    seat(manageBody('5b6f1c3c-5e8d-4c55-9a3e-0e4b7f6b2a10', 42, 'gone', 'payables'));
    [, $body] = seat(manageBody('6b6f1c3c-5e8d-4c55-9a3e-0e4b7f6b2a10', 42, 'gone', null, 'revoke'));
    same('revoked', $body['data']['status'] ?? null, 'revoked');
    same([], heldKeys(42, 'gone'), 'holds nothing');
    [, $body] = seat(manageBody('7b6f1c3c-5e8d-4c55-9a3e-0e4b7f6b2a10', 42, 'gone', null, 'revoke'));
    same('already', $body['data']['status'] ?? null, 'nothing left to revoke');
    ok((int) Db::scalar("SELECT COUNT(*) FROM purchase_audit_log WHERE actor_uuid = 'system:manage-seat' AND action = 'access.profile_unassigned'") === 1, 'the release is in the audit trail');
});

check('seat: owner and unknown roles are 422 ROLE_UNKNOWN; a bad body is 422 invalid_event', function () {
    resetAll();
    foreach (['owner', 'administrator', 'cashier'] as $role) {
        [$status, $body] = seat(manageBody('8b6f1c3c-5e8d-4c55-9a3e-0e4b7f6b2a10', 42, 'u', $role));
        same([422, 'ROLE_UNKNOWN'], [$status, $body['error']['code'] ?? null], $role);
    }
    [$status, $body] = seat(manageBody('8b6f1c3c-5e8d-4c55-9a3e-0e4b7f6b2a10', 42, 'u', 'buyer', 'promote'));
    same([422, 'invalid_event'], [$status, $body['error']['code'] ?? null], 'an unknown action');
    [$status] = seat('{"event_id":"not-a-uuid","cmp_id":42,"uuid":"u","role_key":"buyer","action":"grant"}');
    same(422, $status, 'event_id must be a UUID');
    same([], heldKeys(42, 'u'), 'nothing seated');
});

check('seat: a bad or missing signature is 401; no usable secret is 503; nothing is seated', function () {
    resetAll();
    $raw = manageBody('9b6f1c3c-5e8d-4c55-9a3e-0e4b7f6b2a10', 42, 'u', 'buyer');
    same(401, seat($raw, SEAT_SECRET, null, 'another-secret-of-at-least-32-characters')[0], 'signed with another secret');
    same(401, seat($raw, SEAT_SECRET, time() - 3600)[0], 'an hour old');
    same(401, ManageSeats::handle($raw, [], static fn (): ?string => SEAT_SECRET)[0], 'unsigned');
    same(503, seat($raw, null)[0], 'no secret configured');
    same(503, seat($raw, 'short')[0], 'a secret under 32 characters');
    same([], heldKeys(42, 'u'), 'nothing seated');
    same(0, (int) Db::scalar('SELECT COUNT(*) FROM purchase_manage_seat_events'), 'nothing recorded');
});

check('seat: the route is registered outside the session and company checks', function () {
    $src = (string) file_get_contents(__DIR__ . '/../src/Routes.php');
    ok(str_contains($src, "\$router->post('internal/manage/seats', [ManageSeats::class, 'receive']);"), 'POST internal/manage/seats → ManageSeats::receive');
});

@unlink(sys_get_temp_dir() . '/stub-manage-company-extra.json');

echo "\n" . str_repeat('-', 60) . "\n";
echo "{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
