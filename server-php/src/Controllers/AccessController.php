<?php

declare(strict_types=1);

namespace Aicountly\Api\Controllers;

use Aicountly\Api\Audit;
use Aicountly\Api\Db;
use Aicountly\Api\Http;
use Aicountly\Api\Permissions;

/**
 * Who may do what in Purchases.
 *
 * The permission tables and the catalogue have been here since the first
 * migration; what was missing was any way to write to them. Until now the
 * company owner held everything implicitly (the portal's acs_type = 1) and
 * nobody else could be granted anything at all.
 *
 * TWO RULES DO THE REAL WORK, and both are enforced here rather than in React:
 *
 *   NO ESCALATION. An administrator who is not the owner may grant only
 *   permissions they themselves hold. Without that, `access.manage` is not one
 *   permission among twenty-seven — it is a key to all of them, and the
 *   segregation of duties the rest of this product enforces (nobody approves
 *   their own requisition; clearing a match exception is a separate permission
 *   from raising the bill) would be one profile edit away from decorative.
 *
 *   NO SELF-LOCKOUT. You cannot remove your own last grant of `access.manage`.
 *   A company whose only administrator has just revoked themselves needs the
 *   owner to log in, and on a company whose owner has left, it needs support.
 *
 * Identity stays with the portal. This product stores a uuid, a label the
 * administrator typed, and the access decision. It never copies a name from
 * my.aicountly.com, because a copied name is wrong the day somebody marries.
 */
final class AccessController extends Controller
{
    /**
     * Starter profiles, offered once on a company that has none.
     *
     * Shaped around the four jobs this product actually separates. They are
     * ordinary profiles once created: editable, deletable, not special.
     *
     * @var array<string, array{name: string, description: string, permissions: list<string>}>
     */
    private const STARTER_PROFILES = [
        'buyer' => [
            'name'        => 'Buyer',
            'description' => 'Raises requisitions, sources quotes and issues purchase orders. Cannot approve their own.',
            'permissions' => [
                'requisition.view', 'requisition.create',
                'rfq.view', 'rfq.create', 'quote.enter',
                'po.view', 'po.create',
                'supplier.view', 'cost.view',
                'receipt.request',
            ],
        ],
        'approver' => [
            'name'        => 'Purchase approver',
            'description' => 'Approves requisitions and purchase orders. Deliberately cannot raise them.',
            'permissions' => [
                'requisition.view', 'requisition.approve',
                'po.view', 'po.approve', 'po.cancel',
                'rfq.view', 'rfq.award',
                'supplier.view', 'supplier.approve',
                'cost.view', 'reports.view',
            ],
        ],
        'payables' => [
            'name'        => 'Accounts payable',
            'description' => 'Enters supplier bills, resolves match exceptions and posts to Smart Books.',
            'permissions' => [
                'po.view', 'bill.enter', 'bill.post',
                'match.view', 'match.resolve',
                'return.create', 'claim.create',
                'supplier.view', 'cost.view', 'reports.view',
            ],
        ],
        'viewer' => [
            'name'        => 'Read only',
            'description' => 'Sees the dashboards and the documents behind them, and changes nothing.',
            'permissions' => [
                'requisition.view', 'rfq.view', 'po.view',
                'match.view', 'supplier.view', 'reports.view',
            ],
        ],
    ];

    // -----------------------------------------------------------------------
    // Reading
    // -----------------------------------------------------------------------

    /**
     * The catalogue, what the caller holds, and what they may hand out.
     *
     * `grantable` is what the profile editor renders as available. A non-owner
     * administrator sees their own permissions and no more, so the escalation
     * rule is visible in the UI rather than only surfacing as a rejection.
     */
    public static function catalogue(): void
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'access.manage');

        Http::data([
            'catalog'    => Permissions::CATALOG,
            'granted'    => Permissions::granted($ctx, $auth),
            'grantable'  => Permissions::grantable($ctx, $auth),
            'is_owner'   => $auth->ownsCompany($ctx->cmpId),
            'my_uuid'    => $auth->uuid,
            'owner_note' => 'The company owner holds every permission here automatically, set in Aicountly Manage. '
                . 'Profiles decide what everybody else can do.',
        ]);
    }

    public static function profiles(): void
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'access.manage');

        Http::data(array_map(
            static fn (array $row) => self::presentProfile($row),
            Db::all(
                'SELECT p.*,
                        (SELECT COUNT(*) FROM ' . Permissions::TABLE_ASSIGNMENTS . ' a
                          WHERE a.profile_id = p.profile_id) AS member_count
                 FROM ' . Permissions::TABLE_PROFILES . ' p
                 WHERE p.cmp_id = :cmp
                 ORDER BY p.is_active DESC, p.profile_name',
                ['cmp' => $ctx->cmpId],
            ),
        ));
    }

    /**
     * Everybody with a profile in this company, and the union of what it gives them.
     *
     * Grouped by person, because "what can Priya do?" is the question an
     * administrator actually has, and a row per assignment answers a different one.
     */
    public static function members(): void
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'access.manage');

        $rows = Db::all(
            'SELECT a.assignment_id, a.user_uuid, a.member_label, a.note, a.assigned_by, a.created_at,
                    p.profile_id, p.profile_name, p.permissions, p.is_active
             FROM ' . Permissions::TABLE_ASSIGNMENTS . ' a
             JOIN ' . Permissions::TABLE_PROFILES . ' p ON p.profile_id = a.profile_id
             WHERE a.cmp_id = :cmp
             ORDER BY a.member_label NULLS LAST, a.user_uuid, p.profile_name',
            ['cmp' => $ctx->cmpId],
        );

        $people = [];
        foreach ($rows as $row) {
            $uuid = (string) $row['user_uuid'];
            $people[$uuid] ??= [
                'user_uuid'   => $uuid,
                'label'       => $row['member_label'],
                'is_you'      => $uuid === $auth->uuid,
                'assignments' => [],
                'permissions' => [],
            ];

            // The most recently set label wins; they are ordered so a labelled
            // row comes first.
            $people[$uuid]['label'] ??= $row['member_label'];

            $permissions = array_values(array_filter(
                Db::jsonColumn($row['permissions']),
                static fn ($p) => is_string($p),
            ));

            $people[$uuid]['assignments'][] = [
                'assignment_id' => (int) $row['assignment_id'],
                'profile_id'    => (int) $row['profile_id'],
                'profile_name'  => $row['profile_name'],
                'is_active'     => (bool) $row['is_active'],
                'note'          => $row['note'],
                'assigned_by'   => $row['assigned_by'],
                'assigned_at'   => $row['created_at'],
            ];

            if ((bool) $row['is_active']) {
                $people[$uuid]['permissions'] = array_values(array_unique(
                    array_merge($people[$uuid]['permissions'], $permissions),
                ));
            }
        }

        foreach ($people as $uuid => $person) {
            sort($person['permissions']);
            $people[$uuid] = $person + ['permission_count' => count($person['permissions'])];
        }

        Http::data(array_values($people));
    }

    /**
     * People who have actually used Purchases in this company.
     *
     * Read from our own audit log, which records the portal uuid of whoever
     * acted. It is not a user directory — this product does not have one and
     * must not grow one — but it is the difference between an administrator
     * typing a uuid from memory and picking one that has demonstrably signed in.
     *
     * Anyone already assigned is excluded, so the list is "who could I add".
     */
    public static function people(): void
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'access.manage');

        Http::data(array_map(
            static fn (array $row) => [
                'user_uuid'   => $row['actor_uuid'],
                'actions'     => (int) $row['actions'],
                'first_seen'  => $row['first_seen'],
                'last_seen'   => $row['last_seen'],
                'is_you'      => $row['actor_uuid'] === $auth->uuid,
            ],
            Db::all(
                "SELECT actor_uuid, COUNT(*) AS actions,
                        MIN(created_at) AS first_seen, MAX(created_at) AS last_seen
                 FROM purchase_audit_log
                 WHERE cmp_id = :cmp AND actor_kind = 'user'
                   AND actor_uuid NOT IN (
                       SELECT user_uuid FROM " . Permissions::TABLE_ASSIGNMENTS . ' WHERE cmp_id = :cmp
                   )
                 GROUP BY actor_uuid
                 ORDER BY MAX(created_at) DESC
                 LIMIT 50',
                ['cmp' => $ctx->cmpId],
            ),
        ));
    }

    // -----------------------------------------------------------------------
    // Profiles
    // -----------------------------------------------------------------------

    /** Create a profile, or update one by `profile_id`. */
    public static function saveProfile(): void
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'access.manage');

        $body = Http::body();
        $name = trim((string) ($body['profile_name'] ?? ''));
        if ($name === '') {
            Http::validationFailed('Give the profile a name.', ['field' => 'profile_name']);
        }

        $permissions = self::validatePermissions($body['permissions'] ?? [], $ctx, $auth);
        $profileId = isset($body['profile_id']) ? (int) $body['profile_id'] : 0;

        $existing = null;
        if ($profileId > 0) {
            $existing = Db::first(
                'SELECT * FROM ' . Permissions::TABLE_PROFILES . ' WHERE profile_id = :id AND cmp_id = :cmp',
                ['id' => $profileId, 'cmp' => $ctx->cmpId],
            );
            if ($existing === null) {
                Http::notFound('That profile is not in this company.');
            }

            // Editing a profile you do not fully hold would let you keep the
            // permissions you cannot grant and add ones you can — the
            // escalation rule with an extra step.
            self::assertMayRewrite($existing, $permissions, $ctx, $auth);
        }

        $clash = Db::first(
            'SELECT profile_id FROM ' . Permissions::TABLE_PROFILES . '
             WHERE cmp_id = :cmp AND lower(profile_name) = lower(:name) AND profile_id <> :id',
            ['cmp' => $ctx->cmpId, 'name' => $name, 'id' => $profileId],
        );
        if ($clash !== null) {
            Http::conflict('A profile with that name already exists.', ['existing_profile_id' => (int) $clash['profile_id']]);
        }

        $values = [
            'profile_name' => $name,
            'description'  => self::text($body['description'] ?? null),
            'permissions'  => json_encode(array_values($permissions)),
            'is_active'    => (bool) ($body['is_active'] ?? true),
            'updated_at'   => gmdate('Y-m-d H:i:s'),
        ];

        if ($existing === null) {
            $profileId = (int) Db::insert(Permissions::TABLE_PROFILES, $values + [
                'cmp_id'     => $ctx->cmpId,
                'created_by' => $auth->uuid,
            ], 'profile_id');
        } else {
            Db::update(Permissions::TABLE_PROFILES, $values, ['profile_id' => $profileId]);
        }

        Audit::record(
            $ctx,
            $auth,
            $existing === null ? 'access.profile_created' : 'access.profile_updated',
            'permission_profile',
            $profileId,
            $existing === null ? null : ['permissions' => Db::jsonColumn($existing['permissions'])],
            ['profile_name' => $name, 'permissions' => array_values($permissions)],
        );

        // Somebody may have just edited their own profile.
        Permissions::forget();

        Http::data(self::presentProfile(Db::first(
            'SELECT p.*, (SELECT COUNT(*) FROM ' . Permissions::TABLE_ASSIGNMENTS . ' a WHERE a.profile_id = p.profile_id) AS member_count
             FROM ' . Permissions::TABLE_PROFILES . ' p WHERE p.profile_id = :id',
            ['id' => $profileId],
        ) ?? []));
    }

    /**
     * Delete a profile.
     *
     * Refused while anybody holds it. Cascading would silently strip somebody's
     * access, and the administrator would find out when that person could not
     * post a bill.
     */
    public static function deleteProfile(string $id): void
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'access.manage');

        $profile = Db::first(
            'SELECT * FROM ' . Permissions::TABLE_PROFILES . ' WHERE profile_id = :id AND cmp_id = :cmp',
            ['id' => (int) $id, 'cmp' => $ctx->cmpId],
        );
        if ($profile === null) {
            Http::notFound('That profile is not in this company.');
        }

        $members = (int) Db::scalar(
            'SELECT COUNT(*) FROM ' . Permissions::TABLE_ASSIGNMENTS . ' WHERE profile_id = :id',
            ['id' => (int) $id],
        );
        if ($members > 0) {
            Http::conflict(
                'That profile is assigned to ' . $members . ' ' . ($members === 1 ? 'person' : 'people')
                . '. Remove them first, so nobody loses access without you seeing it.',
                ['member_count' => $members],
            );
        }

        Db::run('DELETE FROM ' . Permissions::TABLE_PROFILES . ' WHERE profile_id = :id', ['id' => (int) $id]);

        Audit::record($ctx, $auth, 'access.profile_deleted', 'permission_profile', (int) $id, [
            'profile_name' => $profile['profile_name'],
            'permissions'  => Db::jsonColumn($profile['permissions']),
        ], null);

        Permissions::forget();
        Http::data(['deleted' => true, 'profile_id' => (int) $id]);
    }

    /** Create the starter profiles, on a company that has none. */
    public static function bootstrap(): void
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'access.manage');

        $grantable = Permissions::grantable($ctx, $auth);
        $created = [];
        $skipped = [];

        foreach (self::STARTER_PROFILES as $key => $starter) {
            $exists = Db::first(
                'SELECT profile_id FROM ' . Permissions::TABLE_PROFILES . '
                 WHERE cmp_id = :cmp AND (system_key = :key OR lower(profile_name) = lower(:name))',
                ['cmp' => $ctx->cmpId, 'key' => $key, 'name' => $starter['name']],
            );
            if ($exists !== null) {
                $skipped[] = $starter['name'];
                continue;
            }

            // A non-owner bootstrapping gets the starter trimmed to what they
            // may grant, rather than the request being refused outright.
            $permissions = array_values(array_intersect($starter['permissions'], $grantable));
            if ($permissions === []) {
                $skipped[] = $starter['name'];
                continue;
            }

            $profileId = (int) Db::insert(Permissions::TABLE_PROFILES, [
                'cmp_id'       => $ctx->cmpId,
                'profile_name' => $starter['name'],
                'description'  => $starter['description'],
                'permissions'  => json_encode($permissions),
                'system_key'   => $key,
                'is_active'    => true,
                'created_by'   => $auth->uuid,
            ], 'profile_id');

            Audit::record($ctx, $auth, 'access.profile_created', 'permission_profile', $profileId, null, [
                'profile_name' => $starter['name'], 'permissions' => $permissions, 'source' => 'bootstrap',
            ]);

            $created[] = ['profile_id' => $profileId, 'profile_name' => $starter['name'], 'permissions' => $permissions];
        }

        Permissions::forget();
        Http::data(['created' => $created, 'skipped' => $skipped]);
    }

    // -----------------------------------------------------------------------
    // Members
    // -----------------------------------------------------------------------

    public static function assign(): void
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'access.manage');

        $body = Http::body();
        $uuid = trim((string) ($body['user_uuid'] ?? ''));
        $profileId = (int) ($body['profile_id'] ?? 0);

        if ($uuid === '') {
            Http::validationFailed('Which person? Their Aicountly user id is required.', ['field' => 'user_uuid']);
        }
        if (mb_strlen($uuid) > 128) {
            Http::validationFailed('That does not look like an Aicountly user id.', ['field' => 'user_uuid']);
        }

        $profile = Db::first(
            'SELECT * FROM ' . Permissions::TABLE_PROFILES . ' WHERE profile_id = :id AND cmp_id = :cmp',
            ['id' => $profileId, 'cmp' => $ctx->cmpId],
        );
        if ($profile === null) {
            Http::validationFailed('Choose a profile that exists in this company.', ['field' => 'profile_id']);
        }

        // Granting a profile is granting its permissions, so the same escalation
        // rule applies as when the profile was written.
        self::assertMayGrant(Db::jsonColumn($profile['permissions']), $ctx, $auth);

        $existing = Db::first(
            'SELECT assignment_id FROM ' . Permissions::TABLE_ASSIGNMENTS . '
             WHERE cmp_id = :cmp AND user_uuid = :uuid AND profile_id = :profile',
            ['cmp' => $ctx->cmpId, 'uuid' => $uuid, 'profile' => $profileId],
        );

        $fields = [
            'member_label' => self::text($body['member_label'] ?? null),
            'note'         => self::text($body['note'] ?? null),
            'assigned_by'  => $auth->uuid,
            'updated_at'   => gmdate('Y-m-d H:i:s'),
        ];

        if ($existing === null) {
            $assignmentId = (int) Db::insert(Permissions::TABLE_ASSIGNMENTS, $fields + [
                'cmp_id'     => $ctx->cmpId,
                'user_uuid'  => $uuid,
                'profile_id' => $profileId,
            ], 'assignment_id');
        } else {
            $assignmentId = (int) $existing['assignment_id'];
            Db::update(Permissions::TABLE_ASSIGNMENTS, $fields, ['assignment_id' => $assignmentId]);
        }

        // A label is a note about a person, so it is applied to every grant they
        // hold rather than only the one just made.
        if ($fields['member_label'] !== null) {
            Db::run(
                'UPDATE ' . Permissions::TABLE_ASSIGNMENTS . '
                 SET member_label = :label WHERE cmp_id = :cmp AND user_uuid = :uuid',
                ['label' => $fields['member_label'], 'cmp' => $ctx->cmpId, 'uuid' => $uuid],
            );
        }

        Audit::record($ctx, $auth, 'access.profile_assigned', 'permission_assignment', $assignmentId, null, [
            'user_uuid'    => $uuid,
            'profile_id'   => $profileId,
            'profile_name' => $profile['profile_name'],
        ], (string) ($fields['note'] ?? ''));

        Permissions::forget();
        Http::data(['assignment_id' => $assignmentId, 'user_uuid' => $uuid, 'profile_id' => $profileId]);
    }

    public static function unassign(string $id): void
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'access.manage');

        $assignment = Db::first(
            'SELECT a.*, p.profile_name, p.permissions
             FROM ' . Permissions::TABLE_ASSIGNMENTS . ' a
             JOIN ' . Permissions::TABLE_PROFILES . ' p ON p.profile_id = a.profile_id
             WHERE a.assignment_id = :id AND a.cmp_id = :cmp',
            ['id' => (int) $id, 'cmp' => $ctx->cmpId],
        );
        if ($assignment === null) {
            Http::notFound('That assignment is not in this company.');
        }

        self::assertNotLockingSelfOut($assignment, $ctx, $auth);

        Db::run('DELETE FROM ' . Permissions::TABLE_ASSIGNMENTS . ' WHERE assignment_id = :id', ['id' => (int) $id]);

        Audit::record($ctx, $auth, 'access.profile_unassigned', 'permission_assignment', (int) $id, [
            'user_uuid'    => $assignment['user_uuid'],
            'profile_name' => $assignment['profile_name'],
        ], null);

        Permissions::forget();
        Http::data(['deleted' => true, 'assignment_id' => (int) $id]);
    }

    // -----------------------------------------------------------------------
    // The rules
    // -----------------------------------------------------------------------

    /**
     * Every permission must exist, and the caller must be able to grant it.
     *
     * @return list<string>
     */
    private static function validatePermissions(mixed $raw, \Aicountly\Api\Context $ctx, \Aicountly\Api\Auth $auth): array
    {
        if (!is_array($raw)) {
            Http::validationFailed('Permissions must be a list.', ['field' => 'permissions']);
        }

        $wanted = [];
        $unknown = [];
        foreach ($raw as $permission) {
            if (!is_string($permission) || $permission === '') {
                continue;
            }
            if (!Permissions::exists($permission)) {
                $unknown[] = $permission;
                continue;
            }
            $wanted[$permission] = true;
        }

        if ($unknown !== []) {
            Http::validationFailed(
                'These are not permissions this product has: ' . implode(', ', array_slice($unknown, 0, 5)) . '.',
                ['field' => 'permissions', 'unknown' => array_values($unknown)],
            );
        }
        if ($wanted === []) {
            Http::validationFailed('A profile with no permissions grants nothing. Choose at least one.', ['field' => 'permissions']);
        }

        self::assertMayGrant(array_keys($wanted), $ctx, $auth);

        return array_keys($wanted);
    }

    /** @param list<mixed> $permissions */
    private static function assertMayGrant(array $permissions, \Aicountly\Api\Context $ctx, \Aicountly\Api\Auth $auth): void
    {
        $grantable = Permissions::grantable($ctx, $auth);
        $beyond = array_values(array_diff(
            array_values(array_filter($permissions, static fn ($p) => is_string($p))),
            $grantable,
        ));

        if ($beyond !== []) {
            Http::forbidden(
                'You can only grant permissions you hold yourself. These are beyond yours: '
                . implode(', ', array_slice($beyond, 0, 5)) . '. A company owner can grant them.',
            );
        }
    }

    /**
     * Rewriting a profile must not smuggle permissions past the escalation rule.
     *
     * Anything the profile already grants that the editor cannot grant has to
     * survive the edit untouched: they may add and remove within their own
     * permissions, and must leave the rest alone.
     *
     * @param array<string, mixed> $existing
     * @param list<string>         $next
     */
    private static function assertMayRewrite(array $existing, array $next, \Aicountly\Api\Context $ctx, \Aicountly\Api\Auth $auth): void
    {
        $grantable = Permissions::grantable($ctx, $auth);
        $current = array_values(array_filter(Db::jsonColumn($existing['permissions']), static fn ($p) => is_string($p)));

        $protected = array_values(array_diff($current, $grantable));
        $dropped = array_values(array_diff($protected, $next));

        if ($dropped !== []) {
            Http::forbidden(
                'This profile grants permissions you do not hold, so you cannot remove them: '
                . implode(', ', array_slice($dropped, 0, 5)) . '. A company owner can.',
            );
        }
    }

    /**
     * Refuse the edit that leaves a company with no administrator.
     *
     * The owner is exempt because the owner is the recovery path: their access
     * comes from the portal and nothing here can take it away.
     *
     * @param array<string, mixed> $assignment
     */
    private static function assertNotLockingSelfOut(array $assignment, \Aicountly\Api\Context $ctx, \Aicountly\Api\Auth $auth): void
    {
        if ($auth->ownsCompany($ctx->cmpId) || (string) $assignment['user_uuid'] !== $auth->uuid) {
            return;
        }

        $permissions = array_values(array_filter(Db::jsonColumn($assignment['permissions']), static fn ($p) => is_string($p)));
        if (!in_array('access.manage', $permissions, true)) {
            return;
        }

        $others = (int) Db::scalar(
            "SELECT COUNT(*)
             FROM " . Permissions::TABLE_ASSIGNMENTS . ' a
             JOIN ' . Permissions::TABLE_PROFILES . " p ON p.profile_id = a.profile_id
             WHERE a.cmp_id = :cmp AND a.user_uuid = :uuid AND a.assignment_id <> :id
               AND p.is_active = TRUE AND p.permissions @> '[\"access.manage\"]'::jsonb",
            ['cmp' => $ctx->cmpId, 'uuid' => $auth->uuid, 'id' => (int) $assignment['assignment_id']],
        );

        if ($others === 0) {
            Http::conflict(
                'That is your own last grant of access management. Removing it would leave you unable to '
                . 'undo it — give somebody else this permission first, or ask the company owner, who always has it.',
            );
        }
    }

    // -----------------------------------------------------------------------

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function presentProfile(array $row): array
    {
        if ($row === []) {
            return [];
        }

        $permissions = array_values(array_filter(Db::jsonColumn($row['permissions'] ?? null), static fn ($p) => is_string($p)));

        return [
            'profile_id'   => (int) $row['profile_id'],
            'profile_name' => $row['profile_name'],
            'description'  => $row['description'],
            'permissions'  => $permissions,
            'permission_count' => count($permissions),
            'is_active'    => (bool) $row['is_active'],
            'is_system'    => (bool) ($row['is_system'] ?? false),
            'system_key'   => $row['system_key'] ?? null,
            'member_count' => (int) ($row['member_count'] ?? 0),
            'created_by'   => $row['created_by'] ?? null,
            'updated_at'   => $row['updated_at'] ?? null,
        ];
    }

    private static function text(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : mb_substr($trimmed, 0, 200);
    }
}
