<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain;

use Aicountly\Api\Auth;
use Aicountly\Api\Clients\InventoryClient;
use Aicountly\Api\Context;
use Aicountly\Api\Db;
use Aicountly\Api\Env;
use Aicountly\Api\Http;
use Aicountly\Api\IntegrationCommand;

/**
 * The serial numbers and batch a goods receipt names, as Inventory has to be told them.
 *
 * Inventory (3f66a41, C6) takes a document line's serials as serial IDS and its batch as a
 * batch_id. The receipt used to send the numbers typed at the gate as text: Inventory cast them
 * to int, so "SN-7" vanished and "1001" became some other unit's id; a batch number rode in the
 * line's metadata and was never a batch at all. It now refuses text serials (422), and for a
 * serial-tracked item an inward line must name one serial per BASE unit, none already in stock.
 *
 * So, for a receipt that names any:
 *
 *   check()     before the receipt is recorded — each line naming serials is for an item that
 *               tracks them, with one per base unit (the line's unit converted by the item's own
 *               factor), each named once. Refused here, with the line, rather than refused by
 *               Inventory after the receipt is on its way.
 *   register()  before the receipt is first sent — batches are created (or the item's existing
 *               batch of that number found), serial numbers registered with POST v1/serials/bulk
 *               (a number the item already has is looked up, and refused if it is in stock: a
 *               unit cannot arrive twice), and the ids are kept on the receipt's lines, so the
 *               body stored on its command — and every retry of it — names ids.
 *
 * As the person recording the receipt: Inventory's policy grants this product's key documents,
 * not master data. Registration repeated (a Retry) finds what the first attempt registered.
 */
final class ReceiptTracking
{
    private const EPSILON = 0.0001;

    public function __construct(
        private readonly Context $ctx,
        private readonly Auth $auth,
    ) {
    }

    /**
     * Refuse serial numbers Inventory would refuse, before anything is recorded. Asks Inventory
     * about the items only when a line names serials.
     *
     * @param array<string, mixed> $input the receive request
     */
    public function check(int $poId, array $input): void
    {
        $named = [];
        foreach (is_array($input['lines'] ?? null) ? $input['lines'] : [] as $index => $want) {
            if (!is_array($want)) {
                continue;
            }
            $serials = self::serialNumbers($want['serials'] ?? null);
            if ($serials !== []) {
                $named[$index] = ['want' => $want, 'serials' => $serials];
            }
        }
        if ($named === []) {
            return;
        }

        $poLines = [];
        foreach (Db::all('SELECT line_id, line_no, item_id, unit_id FROM purchase_order_lines WHERE po_id = :po AND cmp_id = :cmp', ['po' => $poId, 'cmp' => $this->ctx->cmpId]) as $row) {
            $poLines[(int) $row['line_id']] = $row;
        }

        $itemIds = [];
        foreach ($named as $index => $entry) {
            $line = $poLines[(int) ($entry['want']['line_id'] ?? 0)] ?? null;
            if ($line === null || $line['item_id'] === null) {
                Http::validationFailed('Serial numbers can only be given on a goods line of this order.', ['field' => 'lines', 'index' => $index]);
            }
            if (!isset($entry['want']['qty'])) {
                Http::validationFailed(sprintf('Line %d: give the quantity received along with its serial numbers.', (int) $line['line_no']), ['field' => 'lines', 'line_id' => (int) $line['line_id']]);
            }
            $itemIds[] = (int) $line['item_id'];
        }

        $lookup = (new InventoryClient())->withSession($this->auth->sesKey())->bulkLookupItems($this->ctx, $itemIds);
        if (!$lookup['ok']) {
            Http::error(502, 'inventory_unavailable', 'Could not ask Inventory whether these items track serial numbers. Nothing was recorded — try again.', ['retryable' => true, 'detail' => $lookup['error']]);
        }
        $items = [];
        foreach ((array) ($lookup['body']['data'] ?? []) as $item) {
            if (is_array($item) && isset($item['item_id'])) {
                $items[(int) $item['item_id']] = $item;
            }
        }

        $seen = [];
        foreach ($named as $entry) {
            $line = $poLines[(int) $entry['want']['line_id']];
            $itemId = (int) $line['item_id'];
            $lineNo = (int) $line['line_no'];
            $item = $items[$itemId] ?? null;
            $name = (string) ($item['item_name'] ?? ('item #' . $itemId));
            if ($item === null || !self::truthy($item['track_serial'] ?? null)) {
                Http::validationFailed(
                    sprintf('Line %d: %s does not track serial numbers in Inventory, so the GRN cannot carry them. Leave them out, or turn on serial tracking for the item in Inventory first.', $lineNo, $name),
                    ['field' => 'lines', 'line_id' => (int) $line['line_id'], 'item_id' => $itemId],
                );
            }
            $qty = round((float) $entry['want']['qty'], 4);
            $base = round($qty * self::factor($item, self::id($entry['want']['unit_id'] ?? $line['unit_id'])), 4);
            if (abs($base - count($entry['serials'])) > self::EPSILON) {
                Http::validationFailed(
                    sprintf('Line %d: %s is serial-tracked — %s received is %s unit(s), so name %s serial number(s); %d given.', $lineNo, $name, self::num($qty), self::num($base), self::num($base), count($entry['serials'])),
                    ['field' => 'lines', 'line_id' => (int) $line['line_id'], 'base_qty' => $base, 'serials' => count($entry['serials'])],
                );
            }
            foreach ($entry['serials'] as $serial) {
                $key = $itemId . '|' . $serial;
                if (isset($seen[$key])) {
                    Http::validationFailed(sprintf('Line %d: serial number %s is named twice on this receipt.', $lineNo, $serial), ['field' => 'lines', 'line_id' => (int) $line['line_id'], 'serial_no' => $serial]);
                }
                $seen[$key] = true;
            }
        }
    }

    /**
     * Register what the receipt names and keep the ids on its lines — only while nothing has
     * been sent for it (no command yet), so a stored body is never rewritten.
     *
     * @param array<string, mixed> $receipt
     * @return array{receipt: array<string, mixed>, problem: ?array{status: int, code: string, message: string, retryable: bool}}
     */
    public function register(array $receipt, Context $scope, string $commandType): array
    {
        $lines = Db::jsonColumn($receipt['requested_lines']);
        $pending = false;
        foreach ($lines as $line) {
            if ((float) ($line['qty'] ?? 0) > 0
                && ((self::text($line['batch_no'] ?? null) !== null && self::id($line['batch_id'] ?? null) === null)
                    || (self::serialNumbers($line['serials'] ?? null) !== [] && !is_array($line['serial_ids'] ?? null)))) {
                $pending = true;
            }
        }
        if (!$pending) {
            return ['receipt' => $receipt, 'problem' => null];
        }

        $inventory = (new InventoryClient())->withSession($this->auth->sesKey());
        // Registering the serials and batches a receipt names is Purchase's own act: Inventory
        // 2880977 grants this product's key masters.serials.register / masters.batches.register
        // (create only). An Inventory not yet updated refuses the key (403), and the person
        // recording the receipt registers them as before, so neither side waits on the other.
        $registrar = Env::get('INVENTORY_SERVICE_KEY') !== ''
            ? (new InventoryClient())->withService($this->auth->uuid)
            : null;
        $batches = [];
        foreach ($lines as $i => $line) {
            if ((float) ($line['qty'] ?? 0) <= 0) {
                continue;
            }
            $itemId = (int) ($line['item_id'] ?? 0);
            $lineNo = (int) ($line['line_no'] ?? 0);

            $batchNo = self::text($line['batch_no'] ?? null);
            if ($batchNo !== null && self::id($line['batch_id'] ?? null) === null) {
                $key = $itemId . '|' . $batchNo;
                if (!isset($batches[$key])) {
                    $found = $this->batchId($inventory, $registrar, $scope, $itemId, $batchNo, $lineNo);
                    if (is_array($found)) {
                        return ['receipt' => $receipt, 'problem' => $found];
                    }
                    $batches[$key] = $found;
                }
                $lines[$i]['batch_id'] = $batches[$key];
            }

            $serials = self::serialNumbers($line['serials'] ?? null);
            if ($serials !== [] && !is_array($line['serial_ids'] ?? null)) {
                $ids = $this->serialIds($inventory, $registrar, $scope, $itemId, self::id($line['warehouse_id'] ?? null), self::id($lines[$i]['batch_id'] ?? null), $serials, $lineNo);
                if (isset($ids['problem'])) {
                    return ['receipt' => $receipt, 'problem' => $ids['problem']];
                }
                $lines[$i]['serial_ids'] = $ids['ids'];
            }
        }

        $requestId = (int) $receipt['request_id'];
        Db::transaction(function () use ($requestId, $lines, $commandType): void {
            $row = Db::first('SELECT applied_at, status FROM purchase_receipt_requests WHERE request_id = :id AND cmp_id = :cmp FOR UPDATE', ['id' => $requestId, 'cmp' => $this->ctx->cmpId]);
            if ($row === null || $row['applied_at'] !== null || $row['status'] === 'CANCELLED'
                || IntegrationCommand::find($this->ctx->cmpId, $commandType, 'receipt_request', $requestId) !== null) {
                return; // sent meanwhile: its stored body stands
            }
            Db::update('purchase_receipt_requests', ['requested_lines' => $lines, 'updated_at' => gmdate('Y-m-d H:i:s')], ['request_id' => $requestId, 'cmp_id' => $this->ctx->cmpId]);
        });

        return [
            'receipt' => Db::first('SELECT * FROM purchase_receipt_requests WHERE request_id = :id AND cmp_id = :cmp', ['id' => $requestId, 'cmp' => $this->ctx->cmpId]) ?? $receipt,
            'problem' => null,
        ];
    }

    /**
     * Register with Purchase's key when it has one, else — or when Inventory refuses the key
     * (403: an Inventory before 2880977) — as the person.
     *
     * @param callable(InventoryClient): array $call
     */
    private static function registerInInventory(?InventoryClient $registrar, InventoryClient $person, callable $call): array
    {
        if ($registrar !== null) {
            $answer = $call($registrar);
            if ((int) ($answer['status'] ?? 0) !== 403) {
                return $answer;
            }
        }

        return $call($person);
    }

    /** @return int|array{status: int, code: string, message: string, retryable: bool} the batch id, or why not */
    private function batchId(InventoryClient $inventory, ?InventoryClient $registrar, Context $scope, int $itemId, string $batchNo, int $lineNo): int|array
    {
        $created = self::registerInInventory($registrar, $inventory, static fn (InventoryClient $c): array => $c->createBatch($scope, $itemId, $batchNo));
        if ($created['ok'] && self::id($created['body']['data']['batch_id'] ?? null) !== null) {
            return (int) $created['body']['data']['batch_id'];
        }
        if (!$created['ok'] && (int) $created['status'] !== 409) {
            return self::problem($created, sprintf('Line %d: Inventory did not register batch %s', $lineNo, $batchNo));
        }
        // The item already has a batch of that number: that batch.
        $found = $inventory->findBatches($scope, $itemId, $batchNo);
        if ($found['ok']) {
            foreach ((array) ($found['body']['data'] ?? []) as $batch) {
                if (is_array($batch) && (int) ($batch['item_id'] ?? $itemId) === $itemId && (string) ($batch['batch_no'] ?? '') === $batchNo && self::id($batch['batch_id'] ?? null) !== null) {
                    return (int) $batch['batch_id'];
                }
            }
        }

        return self::problem($found['ok'] ? ['ok' => false, 'status' => 409, 'body' => null, 'error' => 'it says the batch exists but does not list it'] : $found, sprintf('Line %d: could not find batch %s in Inventory', $lineNo, $batchNo));
    }

    /**
     * @param list<string> $serials
     * @return array{ids: list<int>}|array{problem: array{status: int, code: string, message: string, retryable: bool}}
     */
    private function serialIds(InventoryClient $inventory, ?InventoryClient $registrar, Context $scope, int $itemId, ?int $warehouseId, ?int $batchId, array $serials, int $lineNo): array
    {
        $registered = self::registerInInventory($registrar, $inventory, static fn (InventoryClient $c): array => $c->registerSerials($scope, $itemId, $warehouseId, $batchId, $serials));
        if (!$registered['ok']) {
            return ['problem' => self::problem($registered, sprintf('Line %d: Inventory did not register the serial numbers', $lineNo))];
        }
        $ids = [];
        foreach ((array) ($registered['body']['data']['created'] ?? []) as $row) {
            if (is_array($row) && isset($row['serial_no'], $row['serial_id'])) {
                $ids[(string) $row['serial_no']] = (int) $row['serial_id'];
            }
        }
        foreach ((array) ($registered['body']['data']['skipped'] ?? []) as $row) {
            $no = (string) ($row['serial_no'] ?? '');
            $reason = (string) ($row['reason'] ?? '');
            if ($reason !== 'already_registered') {
                return ['problem' => ['status' => 422, 'code' => 'serial_refused', 'retryable' => false,
                    'message' => sprintf('Line %d: Inventory would not register serial number %s (%s). Withdraw this receipt and record the delivery again with the right numbers.', $lineNo, $no, str_replace('_', ' ', $reason))]];
            }
            // Already on the item's register — a Retry finding its own registration, or a unit
            // that left and is coming back. Never one in stock: a unit cannot arrive twice.
            $found = $inventory->findSerials($scope, $itemId, $no);
            $match = null;
            foreach ($found['ok'] ? (array) ($found['body']['data'] ?? []) : [] as $serial) {
                if (is_array($serial) && (string) ($serial['serial_no'] ?? '') === $no && (int) ($serial['item_id'] ?? $itemId) === $itemId) {
                    $match = $serial;
                }
            }
            if ($match === null) {
                return ['problem' => self::problem($found['ok'] ? ['ok' => false, 'status' => 409, 'body' => null, 'error' => 'it says the number is registered but does not list it'] : $found, sprintf('Line %d: could not find serial number %s in Inventory', $lineNo, $no))];
            }
            if (in_array((string) ($match['status'] ?? ''), ['in_stock', 'reserved'], true)) {
                return ['problem' => ['status' => 422, 'code' => 'serial_in_stock', 'retryable' => false,
                    'message' => sprintf('Line %d: serial number %s is already in stock in Inventory, so it cannot be received again. Withdraw this receipt and record the delivery again with the right numbers.', $lineNo, $no)]];
            }
            $ids[$no] = (int) $match['serial_id'];
        }

        $out = [];
        foreach ($serials as $no) {
            if (!isset($ids[$no])) {
                return ['problem' => ['status' => 502, 'code' => 'inventory_uncertain', 'retryable' => true,
                    'message' => sprintf('Line %d: Inventory did not say what became of serial number %s. Nothing was received — press Retry.', $lineNo, $no)]];
            }
            $out[] = $ids[$no];
        }

        return ['ids' => $out];
    }

    /**
     * @param array{ok: bool, status: int, body: ?array, error: ?string} $response
     * @return array{status: int, code: string, message: string, retryable: bool}
     */
    private static function problem(array $response, string $what): array
    {
        $why = (string) ($response['error'] ?? ('HTTP ' . $response['status']));
        if (IntegrationCommand::classify($response) === 'blocked') {
            return ['status' => 422, 'code' => 'inventory_refused', 'retryable' => false,
                'message' => $what . ': ' . $why . '. Nothing was received. Withdraw this receipt and record the delivery again once that is put right.'];
        }

        return ['status' => 502, 'code' => 'inventory_unavailable', 'retryable' => true,
            'message' => $what . ': ' . $why . '. Nothing was received — press Retry.' . ((int) $response['status'] === 403 ? ' (Registering serial numbers and batches needs your own Inventory permission to add them.)' : '')];
    }

    /** The item's conversion from the line's unit to its base unit (Inventory's base_qty). */
    private static function factor(array $item, ?int $unitId): float
    {
        if ($unitId === null || $unitId === self::id($item['unit_id'] ?? null)) {
            return 1.0;
        }
        foreach ((array) ($item['units'] ?? []) as $unit) {
            if (is_array($unit) && (int) ($unit['unit_id'] ?? 0) === $unitId && (float) ($unit['conversion_factor'] ?? 0) > 0) {
                return (float) $unit['conversion_factor'];
            }
        }

        return 1.0;
    }

    /** @return list<string> */
    public static function serialNumbers(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        return array_values(array_filter(array_map(static fn ($s) => is_scalar($s) ? trim((string) $s) : '', $raw), static fn (string $s) => $s !== ''));
    }

    private static function truthy(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 't' || $value === 'true';
    }

    private static function id(mixed $value): ?int
    {
        $id = is_numeric($value) ? (int) $value : 0;

        return $id > 0 ? $id : null;
    }

    private static function text(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    private static function num(float $value): string
    {
        return rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');
    }
}
