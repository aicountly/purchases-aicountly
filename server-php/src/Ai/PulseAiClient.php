<?php

declare(strict_types=1);

namespace Aicountly\Api\Ai;

use Aicountly\Api\CrossServiceCallContext;
use Aicountly\Api\Env;

/**
 * Purchases' client for the AI Pulse gateway, adapted from Pulse's reference
 * client (pulse-aicountly: docs/ai-gateway/PulseAiClient.php; the contract is
 * docs/AI_GATEWAY.md in the same repository).
 *
 * Every AI call Purchases makes goes through here to AI Pulse. Purchases keeps its
 * own instructions, output checks and messages; Pulse picks the model Console
 * binds to it, enforces budgets and reports usage to Console under product
 * `purchases` and the call's feature. Purchases holds no model key and calls no
 * model provider.
 *
 * Adapted for Purchases:
 *   - namespace Aicountly\Api\Ai, product `purchases`, configuration read through
 *     Env (the server .env), like everything else in this API;
 *   - THIS PRODUCT'S OWN GATEWAY KEY goes on every call. PULSE_SERVICE_KEY, minted on
 *     the Pulse host for product `purchases`, is how Pulse knows who is calling
 *     (X-Pulse-Service-Key); it travels beside X-Pulse-Product and the user's session,
 *     never instead of them. While it is unset only the session is sent, which Pulse
 *     accepts only until 2026-11-15 00:00 UTC and then answers 401 product_key_required.
 *     The estate CONSOLE_SERVICE_KEY is not a fallback. A key that cannot go into a
 *     header (CR, LF, NUL or any other control character) is refused here as
 *     `not_configured` and never sent. The key is never logged, returned or put in a
 *     message;
 *   - SIGNED-IN USERS ONLY. Purchases has no background AI job — no cron, no
 *     webhook, no public visitor — so a call without the user's ses_key is refused
 *     here and never leaves the server, with a gateway key or without one. With the
 *     session, Pulse checks the user and the company itself (cmp_id is required on every
 *     call that runs AI) and attributes the usage to that person;
 *   - the Pulse origin is derived from this host the same way ApiClient derives
 *     the sibling products' (sandbox hosts, localhost and the CLI use sandbox);
 *   - like every outbound call this product makes, it names itself in
 *     X-Saas-Origin (docs/ARCHITECTURE.md, "Cross-service re-entry");
 *   - an injectable transport, so the tests fake the HTTP layer;
 *   - only what Purchases uses: generate(), text() and status().
 *
 * Configuration (server-php/.env):
 *   PULSE_SERVICE_KEY  Purchases' own AI gateway key (`php spark pulse:gateway-key mint purchases`
 *                      on the Pulse host; production and sandbox each have their own). Sent on
 *                      every call. Unset: only the session is sent (until 2026-11-15).
 *   PULSE_API_ORIGIN   https://pulse.aicountly.com (sandbox: https://pulse.gh.aicountly.com).
 *                      Normally unset — derived from this host. A trailing /api is ignored.
 *
 * Never throws, never logs content. Every method returns
 *   ['ok' => bool, 'status' => int, 'code' => ?string, 'message' => ?string, 'retryable' => bool, 'data' => ?array]
 * where data is the gateway's `data` (id, text, json, tool_calls, stop_reason, model, tier, usage, …)
 * or, for status(), whether AI is available to Purchases and on which tiers.
 */
final class PulseAiClient
{
    public const PRODUCT    = 'purchases';
    public const PRODUCTION = 'https://pulse.aicountly.com';
    public const SANDBOX    = 'https://pulse.gh.aicountly.com';

    /** Connect bound for every call: a Pulse that is up but not accepting is held only by this. */
    private const CONNECT_TIMEOUT_SECONDS = 5.0;

    /** The status line is optional to every screen that shows it — the product's "optional" budget. */
    private const STATUS_CONNECT_SECONDS = 2.0;
    private const STATUS_TIMEOUT_SECONDS = 6.0;

    /**
     * @var \Closure(array{method: string, url: string, headers: list<string>, body: ?string, timeout: float, connect_timeout: float}): array{status: int, body: ?string, error: ?string}
     */
    private \Closure $transport;

    /**
     * @param ?callable $transport how a request goes out — curl unless a test passes a fake:
     *     fn (array{method, url, headers, body, timeout, connect_timeout} $request)
     *         => ['status' => int, 'body' => ?string, 'error' => null | 'timeout' | 'unreachable']
     */
    public function __construct(
        private string $product = self::PRODUCT,  // sent as X-Pulse-Product
        private ?string $origin = null,
        private float $timeoutSeconds = 20.0,     // an Ask answer is waited on in an open drawer
        ?callable $transport = null,
    ) {
        $this->transport = $transport !== null ? \Closure::fromCallable($transport) : self::curl(...);
    }

    /**
     * One call, on behalf of the signed-in user whose ses_key this API received.
     *
     * @param array<string,mixed> $request gateway body: feature, system, input|messages, response_format,
     *                                     tier, max_output_tokens, cmp_id, fy_id, bo_id, …
     * @return array{ok: bool, status: int, code: ?string, message: ?string, retryable: bool, data: ?array}
     */
    public function generate(array $request, ?string $userSesKey): array
    {
        $res = $this->post('/api/ai/v1/generate', $request, $userSesKey);
        if ($res['ok'] && ($res['data']['stop_reason'] ?? '') === 'refused') {
            return self::result(false, $res['status'], 'refused', 'The model declined this request.', false, $res['data']);
        }

        return $res;
    }

    /**
     * Plain text: the product's instructions as `system`, the data as `input`.
     *
     * @param array<string,mixed> $options any other gateway fields (tier, max_output_tokens, cmp_id, …)
     * @return array{ok: bool, status: int, code: ?string, message: ?string, retryable: bool, data: ?array}
     */
    public function text(string $feature, string $system, string $input, array $options = [], ?string $userSesKey = null): array
    {
        return $this->generate(['feature' => $feature, 'system' => $system, 'input' => $input] + $options, $userSesKey);
    }

    /**
     * Is AI available to Purchases right now (for a status line)? Asked as the signed-in user,
     * with the product's gateway key like every other call. Pulse needs no cmp_id here.
     *
     * @return array{ok: bool, status: int, code: ?string, message: ?string, retryable: bool, data: ?array}
     */
    public function status(?string $userSesKey): array
    {
        $refused = self::refusal($userSesKey);
        if ($refused !== null) {
            return $refused;
        }

        return self::decode(($this->transport)([
            'method'          => 'GET',
            'url'             => $this->origin() . '/api/ai/v1/status',
            'headers'         => ['Accept: application/json', ...$this->callerHeaders($userSesKey)],
            'body'            => null,
            'timeout'         => self::STATUS_TIMEOUT_SECONDS,
            'connect_timeout' => self::STATUS_CONNECT_SECONDS,
        ]));
    }

    /**
     * Pulse's origin for the host this API is served from.
     *
     * An explicit PULSE_API_ORIGIN wins. Otherwise the rule ApiClient uses for
     * Books and Inventory: a request arriving at a sandbox host (*.gh.aicountly.com,
     * gh-*), at localhost, or with no host at all (the CLI) talks to the sandbox
     * Pulse; everything else to production. Sandbox never reaches production by
     * accident, and there is no second setting to keep in step.
     */
    public function origin(): string
    {
        $origin = $this->origin !== null && trim($this->origin) !== ''
            ? trim($this->origin)
            : trim(Env::get('PULSE_API_ORIGIN'));

        if ($origin === '') {
            $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
            $host = explode(':', preg_replace('/^www\./', '', $host) ?? '')[0];
            $sandbox = str_contains($host, '.gh.aicountly.com') || str_starts_with($host, 'gh-') || $host === ''
                || str_contains($host, 'localhost') || str_starts_with($host, '127.');
            $origin = $sandbox ? self::SANDBOX : self::PRODUCTION;
        }

        $origin = rtrim($origin, '/');

        return preg_replace('#/api$#i', '', $origin) ?? $origin;
    }

    /**
     * @param array<string,mixed> $body
     * @return array{ok: bool, status: int, code: ?string, message: ?string, retryable: bool, data: ?array}
     */
    private function post(string $path, array $body, ?string $userSesKey): array
    {
        $refused = self::refusal($userSesKey);
        if ($refused !== null) {
            return $refused;
        }

        $encoded = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($encoded === false) {
            return self::result(false, 0, 'invalid_request', 'The request could not be encoded as JSON.', false);
        }

        return self::decode(($this->transport)([
            'method'          => 'POST',
            'url'             => $this->origin() . $path,
            'headers'         => ['Content-Type: application/json', 'Accept: application/json', ...$this->callerHeaders($userSesKey)],
            'body'            => $encoded,
            'timeout'         => $this->timeoutSeconds,
            'connect_timeout' => self::CONNECT_TIMEOUT_SECONDS,
        ]));
    }

    /**
     * What the transport brought back, as a result.
     *
     * @param array{status: int, body: ?string, error: ?string} $res
     * @return array{ok: bool, status: int, code: ?string, message: ?string, retryable: bool, data: ?array}
     */
    private static function decode(array $res): array
    {
        if (($res['error'] ?? null) !== null) {
            // The outcome is unknown. Nothing here writes, and nothing retries it.
            return self::result(false, 0, $res['error'] === 'timeout' ? 'timeout' : 'pulse_unreachable', 'AI Pulse did not answer.', true);
        }

        $status = (int) ($res['status'] ?? 0);
        $json = json_decode((string) ($res['body'] ?? ''), true);
        if (!is_array($json)) {
            return self::result(false, $status, 'bad_response', "AI Pulse answered HTTP {$status} without JSON.", $status >= 500);
        }
        if ($status >= 200 && $status < 300 && ($json['status'] ?? 0) === 1) {
            return self::result(true, $status, null, null, false, is_array($json['data'] ?? null) ? $json['data'] : null);
        }

        return self::result(
            false,
            $status,
            (string) ($json['code'] ?? 'error'),
            (string) ($json['message'] ?? "AI Pulse answered HTTP {$status}."),
            (bool) ($json['retryable'] ?? $status >= 500),
        );
    }

    /**
     * Who is calling, on every call — the mirror of the reference client's callerHeaders():
     * the product (X-Pulse-Product for Pulse, and X-Saas-Origin as every outbound call from
     * this product carries it), then its own gateway key when one is configured, then the
     * signed-in user's session. Only reached once refusal() has let the call through, so the
     * session is there and the key, when set, is fit for a header.
     *
     * @return list<string>
     */
    private function callerHeaders(?string $userSesKey): array
    {
        $headers = ['X-Pulse-Product: ' . $this->product, CrossServiceCallContext::HEADER . ': ' . $this->product];

        $key = self::serviceKey();
        if ($key !== null && $key !== '') {
            $headers[] = 'X-Pulse-Service-Key: ' . $key;
        }
        $headers[] = 'Authorization: Bearer ' . trim((string) $userSesKey);

        return $headers;
    }

    /**
     * Why this call may not leave the server — as a result — or null when it may. Decided
     * before anything is built, so a refused call never reaches the transport.
     *
     * @return ?array{ok: bool, status: int, code: ?string, message: ?string, retryable: bool, data: ?array}
     */
    private static function refusal(?string $userSesKey): ?array
    {
        if (trim((string) $userSesKey) === '') {
            return self::signedInOnly();
        }

        return self::serviceKey() === null ? self::keyUnusable() : null;
    }

    /**
     * This product's own gateway key (PULSE_SERVICE_KEY): '' while none is set, the key when
     * it can be sent, null when what is set cannot be.
     *
     * Only spaces and tabs around it are ignored. A key with a CR, LF, NUL or any other control
     * character in it — a pasted newline, a corrupted file — would split or truncate the header
     * it is sent in, so it is refused rather than repaired or sent.
     */
    private static function serviceKey(): ?string
    {
        $key = trim(Env::get('PULSE_SERVICE_KEY'), " \t");

        return preg_match('/[\x00-\x1F\x7F]/', $key) === 1 ? null : $key;
    }

    /** @return array{ok: bool, status: int, code: ?string, message: ?string, retryable: bool, data: ?array} */
    private static function signedInOnly(): array
    {
        return self::result(false, 0, 'unauthenticated', 'Purchases asks AI Pulse only on behalf of a signed-in user.', false);
    }

    /**
     * Said without the key, and without any part of it.
     *
     * @return array{ok: bool, status: int, code: ?string, message: ?string, retryable: bool, data: ?array}
     */
    private static function keyUnusable(): array
    {
        return self::result(false, 0, 'not_configured', 'The AI Pulse gateway key set for Purchases cannot be sent: it must be one line without control characters.', false);
    }

    /**
     * @param array{method: string, url: string, headers: list<string>, body: ?string, timeout: float, connect_timeout: float} $request
     * @return array{status: int, body: ?string, error: ?string}
     */
    private static function curl(array $request): array
    {
        $ch = curl_init($request['url']);
        if ($ch === false) {
            return ['status' => 0, 'body' => null, 'error' => 'unreachable'];
        }
        $options = [
            CURLOPT_HTTPHEADER        => $request['headers'],
            CURLOPT_RETURNTRANSFER    => true,
            CURLOPT_FOLLOWLOCATION    => false,
            CURLOPT_CONNECTTIMEOUT_MS => (int) round($request['connect_timeout'] * 1000),
            CURLOPT_TIMEOUT_MS        => (int) round($request['timeout'] * 1000),
            CURLOPT_NOSIGNAL          => true,
        ];
        if ($request['method'] === 'POST') {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = (string) $request['body'];
        }
        curl_setopt_array($ch, $options);

        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($raw === false || $errno !== 0) {
            // Deliberately no curl message: it can echo the URL, and nothing
            // about the transport belongs in an answer a person reads.
            return ['status' => 0, 'body' => null, 'error' => $errno === CURLE_OPERATION_TIMEDOUT ? 'timeout' : 'unreachable'];
        }

        return ['status' => $status, 'body' => (string) $raw, 'error' => null];
    }

    /** @return array{ok: bool, status: int, code: ?string, message: ?string, retryable: bool, data: ?array} */
    private static function result(bool $ok, int $status, ?string $code, ?string $message, bool $retryable, ?array $data = null): array
    {
        return ['ok' => $ok, 'status' => $status, 'code' => $code, 'message' => $message, 'retryable' => $retryable, 'data' => $data];
    }
}
