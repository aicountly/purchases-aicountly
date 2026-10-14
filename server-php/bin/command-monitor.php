<?php

declare(strict_types=1);

/**
 * Cross-app command monitor (E8-OPS, F262) — run from cron every five minutes (bin/ops/jobs.txt).
 *
 *   php bin/command-monitor.php            one pass; exit 0 when nothing is stale, 1 when something is
 *   php bin/command-monitor.php --json     the same, as JSON
 *
 * A command to another product (Inventory, Books, Pay ...) that did not complete stays open until
 * somebody opens its document and presses Retry or Reconcile (IntegrationCommand: "WHY NO CRON").
 * That design is kept — a retry is still a person's decision — but nobody used to be TOLD. This
 * pass counts open commands untouched for longer than COMMAND_STALE_MINUTES (default 15), by status
 * and kind, records the result (health: `commands`, `monitor`) and reports to Console's Cron Job
 * Monitor as `purchases.command_monitor`: outcome `error` while anything is stale, so Console's alert
 * badge turns red without anyone opening the document. Counts only — no company, document or payload.
 */

namespace Aicountly\Api;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../src/Env.php';
require __DIR__ . '/../src/Autoload.php';
require __DIR__ . '/ops/heartbeat-lib.php';

use Aicountly\Api\Ops\OpsStatus;

Env::load(__DIR__ . '/../.env');

$json = in_array('--json', $argv, true);
$monitor = 'purchases.command_monitor';
$runId = \aic_ops_run_id($monitor);
$started = gmdate('c');
$env = static fn (string $k): string => Env::get($k);
\aic_ops_heartbeat($env, $monitor, $runId, 'started', $started, null);

try {
    // Commands kept because Books / Inventory hold the company read-only (company lifecycle): ask
    // Manage about each such company at most hourly, and make them sendable again on renewal.
    $restricted = IntegrationCommand::reviewRestricted();
    $commands = OpsStatus::commands();
    if (!($commands['readable'] ?? false)) {
        throw new \RuntimeException('the command table could not be read');
    }
    $kinds = Db::all(
        'SELECT command_type, status, COUNT(*) AS n, COUNT(DISTINCT cmp_id) AS companies
           FROM ' . OpsStatus::COMMANDS_TABLE . "
          WHERE status NOT IN ('" . implode("','", OpsStatus::CLOSED) . "')
            AND updated_at < NOW() - make_interval(secs => :s)
          GROUP BY command_type, status ORDER BY n DESC LIMIT 20",
        ['s' => OpsStatus::staleAfterSeconds()],
    );
    $report = [
        'restricted' => $restricted,
        'open'      => $commands['open'],
        'stale'     => $commands['stale'],
        'oldest_open_age_seconds' => $commands['oldest_open_age_seconds'],
        'stale_by_status' => $commands['stale_by_status'],
        'stale_by_kind' => array_map(static fn (array $r): array => [
            'kind' => (string) $r['command_type'], 'status' => (string) $r['status'],
            'commands' => (int) $r['n'], 'companies' => (int) $r['companies'],
        ], $kinds),
    ];
    $ok = $commands['stale'] === 0;
    OpsStatus::recordRun(OpsStatus::MONITOR, $ok, $report);
    $message = $ok ? 'no cross-app command is stale'
        : sprintf('%d cross-app command(s) open for more than %d minutes (%s) — open the document and press Retry or Reconcile',
            $commands['stale'], intdiv(OpsStatus::staleAfterSeconds(), 60),
            implode(', ', array_map(static fn ($s, $n) => "{$s} {$n}", array_keys($commands['stale_by_status']), $commands['stale_by_status'])));
    \aic_ops_heartbeat($env, $monitor, $runId, $ok ? 'ok' : 'error', $started, gmdate('c'), $message,
        ['open' => $commands['open'], 'stale' => $commands['stale']] + $commands['stale_by_status']);
    echo $json ? json_encode(['ok' => $ok, 'message' => $message] + $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n" : $message . "\n";
    exit($ok ? 0 : 1);
} catch (\Throwable $e) {
    error_log('[command-monitor] ' . $e->getMessage());
    try {
        OpsStatus::recordRun(OpsStatus::MONITOR, false, ['error' => 'unreadable']);
    } catch (\Throwable) {
    }
    \aic_ops_heartbeat($env, $monitor, $runId, 'error', $started, gmdate('c'), 'command monitor could not read the commands: ' . get_class($e));
    fwrite(STDERR, "command-monitor: {$e->getMessage()}\n");
    exit(2);
}
