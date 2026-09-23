<?php

declare(strict_types=1);

namespace Aicountly\Api\Controllers;

use Aicountly\Api\Clients\InventoryClient;
use Aicountly\Api\Domain\PurchaseOrderService;
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

    /**
     * Deliveries, across orders.
     *
     * A receipt has always been reachable through its order. This lists them
     * the other way round, which is what a claim needs: the buyer knows a
     * delivery was short before they know which order it came from.
     */
    public static function receipts(): void
    {
        [$auth, $ctx] = self::enter();

        $params = Http::listParams(['received_at', 'created_at', 'status'], 'created_at');
        $result = (new ReceiptService($ctx, $auth))->search([
            'supplier_account_id' => Http::intParam('supplier_account_id'),
            'po_id'               => Http::intParam('po_id'),
            'status'              => Http::param('status'),
            'q'                   => $params['q'],
        ], $params['limit'], $params['offset'], $params['sort'], $params['order']);

        Http::list($result['rows'], $result['total'], $params['limit'], $params['offset']);
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

    public static function cancel(string $id): void
    {
        [$auth, $ctx] = self::enter();
        Http::data((new PurchaseOrderService($ctx, $auth))->cancel((int) $id, Http::body()));
    }

    /**
     * The order beside what Inventory actually received against it.
     *
     * Composed at read time, every time. This endpoint is why this product needs
     * no GRN table: the receipt detail is Inventory's answer, fetched now, shown
     * next to our own progress figures rather than replacing them.
     */
    public static function receiptStatus(string $id): void
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'po.view');

        $po = (new PurchaseOrderService($ctx, $auth))->find((int) $id);
        if ($po === []) {
            Http::notFound('That purchase order does not exist.');
        }

        $response = (new InventoryClient())
            ->withSession($auth->sesKey())
            ->documentBySource($ctx, 'purchases', 'purchases.order', (int) $id);

        $documents = [];
        $reachable = $response['ok'];
        if ($reachable) {
            $body = $response['body']['data'] ?? [];
            $documents = isset($body['document_id']) ? [$body] : (array) $body;
        }

        Http::data([
            'po_id'               => (int) $po['po_id'],
            'status'              => $po['status'],
            'inventory_reachable' => $reachable,
            // Straight from Inventory, unmodified and unstored.
            'inventory_documents' => $documents,
            'lines'               => array_map(static fn (array $line) => [
                'line_id'         => (int) $line['line_id'],
                'line_no'         => (int) $line['line_no'],
                'item_id'         => $line['item_id'] === null ? null : (int) $line['item_id'],
                'ordered_qty'     => (float) $line['ordered_qty'],
                'received_qty'    => (float) $line['received_qty'],
                'rejected_qty'    => (float) $line['rejected_qty'],
                'billed_qty'      => (float) $line['billed_qty'],
                'outstanding_qty' => round((float) $line['ordered_qty'] - (float) $line['received_qty'], 4),
            ], $po['lines']),
        ]);
    }
}
