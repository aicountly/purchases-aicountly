<?php

declare(strict_types=1);

/**
 * E8-ID: identity outage semantics over REAL HTTP, through this product's own front controller.
 *
 *   my.aicountly says "not a session" (401)                  -> 401, sign in again
 *   my.aicountly cannot say (5xx, HTML/empty 200, timeout)   -> 503 + Retry-After, keep the session
 *   Manage refuses (its own 404/401/403 JSON)                -> 403
 *   Manage cannot say (5xx, HTML/empty 200)                  -> 503 (F493), never "you lost access"
 *
 * The product API runs under `php -S` on its real index.php against the suite's PostgreSQL
 * database (.env written by tests/run.sh). PORTAL_AUTH_BASE and MANAGE_API_BASE point at
 * tests/support/fault_identity_router.php, which passes every request to the suite's own stub
 * (tests/stub/router.php) unless a fault is switched on for that upstream.
 *
 *   php server-php/tests/identity_outage.php        (tests/run.sh runs it too)
 */

$root = dirname(__DIR__);
$probe = getenv('E8ID_PROBE_ROUTE') ?: 'v1/permissions?cmp_id=1&fy_id=6';
$bearer = getenv('E8ID_PROBE_BEARER') ?: 'stub-ses-key';
$apiPort = (int) (getenv('E8ID_API_PORT') ?: 33921);
$faultPort = (int) (getenv('E8ID_IDENTITY_FAULT_PORT') ?: 33922);
$modeFile = sys_get_temp_dir() . '/e8id_identity_modes_' . getmypid() . '.json';
file_put_contents($modeFile, '{}');

$passed = 0;
$failed = 0;
$procs = [];

function start(array $cmd, array $env, string $docroot): mixed
{
    $proc = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $docroot, $env + getenv());

    return $proc;
}

function waitPort(int $port): void
{
    for ($i = 0; $i < 60; $i++) {
        $fp = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
        if ($fp) {
            fclose($fp);

            return;
        }
        usleep(100000);
    }
    throw new RuntimeException('port ' . $port . ' never opened');
}

$fault = 'http://127.0.0.1:' . $faultPort;
$procs[] = start([PHP_BINARY, '-S', '127.0.0.1:' . $faultPort, __DIR__ . '/support/fault_identity_router.php'], [
    'FAULT_MODE_FILE' => $modeFile,
    'FAULT_PASS_ROUTER' => __DIR__ . '/stub/router.php',
    'PHP_CLI_SERVER_WORKERS' => '4',
] + array_filter(['STUB_PULSE_SERVICE_KEY' => getenv('STUB_PULSE_SERVICE_KEY') ?: null]), __DIR__);
$apiEnv = [
    'PORTAL_AUTH_BASE' => $fault,
    'MANAGE_API_BASE' => $fault,
    'PHP_CLI_SERVER_WORKERS' => '4',
];
$procs[] = start([PHP_BINARY, '-S', '127.0.0.1:' . $apiPort, $root . '/index.php'], $apiEnv, $root);
waitPort($faultPort);
waitPort($apiPort);

/** @return array{status:int, retry_after:?string, body:mixed, ms:int} */
function probe(int $port, string $route, string $bearer): array
{
    $ch = curl_init('http://127.0.0.1:' . $port . '/' . (getenv('E8ID_API_PREFIX') ?: '') . $route);
    $retry = null;
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $bearer, 'Accept: application/json'],
        CURLOPT_TIMEOUT => 40,
        CURLOPT_HEADERFUNCTION => static function ($h, string $line) use (&$retry): int {
            if (stripos($line, 'retry-after:') === 0) {
                $retry = trim(substr($line, 12));
            }

            return strlen($line);
        },
    ]);
    $t = microtime(true);
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    return ['status' => $status, 'retry_after' => $retry, 'body' => json_decode((string) $raw, true), 'ms' => (int) ((microtime(true) - $t) * 1000)];
}

function check(string $name, callable $fn): void
{
    global $passed, $failed;
    try {
        $fn();
        echo "  ok    {$name}\n";
        $passed++;
    } catch (Throwable $e) {
        echo "  FAIL  {$name}\n        {$e->getMessage()}\n";
        $failed++;
    }
}

function modes(string $file, array $m): void
{
    file_put_contents($file, json_encode($m));
}

function expectStatus(int $want, array $r, string $what): void
{
    if ($r['status'] !== $want) {
        throw new RuntimeException($what . ': expected HTTP ' . $want . ', got ' . $r['status'] . ' ' . json_encode($r['body']));
    }
}

try {
    check('baseline: the stub portal and stub Manage let the probe through (not 401/403/503)', function () use ($apiPort, $probe, $bearer, $modeFile) {
        modes($modeFile, []);
        $r = probe($apiPort, $probe, $bearer);
        if ($r['status'] < 200 || $r['status'] >= 300) {
            throw new RuntimeException('baseline answered ' . $r['status'] . ' ' . json_encode($r['body']));
        }
    });

    check('my.aicountly refuses the session (its own 401) -> 401, sign in again', function () use ($apiPort, $probe, $bearer, $modeFile) {
        modes($modeFile, ['portal' => 'refused401']);
        expectStatus(401, probe($apiPort, $probe, $bearer), 'refused');
    });

    foreach (['http502', 'http503', 'html200', 'empty200', 'timeout'] as $fault) {
        check("my.aicountly cannot answer ({$fault}) -> 503 + Retry-After, never 401", function () use ($apiPort, $probe, $bearer, $modeFile, $fault) {
            modes($modeFile, ['portal' => $fault]);
            $r = probe($apiPort, $probe, $bearer);
            expectStatus(503, $r, $fault);
            if ($r['retry_after'] === null || (int) $r['retry_after'] < 1) {
                throw new RuntimeException('no Retry-After');
            }
            if ($fault === 'timeout' && $r['ms'] > 12000) {
                throw new RuntimeException('validatesession timeout held the request ' . $r['ms'] . ' ms (bound: 12 s)');
            }
        });
    }

    check('after the outage the same session works again (no negative caching)', function () use ($apiPort, $probe, $bearer, $modeFile) {
        modes($modeFile, ['portal' => 'http503']);
        expectStatus(503, probe($apiPort, $probe, $bearer), 'during');
        modes($modeFile, []);
        $r = probe($apiPort, $probe, $bearer);
        if ($r['status'] < 200 || $r['status'] >= 300) {
            throw new RuntimeException('after recovery answered ' . $r['status']);
        }
    });

    check('Manage refuses the company (its own 404 JSON) -> 403', function () use ($apiPort, $probe, $bearer, $modeFile) {
        modes($modeFile, ['manage' => 'json404']);
        expectStatus(403, probe($apiPort, $probe, $bearer), 'refused');
    });

    foreach (['html200', 'empty200', 'http500', 'http502'] as $fault) {
        check("Manage cannot answer ({$fault}) -> 503, never 403 (F493)", function () use ($apiPort, $probe, $bearer, $modeFile, $fault) {
            modes($modeFile, ['manage' => $fault]);
            expectStatus(503, probe($apiPort, $probe, $bearer), $fault);
        });
    }
} finally {
    foreach ($procs as $p) {
        proc_terminate($p);
        proc_close($p);
    }
    @unlink($modeFile);
}

echo "\n" . str_repeat('-', 60) . "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
