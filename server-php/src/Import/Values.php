<?php

declare(strict_types=1);

namespace Aicountly\Api\Import;

use Aicountly\Api\Dashboards\Decimal;

/**
 * Turning what a file says into what it means.
 *
 * This is the step that makes importing dangerous, and it is separate from
 * reading for exactly that reason: the reader's answers are facts about the
 * file, and these are INTERPRETATIONS. Every one of them can be wrong, so every
 * one of them returns null rather than a guess when the text does not clearly
 * say what it says.
 *
 * "1,25,000.00" is one lakh twenty-five thousand in India and a syntax error in
 * most parsers. "1.250,00" is one thousand two hundred and fifty in Europe and
 * one point two five in a naive float cast — which turns a 1,250 rupee invoice
 * into 1.25 and reconciles it against nothing. A parser that returns 0.0 for
 * anything it cannot read is the worst of all, because zero looks like an
 * answer.
 */
final class Values
{
    /**
     * An amount as an exact decimal string, or null.
     *
     * Handles: Indian and Western grouping, European separators, a leading or
     * trailing currency symbol, parentheses and trailing minus for negatives,
     * and the Dr/Cr suffix an Indian ledger export uses instead of a sign.
     */
    public static function amount(string $raw): ?string
    {
        $text = trim($raw);
        if ($text === '') {
            return null;
        }

        $negative = false;

        // (1,250.00) — accounting's way of writing a credit.
        if (preg_match('/^\((.*)\)$/s', $text, $m) === 1) {
            $negative = true;
            $text = $m[1];
        }

        // Dr / Cr, which carry the sign in a ledger export. Cr on a payable is
        // the normal direction, so it is NOT read as negative: the caller knows
        // which side it asked for. Only the marker is stripped.
        $text = (string) preg_replace('/\b(dr|cr)\b\.?$/i', '', trim($text));

        // 1,250.00- : trailing minus, from mainframe exports.
        if (preg_match('/-\s*$/', $text) === 1) {
            $negative = true;
            $text = (string) preg_replace('/-\s*$/', '', $text);
        }
        if (preg_match('/^\s*-/', $text) === 1) {
            $negative = true;
            $text = (string) preg_replace('/^\s*-/', '', $text);
        }

        // Currency symbols and spaces, including the non-breaking kind that
        // Excel puts between a symbol and its number.
        $text = (string) preg_replace('/[\p{Sc}]|Rs\.?|INR|\s|\x{00A0}/iu', '', $text);

        if ($text === '' || preg_match('/^[\d.,]+$/', $text) !== 1) {
            return null;
        }

        $normalised = self::normaliseSeparators($text);
        if ($normalised === null) {
            return null;
        }

        $value = Decimal::parse($normalised);
        if ($value === null) {
            return null;
        }

        return $negative ? Decimal::negate($value) : $value;
    }

    /**
     * Decide which of . and , is the decimal point, and remove the other.
     *
     * THE RULE: whichever appears LAST is the decimal separator, because no
     * notation puts a thousands separator after the decimal point. That single
     * rule handles 1,250.00 and 1.250,00 and 1,25,000.00 without needing to
     * know which country the file came from.
     *
     * A lone separator is ambiguous — 1,250 is a thousand in one place and one
     * point two five in another. Two or three digits after it is grouping;
     * exactly one or more than three is a decimal. "1,250" resolves to 1250,
     * which is the reading every accounting package in India and Europe agrees
     * on for a whole number.
     */
    private static function normaliseSeparators(string $text): ?string
    {
        $lastDot = strrpos($text, '.');
        $lastComma = strrpos($text, ',');

        if ($lastDot === false && $lastComma === false) {
            return $text;
        }

        if ($lastDot !== false && $lastComma !== false) {
            $decimal = $lastDot > $lastComma ? '.' : ',';
            $grouping = $decimal === '.' ? ',' : '.';

            // GROUPING FIRST. Converting the decimal separator to '.' before
            // stripping the grouping means stripping the character just
            // written: "1.250,00" becomes "1.250.00" and then "125000", a
            // hundredfold error that looks like a plausible number.
            return str_replace($decimal, '.', str_replace($grouping, '', $text));
        }

        $separator = $lastDot !== false ? '.' : ',';
        $position = $lastDot !== false ? $lastDot : $lastComma;
        $after = strlen($text) - $position - 1;
        $occurrences = substr_count($text, $separator);

        // More than one of the same separator can only be grouping.
        if ($occurrences > 1) {
            return str_replace($separator, '', $text);
        }

        if ($after === 3) {
            return str_replace($separator, '', $text);
        }

        return str_replace($separator, '.', $text);
    }

    /**
     * A date as ISO, or null.
     *
     * DAY-FIRST WHERE IT IS AMBIGUOUS. 05/04/2026 is 5 April everywhere this
     * product is used and 4 May in one country. Guessing the wrong one silently
     * moves a bill three weeks and lands it in the wrong month's payables, so
     * the convention is stated here rather than inherited from a locale that
     * depends on which server ran the import.
     *
     * An unambiguous string (a month name, or ISO) is read as what it says.
     */
    public static function date(string $raw): ?string
    {
        $text = trim($raw);
        if ($text === '') {
            return null;
        }

        // Already ISO, possibly with a time the sheet carried.
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $text, $m) === 1) {
            return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? "{$m[1]}-{$m[2]}-{$m[3]}" : null;
        }

        // d/m/y or d-m-y, two- or four-digit year.
        if (preg_match('#^(\d{1,2})[/.\-](\d{1,2})[/.\-](\d{2,4})$#', $text, $m) === 1) {
            $day = (int) $m[1];
            $month = (int) $m[2];
            $year = (int) $m[3];

            // A first part above 12 can only be a day, whatever the convention.
            if ($day <= 12 && $month > 12) {
                [$day, $month] = [$month, $day];
            }
            if ($year < 100) {
                // A two-digit year in a purchase ledger is this century.
                $year += $year < 70 ? 2000 : 1900;
            }

            return checkdate($month, $day, $year) ? sprintf('%04d-%02d-%02d', $year, $month, $day) : null;
        }

        // 5 Apr 2026 / Apr 5, 2026 / 05-APR-2026 — a month name is unambiguous.
        if (preg_match('/[a-z]{3}/i', $text) === 1) {
            $parsed = date_create_immutable_from_format('!j M Y', str_replace(['-', ','], [' ', ' '], $text))
                ?: date_create_immutable_from_format('!M j Y', str_replace(['-', ','], [' ', ' '], $text))
                ?: date_create_immutable_from_format('!j F Y', str_replace(['-', ','], [' ', ' '], $text))
                ?: date_create_immutable_from_format('!F j Y', str_replace(['-', ','], [' ', ' '], $text));

            return $parsed === false ? null : $parsed->format('Y-m-d');
        }

        return null;
    }

    /**
     * An invoice reference, reduced to what is worth comparing.
     *
     * A supplier writes INV-4460 on their statement and INV/4460 on the bill,
     * and a human reconciling by eye treats those as the same document because
     * they are. Punctuation, case and leading zeros come out; the digits and
     * letters stay, in order.
     *
     * The ORIGINAL is always kept alongside — this is for matching only, and
     * nothing that reaches a screen or a ledger is ever the reduced form.
     */
    public static function reference(string $raw): string
    {
        $text = strtoupper(trim($raw));
        $text = (string) preg_replace('/[^A-Z0-9]/', '', $text);

        // INV0004460 and INV4460 are the same reference typed by two systems.
        return (string) preg_replace('/(?<=[A-Z])0+(?=\d)/', '', $text);
    }
}
