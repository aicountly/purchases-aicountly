<?php
declare(strict_types=1);

namespace Aicountly\Api;

/*
 * What this product's database costs to hold — reported once a day to analytics.aicountly.org.
 *
 *   php bin/analytics-platform-snapshot.php                      # work it out, print it
 *   php bin/analytics-platform-snapshot.php --push                # and send it
 *   php bin/analytics-platform-snapshot.php --push --date=2026-11-01   # backfill one day
 *
 * ## Why this exists instead of the SDK's own CLI
 *
 * The SDK ships analytics-platform-collect.php, which reads DB_NAME / DB_USER straight out of
 * .env and connects for itself. For most of the estate that is correct. It is WRONG here:
 * on a deployed server this product's database name and username come from Console's SaaS
 * Database Details at runtime (src/Db.php and its Console resolver), and the DB_* lines in
 * .env are a local development fallback pointing somewhere else entirely. That CLI would have
 * connected to the wrong database and confidently reported its size — a wrong number that looks
 * exactly like a right one — or, finding no DB_NAME at all, mailed the operator "no database
 * found" every night. So it is not installed here (see `.no-env-cli` beside the SDK) and this
 * script takes its place.
 *
 * It asks Db::connect() for the connection the API itself uses, so whatever Console said at boot
 * is exactly what gets measured. There is deliberately no --db option: a collector that can be
 * pointed at another database is a collector that can report the wrong one.
 *
 * ## Nobody is in here
 *
 * Every line is a size in bytes or a count of rows. No customer data, no column values, no
 * identifiers of any kind are read or sent — see sdk/README.md §4b for the exact metric list.
 *
 * Analytics upserts on (date, source, metric, dimension), so running this twice is harmless and
 * re-running it after a fix corrects the day rather than doubling it.
 *
 * Installed into the crontab by the deploy from bin/ops/jobs.txt — there is no cron line to add
 * by hand.
 */

// A worker, never a web page. Before ANYTHING else, including the requires below: a script that
// loads half an application before deciding it should not have been reached over HTTP has already
// done the work an attacker wanted.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../src/Env.php';
require __DIR__ . '/../src/Autoload.php';
// The shared library: the counters, the dimension rules and the push. Copy-don't-edit.
require __DIR__ . '/../src/EstateAnalytics/analytics-platform-snapshot.php';

Env::load(__DIR__ . '/../.env');

$opts = getopt('', ['push', 'date::', 'tables::', 'exact-under::']);
$asOf = is_string($opts['date'] ?? null) && $opts['date'] !== '' ? $opts['date'] : date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $asOf)) {
    fwrite(STDERR, "--date must be YYYY-MM-DD\n");
    exit(2);
}

// Installed on every product, including those where analytics is not switched on yet. Those exit
// QUIETLY: a job that mails the operator an error every night until somebody configures it is a
// job the operator filters, and then nobody sees the night it breaks for a real reason.
//
// A dry run still reports, so running this by hand always shows the figures.
$enabled = Env::get('AICOUNTLY_ANALYTICS_ENABLED', '0');
if (isset($opts['push']) && !in_array(strtolower($enabled), ['1', 'true', 'yes', 'on'], true)) {
    echo "analytics is not enabled for this product (AICOUNTLY_ANALYTICS_ENABLED={$enabled}); nothing sent.\n";
    exit(0);
}

// pdo_pgsql is a separate extension from the pgsql one the application uses. Say so plainly
// rather than failing somewhere inside the library with a class-not-found.
if (!extension_loaded('pdo_pgsql')) {
    fwrite(STDERR, "pdo_pgsql is not loaded in this CLI; point the job at a PHP that has it\n"
        . "  (bash bin/ops/install-jobs.sh --php=/path/to/php --apply).\n");
    exit(3);
}

try {
    $pdo = Db::connect();
    $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
} catch (\Throwable $e) {
    // The message can name the host and the database, never the password.
    fwrite(STDERR, 'analytics platform snapshot: cannot reach the database (' . $e->getMessage() . ")\n");
    exit(1);
}

// Keyed by the DATABASE's real name, because that is the dimension the dashboard shows and
// "aicpurchases_prod" means something to an operator where "primary" does not. Asked of the
// connection rather than of .env, so it is the database actually in use.
$dbName = (string) $pdo->query('SELECT current_database()')->fetchColumn();

$snapshots = \aic_analytics_platform_snapshot([$dbName => $pdo], [
    'date'        => $asOf,
    'tables'      => (int) ($opts['tables'] ?? 25),
    'exact_under' => (int) ($opts['exact-under'] ?? 1000000),
]);

printf("%s  %s  (%d counters, 1 database)\n", $asOf, $dbName, count($snapshots));
foreach ($snapshots as $s) {
    printf("  %-26s %-28s %s\n", $s['metric'], $s['dimension'] ?? '-', number_format($s['value']));
}

if (!isset($opts['push'])) {
    echo "\nDry run. Pass --push to send.\n";
    exit(0);
}

// The analytics settings ARE ordinary .env values, unlike the database login.
$key = Env::get('AICOUNTLY_ANALYTICS_KEY');
if ($key === '') {
    fwrite(STDERR, "AICOUNTLY_ANALYTICS_KEY is not set in .env; nothing sent.\n");
    exit(1);
}
[$code, $body] = \aic_analytics_platform_push(
    $snapshots,
    Env::get('AICOUNTLY_ANALYTICS_URL', 'https://analytics.aicountly.org/api'),
    $key,
    Env::get('AICOUNTLY_ANALYTICS_ENVIRONMENT', 'production')
);

echo "\nHTTP {$code}\n" . substr($body, 0, 800) . "\n";
// A failed push is not fatal: tomorrow's run reports the same day again and the upsert makes that
// a correction rather than a duplicate. Non-zero so cron mail says something went wrong.
exit($code >= 200 && $code < 300 ? 0 : 1);
