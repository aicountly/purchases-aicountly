<?php

declare(strict_types=1);

/**
 * Manage's company lifecycle, Purchase as a SENDER — required from tests/integration.php, sharing
 * its harness. Smart Books and Inventory answer 423 COMPANY_RESTRICTED for a company whose
 * subscription to them lapsed (read-only for 45 days, then purged). For Purchase that is
 * "deferred, not failed": the command is kept as DEFERRED_RESTRICTED (never BLOCKED, never stale),
 * the person reads until when; the monitor's hourly review waits, makes it sendable again on
 * renewal (Retry then posts on the SAME key), or drops it with an audit entry when the company is
 * deleted / purged there.
 */

namespace Aicountly\Api;

use Aicountly\Api\Domain\BillService;
use Aicountly\Api\Ops\OpsStatus;

echo "\nCompany lifecycle: a read-only Smart Books / Inventory company (deferred, not failed)\n";

/** Books' 423 (CompanyReadOnlyGuard::refusalBody). */
function stubRestricted(string $pathFragment, string $lifecycle = 'restricted', ?string $until = '2026-11-28T00:00:00Z'): void
{
    $message = 'This company is read-only until 28 Nov 2026 because its subscription ended. Renew to make changes, or export your data.';
    file_put_contents(sys_get_temp_dir() . '/stub-control.json', json_encode(['path' => $pathFragment, 'status' => 423, 'body' => [
        'status' => 423, 'code' => 'COMPANY_RESTRICTED', 'message' => $message, 'restricted_until' => $until, 'lifecycle' => $lifecycle,
        'error' => ['code' => 'COMPANY_RESTRICTED', 'message' => $message, 'restricted_until' => $until, 'lifecycle' => $lifecycle],
        'messages' => ['error' => $message],
    ]]));
}

/** @return array{0: BillService, 1: int} a bill entered against a received order, not yet posted */
function restrictedBill(Context $ctx, Auth $auth, string $no): array
{
    $po = receivedOrder($ctx, $auth);
    $bills = new BillService($ctx, $auth);
    $bill = $bills->enter([
        'supplier_account_id' => 601, 'po_id' => (int) $po['po_id'], 'supplier_invoice_no' => $no . '-' . $po['po_id'], 'supplier_invoice_date' => '2026-09-19',
        'lines' => [['po_line_id' => (int) $po['lines'][0]['line_id'], 'qty' => 100, 'rate' => 250]],
    ]);

    return [$bills, (int) $bill['request_id']];
}

/** @return array<string, mixed> the newest Books command */
function booksCommand(): array
{
    return Db::first("SELECT * FROM purchase_integration_commands WHERE target_service = 'books' ORDER BY command_id DESC LIMIT 1") ?? [];
}

function dueNow(): void
{
    Db::run("UPDATE purchase_integration_commands SET restriction_check_at = NOW() - interval '1 minute' WHERE status = 'DEFERRED_RESTRICTED'");
}

check('a read-only Smart Books company keeps the bill\'s command, says until when, and Retry after renewal posts it on the same key', function () use ($ctx, $auth) {
    resetDatabase();
    [$bills, $requestId] = restrictedBill($ctx, $auth, 'LCY');

    stubRestricted('vouchers/drafts');
    assertThrows(static fn () => $bills->post($requestId), 'read-only until 28 Nov 2026', 'the person is told why and until when');
    stubRecover();

    $command = booksCommand();
    assertSame(IntegrationCommand::DEFERRED_RESTRICTED, $command['status'], 'kept, not BLOCKED');
    assertTrue($command['restricted_until'] !== null, 'the date is kept');
    assertTrue(str_contains((string) $command['last_error'], 'press Retry once it is'), 'the sentence is stored: ' . $command['last_error']);
    assertTrue(in_array(IntegrationCommand::DEFERRED_RESTRICTED, OpsStatus::CLOSED, true), 'never a stale command');
    $listed = array_filter(IntegrationCommand::outstanding($ctx, [(string) $command['entity_type']]), static fn ($c) => (int) $c['command_id'] === (int) $command['command_id']);
    assertSame(1, count($listed), 'listed as unresolved');

    assertSame(0, IntegrationCommand::reviewRestricted(50, static fn (): array => ['known' => true, 'status' => 'active', 'lifecycle' => 'active'])['companies'], 'not looked at again within the hour');
    dueNow();
    $wait = IntegrationCommand::reviewRestricted(50, static fn (): array => ['known' => true, 'status' => 'active', 'lifecycle' => 'restricted', 'restricted_until' => '2026-11-28T00:00:00Z']);
    assertSame(1, $wait['waiting'], 'still read-only: waits');
    dueNow();
    $ready = IntegrationCommand::reviewRestricted(50, static fn (): array => ['known' => true, 'status' => 'active', 'lifecycle' => 'active']);
    assertSame(1, $ready['ready'], 'renewed: sendable again');
    assertSame(IntegrationCommand::FAILED, booksCommand()['status'], 'retryable');

    $posted = $bills->post($requestId);
    assertSame('POSTED', $posted['status'], 'Retry posts it');
    assertSame($command['idempotency_key'], booksCommand()['idempotency_key'], 'on the key it was first sent with');
});

check('the company deleted in Manage, or purged in Books, drops the command with an audit entry', function () use ($ctx, $auth) {
    resetDatabase();
    [$bills, $requestId] = restrictedBill($ctx, $auth, 'LCD');
    stubRestricted('vouchers/drafts');
    assertThrows(static fn () => $bills->post($requestId), 'read-only', 'deferred');
    stubRecover();
    dueNow();
    $dropped = IntegrationCommand::reviewRestricted(50, static fn (): array => ['known' => true, 'status' => 'deleted', 'lifecycle' => 'active']);
    assertSame(1, $dropped['dropped'], 'dropped');
    assertSame(IntegrationCommand::DROPPED_COMPANY_DELETED, booksCommand()['status'], 'never sent');
    assertSame(1, (int) Db::scalar("SELECT COUNT(*) FROM purchase_audit_log WHERE action = 'integration_command.dropped_company_deleted'"), 'audited');

    resetDatabase();
    [$bills, $requestId] = restrictedBill($ctx, $auth, 'LCP');
    stubRestricted('vouchers/drafts', 'purged', null);
    assertThrows(static fn () => $bills->post($requestId), 'has been deleted', 'purged: the person is told');
    stubRecover();
    assertSame(IntegrationCommand::DROPPED_COMPANY_DELETED, booksCommand()['status'], 'purged there: dropped at once');
    assertSame(1, (int) Db::scalar("SELECT COUNT(*) FROM purchase_audit_log WHERE action = 'integration_command.dropped_company_deleted'"), 'audited');
});

check('Manage is asked for the target product, with the product service key', function () {
    file_put_contents(sys_get_temp_dir() . '/stub-lifecycle.json', json_encode(['55' => ['lifecycle' => 'restricted', 'restricted_until' => '2026-11-28T00:00:00Z']]));
    $before = getenv('MANAGE_PRODUCT_SERVICE_KEY');
    putenv('MANAGE_PRODUCT_SERVICE_KEY=test-manage-product-key');
    try {
        $answer = RestrictedTarget::manageStatus(55, 'books');
    } finally {
        putenv($before === false ? 'MANAGE_PRODUCT_SERVICE_KEY' : 'MANAGE_PRODUCT_SERVICE_KEY=' . $before);
        @unlink(sys_get_temp_dir() . '/stub-lifecycle.json');
    }
    assertSame(['known' => true, 'status' => 'active', 'lifecycle' => 'restricted', 'restricted_until' => '2026-11-28T00:00:00Z'], $answer, 'Manage\'s answer');
    assertSame(false, RestrictedTarget::manageStatus(55, 'books')['known'], 'no key: unknown, never "active"');
});

check('a read-only Inventory company keeps the receipt\'s command and says until when', function () use ($ctx, $auth) {
    resetDatabase();
    $orders = new \Aicountly\Api\Domain\PurchaseOrderService($ctx, $auth);
    $po = $orders->create(poInput());
    $orders->submit((int) $po['po_id']);
    $orders->issue((int) $po['po_id']);

    stubRestricted('inventory-documents');
    assertThrows(
        static fn () => (new \Aicountly\Api\Domain\ReceiptService($ctx, $auth))->request((int) $po['po_id'], []),
        'The target company in Inventory is read-only until 28 Nov 2026',
        'the person is told why and until when',
    );
    stubRecover();

    $command = Db::first("SELECT * FROM purchase_integration_commands WHERE target_service = 'inventory' ORDER BY command_id DESC LIMIT 1") ?? [];
    assertSame(IntegrationCommand::DEFERRED_RESTRICTED, $command['status'] ?? null, 'kept, not BLOCKED');
});
