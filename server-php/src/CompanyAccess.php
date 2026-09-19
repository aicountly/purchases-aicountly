<?php

declare(strict_types=1);

namespace Aicountly\Api;

/**
 * Whether this session owns the company it is working in, per Aicountly Manage.
 *
 * WHY THIS EXISTS AT ALL. The obvious place to read a role is the session: ask
 * the portal who is calling and it tells you. It does not. my.aicountly.com's
 * validatesession answers with `status`, `uuid_aictly`, `aic_auth_id` and
 * `aic_ses_id`, and nothing else — it is pure authentication and has never held
 * a company id, let alone a role in one. Any product reading `acs_type` off that
 * payload reads null forever. Books does not read it either; it asks Manage and
 * normalises the answer, and this is the same job in this product's idiom.
 *
 * Ownership is per company, and Manage owns it. `GET /api/companyinfo` reports
 * it three ways for three generations of caller — `access_type` (1 = owner),
 * `ownership` ("owner" / "shared") and `is_creator` — so all three are read
 * here, most specific first.
 *
 * UNKNOWN IS NOT ZERO. A shape this does not recognise returns null, never 0.
 * Callers refuse on null, which is the safe reading, but they must still be able
 * to tell "Manage says you are not the owner" from "Manage never said" — the
 * second is a fault in this code, and it should be reported as one rather than
 * presented to the user as a company they have no rights in.
 */
final class CompanyAccess
{
    public const OWNER = 1;

    /**
     * Read the access type out of a Manage company payload.
     *
     * Accepts the whole `companyinfo` response, its `data` envelope, or a single
     * row from the `companies` list — the caller should not have to know which
     * of those it is holding.
     *
     * @param array<string, mixed> $payload
     */
    public static function fromPayload(array $payload): ?int
    {
        $direct = self::fromRow($payload);
        if ($direct !== null) {
            return $direct;
        }

        foreach (['data', 'company'] as $key) {
            $nested = $payload[$key] ?? null;
            if (is_array($nested) && !isset($nested[0])) {
                $found = self::fromRow($nested);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): ?int
    {
        foreach (['access_type', 'acs_type'] as $key) {
            if (array_key_exists($key, $row)) {
                $numeric = self::numeric($row[$key]);
                if ($numeric !== null) {
                    return $numeric;
                }
            }
        }

        foreach (['ownership', 'access', 'access_name', 'access_label'] as $key) {
            if (array_key_exists($key, $row)) {
                $label = self::label($row[$key]);
                if ($label !== null) {
                    return $label;
                }
            }
        }

        // Last, and only as a positive: `is_creator: false` means "you did not
        // create this company", which is not the same as "you are not its owner"
        // in every payload that carries it, so it is never read as a 0.
        if (self::truthy($row['is_creator'] ?? null)) {
            return self::OWNER;
        }

        return null;
    }

    private static function numeric(mixed $value): ?int
    {
        if ($value === null || $value === '' || is_array($value)) {
            return null;
        }
        if (is_bool($value)) {
            return $value ? self::OWNER : 0;
        }

        return is_numeric($value) ? (int) $value : null;
    }

    private static function label(mixed $value): ?int
    {
        if ($value === null || $value === '' || is_array($value)) {
            return null;
        }
        if (is_numeric($value)) {
            return (int) $value;
        }

        $label = strtolower(trim((string) $value));
        if ($label === 'owner' || $label === 'creator') {
            return self::OWNER;
        }

        return in_array($label, ['shared', 'delegated', 'member', 'user', 'viewer', 'editor'], true) ? 0 : null;
    }

    private static function truthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value === 1;
        }
        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 't'], true);
        }

        return false;
    }
}
