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
 *   *    /api/manage/v1/...   allow-listed relay to Manage's company registry (src/ManageRelay.php)
 *   GET  /api/session         who the caller is, per the portal
 *   *    /api/v1/...          the Purchases API proper — see src/Routes.php
 */

namespace Aicountly\Api;

// >>> aicountly atomic entry (bin/ops/atomic-entry.php.txt) >>>
if (PHP_SAPI !== 'cli' && !defined('AICOUNTLY_ATOMIC_ENTRY') && isset($_SERVER['SCRIPT_FILENAME'])) {
    define('AICOUNTLY_ATOMIC_ENTRY', true);
    clearstatcache(true);
    $aicountlyEntry = realpath((string) $_SERVER['SCRIPT_FILENAME']);
    if ($aicountlyEntry !== false && $aicountlyEntry !== __FILE__ && is_file($aicountlyEntry)) {
        return require $aicountlyEntry;
    }
    unset($aicountlyEntry);
}
// <<< aicountly atomic entry <<<

require __DIR__ . '/src/Env.php';
require __DIR__ . '/src/Autoload.php';
// The helpers below are declared in their own file: see the note in src/front_helpers.php.
require __DIR__ . '/src/front_helpers.php';
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
 *
 * Every relayed call also names the visitor in X-Aicountly-Client-Ip (this connection's
 * REMOTE_ADDR, never a browser-supplied header), because all of them now reach the portal from
 * this server and its per-IP sign-in throttles would otherwise be shared by every user.
 */
const RELAYED_PATHS = [
    'seskey',
    'seskey/refresh',
    'refresh_authtoken',
    // Sign-out. The browser used to POST my.aicountly.com/api/logout itself; from networks the
    // hosting's bot shield distrusts that cross-site call got a challenge page with no CORS
    // headers and failed, leaving the portal token alive. Same-origin through here, it passes.
    'logout',
];

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

// Every route, the relays included, answers inside this try: an error none of them handles is
// logged with a reference and answered as JSON that quotes it (the catch blocks at the end),
// never as PHP's empty 500.
try {
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
        // Only the headers above are forwarded, so a browser-sent X-Aicountly-Client-Ip, X-Forwarded-For,
        // X-Real-IP, Forwarded or Client-IP never reaches the portal; the visitor is the address this
        // server's own connection sees (see RELAYED_PATHS).
        $visitor = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        if (filter_var($visitor, FILTER_VALIDATE_IP) !== false) {
            $headers[] = 'X-Aicountly-Client-Ip: ' . $visitor;
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

    // Manage's company registry (Users & Access, My workspaces, the company picker), same-origin for
    // the same reason as the portal relay above: a cross-site call to manage.aicountly.com can be
    // answered by the hosting's bot shield with a challenge page that has no CORS headers, and the
    // browser then sees no response at all. ManageRelay forwards only the calls the shared client
    // makes; the raw (still encoded) path is passed so each segment is decoded and checked once.
    if ($path === 'manage' || strpos($path, 'manage/') === 0) {
        ManageRelay::serve($method, $uri, (string) ($_SERVER['QUERY_STRING'] ?? ''), [
            'Authorization'    => authorization_header(),
            'Content-Type'     => (string) ($_SERVER['CONTENT_TYPE'] ?? ''),
            'Accept'           => (string) ($_SERVER['HTTP_ACCEPT'] ?? ''),
            'Idempotency-Key'  => (string) ($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? ''),
            'X-Correlation-Id' => (string) ($_SERVER['HTTP_X_CORRELATION_ID'] ?? ''),
        ], (string) file_get_contents('php://input'));
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

    if ($router->dispatch($method, $path)) {
        exit;
    }
} catch (\PDOException $e) {
    // Only a database that cannot be reached (or is shedding load) is a retryable 503. A value
    // PostgreSQL refused — a date that does not exist, a number too large for its column — is the
    // caller's to fix, and anything else is a fault here; calling those "not reachable" sent
    // operators hunting outages and clients retrying forever (F266). The detail goes to the log.
    $state = (string) ($e->errorInfo[0] ?? (is_string($e->getCode()) ? $e->getCode() : ''));
    if ($state === '' || str_starts_with($state, '08') || str_starts_with($state, '57P') || str_starts_with($state, '53') || $state === 'HY000') {
        error_log('[purchases] database error [' . request_reference() . '] ' . $method . ' /' . $path . ': ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        Http::error(503, 'database_unavailable', 'The Purchases database is not reachable right now. Please retry.', ['retryable' => true, 'request_id' => request_reference()]);
    }
    if (str_starts_with($state, '22')) {
        error_log('[purchases] invalid value [' . request_reference() . '] ' . $method . ' /' . $path . ': ' . $e->getMessage());
        Http::error(422, 'invalid_value', 'A value in the request is out of range or not valid for its field.', ['retryable' => false, 'request_id' => request_reference()]);
    }
    // Any other statement fault is ours: logged and answered like every unhandled error (below).
    answer_uncaught('Purchases', $e, $method, $path);
} catch (\Throwable $e) {
    // Logged with its class, file:line, method, path and reference; answered with a sentence and the
    // same reference (the X-Request-Id the screen sent, so its "Ref: …" finds this line).
    answer_uncaught('Purchases', $e, $method, $path);
}

send_json(404, ['error' => ['code' => 'not_found', 'message' => 'Not found.', 'details' => []], 'message' => 'Not found.']);
