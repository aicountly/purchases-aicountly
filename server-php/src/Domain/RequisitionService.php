<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain;

use Aicountly\Api\Audit;
use Aicountly\Api\Auth;
use Aicountly\Api\Clients\InventoryClient;
use Aicountly\Api\Context;
use Aicountly\Api\Db;
use Aicountly\Api\Http;
use Aicountly\Api\Permissions;

/**
 * Purchase requisitions — somebody needs something.
 *
 * The estimated rate on a line is what the requester expects it to cost, used
 * only to route the approval. It is not a price (that is agreed with a supplier
 * later) and not a valuation (that is Inventory's), and nothing downstream reads
 * it as either.
 */
final class RequisitionService
{
    public function __construct(
        private readonly Context $ctx,
        private readonly Auth $auth,
    ) {
    }

    /** @param array<string, mixed> $input */
    public function create(array $input): array
    {
        Permissions::assert($this->ctx, $this->auth, 'requisition.create');

        $lines = $this->normaliseLines($input['lines'] ?? []);
        if ($lines === []) {
            Http::validationFailed('A requisition needs at least one line.', ['field' => 'lines']);
        }

        return Db::transaction(function () use ($input, $lines) {
            $no = NumberSeries::next($this->ctx, 'requisition');
            $estimated = array_sum(array_map(
                static fn (array $line) => (float) $line['required_qty'] * (float) $line['estimated_rate'],
                $lines,
            ));

            $flags = [];
            foreach (['emergency', 'single_source', 'non_preferred_vendor'] as $flag) {
                if (!empty($input[$flag])) {
                    $flags[] = $flag;
                }
            }

            $requisitionId = (int) Db::insert('purchase_requisitions', [
                'cmp_id'          => $this->ctx->cmpId,
                'fy_id'           => $this->ctx->fyId,
                'bo_id'           => $this->ctx->boId,
                'requisition_no'  => $no,
                'requisition_date' => self::date($input['requisition_date'] ?? null),
                'status'          => 'DRAFT',
                'requester_uuid'  => $this->auth->uuid,
                'department'      => self::text($input['department'] ?? null),
                'cost_centre_id'  => self::id($input['cost_centre_id'] ?? null),
                'project_id'      => self::id($input['project_id'] ?? null),
                'required_by'     => self::text($input['required_by'] ?? null),
                'priority'        => self::text($input['priority'] ?? null) ?? 'normal',
                'justification'   => self::text($input['justification'] ?? null),
                'exception_flags' => $flags,
                'estimated_value' => round($estimated, 4),
            ], 'requisition_id');

            foreach ($lines as $line) {
                Db::insert('purchase_requisition_lines', [
                    'requisition_id' => $requisitionId,
                    'cmp_id'         => $this->ctx->cmpId,
                    'line_no'        => $line['line_no'],
                    'item_id'        => $line['item_id'],
                    'unit_id'        => $line['unit_id'],
                    'warehouse_id'   => $line['warehouse_id'],
                    'is_service'     => $line['is_service'],
                    'description'    => $line['description'],
                    'required_qty'   => $line['required_qty'],
                    'estimated_rate' => $line['estimated_rate'],
                    'required_by'    => $line['required_by'],
                    'notes'          => $line['notes'],
                ], 'line_id');
            }

            Audit::record($this->ctx, $this->auth, 'requisition.created', 'requisition', $requisitionId, null, [
                'requisition_no'  => $no,
                'estimated_value' => $estimated,
            ]);

            return $this->find($requisitionId);
        });
    }

    /** Submit for approval, raising the approval stages the rules call for. */
    public function submit(int $requisitionId): array
    {
        Permissions::assert($this->ctx, $this->auth, 'requisition.create');

        $requisition = $this->find($requisitionId);
        if ($requisition === []) {
            Http::notFound('That requisition does not exist.');
        }
        if ($requisition['status'] !== 'DRAFT') {
            Http::conflict('This requisition has already been submitted.');
        }

        $threshold = (float) (Db::scalar(
            'SELECT requisition_approval_above_amount FROM purchase_settings WHERE cmp_id = :cmp',
            ['cmp' => $this->ctx->cmpId],
        ) ?? 0);

        $value = (float) $requisition['estimated_value'];
        $flags = Db::jsonColumn($requisition['exception_flags']);
        $needsApproval = ($threshold > 0 && $value > $threshold) || $flags !== [];

        Db::transaction(function () use ($requisitionId, $needsApproval, $value, $threshold, $flags) {
            Db::update('purchase_requisitions', [
                'status'     => $needsApproval ? 'APPROVAL_PENDING' : 'APPROVED',
                'approved_by' => $needsApproval ? null : $this->auth->uuid,
                'approved_at' => $needsApproval ? null : self::now(),
                'updated_at' => self::now(),
            ], ['requisition_id' => $requisitionId, 'cmp_id' => $this->ctx->cmpId]);

            if (!$needsApproval) {
                return;
            }

            if ($threshold > 0 && $value > $threshold) {
                Db::insert('purchase_approval_requests', [
                    'cmp_id'              => $this->ctx->cmpId,
                    'fy_id'               => $this->ctx->fyId,
                    'entity_type'         => 'requisition',
                    'entity_id'           => $requisitionId,
                    'required_permission' => 'requisition.approve',
                    'reason_kind'         => 'value',
                    'reason_detail'       => sprintf('Estimated at %s, above the %s approval threshold.', self::money($value), self::money($threshold)),
                    'threshold_value'     => $threshold,
                    'actual_value'        => $value,
                    'requested_by'        => $this->auth->uuid,
                ], 'approval_id');
            }

            foreach ($flags as $flag) {
                Db::insert('purchase_approval_requests', [
                    'cmp_id'              => $this->ctx->cmpId,
                    'fy_id'               => $this->ctx->fyId,
                    'entity_type'         => 'requisition',
                    'entity_id'           => $requisitionId,
                    'required_permission' => 'requisition.approve',
                    'reason_kind'         => (string) $flag,
                    'reason_detail'       => self::describeFlag((string) $flag),
                    'requested_by'        => $this->auth->uuid,
                ], 'approval_id');
            }
        });

        Audit::record($this->ctx, $this->auth, 'requisition.submitted', 'requisition', $requisitionId, ['status' => 'DRAFT'], [
            'status' => $needsApproval ? 'APPROVAL_PENDING' : 'APPROVED',
        ]);

        return $this->find($requisitionId);
    }

    /** @param array<string, mixed> $input */
    public function decide(int $requisitionId, string $action, array $input): array
    {
        Permissions::assert($this->ctx, $this->auth, 'requisition.approve');

        $requisition = $this->find($requisitionId);
        if ($requisition === []) {
            Http::notFound('That requisition does not exist.');
        }
        if ($requisition['status'] !== 'APPROVAL_PENDING') {
            Http::conflict('This requisition is not waiting for approval.');
        }

        // Segregation of duties. The person who raised it may not approve it,
        // however senior they are — that is the point of the control, and a
        // permission that lets you approve does not let you approve your own.
        if ((string) $requisition['requester_uuid'] === $this->auth->uuid && $this->auth->accessType() !== 1) {
            Http::forbidden('You raised this requisition, so somebody else has to approve it.');
        }

        $approved = $action === 'approve';
        $note = self::text($input['note'] ?? null);

        if (!$approved && $note === null) {
            Http::validationFailed('Say why this requisition is being rejected.', ['field' => 'note']);
        }

        Db::transaction(function () use ($requisitionId, $approved, $note) {
            Db::update('purchase_requisitions', [
                'status'      => $approved ? 'APPROVED' : 'REJECTED',
                'approved_by' => $approved ? $this->auth->uuid : null,
                'approved_at' => $approved ? self::now() : null,
                'updated_at'  => self::now(),
            ], ['requisition_id' => $requisitionId, 'cmp_id' => $this->ctx->cmpId]);

            Db::run(
                "UPDATE purchase_approval_requests
                 SET status = :status, decided_by = :by, decided_at = :at, decision_note = :note
                 WHERE cmp_id = :cmp AND entity_type = 'requisition' AND entity_id = :id AND status = 'PENDING'",
                [
                    'status' => $approved ? 'APPROVED' : 'REJECTED',
                    'by'     => $this->auth->uuid,
                    'at'     => self::now(),
                    'note'   => $note,
                    'cmp'    => $this->ctx->cmpId,
                    'id'     => $requisitionId,
                ],
            );
        });

        Audit::record($this->ctx, $this->auth, 'requisition.' . $action, 'requisition', $requisitionId, ['status' => 'APPROVAL_PENDING'], [
            'status' => $approved ? 'APPROVED' : 'REJECTED',
        ], $note ?? '');

        return $this->find($requisitionId);
    }

    /**
     * Requisition lines that Inventory's own replenishment report suggests.
     *
     * READ from Inventory, turned into a requisition the buyer can edit. Nothing
     * about the suggestion is stored: the reorder point, the safety stock and
     * the suggested quantity are Inventory's and stay there.
     *
     * @return array<string, mixed>
     */
    public function replenishmentSuggestions(array $filters = []): array
    {
        Permissions::assert($this->ctx, $this->auth, 'requisition.create');

        $client = (new InventoryClient())->withSession($this->auth->sesKey());
        $response = $client->replenishment($this->ctx, $filters);

        if (!$response['ok']) {
            Http::error(503, 'upstream_unavailable', 'Could not reach Inventory for replenishment suggestions. Please retry.');
        }

        return [
            'source' => 'inventory',
            'note'   => 'Read from Inventory on this request. Nothing here is stored — edit and save to raise a requisition.',
            'rows'   => $response['body']['data'] ?? [],
        ];
    }

    /** @return array<string, mixed> */
    public function find(int $requisitionId): array
    {
        $row = Db::first(
            'SELECT * FROM purchase_requisitions WHERE requisition_id = :id AND cmp_id = :cmp',
            ['id' => $requisitionId, 'cmp' => $this->ctx->cmpId],
        );
        if ($row === null) {
            return [];
        }

        $row['lines'] = Db::all('SELECT * FROM purchase_requisition_lines WHERE requisition_id = :id ORDER BY line_no', ['id' => $requisitionId]);
        $row['approvals'] = Db::all(
            "SELECT * FROM purchase_approval_requests
             WHERE cmp_id = :cmp AND entity_type = 'requisition' AND entity_id = :id ORDER BY approval_id",
            ['cmp' => $this->ctx->cmpId, 'id' => $requisitionId],
        );

        return $row;
    }

    /** @return array{rows:list<array<string, mixed>>, total:int} */
    public function search(array $filters, int $limit, int $offset, string $sort, string $order): array
    {
        [$scope, $params] = $this->ctx->scopeClause('r');
        $where = [$scope];

        if (!empty($filters['status'])) {
            $where[] = 'r.status = :status';
            $params['status'] = (string) $filters['status'];
        }
        if (!empty($filters['requester_uuid'])) {
            $where[] = 'r.requester_uuid = :requester';
            $params['requester'] = (string) $filters['requester_uuid'];
        }
        if (!empty($filters['q'])) {
            $where[] = '(r.requisition_no ILIKE :term OR r.justification ILIKE :term OR r.department ILIKE :term)';
            $params['term'] = '%' . $filters['q'] . '%';
        }
        if (!empty($filters['awaiting_approval'])) {
            $where[] = "r.status = 'APPROVAL_PENDING'";
        }

        $clause = implode(' AND ', $where);
        $sortColumn = in_array($sort, ['requisition_date', 'requisition_no', 'estimated_value', 'status', 'created_at'], true) ? $sort : 'requisition_date';

        return [
            'rows'  => Db::all("SELECT r.* FROM purchase_requisitions r WHERE {$clause} ORDER BY r.{$sortColumn} {$order}, r.requisition_id {$order} LIMIT {$limit} OFFSET {$offset}", $params),
            'total' => (int) Db::scalar("SELECT COUNT(*) FROM purchase_requisitions r WHERE {$clause}", $params),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function normaliseLines(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $lines = [];
        $lineNo = 0;
        foreach ($raw as $line) {
            if (!is_array($line)) {
                continue;
            }
            $isService = (bool) ($line['is_service'] ?? false);
            $itemId = self::id($line['item_id'] ?? null);
            if (!$isService && $itemId === null) {
                Http::validationFailed('Every stock line needs an item.', ['field' => 'lines', 'line_no' => $lineNo + 1]);
            }
            $quantity = round((float) ($line['required_qty'] ?? $line['quantity'] ?? 0), 4);
            if ($quantity <= 0) {
                Http::validationFailed('Quantity must be more than zero.', ['field' => 'lines', 'line_no' => $lineNo + 1]);
            }

            $lines[] = [
                'line_no'        => ++$lineNo,
                'item_id'        => $itemId,
                'unit_id'        => self::id($line['unit_id'] ?? null),
                'warehouse_id'   => self::id($line['warehouse_id'] ?? null),
                'is_service'     => $isService,
                'description'    => self::text($line['description'] ?? null),
                'required_qty'   => $quantity,
                'estimated_rate' => round((float) ($line['estimated_rate'] ?? 0), 4),
                'required_by'    => self::text($line['required_by'] ?? null),
                'notes'          => self::text($line['notes'] ?? null),
            ];
        }

        return $lines;
    }

    private static function describeFlag(string $flag): string
    {
        return match ($flag) {
            'emergency'           => 'Raised as an emergency purchase.',
            'single_source'       => 'Single-source procurement — no competing quotes will be sought.',
            'non_preferred_vendor' => 'Names a supplier who is not on the approved list.',
            default               => 'Requires approval.',
        };
    }

    private static function money(float $value): string
    {
        return '₹' . number_format($value, 2);
    }

    private static function id(mixed $value): ?int
    {
        return ($value === null || $value === '' || (int) $value === 0) ? null : (int) $value;
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
