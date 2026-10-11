<?php

declare(strict_types=1);

/**
 * AICOUNTLY ops kit — pre-migration database snapshot (E8-OPS, F159).
 *
 * CANONICAL COPY: console-react-app/ops/estate/kit/bin/ops/snapshot-lib.php. Every product keeps an
 * identical copy at server-php/bin/ops/snapshot-lib.php; ops/estate/tests checks they have not drifted.
 *
 * The product's own bin/db-snapshot.php connects the way the app does (so on a deployed server the
 * database name and login come from Console's SaaS Database Details, never from DB_NAME / DB_USER read
 * here) and hands the open PDO plus the host/port/password/sslmode to aic_ops_snapshot().
 *
 * What a snapshot is:
 *   <dir>/<app>-<UTCSTAMP>-<rev7>.dump            pg_dump custom format (-Fc), restorable with pg_restore
 *   <dir>/<app>-<UTCSTAMP>-<rev7>.manifest.json   app, revision, server version, sha256 and size of the
 *                                                 dump, the exact row count of every table AT THE SAME
 *                                                 SNAPSHOT the dump was taken from, and the migration
 *                                                 bookkeeping (table, rows, files on disk)
 *
 * The row counts and the dump read the same MVCC snapshot (pg_export_snapshot + pg_dump --snapshot),
 * so a restore drill can compare them exactly while the live app keeps writing.
 *
 * Never printed: the password (it reaches pg_dump through PGPASSWORD in the child's environment only)
 * and any row content. The manifest names the database, which is the operator's own information and
 * lives outside the document root with the dump (mode 0600).
 */

/**
 * @param array{host:string, port:string, password:string, sslmode?:string} $conn
 * @param array{app:string, dir:string, revision?:string, keep?:int, dry_run?:bool,
 *              migrations_table:string, migrations_dir:string, pg_dump?:string} $opts
 * @return int exit code: 0 written (or dry run), 4 pg_dump missing/too old, 5 dump failed, 6 bad options
 */
function aic_ops_snapshot(PDO $pdo, array $conn, array $opts, ?callable $out = null): int
{
    $say = $out ?? static function (string $line): void {
        fwrite(STDOUT, $line . "\n");
    };
    $app = preg_replace('/[^a-z0-9_-]/', '', strtolower($opts['app'])) ?? '';
    $dir = rtrim($opts['dir'], '/');
    $keep = max(1, (int) ($opts['keep'] ?? 14));
    $revision = preg_replace('/[^0-9a-zA-Z._-]/', '', (string) ($opts['revision'] ?? '')) ?? '';
    if ($app === '' || $dir === '') {
        $say('snapshot: --app and --dir are required');

        return 6;
    }

    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $database = (string) $pdo->query('SELECT current_database()')->fetchColumn();
    $user = (string) $pdo->query('SELECT current_user')->fetchColumn();
    $serverNum = (int) $pdo->query('SHOW server_version_num')->fetchColumn();
    $serverMajor = intdiv($serverNum, 10000);

    $pgDump = aic_ops_find_pg_tool('pg_dump', $serverMajor, (string) ($opts['pg_dump'] ?? (getenv('PG_DUMP_BIN') ?: '')));
    if ($pgDump === null) {
        $say("snapshot: no pg_dump of major version >= {$serverMajor} was found (set PG_DUMP_BIN). Nothing was written.");

        return 4;
    }

    $stamp = gmdate('Ymd\THis\Z');
    $base = sprintf('%s-%s%s', $app, $stamp, $revision !== '' ? '-' . substr($revision, 0, 12) : '');
    $dumpPath = $dir . '/' . $base . '.dump';
    $manifestPath = $dir . '/' . $base . '.manifest.json';

    if (!empty($opts['dry_run'])) {
        $say("snapshot (dry run): would dump database of {$app} (server {$serverMajor}) with {$pgDump} to {$dumpPath}, keep the newest {$keep}.");

        return 0;
    }

    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        $say("snapshot: cannot create {$dir}");

        return 5;
    }
    @chmod($dir, 0700);

    // One MVCC snapshot for the counts and the dump.
    $pdo->exec('BEGIN ISOLATION LEVEL REPEATABLE READ READ ONLY');
    try {
        $snapshotId = (string) $pdo->query('SELECT pg_export_snapshot()')->fetchColumn();
        $tables = $pdo->query(
            "SELECT n.nspname AS s, c.relname AS t
               FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
              WHERE c.relkind IN ('r','p') AND n.nspname NOT IN ('pg_catalog','information_schema')
                AND n.nspname NOT LIKE 'pg_toast%' AND n.nspname NOT LIKE 'pg_temp%'
              ORDER BY 1, 2"
        )->fetchAll(PDO::FETCH_ASSOC);
        $counts = [];
        foreach ($tables as $row) {
            $qualified = aic_ops_qi($row['s']) . '.' . aic_ops_qi($row['t']);
            $counts[$row['s'] . '.' . $row['t']] = (int) $pdo->query('SELECT COUNT(*) FROM ' . $qualified)->fetchColumn();
        }
        $migTable = (string) $opts['migrations_table'];
        $migRows = null;
        foreach (array_keys($counts) as $key) {
            if (str_ends_with($key, '.' . $migTable)) {
                $migRows = $counts[$key];
                break;
            }
        }

        $env = [
            'PGHOST' => $conn['host'],
            'PGPORT' => $conn['port'],
            'PGUSER' => $user,
            'PGPASSWORD' => $conn['password'],
            'PGDATABASE' => $database,
            'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
        ];
        if (($conn['sslmode'] ?? '') !== '') {
            $env['PGSSLMODE'] = (string) $conn['sslmode'];
        }
        $old = umask(0077);
        $cmd = [$pgDump, '-Fc', '--no-owner', '--no-acl', '--snapshot=' . $snapshotId, '-f', $dumpPath];
        $proc = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        if (!is_resource($proc)) {
            umask($old);
            $say('snapshot: could not start pg_dump');

            return 5;
        }
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $rc = proc_close($proc);
        umask($old);
    } finally {
        $pdo->exec('COMMIT');
    }

    if ($rc !== 0 || !is_file($dumpPath) || filesize($dumpPath) === 0) {
        @unlink($dumpPath);
        // pg_dump's message names the database and role at most; never the password.
        $say('snapshot: pg_dump failed (exit ' . $rc . '): ' . trim(substr((string) $stderr . (string) $stdout, 0, 600)));

        return 5;
    }

    $manifest = [
        'kit' => 'aicountly-ops-snapshot/1',
        'app' => $app,
        'revision' => $revision !== '' ? $revision : null,
        'created_at' => gmdate('c'),
        'database' => $database,
        'server_version_num' => $serverNum,
        'pg_dump' => trim((string) shell_exec(escapeshellarg($pgDump) . ' --version 2>/dev/null')),
        'dump_file' => basename($dumpPath),
        'dump_bytes' => filesize($dumpPath),
        'dump_sha256' => hash_file('sha256', $dumpPath),
        'tables' => $counts,
        'migrations' => [
            'table' => $migTable,
            'rows' => $migRows,
            'files' => count(glob(rtrim((string) $opts['migrations_dir'], '/') . '/*.sql') ?: []),
        ],
    ];
    $old = umask(0077);
    file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    umask($old);

    $say(sprintf(
        'snapshot: wrote %s (%d bytes, sha256 %s, %d tables, %d rows)',
        $dumpPath,
        $manifest['dump_bytes'],
        substr($manifest['dump_sha256'], 0, 16),
        count($counts),
        array_sum($counts),
    ));

    // Retention: the newest $keep snapshots of this app stay; older dump+manifest pairs go.
    $all = glob($dir . '/' . $app . '-*.dump') ?: [];
    rsort($all, SORT_STRING);
    foreach (array_slice($all, $keep) as $old) {
        @unlink($old);
        @unlink(substr($old, 0, -5) . '.manifest.json');
        $say('snapshot: retention removed ' . basename($old));
    }

    return 0;
}

/** A PostgreSQL client tool whose major version is at least the server's. */
function aic_ops_find_pg_tool(string $tool, int $minMajor, string $preferred = ''): ?string
{
    $candidates = [];
    if ($preferred !== '') {
        $candidates[] = $preferred;
    }
    foreach (array_merge(glob('/usr/pgsql-*/bin/' . $tool) ?: [], glob('/usr/lib/postgresql/*/bin/' . $tool) ?: []) as $c) {
        $candidates[] = $c;
    }
    $candidates[] = '/usr/local/bin/' . $tool;
    $candidates[] = '/usr/bin/' . $tool;
    $best = null;
    $bestMajor = -1;
    foreach (array_unique($candidates) as $c) {
        if (!is_file($c) || !is_executable($c)) {
            continue;
        }
        $v = (string) shell_exec(escapeshellarg($c) . ' --version 2>/dev/null');
        if (preg_match('/(\d+)(?:\.\d+)?/', $v, $m) !== 1) {
            continue;
        }
        $major = (int) $m[1];
        if ($major >= $minMajor && $major > $bestMajor) {
            $best = $c;
            $bestMajor = $major;
        }
        if ($c === $preferred && $major >= $minMajor) {
            return $c;
        }
    }

    return $best;
}

function aic_ops_qi(string $identifier): string
{
    return '"' . str_replace('"', '""', $identifier) . '"';
}

/** --name=value options for the small CLIs of this kit. @return array<string,string|bool> */
function aic_ops_args(array $argv): array
{
    $out = [];
    foreach (array_slice($argv, 1) as $a) {
        if (preg_match('/^--([a-z0-9-]+)(?:=(.*))?$/s', $a, $m) === 1) {
            $out[$m[1]] = array_key_exists(2, $m) ? $m[2] : true;
        }
    }

    return $out;
}
