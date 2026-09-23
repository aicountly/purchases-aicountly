<?php

declare(strict_types=1);

namespace Aicountly\Api\Controllers;

use Aicountly\Api\Ai\ClaimAssistant;
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
            // Resolved from the session inside the service. The flag says
            // "mine"; it never says whose.
            'mine'                => Http::param('mine') === '1',
            'q'                   => $params['q'],
        ], $params['limit'], $params['offset'], $params['sort'], $params['order']);

        Http::list($result['rows'], $result['total'], $params['limit'], $params['offset']);
    }

    /**
     * The option lists, limits and capabilities the claim screen renders from.
     *
     * It exists so the screen has no second copy of the claim kinds to fall out
     * of step with, and so it can say plainly what this deployment cannot do
     * rather than offering a control that fails.
     */
    public static function meta(): void
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'claim.create');

        Http::data((new ReturnClaimService($ctx, $auth))->claimMeta());
    }

    /**
     * Open claims that resemble the one being raised. Advisory: it warns, and
     * nothing here refuses a claim because of what it returns.
     */
    public static function similar(): void
    {
        [$auth, $ctx] = self::enter();

        $rows = (new ReturnClaimService($ctx, $auth))->similarClaims([
            'supplier_account_id' => Http::intParam('supplier_account_id'),
            'claim_kind'          => Http::param('claim_kind'),
            'po_id'               => Http::intParam('po_id'),
            'bill_request_id'     => Http::intParam('bill_request_id'),
        ]);

        Http::list($rows, count($rows), count($rows), 0);
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

    /**
     * AI help for the claim being typed, from a fixed list of jobs.
     *
     * The model key stays on this server — that is the whole reason this is an
     * endpoint rather than a call from the browser. Nothing here writes: the
     * answer is a suggestion the screen shows beside the field for the user to
     * accept or discard.
     */
    public static function assist(): void
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'claim.create');

        $body = Http::body();
        $draft = is_array($body['draft'] ?? null) ? $body['draft'] : [];

        Http::data(ClaimAssistant::run((string) ($body['intent'] ?? ''), $draft));
    }

    public static function transition(string $id, string $action): void
    {
        [$auth, $ctx] = self::enter();
        Http::data((new ReturnClaimService($ctx, $auth))->updateClaim((int) $id, $action, Http::body()));
    }
}
