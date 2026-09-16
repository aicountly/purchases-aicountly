<?php

declare(strict_types=1);

namespace Aicountly\Api\Dashboards;

/**
 * Exact decimal arithmetic on strings.
 *
 * Every money figure on a dashboard arrives as a PostgreSQL NUMERIC cast to
 * text, is added up here, and leaves as a string. It is never a PHP float and
 * never a JavaScript number, because both of those lose paise on the way: the
 * classic 0.1 + 0.2 is the small version of a payables total that disagrees
 * with the accounts by a rupee and costs somebody an afternoon.
 *
 * bcmath would do this, but it is a non-default extension and a dashboard that
 * silently falls back to floats when it is missing is worse than one that does
 * the arithmetic itself. This is integer string maths, which is always present.
 */
final class Decimal
{
    public const ZERO = '0';

    /** Normalise a value to a plain signed decimal string, or null if it is not one. */
    public static function parse(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_float($value)) {
            // A float has already lost what it was going to lose; keep enough
            // digits to represent a currency amount and move on.
            return self::clean(sprintf('%.4F', $value));
        }
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);
        if ($trimmed === '' || preg_match('/^-?\d+(\.\d+)?$/', $trimmed) !== 1) {
            return null;
        }

        return self::clean($trimmed);
    }

    /** Parse, or zero. For sums, where a missing term contributes nothing. */
    public static function of(mixed $value): string
    {
        return self::parse($value) ?? self::ZERO;
    }

    public static function add(string $a, string $b): string
    {
        [$ai, $bi, $scale] = self::align($a, $b);

        return self::rescale(self::intAdd($ai, $bi), $scale);
    }

    public static function sub(string $a, string $b): string
    {
        return self::add($a, self::negate($b));
    }

    public static function negate(string $a): string
    {
        $a = self::clean($a);
        if (self::isZero($a)) {
            return self::ZERO;
        }

        return str_starts_with($a, '-') ? substr($a, 1) : '-' . $a;
    }

    /** -1, 0 or 1. */
    public static function cmp(string $a, string $b): int
    {
        [$ai, $bi] = self::align($a, $b);

        return self::intCmp($ai, $bi);
    }

    public static function isZero(string $a): bool
    {
        return preg_match('/[1-9]/', $a) !== 1;
    }

    public static function isNegative(string $a): bool
    {
        return str_starts_with(self::clean($a), '-') && !self::isZero($a);
    }

    public static function max(string $a, string $b): string
    {
        return self::cmp($a, $b) >= 0 ? self::clean($a) : self::clean($b);
    }

    /** @param list<string> $values */
    public static function sum(array $values): string
    {
        $total = self::ZERO;
        foreach ($values as $value) {
            $total = self::add($total, $value);
        }

        return $total;
    }

    /** Multiply by an integer count. Enough for "this many lines at this rate". */
    public static function mulInt(string $a, int $factor): string
    {
        $result = self::ZERO;
        $abs = abs($factor);
        for ($i = 0; $i < $abs; $i++) {
            $result = self::add($result, $a);
        }

        return $factor < 0 ? self::negate($result) : $result;
    }

    /**
     * a x b, exactly.
     *
     * Schoolbook multiplication on the integer forms, so quantity x rate is the
     * same number a ledger would reach. The result carries the sum of both
     * scales and is not rounded here: the caller decides where to round, once.
     */
    public static function mul(string $a, string $b): string
    {
        $a = self::clean($a);
        $b = self::clean($b);
        if (self::isZero($a) || self::isZero($b)) {
            return self::ZERO;
        }

        $negative = self::isNegative($a) !== self::isNegative($b);
        [$ai, $af] = self::split(ltrim($a, '-'));
        [$bi, $bf] = self::split(ltrim($b, '-'));
        $scale = strlen($af) + strlen($bf);

        $product = self::magMul($ai . $af, $bi . $bf);
        $result = self::rescale($product, $scale);

        return $negative ? self::negate($result) : $result;
    }

    /**
     * a / b, to `$scale` decimal places, half-up. Null when b is zero.
     *
     * Division by zero is not "0%" and is not "unchanged"; it is a comparison
     * that cannot be made, and the caller must say so rather than print a
     * number nobody can defend.
     */
    public static function div(string $a, string $b, int $scale = 4): ?string
    {
        if (self::isZero($b)) {
            return null;
        }

        [$ai, $bi] = self::align($a, $b);

        $negative = (str_starts_with($ai, '-') !== str_starts_with($bi, '-'));
        $ai = ltrim($ai, '-');
        $bi = ltrim($bi, '-');

        // One extra digit, then round half-up on it.
        $shifted = $ai . str_repeat('0', $scale + 1);
        $quotient = self::intDivide($shifted, $bi);

        $lastDigit = (int) substr($quotient, -1);
        $quotient = substr($quotient, 0, -1);
        $quotient = $quotient === '' ? '0' : $quotient;
        if ($lastDigit >= 5) {
            $quotient = self::intAdd($quotient, '1');
        }

        $result = self::rescale($quotient, $scale);

        return $negative ? self::negate($result) : $result;
    }

    /** (value / base) * 100, to `$scale` places. Null when base is zero. */
    public static function percentOf(string $value, string $base, int $scale = 2): ?string
    {
        $ratio = self::div($value, $base, $scale + 2);
        if ($ratio === null) {
            return null;
        }

        return self::round(self::shift($ratio, 2), $scale);
    }

    /** ((now - before) / |before|) * 100. Null when there is no baseline to compare against. */
    public static function percentChange(string $before, string $now, int $scale = 1): ?string
    {
        if (self::isZero($before)) {
            return null;
        }
        $base = self::isNegative($before) ? self::negate($before) : $before;

        return self::percentOf(self::sub($now, $before), $base, $scale);
    }

    /** Move the decimal point right by `$places`. Exact: no division involved. */
    public static function shift(string $a, int $places): string
    {
        [$int, $frac, $sign] = self::split(self::clean($a));

        if ($places >= 0) {
            $frac = str_pad($frac, $places, '0');
            $int .= substr($frac, 0, $places);
            $frac = substr($frac, $places);
        } else {
            $take = -$places;
            $int = str_pad($int, $take, '0', STR_PAD_LEFT);
            $frac = substr($int, strlen($int) - $take) . $frac;
            $int = substr($int, 0, strlen($int) - $take);
        }

        return self::clean($sign . ($int === '' ? '0' : $int) . ($frac === '' ? '' : '.' . $frac));
    }

    /** Round half-up to `$scale` decimal places. */
    public static function round(string $a, int $scale): string
    {
        [$int, $frac, $sign] = self::split(self::clean($a));
        if (strlen($frac) <= $scale) {
            return self::clean($sign . $int . ($frac === '' ? '' : '.' . $frac));
        }

        $keep = substr($frac, 0, $scale);
        $next = (int) $frac[$scale];
        $digits = $int . $keep;
        if ($next >= 5) {
            $digits = self::intAdd($digits, '1');
        }
        $digits = str_pad($digits, $scale + 1, '0', STR_PAD_LEFT);

        $whole = $scale === 0 ? $digits : substr($digits, 0, strlen($digits) - $scale);
        $rest = $scale === 0 ? '' : substr($digits, strlen($digits) - $scale);

        return self::clean($sign . $whole . ($rest === '' ? '' : '.' . $rest));
    }

    /** Trim to a fixed number of decimals for display, keeping the trailing zeros. */
    public static function fixed(string $a, int $scale): string
    {
        $rounded = self::round($a, $scale);
        [$int, $frac, $sign] = self::split($rounded);

        return $sign . $int . ($scale > 0 ? '.' . str_pad($frac, $scale, '0') : '');
    }

    // -----------------------------------------------------------------------
    // Internals — integer strings, no floats anywhere below this line
    // -----------------------------------------------------------------------

    /** @return array{0:string, 1:string, 2:string} int, frac, sign */
    private static function split(string $a): array
    {
        $sign = '';
        if (str_starts_with($a, '-')) {
            $sign = '-';
            $a = substr($a, 1);
        }
        $dot = strpos($a, '.');
        if ($dot === false) {
            return [$a === '' ? '0' : $a, '', $sign];
        }

        return [substr($a, 0, $dot) ?: '0', substr($a, $dot + 1), $sign];
    }

    /** Both values as integers at a common scale. @return array{0:string, 1:string, 2:int} */
    private static function align(string $a, string $b): array
    {
        [$ai, $af, $as] = self::split(self::clean($a));
        [$bi, $bf, $bs] = self::split(self::clean($b));

        $scale = max(strlen($af), strlen($bf));

        return [
            $as . ltrim($ai . str_pad($af, $scale, '0'), '0') ?: $as . '0',
            $bs . ltrim($bi . str_pad($bf, $scale, '0'), '0') ?: $bs . '0',
            $scale,
        ];
    }

    private static function rescale(string $int, int $scale): string
    {
        if ($scale === 0) {
            return self::clean($int);
        }
        $sign = '';
        if (str_starts_with($int, '-')) {
            $sign = '-';
            $int = substr($int, 1);
        }
        $int = str_pad($int, $scale + 1, '0', STR_PAD_LEFT);

        return self::clean($sign . substr($int, 0, strlen($int) - $scale) . '.' . substr($int, strlen($int) - $scale));
    }

    /** Strip leading zeros, trailing fractional zeros and a lone minus sign. */
    private static function clean(string $a): string
    {
        $sign = '';
        if (str_starts_with($a, '-')) {
            $sign = '-';
            $a = substr($a, 1);
        }
        if (str_contains($a, '.')) {
            $a = rtrim(rtrim($a, '0'), '.');
        }
        $a = ltrim($a, '0');
        if ($a === '' || str_starts_with($a, '.')) {
            $a = '0' . $a;
        }

        return ($a === '0' || $a === '') ? '0' : $sign . $a;
    }

    private static function intAdd(string $a, string $b): string
    {
        $aNeg = str_starts_with($a, '-');
        $bNeg = str_starts_with($b, '-');
        $au = ltrim($a, '-');
        $bu = ltrim($b, '-');

        if ($aNeg === $bNeg) {
            return ($aNeg ? '-' : '') . self::magAdd($au, $bu);
        }

        $cmp = self::magCmp($au, $bu);
        if ($cmp === 0) {
            return '0';
        }
        if ($cmp > 0) {
            return ($aNeg ? '-' : '') . self::magSub($au, $bu);
        }

        return ($bNeg ? '-' : '') . self::magSub($bu, $au);
    }

    private static function intCmp(string $a, string $b): int
    {
        $aNeg = str_starts_with($a, '-') && preg_match('/[1-9]/', $a) === 1;
        $bNeg = str_starts_with($b, '-') && preg_match('/[1-9]/', $b) === 1;
        if ($aNeg !== $bNeg) {
            return $aNeg ? -1 : 1;
        }
        $cmp = self::magCmp(ltrim($a, '-'), ltrim($b, '-'));

        return $aNeg ? -$cmp : $cmp;
    }

    private static function magAdd(string $a, string $b): string
    {
        $a = ltrim($a, '0') ?: '0';
        $b = ltrim($b, '0') ?: '0';
        $length = max(strlen($a), strlen($b));
        $a = str_pad($a, $length, '0', STR_PAD_LEFT);
        $b = str_pad($b, $length, '0', STR_PAD_LEFT);

        $carry = 0;
        $out = '';
        for ($i = $length - 1; $i >= 0; $i--) {
            $sum = (int) $a[$i] + (int) $b[$i] + $carry;
            $out = ($sum % 10) . $out;
            $carry = intdiv($sum, 10);
        }

        return ($carry > 0 ? (string) $carry : '') . $out;
    }

    /** a x b, on non-negative integer strings. */
    private static function magMul(string $a, string $b): string
    {
        $a = ltrim($a, '0') ?: '0';
        $b = ltrim($b, '0') ?: '0';
        if ($a === '0' || $b === '0') {
            return '0';
        }

        $lenA = strlen($a);
        $lenB = strlen($b);
        $digits = array_fill(0, $lenA + $lenB, 0);

        for ($i = $lenA - 1; $i >= 0; $i--) {
            $carry = 0;
            $da = (int) $a[$i];
            for ($j = $lenB - 1; $j >= 0; $j--) {
                $slot = $i + $j + 1;
                $sum = $digits[$slot] + ($da * (int) $b[$j]) + $carry;
                $digits[$slot] = $sum % 10;
                $carry = intdiv($sum, 10);
            }
            $digits[$i] += $carry;
        }

        return ltrim(implode('', $digits), '0') ?: '0';
    }

    /** a - b, where a >= b >= 0. */
    private static function magSub(string $a, string $b): string
    {
        $a = ltrim($a, '0') ?: '0';
        $b = ltrim($b, '0') ?: '0';
        $length = max(strlen($a), strlen($b));
        $a = str_pad($a, $length, '0', STR_PAD_LEFT);
        $b = str_pad($b, $length, '0', STR_PAD_LEFT);

        $borrow = 0;
        $out = '';
        for ($i = $length - 1; $i >= 0; $i--) {
            $diff = (int) $a[$i] - (int) $b[$i] - $borrow;
            if ($diff < 0) {
                $diff += 10;
                $borrow = 1;
            } else {
                $borrow = 0;
            }
            $out = $diff . $out;
        }

        return ltrim($out, '0') ?: '0';
    }

    private static function magCmp(string $a, string $b): int
    {
        $a = ltrim($a, '0') ?: '0';
        $b = ltrim($b, '0') ?: '0';
        if (strlen($a) !== strlen($b)) {
            return strlen($a) <=> strlen($b);
        }

        return strcmp($a, $b) <=> 0;
    }

    /** Schoolbook long division on non-negative integer strings. */
    private static function intDivide(string $a, string $b): string
    {
        $b = ltrim($b, '0') ?: '0';
        if ($b === '0') {
            return '0';
        }

        $quotient = '';
        $remainder = '';
        $length = strlen($a);

        for ($i = 0; $i < $length; $i++) {
            $remainder = ltrim($remainder . $a[$i], '0');
            if ($remainder === '') {
                $remainder = '0';
            }

            $digit = 0;
            while (self::magCmp($remainder, $b) >= 0) {
                $remainder = self::magSub($remainder, $b);
                $digit++;
            }
            $quotient .= $digit;
        }

        return ltrim($quotient, '0') ?: '0';
    }
}
