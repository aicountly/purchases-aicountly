<?php

declare(strict_types=1);

namespace Aicountly\Api\Dashboards;

/**
 * Turning an exact decimal string into something a person reads.
 *
 * Done on the server so that the number in an export, the number in a CSV and
 * the number on the card are produced by ONE piece of code. The alternative —
 * the browser formatting a float it parsed from the same string — is how a
 * dashboard and its own export end up disagreeing at the last paisa.
 *
 * Indian digit grouping (1,23,456.78) throughout, because that is how these
 * figures are read. The currency code travels with the value; nothing here
 * assumes INR.
 */
final class Format
{
    /** 12,34,567.89 — Indian grouping, applied to a decimal string. */
    public static function grouped(string $value, int $scale = 2): string
    {
        $fixed = Decimal::fixed($value, $scale);
        $negative = str_starts_with($fixed, '-');
        $fixed = ltrim($fixed, '-');

        $dot = strpos($fixed, '.');
        $whole = $dot === false ? $fixed : substr($fixed, 0, $dot);
        $rest = $dot === false ? '' : substr($fixed, $dot);

        if (strlen($whole) > 3) {
            $last3 = substr($whole, -3);
            $lead = substr($whole, 0, -3);
            // After the first three digits, India groups in twos.
            $lead = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $lead) ?? $lead;
            $whole = $lead . ',' . $last3;
        }

        return ($negative ? '-' : '') . $whole . $rest;
    }

    /** ₹12,34,567.89, or "USD 1,234.00" for a currency with no symbol here. */
    public static function money(string $value, string $currency = 'INR'): string
    {
        $symbols = ['INR' => '₹', 'USD' => '$', 'EUR' => '€', 'GBP' => '£', 'AED' => 'AED ', 'SGD' => 'S$'];
        $symbol = $symbols[strtoupper($currency)] ?? (strtoupper($currency) . ' ');
        $grouped = self::grouped($value, 2);

        return str_starts_with($grouped, '-')
            ? '-' . $symbol . substr($grouped, 1)
            : $symbol . $grouped;
    }

    /** A whole count: 1,234. */
    public static function count(string|int $value): string
    {
        return self::grouped(Decimal::of((string) $value), 0);
    }

    /** A quantity, without trailing zeros: 100, 12.5, 0.125. */
    public static function quantity(string $value, ?string $unitLabel = null): string
    {
        $trimmed = Decimal::round($value, 4);
        $out = self::grouped($trimmed, 0);
        [$whole, $frac] = array_pad(explode('.', Decimal::fixed($trimmed, 4)), 2, '');
        $frac = rtrim($frac, '0');
        if ($frac !== '') {
            $out = self::grouped($whole, 0) . '.' . $frac;
        }

        return $unitLabel === null || $unitLabel === '' ? $out : $out . ' ' . $unitLabel;
    }

    public static function percent(string $value, int $scale = 1): string
    {
        return Decimal::fixed($value, $scale) . '%';
    }

    public static function days(string|int $value): string
    {
        $n = Decimal::round(Decimal::of((string) $value), 0);

        return $n === '1' ? '1 day' : self::grouped($n, 0) . ' days';
    }

    /** "24 Apr 2026", from an ISO date. Never re-parsed by the browser for display. */
    public static function date(?string $iso): ?string
    {
        if ($iso === null || $iso === '') {
            return null;
        }
        try {
            return (new \DateTimeImmutable($iso))->format('d M Y');
        } catch (\Throwable) {
            return $iso;
        }
    }

    public static function dateTime(?string $iso): ?string
    {
        if ($iso === null || $iso === '') {
            return null;
        }
        try {
            return (new \DateTimeImmutable($iso))->format('d M Y, H:i');
        } catch (\Throwable) {
            return $iso;
        }
    }
}
