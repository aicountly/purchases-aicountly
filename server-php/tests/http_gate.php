<?php

declare(strict_types=1);

/**
 * The HTTP gate in front of every route, driven over HTTP through the REAL front controller
 * (index.php under `php -S`, several workers) — launch readiness E8-PUR F219, F424, F450, F357.
 *
 * Every route the Router registers is walked, so a route added later is covered the day it is
 * added:
 *   - no session                        401, and nothing is read or written
 *   - a session the portal says is gone 401
 *   - forged headers instead of a session (X-Service-Key that is no key, X-Saas-Origin,
 *     _manage_verified, X-Actor-Uuid)  401 — never an elevation
 *   - a company Manage says the caller is not a member of (another tenant, a removed member)
 *                                       403 company_access_denied, not a retryable 503 (F424/F450)
 *   - Manage down                       503, retryable
 *   - a member with no Purchases seat   403 on everything but the explicit list of routes a
 *                                       member needs before a seat (F357)
 *   - CORS                              only a configured origin is echoed
 *
 * Needs the stub (tests/run.sh starts it) and starts the API itself on API_PORT.
 *
 *   server-php/tests/run.sh
 */

namespace Aicountly\Api;

require __DIR__ . '/../src/Env.php';
require __DIR__ . '/../src/Autoload.php';

Env::load(__DIR__ . '/../.env');

$root = dirname(__DIR__);
$stubBase = rtrim(Env::get('BOOKS_API_BASE'), '/');
$apiPort = (int) (getenv('API_PORT') ?: ((int) parse_url($stubBase, PHP_URL_PORT) + 1));
$api = 'http://127.0.0.1:' . $apiPort;

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
        echo "  FAIL  {$name}\n        " . str_replace("\n", "\n        ", $e->getMessage()) . "\n";
        $failed++;
    }
}

/** @return array{status:int, body:?array, headers:array<string,string>, raw:string} */
function call(string $method, string $url, array $headers = [], ?array $body = null): array
{
    $ch = curl_init($url);
    $wire = ['Accept: application/json'];
    foreach ($headers as $k => $v) {
        $wire[] = $k . ': ' . $v;
    }
    if ($body !== null) {
        $wire[] = 'Content-Type: application/json';
    }
    $responseHeaders = [];
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $wire,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_NOPROXY        => '*',
        CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$responseHeaders): int {
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
            }

            return strlen($line);
        },
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    $raw = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    $decoded = json_decode($raw, true);

    return ['status' => $status, 'body' => is_array($decoded) ? $decoded : null, 'headers' => $responseHeaders, 'raw' => $raw];
}

function stubControl(?array $control): void
{
    $file = sys_get_temp_dir() . '/stub-control.json';
    if ($control === null) {
        @unlink($file);

        return;
    }
    file_put_contents($file, json_encode($control));
}

/** Every route, as the Router holds it, with its placeholders filled. @return list<array{0:string,1:string}> */
function routes(): array
{
    $router = new Router();
    Routes::register($router);
    $prop = (new \ReflectionClass($router))->getProperty('routes');
    $out = [];
    foreach ($prop->getValue($router) as $route) {
        $segments = array_map(static fn (string $s) => match ($s) {
            '{view}'   => 'overview',
            '{type}'   => 'purchase_order',
            '{action}' => 'submit',
            default    => str_starts_with($s, '{') ? '1' : $s,
        }, $route['segments']);
        $out[] = [$route['method'], implode('/', $segments)];
    }

    return $out;
}

/**
 * The routes a member may reach before anyone gives them a Purchases seat: choosing a company,
 * learning who they are and what they hold, and the pass-through reads of other products' masters
 * (Books and Inventory decide those for the member's own session; nothing of Purchases' is in
 * them — the supplier profile is withheld without supplier.view). Connect's two calls answer, per
 * document, what the caller may share or see — "nothing" is an answer, not a 403. The retired
 * summary endpoint answers 410 to everybody.
 */
const OPEN_TO_MEMBERS = [
    'GET v1/manage/companies',
    'GET v1/manage/companyinfo',
    'GET v1/session',
    'GET v1/permissions',
    'GET v1/catalog/items',
    'GET v1/catalog/items/search',
    'GET v1/catalog/availability',
    'GET v1/catalog/warehouses',
    'GET v1/catalog/uoms',
    'GET v1/catalog/suppliers',
    'GET v1/catalog/tax-categories',
    'POST v1/connect/share-check',
    'GET v1/connect/context/purchase_order/1',
    'GET v1/dashboard',
];

/** Routes that take no company: the company switcher. */
const UNSCOPED = ['GET v1/manage/companies', 'GET v1/manage/companyinfo'];

const SCOPE = '?cmp_id=88&fy_id=6&bo_id=0';

// --- the API, the real front controller --------------------------------------
require_once __DIR__ . '/reset.php';
resetPurchaseTables();
$env = ['PHP_CLI_SERVER_WORKERS' => '4', 'TMPDIR' => sys_get_temp_dir(), 'PATH' => (string) getenv('PATH')];
$proc = proc_open(
    ['php', '-S', '127.0.0.1:' . $apiPort, '-t', $root, $root . '/index.php'],
    [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
    $pipes,
    $root,
    $env,
);
register_shutdown_function(static function () use ($proc): void {
    if (is_resource($proc)) {
        proc_terminate($proc);
    }
});
for ($i = 0; $i < 60; $i++) {
    if (call('GET', $api . '/health')['status'] > 0) {
        break;
    }
    usleep(200000);
}

$routes = routes();
$owner = ['Authorization' => 'Bearer stub-ses-key.role-1.as-user-owner'];
$noSeat = ['Authorization' => 'Bearer stub-ses-key.role-3.as-user-noseat'];
$stranger = ['Authorization' => 'Bearer stub-ses-key.role-none.as-user-stranger'];
$revoked = ['Authorization' => 'Bearer stub-ses-key.revoked'];

echo "\nHTTP gate — every route over the real front controller (" . count($routes) . " routes)\n";

check('the front controller answers, and every route is registered once', function () use ($api, $routes) {
    $health = call('GET', $api . '/health');
    if ($health['status'] !== 200) {
        throw new \RuntimeException('health: ' . $health['status'] . ' ' . $health['raw']);
    }
    $keys = array_map(static fn ($r) => $r[0] . ' ' . $r[1], $routes);
    if (count($keys) < 100) {
        throw new \RuntimeException('only ' . count($keys) . ' routes registered');
    }
});

check('no session: 401 on every route', function () use ($api, $routes) {
    $wrong = [];
    foreach ($routes as [$method, $path]) {
        $r = call($method, $api . '/' . $path . SCOPE, [], $method === 'GET' || $method === 'DELETE' ? null : []);
        if ($r['status'] !== 401) {
            $wrong[] = "{$method} {$path} -> {$r['status']}";
        }
    }
    if ($wrong !== []) {
        throw new \RuntimeException(implode("\n", $wrong));
    }
});

check('a session the portal says is gone: 401 on every route', function () use ($api, $routes, $revoked) {
    $wrong = [];
    foreach ($routes as [$method, $path]) {
        $r = call($method, $api . '/' . $path . SCOPE, $revoked, $method === 'GET' || $method === 'DELETE' ? null : []);
        if ($r['status'] !== 401) {
            $wrong[] = "{$method} {$path} -> {$r['status']}";
        }
    }
    if ($wrong !== []) {
        throw new \RuntimeException(implode("\n", $wrong));
    }
});

check('forged headers in place of a session are never an identity: 401', function () use ($api) {
    $forged = [
        ['X-Service-Key' => 'not-a-key', 'X-Actor-Uuid' => 'user-owner'],
        ['X-Saas-Origin' => 'books', 'X-Source-App' => 'books'],
        ['X-Manage-Verified' => '1', 'X-Provision-Hook' => '1'],
    ];
    foreach ($forged as $headers) {
        foreach ([['GET', 'v1/purchase-orders'], ['POST', 'v1/purchase-orders'], ['PUT', 'v1/settings'], ['GET', 'v1/access/profiles']] as [$method, $path]) {
            $r = call($method, $api . '/' . $path . SCOPE . '&_manage_verified=1', $headers, $method === 'GET' ? null : ['_manage_verified' => true]);
            if ($r['status'] !== 401) {
                throw new \RuntimeException(json_encode($headers) . " {$method} {$path} -> {$r['status']}");
            }
        }
    }
});

check('F424/F450: a company Manage says the caller may not open is 403 company_access_denied on every scoped route, never a retryable 503', function () use ($api, $routes, $stranger, $owner) {
    $wrong = [];
    foreach ($routes as [$method, $path]) {
        if (in_array($method . ' ' . $path, UNSCOPED, true)) {
            continue;
        }
        foreach ([[$stranger, SCOPE], [$owner, '?cmp_id=502&fy_id=6&bo_id=0']] as [$who, $scope]) {
            $r = call($method, $api . '/' . $path . $scope, $who, $method === 'GET' || $method === 'DELETE' ? null : []);
            $code = $r['body']['error']['code'] ?? null;
            if ($r['status'] !== 403 || $code !== 'company_access_denied' || ($r['body']['error']['details']['retryable'] ?? null) !== false) {
                $wrong[] = "{$method} {$path}{$scope} -> {$r['status']} {$code}";
            }
        }
    }
    if ($wrong !== []) {
        throw new \RuntimeException(implode("\n", array_slice($wrong, 0, 40)) . (count($wrong) > 40 ? "\n… " . count($wrong) . ' in all' : ''));
    }
});

check('Manage down, or answering what is not its JSON: 503 context_unavailable, retryable — never "allowed"', function () use ($api, $owner) {
    foreach ([['status' => 503], ['status' => 502, 'html' => true], ['status' => 404, 'html' => true]] as $shape) {
        stubControl(['path' => '/companyinfo'] + $shape);
        Context::forgetVerified();
        $r = call('GET', $api . '/v1/purchase-orders' . SCOPE, $owner);
        stubControl(null);
        if ($r['status'] !== 503 || ($r['body']['error']['code'] ?? null) !== 'context_unavailable') {
            throw new \RuntimeException(json_encode($shape) . ' -> ' . $r['status'] . ' ' . $r['raw']);
        }
    }
});

check('F357: a member with no Purchases seat is refused (403) everywhere but choosing a company and learning who they are', function () use ($api, $routes, $noSeat) {
    $wrong = [];
    foreach ($routes as [$method, $path]) {
        $key = $method . ' ' . $path;
        $r = call($method, $api . '/' . $path . SCOPE, $noSeat, $method === 'GET' || $method === 'DELETE' ? null : []);
        $open = in_array($key, OPEN_TO_MEMBERS, true);
        if ($open && in_array($r['status'], [401, 403], true) && !str_starts_with($key, 'POST v1/connect') && !str_starts_with($key, 'GET v1/connect')) {
            $wrong[] = "{$key} -> {$r['status']} (should be open to a member)";
        }
        if (!$open && $r['status'] !== 403) {
            $wrong[] = "{$key} -> {$r['status']}";
        }
    }
    if ($wrong !== []) {
        throw new \RuntimeException(implode("\n", $wrong));
    }
});

check('the owner reaches the routes (no 401/403/5xx on the reads)', function () use ($api, $routes, $owner) {
    $wrong = [];
    foreach ($routes as [$method, $path]) {
        if ($method !== 'GET' || str_contains($path, '/1')) {
            continue;
        }
        $r = call('GET', $api . '/' . $path . SCOPE, $owner);
        if (in_array($r['status'], [401, 403], true) || $r['status'] >= 500) {
            $wrong[] = "GET {$path} -> {$r['status']} " . substr($r['raw'], 0, 160);
        }
    }
    if ($wrong !== []) {
        throw new \RuntimeException(implode("\n", $wrong));
    }
});

check('errors are the JSON envelope, and an unknown route is 404 JSON', function () use ($api, $owner) {
    $r = call('GET', $api . '/v1/no-such-route' . SCOPE, $owner);
    if ($r['status'] !== 404 || !isset($r['body']['error']['code'])) {
        throw new \RuntimeException($r['status'] . ' ' . $r['raw']);
    }
    $r = call('POST', $api . '/v1/purchase-orders' . SCOPE, $owner, ['lines' => []]);
    if ($r['status'] !== 422 || !isset($r['body']['error']['message'])) {
        throw new \RuntimeException('validation: ' . $r['status'] . ' ' . $r['raw']);
    }
});

check('CORS: only a configured origin is echoed', function () use ($api) {
    $evil = call('OPTIONS', $api . '/v1/purchase-orders', ['Origin' => 'https://evil.example', 'Access-Control-Request-Method' => 'GET']);
    if (isset($evil['headers']['access-control-allow-origin'])) {
        throw new \RuntimeException('echoed ' . $evil['headers']['access-control-allow-origin']);
    }
    $good = call('OPTIONS', $api . '/v1/purchase-orders', ['Origin' => 'http://127.0.0.1:5173', 'Access-Control-Request-Method' => 'GET']);
    if (($good['headers']['access-control-allow-origin'] ?? '') !== 'http://127.0.0.1:5173') {
        throw new \RuntimeException('the configured origin was not echoed: ' . json_encode($good['headers']));
    }
});

echo "\n" . str_repeat('-', 60) . "\n";
echo "{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
