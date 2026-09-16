<?php

declare(strict_types=1);

namespace Aicountly\Api;

/**
 * This product's own permissions, layered over the portal identity.
 *
 * Procurement is where segregation of duties actually matters: the person who
 * raises a requisition should not be the person who approves it, and neither
 * should be the person who clears a three-way match exception. Those are three
 * separate permissions here for exactly that reason.
 *
 * ENFORCED IN THE BACKEND. Hiding a menu item in React is a courtesy, not a
 * control — the API route is one curl away.
 *
 * @var array<string, array<string, string>>
 */
final class Permissions
{
    public const TABLE_PROFILES    = 'purchase_permission_profiles';
    public const TABLE_ASSIGNMENTS = 'purchase_permission_assignments';

    public const CATALOG = [
        'Requisitions' => [
            'requisition.view'    => 'View requisitions',
            'requisition.create'  => 'Raise a requisition',
            'requisition.approve' => 'Approve a requisition',
        ],
        'Sourcing' => [
            'rfq.view'    => 'View RFQs and quotes',
            'rfq.create'  => 'Raise an RFQ and invite suppliers',
            'rfq.award'   => 'Award an RFQ to a supplier',
            'quote.enter' => 'Enter a supplier quotation on their behalf',
        ],
        'Purchase orders' => [
            'po.view'    => 'View purchase orders',
            'po.create'  => 'Raise a purchase order',
            'po.approve' => 'Approve a purchase order',
            'po.amend'   => 'Amend an issued purchase order',
            'po.cancel'  => 'Cancel a purchase order',
            'price.override' => 'Order above the agreed contract rate',
        ],
        'Receiving and billing' => [
            'receipt.request'  => 'Record goods received (creates the GRN in Inventory)',
            'bill.enter'       => 'Enter a supplier bill',
            'bill.post'        => 'Post the bill to Smart Books',
            'match.view'       => 'View three-way match results',
            'match.resolve'    => 'Accept or reject a match exception',
        ],
        'Returns and claims' => [
            'return.create'  => 'Raise a purchase return',
            'return.approve' => 'Approve a purchase return',
            'claim.create'   => 'Raise a supplier claim',
            'claim.settle'   => 'Settle a supplier claim',
        ],
        'Suppliers' => [
            'supplier.view'    => 'View supplier procurement profiles',
            'supplier.manage'  => 'Edit supplier procurement profiles',
            'supplier.approve' => 'Approve or suspend a supplier',
            'cost.view'        => 'See historical purchase prices and spend',
        ],
        'Administration' => [
            'settings.manage' => 'Change Purchases settings',
            'access.manage'   => 'Manage Purchases permission profiles',
            'reports.view'    => 'View procurement reports',
        ],
    ];

    /** @var array<string, list<string>> */
    private static array $cache = [];

    public static function assert(Context $ctx, Auth $auth, string $permission): void
    {
        if (!self::allows($ctx, $auth, $permission)) {
            Http::forbidden('You do not have permission to ' . self::describe($permission) . '.');
        }
    }

    public static function allows(Context $ctx, Auth $auth, string $permission): bool
    {
        if ($auth->isService()) {
            return true;
        }
        if ($auth->accessType() === 1) {
            return true;
        }

        return in_array($permission, self::granted($ctx, $auth), true);
    }

    /** @return list<string> */
    public static function granted(Context $ctx, Auth $auth): array
    {
        $key = $ctx->cmpId . ':' . $auth->uuid;
        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }

        if ($auth->isService() || $auth->accessType() === 1) {
            return self::$cache[$key] = self::all();
        }

        try {
            $rows = Db::all(
                'SELECT p.permissions
                 FROM ' . self::TABLE_ASSIGNMENTS . ' a
                 JOIN ' . self::TABLE_PROFILES . ' p ON p.profile_id = a.profile_id
                 WHERE a.cmp_id = :cmp AND a.user_uuid = :uuid AND p.is_active = TRUE',
                ['cmp' => $ctx->cmpId, 'uuid' => $auth->uuid],
            );
        } catch (\Throwable $e) {
            error_log('[permissions] lookup failed: ' . $e->getMessage());

            return self::$cache[$key] = [];
        }

        $granted = [];
        foreach ($rows as $row) {
            foreach (Db::jsonColumn($row['permissions'] ?? null) as $permission) {
                if (is_string($permission)) {
                    $granted[$permission] = true;
                }
            }
        }

        return self::$cache[$key] = array_keys($granted);
    }

    /** @return list<string> */
    public static function all(): array
    {
        $out = [];
        foreach (self::CATALOG as $group) {
            foreach (array_keys($group) as $permission) {
                $out[] = $permission;
            }
        }

        return $out;
    }

    public static function exists(string $permission): bool
    {
        return in_array($permission, self::all(), true);
    }

    private static function describe(string $permission): string
    {
        foreach (self::CATALOG as $group) {
            if (isset($group[$permission])) {
                return strtolower($group[$permission]);
            }
        }

        return 'do that';
    }
}
