<?php

declare(strict_types=1);

namespace Aicountly\Api\Controllers;

use Aicountly\Api\Domain\ReturnClaimService;
use Aicountly\Api\Http;
use Aicountly\Api\Permissions;

final class ClaimsController extends Controller
{
    public static function index(): void
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'claim.create');

        $params = Http::listParams(['claim_date', 'claim_no', 'claimed_amount', 'status', 'created_at'], 'claim_date');
        $result = (new ReturnClaimService($ctx, $auth))->searchClaims([
            'status'              => Http::param('status'),
            'claim_kind'          => Http::param('claim_kind'),
            'supplier_account_id' => Http::intParam('supplier_account_id'),
            'open_only'           => Http::param('open_only') === '1',
        ], $params['limit'], $params['offset'], $params['sort'], $params['order']);

        Http::list($result['rows'], $result['total'], $params['limit'], $params['offset']);
    }

    public static function show(string $id): void
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'claim.create');

        $claim = (new ReturnClaimService($ctx, $auth))->findClaim((int) $id);
        if ($claim === []) {
            Http::notFound('That claim does not exist.');
        }

        Http::data($claim);
    }

    public static function create(): void
    {
        [$auth, $ctx] = self::enter();
        Http::data((new ReturnClaimService($ctx, $auth))->createClaim(Http::body()), 201);
    }

    public static function transition(string $id, string $action): void
    {
        [$auth, $ctx] = self::enter();
        Http::data((new ReturnClaimService($ctx, $auth))->updateClaim((int) $id, $action, Http::body()));
    }
}
