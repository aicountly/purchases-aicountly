<?php

declare(strict_types=1);

namespace Aicountly\Api\Dashboards;

use Aicountly\Api\Clients\BooksClient;
use Aicountly\Api\Context;

/**
 * What Books says, adapted once.
 *
 * Books owns every posted figure a purchase dashboard shows: net purchases,
 * input GST, the payable and its ageing. This class is the single place that
 * knows the shape of its answers, so a change upstream is one file here rather
 * than five panels.
 *
 * VERIFIED CONTRACT (books-react-app/server-php):
 *   GET dashboard/purchase   DashboardController::purchaseSummary, permission
 *                            `dashboard.read`. Returns data.kpis, prev_period_kpis,
 *                            trend.points, top_suppliers, payables_ageing.
 *   GET reports/bill-by-bill ReportsController::billByBill — REQUIRES acc_id and
 *                            answers 400 without one. It is therefore a
 *                            per-supplier call, never a company-wide payables read.
 *
 * Every figure is converted to an exact decimal STRING on the way in. Books
 * sends JSON numbers; json_decode turns those into PHP floats, and a float is
 * the last place a payable should live. The conversion happens once, here.
 */
final class BooksReader
{
    public const LABEL = 'Smart Books';

    /** Books' purchase voucher type, per BooksClient::VCH_PURCHASE. */
    private const VCH_PURCHASE = 11;

    public function __construct(
        private readonly Context $ctx,
        private readonly string $sesKey,
    ) {
    }

    private function client(): BooksClient
    {
        return (new BooksClient())->withSession($this->sesKey);
    }

    /**
     * The purchase summary for a period, already decimal-safe.
     *
     * @return array{ok: bool, error: ?string, status: int, kpis: array<string,string>, previous: array<string,string>, ageing: array<string,string>, trend: list<array{date:string, amount:string}>, top_suppliers: list<array<string,mixed>>, context: array<string,mixed>}
     */
    public function purchaseSummary(Period $period): array
    {
        $result = $this->client()->purchaseDashboard($this->ctx, [
            'from' => $period->from,
            'to'   => $period->to,
        ]);

        $empty = [
            'kpis' => [], 'previous' => [], 'ageing' => [], 'trend' => [],
            'top_suppliers' => [], 'context' => [],
        ];

        if (!($result['ok'] ?? false)) {
            return $empty + [
                'ok'     => false,
                'status' => (int) ($result['status'] ?? 0),
                'error'  => self::reason($result, 'the purchase summary'),
            ];
        }

        $data = (array) ($result['body']['data'] ?? []);

        return [
            'ok'            => true,
            'status'        => (int) ($result['status'] ?? 200),
            'error'         => null,
            'kpis'          => self::decimalMap((array) ($data['kpis'] ?? [])),
            'previous'      => self::decimalMap((array) ($data['prev_period_kpis'] ?? [])),
            'ageing'        => self::decimalMap((array) ($data['payables_ageing'] ?? [])),
            'trend'         => self::trend((array) ($data['trend'] ?? [])),
            'top_suppliers' => self::suppliers((array) ($data['top_suppliers'] ?? [])),
            'context'       => (array) ($data['context'] ?? []),
        ];
    }

    /**
     * Open bills for ONE supplier, with Books' own due dates.
     *
     * Bounded on purpose: bill-by-bill takes a single acc_id, so this is called
     * for a capped list of suppliers the buyer is actually planning to pay, and
     * never in a loop over every creditor in the ledger.
     *
     * @return array{ok: bool, error: ?string, rows: list<array<string, mixed>>}
     */
    public function openItems(int $supplierAccountId, string $asOn): array
    {
        $result = $this->client()->billByBill($this->ctx, [
            'acc_id' => $supplierAccountId,
            'to'     => $asOn,
        ]);

        if (!($result['ok'] ?? false)) {
            return ['ok' => false, 'error' => self::reason($result, 'open bills for this supplier'), 'rows' => []];
        }

        $data = (array) ($result['body']['data'] ?? []);
        $rows = $data['rows'] ?? $data['bills'] ?? $data['data'] ?? [];

        $out = [];
        foreach ((array) $rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $pending = Decimal::of($row['pending_amount'] ?? $row['balance'] ?? $row['outstanding'] ?? null);
            if (Decimal::isZero($pending)) {
                continue;
            }
            $due = self::dateOrNull($row['due_date'] ?? null);

            $out[] = [
                'supplier_account_id' => $supplierAccountId,
                'bill_ref'            => self::stringOrNull($row['bill_ref'] ?? $row['reference'] ?? $row['vch_no'] ?? null),
                'bill_date'           => self::dateOrNull($row['bill_date'] ?? $row['vch_date'] ?? null),
                'due_date'            => $due,
                // Books is the authority for what is still open; nothing is
                // recomputed here from an invoice total and a payment.
                'pending_amount'      => $pending,
                'original_amount'     => Decimal::parse($row['amount'] ?? $row['bill_amount'] ?? null),
                'days_overdue'        => $due === null ? null : self::daysBetween($due, $asOn),
                'has_due_date'        => $due !== null,
            ];
        }

        return ['ok' => true, 'error' => null, 'rows' => $out];
    }

    /**
     * Every bill Books holds for one supplier, settled ones included.
     *
     * openItems() drops anything with nothing left to pay, which is right for a
     * payables screen and wrong for a reconciliation. The most common thing a
     * supplier statement disagrees about is a bill they still show as open and
     * we have already paid — and a reader that filtered those out would report
     * it as "not in Books at all", sending somebody to look for a missing
     * invoice that was never missing.
     *
     * @return array{ok: bool, error: ?string, rows: list<array<string, mixed>>}
     */
    public function supplierLedger(int $supplierAccountId, string $from, string $to): array
    {
        $result = $this->client()->billByBill($this->ctx, [
            'acc_id' => $supplierAccountId,
            'from'   => $from,
            'to'     => $to,
        ]);

        if (!($result['ok'] ?? false)) {
            return ['ok' => false, 'error' => self::reason($result, 'the bill history for this supplier'), 'rows' => []];
        }

        $data = (array) ($result['body']['data'] ?? []);
        $rows = $data['rows'] ?? $data['bills'] ?? $data['data'] ?? [];

        $out = [];
        foreach ((array) $rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $original = Decimal::parse($row['amount'] ?? $row['bill_amount'] ?? null);
            $pending = Decimal::of($row['pending_amount'] ?? $row['balance'] ?? $row['outstanding'] ?? null);

            $out[] = [
                'bill_ref'        => self::stringOrNull($row['bill_ref'] ?? $row['reference'] ?? $row['vch_no'] ?? null),
                'bill_date'       => self::dateOrNull($row['bill_date'] ?? $row['vch_date'] ?? null),
                'due_date'        => self::dateOrNull($row['due_date'] ?? null),
                'original_amount' => $original,
                'pending_amount'  => $pending,
                'settled'         => Decimal::isZero($pending),
            ];
        }

        return ['ok' => true, 'error' => null, 'rows' => $out];
    }

    /**
     * The posted purchase register — the rows behind "net posted purchases".
     *
     * @return array{ok: bool, error: ?string, rows: list<array<string, mixed>>}
     */
    public function purchaseRegister(Period $period, int $limit = 50): array
    {
        $result = $this->client()->registers($this->ctx, [
            'vch_type_id' => self::VCH_PURCHASE,
            'from'        => $period->from,
            'to'          => $period->to,
            'limit'       => $limit,
        ]);

        if (!($result['ok'] ?? false)) {
            return ['ok' => false, 'error' => self::reason($result, 'the purchase register'), 'rows' => []];
        }

        $body = (array) ($result['body'] ?? []);
        $rows = $body['data'] ?? [];
        if (is_array($rows) && isset($rows['rows'])) {
            $rows = $rows['rows'];
        }

        $out = [];
        foreach ((array) $rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $out[] = [
                'voucher_id'   => isset($row['vch_txn_id']) ? (int) $row['vch_txn_id'] : null,
                'voucher_no'   => self::stringOrNull($row['vch_no'] ?? null),
                'voucher_date' => self::dateOrNull($row['vch_date'] ?? null),
                'party'        => self::stringOrNull($row['party_name'] ?? $row['acc_name'] ?? null),
                'amount'       => Decimal::of($row['amount'] ?? $row['total'] ?? null),
            ];
        }

        return ['ok' => true, 'error' => null, 'rows' => $out];
    }

    // -----------------------------------------------------------------------

    /** @param array<string, mixed> $map @return array<string, string> */
    private static function decimalMap(array $map): array
    {
        $out = [];
        foreach ($map as $key => $value) {
            if (!is_string($key)) {
                continue;
            }
            $parsed = Decimal::parse(is_float($value) || is_int($value) ? self::numberToString($value) : $value);
            if ($parsed !== null) {
                $out[$key] = $parsed;
            }
        }

        return $out;
    }

    /**
     * A JSON number, as the decimal string it was before json_decode saw it.
     *
     * Books rounds to four places before sending, so four places is a faithful
     * rendering rather than a guess.
     */
    private static function numberToString(int|float $value): string
    {
        return is_int($value) ? (string) $value : sprintf('%.4F', $value);
    }

    /** @param array<string, mixed> $trend @return list<array{date:string, amount:string}> */
    private static function trend(array $trend): array
    {
        $points = (array) ($trend['points'] ?? []);
        $out = [];
        foreach ($points as $point) {
            if (!is_array($point)) {
                continue;
            }
            $date = self::dateOrNull($point['date'] ?? $point['day'] ?? $point['period'] ?? null);
            if ($date === null) {
                continue;
            }
            $raw = $point['amount'] ?? $point['total'] ?? $point['value'] ?? 0;
            $out[] = [
                'date'   => $date,
                'amount' => Decimal::of(is_float($raw) || is_int($raw) ? self::numberToString($raw) : $raw),
            ];
        }

        return $out;
    }

    /** @param list<mixed> $rows @return list<array<string, mixed>> */
    private static function suppliers(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $raw = $row['amount'] ?? $row['total'] ?? 0;
            $out[] = [
                'supplier_account_id' => isset($row['acc_id']) ? (int) $row['acc_id'] : null,
                'supplier_name'       => self::stringOrNull($row['acc_name'] ?? $row['party_name'] ?? $row['name'] ?? null),
                'amount'              => Decimal::of(is_float($raw) || is_int($raw) ? self::numberToString($raw) : $raw),
            ];
        }

        return $out;
    }

    /** @param array{status?: int, error?: ?string} $result */
    private static function reason(array $result, string $what): string
    {
        $status = (int) ($result['status'] ?? 0);

        return match (true) {
            $status === 403 => 'Smart Books did not allow this session to read ' . $what . '.',
            $status === 401 => 'Smart Books did not accept this session.',
            $status === 0   => 'Smart Books did not answer in time.',
            $status >= 500  => 'Smart Books returned an error for ' . $what . '.',
            default         => 'Smart Books could not provide ' . $what . ' (HTTP ' . $status . ').',
        };
    }

    private static function stringOrNull(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    private static function dateOrNull(mixed $value): ?string
    {
        $text = self::stringOrNull($value);
        if ($text === null) {
            return null;
        }
        try {
            return (new \DateTimeImmutable($text))->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }

    /** Whole days from $date to $asOn. Negative when the date is still ahead. */
    public static function daysBetween(string $date, string $asOn): int
    {
        try {
            $a = new \DateTimeImmutable($date);
            $b = new \DateTimeImmutable($asOn);
        } catch (\Throwable) {
            return 0;
        }

        return (int) $a->diff($b)->format('%r%a');
    }
}
