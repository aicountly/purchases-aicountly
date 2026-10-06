<?php

declare(strict_types=1);

namespace Aicountly\Api\Analytics;

use Aicountly\Api\Auth;
use Aicountly\Api\Context;
use Aicountly\Api\Http;
use Aicountly\Api\Permissions;

/**
 * The rules every read-only summary for AICOUNTLY Insights follows
 * (Insights alignment 2026-10, CONTRACTS.md "Common rules" and §7).
 *
 *   - Read-only. Nothing here writes a row, a profile or a setting.
 *   - The caller is the signed-in person; Controller::enter() has already checked
 *     the session with the portal and the company with Manage.
 *   - An unknown parameter is a 400 that names it — never silently ignored.
 *   - `from`/`to` are required calendar dates; an invalid date or from > to is a 400.
 *   - Money is a decimal string (computed in PostgreSQL NUMERIC, 4 places), never a float.
 *   - Every list carries meta.total / meta.returned / meta.truncated, and every figure
 *     says its basis in meta.basis, so Insights never mixes an operational figure with
 *     Books' accounting one.
 *   - A permission refusal is a 403 whose body names the permission.
 */
final class InsightsRequest
{
    public const API_VERSION = '1';

    /** The scope every request carries (Context::fromRequest reads them). */
    public const SCOPE_PARAMS = ['cmp_id', 'fy_id', 'bo_id'];

    /** Longest range a summary is computed over in one call. */
    public const MAX_SPAN_DAYS = 400;

    /**
     * Refuse any query parameter this endpoint does not take, naming it.
     *
     * @param list<string> $extra parameters beyond the scope and the period
     */
    public static function onlyParams(array $extra = [], bool $period = true): void
    {
        $allowed = array_merge(self::SCOPE_PARAMS, $period ? ['from', 'to'] : [], $extra);
        foreach (array_keys($_GET) as $name) {
            $name = (string) $name;
            if (!in_array($name, $allowed, true)) {
                self::badRequest('unknown_parameter', 'This endpoint does not take the parameter "' . $name . '".', [
                    'parameter' => $name,
                    'allowed'   => array_values($allowed),
                ]);
            }
        }
    }

    /**
     * The required period, validated.
     *
     * @return array{from: string, to: string}
     */
    public static function period(): array
    {
        $out = [];
        foreach (['from', 'to'] as $name) {
            $value = isset($_GET[$name]) && is_scalar($_GET[$name]) ? trim((string) $_GET[$name]) : '';
            if ($value === '') {
                self::badRequest('missing_parameter', 'The parameter "' . $name . '" is required (YYYY-MM-DD).', ['parameter' => $name]);
            }
            if (!self::isDate($value)) {
                self::badRequest('invalid_parameter', 'The parameter "' . $name . '" is not a calendar date (YYYY-MM-DD).', ['parameter' => $name]);
            }
            $out[$name] = $value;
        }
        if (strcmp($out['from'], $out['to']) > 0) {
            self::badRequest('invalid_period', '"from" is after "to".', ['parameter' => 'from']);
        }
        $days = (int) (new \DateTimeImmutable($out['from']))->diff(new \DateTimeImmutable($out['to']))->days + 1;
        if ($days > self::MAX_SPAN_DAYS) {
            self::badRequest('period_too_long', 'The period is longer than ' . self::MAX_SPAN_DAYS . ' days; ask for it in parts.', [
                'parameter' => 'to', 'max_days' => self::MAX_SPAN_DAYS,
            ]);
        }

        return ['from' => $out['from'], 'to' => $out['to']];
    }

    /** 403 that names the permission, as the contract asks. */
    public static function requirePermission(Context $ctx, Auth $auth, string $permission): void
    {
        if (Permissions::allows($ctx, $auth, $permission)) {
            return;
        }
        $message = 'This summary needs the "' . $permission . '" permission in this product.';
        Http::json(403, [
            'error'   => ['code' => 'forbidden', 'message' => $message, 'permission' => $permission],
            'message' => $message,
        ]);
    }

    /**
     * The envelope: {data, meta}. meta always carries the scope and the API version.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $meta
     */
    public static function respond(Context $ctx, array $data, array $meta): never
    {
        Http::json(200, [
            'data' => $data,
            'meta' => $meta + [
                'scope'       => $ctx->asQuery(),
                'read_only'   => true,
                'api_version' => self::API_VERSION,
            ],
        ]);
    }

    /**
     * The list meta: nothing is paged here, so returned = total and nothing is truncated.
     *
     * @param list<mixed> $rows
     * @return array{total: int, returned: int, truncated: bool}
     */
    public static function listMeta(array $rows): array
    {
        return ['total' => count($rows), 'returned' => count($rows), 'truncated' => false];
    }

    /** @param array<string, mixed> $details */
    public static function badRequest(string $code, string $message, array $details = []): never
    {
        Http::json(400, [
            'error'   => ['code' => $code, 'message' => $message] + (isset($details['parameter']) ? ['parameter' => $details['parameter']] : []) + ['details' => $details],
            'message' => $message,
        ]);
    }

    public static function isDate(string $value): bool
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return false;
        }
        [$y, $m, $d] = array_map('intval', explode('-', $value));

        return checkdate($m, $d, $y);
    }
}
