<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain;

use Aicountly\Api\Audit;
use Aicountly\Api\Auth;
use Aicountly\Api\Clients\InventoryClient;
use Aicountly\Api\Context;
use Aicountly\Api\Db;
use Aicountly\Api\Http;
use Aicountly\Api\IntegrationCommand;
use Aicountly\Api\Permissions;

/**
 * Goods receipt — ORCHESTRATION ONLY.
 *
 * THE GRN IS INVENTORY'S. Purchases says "these goods arrived against this
 * order"; Inventory records the receipt, the batch, the serial, the quality
 * status, the stock movement and the valuation, and hands back a uuid. That
 * uuid is all that is stored here.
 *
 * `received_qty` on our PO line is progress against OUR commitment, written
 * from Inventory's own response. When a screen needs to show what was actually
 * received it reads the GRN from Inventory by uuid — this product does not keep
 * a second set of received quantities to answer with, and that is exactly why
 * there is nothing to reconcile.
 */
final class ReceiptService
{
    public const COMMAND_RECEIPT = 'purchases.receipt.request';

    public function __construct(
        private readonly Context $ctx,
        private readonly Auth $auth,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     *   {lines: [{line_id, qty, rejected_qty?, warehouse_id?, batch_id?, serials?}],
     *    received_at?, supplier_dc_no?, vehicle_no?, landed_costs?}
     */
    public function request(int $poId, array $input): array
    {
        Permissions::assert($this->ctx, $this->auth, 'receipt.request');

        $orders = new PurchaseOrderService($this->ctx, $this->auth);
        $po = $orders->find($poId);
        if ($po === []) {
            Http::notFound('That purchase order does not exist.');
        }
        if (in_array($po['status'], ['DRAFT', 'APPROVAL_PENDING', 'CANCELLED'], true)) {
            Http::conflict('Issue the purchase order before receiving goods against it.');
        }

        $received = $this->resolveReceiptLines($po, $input['lines'] ?? null);
        if ($received === []) {
            Http::validationFailed('There is nothing outstanding to receive on this order.');
        }

        $requestId = (int) Db::insert('purchase_receipt_requests', [
            'cmp_id'          => $this->ctx->cmpId,
            'fy_id'           => $this->ctx->fyId,
            'bo_id'           => $this->ctx->boId,
            'po_id'           => $poId,
            'status'          => 'REQUESTED',
            'received_at'     => self::date($input['received_at'] ?? null),
            'warehouse_id'    => self::id($input['warehouse_id'] ?? $po['delivery_warehouse_id'] ?? null),
            'supplier_dc_no'  => self::text($input['supplier_dc_no'] ?? null),
            'supplier_dc_date' => self::text($input['supplier_dc_date'] ?? null),
            'vehicle_no'      => self::text($input['vehicle_no'] ?? null),
            'requested_lines' => $received,
            'requested_by'    => $this->auth->uuid,
        ], 'request_id');

        return $this->dispatch($requestId);
    }

    /**
     * Send a stored receipt request to Inventory.
     *
     * Split out from request() so a RETRY drives the SAME request row, and
     * therefore reaches IntegrationCommand with the same (entity_type, entity_id)
     * and the same idempotency key. Creating a fresh row on retry would mint a
     * fresh key, and a receipt that Inventory had already recorded but whose
     * response was lost would be recorded a second time — the exact duplicate
     * this whole mechanism exists to prevent.
     */
    private function dispatch(int $requestId): array
    {
        $request = Db::first(
            'SELECT * FROM purchase_receipt_requests WHERE request_id = :id AND cmp_id = :cmp',
            ['id' => $requestId, 'cmp' => $this->ctx->cmpId],
        );
        if ($request === null) {
            Http::notFound('That receipt request does not exist.');
        }

        $poId = (int) $request['po_id'];
        $orders = new PurchaseOrderService($this->ctx, $this->auth);
        $po = $orders->find($poId);
        if ($po === []) {
            Http::notFound('That purchase order does not exist.');
        }

        $received = Db::jsonColumn($request['requested_lines']);
        $input = [
            'received_at'    => $request['received_at'],
            'supplier_dc_no' => $request['supplier_dc_no'],
        ];

        $command = IntegrationCommand::open(
            $this->ctx,
            'inventory',
            self::COMMAND_RECEIPT,
            'receipt_request',
            $requestId,
            ['po_id' => $poId, 'lines' => count($received)],
        );
        $commandId = (int) $command['command_id'];
        IntegrationCommand::markPosting($commandId);

        $payload = [
            'document_type'        => 'PURCHASE_RECEIPT',
            'document_date'        => self::date($input['received_at'] ?? null),
            'source_app'           => 'purchases',
            'source_document_type' => 'purchases.order',
            'source_document_id'   => $poId,
            'source_document_uuid' => (string) $po['po_uuid'],
            'source_document_no'   => (string) $po['po_no'],
            'source_document_date' => (string) $po['po_date'],
            'party_ref'            => (string) $po['supplier_account_id'],
            'party_name'           => $po['supplier_name_snapshot'],
            'narration'            => 'Receipt against ' . $po['po_no']
                . (self::text($input['supplier_dc_no'] ?? null) !== null ? ' (DC ' . $input['supplier_dc_no'] . ')' : ''),
            'currency_code'        => (string) $po['currency_code'],
            'exchange_rate'        => (float) $po['exchange_rate'],
            'lines'                => array_map(static fn (array $line) => array_filter([
                'source_line_ref' => (string) $line['line_id'],
                'item_id'         => (int) $line['item_id'],
                'warehouse_id'    => $line['warehouse_id'],
                'batch_id'        => $line['batch_id'],
                'serials'         => $line['serials'],
                'unit_id'         => $line['unit_id'],
                'qty'             => (float) $line['qty'],
                // The rate we AGREED. Inventory stores it as the source
                // transaction value and computes its own valuation from it;
                // the two are different numbers with different owners.
                'rate'            => (float) $line['rate'],
                'amount'          => round((float) $line['qty'] * (float) $line['rate'], 4),
                'direction'       => 'in',
                'hsn_sac'         => $line['hsn_sac'],
                // Landed cost is capitalised by Inventory according to the
                // company's own policy — we pass the charge, we do not decide
                // whether it becomes part of the cost of the goods.
                'landed_cost_amount'    => $line['landed_cost_amount'],
                'landed_cost_breakdown' => $line['landed_cost_breakdown'],
            ], static fn ($value) => $value !== null && $value !== []), $received),
        ];

        $response = (new InventoryClient())
            ->withService($this->auth->uuid)
            ->postDocument($this->ctx, $payload, (string) $command['idempotency_key']);

        if (!$response['ok']) {
            $message = $response['error'] ?? 'Inventory did not accept the receipt.';
            $businessRefusal = in_array($response['status'], [409, 422], true);
            $businessRefusal ? IntegrationCommand::block($commandId, $message) : IntegrationCommand::fail($commandId, $message);

            Db::update('purchase_receipt_requests', [
                'status' => 'FAILED', 'last_error' => mb_substr($message, 0, 480), 'updated_at' => self::now(),
            ], ['request_id' => $requestId]);

            Http::error(
                $businessRefusal ? 409 : 502,
                $businessRefusal ? 'inventory_refused' : 'inventory_unavailable',
                $businessRefusal
                    ? $message
                    : 'Could not reach Inventory to record this receipt. Nothing has been received — press Retry.',
                ['request_id' => $requestId, 'retryable' => !$businessRefusal, 'detail' => $message],
            );
        }

        $document = $response['body']['data'] ?? [];
        IntegrationCommand::complete($commandId, [
            'inventory_document_id'   => $document['document_id'] ?? null,
            'inventory_document_uuid' => $document['document_uuid'] ?? null,
            'inventory_document_no'   => $document['document_no'] ?? null,
        ]);

        Db::update('purchase_receipt_requests', [
            'status'                  => 'ACCEPTED',
            'inventory_document_id'   => self::id($document['document_id'] ?? null),
            'inventory_document_uuid' => self::text($document['document_uuid'] ?? null),
            'inventory_document_no'   => self::text($document['document_no'] ?? null),
            'updated_at'              => self::now(),
        ], ['request_id' => $requestId]);

        $this->applyReceivedQuantities($poId, $document, $received);

        Audit::record($this->ctx, $this->auth, 'po.received', 'purchase_order', $poId, null, [
            'inventory_document_uuid' => $document['document_uuid'] ?? null,
            'lines'                   => count($received),
        ]);

        return $orders->find($poId);
    }

    /** Retry a receipt that failed, on its original idempotency key. */
    public function retry(int $requestId): array
    {
        Permissions::assert($this->ctx, $this->auth, 'receipt.request');

        $request = Db::first(
            'SELECT * FROM purchase_receipt_requests WHERE request_id = :id AND cmp_id = :cmp',
            ['id' => $requestId, 'cmp' => $this->ctx->cmpId],
        );
        if ($request === null) {
            Http::notFound('That receipt request does not exist.');
        }
        if ($request['status'] === 'ACCEPTED') {
            Http::conflict('That receipt has already been recorded in Inventory.');
        }

        // The SAME row, so the same idempotency key goes back to Inventory.
        return $this->dispatch($requestId);
    }

    /**
     * @param array<string, mixed> $po
     * @return list<array<string, mixed>>
     */
    private function resolveReceiptLines(array $po, mixed $requestedLines): array
    {
        $byId = [];
        foreach ($po['lines'] as $line) {
            $byId[(int) $line['line_id']] = $line;
        }

        $out = [];

        if (is_array($requestedLines) && $requestedLines !== []) {
            foreach ($requestedLines as $requested) {
                if (!is_array($requested)) {
                    continue;
                }
                $lineId = (int) ($requested['line_id'] ?? 0);
                $line = $byId[$lineId] ?? null;
                if ($line === null || (bool) $line['is_service'] || $line['item_id'] === null) {
                    continue;
                }
                $outstanding = round((float) $line['ordered_qty'] - (float) $line['received_qty'], 4);
                $qty = round((float) ($requested['qty'] ?? $outstanding), 4);
                if ($qty <= 0) {
                    continue;
                }
                // Over-receipt is refused here rather than silently absorbed: a
                // supplier sending more than was ordered is a commercial
                // decision, not a data-entry detail.
                if ($qty > $outstanding) {
                    Http::validationFailed(
                        sprintf('Line %d has only %s outstanding. Amend the purchase order to receive more.', (int) $line['line_no'], self::num($outstanding)),
                        ['field' => 'lines', 'line_id' => $lineId, 'outstanding' => $outstanding],
                    );
                }
                $out[] = $this->receiptLine($line, $qty, $requested);
            }

            return $out;
        }

        foreach ($po['lines'] as $line) {
            if ((bool) $line['is_service'] || $line['item_id'] === null) {
                continue;
            }
            $outstanding = round((float) $line['ordered_qty'] - (float) $line['received_qty'], 4);
            if ($outstanding > 0) {
                $out[] = $this->receiptLine($line, $outstanding, []);
            }
        }

        return $out;
    }

    /** @param array<string, mixed> $line */
    private function receiptLine(array $line, float $qty, array $overrides): array
    {
        $breakdown = $overrides['landed_cost_breakdown'] ?? null;

        return [
            'line_id'      => (int) $line['line_id'],
            'item_id'      => (int) $line['item_id'],
            'unit_id'      => self::id($overrides['unit_id'] ?? $line['unit_id']),
            'warehouse_id' => self::id($overrides['warehouse_id'] ?? $line['warehouse_id']),
            'batch_id'     => self::id($overrides['batch_id'] ?? null),
            'serials'      => is_array($overrides['serials'] ?? null) ? array_values($overrides['serials']) : [],
            'qty'          => $qty,
            'rejected_qty' => round((float) ($overrides['rejected_qty'] ?? 0), 4),
            'rate'         => (float) $line['agreed_rate'],
            'hsn_sac'      => $line['hsn_sac'],
            'landed_cost_amount'    => isset($overrides['landed_cost_amount']) ? round((float) $overrides['landed_cost_amount'], 4) : null,
            'landed_cost_breakdown' => is_array($breakdown) ? $breakdown : null,
        ];
    }

    /**
     * @param array<string, mixed>       $document Inventory's response
     * @param list<array<string, mixed>> $requested
     */
    private function applyReceivedQuantities(int $poId, array $document, array $requested): void
    {
        // Prefer what Inventory says it received; fall back to what we asked for
        // only when the response carries no line detail.
        $moved = [];
        foreach ((array) ($document['lines'] ?? []) as $line) {
            $ref = (int) ($line['source_line_ref'] ?? 0);
            if ($ref > 0) {
                $moved[$ref] = (float) ($line['qty'] ?? 0);
            }
        }
        $rejected = [];
        foreach ($requested as $line) {
            if ($moved === []) {
                $moved[(int) $line['line_id']] = (float) $line['qty'];
            }
            if ((float) $line['rejected_qty'] > 0) {
                $rejected[(int) $line['line_id']] = (float) $line['rejected_qty'];
            }
        }

        Db::transaction(function () use ($moved, $rejected, $poId) {
            foreach ($moved as $lineId => $qty) {
                Db::run(
                    'UPDATE purchase_order_lines
                     SET received_qty = received_qty + :qty, rejected_qty = rejected_qty + :rejected, updated_at = :now
                     WHERE line_id = :id AND cmp_id = :cmp',
                    [
                        'qty' => $qty,
                        'rejected' => $rejected[$lineId] ?? 0,
                        'now' => self::now(),
                        'id' => $lineId,
                        'cmp' => $this->ctx->cmpId,
                    ],
                );
            }

            $outstanding = (float) Db::scalar(
                'SELECT COALESCE(SUM(GREATEST(ordered_qty - received_qty, 0)), 0)
                 FROM purchase_order_lines WHERE po_id = :id AND is_service = FALSE',
                ['id' => $poId],
            );

            Db::update('purchase_orders', [
                'status'     => $outstanding > 0 ? 'PARTIALLY_RECEIVED' : 'RECEIVED',
                'updated_at' => self::now(),
            ], ['po_id' => $poId, 'cmp_id' => $this->ctx->cmpId]);
        });
    }

    private static function num(float $value): string
    {
        return rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');
    }

    private static function id(mixed $value): ?int
    {
        return ($value === null || $value === '' || (int) $value === 0) ? null : (int) $value;
    }

    /**
     * The deliveries recorded against this company's orders.
     *
     * Added for the claim screen, which has to let a buyer say WHICH delivery
     * was short. Until now a receipt could only be reached through the order it
     * belongs to, which is fine when you are looking at the order and useless
     * when you are looking at a shortage.
     *
     * The supplier lives on the order, not on the receipt, so filtering by
     * supplier goes through the join — and the GRN number comes back from the
     * uuid Inventory gave us, because Inventory owns the receipt itself.
     *
     * @param array<string, mixed> $filters
     * @return array{rows:list<array<string, mixed>>, total:int}
     */
    public function search(array $filters, int $limit, int $offset, string $sort, string $order): array
    {
        Permissions::assert($this->ctx, $this->auth, 'po.view');

        $where = ['r.cmp_id = :ctx_cmp_id'];
        $params = ['ctx_cmp_id' => $this->ctx->cmpId];

        if (!empty($filters['supplier_account_id'])) {
            $where[] = 'o.supplier_account_id = :supplier';
            $params['supplier'] = (int) $filters['supplier_account_id'];
        }
        if (!empty($filters['po_id'])) {
            $where[] = 'r.po_id = :po';
            $params['po'] = (int) $filters['po_id'];
        }
        if (!empty($filters['status'])) {
            $where[] = 'r.status = :status';
            $params['status'] = (string) $filters['status'];
        }
        if (!empty($filters['q'])) {
            $where[] = '(r.inventory_document_no ILIKE :q OR r.supplier_dc_no ILIKE :q OR o.po_no ILIKE :q)';
            $params['q'] = '%' . str_replace(['%', '_'], ['\\%', '\\_'], (string) $filters['q']) . '%';
        }

        $clause = implode(' AND ', $where);
        $sortColumn = in_array($sort, ['received_at', 'created_at', 'status'], true) ? $sort : 'created_at';

        $rows = Db::all(
            "SELECT r.request_id, r.po_id, r.status, r.received_at, r.supplier_dc_no, r.supplier_dc_date,
                    r.vehicle_no, r.warehouse_id, r.inventory_document_uuid, r.inventory_document_no,
                    r.created_at, o.po_no, o.supplier_account_id, o.supplier_name_snapshot
               FROM purchase_receipt_requests r
               JOIN purchase_orders o ON o.po_id = r.po_id
              WHERE {$clause}
              ORDER BY r.{$sortColumn} {$order} NULLS LAST, r.request_id {$order}
              LIMIT {$limit} OFFSET {$offset}",
            $params,
        );

        return [
            'rows'  => $rows,
            'total' => (int) Db::scalar(
                "SELECT COUNT(*) FROM purchase_receipt_requests r JOIN purchase_orders o ON o.po_id = r.po_id WHERE {$clause}",
                $params,
            ),
        ];
    }

    private static function text(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private static function date(mixed $value): string
    {
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($value)) === 1) {
            return trim($value);
        }

        return gmdate('Y-m-d');
    }

    private static function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }
}
