<?php

declare(strict_types=1);

namespace Aicountly\Api\Controllers;

use Aicountly\Api\Domain\BillService;
use Aicountly\Api\Http;
use Aicountly\Api\Permissions;

final class BillsController extends Controller
{
    public static function index(): void
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'bill.enter');

        $params = Http::listParams(['supplier_invoice_date', 'supplier_invoice_no', 'status', 'created_at'], 'created_at');
        $result = (new BillService($ctx, $auth))->search([
            'status'              => Http::param('status'),
            'supplier_account_id' => Http::intParam('supplier_account_id'),
            'exceptions_only'     => Http::param('exceptions_only') === '1',
            'q'                   => $params['q'],
        ], $params['limit'], $params['offset'], $params['sort'], $params['order']);

        Http::list($result['rows'], $result['total'], $params['limit'], $params['offset']);
    }

    public static function show(string $id): void
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'match.view');

        $bill = (new BillService($ctx, $auth))->find((int) $id);
        if ($bill === []) {
            Http::notFound('That bill does not exist.');
        }

        Http::data($bill);
    }

    public static function create(): void
    {
        [$auth, $ctx] = self::enter();
        Http::data((new BillService($ctx, $auth))->enter(Http::body()), 201);
    }

    public static function rematch(string $id): void
    {
        [$auth, $ctx] = self::enter();
        Http::data((new BillService($ctx, $auth))->rematch((int) $id));
    }

    public static function post(string $id): void
    {
        [$auth, $ctx] = self::enter();
        Http::data((new BillService($ctx, $auth))->post((int) $id, Http::body()));
    }

    public static function acceptException(string $id): void
    {
        [$auth, $ctx] = self::enter();
        Http::data((new BillService($ctx, $auth))->resolveException((int) $id, 'accept', Http::body()));
    }

    public static function rejectException(string $id): void
    {
        [$auth, $ctx] = self::enter();
        Http::data((new BillService($ctx, $auth))->resolveException((int) $id, 'reject', Http::body()));
    }
}
