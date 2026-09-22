<?php

declare(strict_types=1);

namespace Aicountly\Api\Import;

use Aicountly\Api\Dashboards\Decimal;
use Aicountly\Api\Dashboards\Format;

/**
 * A supplier's statement against what Smart Books actually holds.
 *
 * THIS IS THE GAP THAT WAS DOCUMENTED AND IS NOW CLOSED. Books answers
 * `reports/bill-by-bill` only for ONE account at a time, which is why this
 * product cannot produce a company-wide "due within N days". A reconciliation
 * is inherently one supplier at a time, so the same constraint costs nothing
 * here — the acc_id is the whole point of the screen.
 *
 * WHAT IT NEVER DOES: change anything. It reports four buckets and stops. Books
 * owns the ledger, a statement is the supplier's opinion of it, and the only
 * thing this product is entitled to do with a disagreement is put it in front
 * of somebody who can decide. An importer that "corrected" the ledger to agree
 * with a supplier's PDF would be the most expensive bug in the product.
 *
 * MATCHING, IN ORDER OF EVIDENCE. Reference first, because a shared invoice
 * number is near-proof. Then amount and date together, because two documents
 * for the same money on the same day are almost certainly one document. Then
 * amount alone, ONLY when exactly one candidate has it — a second candidate
 * with the same amount makes the match a coin toss, and a coin toss reported as
 * a match is worse than an unmatched row somebody looks at.
 */
final class StatementReconciler
{
    /** Days apart two dates may be and still be the same document. */
    private const DATE_WINDOW = 5;

    /**
     * @param list<list<string>> $rows raw statement rows, preamble removed
     * @param list<array<string, mixed>> $ledger rows from BooksReader::supplierLedger()
     * @return array<string, mixed>
     */
    public static function reconcile(array $rows, ColumnMap $map, array $ledger, string $currency): array
    {
        $statement = self::readStatement($rows, $map, $currency);

        // Index the ledger by the things worth matching on. Ledger rows are
        // consumed as they match, so one bill cannot satisfy two statement
        // lines — a duplicate on the statement must stay visible as one.
        $unmatched = $ledger;
        $matched = [];
        $differing = [];
        $onlyStatement = [];

        foreach ($statement['lines'] as $line) {
            $index = self::findMatch($line, $unmatched);

            if ($index === null) {
                $onlyStatement[] = $line + ['reason' => self::whyUnmatched($line)];
                continue;
            }

            $bill = $unmatched[$index];
            unset($unmatched[$index]);

            $difference = Decimal::sub($line['amount'] ?? '0', $bill['original_amount'] ?? '0');
            $entry = [
                'statement'   => $line,
                'bill'        => self::presentBill($bill, $currency),
                'difference'  => $difference,
                'difference_formatted' => Format::money($difference, $currency),
                'agrees'      => Decimal::isZero($difference),
            ];

            if (Decimal::isZero($difference)) {
                $matched[] = $entry;
            } else {
                $differing[] = $entry;
            }
        }

        $onlyBooks = array_map(
            static fn (array $bill) => self::presentBill($bill, $currency),
            array_values($unmatched),
        );

        return [
            'currency' => $currency,
            'statement_total' => Format::money($statement['total'], $currency),
            'statement_total_raw' => $statement['total'],
            'ledger_total' => Format::money(self::total($ledger), $currency),
            'ledger_total_raw' => self::total($ledger),
            'difference' => Format::money(Decimal::sub($statement['total'], self::total($ledger)), $currency),
            'lines_read' => count($statement['lines']),
            'lines_skipped' => $statement['skipped'],
            'buckets' => [
                [
                    'id' => 'agreed',
                    'label' => 'Agreed',
                    'tone' => 'good',
                    'count' => count($matched),
                    'rows' => $matched,
                    'meaning' => 'On the statement and in Smart Books, for the same amount. Nothing to do.',
                ],
                [
                    'id' => 'differs',
                    'label' => 'Same bill, different amount',
                    'tone' => 'warn',
                    'count' => count($differing),
                    'rows' => $differing,
                    'meaning' => 'Both sides have the document and disagree about the money. Usually a credit note '
                        . 'one side has applied and the other has not, or tax treated differently.',
                ],
                [
                    'id' => 'only_statement',
                    'label' => 'On the statement only',
                    'tone' => 'bad',
                    'count' => count($onlyStatement),
                    'rows' => $onlyStatement,
                    'meaning' => 'The supplier is billing for something Smart Books has no record of. Either an '
                        . 'invoice never reached us, or it reached us and was never entered.',
                ],
                [
                    'id' => 'only_books',
                    'label' => 'In Smart Books only',
                    'tone' => 'warn',
                    'count' => count($onlyBooks),
                    'rows' => $onlyBooks,
                    'meaning' => 'We hold a bill the statement does not show. Often already settled and dropped from '
                        . 'their open list, sometimes a bill entered against the wrong supplier.',
                ],
            ],
            'basis' => 'Statement lines matched against Smart Books bills for this supplier — by invoice reference '
                . 'first, then by amount and date within ' . self::DATE_WINDOW . ' days, then by a unique amount. '
                . 'Nothing here changes the ledger: Smart Books owns it, and a disagreement is a decision for a person.',
        ];
    }

    /**
     * @param list<list<string>> $rows
     * @return array{lines: list<array<string, mixed>>, total: string, skipped: int}
     */
    private static function readStatement(array $rows, ColumnMap $map, string $currency = 'INR'): array
    {
        $lines = [];
        $skipped = 0;
        $total = '0';

        foreach ($rows as $number => $row) {
            $amount = $map->amount($row);
            $date = Values::date($map->cell($row, 'date'));
            $reference = $map->cell($row, 'reference');

            // A line with no money on it is a subtotal, a carried-forward
            // balance or a blank — none of which is a document to reconcile.
            if ($amount === null || Decimal::isZero($amount)) {
                $skipped++;
                continue;
            }

            $lines[] = [
                'row'         => $number + 1,
                'date'        => $date,
                'reference'   => $reference === '' ? null : $reference,
                'key'         => $reference === '' ? null : Values::reference($reference),
                'description' => $map->cell($row, 'description') ?: null,
                'amount'      => $amount,
                // Formatted here, beside the ledger's own formatting, so the
                // two columns of the same comparison are written the same way.
                // A reader asked to compare 200000 with Rs.2,00,000.00 is being
                // asked to do the formatting in their head.
                'amount_formatted' => Format::money($amount, $currency),
            ];
            $total = Decimal::add($total, $amount);
        }

        return ['lines' => $lines, 'total' => $total, 'skipped' => $skipped];
    }

    /**
     * @param array<string, mixed> $line
     * @param array<int, array<string, mixed>> $candidates
     */
    private static function findMatch(array $line, array $candidates): ?int
    {
        // 1. The reference. A shared invoice number is near-proof, and it is
        //    compared in reduced form so INV-4460 and INV/004460 agree.
        if ($line['key'] !== null) {
            foreach ($candidates as $index => $bill) {
                $ref = $bill['bill_ref'] ?? null;
                if (is_string($ref) && $ref !== '' && Values::reference($ref) === $line['key']) {
                    return $index;
                }
            }
        }

        // 2. Amount and date together.
        if ($line['date'] !== null) {
            foreach ($candidates as $index => $bill) {
                if (!Decimal::isZero(Decimal::sub($line['amount'], (string) ($bill['original_amount'] ?? '0')))) {
                    continue;
                }
                $billDate = $bill['bill_date'] ?? null;
                if (is_string($billDate) && self::daysApart($line['date'], $billDate) <= self::DATE_WINDOW) {
                    return $index;
                }
            }
        }

        // 3. Amount alone, and ONLY when it is unique. Two bills for the same
        //    money make this a guess, and a guess presented as a match is how a
        //    reconciliation quietly stops being one.
        $hits = [];
        foreach ($candidates as $index => $bill) {
            if (Decimal::isZero(Decimal::sub($line['amount'], (string) ($bill['original_amount'] ?? '0')))) {
                $hits[] = $index;
            }
        }

        return count($hits) === 1 ? $hits[0] : null;
    }

    /** @param array<string, mixed> $line */
    private static function whyUnmatched(array $line): string
    {
        if ($line['reference'] === null) {
            return 'No invoice reference on this line, and no bill in Smart Books has this amount on this date.';
        }

        return 'Smart Books has no bill with this reference, amount or date for this supplier.';
    }

    /** @param array<string, mixed> $bill */
    private static function presentBill(array $bill, string $currency): array
    {
        return [
            'bill_ref'  => $bill['bill_ref'] ?? null,
            'bill_date' => $bill['bill_date'] ?? null,
            'due_date'  => $bill['due_date'] ?? null,
            'amount'    => $bill['original_amount'] ?? null,
            'amount_formatted' => Format::money((string) ($bill['original_amount'] ?? '0'), $currency),
            'pending_formatted' => Format::money((string) ($bill['pending_amount'] ?? '0'), $currency),
            'settled'   => (bool) ($bill['settled'] ?? false),
        ];
    }

    /** @param list<array<string, mixed>> $ledger */
    private static function total(array $ledger): string
    {
        $total = '0';
        foreach ($ledger as $bill) {
            $total = Decimal::add($total, (string) ($bill['original_amount'] ?? '0'));
        }

        return $total;
    }

    private static function daysApart(string $a, string $b): int
    {
        $left = date_create_immutable($a);
        $right = date_create_immutable($b);
        if ($left === false || $right === false) {
            return PHP_INT_MAX;
        }

        return (int) abs((int) $left->diff($right)->days);
    }
}
