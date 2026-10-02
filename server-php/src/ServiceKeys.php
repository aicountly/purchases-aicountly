<?php

declare(strict_types=1);

namespace Aicountly\Api;

/**
 * Service keys other AICOUNTLY products present when they call this one.
 *
 * Configured as `SERVICE_KEYS=books:<key>,pos:<key>,billing:<key>` in api/.env.
 * The comparison is constant-time: a plain === on a secret leaks its length and,
 * over enough requests, its content.
 *
 * A key proves WHICH PRODUCT is calling. It does not make that product the owner of every
 * company with every permission — which is what it was until October 2026: a configured key
 * skipped the Manage check (Context::assertAllowed) and held every permission
 * (Permissions::allows), so one leaked key opened every company's procurement, approvals and
 * access administration. Two settings now bound it, the fleet pattern (sales-aicountly 691c407,
 * crm-aicountly ServiceKeys::allowsCompany), stricter here on companies:
 *
 *   SERVICE_KEY_COMPANIES=insights:12|15
 *        the Manage company ids a product key may act for. REQUIRED: a product with no entry
 *        may act for no company at all — there is no session to ask Manage with, so the
 *        binding is the only tenant check a key has. There is no wildcard.
 *
 *   SERVICE_KEY_PERMISSIONS=insights:po.view|reports.view
 *        what a product key may do, as this product's permission codes. A product NOT listed
 *        is READ-ONLY (DEFAULT_PERMISSIONS). Codes this product does not define are dropped, and
 *        NEVER is removed whatever is listed: administration, and every decision segregation of
 *        duties reserves for a person.
 *
 * No product calls Purchases with a key today (Email, Insights and Connect send the person's own
 * session), so these bounds break no caller; they decide what a key could do the day one is set.
 */
final class ServiceKeys
{
    /** What a product key may do when SERVICE_KEY_PERMISSIONS says nothing about it: read documents. */
    public const DEFAULT_PERMISSIONS = ['requisition.view', 'rfq.view', 'po.view', 'match.view', 'supplier.view'];

    /**
     * Never held by a product key, whatever SERVICE_KEY_PERMISSIONS lists.
     *
     * Administration (who may do what, the company's settings); approvals and the other
     * decisions this product's segregation of duties gives a person — a key acts for an actor it
     * merely names (X-Actor-Uuid), so "not your own order" cannot hold for it; and everything that
     * posts to Books, which takes vouchers only on a person's session.
     */
    public const NEVER = [
        'access.manage', 'settings.manage',
        'requisition.approve', 'po.approve', 'po.amend', 'po.cancel', 'po.close', 'price.override',
        'rfq.award', 'receipt.over_tolerance', 'match.resolve',
        'bill.post', 'return.approve', 'return.financial_adjustment', 'claim.settle', 'supplier.approve',
    ];

    /** The calling product's name, or null when the key is unknown. */
    public static function resolveApp(string $presented): ?string
    {
        $presented = trim($presented);
        if ($presented === '') {
            return null;
        }

        foreach (self::configured() as $app => $key) {
            if (hash_equals($key, $presented)) {
                return $app;
            }
        }

        return null;
    }

    /** Whether a product key may act for this company: only one SERVICE_KEY_COMPANIES lists for it. */
    public static function allowsCompany(string $app, int $cmpId): bool
    {
        $ids = self::listFor('SERVICE_KEY_COMPANIES', $app);
        if ($ids === null || $cmpId <= 0) {
            return false;
        }

        return in_array($cmpId, array_map('intval', array_filter($ids, static fn (string $v) => ctype_digit($v))), true);
    }

    /**
     * The permissions a product key holds here.
     *
     * @return list<string>
     */
    public static function permissions(string $app): array
    {
        $listed = self::listFor('SERVICE_KEY_PERMISSIONS', $app) ?? self::DEFAULT_PERMISSIONS;

        return array_values(array_unique(array_filter(
            $listed,
            static fn (string $p) => Permissions::exists($p) && !in_array($p, self::NEVER, true),
        )));
    }

    /**
     * `app:a|b,other:c` → ['a', 'b'] for `app`, or null when `app` is not listed.
     *
     * @return list<string>|null
     */
    private static function listFor(string $envKey, string $app): ?array
    {
        $raw = Env::get($envKey);
        if ($raw === '') {
            return null;
        }

        foreach (explode(',', $raw) as $pair) {
            $pair = trim($pair);
            if ($pair === '' || !str_contains($pair, ':')) {
                continue;
            }
            [$name, $values] = explode(':', $pair, 2);
            if (strtolower(trim($name)) !== strtolower($app)) {
                continue;
            }

            return array_values(array_filter(array_map('trim', explode('|', $values)), static fn (string $v) => $v !== ''));
        }

        return null;
    }

    /** @return array<string, string> */
    private static function configured(): array
    {
        $raw = Env::get('SERVICE_KEYS');
        if ($raw === '') {
            return [];
        }

        $out = [];
        foreach (explode(',', $raw) as $pair) {
            $pair = trim($pair);
            if ($pair === '' || !str_contains($pair, ':')) {
                continue;
            }
            [$app, $key] = explode(':', $pair, 2);
            $app = strtolower(trim($app));
            $key = trim($key);
            // A placeholder left in a deployed .env must not authenticate anything.
            if ($app === '' || $key === '' || str_starts_with($key, 'CHANGE_ME')) {
                continue;
            }
            $out[$app] = $key;
        }

        return $out;
    }
}
