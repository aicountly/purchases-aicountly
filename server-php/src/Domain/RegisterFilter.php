<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain;

use Aicountly\Api\Http;

/**
 * Quick filters and advanced filters for the register-style lists (bills, orders, returns,
 * claims, requisitions), the same questions Books' Purchase register answers:
 *
 *   gst=<key>[,<key>…]       GST quick filters (see GST_GROUPS); bills only
 *   amount_min, amount_max   the amount the list shows for a row
 *
 * The supplier filter is each list's own `supplier_account_id`, which every one of them already
 * took before this; it is not repeated here.
 *
 * QUICK FILTERS COMBINE THE WAY A USER READS THEM: keys in the same group are alternatives (OR),
 * groups narrow each other (AND) — Books' rule, so a link means the same thing in both products.
 *
 * A KEY THIS PRODUCT CANNOT ANSWER IS REPORTED, NOT GUESSED. Most of Books' keys need facts that
 * live in Books and are not kept here: the supplier's GST registration (registered, composition,
 * SEZ, overseas) is on their Books ledger, read live when a bill is posted (PlaceOfSupply); the
 * place of supply is decided from it at that moment, and for a branch the home state is the
 * branch's GSTIN in Books; whether a line is nil-rated or exempt is the Books tax category's
 * nature, of which a bill line carries only the id. A list of every bill here has no answer to
 * "local" for a bill not yet posted, and a filter that quietly left those out would pass for one.
 * So a register names the keys it can answer from its own rows (`$gstPredicates`); any other
 * known key that arrives (a link copied from Books, a hand-edited URL) is left off the query and
 * returned as unavailable, so the screen says why instead of listing everything under a chip that
 * did nothing. Unknown keys are typos and are dropped.
 *
 * Every predicate is SQL on the list's own query, so the count, the pages and the rows all
 * describe the filtered set.
 */
final class RegisterFilter
{
    public const GST_LOCAL = 'local';
    public const GST_INTERSTATE = 'interstate';
    /** Imports, on this (inward) side. */
    public const GST_OVERSEAS = 'overseas';
    public const GST_REGISTERED = 'registered';
    public const GST_UNREGISTERED = 'unregistered';
    public const GST_COMPOSITION = 'composition';
    public const GST_SEZ = 'sez';
    public const GST_EXEMPT = 'exempt';
    public const GST_RCM = 'rcm';

    /** @var array<string, list<string>> group => keys; OR inside a group, AND across groups. */
    public const GST_GROUPS = [
        'region' => [self::GST_LOCAL, self::GST_INTERSTATE, self::GST_OVERSEAS],
        'party'  => [self::GST_REGISTERED, self::GST_UNREGISTERED, self::GST_COMPOSITION, self::GST_SEZ],
        'tax'    => [self::GST_EXEMPT, self::GST_RCM],
    ];

    /**
     * @param list<string> $gst the keys applied, in declared order
     * @param list<string> $gstUnavailable known keys that were asked for and this register cannot answer
     * @param array<string, string> $gstPredicates key => SQL condition on the list's own row
     */
    private function __construct(
        public readonly array $gst = [],
        public readonly array $gstUnavailable = [],
        public readonly ?string $amountMin = null,
        public readonly ?string $amountMax = null,
        private readonly array $gstPredicates = [],
    ) {
    }

    public static function none(): self
    {
        return new self();
    }

    /**
     * Read the filters off the request.
     *
     * @param array<string, mixed> $params gst, amount_min, amount_max as the request carried them
     * @param array<string, string> $gstPredicates the keys this register answers, each as SQL. A
     *        constant of the calling service, never request input.
     */
    public static function fromParams(array $params, array $gstPredicates = []): self
    {
        $asked = self::parseKeys($params['gst'] ?? '', self::gstKeys());
        $gst = array_values(array_filter($asked, static fn (string $k): bool => isset($gstPredicates[$k])));
        $unavailable = array_values(array_filter($asked, static fn (string $k): bool => !isset($gstPredicates[$k])));
        [$min, $max] = self::parseAmountRange($params['amount_min'] ?? null, $params['amount_max'] ?? null);

        return new self($gst, $unavailable, $min, $max, $gstPredicates);
    }

    /**
     * fromParams() over this request's query string.
     *
     * @param array<string, string> $gstPredicates
     */
    public static function fromRequest(array $gstPredicates = []): self
    {
        return self::fromParams([
            'gst'        => Http::param('gst'),
            'amount_min' => Http::param('amount_min'),
            'amount_max' => Http::param('amount_max'),
        ], $gstPredicates);
    }

    /**
     * What a list's meta carries under `filters`: this filter and the supplier the list was
     * narrowed to (each list's own supplier_account_id), so the screen shows what was applied.
     *
     * @return array<string, mixed>
     */
    public function metaWithSupplier(?int $supplierId): array
    {
        return $this->toMeta() + ['supplier_account_id' => $supplierId !== null && $supplierId > 0 ? $supplierId : null];
    }

    /** @return list<string> every GST key, in declared order */
    public static function gstKeys(): array
    {
        return array_merge(...array_values(self::GST_GROUPS));
    }

    /**
     * @param list<string> $allowed
     * @return list<string> the recognised keys, in their declared order, each once
     */
    public static function parseKeys(mixed $raw, array $allowed): array
    {
        $picked = [];
        foreach (explode(',', strtolower(is_array($raw) ? implode(',', array_filter($raw, 'is_scalar')) : (is_scalar($raw) ? (string) $raw : ''))) as $part) {
            $part = trim($part);
            if ($part !== '') {
                $picked[$part] = true;
            }
        }

        return array_values(array_filter($allowed, static fn (string $k): bool => isset($picked[$k])));
    }

    /**
     * Non-negative amounts only; blank, garbage or negative means "no bound". Bounds entered the
     * wrong way round are swapped — "50,000 to 10,000" is a range, not an empty list. Kept as
     * decimal strings and compared as NUMERIC in SQL, so 0.10 is not 0.1000000000000000055.
     *
     * @return array{0: ?string, 1: ?string}
     */
    public static function parseAmountRange(mixed $rawMin, mixed $rawMax): array
    {
        $min = self::parseAmount($rawMin);
        $max = self::parseAmount($rawMax);
        if ($min !== null && $max !== null && self::compare($min, $max) > 0) {
            [$min, $max] = [$max, $min];
        }

        return [$min, $max];
    }

    /** "1,50,000" and "1 500.50" are amounts; "-5", "5e3", "abc" are not. */
    public static function parseAmount(mixed $raw): ?string
    {
        if (!is_scalar($raw)) {
            return null;
        }
        $value = str_replace([',', ' '], '', trim((string) $raw));
        if (preg_match('/^(\d+)(?:\.(\d+))?$/', $value, $m) !== 1) {
            return null;
        }
        $whole = ltrim($m[1], '0');
        $fraction = rtrim($m[2] ?? '', '0');

        return ($whole === '' ? '0' : $whole) . ($fraction === '' ? '' : '.' . $fraction);
    }

    public function hasAmountRange(): bool
    {
        return $this->amountMin !== null || $this->amountMax !== null;
    }

    /**
     * Narrow the list's query: one AND-ed condition per GST group asked for, and the amount bounds
     * on `$amountSql` — the same expression the list shows, so "above 50,000" means above the
     * figure on the screen.
     *
     * @param list<string> $where
     * @param array<string, mixed> $params
     */
    public function apply(array &$where, array &$params, string $amountSql): void
    {
        foreach (self::GST_GROUPS as $keys) {
            $group = [];
            foreach ($keys as $key) {
                if (in_array($key, $this->gst, true)) {
                    $group[] = '(' . $this->gstPredicates[$key] . ')';
                }
            }
            if ($group !== []) {
                $where[] = '(' . implode(' OR ', $group) . ')';
            }
        }
        if ($this->amountMin !== null) {
            $where[] = '(' . $amountSql . ') >= CAST(:rf_amount_min AS NUMERIC)';
            $params['rf_amount_min'] = $this->amountMin;
        }
        if ($this->amountMax !== null) {
            $where[] = '(' . $amountSql . ') <= CAST(:rf_amount_max AS NUMERIC)';
            $params['rf_amount_max'] = $this->amountMax;
        }
    }

    /**
     * What the list's meta reports back, so the screen lights only the chips the server applied
     * and says which it could not.
     *
     * @return array{gst: list<string>, gst_unavailable: list<string>, amount_min: ?string, amount_max: ?string}
     */
    public function toMeta(): array
    {
        return [
            'gst'             => $this->gst,
            'gst_unavailable' => $this->gstUnavailable,
            'amount_min'      => $this->amountMin,
            'amount_max'      => $this->amountMax,
        ];
    }

    /** Two canonical non-negative decimal strings (parseAmount's), compared without floats. */
    private static function compare(string $a, string $b): int
    {
        [$aWhole, $aFraction] = array_pad(explode('.', $a, 2), 2, '');
        [$bWhole, $bFraction] = array_pad(explode('.', $b, 2), 2, '');
        if (strlen($aWhole) !== strlen($bWhole)) {
            return strlen($aWhole) <=> strlen($bWhole);
        }
        $width = max(strlen($aFraction), strlen($bFraction));

        return strcmp($aWhole . str_pad($aFraction, $width, '0'), $bWhole . str_pad($bFraction, $width, '0')) <=> 0;
    }
}
