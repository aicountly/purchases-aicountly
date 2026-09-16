<?php

declare(strict_types=1);

namespace Aicountly\Api\Controllers;

use Aicountly\Api\Domain\SourcingService;
use Aicountly\Api\Http;
use Aicountly\Api\Permissions;

final class SourcingController extends Controller
{
    public static function index(): void
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'rfq.view');

        $params = Http::listParams(['rfq_date', 'rfq_no', 'status', 'response_deadline', 'created_at'], 'rfq_date');
        $result = (new SourcingService($ctx, $auth))->searchRfqs([
            'status' => Http::param('status'),
            'q'      => $params['q'],
        ], $params['limit'], $params['offset'], $params['sort'], $params['order']);

        Http::list($result['rows'], $result['total'], $params['limit'], $params['offset']);
    }

    public static function show(string $id): void
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'rfq.view');

        $rfq = (new SourcingService($ctx, $auth))->findRfq((int) $id);
        if ($rfq === []) {
            Http::notFound('That RFQ does not exist.');
        }

        Http::data($rfq);
    }

    public static function create(): void
    {
        [$auth, $ctx] = self::enter();
        Http::data((new SourcingService($ctx, $auth))->createRfq(Http::body()), 201);
    }

    public static function issue(string $id): void
    {
        [$auth, $ctx] = self::enter();
        Http::data((new SourcingService($ctx, $auth))->issueRfq((int) $id));
    }

    public static function invite(string $id): void
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'rfq.create');

        $supplierId = Http::intParam('supplier_account_id');
        if ($supplierId === null) {
            Http::validationFailed('Say which supplier to invite.', ['field' => 'supplier_account_id']);
        }

        $service = new SourcingService($ctx, $auth);
        $service->inviteSupplier((int) $id, $supplierId);

        Http::data($service->findRfq((int) $id));
    }

    public static function recordQuote(string $id): void
    {
        [$auth, $ctx] = self::enter();
        Http::data((new SourcingService($ctx, $auth))->recordQuote((int) $id, Http::body()), 201);
    }

    /** The comparative statement — quoted rates beside estimated landed totals. */
    public static function compare(string $id): void
    {
        [$auth, $ctx] = self::enter();
        Http::data((new SourcingService($ctx, $auth))->compare((int) $id));
    }

    public static function award(string $id): void
    {
        [$auth, $ctx] = self::enter();
        Http::data((new SourcingService($ctx, $auth))->award((int) $id, Http::body()));
    }
}
