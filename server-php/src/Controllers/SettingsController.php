<?php

declare(strict_types=1);

namespace Aicountly\Api\Controllers;

use Aicountly\Api\Db;
use Aicountly\Api\Http;
use Aicountly\Api\Permissions;

final class SettingsController extends Controller
{
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

        Http::data($row ?? []);
    }

    public static function update(): void
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'settings.manage');

        $body = Http::body();
        $allowed = [
            'requisition_prefix', 'rfq_prefix', 'po_prefix', 'return_prefix', 'claim_prefix',
            'po_approval_above_amount', 'requisition_approval_above_amount',
            'enforce_approved_vendors', 'block_bill_on_match_failure', 'default_warehouse_id',
        ];

        $changes = [];
        foreach ($allowed as $field) {
            if (array_key_exists($field, $body)) {
                $changes[$field] = $body[$field];
            }
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

    public static function saveMatchPolicy(): void
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'settings.manage');

        $body = Http::body();
        $name = trim((string) ($body['policy_name'] ?? ''));
        if ($name === '') {
            Http::validationFailed('A match policy needs a name.', ['field' => 'policy_name']);
        }

        $values = [
            'qty_tolerance_pc'      => (float) ($body['qty_tolerance_pc'] ?? 0),
            'rate_tolerance_pc'     => (float) ($body['rate_tolerance_pc'] ?? 0),
            'value_tolerance_amt'   => (float) ($body['value_tolerance_amt'] ?? 0),
            'freight_tolerance_amt' => (float) ($body['freight_tolerance_amt'] ?? 0),
            'auto_match_below_amt'  => (float) ($body['auto_match_below_amt'] ?? 0),
            'is_default'            => (bool) ($body['is_default'] ?? false),
        ];

        foreach (['qty_tolerance_pc', 'rate_tolerance_pc'] as $field) {
            if ($values[$field] < 0 || $values[$field] > 100) {
                Http::validationFailed('A tolerance percentage must be between 0 and 100.', ['field' => $field]);
            }
        }

        $existing = Db::first(
            'SELECT policy_id FROM purchase_match_policies WHERE cmp_id = :cmp AND policy_name = :name',
            ['cmp' => $ctx->cmpId, 'name' => $name],
        );

        Db::transaction(function () use ($existing, $values, $name, $ctx) {
            if ($values['is_default']) {
                // Exactly one default, or the match engine picks arbitrarily.
                Db::run('UPDATE purchase_match_policies SET is_default = FALSE WHERE cmp_id = :cmp', ['cmp' => $ctx->cmpId]);
            }
            if ($existing === null) {
                Db::insert('purchase_match_policies', $values + ['cmp_id' => $ctx->cmpId, 'policy_name' => $name], 'policy_id');
            } else {
                Db::update('purchase_match_policies', $values, ['policy_id' => (int) $existing['policy_id']]);
            }
        });

        self::matchPolicies();
    }
}
