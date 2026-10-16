<?php

declare(strict_types=1);

/**
 * Company lifecycle (docs/COMPANY_LIFECYCLE.md) — the receiver kit as Purchases wires it:
 *
 *   - the reviewed purge manifest loads and has no problems on the migrated schema, for the company and the
 *     person scope (strict mode: a migration that adds a cmp_id table without listing it fails here);
 *   - every allow-list entry names a real write route;
 *   - over HTTP through the real front controller: Manage's signed events (401 / 422 / 202, duplicates,
 *     occurred_at order), the 423 COMPANY_RESTRICTED write gate (GET and the allow-list pass), companyinfo as
 *     the source of truth, and a company / account purge run by the worker with its signed ack.
 *
 * Run by tests/run.sh after the integration suite, on the migrated test database and the stub.
 */

namespace Aicountly\Api;

use Aicountly\Lifecycle\Kit;
use Aicountly\Lifecycle\PurgeEngine;
use Aicountly\Lifecycle\PurgeManifest;
use Aicountly\Lifecycle\ReadOnlyGuard;
use Aicountly\Lifecycle\Signature;

require __DIR__ . '/../src/Env.php';
require __DIR__ . '/../src/Autoload.php';

Env::load(__DIR__ . '/../.env');
require_once __DIR__ . '/../lib/company-lifecycle/autoload.php';

const LC_SECRET = 'test-lifecycle-secret-0123456789abcdef-purchases';
const LC_CMP = 88;          // a company the stub lets the test session open (fy 6)
const LC_PURGE_CMP = 4242;  // a company only this file writes rows for
const LC_MANIFEST = __DIR__ . '/../config/lifecycle-manifest.php';

$lcPassed = 0;
$lcFailed = 0;

function lcCheck(string $name, callable $fn): void
{
    global $lcPassed, $lcFailed;
    try {
        $fn();
        $lcPassed++;
        echo "  ok    {$name}\n";
    } catch (\Throwable $e) {
        $lcFailed++;
        echo "  FAIL  {$name}\n        " . $e->getMessage() . "\n";
    }
}

function lcSame(mixed $expected, mixed $actual, string $what): void
{
    if ($expected !== $actual) {
        throw new \RuntimeException($what . ': expected ' . json_encode($expected) . ', got ' . json_encode($actual));
    }
}

function lcTrue(bool $cond, string $what): void
{
    if (!$cond) {
        throw new \RuntimeException($what);
    }
}

function lcUuid(): string
{
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);

    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
}

function lcReset(): void
{
    Db::connect()->exec('TRUNCATE aic_lifecycle_acks, aic_lifecycle_jobs, aic_lifecycle_events, aic_lifecycle_state, aic_lifecycle_expiries RESTART IDENTITY');
    @unlink(sys_get_temp_dir() . '/stub-manage-lifecycle.json');
}

echo "\nCompany lifecycle (receiver kit " . trim((string) file_get_contents(__DIR__ . '/../lib/company-lifecycle/VERSION')) . ")\n";

// --- the manifest -----------------------------------------------------------------------------------

lcCheck('the purge manifest is reviewed (not a draft) and names this app', function () {
    $m = PurgeManifest::load(LC_MANIFEST);
    lcSame('purchases', $m->appKey(), 'app_key');
    lcTrue(count($m->tables('company')) > 0, 'company tables are listed');
    foreach (array_merge($m->retained('company'), $m->retained('user')) as $r) {
        lcTrue(strlen($r['reason']) > 40, $r['table'] . ' carries its legal reason');
    }
});

lcCheck('the manifest has no problems on the migrated schema — company scope', function () {
    $engine = new PurgeEngine(Db::connect(), PurgeManifest::load(LC_MANIFEST));
    lcSame([], $engine->problems('company'), 'problems(company)');
});

lcCheck('the manifest has no problems on the migrated schema — person scope', function () {
    $engine = new PurgeEngine(Db::connect(), PurgeManifest::load(LC_MANIFEST));
    lcSame([], $engine->problems('user'), 'problems(user)');
});

lcCheck('no purchase voucher, goods receipt, return or claim table is purged (they are retained by statute)', function () {
    $m = PurgeManifest::load(LC_MANIFEST);
    $purged = array_column($m->tables('company'), 'table');
    $retained = array_column($m->retained('company'), 'table');
    foreach (['purchase_bill_requests', 'purchase_receipt_requests', 'purchase_returns', 'purchase_return_lines', 'purchase_claims', 'purchase_claim_resolutions', 'purchase_audit_log'] as $t) {
        lcTrue(in_array($t, $retained, true) && !in_array($t, $purged, true), $t . ' is retained');
    }
});

lcCheck('kit 1.1: every retained table has 8 years; the PO -> quote / RFQ / requisition chain is kept only when referenced', function () {
    $m = PurgeManifest::load(LC_MANIFEST);
    lcSame([], $m->warnings(), 'manifest warnings');
    foreach ($m->retained('company') as $r) {
        lcTrue(($r['retain_years'] ?? null) === 8 && in_array($r['retain_from'] ?? '', ['created_at', 'retention_anchor_at'], true), $r['table'] . ': 8 years');
    }
    $modes = [];
    foreach ($m->tables('company') as $t) {
        $modes[$t['table']] = $t['mode'] ?? 'purge';
    }
    foreach (['purchase_orders', 'purchase_order_lines', 'purchase_quotes', 'purchase_quote_lines', 'purchase_rfqs', 'purchase_rfq_lines', 'purchase_requisitions', 'purchase_requisition_lines'] as $t) {
        lcSame('retain_referenced', $modes[$t] ?? null, $t . ' mode');
    }
    lcSame([], CompanyLifecycle::LIFECYCLE_MACHINE_ALLOW, 'no machine write passes a read-only company (Purchases receives no money-moved / provider webhook or Manage callback)');
});

// --- the allow-list ---------------------------------------------------------------------------------

lcCheck('every allow-list entry matches a real write route in Routes.php', function () {
    $router = new Router();
    Routes::register($router);
    $routes = (new \ReflectionProperty(Router::class, 'routes'))->getValue($router);
    lcTrue(CompanyLifecycle::LIFECYCLE_ALLOW !== [], 'the allow-list is not empty');
    foreach (CompanyLifecycle::LIFECYCLE_ALLOW as $entry) {
        $hit = false;
        foreach ($routes as $r) {
            if ($r['method'] === 'GET') {
                continue;
            }
            $path = '/api/' . implode('/', array_map(static fn (string $s) => str_starts_with($s, '{') ? '1' : $s, $r['segments']));
            if (ReadOnlyGuard::decide('restricted', null, $r['method'], $path, [$entry]) === null) {
                $hit = true;
                break;
            }
        }
        lcTrue($hit, $entry . ' matches no write route');
    }
});

lcCheck('the receiver route is registered outside the company-scoped controllers', function () {
    $router = new Router();
    Routes::register($router);
    $routes = (new \ReflectionProperty(Router::class, 'routes'))->getValue($router);
    $found = array_values(array_filter($routes, static fn (array $r) => $r['method'] === 'POST' && implode('/', $r['segments']) === 'internal/manage/company-lifecycle'));
    lcSame(1, count($found), 'one POST internal/manage/company-lifecycle');
    lcSame([CompanyLifecycle::class, 'receive'], $found[0]['handler'], 'handled by CompanyLifecycle::receive');
});

// --- over HTTP --------------------------------------------------------------------------------------

$stub = rtrim(Env::get('MANAGE_API_BASE'), '/');
$port = (int) parse_url($stub, PHP_URL_PORT) + 60;
$base = 'http://127.0.0.1:' . $port;
$server = proc_open(
    [PHP_BINARY, '-S', '127.0.0.1:' . $port, __DIR__ . '/../index.php'],
    [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
    $pipes,
    __DIR__ . '/..',
    [
        'PORTAL_AUTH_BASE' => $stub, 'TMPDIR' => sys_get_temp_dir(), 'PATH' => (string) getenv('PATH'),
        'LIFECYCLE_WEBHOOK_SECRET' => LC_SECRET, 'MANAGE_API_URL' => $stub,
    ],
);

/** @return array{0:int, 1:?array} */
function lcHttp(string $method, string $path, ?string $body = null, array $headers = []): array
{
    global $base;
    $ch = curl_init($base . '/' . ltrim($path, '/'));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*',
        CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => array_merge(['Accept: application/json'], $headers),
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    $raw = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    $json = json_decode($raw, true);

    return [$status, is_array($json) ? $json : null];
}

/** Manage's signed event. @return array{0:int, 1:?array} */
function lcEvent(array $event, ?string $secret = LC_SECRET): array
{
    $raw = (string) json_encode($event, JSON_UNESCAPED_SLASHES);
    $headers = ['Content-Type: application/json', 'X-Manage-Event: ' . ($event['event'] ?? ''), 'Idempotency-Key: ' . ($event['event_id'] ?? '')];
    if ($secret !== null) {
        $headers = array_merge($headers, Signature::headerLines($secret, $raw, time()));
    }

    return lcHttp('POST', 'internal/manage/company-lifecycle', $raw, $headers);
}

function lcCompanyEvent(string $event, int $cmpId, int $occurredAgo = 0, array $extra = []): array
{
    return ['event_id' => lcUuid(), 'event' => $event, 'cmp_id' => $cmpId, 'app_key' => 'purchases',
        'reason' => $event === 'company_unrestricted' ? 'subscription_renewed' : 'subscription_lapsed',
        'occurred_at' => gmdate('Y-m-d\TH:i:s\Z', time() - $occurredAgo)] + $extra;
}

/** A person's write: PUT v1/settings as the company owner. */
function lcPersonWrite(int $cmpId = LC_CMP): array
{
    return lcHttp('PUT', 'v1/settings?cmp_id=' . $cmpId . '&fy_id=6', '{}', ['Content-Type: application/json', 'Authorization: Bearer test-ses-key-lifecycle']);
}

try {
    for ($i = 0; $i < 40 && lcHttp('GET', 'health')[0] !== 200; $i++) {
        usleep(250000);
    }
    lcReset();

    lcCheck('an unsigned or wrongly signed event is 401; another app\'s event is 422', function () {
        lcSame(401, lcEvent(lcCompanyEvent('company_restricted', LC_CMP), null)[0], 'unsigned');
        lcSame(401, lcEvent(lcCompanyEvent('company_restricted', LC_CMP), 'another-secret-0123456789abcdef0123456789')[0], 'wrong secret');
        $e = lcCompanyEvent('company_restricted', LC_CMP);
        $e['app_key'] = 'books';
        lcSame(422, lcEvent($e)[0], 'app_key books');
        $e = lcCompanyEvent('company_restricted', LC_CMP);
        unset($e['cmp_id']);
        lcSame(422, lcEvent($e)[0], 'company event without cmp_id');
        lcSame(0, (int) Db::scalar('SELECT COUNT(*) FROM aic_lifecycle_state'), 'nothing restricted');
    });

    lcCheck('before any event a person\'s write is not refused as restricted', function () {
        lcTrue(lcPersonWrite()[0] !== 423, 'not 423');
    });

    $restricted = lcCompanyEvent('company_restricted', LC_CMP, 120, ['restricted_until' => gmdate('Y-m-d\TH:i:s\Z', time() + 45 * 86400)]);
    lcCheck('company_restricted is accepted (202 applied); a redelivery is a duplicate', function () use ($restricted) {
        [$status, $json] = lcEvent($restricted);
        lcSame([202, 'accepted', 'applied', false], [$status, $json['status'] ?? null, $json['outcome'] ?? null, $json['duplicate'] ?? null], 'first delivery');
        [$status, $json] = lcEvent($restricted);
        lcSame([202, true], [$status, $json['duplicate'] ?? null], 'redelivery');
    });

    lcCheck('restricted: a person\'s write answers 423 COMPANY_RESTRICTED with the date; reads still answer', function () {
        [$status, $json] = lcPersonWrite();
        lcSame(423, $status, 'PUT v1/settings');
        lcSame('COMPANY_RESTRICTED', $json['code'] ?? null, 'code');
        lcSame('COMPANY_RESTRICTED', $json['error']['code'] ?? null, 'error.code (the SPA reads it)');
        lcTrue(str_contains((string) ($json['message'] ?? ''), 'read-only until'), 'message names the date: ' . ($json['message'] ?? ''));
        lcTrue(is_string($json['restricted_until'] ?? null), 'restricted_until');
        [$status] = lcHttp('GET', 'v1/settings?cmp_id=' . LC_CMP . '&fy_id=6', null, ['Authorization: Bearer test-ses-key-lifecycle']);
        lcSame(200, $status, 'GET v1/settings');
    });

    lcCheck('restricted: an allow-listed export / print write is not refused', function () {
        [$status] = lcHttp('POST', 'v1/import/preview?cmp_id=' . LC_CMP . '&fy_id=6', '{}', ['Content-Type: application/json', 'Authorization: Bearer test-ses-key-lifecycle']);
        lcTrue($status !== 423, 'POST v1/import/preview answered ' . $status);
    });

    lcCheck('restricted: a caller who may not open the company still gets 403, not 423', function () {
        lcSame(403, lcPersonWrite(4033)[0], 'company 4033: no Purchases access');
    });

    lcCheck('an older event delivered late is stale and changes nothing', function () {
        [$status, $json] = lcEvent(lcCompanyEvent('company_unrestricted', LC_CMP, 3600));
        lcSame([202, 'stale'], [$status, $json['outcome'] ?? null], 'stale unrestrict');
        lcSame(423, lcPersonWrite()[0], 'still read-only');
    });

    lcCheck('company_unrestricted lifts it', function () {
        [$status, $json] = lcEvent(lcCompanyEvent('company_unrestricted', LC_CMP, 0));
        lcSame([202, 'applied'], [$status, $json['outcome'] ?? null], 'unrestrict');
        lcTrue(lcPersonWrite()[0] !== 423, 'writes again');
    });

    lcCheck('kit 1.1 machine writes: Books\' voucher event answers 423 in a read-only company (Books defers it); renewed, it passes', function () {
        $books = static function (): array {
            $eventId = 'lc-' . bin2hex(random_bytes(6));
            $raw = (string) json_encode(['event_id' => $eventId, 'cmp_id' => LC_CMP, 'vch_txn_id' => 987654, 'event_type' => 'voucher.cancelled']);
            $t = time();

            return lcHttp('POST', 'v1/integration/books/voucher-events', $raw, [
                'Content-Type: application/json', 'X-Service-Key: test-books-service-key-0001', 'X-Books-Event-Id: ' . $eventId,
                'X-Books-Signature: t=' . $t . ',v1=' . hash_hmac('sha256', $t . '.' . $raw, Env::get('BOOKS_EVENTS_SIGNING_SECRET')),
            ]);
        };
        sleep(1);   // a newer occurred_at than the events above
        lcSame(202, lcEvent(lcCompanyEvent('company_restricted', LC_CMP, 0, ['restricted_until' => gmdate('Y-m-d\TH:i:s\Z', time() + 45 * 86400)]))[0], 'restricted');
        try {
            $inbox = static fn (): int => (int) Db::scalar('SELECT COUNT(*) FROM purchase_books_event_inbox WHERE cmp_id = :c', ['c' => LC_CMP]);
            $before = $inbox();
            [$status, $json] = $books();
            lcSame([423, 'COMPANY_RESTRICTED'], [$status, $json['code'] ?? null], 'Books POST v1/integration/books/voucher-events');
            lcTrue(is_string($json['restricted_until'] ?? null) && ($json['lifecycle'] ?? null) === 'restricted', 'restricted_until and lifecycle for Books\' deferral');
            lcSame($before, $inbox(), 'nothing applied');
            $guard = CompanyLifecycle::kit()->guard(CompanyLifecycle::LIFECYCLE_ALLOW, CompanyLifecycle::LIFECYCLE_MACHINE_ALLOW);
            lcSame(null, $guard->checkMachine(LC_CMP, 'POST', '/api/internal/manage/company-lifecycle'), 'Manage\'s own event always passes');
            lcSame(423, $guard->checkMachine(LC_CMP, 'POST', '/api/v1/purchase-orders')[0] ?? null, 'any other service-key write is refused');
            lcSame(null, $guard->checkMachine(LC_CMP, 'GET', '/api/v1/purchase-orders'), 'a machine read passes');
        } finally {
            sleep(1);
            lcEvent(lcCompanyEvent('company_unrestricted', LC_CMP, 0));
        }
        lcTrue($books()[0] !== 423, 'renewed: Books\' event is taken again');
    });

    lcCheck('Manage companyinfo is the source of truth: restricted there → 423 here, active → lifted', function () {
        $until = gmdate('Y-m-d\TH:i:s\Z', time() + 10 * 86400);
        file_put_contents(sys_get_temp_dir() . '/stub-manage-lifecycle.json', json_encode([(string) LC_CMP => ['lifecycle' => 'restricted', 'restricted_until' => $until]]));
        [$status, $json] = lcPersonWrite();
        lcSame([423, 'COMPANY_RESTRICTED'], [$status, $json['code'] ?? null], 'restricted by companyinfo');
        lcSame('companyinfo', Db::scalar('SELECT source FROM aic_lifecycle_state WHERE target_cmp_id = :c', ['c' => LC_CMP]), 'recorded from companyinfo');
        file_put_contents(sys_get_temp_dir() . '/stub-manage-lifecycle.json', json_encode([(string) LC_CMP => ['lifecycle' => 'active', 'restricted_until' => null]]));
        lcTrue(lcPersonWrite()[0] !== 423, 'active again');
        @unlink(sys_get_temp_dir() . '/stub-manage-lifecycle.json');
    });

    lcCheck('company_purge_requested: queued, purged by the worker (statutory records retained), acked to Manage', function () {
        $c = LC_PURGE_CMP;
        Db::run('DELETE FROM purchase_settings WHERE cmp_id = :c', ['c' => $c]);       // a previous run that stopped half-way
        Db::run('DELETE FROM purchase_match_policies WHERE cmp_id = :c', ['c' => $c]);
        Db::run('INSERT INTO purchase_settings (cmp_id) VALUES (:c)', ['c' => $c]);
        Db::run("INSERT INTO purchase_match_policies (cmp_id, policy_name) VALUES (:c, 'Lifecycle test')", ['c' => $c]);
        Db::run("INSERT INTO purchase_audit_log (cmp_id, fy_id, actor_uuid, actor_kind, source_app, action, entity_type) VALUES (:c, 6, 'user-owner', 'person', 'purchases', 'settings.updated', 'settings')", ['c' => $c]);

        // kit 1.1 chain: requisition -> RFQ -> quote -> PO a bill was booked against (kept), and a requisition,
        // an RFQ with its quote, and a PO nothing retained points at (purged).
        Db::connect()->exec("SET aicountly.lifecycle_purge = 'on'");     // what config/lifecycle-kit.php does for the worker
        foreach (['purchase_bill_requests', 'purchase_order_lines', 'purchase_orders', 'purchase_quote_lines', 'purchase_quotes', 'purchase_rfq_lines', 'purchase_rfqs', 'purchase_requisition_lines', 'purchase_requisitions'] as $t) {
            Db::run('DELETE FROM ' . $t . ' WHERE cmp_id = :c', ['c' => $c]);
        }
        $id = static fn (string $sql, array $p = []): int => (int) Db::scalar($sql, ['c' => $c] + $p);
        $req = $id("INSERT INTO purchase_requisitions (cmp_id, fy_id, requisition_no, requisition_date, requester_uuid) VALUES (:c, 6, 'LC-R1', CURRENT_DATE, 'user-owner') RETURNING requisition_id");
        $reqIdle = $id("INSERT INTO purchase_requisitions (cmp_id, fy_id, requisition_no, requisition_date, requester_uuid) VALUES (:c, 6, 'LC-R2', CURRENT_DATE, 'user-owner') RETURNING requisition_id");
        $rfq = $id("INSERT INTO purchase_rfqs (cmp_id, fy_id, rfq_no, rfq_date, created_by) VALUES (:c, 6, 'LC-RFQ1', CURRENT_DATE, 'user-owner') RETURNING rfq_id");
        $rfqLine = $id('INSERT INTO purchase_rfq_lines (cmp_id, rfq_id, line_no) VALUES (:c, :r, 1) RETURNING line_id', ['r' => $rfq]);
        $rfqIdle = $id("INSERT INTO purchase_rfqs (cmp_id, fy_id, rfq_no, rfq_date, created_by) VALUES (:c, 6, 'LC-RFQ2', CURRENT_DATE, 'user-owner') RETURNING rfq_id");
        $quote = $id('INSERT INTO purchase_quotes (cmp_id, rfq_id, supplier_account_id) VALUES (:c, :r, 601) RETURNING quote_id', ['r' => $rfq]);
        $quoteIdle = $id('INSERT INTO purchase_quotes (cmp_id, rfq_id, supplier_account_id) VALUES (:c, :r, 601) RETURNING quote_id', ['r' => $rfqIdle]);
        $po = $id("INSERT INTO purchase_orders (cmp_id, fy_id, po_no, po_date, supplier_account_id, created_by, requisition_id, rfq_id, quote_id) VALUES (:c, 6, 'LC-PO1', CURRENT_DATE, 601, 'user-owner', :r, :f, :q) RETURNING po_id",
            ['r' => $req, 'f' => $rfq, 'q' => $quote]);
        $poLine = $id('INSERT INTO purchase_order_lines (cmp_id, po_id, line_no) VALUES (:c, :p, 1) RETURNING line_id', ['p' => $po]);
        $poIdle = $id("INSERT INTO purchase_orders (cmp_id, fy_id, po_no, po_date, supplier_account_id, created_by) VALUES (:c, 6, 'LC-PO2', CURRENT_DATE, 601, 'user-owner') RETURNING po_id");
        $bill = $id("INSERT INTO purchase_bill_requests (cmp_id, fy_id, supplier_account_id, requested_by, po_id) VALUES (:c, 6, 601, 'user-owner', :p) RETURNING request_id", ['p' => $po]);

        $auditBefore = (int) Db::scalar('SELECT COUNT(*) FROM purchase_audit_log WHERE cmp_id = :c', ['c' => $c]);
        $event = lcCompanyEvent('company_purge_requested', $c);
        [$status, $json] = lcEvent($event);
        lcSame([202, 'queued'], [$status, $json['outcome'] ?? null], 'queued');
        lcSame(423, lcPersonWrite($c)[0], 'read-only while the purge is pending');

        $acks = [];
        $kit = Kit::fromEnv('purchases', Db::connect(), LC_MANIFEST, static fn (string $n): ?string => ['LIFECYCLE_WEBHOOK_SECRET' => LC_SECRET, 'MANAGE_API_URL' => 'https://manage.example.test'][$n] ?? null, [], [
            'http' => static function (string $method, string $url, array $headers, ?string $body) use (&$acks): array {
                $acks[] = ['url' => $url, 'body' => json_decode((string) $body, true), 'headers' => $headers];

                return ['status' => 200, 'body' => '{"accepted":true}', 'error' => null];
            },
        ]);
        $kit->worker()->run();

        $job = Db::first('SELECT status, archive_name, retained FROM aic_lifecycle_jobs WHERE event_id = :e', ['e' => $event['event_id']]);
        lcSame('purged', $job['status'] ?? null, 'job purged');
        lcSame(0, (int) Db::scalar('SELECT COUNT(*) FROM purchase_settings WHERE cmp_id = :c', ['c' => $c]), 'purchase_settings deleted');
        lcSame(0, (int) Db::scalar('SELECT COUNT(*) FROM purchase_match_policies WHERE cmp_id = :c', ['c' => $c]), 'purchase_match_policies deleted');
        lcSame($auditBefore, (int) Db::scalar('SELECT COUNT(*) FROM purchase_audit_log WHERE cmp_id = :c', ['c' => $c]), 'purchase_audit_log retained');
        $archive = (string) $job['archive_name'];
        lcSame(1, (int) Db::scalar('SELECT COUNT(*) FROM ' . $archive . '.purchase_settings'), 'archived first');
        lcSame('purged', Db::scalar('SELECT state FROM aic_lifecycle_state WHERE target_cmp_id = :c', ['c' => $c]), 'state purged');

        lcSame(1, count($acks), 'one ack');
        lcSame('https://manage.example.test/api/internal/company-lifecycle/ack', $acks[0]['url'], 'ack URL');
        lcSame(['event_id' => $event['event_id'], 'cmp_id' => $c, 'app_key' => 'purchases', 'status' => 'purged'], array_intersect_key($acks[0]['body'], array_flip(['event_id', 'cmp_id', 'app_key', 'status'])), 'ack body');
        lcTrue(in_array('public.purchase_audit_log', $acks[0]['body']['retained'], true), 'retained names purchase_audit_log: ' . json_encode($acks[0]['body']['retained']));
        lcSame(423, lcPersonWrite($c)[0], 'a purged company stays read-only');

        // kit 1.1 row-level retention: the chain the retained bill points at stays, the rest goes.
        $live = static fn (string $t, string $col, int $v): int => (int) Db::scalar('SELECT COUNT(*) FROM ' . $t . ' WHERE ' . $col . ' = :v', ['v' => $v]);
        lcSame([1, 1, 1, 1, 1, 1, 1], [$live('purchase_bill_requests', 'request_id', $bill), $live('purchase_orders', 'po_id', $po), $live('purchase_order_lines', 'line_id', $poLine),
            $live('purchase_quotes', 'quote_id', $quote), $live('purchase_rfqs', 'rfq_id', $rfq), $live('purchase_rfq_lines', 'line_id', $rfqLine), $live('purchase_requisitions', 'requisition_id', $req)],
            'bill, its PO (with lines), quote, RFQ (with lines) and requisition are kept');
        lcSame([0, 0, 0, 0], [$live('purchase_orders', 'po_id', $poIdle), $live('purchase_quotes', 'quote_id', $quoteIdle), $live('purchase_rfqs', 'rfq_id', $rfqIdle), $live('purchase_requisitions', 'requisition_id', $reqIdle)],
            'the unreferenced PO, quote, RFQ and requisition are purged');
        lcSame(1, (int) Db::scalar('SELECT COUNT(*) FROM ' . $archive . '.purchase_rfqs WHERE rfq_id = :r', ['r' => $rfqIdle]), 'archived first');
        lcTrue(in_array('public.purchase_orders', $acks[0]['body']['retained'], true), 'retained names the kept orders');

        // kit 1.1 retention expiry: nothing within 8 years; after, the bill and the audit rows go, then the chain.
        $expiry = static fn (int $now) => Kit::fromEnv('purchases', Db::connect(), LC_MANIFEST, static fn (string $n): ?string => ['LIFECYCLE_WEBHOOK_SECRET' => LC_SECRET, 'MANAGE_API_URL' => 'https://manage.example.test'][$n] ?? null, [], [
            'clock' => static fn (): int => $now, 'http' => static fn (): array => ['status' => 200, 'body' => '{}', 'error' => null],
        ])->worker()->expireRetained();
        $today = $expiry(time());
        lcSame([0, []], [$today['expired_rows'], $today['errors']], 'nothing expires within 8 years');
        $later = $expiry((int) strtotime('+8 years +1 day'));
        lcSame([], $later['errors'], 'expiry errors');
        lcSame([0, 0, 0, 0, 0, 0, 0, 0], [$live('purchase_bill_requests', 'request_id', $bill), $live('purchase_orders', 'po_id', $po), $live('purchase_order_lines', 'line_id', $poLine),
            $live('purchase_quotes', 'quote_id', $quote), $live('purchase_rfqs', 'rfq_id', $rfq), $live('purchase_rfq_lines', 'line_id', $rfqLine), $live('purchase_requisitions', 'requisition_id', $req),
            (int) Db::scalar('SELECT COUNT(*) FROM purchase_audit_log WHERE cmp_id = :c', ['c' => $c])], 'all gone after the period');
        $expiredArchive = (string) Db::scalar('SELECT archive_name FROM aic_lifecycle_expiries WHERE target_cmp_id = :c ORDER BY id DESC LIMIT 1', ['c' => $c]);
        lcSame(1, (int) Db::scalar('SELECT COUNT(*) FROM ' . $expiredArchive . '.purchase_bill_requests'), 'expired rows are archived first');

        Db::connect()->exec('DROP SCHEMA ' . $archive . ' CASCADE');
        Db::connect()->exec('DROP SCHEMA ' . $expiredArchive . ' CASCADE');
        Db::connect()->exec('RESET aicountly.lifecycle_purge');
        Db::run('DELETE FROM purchase_settings WHERE cmp_id = :c', ['c' => $c]);
        Db::connect()->exec('TRUNCATE purchase_audit_log');
    });

    lcCheck('account_purge_requested: saved views of that person are purged (archived first), acked with uuid and no cmp_id', function () {
        Db::run('DELETE FROM purchase_user_preferences WHERE cmp_id = :c', ['c' => LC_CMP]);
        Db::run("INSERT INTO purchase_user_preferences (cmp_id, user_uuid, scope, payload) VALUES (:c, '1001', 'bills.list', '{}')", ['c' => LC_CMP]);
        Db::run("INSERT INTO purchase_user_preferences (cmp_id, user_uuid, scope, payload) VALUES (:c, '2002', 'bills.list', '{}')", ['c' => LC_CMP]);
        $event = ['event_id' => lcUuid(), 'event' => 'account_purge_requested', 'uuid' => '1001', 'app_key' => 'purchases', 'reason' => 'account_closed', 'occurred_at' => gmdate('Y-m-d\TH:i:s\Z')];
        [$status, $json] = lcEvent($event);
        lcSame([202, 'queued', '1001'], [$status, $json['outcome'] ?? null, $json['uuid'] ?? null], 'queued');
        $bad = $event;
        $bad['event_id'] = lcUuid();
        $bad['cmp_id'] = LC_CMP;
        lcSame(422, lcEvent($bad)[0], 'an account event with a cmp_id');

        $acks = [];
        $kit = Kit::fromEnv('purchases', Db::connect(), LC_MANIFEST, static fn (string $n): ?string => ['LIFECYCLE_WEBHOOK_SECRET' => LC_SECRET, 'MANAGE_API_URL' => 'https://manage.example.test'][$n] ?? null, [], [
            'http' => static function (string $method, string $url, array $headers, ?string $body) use (&$acks): array {
                $acks[] = json_decode((string) $body, true);

                return ['status' => 200, 'body' => '{}', 'error' => null];
            },
        ]);
        $kit->worker()->run();
        lcSame('purged', Db::scalar('SELECT status FROM aic_lifecycle_jobs WHERE event_id = :e', ['e' => $event['event_id']]), 'job');
        lcSame(['uuid' => '1001', 'status' => 'purged'], ['uuid' => $acks[0]['uuid'] ?? null, 'status' => $acks[0]['status'] ?? null], 'ack');
        lcTrue(!array_key_exists('cmp_id', $acks[0]), 'no cmp_id on an account ack');
        lcSame(0, (int) Db::scalar("SELECT COUNT(*) FROM purchase_user_preferences WHERE user_uuid = '1001'"), 'the person\'s views are gone');
        lcSame(1, (int) Db::scalar("SELECT COUNT(*) FROM purchase_user_preferences WHERE user_uuid = '2002'"), 'another person\'s stay');
        Db::connect()->exec('DROP SCHEMA ' . Db::scalar('SELECT archive_name FROM aic_lifecycle_jobs WHERE event_id = :e', ['e' => $event['event_id']]) . ' CASCADE');
        Db::run('DELETE FROM purchase_user_preferences WHERE cmp_id = :c', ['c' => LC_CMP]);
    });

    lcCheck('without LIFECYCLE_WEBHOOK_SECRET the receiver answers 503 (Manage retries) and restricts nothing', function () {
        $kit = Kit::fromEnv('purchases', Db::connect(), LC_MANIFEST, static fn (string $n): ?string => null);
        $raw = (string) json_encode(lcCompanyEvent('company_restricted', LC_CMP));
        [$status] = $kit->receiver()->handle($raw, Signature::headerLines(LC_SECRET, $raw, time()));
        lcSame(503, $status, 'not configured');
    });
} finally {
    lcReset();
    @unlink(sys_get_temp_dir() . '/stub-manage-app-restricted.txt');
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
}

echo "\n{$lcPassed} passed, {$lcFailed} failed\n";
exit($lcFailed === 0 ? 0 : 1);
