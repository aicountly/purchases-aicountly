<?php

declare(strict_types=1);

namespace Aicountly\Api\Controllers;

use Aicountly\Api\Domain\PurchaseOrderService;
use Aicountly\Api\Domain\ReceiptLedger;
use Aicountly\Api\Domain\ReceiptReturnService;
use Aicountly\Api\Domain\ReceiptService;
use Aicountly\Api\Http;
use Aicountly\Api\Permissions;

final class PurchaseOrdersController extends Controller
{
    public static function index(): void
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'po.view');

        $params = Http::listParams(['po_date', 'po_no', 'total_amount', 'status', 'promised_date', 'created_at'], 'po_date');
        $result = (new PurchaseOrderService($ctx, $auth))->search([
            'status'              => Http::param('status'),
            'supplier_account_id' => Http::intParam('supplier_account_id'),
            'from'                => Http::param('from'),
            'to'                  => Http::param('to'),
            'open_only'           => Http::param('open_only') === '1',
            'overdue'             => Http::param('overdue') === '1',
            'q'                   => $params['q'],
        ], $params['limit'], $params['offset'], $params['sort'], $params['order']);

        Http::list($result['rows'], $result['total'], $params['limit'], $params['offset']);
    }

    public static function show(string $id): void
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'po.view');

        $po = (new PurchaseOrderService($ctx, $auth))->find((int) $id);
        if ($po === []) {
            Http::notFound('That purchase order does not exist.');
        }

        Http::data($po);
    }

    public static function create(): void
    {
        [$auth, $ctx] = self::enter();
        Http::data((new PurchaseOrderService($ctx, $auth))->create(Http::body()), 201);
    }

    public static function submit(string $id): void
    {
        [$auth, $ctx] = self::enter();
        Http::data((new PurchaseOrderService($ctx, $auth))->submit((int) $id));
    }

    public static function approve(string $id): void
    {
        [$auth, $ctx] = self::enter();
        Http::data((new PurchaseOrderService($ctx, $auth))->decide((int) $id, 'approve', Http::body()));
    }

    public static function reject(string $id): void
    {
        [$auth, $ctx] = self::enter();
        Http::data((new PurchaseOrderService($ctx, $auth))->decide((int) $id, 'reject', Http::body()));
    }

    public static function issue(string $id): void
    {
        [$auth, $ctx] = self::enter();
        Http::data((new PurchaseOrderService($ctx, $auth))->issue((int) $id));
    }

    /** The order's document as a PDF, recorded as prepared. */
    public static function document(string $id): void
    {
        [$auth, $ctx] = self::enter();
        $doc = (new PurchaseOrderService($ctx, $auth))->document((int) $id);
        if (PHP_SAPI === 'cli') {
            Http::data(['file_name' => $doc['file_name'], 'fingerprint' => $doc['fingerprint'], 'bytes' => strlen($doc['pdf']), 'pdf' => base64_encode($doc['pdf'])]);
        }
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $doc['file_name'] . '"');
        header('Cache-Control: no-store');
        header('X-Document-Fingerprint: ' . $doc['fingerprint']);
        echo $doc['pdf'];
        exit;
    }

    public static function sent(string $id): void
    {
        [$auth, $ctx] = self::enter();
        Http::data((new PurchaseOrderService($ctx, $auth))->markSent((int) $id, Http::body()));
    }

    public static function acknowledge(string $id): void
    {
        [$auth, $ctx] = self::enter();
        Http::data((new PurchaseOrderService($ctx, $auth))->acknowledge((int) $id, Http::body()));
    }

    public static function receive(string $id): void
    {
        [$auth, $ctx] = self::enter();
        Http::data((new ReceiptService($ctx, $auth))->request((int) $id, Http::body()));
    }

    public static function retryReceipt(string $id): void
    {
        [$auth, $ctx] = self::enter();
        Http::data((new ReceiptService($ctx, $auth))->retry((int) $id));
    }

    /** Ask Inventory whether it holds a receipt whose outcome is unknown, and settle ours from its answer. */
    public static function reconcileReceipt(string $id): void
    {
        [$auth, $ctx] = self::enter();
        Http::data((new ReceiptService($ctx, $auth))->reconcile((int) $id));
    }

    /** Withdraw a receipt Inventory never recorded. */
    public static function cancelReceipt(string $id): void
    {
        [$auth, $ctx] = self::enter();
        Http::data((new ReceiptService($ctx, $auth))->cancel((int) $id, Http::body()));
    }

    /** Undo a GRN recorded by mistake, that no bill has settled: Inventory reverses it. */
    public static function reverseReceipt(string $id): void
    {
        [$auth, $ctx] = self::enter();
        Http::data((new ReceiptReturnService($ctx, $auth))->reverse((int) $id, Http::body()));
    }

    /** Give back goods received and not billed — all of a GRN, or some of it. */
    public static function returnReceipt(string $id): void
    {
        [$auth, $ctx] = self::enter();
        Http::data((new ReceiptReturnService($ctx, $auth))->returnUnbilled((int) $id, Http::body()));
    }

    /** Send again a return or reversal whose answer was lost, on its own key. */
    public static function retryReceiptReturn(string $id): void
    {
        [$auth, $ctx] = self::enter();
        Http::data((new ReceiptReturnService($ctx, $auth))->retry((int) $id));
    }

    /** Abandon a return or reversal Inventory has not acted on. */
    public static function withdrawReceiptReturn(string $id): void
    {
        [$auth, $ctx] = self::enter();
        Http::data((new ReceiptReturnService($ctx, $auth))->withdraw((int) $id, Http::body()));
    }

    public static function cancel(string $id): void
    {
        [$auth, $ctx] = self::enter();
        Http::data((new PurchaseOrderService($ctx, $auth))->cancel((int) $id, Http::body()));
    }

    /** Stop waiting for what will not be delivered, with a reason. */
    public static function shortClose(string $id): void
    {
        [$auth, $ctx] = self::enter();
        Http::data((new PurchaseOrderService($ctx, $auth))->shortClose((int) $id, Http::body()));
    }

    /**
     * The order beside what Inventory actually received against it.
     *
     * Composed at read time, every time, from every GRN of the order (ReceiptLedger).
     * This endpoint is why this product needs no GRN table: the receipt detail is
     * Inventory's answer, fetched now, shown next to our own progress figures — and
     * where the two disagree, the disagreement is shown rather than resolved.
     */
    public static function receiptStatus(string $id): void
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'po.view');

        $po = (new PurchaseOrderService($ctx, $auth))->find((int) $id);
        if ($po === []) {
            Http::notFound('That purchase order does not exist.');
        }

        $ledger = (new ReceiptLedger($ctx, $auth))->forOrder((int) $id);
        $progress = [];
        foreach ($po['progress']['lines'] as $line) {
            $progress[$line['line_id']] = $line;
        }

        Http::data([
            'po_id'               => (int) $po['po_id'],
            'status'              => $po['status'],
            'receipt_status'      => $po['progress']['receipt_status'],
            'billing_status'      => $po['progress']['billing_status'],
            'inventory_reachable' => $ledger['reachable'],
            // Straight from Inventory, unmodified and unstored.
            'inventory_documents' => $ledger['documents'],
            'discrepancies'       => $ledger['discrepancies'],
            'receipts'            => array_map(static fn (array $r) => [
                'request_id'    => (int) $r['request_id'],
                'receipt_no'    => $r['receipt_no'],
                'status'        => $r['status'],
                'received_at'   => $r['received_at'],
                'applied'       => $r['applied_at'] !== null,
                'document_no'   => $r['inventory_document_no'],
                'last_error'    => $r['last_error'],
            ], $po['receipts']),
            'lines'               => array_map(static fn (array $line) => [
                'line_id'            => (int) $line['line_id'],
                'line_no'            => (int) $line['line_no'],
                'item_id'            => $line['item_id'] === null ? null : (int) $line['item_id'],
                'ordered_qty'        => (float) $line['ordered_qty'],
                'short_closed_qty'   => (float) $line['short_closed_qty'],
                'received_qty'       => (float) $line['received_qty'],
                'inventory_received' => $ledger['reachable'] ? ($ledger['received_by_line'][(int) $line['line_id']] ?? 0.0) : null,
                'rejected_qty'       => (float) $line['rejected_qty'],
                'returned_qty'       => (float) $line['returned_qty'],
                'billed_qty'         => (float) $line['billed_qty'],
                'outstanding_qty'    => $progress[(int) $line['line_id']]['to_receive_qty'] ?? 0.0,
                'to_bill_qty'        => $progress[(int) $line['line_id']]['to_bill_qty'] ?? 0.0,
            ], $po['lines']),
        ]);
    }
}
