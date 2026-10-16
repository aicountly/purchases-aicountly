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
    /** Settings the cross-app journeys need (presence only). */
    public const REQUIRED_CONFIG = ['INVENTORY_SERVICE_KEY', 'BOOKS_SERVICE_KEY', 'PULSE_SERVICE_KEY'];

    public static function revision(): ?string
    {
        $file = __DIR__ . '/../../REVISION';
        if (!is_file($file)) {
            return null;
        }
        $rev = trim((string) file_get_contents($file));

        return preg_match('/^[0-9a-f]{7,40}$/', $rev) === 1 ? $rev : null;
    }

    /** @return array{items: array<string,bool>, ready: bool} */
    public static function config(): array
    {
        $items = [];
        foreach (self::REQUIRED_CONFIG as $name) {
            $value = trim(Env::get($name));
            $items[$name] = $value !== '' && !str_starts_with($value, 'CHANGE_ME');
        }

        return ['items' => $items, 'ready' => !in_array(false, $items, true)];
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
