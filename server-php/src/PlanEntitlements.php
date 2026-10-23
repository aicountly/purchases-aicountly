<?php

declare(strict_types=1);

namespace Aicountly\Api;

/**
 * The company's plan for Purchases, as Manage states it, and the limit Purchases enforces from it.
 *
 * Manage's companyinfo (asked naming Purchases, X-Saas-Origin: purchases) carries, for a Manage that
 * has plans (CONTRACTS §3.5):
 *
 *   "plan": {"code","label","is_default","status","ends_at","entitlements": {"<code>": <value>, …}}
 *
 * (entitlements may also arrive as a list [{code, value}]; both are read). Values are data in
 * Engage, never code here (§3.6). Purchases enforces:
 *
 *   purchases.max_purchase_orders_per_month   new purchase orders the company raises in a calendar
 *                                             month (IST): POST v1/purchase-orders. An amendment (a
 *                                             new version of an existing order) is not a new order.
 *
 * An over-limit create answers 402 PLAN_LIMIT_REACHED {code, limit, used}. Nothing existing is
 * touched: reads, exports, approvals, receipts, bills, returns and everything already recorded stay
 * as they are; only a new purchase order beyond the limit is refused.
 *
 * Unlimited unless a limit is stated: no `plan` (a Manage that predates plans, a product's service
 * key — which carries no person and no plan), no entitlements, an absent code, or a value that is
 * not a whole number ≥ 0 — today's behaviour.
 */
final class PlanEntitlements
{
    public const ERROR_CODE = 'PLAN_LIMIT_REACHED';

    public const MAX_PURCHASE_ORDERS_PER_MONTH = 'purchases.max_purchase_orders_per_month';

    /** The calendar a "month" is counted in. */
    private const TIMEZONE = 'Asia/Kolkata';

    /** @var array<string, array{0: string, 1: string}> */
    private const NOUNS = [
        self::MAX_PURCHASE_ORDERS_PER_MONTH => ['purchase order a month', 'purchase orders a month'],
    ];

    /**
     * The plan companyinfo carried, normalised; null when Manage said nothing about plans.
     *
     * @param array<string, mixed> $row  companyinfo's company row
     * @param array<mixed> $body         the whole answer
     * @return array{code: string, label: string, is_default: bool, status: ?string, ends_at: ?string, entitlements: array<string, mixed>}|null
     */
    public static function fromCompanyInfo(array $row, array $body = []): ?array
    {
        foreach ([$row, is_array($body['data'] ?? null) ? $body['data'] : [], $body] as $source) {
            if (is_array($source) && array_key_exists('plan', $source)) {
                return self::normalize($source['plan']);
            }
        }

        return null;
    }

    /**
     * @return array{code: string, label: string, is_default: bool, status: ?string, ends_at: ?string, entitlements: array<string, mixed>}|null
     */
    public static function normalize(mixed $plan): ?array
    {
        if (!is_array($plan) || ($plan !== [] && array_is_list($plan))) {
            return null;
        }
        $entitlements = [];
        $raw = $plan['entitlements'] ?? [];
        if (is_array($raw)) {
            if (array_is_list($raw)) {
                foreach ($raw as $e) {
                    if (is_array($e) && is_string($e['code'] ?? null) && $e['code'] !== '') {
                        $entitlements[$e['code']] = $e['value'] ?? null;
                    }
                }
            } else {
                foreach ($raw as $code => $value) {
                    if (is_string($code) && $code !== '') {
                        $entitlements[$code] = $value;
                    }
                }
            }
        }

        return [
            'code'         => is_scalar($plan['code'] ?? null) ? (string) $plan['code'] : '',
            'label'        => is_scalar($plan['label'] ?? null) ? (string) $plan['label'] : '',
            'is_default'   => ($plan['is_default'] ?? false) === true,
            'status'       => is_string($plan['status'] ?? null) ? $plan['status'] : null,
            'ends_at'      => is_string($plan['ends_at'] ?? null) ? $plan['ends_at'] : null,
            'entitlements' => $entitlements,
        ];
    }

    /** The numeric limit for $code, or null for "no limit". */
    public static function limit(?array $plan, string $code): ?int
    {
        if ($plan === null || !is_array($plan['entitlements'] ?? null) || !array_key_exists($code, $plan['entitlements'])) {
            return null;
        }
        $value = $plan['entitlements'][$code];
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }
        if (is_float($value) && $value >= 0 && floor($value) === $value) {
            return (int) $value;
        }
        if (is_string($value) && preg_match('/^\d{1,9}$/', trim($value)) === 1) {
            return (int) trim($value);
        }

        return null;
    }

    /**
     * Whether a feature (`feature.<name>`) is on. Absent = on (§3.6); only an explicit false / 0 /
     * "false" turns it off.
     */
    public static function allows(?array $plan, string $featureCode): bool
    {
        if ($plan === null || !is_array($plan['entitlements'] ?? null) || !array_key_exists($featureCode, $plan['entitlements'])) {
            return true;
        }
        $value = $plan['entitlements'][$featureCode];

        return !($value === false || $value === 0 || (is_string($value) && in_array(strtolower(trim($value)), ['false', '0', 'off', 'no'], true)));
    }

    /**
     * The refusal's details when one more would go past the limit, otherwise null. $used is only
     * counted when a limit applies.
     *
     * @param int|callable(): int $used
     * @return array{code: string, limit: int, used: int}|null
     */
    public static function exceeded(?array $plan, string $code, int|callable $used): ?array
    {
        $limit = self::limit($plan, $code);
        if ($limit === null) {
            return null;
        }
        $count = is_callable($used) ? (int) $used() : $used;

        return $count >= $limit ? ['code' => $code, 'limit' => $limit, 'used' => $count] : null;
    }

    public static function message(string $code, int $limit): string
    {
        [$one, $many] = self::NOUNS[$code] ?? ['record', 'records'];

        return sprintf('Your plan allows %d %s — ask the account owner to upgrade in Manage.', $limit, $limit === 1 ? $one : $many);
    }

    /**
     * Ends the request with 402 PLAN_LIMIT_REACHED when the company's plan caps $code and one more
     * would go past it. A product's service key is never limited here (it carries no plan).
     *
     * @param callable(): int $used
     */
    public static function assertWithin(Context $ctx, Auth $auth, string $code, callable $used): void
    {
        if ($auth->isService()) {
            return;
        }
        $details = self::exceeded(Context::planFor($ctx->cmpId), $code, $used);
        if ($details !== null) {
            Http::error(402, self::ERROR_CODE, self::message($code, $details['limit']), $details);
        }
    }

    /** New purchase orders (version 0; an amendment is not a new order) the company raised this calendar month. */
    public static function purchaseOrdersThisMonth(int $cmpId): int
    {
        return (int) Db::scalar(
            'SELECT COUNT(*) FROM purchase_orders
              WHERE cmp_id = :cmp AND version_no = 0 AND created_at >= CAST(:start AS timestamptz)',
            ['cmp' => $cmpId, 'start' => self::monthStart()],
        );
    }

    private static function monthStart(): string
    {
        return (new \DateTimeImmutable('first day of this month 00:00:00', new \DateTimeZone(self::TIMEZONE)))->format(\DATE_ATOM);
    }
}
