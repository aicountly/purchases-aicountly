<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain;

use Aicountly\Api\Auth;
use Aicountly\Api\Context;
use Aicountly\Api\Permissions;

/**
 * Which approval requests a person may see, and how much of each — one rule for every surface
 * that lists them: the approvals inbox (v1/approvals), the overview's "awaiting your approval"
 * count and its priority inbox.
 *
 *   listed   only kinds of document the caller approves (requisition.approve, po.approve), and a
 *            stage that names a permission only to those who hold it
 *   values   the document's value, threshold and the reason text (which states them) only to a
 *            caller who may view that document (requisition.view, po.view) — the same rule as the
 *            document's own screen
 *
 * The overview's inbox used to list every pending approval in the company, with its value and
 * its reason, to anybody who could open the company.
 */
final class ApprovalAccess
{
    /** Approval kinds and the permission that decides each. */
    private const APPROVES = ['requisition' => 'requisition.approve', 'purchase_order' => 'po.approve'];

    /** Approval kinds and the permission that shows each document's values. */
    private const VIEWS = ['requisition' => 'requisition.view', 'purchase_order' => 'po.view'];

    /**
     * The SQL condition on purchase_approval_requests (under $alias) for what this caller may
     * decide, with its bindings; null when they decide nothing at all.
     *
     * @return array{0: ?string, 1: array<string, mixed>}
     */
    public static function decidable(Context $ctx, Auth $auth, string $alias = 'a'): array
    {
        $kinds = [];
        $bind = [];
        foreach (array_keys(self::APPROVES) as $i => $kind) {
            if (Permissions::allows($ctx, $auth, self::APPROVES[$kind])) {
                $kinds[] = ':appr_kind' . $i;
                $bind['appr_kind' . $i] = $kind;
            }
        }
        if ($kinds === []) {
            return [null, []];
        }

        $held = [];
        foreach (array_values(Permissions::granted($ctx, $auth)) as $i => $permission) {
            $held[] = ':appr_perm' . $i;
            $bind['appr_perm' . $i] = $permission;
        }
        $sql = "{$alias}.entity_type IN (" . implode(', ', $kinds) . ')'
            . " AND ({$alias}.required_permission IS NULL" . ($held === [] ? '' : " OR {$alias}.required_permission IN (" . implode(', ', $held) . ')') . ')';

        return [$sql, $bind];
    }

    /** Whether this caller may see the values of an approval of this kind. */
    public static function seesValues(Context $ctx, Auth $auth, string $entityType): bool
    {
        $permission = self::VIEWS[$entityType] ?? null;

        return $permission !== null && Permissions::allows($ctx, $auth, $permission);
    }

    /** The permission whose absence withheld an approval's values, for the screen to name. */
    public static function viewPermission(string $entityType): ?string
    {
        return self::VIEWS[$entityType] ?? null;
    }
}
