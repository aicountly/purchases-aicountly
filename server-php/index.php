<?php

declare(strict_types=1);

/**
 * Purchases API — front controller.
 *
 * Deployed to <document root>/api, so it is same-origin with the React app on
 * both purchase.aicountly.com and purchase.gh.aicountly.com (singular — the deployed hosts; the plural names are aliases).
 *
 * Routes:
 *   GET  /api/health          liveness + which environment answered
 *   POST /api/global/{path}   allow-listed relay to the portal auth API
 *   GET  /api/session         who the caller is, per the portal
 *   *    /api/v1/...          the Purchases API proper — see src/Routes.php
 */

namespace Aicountly\Api;

require __DIR__ . '/src/Env.php';
require __DIR__ . '/src/Autoload.php';
require __DIR__ . '/src/Portal.php';

Env::load(__DIR__ . '/.env');

/**
 * Portal paths this API relays for the browser.
 *
 * The relay exists so the SPA never makes a cross-origin call to the portal:
 * a new product domain is not in the portal's CORS allowlist on day one.
 *
 * It is an allowlist and must stay one. Forwarding arbitrary paths would turn
 * this host into an open proxy for the portal's whole auth surface — login,
 * signup, OTP, user lookups — with the portal seeing this server's IP instead
 * of the caller's, so anything it rate-limits per IP could be driven through
 * here instead.
 */
const RELAYED_PATHS = [
    'seskey',
    'seskey/refresh',
    'refresh_authtoken',
];

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * @param array<string, mixed> $payload
 */
function send_json(int $status, array $payload): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * The Authorization header, wherever this server happens to expose it.
 *
 * Under CGI/FastCGI Apache does not pass it to PHP unless it is copied
 * explicitly, and after an internal rewrite it arrives only under the
 * REDIRECT_ prefix. Reading just one of these is why an otherwise correct
 * deployment answers 401 to every sign-in.
 */
function authorization_header(): string
{
    $candidates = [
        $_SERVER['HTTP_AUTHORIZATION'] ?? '',
        $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '',
    ];

    if (function_exists('apache_request_headers')) {
        foreach ((array) apache_request_headers() as $name => $value) {
            if (strcasecmp((string) $name, 'Authorization') === 0) {
                $candidates[] = (string) $value;
                break;
            }
        }
    }

    foreach ($candidates as $candidate) {
        if (is_string($candidate) && $candidate !== '') {
            return $candidate;
        }
    }

    return '';
}

function bearer_token(): string
{
    $header = authorization_header();
    if ($header === '' || preg_match('/Bearer\s+(.+)/i', $header, $matches) !== 1) {
        return '';
    }

    return trim($matches[1]);
}

/**
 * Collapse a routed path to the exact form RELAYED_PATHS is written in.
 *
 * Percent-escapes are decoded first so `%2e%2e` cannot smuggle a traversal
 * segment past the allowlist; exact matching does the rest.
 */
function normalise_path(string $path): string
{
    $decoded = str_replace('\\', '/', rawurldecode($path));
    $segments = array_values(array_filter(explode('/', $decoded), static fn ($s) => $s !== ''));

    return strtolower(implode('/', $segments));
}

/**
 * CORS for local development only.
 *
 * In both deployed environments the app and this API share an origin, so no
 * CORS headers are needed or sent. CORS_ALLOWED_ORIGINS in the server .env is
 * what lets `npm run dev` on localhost talk to a deployed API.
 */
function apply_cors(): void
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin === '') {
        return;
    }

    $allowed = array_filter(array_map('trim', explode(',', Env::get('CORS_ALLOWED_ORIGINS'))));
    if (!in_array($origin, $allowed, true)) {
        return;
    }

    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Headers: Authorization, Content-Type, Idempotency-Key, X-Source-App, X-Saas-Origin');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Max-Age: 600');
    header('Vary: Origin');
}

// ---------------------------------------------------------------------------
// Routing
// ---------------------------------------------------------------------------

apply_cors();

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$uri = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');

// Strip the directory this front controller is mounted under, so the same file
// works at <docroot>/api and at the root of a dedicated API vhost.
$mountPoint = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php'))), '/');
if ($mountPoint !== '' && $mountPoint !== '/' && strpos($uri, $mountPoint) === 0) {
    $uri = substr($uri, strlen($mountPoint));
}

$path = normalise_path($uri);

if ($path === '' || $path === 'health') {
    // Liveness AND readiness. 'status' stays ok whenever PHP is serving, so
    // an uptime monitor pointed here keeps behaving as it always has; the
    // database block is what tells you whether the app can actually be used.
    // Reporting only the former is how a deploy went green on an app whose
    // every real endpoint answered 503.
    $database = Health::database();
    $environment = Health::environment();

    send_json(200, [
        'status' => 'ok',
        'app' => 'Purchases',
        'env' => Env::get('APP_ENV', 'unknown'),
        'time' => gmdate('c'),
        'database' => $database,
        // E8-OPS (F162, F128, F262): the commit this deploy wrote to REVISION; which settings the
        // cross-app journeys need are present (booleans only); cross-app commands left open too long
        // (counts only); and whether the command monitor (cron) runs. Post-deploy reads all four.
        'revision' => \Aicountly\Api\Ops\OpsStatus::revision(),
        'config' => $opsConfig = \Aicountly\Api\Ops\OpsStatus::config(),
        'commands' => $database['reachable'] ? \Aicountly\Api\Ops\OpsStatus::commands() : ['readable' => false],
        'monitor' => $monitor = ($database['reachable'] ? \Aicountly\Api\Ops\OpsStatus::monitor() : ['state' => 'unknown']),
        // Which environment this deployment is configured as and whether every sibling base
        // agrees with it (A20). A production API pointing at a sandbox Books is not usable.
        'environment' => $environment,
        // One field to read when something is wrong. False means the site
        // is up and the product is not usable.
        'usable' => $usable = $database['reachable'] && ($database['schema']['ready'] ?? false) && $environment['ok'],
        // Usable, configured for its cross-app journeys, and watched: what a production deploy requires.
        'ready' => $usable && $opsConfig['ready'] && $monitor['state'] === 'running',
    ]);
}

if (strpos($path, 'global/') === 0) {
    $portalPath = substr($path, strlen('global/'));

    if (!in_array($portalPath, RELAYED_PATHS, true)) {
        send_json(404, ['message' => 'This path is not relayed. Call the portal API directly.']);
    }

    $headers = [];
    $authorization = authorization_header();
    if ($authorization !== '') {
        $headers[] = 'Authorization: ' . $authorization;
    }
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (is_string($contentType) && $contentType !== '') {
        $headers[] = 'Content-Type: ' . $contentType;
    }

    $body = (string) file_get_contents('php://input');
    $result = Portal::forward($method, $portalPath, $headers, $body);

    if ($result['status'] === 504) {
        send_json(504, ['message' => 'Auth service unavailable — please retry.']);
    }

    http_response_code($result['status']);
    header('Content-Type: ' . $result['contentType']);
    header('Cache-Control: no-store');
    echo $result['body'];
    exit;
}

if ($path === 'session') {
    $sesKey = bearer_token();
    if ($sesKey === '') {
        send_json(401, ['message' => 'Missing bearer session key.']);
    }

    $checked = Portal::checkSesKey($sesKey);
    if ($checked['state'] === 'unavailable') {
        // The portal could not say. Not a sign-out: 503 + Retry-After (MNY-18).
        header('Retry-After: ' . max(1, (int) $checked['retry_after']));
        send_json(503, [
            'error'   => ['code' => 'auth_unavailable', 'message' => 'Sign-in could not be checked right now. Please retry in a moment.'],
            'message' => 'Sign-in could not be checked right now. Please retry in a moment.',
        ]);
    }
    $session = $checked['session'];
    if ($session === null) {
        send_json(401, ['message' => 'Invalid or expired session.']);
    }

    send_json(200, [
        'authenticated' => true,
        'uuid' => $session['uuid_aictly'] ?? ($session['uuid'] ?? ''),
    ]);
}

// ---------------------------------------------------------------------------
// The Purchases API
//
// Everything above this line is the auth bootstrap and predates the product.
// Everything below is the product, and it all goes through one router so that
// authentication, company scope and the tenant check happen in one place rather
// than being remembered per endpoint.
// ---------------------------------------------------------------------------

$router = new Router();
Routes::register($router);

try {
    if ($router->dispatch($method, $path)) {
        exit;
    }
} catch (\PDOException $e) {
    // Only a database that cannot be reached (or is shedding load) is a retryable 503. A value
    // PostgreSQL refused — a date that does not exist, a number too large for its column — is the
    // caller's to fix, and anything else is a fault here; calling those "not reachable" sent
    // operators hunting outages and clients retrying forever (F266). The detail goes to the log.
    error_log('[purchases] database error on ' . $path . ': ' . $e->getMessage());
    $state = (string) ($e->errorInfo[0] ?? (is_string($e->getCode()) ? $e->getCode() : ''));
    if ($state === '' || str_starts_with($state, '08') || str_starts_with($state, '57P') || str_starts_with($state, '53') || $state === 'HY000') {
        Http::error(503, 'database_unavailable', 'The Purchases database is not reachable right now. Please retry.', ['retryable' => true]);
    }
    if (str_starts_with($state, '22')) {
        Http::error(422, 'invalid_value', 'A value in the request is out of range or not valid for its field.', ['retryable' => false]);
    }
    Http::error(500, 'server_error', 'Something went wrong handling that request.', ['retryable' => false]);
} catch (\Throwable $e) {
    error_log('[purchases] unhandled error on ' . $path . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    Http::error(500, 'server_error', 'Something went wrong handling that request.');
}

send_json(404, ['error' => ['code' => 'not_found', 'message' => 'Not found.', 'details' => []], 'message' => 'Not found.']);
