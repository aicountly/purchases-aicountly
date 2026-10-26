<?php

declare(strict_types=1);

namespace Aicountly\Api;

/*
 * The front controller's helpers (index.php requires this file once).
 *
 * They live here and not in index.php because of the atomic-deploy entry guard at the top of index.php
 * (bin/ops/atomic-entry.php.txt): PHP declares a file's top-level functions when it compiles the file,
 * BEFORE the guard runs, so when the guard hands a request to the current release's index.php that copy
 * would declare them a second time - "Cannot redeclare", an empty HTTP 500 on every request a PHP worker
 * serves in the minutes after a deploy. index.php itself must declare no function, class, interface,
 * trait or enum (tests/ops/atomic-entry-test.sh checks it, through two releases).
 *
 * Never served: api/.htaccess answers 404 for every real file but index.php.
 */

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
    header('Access-Control-Allow-Headers: Authorization, Content-Type, Idempotency-Key, X-Source-App, X-Saas-Origin, X-Correlation-Id, X-Request-Id');
    header('Access-Control-Expose-Headers: X-Request-Id');
    header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
    header('Access-Control-Max-Age: 600');
    header('Vary: Origin');
}

/**
 * The reference an error answer and its log line share: the caller's X-Request-Id when it is a safe
 * token (the SPA sends a random one with every call), else a fresh 32-hex id. One per request.
 *
 * It is what a user quotes from "Ref: …" on an error and what the find-ref workflow greps the logs
 * for, so it has to be in both places and has to match find-ref's rule: 6-64 of [A-Za-z0-9._-].
 */
function request_reference(): string
{
    static $reference = null;
    if ($reference === null) {
        $given = trim((string) ($_SERVER['HTTP_X_REQUEST_ID'] ?? $_SERVER['REDIRECT_HTTP_X_REQUEST_ID'] ?? ''));
        $reference = preg_match('/^[A-Za-z0-9._-]{6,64}$/', $given) === 1 ? $given : bin2hex(random_bytes(16));
    }

    return $reference;
}

/**
 * Answer an error nothing else handled: one log line with the reference, the exception class,
 * message and file:line and the request's method and path; and a JSON answer with a sentence, a
 * code and the same reference, never the exception's text.
 *
 * An unhandled failure can come after work was done (a relayed save that landed before this API
 * failed), so a write is told to check before trying again rather than "nothing was changed".
 */
function answer_uncaught(string $app, \Throwable $e, string $method, string $path, int $status = 500, string $code = 'server_error'): never
{
    $reference = request_reference();
    error_log('[' . strtolower($app) . '] unhandled ' . get_class($e) . ' [' . $reference . '] ' . $method . ' /' . $path
        . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    $read = in_array($method, ['GET', 'HEAD', 'OPTIONS'], true);
    $message = $read
        ? $app . ' could not complete this request. Try again, and quote reference ' . $reference . ' if it keeps failing.'
        : $app . ' could not complete this request, so it is not known whether the change was saved. Reload to check before trying again, and quote reference ' . $reference . ' if it keeps failing.';
    if (!headers_sent()) {
        header('X-Request-Id: ' . $reference);
    }
    Http::json($status, [
        'error'      => ['code' => $code, 'message' => $message, 'details' => ['request_id' => $reference], 'request_id' => $reference],
        'message'    => $message,
        'request_id' => $reference,
    ]);
}
