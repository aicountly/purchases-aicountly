<?php

declare(strict_types=1);

/**
 * E8-BK-POST A16: Books cancels a purchase voucher it holds for one of our bills and tells us
 * through POST /api/v1/integration/books/voucher-events (service key + signature), through the
 * real front controller tests/run.sh starts. Required by tests/integration.php.
 */

namespace Aicountly\Api;

use Aicountly\Api\Domain\BillService;
use Aicountly\Api\Support\BooksEventSignature;

echo "\nBooks voucher events (A16)\n";

/** @return array{status:int, json:?array, body:string} */
function booksEvent(array $event, array $override = []): array
{
    $raw = (string) json_encode($event);
    $secret = $override['secret'] ?? (string) getenv('BOOKS_EVENTS_SIGNING_SECRET_FOR_TESTS');
    $headers = [
        'X-Service-Key'     => $override['key'] ?? 'test-books-service-key-0001',
        'X-Books-Event-Id'  => (string) ($event['event_id'] ?? ''),
        'X-Books-Signature' => $override['signature'] ?? BooksEventSignature::sign($secret, $raw, time()),
        'Content-Type'      => 'application/json',
    ];
    $ch = curl_init(rtrim((string) getenv('PURCHASES_E2E_BASE'), '/') . '/v1/integration/books/voucher-events');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $raw, CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => array_map(static fn ($k, $v) => $k . ': ' . $v, array_keys($headers), $headers),
    ]);
    $body = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    $json = json_decode($body, true);

    return ['status' => $status, 'json' => is_array($json) ? $json : null, 'body' => $body];
}

if (getenv('PURCHASES_E2E_BASE')) {
    check('a bill Books cancelled frees its supplier invoice number and its PO quantities, once', function () use ($ctx, $auth) {
        resetDatabase();
        Db::connect()->exec('TRUNCATE purchase_books_event_inbox');
        $po = billedOrder($ctx, $auth);
        $bill = Db::first('SELECT * FROM purchase_bill_requests WHERE po_id = :po', ['po' => (int) $po['po_id']]);
        assertSame('POSTED', $bill['status'], 'the bill posted');
        $voucherId = (int) $bill['books_voucher_id'];
        assertTrue($voucherId > 0, 'Books voucher id kept');
        assertSame('100.0000', (string) Db::scalar('SELECT billed_qty FROM purchase_order_lines WHERE po_id = :po ORDER BY line_id LIMIT 1', ['po' => (int) $po['po_id']]), 'billed in full');
        assertThrows(static fn () => (new BillService($ctx, $auth))->enter([
            'supplier_account_id' => 601, 'po_id' => (int) $po['po_id'], 'supplier_invoice_no' => $bill['supplier_invoice_no'], 'supplier_invoice_date' => '2026-09-19',
            'lines' => [['po_line_id' => (int) $po['lines'][0]['line_id'], 'qty' => 100, 'rate' => 250]],
        ]), 'already been entered', 'the same invoice number is refused while the bill stands');

        $event = ['event_id' => 'evt-a16-p-' . $voucherId, 'event_type' => 'voucher.cancelled', 'cmp_id' => 88, 'fy_id' => 6, 'bo_id' => 0,
            'vch_txn_id' => $voucherId, 'vch_no' => 'PUR/1', 'revision' => 0, 'reason' => 'entered against the wrong supplier',
            'source' => ['app' => 'purchases', 'document_type' => 'purchases.bill', 'document_id' => (string) $bill['request_id']]];
        assertSame(401, booksEvent($event, ['signature' => 't=' . time() . ',v1=' . str_repeat('a', 64)])['status'], 'a bad signature is refused');
        assertSame(401, booksEvent($event, ['key' => 'test-insights-service-key-01'])['status'], 'another product\'s key is refused');

        $first = booksEvent($event);
        assertSame(200, $first['status'], 'applied: ' . $first['body']);
        assertSame('applied', $first['json']['data']['status'] ?? null, 'outcome');
        assertSame('purchases:bill:' . $bill['request_id'] . ':cancelled_in_books', $first['json']['data']['reference'] ?? null, 'reference');
        assertSame(true, booksEvent($event)['json']['data']['duplicate'] ?? false, 'a redelivery is recognised and changes nothing');

        $after = Db::first('SELECT status, cancelled_in_books_at FROM purchase_bill_requests WHERE request_id = :id', ['id' => (int) $bill['request_id']]);
        assertSame('CANCELLED', $after['status'], 'the bill is cancelled here too');
        assertTrue($after['cancelled_in_books_at'] !== null, 'and says Books did it');
        assertSame('0.0000', (string) Db::scalar('SELECT billed_qty FROM purchase_order_lines WHERE po_id = :po ORDER BY line_id LIMIT 1', ['po' => (int) $po['po_id']]), 'the PO line is unbilled again, once');

        $again = (new BillService($ctx, $auth))->enter([
            'supplier_account_id' => 601, 'po_id' => (int) $po['po_id'], 'supplier_invoice_no' => $bill['supplier_invoice_no'], 'supplier_invoice_date' => '2026-09-19',
            'lines' => [['po_line_id' => (int) $po['lines'][0]['line_id'], 'qty' => 100, 'rate' => 250]],
        ]);
        assertTrue((int) $again['request_id'] > (int) $bill['request_id'], 'the same supplier invoice number can be entered again');

        $unknown = booksEvent(['event_id' => 'evt-a16-p-unknown', 'vch_txn_id' => 987654] + $event);
        assertSame('ignored', $unknown['json']['data']['status'] ?? null, 'a voucher no bill holds is ignored with the reason');
    });
} else {
    echo "  skip  Books voucher events over HTTP (PURCHASES_E2E_BASE not set)\n";
}
