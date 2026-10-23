<?php

declare(strict_types=1);

namespace Aicountly\Api\Ops;

use Aicountly\Api\Db;
use Aicountly\Api\Env;

/**
 * What /api/health says about this deployment's operations (E8-OPS: F162, F128, F262, A24).
 *
 *   revision   the commit the deploy wrote to REVISION (bin/ops/release.sh) — post-deploy compares it
 *   config     which settings a cross-app journey needs are present: BOOLEANS ONLY, never a value
 *   commands   cross-app commands (purchase_integration_commands) left open longer than COMMAND_STALE_MINUTES
 *              (default 15): counts only — no company, document or payload
 *   monitor    whether bin/command-monitor.php (cron, bin/ops/jobs.txt) has run recently
 *
 * Public and unauthenticated, like the rest of /api/health: nothing here identifies a tenant.
 */
final class OpsStatus
{
    public const COMMANDS_TABLE = 'purchase_integration_commands';
    public const HEARTBEATS_TABLE = 'purchases_ops_heartbeats';
    public const MONITOR = 'command-monitor';
    /**
     * Statuses that are finished, one way or another — or, DEFERRED_RESTRICTED, kept on purpose
     * while the other product holds the company read-only (reviewed hourly by the monitor, never
     * "stale"); everything else is still open.
     */
    public const CLOSED = ['COMPLETED', 'CANCELLED', 'SUPERSEDED', 'DUPLICATE', 'DEFERRED_RESTRICTED', 'DROPPED_COMPANY_DELETED'];
    /**
     * Settings the cross-app journeys need (presence only); `ready` requires every one of them.
     *
     *   INVENTORY_SERVICE_KEY        goods receipts, returns, batch/serial registration are posted to Inventory
     *                                as Purchases (= Inventory's INVENTORY_SERVICE_KEYS `purchases:` entry)
     *   BOOKS_EVENTS_SIGNING_SECRET  Books' voucher events (cancel / revise a posted bill) are verified with it
     *                                (>= 16 characters; = Books' BOOKS_SOURCE_EVENTS_PURCHASES_SECRET)
     *   SERVICE_KEYS:books           the `books:<key>` entry Books presents with those events
     *                                (= Books' BOOKS_SOURCE_EVENTS_PURCHASES_KEY)
     *   LIFECYCLE_WEBHOOK_SECRET     Manage's signed lifecycle and seat events (>= 32 characters;
     *                                = Manage's LIFECYCLE_WEBHOOK_SECRET_PURCHASES)
     *   MANAGE_API_URL               where a company purge is acknowledged to Manage
     *
     * There is no Books service key: Books is always called as the signed-in person (F374/F510), so the
     * Books session path needs nothing here. BOOKS_SERVICE_KEY was listed until October 2026 and made
     * `ready` unreachable on a correctly configured host (launch-readiness P0-P1).
     */
    public const REQUIRED_CONFIG = ['INVENTORY_SERVICE_KEY', 'BOOKS_EVENTS_SIGNING_SECRET', 'SERVICE_KEYS:books', 'LIFECYCLE_WEBHOOK_SECRET', 'MANAGE_API_URL'];

    /**
     * Settings a feature uses but the product works without: reported (config.optional, config.warnings),
     * never part of `ready`.
     *
     *   PULSE_SERVICE_KEY            AI Pulse gateway key; without it AI Insights is rules-only (after
     *                                2026-11-15 Pulse refuses a session-only call)
     *   MANAGE_PRODUCT_SERVICE_KEY   the purge worker asks Manage whether a purge was reversed before it runs
     *
     * Contacts needs no setting: it is called as the signed-in person at the host APP_ENV derives
     * (CONTACTS_API_BASE only pins another one), and Purchases holds no Contacts key.
     */
    public const OPTIONAL_CONFIG = ['PULSE_SERVICE_KEY', 'MANAGE_PRODUCT_SERVICE_KEY'];

    /** Shortest secret each verifier accepts (BooksEventSignature, the lifecycle kit's Config). */
    private const MIN_LENGTH = ['BOOKS_EVENTS_SIGNING_SECRET' => 16, 'LIFECYCLE_WEBHOOK_SECRET' => 32];

    public static function revision(): ?string
    {
        $file = __DIR__ . '/../../REVISION';
        if (!is_file($file)) {
            return null;
        }
        $rev = trim((string) file_get_contents($file));

        return preg_match('/^[0-9a-f]{7,40}$/', $rev) === 1 ? $rev : null;
    }

    /**
     * Which settings are present (booleans only, never a value). `items` keeps its shape (name => bool, the
     * required ones); `optional` and `warnings` (the names of optional settings that are missing) are new
     * and never decide `ready`.
     *
     * @param (callable(string): string)|null $env test seam; Env::get by default
     * @return array{items: array<string,bool>, ready: bool, optional: array<string,bool>, warnings: list<string>}
     */
    public static function config(?callable $env = null): array
    {
        $env ??= static fn (string $name): string => Env::get($name);
        $items = [];
        foreach (self::REQUIRED_CONFIG as $name) {
            $items[$name] = self::present($name, $env);
        }
        $optional = [];
        foreach (self::OPTIONAL_CONFIG as $name) {
            $optional[$name] = self::present($name, $env);
        }

        return [
            'items'    => $items,
            'ready'    => !in_array(false, $items, true),
            'optional' => $optional,
            'warnings' => array_keys(array_filter($optional, static fn (bool $set): bool => !$set)),
        ];
    }

    /** @param callable(string): string $env */
    private static function present(string $name, callable $env): bool
    {
        if (str_starts_with($name, 'SERVICE_KEYS:')) {
            // One app's entry in the inbound key list (`app:key,other:key`), a placeholder not counting.
            $app = substr($name, strlen('SERVICE_KEYS:'));
            foreach (explode(',', (string) $env('SERVICE_KEYS')) as $pair) {
                $pair = explode(':', trim($pair), 2);
                if (count($pair) === 2 && strtolower(trim($pair[0])) === $app && self::usable(trim($pair[1]))) {
                    return true;
                }
            }

            return false;
        }
        $value = trim((string) $env($name));

        return self::usable($value) && strlen($value) >= (self::MIN_LENGTH[$name] ?? 1);
    }

    private static function usable(string $value): bool
    {
        return $value !== '' && !str_starts_with(strtoupper($value), 'CHANGE_ME');
    }

    public static function staleAfterSeconds(): int
    {
        $m = (int) Env::get('COMMAND_STALE_MINUTES', '15');

        return max(1, $m > 0 ? $m : 15) * 60;
    }

    /**
     * Open commands and how many have been left alone too long.
     *
     * @return array<string, mixed>
     */
    public static function commands(): array
    {
        $closed = "'" . implode("','", self::CLOSED) . "'";
        try {
            $row = Db::first(
                'SELECT COUNT(*) AS open,
                        COUNT(*) FILTER (WHERE updated_at < NOW() - make_interval(secs => :s)) AS stale,
                        EXTRACT(EPOCH FROM (NOW() - MIN(updated_at)))::BIGINT AS oldest
                   FROM ' . self::COMMANDS_TABLE . ' WHERE status NOT IN (' . $closed . ')',
                ['s' => self::staleAfterSeconds()],
            ) ?? [];
            $by = Db::all(
                'SELECT status, COUNT(*) AS n FROM ' . self::COMMANDS_TABLE . '
                  WHERE status NOT IN (' . $closed . ') AND updated_at < NOW() - make_interval(secs => :s)
                  GROUP BY status ORDER BY status',
                ['s' => self::staleAfterSeconds()],
            );
        } catch (\Throwable $e) {
            error_log('[health] command counts unreadable: ' . $e->getMessage());

            return ['readable' => false];
        }
        $byStatus = [];
        foreach ($by as $r) {
            $byStatus[(string) $r['status']] = (int) $r['n'];
        }

        return [
            'readable'            => true,
            'open'                => (int) ($row['open'] ?? 0),
            'stale'               => (int) ($row['stale'] ?? 0),
            'stale_by_status'     => $byStatus,
            'stale_after_seconds' => self::staleAfterSeconds(),
            'oldest_open_age_seconds' => isset($row['oldest']) && $row['oldest'] !== null ? (int) $row['oldest'] : null,
        ];
    }

    /** @return array{state:string, last_run_at:?string, age_seconds:?int, last_ok:?bool} */
    public static function monitor(): array
    {
        try {
            $row = Db::first(
                'SELECT last_finished_at, last_ok, EXTRACT(EPOCH FROM (NOW() - last_finished_at))::BIGINT AS age
                   FROM ' . self::HEARTBEATS_TABLE . ' WHERE job_name = :n',
                ['n' => self::MONITOR],
            );
        } catch (\Throwable) {
            return ['state' => 'unknown', 'last_run_at' => null, 'age_seconds' => null, 'last_ok' => null];
        }
        if ($row === null || $row['last_finished_at'] === null) {
            return ['state' => 'never_ran', 'last_run_at' => null, 'age_seconds' => null, 'last_ok' => null];
        }
        $age = (int) $row['age'];
        // The monitor runs every five minutes; three missed runs is a monitor that is not running.
        $staleAfter = max(60, (int) Env::get('COMMAND_MONITOR_STALE_MINUTES', '15') * 60);

        return [
            'state'       => $age <= $staleAfter ? 'running' : 'stale',
            'last_run_at' => gmdate('c', (int) strtotime((string) $row['last_finished_at'])),
            'age_seconds' => $age,
            'last_ok'     => $row['last_ok'] === null ? null : (bool) $row['last_ok'],
        ];
    }

    /** @param array<string, mixed> $report counts only */
    public static function recordRun(string $job, bool $ok, array $report): void
    {
        Db::run(
            'INSERT INTO ' . self::HEARTBEATS_TABLE . ' (job_name, last_started_at, last_finished_at, last_ok, last_report, runs, updated_at)
             VALUES (:n, NOW(), NOW(), :ok, CAST(:r AS JSONB), 1, NOW())
             ON CONFLICT (job_name) DO UPDATE SET last_finished_at = NOW(), last_ok = EXCLUDED.last_ok,
                 last_report = EXCLUDED.last_report, runs = ' . self::HEARTBEATS_TABLE . '.runs + 1, updated_at = NOW()',
            ['n' => $job, 'ok' => $ok ? 'true' : 'false', 'r' => (string) json_encode($report, JSON_UNESCAPED_SLASHES)],
        );
    }
}
