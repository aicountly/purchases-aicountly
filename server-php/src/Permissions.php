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
            'po.close'   => 'Short-close a purchase order that will not be delivered in full',
            'price.override' => 'Order above the agreed contract rate',
        ],
        'Receiving and billing' => [
            'receipt.request'  => 'Record goods received (creates the GRN in Inventory)',
            'receipt.over_tolerance' => 'Accept goods beyond the ordered quantity and its tolerance',
            'bill.enter'       => 'Enter a supplier bill',
            'bill.post'        => 'Post the bill to Smart Books',
            'match.view'       => 'View three-way match results',
            'match.resolve'    => 'Accept or reject a match exception',
        ],
        'Returns and claims' => [
            'return.create'  => 'Raise a purchase return',
            'return.approve' => 'Approve a purchase return',
            'return.financial_adjustment' => 'Raise a debit note for a return with no goods going back',
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
        if (self::allows($ctx, $auth, $permission)) {
            return;
        }
        if ($auth->isService()) {
            // Said to a developer of another product, so it names the knob.
            Http::forbidden(
                'The ' . $auth->sourceApp . ' service key is not allowed to ' . self::describe($permission) . '. '
                . 'Product keys are read-only in Purchases unless SERVICE_KEY_PERMISSIONS grants more, and never approve, administer or post to Smart Books.',
            );
        }
        Http::forbidden('You do not have permission to ' . self::describe($permission) . '.');
    }

    public static function allows(Context $ctx, Auth $auth, string $permission): bool
    {
        // A trusted product backend acts for a human its own side already authorised — but
        // only within what this deployment lets that PRODUCT do here (ServiceKeys): read-only
        // unless SERVICE_KEY_PERMISSIONS says otherwise, and never the ServiceKeys::NEVER set.
        if ($auth->isService()) {
            return in_array($permission, ServiceKeys::permissions($auth->sourceApp), true);
        }
        if ($auth->ownsCompany($ctx->cmpId)) {
            return true;
        }

        return in_array($permission, self::granted($ctx, $auth), true);
    }

    /** @return list<string> */
    public static function granted(Context $ctx, Auth $auth): array
    {
        $key = $ctx->cmpId . ':' . $auth->kind . ':' . $auth->sourceApp . ':' . $auth->uuid;
        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }

        if ($auth->isService()) {
            return self::$cache[$key] = ServiceKeys::permissions($auth->sourceApp);
        }
        if ($auth->ownsCompany($ctx->cmpId)) {
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

    /**
     * Drop the memoised grants for a user.
     *
     * The cache is per-request, which is right for reads: `granted()` is called
     * several times while rendering a screen. But an endpoint that CHANGES
     * somebody's profile and then reports the result would answer from the
     * grants it read before the change — including, when the caller edits their
     * own access, telling them the edit did nothing.
     */
    public static function forget(?Context $ctx = null, ?Auth $auth = null): void
    {
        if ($ctx === null || $auth === null) {
            self::$cache = [];

            return;
        }

        unset(self::$cache[$ctx->cmpId . ':' . $auth->kind . ':' . $auth->sourceApp . ':' . $auth->uuid]);
    }

    /**
     * What each of these people holds in this company through Purchases profiles — for a
     * question asked about somebody other than the caller (who may view a shared document).
     * A company owner's rights come from Manage and can only be read from their own session,
     * so an owner who holds no profile is reported here without any; their own read decides.
     *
     * @param list<string> $uuids
     * @return array<string, list<string>> keyed by the lower-cased uuid
     */
    public static function grantedTo(int $cmpId, array $uuids): array
    {
        $uuids = array_values(array_unique(array_map('strtolower', $uuids)));
        $out = array_fill_keys($uuids, []);
        if ($uuids === []) {
            return $out;
        }
        $placeholders = [];
        $params = ['cmp' => $cmpId];
        foreach (array_values($uuids) as $i => $uuid) {
            $placeholders[] = ':u' . $i;
            $params['u' . $i] = $uuid;
        }
        $rows = Db::all(
            'SELECT LOWER(a.user_uuid) AS user_uuid, p.permissions
             FROM ' . self::TABLE_ASSIGNMENTS . ' a
             JOIN ' . self::TABLE_PROFILES . ' p ON p.profile_id = a.profile_id
             WHERE a.cmp_id = :cmp AND p.is_active = TRUE AND LOWER(a.user_uuid) IN (' . implode(', ', $placeholders) . ')',
            $params,
        );
        foreach ($rows as $row) {
            foreach (Db::jsonColumn($row['permissions'] ?? null) as $permission) {
                if (is_string($permission) && !in_array($permission, $out[$row['user_uuid']] ?? [], true)) {
                    $out[$row['user_uuid']][] = $permission;
                }
            }
        }

        return $out;
    }

    /**
     * The permissions this caller may hand to somebody else.
     *
     * A company owner may grant anything. Anybody else may grant only what they
     * themselves hold — otherwise `access.manage` is not a permission, it is a
     * route to every other permission, and the segregation of duties the rest of
     * this product enforces would be one profile edit away from meaningless.
     *
     * @return list<string>
     */
    public static function grantable(Context $ctx, Auth $auth): array
    {
        if ($auth->isService()) {
            return []; // a product key grants nobody anything (access.manage is never a key's)
        }
        if ($auth->ownsCompany($ctx->cmpId)) {
            return self::all();
        }

        return self::granted($ctx, $auth);
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
