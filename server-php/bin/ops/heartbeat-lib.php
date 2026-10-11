<?php

declare(strict_types=1);

/**
 * AICOUNTLY ops kit — report a scheduled run to Console's Cron Job Monitor (E8-OPS: F160, F162, F262).
 *
 * CANONICAL COPY: console-react-app/ops/estate/kit/bin/ops/heartbeat-lib.php (identical in every product
 * at server-php/bin/ops/heartbeat-lib.php). Same protocol as Inventory's CronHeartbeat:
 *   POST {CONSOLE_API_BASE}/portal/cron-heartbeat   X-Console-Cron-Key: <key>
 *   {monitor, run_id, outcome: started|ok|error, started_at, finished_at, message, counts}
 * The monitor code must be registered in Console (console-react-app migration 059_e8op_product_cron_monitors).
 *
 * The key is CONSOLE_CRON_MONITOR_KEY, else CONSOLE_SERVICE_KEY (both accepted by Console's CronMonitorKey).
 * UNCONFIGURED MEANS INERT: no key, no HTTP — a laptop must never hold production's monitor green.
 * NEVER BREAKS THE JOB: every failure is swallowed (logged once), 5 s budget per POST.
 */

/**
 * @param callable(string):string $env reads one setting from the product's .env
 * @param array<string, scalar> $counts counts only — never a company id, name or payload
 */
function aic_ops_heartbeat(callable $env, string $monitor, string $runId, string $outcome, ?string $startedAt, ?string $finishedAt, string $message = '', array $counts = []): bool
{
    try {
        $key = trim($env('CONSOLE_CRON_MONITOR_KEY'));
        if ($key === '') {
            $key = trim($env('CONSOLE_SERVICE_KEY'));
        }
        if ($key === '') {
            return false;
        }
        $base = rtrim(trim($env('CONSOLE_API_BASE')) ?: 'https://console.aicountly.org/api', '/');
        $body = array_filter([
            'monitor'     => $monitor,
            'run_id'      => substr($runId, 0, 120),
            'outcome'     => $outcome,
            'started_at'  => $startedAt,
            'finished_at' => $finishedAt,
            'message'     => $message === '' ? null : substr($message, 0, 2000),
            'counts'      => $counts === [] ? null : array_slice($counts, 0, 50, true),
        ], static fn ($v) => $v !== null);
        $json = (string) json_encode($body, JSON_UNESCAPED_SLASHES);
        $ctx = stream_context_create(['http' => [
            'method'        => 'POST',
            'header'        => "Content-Type: application/json\r\nAccept: application/json\r\nX-Console-Cron-Key: {$key}\r\n",
            'content'       => $json,
            'timeout'       => 5,
            'ignore_errors' => true,
        ]]);
        $resp = @file_get_contents($base . '/portal/cron-heartbeat', false, $ctx);
        $status = 0;
        foreach ($http_response_header ?? [] as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m) === 1) {
                $status = (int) $m[1];
            }
        }
        if ($status < 200 || $status >= 300) {
            error_log('[heartbeat] Console did not accept ' . $monitor . ' ' . $outcome . ' (HTTP ' . $status . ')');

            return false;
        }

        return $resp !== false;
    } catch (\Throwable $e) {
        error_log('[heartbeat] ' . $monitor . ': ' . get_class($e));

        return false;
    }
}

function aic_ops_run_id(string $monitor): string
{
    return $monitor . '-' . gmdate('YmdHis') . '-' . bin2hex(random_bytes(4));
}
