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
        $auth = Auth::require();
        $body = Http::body();
        $id = $body['entity_id'] ?? null;
        $ctx = self::scope($auth, (string) ($body['entity_type'] ?? ''), is_int($id) || (is_string($id) && ctype_digit($id)) ? (int) $id : 0);
        Http::data((new ConnectShareService($ctx, $auth))->shareCheck($body));
    }

    /**
     * GET v1/connect/context/{type}/{id} — what the viewer may see of it now.
     *
     * Connect passes the viewer's year when it knows it; see scope() for when it does not.
     */
    public static function context(string $type, string $id): void
    {
        $auth = Auth::require();
        $entityId = ctype_digit($id) ? (int) $id : 0;
        $ctx = self::scope($auth, $type, $entityId);

        Http::data((new ConnectShareService($ctx, $auth))->context($type, $entityId));
    }

    /**
     * The company scope a Connect question runs under: the caller's own cmp_id, and the year
     * Connect passed — or, when it knows none, the document's own year (read by company and id
     * only). The company is confirmed with Manage for this session before anything is answered.
     */
    private static function scope(Auth $auth, string $type, int $entityId): Context
    {
        $cmpId = Http::intParam('cmp_id', 0) ?? 0;
        if ($cmpId <= 0) {
            Http::error(400, 'context_required', 'cmp_id is required.');
        }
        $fyId = Http::intParam('fy_id', 0) ?? 0;
        if ($fyId <= 0) {
            $fyId = ConnectShareService::yearOf($cmpId, $type, $entityId) ?? 0;
            if ($fyId <= 0) {
                Http::notFound('Purchases has no such document.');
            }
        }
        $ctx = Context::of($cmpId, $fyId, Http::intParam('bo_id', 0) ?? 0);
        $ctx->assertAllowed($auth);

        return $ctx;
    }
}
