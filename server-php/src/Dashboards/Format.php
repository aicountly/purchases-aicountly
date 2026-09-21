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
        return self::withSymbol(self::grouped($value, 2), $currency);
    }

    /** The symbol in front, with a leading minus kept in front of it. */
    private static function withSymbol(string $rendered, string $currency): string
    {
        $symbols = ['INR' => '₹', 'USD' => '$', 'EUR' => '€', 'GBP' => '£', 'AED' => 'AED ', 'SGD' => 'S$'];
        $symbol = $symbols[strtoupper($currency)] ?? (strtoupper($currency) . ' ');

        return str_starts_with($rendered, '-')
            ? '-' . $symbol . substr($rendered, 1)
            : $symbol . $rendered;
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

    /**
     * A short form for a chart axis or a legend: 85.0K, 12.4L, 1.53Cr.
     *
     * Indian scale, because these are Indian books. Compaction loses precision
     * by design, so it is ONLY ever used where the exact figure is also
     * available — beside it, in the tooltip, or in the table under the chart.
     * Nothing in this product states a total in this form alone.
     */
    public static function compact(string $value): string
    {
        $negative = Decimal::isNegative($value);
        $abs = $negative ? Decimal::negate($value) : Decimal::of($value);

        [$divisor, $suffix] = match (true) {
            Decimal::cmp($abs, '10000000') >= 0 => ['10000000', 'Cr'],
            Decimal::cmp($abs, '100000') >= 0   => ['100000', 'L'],
            // 999.5 rather than 1000: the plain branch rounds to whole rupees,
            // and a value that rounds to 1,000 should read as 1.00K rather than
            // as an un-suffixed 1,000 sitting next to 1.02K on the same axis.
            Decimal::cmp($abs, '999.5') >= 0    => ['1000', 'K'],
            default                             => ['1', ''],
        };

        if ($suffix === '') {
            return ($negative ? '-' : '') . self::grouped($abs, Decimal::cmp($abs, '100') >= 0 ? 0 : 2);
        }

        $scaled = Decimal::div($abs, $divisor, 3) ?? '0';
        // Three significant figures reads as a magnitude without pretending to
        // a precision the short form cannot carry: 1.53Cr, 12.4L, 85.0K.
        $places = Decimal::cmp($scaled, '100') >= 0 ? 0 : (Decimal::cmp($scaled, '10') >= 0 ? 1 : 2);

        return ($negative ? '-' : '') . Decimal::fixed($scaled, $places) . $suffix;
    }

    /** The same short form with the currency symbol: ₹1.53Cr. */
    public static function compactMoney(string $value, string $currency = 'INR'): string
    {
        return self::withSymbol(self::compact($value), $currency);
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
