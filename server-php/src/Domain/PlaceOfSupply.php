<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain;

use Aicountly\Api\Clients\BooksClient;
use Aicountly\Api\Context;
use Aicountly\Api\Http;

/**
 * Where a purchase is supplied FROM, as Books needs it to split the GST.
 *
 * Books composes a purchase, a debit note and their GST from `party.pos_state_code`: the
 * COUNTERPARTY's state, compared with the branch's own (GstStateCode::isIntraStateSupply) —
 * equal, CGST + SGST; different, IGST. Purchase never sent it, so Books split nothing and
 * booked a GST-categorised bill with no GST at all; Books now refuses such a voucher (422). The
 * counterparty on a purchase is the supplier, so (as Books' own GSTR-2B purchase drafts do):
 *
 *   1. the GSTIN on the supplier's invoice, when the bill names one (a supplier registered in
 *      several states invoices from one of them), else the GSTIN on their Books ledger: its
 *      first two digits are the state
 *   2. a supplier abroad (ledger registration `overseas`, non-resident, or a country other than
 *      India): Books' special code 96, Other Country — an import is inter-state, IGST
 *   3. the state on the supplier's ledger, for a supplier with no GSTIN
 *   4. otherwise unknown: nothing is sent, and Books decides — it refuses a GST-categorised
 *      voucher, and Purchase says what to fix (explainRefusal())
 *
 * A supplier registered as an SEZ unit is also sent `supply_nature: sez`, the nature Books' own
 * SEZ purchases carry. A supply from an SEZ is inter-state even within the state; Books splits
 * by state alone today, which is why a same-state SEZ bill is flagged after posting
 * (BillService::voucherProblems) and why it is a hand-off to Books.
 *
 * The supplier's ledger is Books', read live as the person posting, never stored here.
 */
final class PlaceOfSupply
{
    /** Books' special place-of-supply code for a counterparty outside India (GstStateCode). */
    public const OTHER_COUNTRY = '96';

    /** Books' own name for a supply from an SEZ unit (GstPortalPurchaseDraftService). */
    public const SEZ = 'sez';

    /**
     * Read the supplier's GST identity from its Books ledger, and decide. A Books that cannot be
     * asked is a retryable refusal — a payload built without the answer would be stored and
     * replayed for ever.
     *
     * @return array{pos_state_code: ?string, supply_nature: ?string, basis: string}
     */
    public static function forSupplier(BooksClient $books, Context $scope, int $supplierAccId, ?string $billFromGstin = null): array
    {
        $response = $books->account($scope, $supplierAccId);
        if (!$response['ok'] || !is_array($response['body']['data'] ?? null)) {
            Http::error(
                $response['status'] === 404 ? 422 : 502,
                'supplier_ledger_unreadable',
                $response['status'] === 404
                    ? 'Smart Books has no ledger for this supplier, so the place of supply cannot be decided. Nothing was sent.'
                    : 'Could not read the supplier\'s ledger from Smart Books to decide the place of supply. Nothing was sent — try again.',
                ['retryable' => $response['status'] !== 404, 'detail' => $response['error'] ?? null],
            );
        }

        return self::decide($response['body']['data'], $billFromGstin);
    }

    /**
     * @param array<string, mixed> $ledger Books' ledger row (gstin, gst_reg_type, state_code, country_code, is_non_resident)
     * @return array{pos_state_code: ?string, supply_nature: ?string, basis: string}
     */
    public static function decide(array $ledger, ?string $billFromGstin = null): array
    {
        $regType = strtolower(trim((string) ($ledger['gst_reg_type'] ?? '')));
        $nature = $regType === 'sez' ? self::SEZ : null;

        $billState = self::stateOfGstin($billFromGstin);
        if ($billState !== null) {
            return ['pos_state_code' => $billState, 'supply_nature' => $nature, 'basis' => 'the GSTIN on the supplier\'s invoice'];
        }
        $ledgerState = self::stateOfGstin(is_string($ledger['gstin'] ?? null) ? $ledger['gstin'] : null);
        if ($ledgerState !== null) {
            return ['pos_state_code' => $ledgerState, 'supply_nature' => $nature, 'basis' => 'the supplier\'s GSTIN'];
        }

        $country = strtoupper(trim((string) ($ledger['country_code'] ?? '')));
        if ($regType === 'overseas' || self::truthy($ledger['is_non_resident'] ?? false) || !in_array($country, ['', 'IN', 'IND', 'INDIA'], true)) {
            return ['pos_state_code' => self::OTHER_COUNTRY, 'supply_nature' => null, 'basis' => 'a supplier outside India (import)'];
        }

        $state = self::stateCode((string) ($ledger['state_code'] ?? ''));
        if ($state !== null) {
            return ['pos_state_code' => $state, 'supply_nature' => $nature, 'basis' => 'the state on the supplier\'s ledger'];
        }

        return ['pos_state_code' => null, 'supply_nature' => $nature, 'basis' => 'unknown'];
    }

    /** The two-digit state a GSTIN was issued in, or null when it is not a GSTIN. */
    public static function stateOfGstin(?string $gstin): ?string
    {
        $gstin = strtoupper(trim((string) $gstin));
        if (preg_match('/^(\d{2})[0-9A-Z]{13}$/', $gstin, $m) !== 1) {
            return null;
        }

        return self::stateCode($m[1]);
    }

    /** A GSTIN as typed, normalised; null when empty; refused when it is not one. */
    public static function normaliseGstin(mixed $value, string $field): ?string
    {
        $gstin = strtoupper(trim(is_string($value) ? $value : ''));
        if ($gstin === '') {
            return null;
        }
        if (self::stateOfGstin($gstin) === null) {
            Http::validationFailed('That is not a GSTIN: 15 characters, starting with the two-digit state code.', ['field' => $field]);
        }

        return $gstin;
    }

    /**
     * Books' refusal, as the person should read it: Books' own words, and — when the voucher went
     * without a place of supply and the refusal is about one — what to fix and how to resend.
     *
     * @param array<string, mixed> $payload the body that was sent
     * @param string $resend what the person presses afterwards ("Revise the bill", "Send it again")
     */
    public static function explainRefusal(string $booksMessage, array $payload, string $resend): string
    {
        $message = trim($booksMessage) === '' ? 'Smart Books refused it.' : trim($booksMessage);
        $sentWithout = trim((string) ($payload['party']['pos_state_code'] ?? '')) === '';
        $aboutSupply = preg_match('/place of supply|pos[_ ]state|state code|gst[_ ]?split|split the gst|company state|branch state/i', $message) === 1;
        if (!$sentWithout || !$aboutSupply) {
            return $message;
        }

        return $message . ' — The supplier\'s ledger in Smart Books has no GSTIN and no state, so Purchase could not say where this was supplied from. Add the supplier\'s GSTIN (or state) to their ledger in Smart Books, then ' . lcfirst($resend) . '.';
    }

    /** A GST state code (01–38, or 97 Other Territory), two digits; null otherwise. */
    private static function stateCode(string $raw): ?string
    {
        $raw = trim($raw);
        if (preg_match('/^\d{1,2}$/', $raw) !== 1) {
            return null;
        }
        $code = str_pad($raw, 2, '0', STR_PAD_LEFT);
        $n = (int) $code;

        return ($n >= 1 && $n <= 38) || $code === '97' ? $code : null;
    }

    private static function truthy(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || (is_string($value) && in_array(strtolower($value), ['t', 'true', 'yes'], true));
    }
}
