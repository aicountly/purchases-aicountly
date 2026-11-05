<?php
declare(strict_types=1);

/*
 * What this product's database costs to hold — pushed to analytics.aicountly.org once a day.
 *
 *   php analytics-platform-snapshot.php --env=/path/to/api/.env                 # work it out, print it
 *   php analytics-platform-snapshot.php --env=… --push                          # and send it
 *   php analytics-platform-snapshot.php --env=… --push --date=2026-11-01        # backfill one day
 *   php analytics-platform-snapshot.php --env=… --db=DB --db=DB_AUTH            # name the databases
 *   php analytics-platform-snapshot.php --env=… --storage-dir=/home/x/uploads   # and object storage
 *
 * Copy unchanged beside AicountlyAnalytics.php (sdk/README.md §1). Cron, once a day, after
 * midnight IST — stagger the minute per product so forty hosts do not all start at once:
 *
 *   23 1 * * *  php /path/to/analytics-platform-snapshot.php --env=/path/to/api/.env --push \
 *                 >> /path/to/writable/logs/analytics-platform.log 2>&1
 *
 * ## Why this exists
 *
 * The SDK reports EVENTS: somebody did something. No number of events can answer "how big is the
 * database" or "how many invoice rows exist", because those are not behaviours — nobody performs
 * them. So the system that OWNS each fact reports it directly, once a day, and for a database
 * that owner is the product sitting on top of it (analytics docs/08).
 *
 * Analytics upserts on (date, source, metric, dimension), so running this twice is harmless and
 * re-running it after a fix corrects the day rather than doubling it.
 *
 * ## No person is in here
 *
 * Every line is a COUNT or a SIZE. Table NAMES travel (they are the breakdown), row CONTENT never
 * does, and there is no identifier of any kind in the payload — which makes this the safest data
 * in the warehouse rather than the riskiest (analytics docs/05, docs/08).
 *
 * ## Deployed servers whose credentials come from Console
 *
 * Several products take the live database name and login from Console's SaaS Database Details at
 * runtime, not from DB_NAME in .env. On those, this file is not the entry point: the product's
 * own wrapper opens the connection the way the app does and calls
 * aic_analytics_platform_snapshot() with it. See templates/platform-snapshot.*.php.
 */

// ─────────────────────────────────────────────────────────────────────────────
// The library — give it open connections, get back the counters
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Build the platform snapshot for one product.
 *
 * @param  array<string,PDO> $databases  label (the dimension) => open connection. The label is
 *                                       what appears on the dashboard, so it should be the
 *                                       database's name or a word the operator would recognise.
 * @param  array{date?:string, tables?:int, exact_under?:int, storage?:array<string,string>} $opts
 * @return list<array{metric:string, as_of_date:string, value:int, dimension?:string}>
 */
function aic_analytics_platform_snapshot(array $databases, array $opts = []): array
{
    $asOf        = (string) ($opts['date'] ?? date('Y-m-d'));
    $topTables   = max(1, (int) ($opts['tables'] ?? 25));
    $exactUnder  = max(0, (int) ($opts['exact_under'] ?? 1000000));
    $out         = [];
    $add = static function (string $metric, int $value, string $dimension = '') use (&$out, $asOf): void {
        // Analytics refuses a negative count, rightly: there is no such thing as minus four rows.
        $out[] = ['metric' => $metric, 'as_of_date' => $asOf, 'value' => max(0, $value)]
            + ($dimension !== '' ? ['dimension' => $dimension] : []);
    };

    $databaseCount = 0;
    $bytesTotal    = 0;
    $rowsTotal     = 0;
    $tablesTotal   = 0;

    foreach ($databases as $label => $pdo) {
        $label = aic_analytics_dimension($label);
        if ($label === '') {
            continue;
        }
        try {
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $size = (int) $pdo->query('SELECT pg_database_size(current_database())')->fetchColumn();
        } catch (Throwable) {
            // One unreachable database must not cost the night's run for the others.
            continue;
        }
        $databaseCount++;
        $bytesTotal += $size;
        $add('db.size_bytes', $size, $label);

        // relkind='r' is ordinary tables, and a partition child is one — so this reaches the rows
        // and bytes that actually exist. The partitioned PARENT ('p') holds neither and is left
        // out, which is what stops a partitioned table being counted twice.
        try {
            $tables = $pdo->query(
                "SELECT n.nspname AS schema, c.relname AS name,
                        pg_total_relation_size(c.oid) AS bytes,
                        c.reltuples::bigint           AS estimate
                   FROM pg_class c
                   JOIN pg_namespace n ON n.oid = c.relnamespace
                  WHERE c.relkind = 'r'
                    AND n.nspname NOT IN ('pg_catalog', 'information_schema')
                    AND n.nspname NOT LIKE 'pg_toast%'
               ORDER BY pg_total_relation_size(c.oid) DESC"
            )->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            continue;
        }

        $tablesTotal += count($tables);
        $add('db.table_count', count($tables), $label);

        $reported = 0;
        foreach ($tables as $t) {
            $rows = aic_analytics_row_count($pdo, (string) $t['schema'], (string) $t['name'],
                (int) $t['estimate'], $exactUnder);
            $rowsTotal += $rows;

            // Only the biggest tables get a row of their own. A product with four hundred tables
            // would otherwise push eight hundred counters a night to say nothing anyone reads,
            // and the UNDIMENSIONED totals below still cover every table in the database.
            if ($reported < $topTables) {
                $dim = aic_analytics_dimension((string) $t['name']);
                if ($dim !== '') {
                    $add('db.rows', $rows, $dim);
                    $add('db.largest_table_bytes', (int) $t['bytes'], $dim);
                    $reported++;
                }
            }
        }
    }

    // The headline figures. Undimensioned, so Analytics adds them across every product to get the
    // estate total, and the breakdowns above sit under them.
    $add('db.count', $databaseCount);
    $add('db.size_bytes', $bytesTotal);
    $add('db.rows', $rowsTotal);
    $add('db.table_count', $tablesTotal);

    // Object storage, if the operator said where it is. Measured, never guessed.
    $storageTotal = 0;
    $objectsTotal = 0;
    foreach (($opts['storage'] ?? []) as $label => $dir) {
        [$bytes, $objects] = aic_analytics_dir_usage((string) $dir);
        if ($bytes === null) {
            continue;
        }
        $storageTotal += $bytes;
        $objectsTotal += $objects;
        $dim = aic_analytics_dimension((string) $label);
        if ($dim !== '') {
            $add('storage.bytes', $bytes, $dim);
            $add('storage.objects', $objects, $dim);
        }
    }
    if ($storageTotal > 0 || $objectsTotal > 0) {
        $add('storage.bytes', $storageTotal);
        $add('storage.objects', $objectsTotal);
    }

    return $out;
}

/**
 * How many rows are in this table.
 *
 * An exact COUNT(*) reads the whole table. On the estate's biggest tables that is tens of
 * millions of rows every night, on a production database, to put one number on a dashboard — so
 * the planner's own estimate is used once a table is large enough that nobody is reading the last
 * three digits anyway. Below the threshold the exact count is cheap, so it is taken.
 *
 * reltuples is -1 on a table autovacuum has never looked at (PostgreSQL 14+), and 0 on an old
 * server that has never analysed it. Both mean "unknown", not "empty", and both fall through to
 * the exact count — reporting zero rows for a table nobody has analysed is the kind of wrong
 * number that gets believed.
 */
function aic_analytics_row_count(PDO $pdo, string $schema, string $table, int $estimate, int $exactUnder): int
{
    if ($estimate > $exactUnder) {
        return $estimate;
    }
    try {
        $q = sprintf('SELECT count(*) FROM %s.%s',
            aic_analytics_quote_ident($schema), aic_analytics_quote_ident($table));

        return (int) $pdo->query($q)->fetchColumn();
    } catch (Throwable) {
        return max(0, $estimate);
    }
}

/** PostgreSQL identifier quoting: the only safe way to put a table name into SQL. */
function aic_analytics_quote_ident(string $name): string
{
    return '"' . str_replace('"', '""', $name) . '"';
}

/**
 * A dimension must match ^[a-z0-9][a-z0-9_.-]*$ or ingest refuses it (analytics docs/08) — the
 * rule that stops an e-mail address or a person's name ever arriving as a breakdown label. A
 * table name that cannot be made to fit is skipped rather than mangled into a different name.
 */
function aic_analytics_dimension(string $raw): string
{
    $slug = strtolower(trim($raw));
    $slug = (string) preg_replace('/[^a-z0-9_.-]+/', '_', $slug);
    $slug = (string) preg_replace('/^[^a-z0-9]+/', '', $slug);

    return substr($slug, 0, 120);
}

/** @return array{0:int|null, 1:int} bytes and object count, or [null, 0] if unreadable. */
function aic_analytics_dir_usage(string $dir): array
{
    if ($dir === '' || !is_dir($dir) || !is_readable($dir)) {
        return [null, 0];
    }
    $bytes = 0;
    $files = 0;
    try {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($it as $file) {
            if ($file->isFile()) {
                $bytes += (int) $file->getSize();
                $files++;
            }
        }
    } catch (Throwable) {
        return [null, 0];
    }

    return [$bytes, $files];
}

/**
 * Send the counters. Returns the HTTP status, or 0 if the request never left.
 *
 * @param list<array<string,mixed>> $snapshots
 */
function aic_analytics_platform_push(array $snapshots, string $url, string $key, string $environment): array
{
    $endpoint = rtrim($url, '/') . '/internal/analytics/snapshots';
    $payload  = json_encode(['environment' => $environment, 'snapshots' => $snapshots], JSON_THROW_ON_ERROR);

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . $key],
    ]);
    $body = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [$code, $body];
}

// ─────────────────────────────────────────────────────────────────────────────
// The CLI — for products whose database login is in their own .env
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Read a .env the way CodeIgniter's DotEnv does: the FIRST non-empty assignment wins.
 *
 * Not the last. A real host's .env has a key assigned more than once more often than anyone
 * expects, and reading the other end gives a script that confidently disagrees with the
 * application it is reporting on.
 *
 * @return array<string,string>
 */
function aic_analytics_read_env(string $path): array
{
    $out = [];
    foreach (@file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = ltrim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        if (!preg_match('/^(?:export\s+)?([A-Za-z_][A-Za-z0-9_.]*)\s*=\s*(.*)$/', $line, $m)) {
            continue;
        }
        [$key, $raw] = [$m[1], rtrim($m[2])];
        if ($raw !== '' && ($raw[0] === '"' || $raw[0] === "'")) {
            $q   = $raw[0];
            $end = strrpos($raw, $q);
            $raw = $end > 0 ? substr($raw, 1, $end - 1) : substr($raw, 1);
        } else {
            $raw = rtrim((string) preg_replace('/\s+#.*$/', '', $raw));
        }
        if ($raw !== '' && !isset($out[$key])) {
            $out[$key] = $raw;
        }
    }

    return $out;
}


if (PHP_SAPI !== 'cli' || !isset($argv) || basename((string) ($argv[0] ?? '')) !== basename(__FILE__)) {
    return; // required as a library by a product's own wrapper: define the functions and stop.
}

$opts = getopt('', ['push', 'env::', 'date::', 'db::', 'tables::', 'exact-under::', 'storage-dir::', 'source::']);
$argvRaw = array_slice($argv, 1);

$envPath = is_string($opts['env'] ?? null) && $opts['env'] !== '' ? $opts['env'] : __DIR__ . '/../.env';
if (!is_file($envPath) || !is_readable($envPath)) {
    fwrite(STDERR, "analytics-platform-snapshot: cannot read {$envPath} (pass --env=/path/to/api/.env)\n");
    exit(2);
}
$env = aic_analytics_read_env($envPath);
$get = static fn (string $k, string $d = ''): string => $env[$k] ?? (string) (getenv($k) ?: $d);

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
        if (preg_match('/^(DB(?:_[A-Z0-9]+)*)_NAME$/', $key, $m) && isset($env[$m[1] . '_USER'])) {
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
        $databases[$name] = new PDO($dsn, $user, $get($prefix . '_PASS'), [
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
