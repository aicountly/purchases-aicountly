<?php

declare(strict_types=1);

/**
 * The audit trail (AICOUNTLY audit-trail standard v1): the writer, fail-closed material rows,
 * coverage of the core documents and access, and the read API with its CSV.
 *
 * Required by tests/integration.php, whose helpers, stub and database it shares.
 */

namespace Aicountly\Api;

use Aicountly\Api\Controllers\AuditController;
use Aicountly\Api\Controllers\SettingsController;
use Aicountly\Api\Domain\PurchaseOrderService;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/**
 * Call a controller action as $auth, with $query as the request's parameters.
 *
 * @param array<string, mixed> $query
 * @param list<string>         $args
 * @return array{status: int, data: mixed, meta: mixed, message: mixed}
 */
function callAudited(string $class, string $action, Context $ctx, Auth $auth, array $query = [], array $args = []): array
{
    $_GET = ['cmp_id' => (string) $ctx->cmpId, 'fy_id' => (string) $ctx->fyId, 'bo_id' => (string) $ctx->boId] + $query;
    $reflection = new \ReflectionClass(Http::class);
    $cached = $reflection->getProperty('body');
    $cached->setAccessible(true);
    $cached->setValue(null, $query);
    Auth::adopt($auth);
    Permissions::forget();

    try {
        $class::$action(...$args);
    } catch (ResponseSent $sent) {
        return [
            'status'  => $sent->status,
            'data'    => $sent->payload['data'] ?? null,
            'meta'    => $sent->payload['meta'] ?? null,
            'message' => $sent->payload['message'] ?? null,
        ];
    } finally {
        Auth::adopt(null);
        $cached->setValue(null, null);
        $_GET = [];
    }

    throw new \RuntimeException("{$class}::{$action} returned without responding");
}

/**
 * A row's stored changes, each as field/label/old/new (jsonb keeps its own key order).
 *
 * @return list<array{field: mixed, label: mixed, old: mixed, new: mixed}>
 */
function storedChanges(array $row): array
{
    return array_map(
        static fn (array $c) => ['field' => $c['field'] ?? null, 'label' => $c['label'] ?? null, 'old' => $c['old'] ?? null, 'new' => $c['new'] ?? null],
        Db::jsonColumn($row['changes']),
    );
}

/** @return array<string, mixed> the newest audit row for this action */
function lastAudit(string $action): array
{
    $row = Db::first('SELECT * FROM purchase_audit_log WHERE action = :a ORDER BY audit_id DESC LIMIT 1', ['a' => $action]);
    if ($row === null) {
        throw new \RuntimeException("no '{$action}' row was written");
    }

    return $row;
}

echo "\nAudit trail (standard v1)\n";

check('the writer records the request: id, address, browser, label, summary and severity', function () use ($ctx, $auth) {
    resetDatabase();
    $server = $_SERVER;
    $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
    $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.1';
    $_SERVER['HTTP_USER_AGENT'] = "Mozilla/5.0 (Test)\r\nInjected: header";
    try {
        Audit::record($ctx, $auth, 'test.recorded', 'purchase_order', 41, ['status' => 'DRAFT', 'po_no' => 'PO/6/0041'], ['status' => 'APPROVED', 'po_no' => 'PO/6/0041']);
    } finally {
        $_SERVER = $server;
    }
    $row = lastAudit('test.recorded');
    assertSame('203.0.113.9', $row['ip_address'], 'REMOTE_ADDR is preferred over a forwarded header anyone can send');
    assertSame('Mozilla/5.0 (Test) Injected: header', $row['user_agent'], 'the browser, with control characters flattened');
    assertTrue(is_string($row['request_id']) && strlen($row['request_id']) >= 8, 'a request id');
    assertSame('PO/6/0041', $row['entity_label'], 'the record is named by its number');
    assertSame('Purchase order PO/6/0041: recorded', $row['summary'], 'one readable line');
    assertSame('info', $row['severity'], 'severity');
    $changes = storedChanges($row);
    assertSame(1, count($changes), 'only the field that changed: ' . json_encode($changes));
    assertSame(['field' => 'status', 'label' => 'Status', 'old' => 'DRAFT', 'new' => 'APPROVED'], $changes[0], 'old -> new');
});

check('behind a private proxy the last forwarded hop is the caller, never the first', function () {
    $server = $_SERVER;
    try {
        $_SERVER['REMOTE_ADDR'] = '10.0.0.5';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '1.2.3.4, 198.51.100.7';
        assertSame('198.51.100.7', Audit::clientIp(), 'the hop the proxy appended');
        unset($_SERVER['HTTP_X_FORWARDED_FOR']);
        assertSame('10.0.0.5', Audit::clientIp(), 'no header: the connection itself');
    } finally {
        $_SERVER = $server;
    }
});

check('the diff skips unchanged fields and secrets, and snapshots never hold a secret', function () use ($ctx, $auth) {
    resetDatabase();
    $changes = Audit::changes(
        ['name' => 'Same', 'amount' => '100.0000', 'password' => 'old', 'api_key' => 'k1', 'ses_key' => 's', 'shipping' => 'road', 'upi_pin' => '1234'],
        ['name' => 'Same', 'amount' => 100, 'password' => 'new', 'api_key' => 'k2', 'ses_key' => 't', 'shipping' => 'air', 'upi_pin' => '9999'],
    );
    assertSame(['shipping'], array_column($changes, 'field'), 'only the real, non-secret change');
    assertTrue(Audit::isSecret('refresh_token') && Audit::isSecret('otp') && !Audit::isSecret('shipping'), 'secret names');

    Audit::record($ctx, $auth, 'test.secret', 'settings', 88, ['token' => 'abc'], ['token' => 'def', 'nested' => ['secret' => 'x', 'ok' => 1]]);
    $row = lastAudit('test.secret');
    assertTrue(!str_contains((string) $row['before_state'] . (string) $row['after_state'], 'abc')
        && !str_contains((string) $row['after_state'], 'def') && !str_contains((string) $row['after_state'], '"x"'), 'secret values redacted at any depth');
    assertSame(['nested'], array_column(storedChanges($row), 'field'), 'the token change itself is not recorded');
});

check('the audit log refuses UPDATE and DELETE of the new columns too', function () use ($ctx, $auth) {
    resetDatabase();
    Audit::record($ctx, $auth, 'test.immutable', 'purchase_order', 1);
    assertThrows(static fn () => Db::run("UPDATE purchase_audit_log SET summary = 'rewritten', severity = 'info'"), 'append-only', 'UPDATE');
    assertThrows(static fn () => Db::run('DELETE FROM purchase_audit_log WHERE action = :a', ['a' => 'test.immutable']), 'append-only', 'DELETE');
    assertThrows(static fn () => Db::run("INSERT INTO purchase_audit_log (cmp_id, fy_id, actor_uuid, actor_kind, source_app, action, entity_type, severity)
        VALUES (88, 6, 'x', 'user', 'purchases', 'x', 'x', 'catastrophic')"), 'severity_check', 'an unknown severity');
});

check('best-effort audit inside a transaction cannot abort the caller\'s work', function () use ($ctx, $auth) {
    resetDatabase();
    $kept = Db::transaction(static function () use ($ctx, $auth): int {
        Db::run('ALTER TABLE purchase_audit_log RENAME TO purchase_audit_log_held');
        try {
            Audit::record($ctx, $auth, 'test.lost', 'purchase_order', 1);
            // On PostgreSQL a failed statement poisons the transaction; the savepoint is what keeps this working.
            return (int) Db::scalar('SELECT 1');
        } finally {
            Db::run('ALTER TABLE purchase_audit_log_held RENAME TO purchase_audit_log');
        }
    });
    assertSame(1, $kept, 'the transaction carried on');
});

check('a PO approval whose audit row cannot be written is not approved (fail-closed)', function () use ($ctx, $auth) {
    resetDatabase();
    Db::run('INSERT INTO purchase_settings (cmp_id, po_approval_above_amount) VALUES (88, 1) ON CONFLICT (cmp_id) DO UPDATE SET po_approval_above_amount = 1');
    grantProfile($ctx, 'user-approver', 'Approver', ['po.view', 'po.approve']);
    $approver = authFor('user-approver', 2);
    $po = (new PurchaseOrderService($ctx, $auth))->create(poInput());
    (new PurchaseOrderService($ctx, $auth))->submit((int) $po['po_id']);

    assertThrows(static function () use ($ctx, $approver, $po): void {
        Db::transaction(static function () use ($ctx, $approver, $po): void {
            Db::run('ALTER TABLE purchase_audit_log RENAME TO purchase_audit_log_held');
            (new PurchaseOrderService($ctx, $approver))->decide((int) $po['po_id'], 'approve', []);
        });
    }, 'audit record could not be written', 'the approval fails');
    assertSame('APPROVAL_PENDING', (string) Db::scalar('SELECT status FROM purchase_orders WHERE po_id = :id', ['id' => (int) $po['po_id']]), 'and nothing was approved');
    assertTrue(Db::scalar("SELECT to_regclass('purchase_audit_log')") !== null, 'the table is back');

    // With the trail writable, the same approval is recorded with what changed.
    (new PurchaseOrderService($ctx, $approver))->decide((int) $po['po_id'], 'approve', []);
    $row = lastAudit('po.approve');
    assertSame('material', $row['severity'], 'an approval is material');
    assertSame('user-approver', $row['actor_uuid'], 'by the approver');
    assertSame((string) $po['po_no'], $row['entity_label'], 'naming the order');
    $changes = storedChanges($row);
    assertSame([['field' => 'status', 'label' => 'Status', 'old' => 'APPROVAL_PENDING', 'new' => 'APPROVED']], $changes, 'status old -> new');
});

check('core writes are traced: PO create/submit/cancel, settings, match policies, access', function () use ($ctx, $auth) {
    resetDatabase();
    $orders = new PurchaseOrderService($ctx, $auth);
    $po = $orders->create(poInput());
    $orders->submit((int) $po['po_id']);
    $orders->cancel((int) $po['po_id'], ['reason' => 'Ordered twice.']);
    foreach (['po.created', 'po.submitted', 'po.cancelled'] as $action) {
        assertSame((string) $po['po_no'], lastAudit($action)['entity_label'], $action . ' names the order');
    }
    assertSame('Ordered twice.', lastAudit('po.cancelled')['reason'], 'the cancel carries its reason');

    $r = callAudited(SettingsController::class, 'update', $ctx, $auth, ['po_prefix' => 'PUR', 'sod_policy' => 'strict']);
    assertSame(200, $r['status'], 'settings saved: ' . json_encode($r['message']));
    $updated = storedChanges(lastAudit('settings.updated'));
    assertSame('po_prefix', $updated[0]['field'] ?? null, 'the numbering prefix change: ' . json_encode($updated));
    assertSame('PUR', $updated[0]['new'] ?? null, 'to its new value');
    assertSame('material', lastAudit('settings.controls_changed')['severity'], 'the control change too');

    $r = callAudited(SettingsController::class, 'saveMatchPolicy', $ctx, $auth, ['policy_name' => 'Tight', 'qty_tolerance_pc' => 1]);
    assertSame(200, $r['status'], 'policy saved');
    $policyId = (int) Db::scalar("SELECT policy_id FROM purchase_match_policies WHERE policy_name = 'Tight'");
    callAudited(SettingsController::class, 'saveMatchPolicy', $ctx, $auth, ['policy_id' => $policyId, 'policy_name' => 'Tight', 'qty_tolerance_pc' => 2]);
    $changed = storedChanges(lastAudit('settings.match_policy_updated'));
    assertSame(['qty_tolerance_pc'], array_column($changed, 'field'), 'only the tolerance changed: ' . json_encode($changed));
    callAudited(SettingsController::class, 'deleteMatchPolicy', $ctx, $auth, [], [(string) $policyId]);
    assertSame('Tight', lastAudit('settings.match_policy_deleted')['entity_label'], 'a deleted policy still names itself');

    $profileId = grantProfile($ctx, 'user-x', 'Viewer', ['po.view']);
    callAccess('assign', $ctx, $auth, ['user_uuid' => 'user-new', 'profile_id' => $profileId]);
    $grant = lastAudit('access.profile_assigned');
    assertSame('security', $grant['severity'], 'an access change is a security event');
    assertSame('Viewer', $grant['entity_label'], 'naming the profile granted');
});

check('GET v1/audit-log: filters, paging, total, and only this company', function () use ($ctx, $auth) {
    resetDatabase();
    $orders = new PurchaseOrderService($ctx, $auth);
    $po = $orders->create(poInput());
    $orders->submit((int) $po['po_id']);
    Audit::record(freshContext(999), authFor('user-other', 1, 999), 'po.created', 'purchase_order', 5, null, ['po_no' => 'OTHER/1']);

    $all = callAudited(AuditController::class, 'index', $ctx, $auth);
    assertSame(200, $all['status'], 'answered');
    assertSame(2, $all['meta']['total'], 'two rows in this company, not the other one');
    assertTrue(!in_array('OTHER/1', array_column($all['data'], 'entity_label'), true), 'another company\'s row is never shown');

    $byAction = callAudited(AuditController::class, 'index', $ctx, $auth, ['action' => 'po.submitted']);
    assertSame(1, $byAction['meta']['total'], 'by action');
    $byPrefix = callAudited(AuditController::class, 'index', $ctx, $auth, ['action' => 'po.*', 'limit' => 1]);
    assertSame([2, 1], [$byPrefix['meta']['total'], count($byPrefix['data'])], 'by prefix, paged with the full total');
    $byEntity = callAudited(AuditController::class, 'index', $ctx, $auth, ['entity_type' => 'purchase_order', 'entity_id' => (string) $po['po_id']]);
    assertSame(2, $byEntity['meta']['total'], 'by record');
    $byQ = callAudited(AuditController::class, 'index', $ctx, $auth, ['q' => (string) $po['po_no']]);
    assertSame(2, $byQ['meta']['total'], 'by search on the number');
    $byActor = callAudited(AuditController::class, 'index', $ctx, $auth, ['actor_uuid' => 'nobody']);
    assertSame(0, $byActor['meta']['total'], 'by actor');
    $future = callAudited(AuditController::class, 'index', $ctx, $auth, ['from' => '2099-01-01']);
    assertSame(0, $future['meta']['total'], 'by date');
    $bad = callAudited(AuditController::class, 'index', $ctx, $auth, ['from' => '2026-02-31']);
    assertSame(422, $bad['status'], 'a date that is not one is refused');

    $history = callAudited(AuditController::class, 'entity', $ctx, $auth, [], ['purchase_order', (string) $po['po_id']]);
    assertSame(['po.submitted', 'po.created'], array_column($history['data'], 'action'), 'one record\'s history, newest first');
    assertSame(['field' => 'status', 'old' => 'DRAFT', 'new' => 'APPROVED'], array_intersect_key($history['data'][0]['changes'][0] ?? [], ['field' => 1, 'old' => 1, 'new' => 1]), 'with its field changes');
});

check('the audit trail is permission-gated: audit.view to read, audit.export to export', function () use ($ctx, $auth) {
    resetDatabase();
    grantProfile($ctx, 'user-buyer', 'Buyer', ['po.view', 'po.create']);
    grantProfile($ctx, 'user-auditor', 'Auditor', ['audit.view']);
    $buyer = authFor('user-buyer', 2);
    $auditor = authFor('user-auditor', 2);

    assertSame(403, callAudited(AuditController::class, 'index', $ctx, $buyer)['status'], 'no audit.view: refused');
    assertSame(403, callAudited(AuditController::class, 'entity', $ctx, $buyer, [], ['purchase_order', '1'])['status'], 'nor one record\'s history');
    assertSame(200, callAudited(AuditController::class, 'index', $ctx, $auditor)['status'], 'audit.view reads');
    assertSame(403, callAudited(AuditController::class, 'export', $ctx, $auditor)['status'], 'but exporting needs audit.export');
    assertTrue(!in_array('audit.view', ServiceKeys::permissions('books'), true), 'a product key never reads the trail');
});

check('CSV export: formula-safe, capped with a PARTIAL line, and itself recorded', function () use ($ctx, $auth) {
    resetDatabase();
    Audit::record($ctx, $auth, 'po.cancelled', 'purchase_order', 7, null, ['po_no' => 'PO/6/0007'], '=HYPERLINK("http://evil")');
    $r = callAudited(AuditController::class, 'export', $ctx, $auth);
    assertSame(200, $r['status'], 'exported');
    $csv = (string) $r['data']['csv'];
    assertTrue(str_starts_with($csv, "\xEF\xBB\xBF"), 'a BOM for Excel');
    assertTrue(str_contains($csv, "'=HYPERLINK"), 'a formula cell is neutralised');
    assertSame(false, $r['data']['truncated'], 'not truncated');
    assertSame(1, (int) Db::scalar("SELECT COUNT(*) FROM purchase_audit_log WHERE action = 'audit.exported'"), 'the export is in the trail');

    Db::run(
        "INSERT INTO purchase_audit_log (cmp_id, fy_id, actor_uuid, actor_kind, source_app, action, entity_type, entity_id)
         SELECT 88, 6, 'bulk', 'user', 'purchases', 'test.bulk', 'purchase_order', g::text FROM generate_series(1, :n) g",
        ['n' => AuditController::EXPORT_CAP + 1],
    );
    $big = callAudited(AuditController::class, 'export', $ctx, $auth, ['action' => 'test.bulk']);
    assertSame(true, $big['data']['truncated'], 'flagged as truncated');
    assertSame(AuditController::EXPORT_CAP, $big['data']['rows'], 'at the cap');
    assertTrue(str_contains((string) $big['data']['csv'], 'PARTIAL: the first ' . AuditController::EXPORT_CAP), 'and the file says so');
});
