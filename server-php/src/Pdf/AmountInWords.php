<?php

declare(strict_types=1);

namespace Aicountly\Api\Pdf;

/**
 * An amount in words, the Indian way: lakh and crore, rupees and paise.
 *
 *   1,25,000.50 → "Rupees One Lakh Twenty-Five Thousand and Fifty Paise Only"
 *
 * Printed under a purchase order's total, as Indian purchase orders and tax invoices carry it. The
 * paise are rounded to the nearest one first, so the words always say exactly the figure printed
 * beside them (number_format with two decimals rounds the same way).
 */
final class AmountInWords
{
    private const ONES = [
        '', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten',
        'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen',
    ];

    private const TENS = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];

    /** "Rupees … and … Paise Only" for INR; another currency is named by its code ("USD … and … Cents Only"). */
    public static function of(float $amount, string $currency = 'INR'): string
    {
        $negative = $amount < 0;
        $paiseTotal = (int) round(abs($amount) * 100);
        $whole = intdiv($paiseTotal, 100);
        $paise = $paiseTotal % 100;

        $inr = strtoupper($currency) === 'INR' || $currency === '';
        $major = $inr ? 'Rupees' : strtoupper($currency);
        $minor = $inr ? 'Paise' : 'Cents';

        $words = $major . ' ' . ($whole === 0 ? 'Zero' : self::indian($whole));
        if ($paise > 0) {
            $words .= ' and ' . self::belowHundred($paise) . ' ' . $minor;
        }

        return ($negative ? 'Minus ' : '') . $words . ' Only';
    }

    /** A whole number in the Indian grouping: crore, lakh, thousand, hundred. */
    public static function indian(int $n): string
    {
        if ($n === 0) {
            return 'Zero';
        }
        $parts = [];
        $crore = intdiv($n, 10_000_000);
        $n %= 10_000_000;
        if ($crore > 0) {
            // Above 99 crore the crore count itself is written the Indian way ("One Hundred Crore").
            $parts[] = self::indian($crore) . ' Crore';
        }
        foreach ([[100_000, 'Lakh'], [1_000, 'Thousand'], [100, 'Hundred']] as [$unit, $name]) {
            $count = intdiv($n, $unit);
            $n %= $unit;
            if ($count > 0) {
                $parts[] = self::belowHundred($count) . ' ' . $name;
            }
        }
        if ($n > 0) {
            $parts[] = self::belowHundred($n);
        }

        return implode(' ', $parts);
    }

    private static function belowHundred(int $n): string
    {
        if ($n < 20) {
            return self::ONES[$n];
        }

        return self::TENS[intdiv($n, 10)] . ($n % 10 > 0 ? '-' . self::ONES[$n % 10] : '');
    }
}
