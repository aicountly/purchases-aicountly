<?php

declare(strict_types=1);

namespace Aicountly\Api;

use Aicountly\Api\Pdf\PurchaseOrderTax;

/**
 * Manage portal state master ids -> GST state codes.
 *
 * Manage stores a company's / branch's state as its OWN master id (ghm_*_address.state_id, sent
 * as companyinfo `ro_state` / `co_state` and branch/info `address.state_id`), NOT as the 2-digit
 * GST state code: Punjab is 18 in Manage but 03 under GST, and 18 under GST is Assam. Reading a
 * Manage id as a GST code names the wrong state, the wrong place of supply, and splits an
 * intra-state purchase as IGST (or an inter-state one as CGST + SGST).
 *
 * The ids are Manage's INDIAN_STATE_OPTIONS (manage-aicountly web/src/data/companyFormOptions.js);
 * this mirrors Books' App\Services\ManageStateMaster. Any value read from a Manage state field
 * goes through here; a GSTIN's first two digits, when there is one, still win over it.
 */
final class ManageStateMaster
{
    /** @var array<int, string> Manage state id => GST state code */
    private const GST_CODE_BY_MANAGE_ID = [
        1  => '37', // Andhra Pradesh
        2  => '18', // Assam
        3  => '12', // Arunachal Pradesh
        4  => '10', // Bihar
        5  => '24', // Gujarat
        6  => '06', // Haryana
        7  => '02', // Himachal Pradesh
        8  => '01', // Jammu & Kashmir
        9  => '29', // Karnataka
        10 => '32', // Kerala
        11 => '23', // Madhya Pradesh
        12 => '27', // Maharashtra
        13 => '14', // Manipur
        14 => '17', // Meghalaya
        15 => '15', // Mizoram
        16 => '13', // Nagaland
        17 => '21', // Odisha
        18 => '03', // Punjab
        19 => '08', // Rajasthan
        20 => '11', // Sikkim
        21 => '33', // Tamil Nadu
        22 => '16', // Tripura
        23 => '09', // Uttar Pradesh
        24 => '19', // West Bengal
        25 => '07', // Delhi
        26 => '30', // Goa
        27 => '34', // Puducherry
        28 => '31', // Lakshadweep
        29 => '26', // Daman & Diu (merged into DNH & DD, GST 26)
        30 => '26', // Dadra & Nagar Haveli (merged into DNH & DD, GST 26)
        31 => '04', // Chandigarh
        32 => '35', // Andaman & Nicobar
        33 => '05', // Uttarakhand
        34 => '20', // Jharkhand
        35 => '22', // Chhattisgarh
        37 => '38', // Ladakh
        39 => '97', // Other Territory
        40 => '36', // Telangana
        // 38 = "Other Country": no Indian GST state.
    ];

    /** Top-level keys a company row may carry its GSTIN under. */
    private const GSTIN_KEYS = ['gstin', 'gst_no', 'gst_number', 'company_gstin', 'comp_gstin', 'cmp_gstin'];

    /**
     * GST state code for a Manage state field ('' when it names no Indian state).
     *
     * A numeric value is a Manage master id, never a GST code. A non-numeric value is taken as a
     * state name ("Punjab", "Jammu & Kashmir") and matched against the GST state names.
     */
    public static function gstCode(mixed $manageState): string
    {
        if (is_int($manageState)) {
            return self::GST_CODE_BY_MANAGE_ID[$manageState] ?? '';
        }
        if (!is_string($manageState)) {
            return '';
        }
        $raw = trim($manageState);
        if (preg_match('/^\d{1,3}$/', $raw) === 1) {
            return self::GST_CODE_BY_MANAGE_ID[(int) $raw] ?? '';
        }

        return $raw === '' ? '' : self::codeForName($raw);
    }

    /**
     * The GST state of a Manage companyinfo row: its GSTIN's first two digits when it has one, else
     * its registered office's Manage state id (`ro_state`) mapped to the GST code. Null when neither
     * names an Indian state.
     *
     * @param array<string, mixed> $company companyinfo's company row (its `data`)
     */
    public static function companyGstCode(array $company): ?string
    {
        $fromGstin = self::gstinPrefix($company);
        if ($fromGstin !== null) {
            return $fromGstin;
        }
        $code = self::gstCode($company['ro_state'] ?? null);

        return $code === '' ? null : $code;
    }

    /**
     * The state a company row's GSTIN names: a top-level GSTIN, else its `identifiers` GSTINs when
     * they all name one state (a company registered in several states has no single answer here).
     *
     * @param array<string, mixed> $company
     */
    private static function gstinPrefix(array $company): ?string
    {
        foreach (self::GSTIN_KEYS as $key) {
            $code = self::prefixOf($company[$key] ?? null);
            if ($code !== null) {
                return $code;
            }
        }
        $codes = [];
        foreach (is_array($company['identifiers'] ?? null) ? $company['identifiers'] : [] as $id) {
            if (is_array($id) && strtolower((string) ($id['type'] ?? '')) === 'gstin') {
                $code = self::prefixOf($id['value'] ?? null);
                if ($code !== null) {
                    $codes[$code] = true;
                }
            }
        }

        return count($codes) === 1 ? (string) array_key_first($codes) : null;
    }

    private static function prefixOf(mixed $gstin): ?string
    {
        $g = is_string($gstin) ? strtoupper(trim($gstin)) : '';
        if (preg_match('/^(\d{2})[A-Z0-9]{13}$/', $g, $m) !== 1) {
            return null;
        }

        return isset(PurchaseOrderTax::STATES[$m[1]]) && $m[1] !== '96' ? $m[1] : null;
    }

    private static function codeForName(string $name): string
    {
        $wanted = self::normaliseName($name);
        foreach (PurchaseOrderTax::STATES as $code => $label) {
            if ($code === '96' || $code === '28') {
                continue; // Other Country names no Indian state; 28 is the pre-2014 Andhra code.
            }
            if (self::normaliseName($label) === $wanted) {
                return (string) $code;
            }
        }

        return '';
    }

    private static function normaliseName(string $name): string
    {
        return (string) preg_replace('/[^a-z]/', '', str_replace('&', 'and', strtolower($name)));
    }
}
