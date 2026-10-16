<?php

declare(strict_types=1);

namespace Aicountly\Api;

use Aicountly\Lifecycle\Kit;

/**
 * Manage's company lifecycle in Purchases — the AICOUNTLY receiver kit (server-php/lib/company-lifecycle,
 * vendored unchanged) wired into this API. See docs/COMPANY_LIFECYCLE.md.
 *
 *   receive()   POST /api/internal/manage/company-lifecycle — Manage's signed events. Outside every
 *               session / company check: the HMAC (LIFECYCLE_WEBHOOK_SECRET) is its only credential.
 *   enforce()   after Context::assertAllowed() confirmed with Manage that the person may open the
 *               company: a write to a restricted / purge_pending / purged company answers 423
 *               COMPANY_RESTRICTED (never 403/404 — the seat stays and exports keep working).
 *   sync()      Manage companyinfo (data.lifecycle / data.restricted_until) is the source of truth;
 *               wherever Purchases reads it the stored state is corrected.
 *
 *   enforceMachine()  kit 1.1 machine gating: product backends calling with a service key — Books' signed
 *               voucher events (POST v1/integration/books/voucher-events), any other service-key write —
 *               are refused with the same 423 COMPANY_RESTRICTED once their key / signature and the company
 *               were verified. Their senders defer the item (Books' source-event outbox keeps the event as
 *               deferred_restricted) and send it again on renewal.
 */
final class CompanyLifecycle
{
    public const APP_KEY = 'purchases';

    /**
     * Writes that stay open while the company is read-only: they only read, render or export, or are
     * the signed-in person's own UI state. "METHOD /path" with `*` = one segment, `**` = anything.
     * tests/company_lifecycle.php fails when an entry stops matching a route in Routes.php.
     */
    public const LIFECYCLE_ALLOW = [
        // Connect: may this document be shared, and what could each viewer see — a permission check.
        'POST /api/v1/connect/share-check',
        // Insights dashboard question over figures already recorded.
        'POST /api/v1/insights/ask',
        // Reading an uploaded file (POST because it carries the file): a preview with no side effects,
        // and a supplier statement compared against the bills recorded — neither writes anything.
        'POST /api/v1/import/preview',
        'POST /api/v1/import/reconcile-statement',
    ];

    /**
     * Machine writes (kit 1.1 checkMachine: another product's service key or signature, no person) that pass
     * whatever the company's state. Only money that already moved, telephony / provider webhooks that cannot
     * be replayed, and Manage-signed callbacks belong here; Purchases receives none of them (Books' voucher events are deferred by Books' source-event outbox).
     * Every other machine write into a read-only company answers 423 COMPANY_RESTRICTED and its sender
     * defers it (DeferredSend). tests/company_lifecycle.php fails when an entry stops matching a route.
     *
     * @var list<string>
     */
    public const LIFECYCLE_MACHINE_ALLOW = [];

    private static ?Kit $kit = null;

    public static function kit(): Kit
    {
        if (self::$kit === null) {
            require_once __DIR__ . '/../lib/company-lifecycle/autoload.php';
            // Purchases' own connection: its database name and user come from Console (ConsoleDatabaseDetails),
            // which the kit's own .env reader would not know.
            self::$kit = Kit::fromEnv(
                self::APP_KEY,
                Db::connect(),
                __DIR__ . '/../config/lifecycle-manifest.php',
                static fn (string $name): ?string => Env::get($name) !== '' ? Env::get($name) : null,
            );
        }

        return self::$kit;
    }

    /** For tests: build the kit again (another database, another secret). */
    public static function forget(): void
    {
        self::$kit = null;
    }

    /** POST internal/manage/company-lifecycle */
    public static function receive(): void
    {
        $headers = function_exists('getallheaders') ? (getallheaders() ?: $_SERVER) : $_SERVER;
        try {
            [$status, $json] = self::kit()->receiver()->handle((string) file_get_contents('php://input'), $headers);
        } catch (\Throwable $e) {
            // No database (or no kit): Manage retries a 503.
            error_log('[purchases] company lifecycle event not recorded: ' . $e->getMessage());
            [$status, $json] = [503, ['status' => 'error', 'code' => 'event_not_recorded', 'message' => 'The event could not be recorded. Retry later.']];
        }
        Http::json($status, $json);
    }

    /** The write gate, after the company-access check passed. Ends the request with 423 when refused. */
    public static function enforce(int $cmpId): void
    {
        $method = Http::method();
        if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return;
        }
        try {
            $refusal = self::kit()->guard(self::LIFECYCLE_ALLOW)->check($cmpId, $method, self::requestPath());
        } catch (\Throwable $e) {
            // A fault reading the state must not turn into "your subscription ended" (the kit's own rule).
            error_log('[purchases] company lifecycle state unreadable for cmp=' . $cmpId . ': ' . $e->getMessage());

            return;
        }
        if ($refusal !== null) {
            Http::json($refusal[0], $refusal[1]);
        }
    }

    /**
     * The machine-caller gate (kit 1.1): after the caller's key / signature and the company were verified
     * (so a caller who may not act there still gets 401/403), a write into a restricted / purge_pending /
     * purged company answers 423 COMPANY_RESTRICTED — with restricted_until and lifecycle, which the
     * sender's DeferredSend reads to keep the item and send it again on renewal. Reads always pass.
     */
    public static function enforceMachine(int $cmpId): void
    {
        $method = Http::method();
        if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return;
        }
        try {
            $refusal = self::kit()->guard(self::LIFECYCLE_ALLOW, self::LIFECYCLE_MACHINE_ALLOW)->checkMachine($cmpId, $method, self::requestPath());
        } catch (\Throwable $e) {
            error_log('[purchases] company lifecycle state unreadable for cmp=' . $cmpId . ' (machine write): ' . $e->getMessage());

            return;
        }
        if ($refusal !== null) {
            Http::json($refusal[0], $refusal[1]);
        }
    }

    /** @param array<string, mixed> $companyInfoData Manage companyinfo's `data` */
    public static function sync(int $cmpId, array $companyInfoData): void
    {
        if (!array_key_exists('lifecycle', $companyInfoData)) {
            return;
        }
        try {
            self::kit()->guard()->syncFromCompanyInfo($cmpId, $companyInfoData);
        } catch (\Throwable $e) {
            error_log('[purchases] companyinfo lifecycle not recorded for cmp=' . $cmpId . ': ' . $e->getMessage());
        }
    }

    /**
     * "/api/<routed path>" — the request path as Routes.php names it, wherever the API is mounted
     * (<docroot>/api on cPanel, the root of `php -S`), so the allow-list reads like the route table.
     */
    public static function requestPath(): string
    {
        $uri = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');
        $mount = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php'))), '/');
        if ($mount !== '' && $mount !== '/' && str_starts_with($uri, $mount)) {
            $uri = substr($uri, strlen($mount));
        }
        $segments = array_values(array_filter(explode('/', str_replace('\\', '/', rawurldecode($uri))), static fn ($s) => $s !== ''));

        return '/api/' . strtolower(implode('/', $segments));
    }
}
