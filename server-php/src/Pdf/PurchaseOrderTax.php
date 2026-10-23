<?php

declare(strict_types=1);

namespace Aicountly\Api\Pdf;

use Aicountly\Api\Domain\PlaceOfSupply;

/**
 * The GST on a purchase order, as the document prints it: by rate, split CGST + SGST or IGST.
 *
 * WHICH SPLIT. GST on a supply is CGST + SGST when the supplier's state and the place of supply are
 * the same state, IGST when they differ (IGST Act s.7/s.8). For a purchase order:
 *
 *   supplier's state  PlaceOfSupply::decide() on the supplier's Books ledger — the GSTIN's state,
 *                     else the ledger's state; a supplier abroad is 96 (import: IGST); a supplier
 *                     registered as an SEZ unit supplies inter-state (IGST) even within the state
 *   place of supply   where the goods are delivered: the buyer's GST registration this order is
 *                     raised under (Books' branch GST state), else the company's registered office
 *                     state from Manage
 *
 * Either one unknown → the split is not printed ("GST (estimated)" as one figure) and the document
 * says why, rather than guessing a split the supplier's invoice would contradict.
 *
 * WHAT ADDS UP. The figures are the order's own (line_amount, estimated_tax_pc; PurchaseOrderService::
 * totals): taxable value per rate is the net line amount, tax per line is net × rate. Printed to the
 * paisa, the parts must add up to the printed total, which is the order's own total: tax is printed
 * per rate (CGST + SGST is exactly each rate's tax, an odd paisa going to SGST), and whatever
 * two-decimal printing of four-decimal figures leaves over is its own "Rounding" line.
 */
final class PurchaseOrderTax
{
    public const INTRA = 'intra';
    public const INTER = 'inter';
    public const UNKNOWN = 'unknown';

    /**
     * @param array<string, mixed>       $po the order with its lines (line_amount, estimated_tax_pc)
     * @param array{pos_state_code: ?string, supply_nature: ?string}|null $supplier PlaceOfSupply::decide() on the ledger
     * @return array{
     *   mode: string, reason: string, supplier_state: ?string, place_of_supply: ?string,
     *   subtotal: float, discount: float, freight: float, other: float, tax: float, rounding: float, total: float,
     *   cgst: float, sgst: float, igst: float,
     *   rates: list<array{rate: float, taxable: float, tax: float, cgst: float, sgst: float, igst: float}>
     * }
     */
    public static function summarise(array $po, ?array $supplier, ?string $placeOfSupply): array
    {
        $subtotal = self::r((float) ($po['subtotal_amount'] ?? 0));
        $discount = self::r((float) ($po['discount_amount'] ?? 0));
        $freight = self::r((float) ($po['freight_amount'] ?? 0));
        $other = self::r((float) ($po['other_charges'] ?? 0));
        $total = self::r((float) ($po['total_amount'] ?? 0));
        // Taxable value and tax by rate, from the lines as the order computed them.
        $byRate = [];
        foreach ((array) ($po['lines'] ?? []) as $line) {
            $rate = round((float) ($line['estimated_tax_pc'] ?? 0), 3);
            $amount = (float) ($line['line_amount'] ?? 0);
            $key = number_format($rate, 3, '.', '');
            $byRate[$key] ??= ['rate' => $rate, 'taxable' => 0.0, 'tax' => 0.0];
            $byRate[$key]['taxable'] += $amount;
            $byRate[$key]['tax'] += round($amount * $rate / 100, 4);
        }
        ksort($byRate, SORT_NUMERIC);
        $rates = [];
        foreach ($byRate as $row) {
            if ($row['rate'] <= 0 && abs($row['tax']) < 0.005) {
                continue;
            }
            $rates[] = ['rate' => $row['rate'], 'taxable' => self::r($row['taxable']), 'tax' => self::r($row['tax'])];
        }
        $tax = self::r(array_sum(array_column($rates, 'tax')));
        // What two-decimal printing of 4-decimal figures leaves over (a paisa at most, in practice): shown
        // as its own line, so the printed parts add up to the printed total, which is the order's own.
        $rounding = self::r($total - ($subtotal - $discount + $freight + $other + $tax));

        [$mode, $reason] = self::mode($supplier, $placeOfSupply);
        $out = [];
        foreach ($rates as $row) {
            $cgst = $sgst = $igst = 0.0;
            if ($mode === self::INTRA) {
                // Half each; an odd paisa goes to SGST, so CGST + SGST is exactly the row's tax.
                $cgst = self::r(floor(round($row['tax'] * 100) / 2) / 100);
                $sgst = self::r($row['tax'] - $cgst);
            } elseif ($mode === self::INTER) {
                $igst = $row['tax'];
            }
            $out[] = $row + ['cgst' => $cgst, 'sgst' => $sgst, 'igst' => $igst];
        }

        return [
            'mode'            => $mode,
            'reason'          => $reason,
            'supplier_state'  => $supplier['pos_state_code'] ?? null,
            'place_of_supply' => $placeOfSupply,
            'subtotal'        => $subtotal,
            'discount'        => $discount,
            'freight'         => $freight,
            'other'           => $other,
            'tax'             => $tax,
            'rounding'        => $rounding,
            'total'           => $total,
            'cgst'            => self::r(array_sum(array_column($out, 'cgst'))),
            'sgst'            => self::r(array_sum(array_column($out, 'sgst'))),
            'igst'            => $mode === self::INTER ? $tax : 0.0,
            'rates'           => $out,
        ];
    }

    /**
     * @param array{pos_state_code: ?string, supply_nature: ?string}|null $supplier
     * @return array{0: string, 1: string}
     */
    public static function mode(?array $supplier, ?string $placeOfSupply): array
    {
        $from = $supplier['pos_state_code'] ?? null;
        if ($from === PlaceOfSupply::OTHER_COUNTRY) {
            return [self::INTER, 'Import from a supplier outside India: IGST.'];
        }
        if (($supplier['supply_nature'] ?? null) === PlaceOfSupply::SEZ) {
            return [self::INTER, 'Supply from an SEZ unit: IGST.'];
        }
        if ($from === null || $placeOfSupply === null) {
            return [self::UNKNOWN, $from === null
                ? 'The supplier\'s state is not on their Smart Books ledger, so the GST split is not shown.'
                : 'This company\'s GST state is not known, so the GST split is not shown.'];
        }

        return $from === $placeOfSupply
            ? [self::INTRA, 'Supplier and place of supply in the same state: CGST + SGST.']
            : [self::INTER, 'Supplier in another state: IGST.'];
    }

    /** "Karnataka (29)"; an unknown code as itself; null as "Not known". */
    public static function stateLabel(?string $code): string
    {
        if ($code === null || $code === '') {
            return 'Not known';
        }

        return isset(self::STATES[$code]) ? self::STATES[$code] . ' (' . $code . ')' : $code;
    }

    /** GST state codes (the first two digits of a GSTIN) and Books' special codes. */
    public const STATES = [
        '01' => 'Jammu and Kashmir', '02' => 'Himachal Pradesh', '03' => 'Punjab', '04' => 'Chandigarh',
        '05' => 'Uttarakhand', '06' => 'Haryana', '07' => 'Delhi', '08' => 'Rajasthan', '09' => 'Uttar Pradesh',
        '10' => 'Bihar', '11' => 'Sikkim', '12' => 'Arunachal Pradesh', '13' => 'Nagaland', '14' => 'Manipur',
        '15' => 'Mizoram', '16' => 'Tripura', '17' => 'Meghalaya', '18' => 'Assam', '19' => 'West Bengal',
        '20' => 'Jharkhand', '21' => 'Odisha', '22' => 'Chhattisgarh', '23' => 'Madhya Pradesh', '24' => 'Gujarat',
        '25' => 'Daman and Diu', '26' => 'Dadra and Nagar Haveli and Daman and Diu', '27' => 'Maharashtra',
        '28' => 'Andhra Pradesh (old)', '29' => 'Karnataka', '30' => 'Goa', '31' => 'Lakshadweep', '32' => 'Kerala',
        '33' => 'Tamil Nadu', '34' => 'Puducherry', '35' => 'Andaman and Nicobar Islands', '36' => 'Telangana',
        '37' => 'Andhra Pradesh', '38' => 'Ladakh', '96' => 'Other Country', '97' => 'Other Territory',
    ];

    private static function r(float $value): float
    {
        $rounded = round($value, 2);

        return $rounded == 0.0 ? 0.0 : $rounded;
    }
}
