<?php

declare(strict_types=1);

namespace Aicountly\Api\Controllers;

use Aicountly\Api\Domain\ReturnClaimService;
use Aicountly\Api\Http;
use Aicountly\Api\Permissions;

final class ReturnsController extends Controller
{
    public static function index(): void
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'return.create');

        $params = Http::listParams(['return_date', 'return_no', 'status', 'created_at'], 'return_date');
        $result = (new ReturnClaimService($ctx, $auth))->searchReturns([
            'status'              => Http::param('status'),
            'supplier_account_id' => Http::intParam('supplier_account_id'),
            'q'                   => $params['q'],
        ], $params['limit'], $params['offset'], $params['sort'], $params['order']);

        Http::list($result['rows'], $result['total'], $params['limit'], $params['offset']);
    }

    public static function show(string $id): void
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'return.create');

        $return = (new ReturnClaimService($ctx, $auth))->findReturn((int) $id);
        if ($return === []) {
            Http::notFound('That return does not exist.');
        }

        Http::data($return);
    }

    public static function create(): void
    {
        [$auth, $ctx] = self::enter();
        Http::data((new ReturnClaimService($ctx, $auth))->createReturn(Http::body()), 201);
    }

    public static function approve(string $id): void
    {
        [$auth, $ctx] = self::enter();
        Http::data((new ReturnClaimService($ctx, $auth))->approveReturn((int) $id, Http::body()));
    }

    public static function dispatch(string $id): void
    {
        [$auth, $ctx] = self::enter();
        Http::data((new ReturnClaimService($ctx, $auth))->dispatchReturn((int) $id));
    }

    public static function debitNote(string $id): void
    {
        [$auth, $ctx] = self::enter();
        Http::data((new ReturnClaimService($ctx, $auth))->requestDebitNote((int) $id));
    }
}
