<?php
declare(strict_types=1);

/*
 * What this product's database costs to hold — the command-line entry point.
 *
 *   php analytics-platform-collect.php --env=/path/to/api/.env                 # work it out, print it
 *   php analytics-platform-collect.php --env=… --push                          # and send it
 *   php analytics-platform-collect.php --env=… --push --date=2026-11-01        # backfill one day
 *   php analytics-platform-collect.php --env=… --db=DB --db=DB_AUTH            # name the databases
 *   php analytics-platform-collect.php --env=… --storage-dir=/home/x/uploads   # and object storage
 *
 * Copy unchanged beside analytics-platform-snapshot.php, which holds the counters themselves
 * (sdk/README.md §4b). Installed by the deploy from bin/ops/jobs.txt — there is no cron line to
 * add by hand.
 *
 * THIS FILE OPENS A DATABASE CONNECTION FROM .env. Products that take their live database name
 * and login from Console's SaaS Database Details at runtime must NOT use it: on those hosts the
 * DB_* lines in .env are a local development fallback pointing somewhere else entirely, and this
 * would confidently report the size of the wrong database. They copy the library alone and hand
 * it a connection their own application opened — see platform-snapshot.codeigniter.php in
 * templates/, and manage-aicountly's own collector for a worked example.
 */

require_once __DIR__ . '/analytics-platform-snapshot.php';

$opts = getopt('', ['push', 'env::', 'date::', 'db::', 'tables::', 'exact-under::', 'storage-dir::', 'source::']);
$argvRaw = array_slice($argv, 1);

$envPath = is_string($opts['env'] ?? null) && $opts['env'] !== '' ? $opts['env'] : __DIR__ . '/../.env';
if (!is_file($envPath) || !is_readable($envPath)) {
    fwrite(STDERR, "analytics-platform-snapshot: cannot read {$envPath} (pass --env=/path/to/api/.env)\n");
    exit(2);
}
$env = aic_analytics_read_env($envPath);
$get = static fn (string $k, string $d = ''): string => $env[$k] ?? (string) (getenv($k) ?: $d);

// Installed into every product's crontab by the deploy, including products where analytics has
// not been switched on yet. Those exit QUIETLY: a job that mails the operator an error every
// night until somebody configures it is a job the operator filters, and then nobody sees the
// night it breaks for a real reason. Same posture as analytics-flush.php.
//
// A dry run still reports, so `--env=... ` by hand always shows the figures whatever the flag says.
$enabled = $get('AICOUNTLY_ANALYTICS_ENABLED', '0');
if (isset($opts['push']) && !in_array(strtolower($enabled), ['1', 'true', 'yes', 'on'], true)) {
    echo "analytics is not enabled for this product (AICOUNTLY_ANALYTICS_ENABLED={$enabled}); nothing sent.\n";
    exit(0);
}

$asOf = is_string($opts['date'] ?? null) && $opts['date'] !== '' ? $opts['date'] : date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $asOf)) {
    fwrite(STDERR, "--date must be YYYY-MM-DD\n");
    exit(2);
}

// Which databases. Named with --db=PREFIX (repeatable), or discovered from the .env: any
// PREFIX_NAME that also has a PREFIX_USER beside it is a database this product connects to.
$prefixes = [];
foreach ($argvRaw as $arg) {
    if (str_starts_with($arg, '--db=') && substr($arg, 5) !== '') {
        $prefixes[] = substr($arg, 5);
    }
}
if ($prefixes === []) {
    foreach (array_keys($env) as $key) {
        // A product may prefix its keys with its own name: Engage uses ENGAGE_DB_NAME, not DB_NAME.
        // Anchoring the pattern at DB_ missed those entirely and the collector reported "no
        // database found" on a host where the database was plainly configured.
        if (preg_match('/^([A-Z0-9_]*DB(?:_[A-Z0-9]+)*)_NAME$/', $key, $m) && isset($env[$m[1] . '_USER'])) {
            $prefixes[] = $m[1];
        }
    }
}
if ($prefixes === []) {
    fwrite(STDERR, "analytics-platform-snapshot: no database found in {$envPath}.\n"
        . "  Expected DB_NAME and DB_USER (or PREFIX_NAME / PREFIX_USER), or pass --db=PREFIX.\n"
        . "  If this product takes its database login from Console at runtime, use the wrapper in\n"
        . "  templates/platform-snapshot.*.php instead of this CLI.\n");
    exit(2);
}

$databases = [];
foreach (array_unique($prefixes) as $prefix) {
    $name = $get($prefix . '_NAME');
    $user = $get($prefix . '_USER');
    if ($name === '' || $user === '') {
        fwrite(STDERR, "analytics-platform-snapshot: {$prefix}_NAME / {$prefix}_USER are not set; skipping.\n");
        continue;
    }
    $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s',
        $get($prefix . '_HOST', '127.0.0.1'), $get($prefix . '_PORT', '5432'), $name);
    if ($get($prefix . '_SSLMODE') !== '') {
        $dsn .= ';sslmode=' . $get($prefix . '_SSLMODE');
    }
    try {
        // Keyed by the DATABASE's name, not the env prefix: the dashboard shows this, and
        // "aicbooks_prod" means something to an operator where "DB_SECONDARY" does not.
        // _PASS or _PASSWORD: both spellings are in use across the estate (Engage writes
        // ENGAGE_DB_PASSWORD), and guessing one silently produces an authentication failure that
        // reads like a wrong password rather than a key this script never looked at.
        $pass = $get($prefix . '_PASS') !== '' ? $get($prefix . '_PASS') : $get($prefix . '_PASSWORD');
        $databases[$name] = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
    } catch (Throwable $e) {
        // The message can carry the host and database name, never the password.
        fwrite(STDERR, "analytics-platform-snapshot: cannot connect to {$name} ({$e->getMessage()})\n");
    }
}
if ($databases === []) {
    fwrite(STDERR, "analytics-platform-snapshot: no database could be reached; nothing to report.\n");
    exit(1);
}

$storage = [];
foreach ($argvRaw as $arg) {
    if (str_starts_with($arg, '--storage-dir=') && substr($arg, 14) !== '') {
        $dir = substr($arg, 14);
        $storage[basename(rtrim($dir, '/')) ?: 'storage'] = $dir;
    }
}

$snapshots = aic_analytics_platform_snapshot($databases, [
    'date'        => $asOf,
    'tables'      => (int) ($opts['tables'] ?? 25),
    'exact_under' => (int) ($opts['exact-under'] ?? 1000000),
    'storage'     => $storage,
]);

$source = is_string($opts['source'] ?? null) && $opts['source'] !== ''
    ? $opts['source'] : $get('AICOUNTLY_ANALYTICS_SOURCE', '(from the service key)');

printf("%s  %s  (%d counters, %d database(s))\n", $asOf, $source, count($snapshots), count($databases));
foreach ($snapshots as $s) {
    printf("  %-26s %-28s %s\n", $s['metric'], $s['dimension'] ?? '-', number_format($s['value']));
}

if (!isset($opts['push'])) {
    echo "\nDry run. Pass --push to send.\n";
    exit(0);
}

$key = $get('AICOUNTLY_ANALYTICS_KEY');
if ($key === '') {
    fwrite(STDERR, "AICOUNTLY_ANALYTICS_KEY is not set in {$envPath}; nothing sent.\n");
    exit(1);
}
[$code, $body] = aic_analytics_platform_push(
    $snapshots,
    $get('AICOUNTLY_ANALYTICS_URL', 'https://analytics.aicountly.org/api'),
    $key,
    $get('AICOUNTLY_ANALYTICS_ENVIRONMENT', 'production')
);

echo "\nHTTP {$code}\n" . substr($body, 0, 800) . "\n";
// A failed push is not fatal to anything: tomorrow's run reports the same day again and the
// upsert makes that a correction rather than a duplicate. Exit non-zero so cron mail says so.
exit($code >= 200 && $code < 300 ? 0 : 1);
