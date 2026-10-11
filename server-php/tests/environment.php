<?php

declare(strict_types=1);

/**
 * E8-ID: the environment a deployment talks to is chosen by server configuration, never by the
 * request's Host header (A20 / F062 / F210 / F284 / F413), and a 2xx whose body cannot be read is
 * an unknown outcome, never a success (A10).
 *
 *   php server-php/tests/environment.php      (tests/run.sh runs it too)
 *
 * Unit part: every concrete ApiClient in src/Clients, under every class of Host value, with
 * production / sandbox / local / unset / unrecognised configuration. HTTP part: a real HTTP
 * listener (php -S, this file's sibling fault router) answering 200 text/html, 200 empty,
 * 200 "true", 200 {} and 204, driven through the product's own ApiClient::request() over the wire.
 */

namespace Aicountly\Api;

require __DIR__ . '/../src/Env.php';
require __DIR__ . '/../src/Autoload.php';

use Aicountly\Api\Clients\ApiClient;

Env::load(__DIR__ . '/no-such-file.env');
$clearVars = ['APP_ENV', 'AIC_ENVIRONMENT'];
foreach (glob(__DIR__ . '/../src/Clients/*.php') ?: [] as $file) {
    if (preg_match_all("/'([A-Z_]+_API_BASE)'/", (string) file_get_contents($file), $m)) {
        array_push($clearVars, ...$m[1]);
    }
}
foreach (array_unique($clearVars) as $name) {
    putenv($name);
}
unset($_SERVER['HTTP_HOST']);

$passed = 0;
$failed = 0;

function check(string $name, callable $fn): void
{
    global $passed, $failed;
    try {
        $fn();
        echo "  ok    {$name}\n";
        $passed++;
    } catch (\Throwable $e) {
        echo "  FAIL  {$name}\n        {$e->getMessage()} (" . basename($e->getFile()) . ':' . $e->getLine() . ")\n";
        $failed++;
    } finally {
        putenv('APP_ENV');
        putenv('AIC_ENVIRONMENT');
        unset($_SERVER['HTTP_HOST']);
        if (method_exists(ApiClient::class, 'intercept')) {
            ApiClient::intercept(null);
        }
    }
}

function same(mixed $expected, mixed $actual, string $what): void
{
    if ($expected !== $actual) {
        throw new \RuntimeException($what . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

/** @return list<ApiClient> every concrete client constructible without arguments */
function clients(): array
{
    $out = [];
    foreach (glob(__DIR__ . '/../src/Clients/*.php') ?: [] as $file) {
        $class = 'Aicountly\\Api\\Clients\\' . basename($file, '.php');
        if (!class_exists($class)) {
            continue;
        }
        $ref = new \ReflectionClass($class);
        if ($ref->isAbstract() || !$ref->isSubclassOf(ApiClient::class)) {
            continue;
        }
        $ctor = $ref->getConstructor();
        if ($ctor !== null && $ctor->getNumberOfRequiredParameters() > 0) {
            continue;
        }
        $out[] = $ref->newInstance();
    }
    if (count($out) < 2) {
        throw new \RuntimeException('expected at least two sibling clients, found ' . count($out));
    }

    return $out;
}

function call(object $o, string $method): mixed
{
    $m = new \ReflectionMethod($o, $method);
    $m->setAccessible(true);

    return $m->invoke($o);
}

const HOSTS = [null, '', 'anything.gh.aicountly.com', 'gh-anything.aicountly.com', 'localhost:5173', '127.0.0.1:8080', 'preview.example.net', 'purchase.aicountly.com'];

check('APP_ENV=production: every sibling resolves to production for EVERY Host value (A20)', function (): void {
    putenv('APP_ENV=production');
    foreach (clients() as $client) {
        $production = (string) call($client, 'productionBase');
        foreach (HOSTS as $host) {
            if ($host === null) {
                unset($_SERVER['HTTP_HOST']);
            } else {
                $_SERVER['HTTP_HOST'] = $host;
            }
            same($production, $client->base(), $client->service() . ' with Host ' . var_export($host, true));
            same(null, $client->baseProblem(), $client->service() . ' has no base problem');
        }
    }
});

check('APP_ENV=sandbox (and local/development) resolves to sandbox whatever the Host says', function (): void {
    foreach (['sandbox', 'staging', 'local', 'development', 'testing'] as $env) {
        putenv('APP_ENV=' . $env);
        foreach (clients() as $client) {
            $sandbox = (string) call($client, 'sandboxBase');
            foreach (HOSTS as $host) {
                $host === null ? (function () { unset($_SERVER['HTTP_HOST']); })() : ($_SERVER['HTTP_HOST'] = $host);
                same($sandbox, $client->base(), $client->service() . " under APP_ENV={$env}, Host " . var_export($host, true));
            }
        }
    }
});

check('AIC_ENVIRONMENT (the estate-wide name) wins over APP_ENV', function (): void {
    putenv('APP_ENV=sandbox');
    putenv('AIC_ENVIRONMENT=production');
    foreach (clients() as $client) {
        same((string) call($client, 'productionBase'), $client->base(), $client->service());
    }
    putenv('APP_ENV=production');
    putenv('AIC_ENVIRONMENT=sandbox');
    foreach (clients() as $client) {
        same((string) call($client, 'sandboxBase'), $client->base(), $client->service());
    }
});

check('unset APP_ENV is production (reported by health as source=default); a CLI with no Host never reaches sandbox', function (): void {
    foreach (clients() as $client) {
        same((string) call($client, 'productionBase'), $client->base(), $client->service());
    }
    same('default', Environment::resolve()['source'], 'source');
});

check('an unrecognised APP_ENV fails closed: no base, and request() refuses without touching the network', function (): void {
    putenv('APP_ENV=prodution');
    $calls = 0;
    if (method_exists(ApiClient::class, 'intercept')) {
        ApiClient::intercept(function () use (&$calls) {
            $calls++;

            return ['ok' => true, 'status' => 200, 'body' => [], 'error' => null];
        });
    }
    foreach (clients() as $client) {
        same('', $client->base(), $client->service() . ' base');
        same('environment_not_configured', $client->baseProblem(), $client->service() . ' problem');
        $r = $client->request('GET', 'anything');
        same(false, $r['ok'], $client->service() . ' refused');
        same(0, $r['status'], $client->service() . ' status (unavailable, never a refusal)');
        same($client->service() . '_environment_not_configured', $r['error'], $client->service() . ' error');
    }
    same(0, $calls, 'nothing was sent');
    $report = Environment::report(['X_API_BASE' => null]);
    same(false, $report['ok'], 'health is not ok');
    same('environment_unrecognised', $report['problems'][0]['problem'], 'health names the problem');
});

check('an explicit *_API_BASE still wins; production refuses one that points at sandbox or loopback (F062)', function (): void {
    putenv('APP_ENV=production');
    $client = clients()[0];
    $var = $client->baseVariable();
    try {
        putenv($var . '=https://pinned.example.com/api/');
        same('https://pinned.example.com/api', $client->base(), 'pinned base wins');
        same(null, $client->baseProblem(), 'a production host is fine');
        foreach (['https://books.gh.aicountly.com', 'https://gh-books.aicountly.com', 'http://127.0.0.1:9000', 'http://localhost'] as $bad) {
            putenv($var . '=' . $bad);
            same('production_base_not_production', $client->baseProblem(), $bad);
            $r = $client->request('POST', 'v1/anything', ['a' => 1]);
            same(false, $r['ok'], $bad . ' refused');
            same(0, $r['status'], $bad . ' status');
        }
        $report = Environment::report([$var => $client->base()]);
        same('production_base_not_production', $report['problems'][0]['problem'] ?? null, 'health reports it');
        putenv('APP_ENV=local');
        putenv($var . '=http://127.0.0.1:9000');
        same(null, $client->baseProblem(), 'a local deployment may use a loopback base');
    } finally {
        putenv($var);
    }
});

check('no source file under src/ derives an environment or origin from HTTP_HOST', function (): void {
    $offenders = [];
    $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(__DIR__ . '/../src'));
    foreach ($it as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php' || $file->getFilename() === 'Environment.php') {
            continue;
        }
        foreach (file($file->getPathname()) ?: [] as $i => $line) {
            $code = ltrim($line);
            if (str_starts_with($code, '*') || str_starts_with($code, '//') || str_starts_with($code, '/*')) {
                continue;
            }
            if (str_contains($line, "'HTTP_HOST'")) {
                $offenders[] = $file->getFilename() . ':' . ($i + 1);
            }
        }
    }
    same([], $offenders, 'HTTP_HOST readers');
});

// ---------------------------------------------------------------------------------------------
// A10 over real HTTP
// ---------------------------------------------------------------------------------------------

$port = (int) (getenv('E8ID_FAULT_PORT') ?: 33911);
$router = __DIR__ . '/support/fault_2xx_router.php';
$proc = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, $router], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
for ($i = 0; $i < 40; $i++) {
    $fp = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
    if ($fp) {
        fclose($fp);
        break;
    }
    usleep(100000);
}

check('a 2xx that is not JSON (HTML, empty, a bare scalar) is ok=false 502 unreadable_response, outcome_unknown (A10)', function () use ($port): void {
    putenv('APP_ENV=local');
    $client = clients()[0];
    $var = $client->baseVariable();
    putenv($var . '=http://127.0.0.1:' . $port);
    try {
        foreach (['html', 'empty', 'scalar'] as $case) {
            $r = $client->request('POST', 'fault/' . $case, ['x' => 1], ['Idempotency-Key' => 'e8id-test-' . $case], true);
            same(false, $r['ok'], $case . ' ok');
            same(502, $r['status'], $case . ' status');
            same('unreadable_response', $r['error'], $case . ' error');
            same(true, $r['outcome_unknown'] ?? null, $case . ' outcome_unknown');
        }
        $r = $client->request('POST', 'fault/object', ['x' => 1]);
        same(true, $r['ok'], 'a JSON object is still a readable answer');
        $r = $client->request('DELETE', 'fault/nocontent');
        same(true, $r['ok'], '204 carries no body and is fine');
        $r = $client->request('GET', 'fault/html');
        same(false, $r['ok'], 'a GET that answers HTML is unavailable too');
    } finally {
        putenv($var);
    }
});

if (class_exists(IntegrationCommand::class) && method_exists(IntegrationCommand::class, 'classify')) {
    check('IntegrationCommand classifies an unreadable 2xx as uncertain (same key, reconcile) — never completed, never blocked', function (): void {
        $outcome = IntegrationCommand::classify(['ok' => false, 'status' => 502, 'body' => null, 'error' => 'unreadable_response', 'outcome_unknown' => true]);
        same(true, in_array($outcome, ['uncertain', 'UNCERTAIN', 'outcome_uncertain'], true), 'classified as ' . var_export($outcome, true));
    });
}

proc_terminate($proc);
proc_close($proc);

echo "\n" . str_repeat('-', 60) . "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
