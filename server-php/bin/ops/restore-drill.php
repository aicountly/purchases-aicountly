<?php

declare(strict_types=1);

/**
 * AICOUNTLY ops kit — populated restore drill (E8-OPS, F159).
 *
 * CANONICAL COPY: console-react-app/ops/estate/kit/bin/ops/restore-drill.php (identical in every product
 * at server-php/bin/ops/restore-drill.php). Standalone: needs only pdo_pgsql and pg_restore, never the
 * app's code or its .env (it talks to PostgreSQL through psql/pg_restore), so it can run on the host, on an operator laptop or in a test.
 *
 *   php bin/ops/restore-drill.php --manifest=/path/<app>-<stamp>.manifest.json \
 *       --admin-url-env=RESTORE_DRILL_ADMIN_URL --target-db=<app>_restore_drill [--keep] \
 *       [--files-archive=/path/files.tar.gz --files-dir=/tmp/x] [--evidence=/path/evidence.json]
 *
 *   RESTORE_DRILL_ADMIN_URL  postgresql://user:password@host:port/postgres — a role that may CREATE DATABASE.
 *                            Read from the environment only (never an argument, so it is not in `ps`).
 *
 * What it proves, and writes as evidence (JSON, also printed):
 *   1. the dump's sha256 still matches the manifest (the file was not truncated or swapped);
 *   2. pg_restore into a NEW, EMPTY database succeeds (exit 0, no errors);
 *   3. every table's row count in the restored database equals the count taken at the dump's own
 *      snapshot (manifest.tables) — a populated restore, not "the schema came back";
 *   4. the migration bookkeeping table has the rows the manifest recorded;
 *   5. optionally, a files archive (uploads, writable/) extracts and its file count/bytes are reported.
 * The drill database is dropped afterwards unless --keep.
 *
 * SAFETY: refuses a target that is the dump's own database, that exists already (unless --replace and
 * its name carries "drill" or "restore"), or whose name does not carry "drill" or "restore". It never
 * touches any other database.
 *
 * Exit codes: 0 drill passed, 1 drill failed (evidence says which check), 2 bad options / refused.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/snapshot-lib.php';

/**
 * One SQL statement through psql (never a PDO of our own: the product's Db::connect stays the only place
 * that builds a connection to ITS database; this tool talks to the drill server with the admin URL).
 *
 * @return array{0:int,1:string} exit code and the unaligned, tuples-only output
 */
function drill_psql(string $psql, array $env, string $db, string $sql): array
{
    $proc = proc_open([$psql, '-X', '-qAt', '-v', 'ON_ERROR_STOP=1', '-d', $db, '-c', $sql], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
    if (!is_resource($proc)) {
        return [1, ''];
    }
    $out = (string) stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [proc_close($proc), trim($out)];
}

$o = aic_ops_args($argv);
$manifestPath = (string) ($o['manifest'] ?? '');
$target = strtolower((string) ($o['target-db'] ?? ''));
$adminEnv = (string) ($o['admin-url-env'] ?? 'RESTORE_DRILL_ADMIN_URL');
$evidencePath = (string) ($o['evidence'] ?? '');
$keep = isset($o['keep']);
$replace = isset($o['replace']);

$fail = static function (string $m, int $code = 2): never {
    fwrite(STDERR, "restore-drill: {$m}\n");
    exit($code);
};

if ($manifestPath === '' || !is_file($manifestPath)) {
    $fail('--manifest=<file> is required and must exist');
}
$manifest = json_decode((string) file_get_contents($manifestPath), true);
if (!is_array($manifest) || ($manifest['kit'] ?? '') !== 'aicountly-ops-snapshot/1') {
    $fail('not an aicountly ops snapshot manifest');
}
$dump = dirname($manifestPath) . '/' . basename((string) $manifest['dump_file']);
if (!is_file($dump)) {
    $fail('the dump named by the manifest is missing: ' . basename($dump));
}
if (preg_match('/^[a-z_][a-z0-9_]{0,62}$/', $target) !== 1 || preg_match('/drill|restore/', $target) !== 1) {
    $fail('--target-db must be a plain lower-case name containing "drill" or "restore"');
}
if ($target === strtolower((string) $manifest['database'])) {
    $fail('--target-db is the snapshot\'s own database; a drill never restores over it');
}
$adminUrl = (string) (getenv($adminEnv) ?: '');
$admin = parse_url($adminUrl);
if (!is_array($admin) || !in_array($admin['scheme'] ?? '', ['postgres', 'postgresql'], true)) {
    $fail("{$adminEnv} must hold a postgresql:// URL of a role that may CREATE DATABASE");
}
$host = (string) ($admin['host'] ?? '127.0.0.1');
$port = (string) ($admin['port'] ?? '5432');
$user = rawurldecode((string) ($admin['user'] ?? ''));
$pass = rawurldecode((string) ($admin['pass'] ?? ''));
$adminDb = ltrim((string) ($admin['path'] ?? '/postgres'), '/') ?: 'postgres';

$evidence = [
    'kit' => 'aicountly-ops-restore-drill/1',
    'app' => $manifest['app'],
    'manifest' => basename($manifestPath),
    'revision' => $manifest['revision'],
    'snapshot_created_at' => $manifest['created_at'],
    'drill_started_at' => gmdate('c'),
    'target_db' => $target,
    'checks' => [],
];
$check = static function (string $name, bool $ok, string $detail) use (&$evidence): bool {
    $evidence['checks'][] = ['check' => $name, 'ok' => $ok, 'detail' => $detail];
    fwrite(STDOUT, ($ok ? 'PASS ' : 'FAIL ') . $name . ' — ' . $detail . "\n");

    return $ok;
};

$ok = true;
$sha = hash_file('sha256', $dump);
$ok = $check('dump checksum', $sha === $manifest['dump_sha256'], $sha === $manifest['dump_sha256'] ? 'sha256 matches the manifest' : 'sha256 differs from the manifest') && $ok;

$pgEnv = ['PGHOST' => $host, 'PGPORT' => $port, 'PGUSER' => $user, 'PGPASSWORD' => $pass, 'PATH' => getenv('PATH') ?: '/usr/bin:/bin'];
$psql = aic_ops_find_pg_tool('psql', 0, (string) (getenv('PSQL_BIN') ?: '')) ?? $fail('psql was not found (set PSQL_BIN)');
[$rc, $ver] = drill_psql($psql, $pgEnv, $adminDb, 'SHOW server_version_num');
if ($rc !== 0) {
    $fail('cannot connect with ' . $adminEnv);
}
[, $exists] = drill_psql($psql, $pgEnv, $adminDb, "SELECT 1 FROM pg_database WHERE datname = '" . $target . "'");
if ($exists === '1' && !$replace) {
    $fail("database {$target} exists; pass --replace to drop and recreate it (drill names only)");
}
if ($exists === '1') {
    drill_psql($psql, $pgEnv, $adminDb, 'DROP DATABASE ' . aic_ops_qi($target));
}
[$rc] = drill_psql($psql, $pgEnv, $adminDb, 'CREATE DATABASE ' . aic_ops_qi($target) . ' TEMPLATE template0');
if ($rc !== 0) {
    $fail("could not create {$target}");
}

$serverMajor = intdiv((int) $ver, 10000);
$pgRestore = aic_ops_find_pg_tool('pg_restore', $serverMajor, (string) (getenv('PG_RESTORE_BIN') ?: ''));
if ($pgRestore === null) {
    drill_psql($psql, $pgEnv, $adminDb, 'DROP DATABASE ' . aic_ops_qi($target));
    $fail("no pg_restore of major version >= {$serverMajor} found (set PG_RESTORE_BIN)");
}

$t0 = microtime(true);
$proc = proc_open(
    [$pgRestore, '--no-owner', '--no-acl', '--exit-on-error', '-d', $target, $dump],
    [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $pipes,
    null,
    $pgEnv,
);
$stderr = '';
$rc = 1;
if (is_resource($proc)) {
    stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $rc = proc_close($proc);
}
$evidence['restore_seconds'] = round(microtime(true) - $t0, 2);
$ok = $check('pg_restore', $rc === 0, $rc === 0 ? 'restored into an empty database' : 'exit ' . $rc . ': ' . trim(substr($stderr, 0, 400))) && $ok;

if ($rc === 0) {
    $mismatch = [];
    $rows = 0;
    foreach ((array) $manifest['tables'] as $qualified => $expected) {
        [$schema, $table] = explode('.', (string) $qualified, 2);
        [$qrc, $qout] = drill_psql($psql, $pgEnv, $target, 'SELECT COUNT(*) FROM ' . aic_ops_qi($schema) . '.' . aic_ops_qi($table));
        $got = $qrc === 0 ? (int) $qout : -1;
        $rows += max(0, $got);
        if ($got !== (int) $expected) {
            $mismatch[] = "{$qualified}: expected {$expected}, restored {$got}";
        }
    }
    $evidence['restored_rows'] = $rows;
    $ok = $check(
        'row counts',
        $mismatch === [],
        $mismatch === [] ? sprintf('%d tables, %d rows, all equal to the dump snapshot', count((array) $manifest['tables']), $rows)
            : count($mismatch) . ' table(s) differ: ' . implode('; ', array_slice($mismatch, 0, 10)),
    ) && $ok;

    $mig = (array) ($manifest['migrations'] ?? []);
    if (($mig['rows'] ?? null) !== null) {
        $migCount = -1;
        foreach (array_keys((array) $manifest['tables']) as $q) {
            if (str_ends_with((string) $q, '.' . $mig['table'])) {
                [$s, $t] = explode('.', (string) $q, 2);
                $migCount = (int) drill_psql($psql, $pgEnv, $target, 'SELECT COUNT(*) FROM ' . aic_ops_qi($s) . '.' . aic_ops_qi($t))[1];
            }
        }
        $ok = $check('migration bookkeeping', $migCount === (int) $mig['rows'], "{$mig['table']}: {$migCount} rows restored, {$mig['rows']} at the snapshot, {$mig['files']} files on disk then") && $ok;
    }
}

$archive = (string) ($o['files-archive'] ?? '');
if ($archive !== '') {
    $filesDir = (string) ($o['files-dir'] ?? '');
    if ($filesDir === '' || (is_dir($filesDir) && (glob($filesDir . '/*') ?: []) !== [])) {
        $ok = $check('files restore', false, '--files-dir must name a new or empty directory') && $ok;
    } else {
        @mkdir($filesDir, 0700, true);
        $out = [];
        exec('tar -xzf ' . escapeshellarg($archive) . ' -C ' . escapeshellarg($filesDir) . ' 2>&1', $out, $trc);
        $count = 0;
        $bytes = 0;
        if ($trc === 0) {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($filesDir, FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if ($f->isFile()) {
                    $count++;
                    $bytes += $f->getSize();
                }
            }
        }
        $ok = $check('files restore', $trc === 0 && $count > 0, $trc === 0 ? "{$count} files, {$bytes} bytes extracted" : 'tar exit ' . $trc) && $ok;
    }
}

if (!$keep) {
    drill_psql($psql, $pgEnv, $adminDb, 'DROP DATABASE IF EXISTS ' . aic_ops_qi($target));
    $evidence['target_dropped'] = true;
}
$evidence['drill_finished_at'] = gmdate('c');
$evidence['result'] = $ok ? 'passed' : 'failed';
$json = json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
if ($evidencePath !== '') {
    file_put_contents($evidencePath, $json);
}
fwrite(STDOUT, $json);
exit($ok ? 0 : 1);
