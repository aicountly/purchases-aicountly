<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain;

use Aicountly\Api\Auth;
use Aicountly\Api\Clients\InventoryClient;
use Aicountly\Api\Context;
use Aicountly\Api\Db;

/**
 * What Inventory says has been received against a purchase order — every GRN, live.
 *
 * An order has as many GRNs as it had deliveries, each filed under its own receipt's
 * identity. They are read one by one from Inventory, by the document id Inventory gave
 * back, every time this is asked; nothing here is a stored copy. Receipts posted before
 * each had its own identity are found the old way, by the order's identity, once.
 *
 * A reversed or cancelled GRN counts for nothing — and where this product still counts a
 * receipt that Inventory no longer does, that is reported as a discrepancy rather than
 * quietly believed on either side. A GRN reversed from here (ReceiptReturnService) is not
 * read at all: both sides agree it counts for nothing. One partly returned is read under the
 * replacement document Inventory made for the quantity kept.
 */
final class ReceiptLedger
{
    private const DEAD = ['CANCELLED', 'REVERSED', 'FAILED', 'DRAFT'];

    public function __construct(
        private readonly Context $ctx,
        private readonly Auth $auth,
    ) {
    }

    /**
     * @return array{
     *   reachable: bool,
     *   received_by_line: array<int, float>,
     *   documents: list<array<string, mixed>>,
     *   discrepancies: list<array<string, mixed>>
     * }
     */
    public function forOrder(int $poId): array
    {
        $receipts = Db::all(
            "SELECT request_id, receipt_no, receipt_uuid, status, applied_at, applied_lines, requested_lines,
                    inventory_document_id, inventory_document_uuid, inventory_document_no, source_document_type
               FROM purchase_receipt_requests
              WHERE po_id = :po AND cmp_id = :cmp AND status NOT IN ('CANCELLED', 'REVERSED')
              ORDER BY request_id",
            ['po' => $poId, 'cmp' => $this->ctx->cmpId],
        );

        $client = (new InventoryClient())->withService($this->auth->uuid);
        $reachable = true;
        $documents = [];
        $seen = [];
        $receivedByLine = [];
        $discrepancies = [];

        $hasLegacy = false;
        foreach ($receipts as $receipt) {
            if ($receipt['source_document_type'] === ReceiptService::LEGACY_SOURCE_TYPE) {
                $hasLegacy = true;
                continue;
            }
            if ($receipt['applied_at'] === null) {
                continue; // not recorded in Inventory yet; the command strip says so
            }
            if ($receipt['inventory_document_id'] === null) {
                continue; // rejected in full at the gate: nothing went to Inventory
            }

            $response = $client->document($this->ctx, (int) $receipt['inventory_document_id']);
            if (!$response['ok']) {
                if ((int) $response['status'] === 404) {
                    $discrepancies[] = [
                        'kind'       => 'missing_in_inventory',
                        'request_id' => (int) $receipt['request_id'],
                        'receipt_no' => $receipt['receipt_no'],
                        'detail'     => sprintf('GRN %s is counted on this order, but Inventory has no document %s.', $receipt['receipt_no'], $receipt['inventory_document_no'] ?? $receipt['inventory_document_id']),
                    ];
                    continue;
                }
                $reachable = false;
                continue;
            }
            $document = $response['body']['data'] ?? [];
            if (!is_array($document) || !isset($document['document_id'])) {
                $reachable = false;
                continue;
            }
            $seen[(int) $document['document_id']] = true;
            $documents[] = $this->summarise($document, $receipt);

            $status = strtoupper((string) ($document['status'] ?? ''));
            if (in_array($status, self::DEAD, true)) {
                $discrepancies[] = [
                    'kind'       => 'reversed_in_inventory',
                    'request_id' => (int) $receipt['request_id'],
                    'receipt_no' => $receipt['receipt_no'],
                    'detail'     => sprintf('Inventory shows GRN %s as %s, but this order still counts it as received.', $document['document_no'] ?? $receipt['receipt_no'], strtolower($status)),
                ];
                continue;
            }

            foreach ($this->linesByRef($document) as $lineId => $qty) {
                $receivedByLine[$lineId] = ($receivedByLine[$lineId] ?? 0.0) + $qty;
            }
            $applied = [];
            foreach (Db::jsonColumn($receipt['applied_lines'] ?? $receipt['requested_lines']) as $line) {
                $applied[(int) $line['line_id']] = ($applied[(int) $line['line_id']] ?? 0.0) + (float) ($line['qty'] ?? 0);
            }
            foreach ($this->linesByRef($document) as $lineId => $qty) {
                if (abs(($applied[$lineId] ?? 0.0) - $qty) > 0.00005) {
                    $discrepancies[] = [
                        'kind'       => 'quantity_differs',
                        'request_id' => (int) $receipt['request_id'],
                        'receipt_no' => $receipt['receipt_no'],
                        'line_id'    => $lineId,
                        'detail'     => sprintf('GRN %s: Inventory holds %s on line %d, this order counted %s.', $receipt['receipt_no'], self::num($qty), $lineId, self::num($applied[$lineId] ?? 0.0)),
                    ];
                }
            }
        }

        if ($hasLegacy) {
            // Before receipts had their own identity, every GRN of an order was filed
            // under the order — and Inventory kept one document per source, so there is
            // at most one to find this way.
            $response = $client->documentBySource($this->ctx, 'purchases', ReceiptService::LEGACY_SOURCE_TYPE, $poId);
            if ($response['ok']) {
                $body = $response['body']['data'] ?? [];
                $list = isset($body['document_id']) ? [$body] : (is_array($body) ? $body : []);
                foreach ($list as $document) {
                    if (!is_array($document) || !isset($document['document_id']) || isset($seen[(int) $document['document_id']])) {
                        continue;
                    }
                    $seen[(int) $document['document_id']] = true;
                    $documents[] = $this->summarise($document, null);
                    if (in_array(strtoupper((string) ($document['status'] ?? '')), self::DEAD, true)) {
                        continue;
                    }
                    foreach ($this->linesByRef($document) as $lineId => $qty) {
                        $receivedByLine[$lineId] = ($receivedByLine[$lineId] ?? 0.0) + $qty;
                    }
                }
            } elseif ((int) $response['status'] !== 404) {
                $reachable = false;
            }

            $legacyCount = count(array_filter($receipts, static fn (array $r) => $r['source_document_type'] === ReceiptService::LEGACY_SOURCE_TYPE && $r['applied_at'] !== null));
            if ($legacyCount > 1) {
                $discrepancies[] = [
                    'kind'   => 'legacy_identity',
                    'detail' => sprintf('%d receipts on this order were recorded under the order\'s own identity; Inventory keeps one document per source, so only the first of them can be in stock. See the receipt repair report.', $legacyCount),
                ];
            }
        }

        return [
            'reachable'        => $reachable,
            'received_by_line' => $receivedByLine,
            'documents'        => $documents,
            'discrepancies'    => $discrepancies,
        ];
    }

    /** @return array<int, float> received quantity per purchase-order line id */
    private function linesByRef(array $document): array
    {
        $out = [];
        foreach ((array) ($document['lines'] ?? []) as $line) {
            $ref = (int) ($line['source_line_ref'] ?? 0);
            if ($ref <= 0) {
                continue;
            }
            if (isset($line['direction']) && $line['direction'] !== 'in') {
                continue;
            }
            $out[$ref] = ($out[$ref] ?? 0.0) + (float) ($line['qty'] ?? 0);
        }

        return $out;
    }

    /**
     * @param array<string, mixed>      $document
     * @param array<string, mixed>|null $receipt
     */
    private function summarise(array $document, ?array $receipt): array
    {
        return [
            'document_id'   => (int) $document['document_id'],
            'document_uuid' => $document['document_uuid'] ?? null,
            'document_no'   => $document['document_no'] ?? null,
            'document_type' => $document['document_type'] ?? null,
            'stock_effect'  => $document['stock_effect'] ?? null,
            'status'        => $document['status'] ?? null,
            'document_date' => $document['document_date'] ?? null,
            'request_id'    => $receipt === null ? null : (int) $receipt['request_id'],
            'receipt_no'    => $receipt['receipt_no'] ?? null,
            'legacy'        => $receipt === null,
            'lines'         => array_values(array_map(static fn (array $l) => [
                'source_line_ref' => isset($l['source_line_ref']) ? (int) $l['source_line_ref'] : null,
                'item_id'         => isset($l['item_id']) ? (int) $l['item_id'] : null,
                'qty'             => (float) ($l['qty'] ?? 0),
                'warehouse_id'    => $l['warehouse_id'] ?? null,
                'batch_id'        => $l['batch_id'] ?? null,
                'valuation_rate'  => $l['valuation_rate'] ?? null,
            ], array_filter((array) ($document['lines'] ?? []), 'is_array'))),
        ];
    }

    private static function num(float $value): string
    {
        return rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');
    }
}
