<?php

declare(strict_types=1);

namespace Aicountly\Api\Controllers;

use Aicountly\Api\Auth;
use Aicountly\Api\Context;
use Aicountly\Api\Domain\ConnectShareService;
use Aicountly\Api\Http;

/**
 * The two questions Aicountly Connect asks before and after a Purchases document is shared in a
 * conversation. Both are asked with a person's own session — the sharer's, then each viewer's —
 * and answered from the same permissions as the document's own screen.
 */
final class ConnectController extends Controller
{
    /** POST v1/connect/share-check — may the sharer share it, and who could open it? */
    public static function shareCheck(): void
    {
        [$auth, $ctx] = self::enter();
        Http::data((new ConnectShareService($ctx, $auth))->shareCheck(Http::body()));
    }

    /**
     * GET v1/connect/context/{type}/{id} — what the viewer may see of it now.
     *
     * Connect passes the viewer's year when it knows it. When it does not, the document's own
     * year is used — read by company and id only — and the company is still confirmed with Manage
     * for this session before anything about the document is answered.
     */
    public static function context(string $type, string $id): void
    {
        $auth = Auth::require();
        $cmpId = Http::intParam('cmp_id', 0) ?? 0;
        if ($cmpId <= 0) {
            Http::error(400, 'context_required', 'cmp_id is required.');
        }
        $entityId = ctype_digit($id) ? (int) $id : 0;
        $fyId = Http::intParam('fy_id', 0) ?? 0;
        if ($fyId <= 0) {
            $fyId = ConnectShareService::yearOf($cmpId, $type, $entityId) ?? 0;
            if ($fyId <= 0) {
                Http::notFound('Purchases has no such document.');
            }
        }
        $ctx = Context::of($cmpId, $fyId, Http::intParam('bo_id', 0) ?? 0);
        $ctx->assertAllowed($auth);

        Http::data((new ConnectShareService($ctx, $auth))->context($type, $entityId));
    }
}
