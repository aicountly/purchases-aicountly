<?php

declare(strict_types=1);

namespace Aicountly\Api\Controllers;

use Aicountly\Api\Db;
use Aicountly\Api\Http;
use Aicountly\Api\Permissions;

final class SettingsController extends Controller
{
    /**
     * The kinds of procurement a purchase profile can describe.
     *
     * Here rather than in the browser because a value that reaches the database
     * is the server's to decide. The UI reads this list from `show()` and
     * renders whatever it is given, so adding a sixth kind is one line here and
     * no front-end release at all.
     */
    private const PROFILE_TYPES = [
        'STANDARD'    => 'Standard Procurement',
        'SERVICES'    => 'Services Procurement',
        'CAPEX'       => 'Capital Expenditure',
        'SUBCONTRACT' => 'Subcontracting / Job Work',
        'IMPORT'      => 'Import Procurement',
    ];

    /** Columns a client may write, and how each one is cleaned. */
    private const TEXT_LIMITS = [
        'profile_code'       => 12,
        'profile_name'       => 120,
        'description'        => 500,
        'requisition_prefix' => 12,
        'rfq_prefix'         => 12,
        'po_prefix'          => 12,
        'return_prefix'      => 12,
        'claim_prefix'       => 12,
    ];

    private const PREFIX_FIELDS = [
        'requisition_prefix' => 'Requisition prefix',
        'rfq_prefix'         => 'RFQ prefix',
        'po_prefix'          => 'Purchase order prefix',
        'return_prefix'      => 'Return prefix',
        'claim_prefix'       => 'Claim prefix',
    ];

    private const AMOUNT_FIELDS = [
        'po_approval_above_amount'          => 'Purchase order approval limit',
        'requisition_approval_above_amount' => 'Requisition approval limit',
    ];

    private const FLAG_FIELDS = [
        'enforce_approved_vendors',
        'block_bill_on_match_failure',
        'is_active',
    ];

    public static function session(): void
    {
        [$auth, $ctx] = self::enter();

        Http::data([
            'uuid'         => $auth->uuid,
            'display_name' => $auth->displayName(),
            'kind'         => $auth->kind,
            'is_owner'     => $auth->ownsCompany($ctx->cmpId),
            // Whether Manage named a role at all. A client that only knows
            // `is_owner: false` cannot tell "you are a delegate here" from
            // "we could not find out", and it would render the same empty app
            // for both — which is precisely the failure this pair exists to
            // stop being invisible.
            'access_resolved' => $auth->companyAccessResolved($ctx->cmpId),
            'context'      => $ctx->asQuery(),
            'permissions'  => Permissions::granted($ctx, $auth),
        ]);
    }

    public static function permissions(): void
    {
        [$auth, $ctx] = self::enter();

        Http::data([
            'catalog' => Permissions::CATALOG,
            'granted' => Permissions::granted($ctx, $auth),
        ]);
    }

    public static function show(): void
    {
        [$auth, $ctx] = self::enter();

        $row = Db::first('SELECT * FROM purchase_settings WHERE cmp_id = :cmp', ['cmp' => $ctx->cmpId]);
        if ($row === null) {
            Db::insert('purchase_settings', ['cmp_id' => $ctx->cmpId], 'cmp_id');
            $row = Db::first('SELECT * FROM purchase_settings WHERE cmp_id = :cmp', ['cmp' => $ctx->cmpId]);
        }

        // `meta` rather than a second endpoint: the profile-type list and the
        // number format are what the SCREEN needs to render this row, and a
        // round trip per dropdown is a round trip the user waits for.
        Http::json(200, [
            'data' => $row ?? [],
            'meta' => [
                'profile_types' => self::profileTypeOptions(),
                // How a document number is actually built, so the screen can
                // show a true example instead of inventing one. NumberSeries
                // owns the format; this is that format, described.
                'number_format' => [
                    'pattern' => '{prefix}/{fy_id}/{0000}',
                    'fy_id'   => $ctx->fyId,
                    'example' => 'PR/' . $ctx->fyId . '/0001',
                ],
                'can_manage'    => Permissions::allows($ctx, $auth, 'settings.manage'),
            ],
        ]);
    }

    public static function update(): void
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'settings.manage');

        $body = Http::body();
        $changes = [];

        foreach (self::TEXT_LIMITS as $field => $limit) {
            if (!array_key_exists($field, $body)) {
                continue;
            }
            $value = trim((string) $body[$field]);

            if (mb_strlen($value) > $limit) {
                Http::validationFailed(
                    self::label($field) . ' is too long (' . $limit . ' characters at most).',
                    ['field' => $field],
                );
            }

            // A code is an identifier people type and compare, so it is stored
            // in one case rather than in whichever case it was typed in.
            if ($field === 'profile_code') {
                $value = mb_strtoupper($value);
                if ($value !== '' && preg_match('/^[A-Z0-9][A-Z0-9._-]*$/', $value) !== 1) {
                    Http::validationFailed(
                        'A profile code may use letters, digits, dot, dash and underscore, and must start with a letter or digit.',
                        ['field' => $field],
                    );
                }
            }

            // A prefix becomes part of every document number this product
            // issues, so it may not be blank and may not carry whitespace —
            // "PO 1/33/0001" is a number nobody can search for.
            if (isset(self::PREFIX_FIELDS[$field])) {
                if ($value === '') {
                    Http::validationFailed(self::label($field) . ' cannot be empty.', ['field' => $field]);
                }
                if (preg_match('/\s/u', $value) === 1) {
                    Http::validationFailed(self::label($field) . ' cannot contain spaces.', ['field' => $field]);
                }
            }

            $changes[$field] = $value;
        }

        if (array_key_exists('profile_type', $body)) {
            $type = mb_strtoupper(trim((string) $body['profile_type']));
            if ($type === '') {
                $type = 'STANDARD';
            }
            if (!isset(self::PROFILE_TYPES[$type])) {
                Http::validationFailed('That is not a profile type this product knows.', [
                    'field'   => 'profile_type',
                    'allowed' => array_keys(self::PROFILE_TYPES),
                ]);
            }
            $changes['profile_type'] = $type;
        }

        foreach (self::AMOUNT_FIELDS as $field => $label) {
            if (!array_key_exists($field, $body)) {
                continue;
            }
            $raw = $body[$field];
            $value = is_string($raw) ? trim($raw) : $raw;
            if ($value === '' || $value === null) {
                $value = 0;
            }
            if (!is_numeric($value)) {
                Http::validationFailed($label . ' must be a number.', ['field' => $field]);
            }
            if ((float) $value < 0) {
                Http::validationFailed($label . ' cannot be negative.', ['field' => $field]);
            }
            $changes[$field] = (float) $value;
        }

        foreach (self::FLAG_FIELDS as $field) {
            if (array_key_exists($field, $body)) {
                $changes[$field] = self::boolean($body[$field]);
            }
        }

        if (array_key_exists('default_warehouse_id', $body)) {
            $warehouse = $body['default_warehouse_id'];
            $changes['default_warehouse_id'] = ($warehouse === null || $warehouse === '') ? null : (int) $warehouse;
        }

        if ($changes !== []) {
            $changes['updated_at'] = gmdate('Y-m-d H:i:s');
            Db::run('INSERT INTO purchase_settings (cmp_id) VALUES (:cmp) ON CONFLICT (cmp_id) DO NOTHING', ['cmp' => $ctx->cmpId]);
            Db::update('purchase_settings', $changes, ['cmp_id' => $ctx->cmpId]);
        }

        self::show();
    }

    /** Three-way match tolerances. */
    public static function matchPolicies(): void
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'match.view');

        Http::data(Db::all(
            'SELECT * FROM purchase_match_policies WHERE cmp_id = :cmp ORDER BY is_default DESC, policy_name',
            ['cmp' => $ctx->cmpId],
        ));
    }

    /**
     * Create a policy, or edit one.
     *
     * `policy_id` in the body means edit THAT policy, including renaming it.
     * Without one the name is the identity, which is how this endpoint has
     * always behaved and what the upsert below preserves — a caller that has
     * never sent an id keeps getting exactly what it got before.
     */
    public static function saveMatchPolicy(): void
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'settings.manage');

        $body = Http::body();
        $name = trim((string) ($body['policy_name'] ?? ''));
        if ($name === '') {
            Http::validationFailed('A match policy needs a name.', ['field' => 'policy_name']);
        }
        if (mb_strlen($name) > 80) {
            Http::validationFailed('A policy name is 80 characters at most.', ['field' => 'policy_name']);
        }

        $values = [
            'qty_tolerance_pc'      => self::amount($body, 'qty_tolerance_pc'),
            'rate_tolerance_pc'     => self::amount($body, 'rate_tolerance_pc'),
            'value_tolerance_amt'   => self::amount($body, 'value_tolerance_amt'),
            'freight_tolerance_amt' => self::amount($body, 'freight_tolerance_amt'),
            'auto_match_below_amt'  => self::amount($body, 'auto_match_below_amt'),
            'is_default'            => self::boolean($body['is_default'] ?? false),
        ];

        foreach (['qty_tolerance_pc', 'rate_tolerance_pc'] as $field) {
            if ($values[$field] < 0 || $values[$field] > 100) {
                Http::validationFailed('A tolerance percentage must be between 0 and 100.', ['field' => $field]);
            }
        }
        foreach (['value_tolerance_amt', 'freight_tolerance_amt', 'auto_match_below_amt'] as $field) {
            if ($values[$field] < 0) {
                Http::validationFailed('A tolerance amount cannot be negative.', ['field' => $field]);
            }
        }

        $policyId = isset($body['policy_id']) && $body['policy_id'] !== '' ? (int) $body['policy_id'] : null;

        $existing = $policyId !== null
            ? Db::first(
                'SELECT policy_id FROM purchase_match_policies WHERE policy_id = :id AND cmp_id = :cmp',
                ['id' => $policyId, 'cmp' => $ctx->cmpId],
            )
            : Db::first(
                'SELECT policy_id FROM purchase_match_policies WHERE cmp_id = :cmp AND policy_name = :name',
                ['cmp' => $ctx->cmpId, 'name' => $name],
            );

        if ($policyId !== null && $existing === null) {
            Http::notFound('That match policy no longer exists.');
        }

        // UNIQUE (cmp_id, policy_name) would otherwise surface as a 500 on a
        // rename onto a name somebody else is already using.
        $clash = Db::first(
            'SELECT policy_id FROM purchase_match_policies WHERE cmp_id = :cmp AND policy_name = :name',
            ['cmp' => $ctx->cmpId, 'name' => $name],
        );
        if ($clash !== null && ($existing === null || (int) $clash['policy_id'] !== (int) $existing['policy_id'])) {
            Http::conflict('Another tolerance policy is already called "' . $name . '".', ['field' => 'policy_name']);
        }

        Db::transaction(function () use ($existing, $values, $name, $ctx) {
            if ($values['is_default']) {
                // Exactly one default, or the match engine picks arbitrarily.
                Db::run('UPDATE purchase_match_policies SET is_default = FALSE WHERE cmp_id = :cmp', ['cmp' => $ctx->cmpId]);
            }
            if ($existing === null) {
                Db::insert('purchase_match_policies', $values + ['cmp_id' => $ctx->cmpId, 'policy_name' => $name], 'policy_id');
            } else {
                Db::update(
                    'purchase_match_policies',
                    $values + ['policy_name' => $name],
                    ['policy_id' => (int) $existing['policy_id']],
                );
            }
        });

        self::matchPolicies();
    }

    /**
     * Retire a tolerance policy.
     *
     * Match RESULTS keep their reference — `purchase_match_results.policy_id`
     * is ON DELETE SET NULL, so a bill matched last quarter stays matched and
     * simply stops naming a policy that no longer exists. What was decided then
     * was decided under the rules in force then, and deleting a policy does not
     * get to rewrite that.
     */
    public static function deleteMatchPolicy(string $id): void
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'settings.manage');

        $policyId = (int) $id;
        $existing = Db::first(
            'SELECT policy_id FROM purchase_match_policies WHERE policy_id = :id AND cmp_id = :cmp',
            ['id' => $policyId, 'cmp' => $ctx->cmpId],
        );
        if ($existing === null) {
            Http::notFound('That match policy no longer exists.');
        }

        Db::run('DELETE FROM purchase_match_policies WHERE policy_id = :id AND cmp_id = :cmp', [
            'id'  => $policyId,
            'cmp' => $ctx->cmpId,
        ]);

        self::matchPolicies();
    }

    // -----------------------------------------------------------------------

    /** @return list<array{value:string, label:string}> */
    private static function profileTypeOptions(): array
    {
        $out = [];
        foreach (self::PROFILE_TYPES as $value => $label) {
            $out[] = ['value' => $value, 'label' => $label];
        }

        return $out;
    }

    /** @param array<string, mixed> $body */
    private static function amount(array $body, string $field): float
    {
        $raw = $body[$field] ?? 0;
        $value = is_string($raw) ? trim($raw) : $raw;
        if ($value === '' || $value === null) {
            return 0.0;
        }
        if (!is_numeric($value)) {
            Http::validationFailed('That tolerance must be a number.', ['field' => $field]);
        }

        return (float) $value;
    }

    /**
     * JSON gives booleans, HTML forms give "true"/"on"/"1".
     *
     * A bare `(bool)` cast reads the string "false" as true, which is how a
     * checkbox somebody had just cleared came back ticked.
     */
    private static function boolean(mixed $raw): bool
    {
        if (is_bool($raw)) {
            return $raw;
        }
        if (is_string($raw)) {
            return in_array(strtolower(trim($raw)), ['1', 'true', 'yes', 'on'], true);
        }

        return (bool) $raw;
    }

    private static function label(string $field): string
    {
        return self::PREFIX_FIELDS[$field] ?? match ($field) {
            'profile_code' => 'Profile code',
            'profile_name' => 'Profile name',
            'description'  => 'Description',
            default        => 'That field',
        };
    }
}
