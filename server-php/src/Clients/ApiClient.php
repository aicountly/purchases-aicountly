<?php

declare(strict_types=1);

namespace Aicountly\Api\Clients;

use Aicountly\Api\CrossServiceCallContext;
use Aicountly\Api\Env;
use Aicountly\Api\IdempotencyKey;

/**
 * Base for every outbound call to another AICOUNTLY product.
 *
 * THE RULE THIS CLASS EXISTS TO KEEP: authoritative data owned by another
 * product is READ LIVE, here, on the request that needs it. It is never copied
 * into this product's database, never refreshed by a cron, never mirrored into
 * a "cache" table. A short-lived in-request memo (see $memo) is the only thing
 * that survives a call, and it dies with the process.
 *
 * Two timeout budgets, both with a connect bound, because a product that is up
 * but not accepting — every child blocked waiting on another pool — is held
 * only by CURLOPT_CONNECTTIMEOUT:
 *
 *   optional  connect 2s / total 6s   a screen degrades, stored data is shown
 *   required  connect 3s / total 20s  a write the user is waiting on
 */
abstract class ApiClient
{
    protected const CONNECT_TIMEOUT_OPTIONAL = 2;
    protected const TOTAL_TIMEOUT_OPTIONAL   = 6;
    protected const CONNECT_TIMEOUT_REQUIRED = 3;
    protected const TOTAL_TIMEOUT_REQUIRED   = 20;

    /**
     * Per-request memo of GET responses, keyed by method+url.
     *
     * This is deliberately process-local and dies with the request. It exists so
     * one screen that needs the same item list in three places costs one call,
     * not three — never so a later request can skip asking. Nothing here is
     * written to disk or to the database.
     *
     * @var array<string, array{ok:bool, status:int, body:?array, error:?string}>
     */
    private array $memo = [];

    /**
     * CLI ONLY: answers in place of the network, for a test that has to see the exact URL and
     * headers a deployed base produces — https://contacts.aicountly.com, say — without sending
     * anything anywhere. Under a web SAPI it is never set and never read.
     *
     * @var (callable(string $method, string $url, list<string> $headers, ?array $body): array)|null
     */
    private static $interceptor = null;

    /** @param (callable(string, string, list<string>, ?array): array)|null $answer */
    public static function intercept(?callable $answer): void
    {
        if (PHP_SAPI !== 'cli') {
            return;
        }
        self::$interceptor = $answer;
    }

    /** Product name this client talks to: books | inventory | manage | contacts. */
    abstract public function service(): string;

    /** Production origin, used when nothing more specific is configured. */
    abstract protected function productionBase(): string;

    /** Sandbox origin. */
    abstract protected function sandboxBase(): string;

    /** Environment variable that overrides the derived base, e.g. BOOKS_API_BASE. */
    abstract protected function baseEnvKey(): string;

    /** This product's own name, sent so the callee will not call us back inside our own request. */
    protected function selfName(): string
    {
        return Env::get('APP_PRODUCT_KEY', 'purchases');
    }

    /**
     * Where this product's sibling lives.
     *
     * Derived from our own hostname so sandbox talks to sandbox without a second
     * set of environment variables to keep in step — an explicit env override
     * still wins, for local development and for a one-off cutover.
     */
    public function base(): string
    {
        $configured = Env::get($this->baseEnvKey());
        if ($configured !== '') {
            return rtrim($configured, '/');
        }

        $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
        $host = explode(':', preg_replace('/^www\./', '', $host) ?? '')[0];

        if (str_contains($host, '.gh.aicountly.com') || str_starts_with($host, 'gh-') || $host === '' || str_contains($host, 'localhost') || str_starts_with($host, '127.')) {
            return $this->sandboxBase();
        }

        return $this->productionBase();
    }

    /** The API root. A configured base may already include /api, and a local spark origin serves it at the root. */
    public function apiRoot(): string
    {
        $base = $this->base();
        if (preg_match('#/api$#', $base) === 1 || preg_match('#^https?://(127\.0\.0\.1|localhost)(:\d+)?$#', $base) === 1) {
            return $base;
        }

        return $base . '/api';
    }

    /**
     * One call to the other product.
     *
     * @param array<string, mixed>|null $body
     * @param array<string, string>     $headers extra headers (auth is added by the caller through withAuth)
     * @return array{ok:bool, status:int, body:?array, error:?string}
     */
    public function request(string $method, string $path, ?array $body = null, array $headers = [], bool $required = false): array
    {
        $method = strtoupper($method);

        // RE-ENTRY GUARD. If the product we are about to call is the one whose
        // request we are serving, its worker is already blocked on our response.
        // Calling it now parks a second one of its workers on a request that is
        // waiting on it. See CrossServiceCallContext.
        if (CrossServiceCallContext::isInboundFrom($this->service())) {
            $this->log('suppressed', $path, 0, 0.0, 'inbound_from_' . $this->service());

            return ['ok' => false, 'status' => 0, 'body' => null, 'error' => $this->service() . '_reentrant_call_refused'];
        }

        $url = $this->apiRoot() . '/' . ltrim($path, '/');
        $memoKey = $method . ' ' . $url . ' ' . ($headers['Authorization'] ?? '');
        if ($method === 'GET' && isset($this->memo[$memoKey])) {
            return $this->memo[$memoKey];
        }

        $startedAt = microtime(true);
        $ch = curl_init($url);
        if ($ch === false) {
            return ['ok' => false, 'status' => 0, 'body' => null, 'error' => 'curl_init_failed'];
        }

        $wire = ['Accept: application/json'];
        if ($body !== null) {
            $wire[] = 'Content-Type: application/json';
        }
        // Name ourselves, so the callee suppresses its own calls back to us.
        $wire[] = CrossServiceCallContext::HEADER . ': ' . $this->selfName();
        $wire[] = 'X-Source-App: ' . $this->selfName();
        foreach ($headers as $name => $value) {
            if ($value !== '') {
                $wire[] = $name . ': ' . $this->wireHeaderValue((string) $name, (string) $value);
            }
        }

        if (PHP_SAPI === 'cli' && self::$interceptor !== null) {
            $answer = (self::$interceptor)($method, $url, $wire, $body);

            return (is_array($answer) ? $answer : []) + ['ok' => false, 'status' => 0, 'body' => null, 'error' => 'intercepted'];
        }

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $wire,
            CURLOPT_CONNECTTIMEOUT => $required ? self::CONNECT_TIMEOUT_REQUIRED : self::CONNECT_TIMEOUT_OPTIONAL,
            CURLOPT_TIMEOUT        => $required ? self::TOTAL_TIMEOUT_REQUIRED : self::TOTAL_TIMEOUT_OPTIONAL,
        ];
        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE);
        }
        curl_setopt_array($ch, $options);

        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $transportError = curl_error($ch);
        curl_close($ch);

        $ms = (microtime(true) - $startedAt) * 1000;

        if ($raw === false || $status === 0) {
            $this->log('failed', $path, $status, $ms, $transportError !== '' ? 'transport' : 'no_response');

            return ['ok' => false, 'status' => 0, 'body' => null, 'error' => $transportError !== '' ? $transportError : 'unreachable'];
        }

        $decoded = json_decode((string) $raw, true);
        $result = [
            'ok'     => $status >= 200 && $status < 300,
            'status' => $status,
            'body'   => is_array($decoded) ? $decoded : null,
            'error'  => null,
        ];
        if (!$result['ok']) {
            $result['error'] = is_array($decoded) ? self::errorMessage($decoded, $status) : 'HTTP ' . $status;
            $this->log('error', $path, $status, $ms, null);
        }

        if ($method === 'GET') {
            $this->memo[$memoKey] = $result;
        }

        return $result;
    }

    /**
     * A header value as it goes on the wire. Only the Idempotency-Key is touched: it is sized to
     * the width the product being called keeps it in — every call, every product, whatever built
     * the key — so no write can reach Books with a key Books refuses (IdempotencyKey).
     */
    private function wireHeaderValue(string $name, string $value): string
    {
        if (strcasecmp($name, 'Idempotency-Key') !== 0) {
            return $value;
        }

        return IdempotencyKey::forWire($value, '', IdempotencyKey::limitFor($this->service()));
    }

    /**
     * Log the path only — never the query string, which carries identifiers, and
     * never tokens or response bodies. A fast success is silent: these run on
     * every company-scoped request and logging each one buries the failures the
     * log exists to surface.
     */
    private function log(string $outcome, string $path, int $status, float $ms, ?string $reason): void
    {
        $operation = explode('?', ltrim($path, '/'))[0];
        error_log(sprintf(
            '[cross-service] service=%s outcome=%s operation=%s status=%d ms=%d%s',
            $this->service(),
            $outcome,
            $operation,
            $status,
            (int) $ms,
            $reason !== null ? ' reason=' . $reason : '',
        ));
    }

    /** `?a=1&b=2` from a map, dropping nulls and empty strings. */
    protected static function query(array $params): string
    {
        $clean = [];
        foreach ($params as $key => $value) {
            if ($value === null || $value === '' || is_array($value)) {
                continue;
            }
            $clean[$key] = is_bool($value) ? ($value ? '1' : '0') : (string) $value;
        }

        return $clean === [] ? '' : '?' . http_build_query($clean);
    }

    /**
     * The reason another product gave for refusing, in whichever envelope it used.
     *
     * Inventory and this product answer {error: {code, message}}. Books answers with
     * CodeIgniter's fail(): {status, error: <int>, messages: {error: "…"}}. Reading only the
     * first shape turned every Books refusal into "HTTP 409" — a duplicate supplier invoice, a
     * closed year, an unknown stock effect all reached the person as a status code.
     *
     * @param array<string, mixed> $decoded
     */
    private static function errorMessage(array $decoded, int $status): string
    {
        $candidates = [
            is_array($decoded['error'] ?? null) ? ($decoded['error']['message'] ?? null) : null,
            is_array($decoded['messages'] ?? null) ? ($decoded['messages']['error'] ?? (is_string(reset($decoded['messages'])) ? reset($decoded['messages']) : null)) : null,
            $decoded['message'] ?? null,
            is_string($decoded['error'] ?? null) ? $decoded['error'] : null,
        ];
        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return 'HTTP ' . $status;
    }
}
