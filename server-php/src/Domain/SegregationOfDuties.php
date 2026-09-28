<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain;

use Aicountly\Api\Audit;
use Aicountly\Api\Auth;
use Aicountly\Api\Context;
use Aicountly\Api\Db;
use Aicountly\Api\Http;

/**
 * Nobody decides on a document they raised — as the company's stated policy, the same everywhere.
 *
 * `strict`: no exceptions. `owner_with_reason` (the default, so owners keep what they could always
 * do): the company owner may decide on their own document, but only by saying why, and the
 * exception is audited as such. There is no silent bypass for anybody.
 */
final class SegregationOfDuties
{
    public const POLICIES = ['strict', 'owner_with_reason'];

    public static function policy(Context $ctx): string
    {
        $policy = (string) (Db::scalar('SELECT sod_policy FROM purchase_settings WHERE cmp_id = :cmp', ['cmp' => $ctx->cmpId]) ?? 'owner_with_reason');

        return in_array($policy, self::POLICIES, true) ? $policy : 'owner_with_reason';
    }

    /**
     * @param string      $raisedBy who raised the document
     * @param string      $what     "purchase order", "requisition", … for the refusal
     * @param string|null $reason   the owner's stated reason for deciding on their own document
     */
    public static function assertMayDecide(Context $ctx, Auth $auth, string $raisedBy, string $what, string $entityType, int $entityId, ?string $reason): void
    {
        if ($raisedBy === '' || $raisedBy !== $auth->uuid) {
            return;
        }
        $policy = self::policy($ctx);
        if ($policy === 'owner_with_reason' && $auth->ownsCompany($ctx->cmpId)) {
            if ($reason === null || trim($reason) === '') {
                Http::error(403, 'sod_reason_required', sprintf('You raised this %s. As the company owner you may decide on it yourself only by saying why; the reason is recorded.', $what), ['policy' => $policy]);
            }
            Audit::record($ctx, $auth, 'sod.owner_exception', $entityType, $entityId, null, ['policy' => $policy], trim($reason));

            return;
        }
        Http::error(403, 'sod_self_decision', sprintf('You raised this %s, so somebody else has to approve it.', $what), ['policy' => $policy]);
    }
}
