<?php

declare(strict_types=1);

/**
 * Insights alignment 2026-10 — Purchases' side of the contract.
 *
 *   MNY-18  a my.aicountly outage is a 503 + Retry-After, not a 401 sign-out.
 *   §7      GET v1/analytics/open-commitment and v1/analytics/po-to-bill for Insights.
 *
 * Required from integration.php, so it shares its harness. With CAPTURE_INSIGHTS_CONTRACTS=1 the
 * envelope checks write what the handlers answered to docs/contracts/insights/; without it they
 * check the committed captures still have the shape the handlers emit.
 */

namespace Aicountly\Api;

use Aicountly\Api\Controllers\AnalyticsController;
use Aicountly\Api\Controllers\SettingsController;
use Aicountly\Api\Domain\PurchaseOrderService;

echo "\nInsights contract (MNY-18, §7)\n";

/**
 * Call a controller as a request would reach it: query string and Authorization as given, the
 * session checked against the stub's portal (never the real one). @return array{0:int, 1:array}
 */
function insightsCall(callable $handler, string $sesKey, array $query): array
{
    $savedGet = $_GET;
    $savedServer = $_SERVER;
    $body = new \ReflectionProperty(Http::class, 'body');
    $body->setAccessible(true);
    $body->setValue(null, []);
    $_GET = $query;
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $sesKey;
    putenv('PORTAL_AUTH_BASE=' . Env::get('MANAGE_API_BASE'));
    Context::forgetVerified();
    Permissions::forget();
    try {
        $handler();
    } catch (ResponseSent $sent) {
        return [$sent->status, $sent->payload];
    } finally {
        $_GET = $savedGet;
        $_SERVER = $savedServer;
        $body->setValue(null, null);
        putenv('PORTAL_AUTH_BASE');
    }
    throw new \RuntimeException('the route answered nothing');
}

/** Row counts of every table this product owns — a read must leave all of them as they were. */
function purchasesRowCounts(): array
{
    $out = [];
    foreach (Db::all("SELECT table_name FROM information_schema.tables WHERE table_schema = 'public' AND table_type = 'BASE TABLE' ORDER BY table_name") as $t) {
        $out[$t['table_name']] = (int) Db::scalar('SELECT COUNT(*) FROM "' . $t['table_name'] . '"');
    }

    return $out;
}

/** Write (CAPTURE_INSIGHTS_CONTRACTS=1) or check the committed capture of one envelope. */
function insightsCapture(string $name, array $envelope): void
{
    $envelope = json_decode((string) json_encode($envelope), true);
    $dir = __DIR__ . '/../../docs/contracts/insights';
    $file = $dir . '/' . $name . '.json';
    if (getenv('CAPTURE_INSIGHTS_CONTRACTS') === '1') {
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents($file, json_encode($envelope, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");

        return;
    }
    assertTrue(is_file($file), 'the capture ' . $name . '.json is published');
    $committed = json_decode((string) file_get_contents($file), true);
    assertSame(insightsShape($committed), insightsShape($envelope), 'the published ' . $name . '.json has the shape the handler emits');
}

function insightsShape(mixed $v): mixed
{
    if (!is_array($v)) {
        return get_debug_type($v);
    }
    if (array_is_list($v)) {
        return $v === [] ? [] : [insightsShape($v[0])];
    }
    ksort($v);

    return array_map('Aicountly\Api\insightsShape', $v);
}

/** One order issued and not received; one received in full and billed for its first line only. */
function commitmentFixture(Context $ctx, Auth $auth): void
{
    $orders = new PurchaseOrderService($ctx, $auth);
    $open = $orders->create(poInput());
    $orders->submit((int) $open['po_id']);
    $orders->issue((int) $open['po_id']);
    billedOrder($ctx, $auth);
    // A draft commits nothing.
    $orders->create(poInput());
}

check('open commitment: what open orders still owe, received-not-billed, overdue — per currency, decimal strings, nothing written', function () {
    resetDatabase();
    $ctx = freshContext();
    commitmentFixture($ctx, authFor());
    $before = purchasesRowCounts();
    [$status, $body] = insightsCall(fn () => AnalyticsController::openCommitment(), 'stub-ses-key.role-1', ['cmp_id' => '88', 'fy_id' => '6', 'bo_id' => '0']);
    assertSame(200, $status, 'answered: ' . json_encode($body));
    assertSame($before, purchasesRowCounts(), 'the read wrote nothing');
    $row = $body['data']['rows'][0] ?? [];
    assertSame(['INR', 1, 2, '61000.0000'], [$row['currency'], $row['open_orders'], $row['open_lines'], $row['commitment_value']], 'one open order: 100 × 250 + 40 × 900 still to come');
    assertSame('36000.0000', $row['received_not_billed_value'], 'the received order\'s unbilled line: 40 × 900');
    assertSame([1, '2026-09-01'], [$row['overdue_orders'], $row['oldest_open_po_date']], 'past its promised date');
    assertSame([1, 1, false], [$body['meta']['total'], $body['meta']['returned'], $body['meta']['truncated']], 'list meta');
    assertSame('operational', $body['meta']['basis']['accounting'], 'basis');
    insightsCapture('purchases.open-commitment', $body);

    [$status, $body] = insightsCall(fn () => AnalyticsController::openCommitment(), 'stub-ses-key.role-1', ['cmp_id' => '88', 'fy_id' => '6', 'to' => '2026-09-30']);
    assertSame([400, 'to'], [$status, $body['error']['parameter'] ?? null], 'the commitment is "right now": a period parameter is named and refused');
});

check('PO to bill: orders raised in the period — ordered, received, billed, and the bills by state', function () {
    resetDatabase();
    $ctx = freshContext();
    commitmentFixture($ctx, authFor());
    [$status, $body] = insightsCall(fn () => AnalyticsController::poToBill(), 'stub-ses-key.role-1', ['cmp_id' => '88', 'fy_id' => '6', 'bo_id' => '0', 'from' => '2026-09-01', 'to' => '2026-09-30']);
    assertSame(200, $status, 'answered: ' . json_encode($body));
    $row = $body['data']['rows'][0] ?? [];
    assertSame([2, 0, 1, 1], [$row['orders'], $row['orders_fully_billed'], $row['orders_partly_billed'], $row['orders_not_billed']], 'two issued orders; the draft is out');
    assertSame(['122000.0000', '61000.0000', '25000.0000', '36000.0000', '20.49'], [$row['ordered_value'], $row['received_value'], $row['billed_value'], $row['received_not_billed_value'], $row['billed_pc']], 'values on the agreed-rate basis');
    assertSame(1, $row['bill_requests']['posted'], 'one bill posted to Books');
    insightsCapture('purchases.po-to-bill', $body);

    [$status, $body] = insightsCall(fn () => AnalyticsController::poToBill(), 'stub-ses-key.role-1', ['cmp_id' => '88', 'fy_id' => '6', 'from' => '2026-09-01', 'to' => '2026-09-30', 'supplier' => '601']);
    assertSame([400, 'supplier'], [$status, $body['error']['parameter'] ?? null], 'an unknown parameter is named');
    insightsCapture('purchases.error-unknown-parameter', $body);
    [$status, $body] = insightsCall(fn () => AnalyticsController::poToBill(), 'stub-ses-key.role-1', ['cmp_id' => '88', 'fy_id' => '6', 'from' => '2026-09-30', 'to' => '2026-09-01']);
    assertSame([400, 'invalid_period'], [$status, $body['error']['code'] ?? null], 'from after to');
    [$status, $body] = insightsCall(fn () => AnalyticsController::poToBill(), 'stub-ses-key.role-1', ['cmp_id' => '88', 'fy_id' => '6', 'from' => '2025-01-01', 'to' => '2025-01-31']);
    assertSame([200, []], [$status, $body['data']['rows']], 'an empty period: no rows, not zeros');
    [$status, $body] = insightsCall(fn () => AnalyticsController::poToBill(), 'stub-ses-key.role-0.as-clerk', ['cmp_id' => '88', 'fy_id' => '6', 'from' => '2026-09-01', 'to' => '2026-09-30']);
    assertSame([403, 'reports.view'], [$status, $body['error']['permission'] ?? null], 'a member without reports.view is refused, naming it');
    insightsCapture('purchases.error-forbidden', $body);
});

check('the portal\'s answer is classified valid / invalid / unavailable; an outage is 503 auth_unavailable, a refusal 401 (MNY-18)', function () {
    resetDatabase();
    putenv('PORTAL_AUTH_BASE=' . Env::get('MANAGE_API_BASE'));
    try {
        assertSame('valid', Portal::checkSesKey('stub-ses-key.role-1')['state'], 'a live session');
        stubFail('validatesession', 401);
        assertSame('invalid', Portal::checkSesKey('stub-ses-key.role-1')['state'], 'the portal\'s own 401');
        stubFail('validatesession', 503);
        assertSame('unavailable', Portal::checkSesKey('stub-ses-key.role-1')['state'], 'a 503 is not a statement about the token');
        stubFail('validatesession', 500);
        assertSame('unavailable', Portal::checkSesKey('stub-ses-key.role-1')['state'], 'a 500 neither');
        stubRecover();
        putenv('PORTAL_AUTH_BASE=http://127.0.0.1:1');
        assertSame('unavailable', Portal::checkSesKey('stub-ses-key.role-1')['state'], 'nothing listening');
    } finally {
        putenv('PORTAL_AUTH_BASE');
        stubRecover();
    }

    stubFail('validatesession', 503);
    try {
        [$status, $body] = insightsCall(fn () => SettingsController::permissions(), 'stub-ses-key.role-1', ['cmp_id' => '88', 'fy_id' => '6']);
    } finally {
        stubRecover();
    }
    assertSame([503, 'auth_unavailable'], [$status, $body['error']['code'] ?? null], 'through a route: 503, not a sign-out');
    stubFail('validatesession', 401);
    try {
        [$status, $body] = insightsCall(fn () => SettingsController::permissions(), 'stub-ses-key.role-1', ['cmp_id' => '88', 'fy_id' => '6']);
    } finally {
        stubRecover();
    }
    assertSame([401, 'unauthorized'], [$status, $body['error']['code'] ?? null], 'a refused session is still a 401');
});

check('over HTTP: the front controller sends 503 + Retry-After on a portal outage, on the API and on /session', function () {
    resetDatabase();
    $stub = Env::get('MANAGE_API_BASE');
    $port = (int) parse_url($stub, PHP_URL_PORT) + 50;
    $proc = proc_open(
        [PHP_BINARY, '-S', '127.0.0.1:' . $port, __DIR__ . '/../index.php'],
        [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
        $pipes, __DIR__ . '/..',
        ['PORTAL_AUTH_BASE' => $stub, 'TMPDIR' => sys_get_temp_dir(), 'PATH' => (string) getenv('PATH')],
    );
    $get = static function (string $path) use ($port): array {
        $headers = [];
        $ch = curl_init('http://127.0.0.1:' . $port . '/' . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*',
            CURLOPT_HTTPHEADER => ['Authorization: Bearer stub-ses-key.role-1'],
            CURLOPT_HEADERFUNCTION => static function ($h, string $line) use (&$headers): int {
                if (str_contains($line, ':')) {
                    [$n, $v] = explode(':', $line, 2);
                    $headers[strtolower(trim($n))] = trim($v);
                }

                return strlen($line);
            },
        ]);
        $body = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return [$status, $headers, json_decode($body, true)];
    };
    try {
        for ($i = 0; $i < 40; $i++) {
            if ($get('health')[0] === 200) {
                break;
            }
            usleep(250000);
        }
        stubFail('validatesession', 503);
        [$status, $headers, $body] = $get('v1/permissions?cmp_id=88&fy_id=6');
        assertSame([503, 'auth_unavailable'], [$status, $body['error']['code'] ?? null], 'the API: 503');
        assertTrue(ctype_digit($headers['retry-after'] ?? ''), 'with Retry-After');
        // An Insights read during the outage, captured from the real front controller (I-OPSAN request).
        [$status, $headers, $body] = $get('v1/analytics/open-commitment?cmp_id=88&fy_id=6');
        assertSame([503, 'auth_unavailable', true], [$status, $body['error']['code'] ?? null, ctype_digit($headers['retry-after'] ?? '')], 'an Insights read: 503 + Retry-After');
        insightsCapture('purchases.error-auth-unavailable', $body);
        [$status, $headers] = $get('session');
        assertSame(503, $status, '/session: 503');
        assertTrue(isset($headers['retry-after']), 'with Retry-After');
        stubRecover();
        [$status] = $get('session');
        assertSame(200, $status, 'and back once the portal answers');
    } finally {
        stubRecover();
        proc_terminate($proc);
        proc_close($proc);
    }
});
