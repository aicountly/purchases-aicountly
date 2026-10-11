<?php

declare(strict_types=1);

/**
 * Pre-migration snapshot of this product's database (E8-OPS, F159). Run by bin/ops/release.sh before any
 * migration; an operator can run it by hand at any time.
 *
 *   php bin/db-snapshot.php --dir=~/aicountly-backups/purchases-production [--revision=<sha>] [--keep=14] [--dry-run]
 *
 * Connects exactly as the app does (Db::connect(): on a deployed server the database name and login come
 * from Console's SaaS Database Details, never from DB_NAME / DB_USER read here), then hands the open
 * connection to the kit's aic_ops_snapshot(): pg_dump -Fc at the same MVCC snapshot as an exact per-table
 * row count, written with a manifest that bin/ops/restore-drill.php checks a restore against.
 * Exit codes: 0 written · 1 cannot connect · 4 no suitable pg_dump · 5 dump failed · 6 bad options.
 */

namespace Aicountly\Api;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../src/Env.php';
require __DIR__ . '/../src/Autoload.php';
require __DIR__ . '/ops/snapshot-lib.php';

Env::load(__DIR__ . '/../.env');

$o = \aic_ops_args($argv);
$dir = (string) ($o['dir'] ?? '');
if ($dir === '') {
    fwrite(STDERR, "usage: php bin/db-snapshot.php --dir=<backup folder outside the document root> [--revision=<sha>] [--keep=N] [--dry-run]\n");
    exit(6);
}
if (str_starts_with($dir, '~/')) {
    $dir = (getenv('HOME') ?: '') . substr($dir, 1);
}

try {
    $pdo = Db::connect();
} catch (\Throwable $e) {
    fwrite(STDERR, 'snapshot: cannot connect to the database (' . get_class($e) . '); php bin/db-check.php says why.' . "\n");
    exit(1);
}

exit(\aic_ops_snapshot($pdo, [
    'host'     => Env::get('DB_HOST', '127.0.0.1'),
    'port'     => Env::get('DB_PORT', '5432'),
    'password' => Env::get('DB_PASS'),
    'sslmode'  => Env::get('DB_SSLMODE'),
], [
    'app'              => 'purchases',
    'dir'              => $dir,
    'revision'         => (string) ($o['revision'] ?? ''),
    'keep'             => (int) ($o['keep'] ?? (getenv('BACKUP_KEEP') ?: 14)),
    'dry_run'          => isset($o['dry-run']),
    'migrations_table' => 'purchase_sql_migrations',
    'migrations_dir'   => __DIR__ . '/../database/migrations',
]));
