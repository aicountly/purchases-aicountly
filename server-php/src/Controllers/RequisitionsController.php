<?php

declare(strict_types=1);

namespace Aicountly\Api\Controllers;

use Aicountly\Api\Domain\RequisitionService;
use Aicountly\Api\Http;
use Aicountly\Api\Permissions;

final class RequisitionsController extends Controller
{
    public static function index(): void
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'requisition.view');

        $params = Http::listParams(['requisition_date', 'requisition_no', 'estimated_value', 'status', 'created_at'], 'requisition_date');
        $result = (new RequisitionService($ctx, $auth))->search([
            'status'            => Http::param('status'),
            'requester_uuid'    => Http::param('requester_uuid'),
            'awaiting_approval' => Http::param('awaiting_approval') === '1',
            'q'                 => $params['q'],
        ], $params['limit'], $params['offset'], $params['sort'], $params['order']);

        Http::list($result['rows'], $result['total'], $params['limit'], $params['offset']);
    }

    public static function show(string $id): void
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'requisition.view');

        $requisition = (new RequisitionService($ctx, $auth))->find((int) $id);
        if ($requisition === []) {
            Http::notFound('That requisition does not exist.');
        }

        Http::data($requisition);
    }

    public static function create(): void
    {
        [$auth, $ctx] = self::enter();
        Http::data((new RequisitionService($ctx, $auth))->create(Http::body()), 201);
    }

    public static function submit(string $id): void
    {
        [$auth, $ctx] = self::enter();
        Http::data((new RequisitionService($ctx, $auth))->submit((int) $id));
    }

    public static function approve(string $id): void
    {
        [$auth, $ctx] = self::enter();
        Http::data((new RequisitionService($ctx, $auth))->decide((int) $id, 'approve', Http::body()));
    }

    public static function reject(string $id): void
    {
        [$auth, $ctx] = self::enter();
        Http::data((new RequisitionService($ctx, $auth))->decide((int) $id, 'reject', Http::body()));
    }

    /** Inventory's replenishment suggestions, read live and stored nowhere. */
    public static function replenishment(): void
    {
        [$auth, $ctx] = self::enter();
        Http::data((new RequisitionService($ctx, $auth))->replenishmentSuggestions([
            'warehouse_id' => Http::intParam('warehouse_id'),
            'item_grp_id'  => Http::intParam('item_grp_id'),
            'limit'        => Http::intParam('limit', 100),
        ]));
    }
}
