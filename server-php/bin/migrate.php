<?php

declare(strict_types=1);

/**
 * Apply pending SQL migrations.
 *
 *   php server-php/bin/migrate.php            apply everything not yet applied
 *   php server-php/bin/migrate.php --status   list what would run, change nothing
 *   php server-php/bin/migrate.php --dry-run  parse and check, roll back
 *
 * Exit codes: 0 done, 1 a file failed (nothing of it applied), 2 an applied file was edited,
 * 3 another run holds the migration lock (nothing changed).
 *
 * Each file runs inside its own transaction and is recorded by name, so a
 * half-applied migration cannot exist: either the file is in and recorded, or
 * neither. Files are applied in filename order, which is why they are numbered.
 */

namespace Aicountly\Api;

// A worker, never a web page (F483). .htaccess refuses bin/ over HTTP; this holds on a server that
// ignores .htaccess.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../src/Env.php';
require __DIR__ . '/../src/Db.php';

Env::load(__DIR__ . '/../.env');

$args    = array_slice($argv, 1);
$status  = in_array('--status', $args, true);
$dryRun  = in_array('--dry-run', $args, true);

$dir = __DIR__ . '/../database/migrations';
$files = glob($dir . '/*.sql') ?: [];
sort($files, SORT_STRING);

if ($files === []) {
    fwrite(STDERR, "No migrations found in {$dir}\n");
    exit(1);
}

try {
    $pdo = Db::connect();
} catch (\Throwable $e) {
    $diagnosis = DatabaseDiagnosis::classify($e);
    fwrite(STDERR, "Cannot connect: {$e->getMessage()}\n");
    fwrite(STDERR, "Reason: {$diagnosis['reason']}. {$diagnosis['hint']}\n");
    fwrite(STDERR, "Run php bin/db-check.php to see where each connection setting comes from.\n");
    exit(1);
}

// One migration run at a time (F159). Two deploys, or a deploy and an operator, running this at once
// could both see a file as pending; the per-file transaction stops a double insert of the record but
// not a non-idempotent statement running twice. A session-level advisory lock serialises the runs:
// the second waits up to MIGRATE_LOCK_WAIT_SECONDS (default 120) and then gives up with exit 3,
// changing nothing. The lock ends with this process, so a killed run never leaves it held.
$lockWait = max(0, (int) (getenv('MIGRATE_LOCK_WAIT_SECONDS') ?: 120));
$lockDeadline = time() + $lockWait;
while (true) {
    $got = $pdo->query("SELECT pg_try_advisory_lock(hashtext('aicountly-purchases-migrate'))")->fetchColumn();
    if ($got === true || $got === 't' || $got === 1 || $got === '1') {
        break;
    }
    if (time() >= $lockDeadline) {
        fwrite(STDERR, "LOCKED   another migration run holds the lock; waited {$lockWait}s. Nothing was changed.\n");
        exit(3);
    }
    sleep(1);
}

$pdo->exec(
    'CREATE TABLE IF NOT EXISTS purchase_sql_migrations (
        migration_id BIGSERIAL PRIMARY KEY,
        filename     TEXT        NOT NULL UNIQUE,
        checksum     TEXT        NOT NULL,
        applied_at   TIMESTAMPTZ NOT NULL DEFAULT NOW()
     )',
);

$applied = [];
foreach (Db::all('SELECT filename, checksum FROM purchase_sql_migrations') as $row) {
    $applied[$row['filename']] = $row['checksum'];
}

$pending = 0;
$drift   = 0;

foreach ($files as $path) {
    $name = basename($path);
    $sql = (string) file_get_contents($path);
    $checksum = hash('sha256', $sql);

    if (isset($applied[$name])) {
        if ($applied[$name] !== $checksum) {
            // An applied migration that has since been edited. Reapplying it
            // would not undo what the old version did; say so and let a human
            // decide, rather than guessing.
            fwrite(STDERR, "CHANGED  {$name} — already applied, but the file has been edited since.\n");
            $drift++;
        } elseif ($status) {
            // "already", not "applied": in apply mode that same word means
            // "just applied it now". A deploy log is read by someone who
            // needs to know which of those two happened.
            echo "already  {$name}\n";
        }
        continue;
    }

    $pending++;
    if ($status) {
        echo "PENDING  {$name}\n";
        continue;
    }

    try {
        $pdo->beginTransaction();
        $pdo->exec($sql);
        if ($dryRun) {
            $pdo->rollBack();
            echo "ok (rolled back)  {$name}\n";
            continue;
        }
        $stmt = $pdo->prepare('INSERT INTO purchase_sql_migrations (filename, checksum) VALUES (:f, :c)');
        $stmt->execute(['f' => $name, 'c' => $checksum]);
        $pdo->commit();
        echo "applied  {$name}\n";
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        fwrite(STDERR, "FAILED   {$name}\n         {$e->getMessage()}\n");
        exit(1);
    }
}

// 009 creates the duplicate-invoice index only when the data allows it, with nothing but a
// Postgres WARNING otherwise — easy to miss in a deploy log, and while it is missing new duplicate
// supplier invoices can be entered (E8-PUR F221). Said plainly at the end of every apply run.
if (!$status && !$dryRun) {
    $hasBills = $pdo->query("SELECT to_regclass('purchase_bill_requests') IS NOT NULL")->fetchColumn();
    $hasIndex = $pdo->query("SELECT 1 FROM pg_indexes WHERE indexname = 'uq_purchase_bills_supplier_invoice'")->fetchColumn();
    if ($hasBills && !$hasIndex) {
        fwrite(STDERR, "\nWARNING  uq_purchase_bills_supplier_invoice is MISSING: duplicate supplier invoices exist, so migration 009 could not create it,\n"
            . "         and new duplicates can be entered until it does. Run: php bin/receipt-repair.php --cmp=<id> (duplicate_invoices, missing_indexes).\n");
    }
}

if ($drift > 0) {
    exit(2);
}

if ($status) {
    echo $pending === 0 ? "\nUp to date.\n" : "\n{$pending} migration(s) pending.\n";
} elseif ($pending === 0) {
    echo "Up to date.\n";
}
