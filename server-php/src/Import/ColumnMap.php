<?php

declare(strict_types=1);

namespace Aicountly\Api\Import;

/**
 * Which column is which, worked out rather than asked for.
 *
 * DETERMINISTIC, AND FIRST. Every supplier statement in the world has a date
 * column, a reference column and an amount column, and they are labelled from a
 * small vocabulary — "Date", "Dt", "Voucher Date", "Bill No", "Invoice #",
 * "Amount", "Debit". Matching that vocabulary is a lookup, and a lookup is
 * repeatable, explainable and free. A model is not needed to read the word
 * "Invoice", and reaching for one here would make the answer depend on a
 * network call and a temperature.
 *
 * WHEN THE HEADER IS NOT AT THE TOP. Statements begin with a letterhead, an
 * address and a period line before the table starts. The header row is found by
 * scoring rows rather than assumed to be the first — the row that matches the
 * most known labels wins, and everything above it is preamble.
 *
 * WHAT IS NEVER GUESSED. A mapping the vocabulary cannot justify is returned as
 * null with its candidates listed, and the screen asks. The failure mode this
 * avoids is a "Credit" column silently read as the invoice amount, which
 * reconciles cleanly and is entirely wrong.
 */
final class ColumnMap
{
    /** How many rows from the top to consider as the header. */
    private const HEADER_SEARCH_DEPTH = 15;

    /**
     * The vocabulary. Lower-case, punctuation removed, longest match wins.
     *
     * @var array<string, list<string>>
     */
    public const VOCABULARY = [
        'date' => [
            'date', 'dt', 'billdate', 'invoicedate', 'voucherdate', 'vchdate', 'docdate',
            'documentdate', 'transactiondate', 'trndate', 'postingdate', 'entrydate',
        ],
        'reference' => [
            'billno', 'invoiceno', 'invoicenumber', 'invoice', 'billnumber', 'reference', 'ref',
            'refno', 'referenceno', 'voucherno', 'vchno', 'documentno', 'docno', 'billref',
            'particularsno', 'number', 'no',
        ],
        'description' => [
            'particulars', 'narration', 'description', 'details', 'remarks', 'note', 'notes',
            'item', 'itemname', 'nature',
        ],
        'amount' => [
            'amount', 'amt', 'value', 'total', 'grosstotal', 'invoiceamount', 'billamount',
            'netamount', 'totalamount', 'grandtotal',
        ],
        'debit' => ['debit', 'dr', 'dramount', 'debitamount', 'withdrawal'],
        'credit' => ['credit', 'cr', 'cramount', 'creditamount', 'deposit'],
        'balance' => ['balance', 'bal', 'closingbalance', 'runningbalance', 'outstanding', 'pending', 'pendingamount'],
        'due_date' => ['duedate', 'due', 'dueon', 'paymentdue', 'maturitydate'],
    ];

    /**
     * @param array<string, int|null> $columns field => column index
     * @param list<string> $notes
     */
    private function __construct(
        public readonly int $headerRow,
        public readonly array $columns,
        public readonly array $headers,
        public readonly array $notes,
    ) {
    }

    /**
     * Map a table, treating `$required` as the fields that must be found.
     *
     * @param list<string> $required
     */
    public static function detect(Table $table, array $required = ['date', 'amount']): self
    {
        $all = [$table->headers, ...$table->rows];
        $depth = min(self::HEADER_SEARCH_DEPTH, count($all));

        $bestRow = 0;
        $bestScore = -1;
        for ($i = 0; $i < $depth; $i++) {
            $score = self::score($all[$i] ?? []);
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestRow = $i;
            }
        }

        $headers = $all[$bestRow] ?? [];
        $notes = [];

        if ($bestScore <= 0) {
            return new self($bestRow, array_fill_keys(array_keys(self::VOCABULARY), null), $headers, [
                'No column headings were recognised. Choose which column is which below — nothing is imported until you do.',
            ]);
        }

        if ($bestRow > 0) {
            $notes[] = sprintf(
                'The table starts on row %d; the %d row%s above it were read as a letterhead and skipped.',
                $bestRow + 1,
                $bestRow,
                $bestRow === 1 ? '' : 's',
            );
        }

        $columns = [];
        $taken = [];
        foreach (self::VOCABULARY as $field => $words) {
            $index = self::match($headers, $words, $taken);
            $columns[$field] = $index;
            if ($index !== null) {
                $taken[] = $index;
            }
        }

        // A statement with separate Debit and Credit columns has no single
        // amount column, and that is normal rather than a failure.
        if ($columns['amount'] === null && $columns['debit'] !== null) {
            $notes[] = 'This statement has separate debit and credit columns; the amount is taken from them.';
        }

        foreach ($required as $field) {
            $satisfied = $columns[$field] !== null
                || ($field === 'amount' && ($columns['debit'] !== null || $columns['credit'] !== null));
            if (!$satisfied) {
                $notes[] = sprintf('No “%s” column was recognised. Pick one below.', $field);
            }
        }

        return new self($bestRow, $columns, $headers, $notes);
    }

    /** How many cells of a row look like column headings. */
    private static function score(array $row): int
    {
        $score = 0;
        foreach ($row as $cell) {
            $normalised = self::normalise((string) $cell);
            if ($normalised === '') {
                continue;
            }
            foreach (self::VOCABULARY as $words) {
                if (in_array($normalised, $words, true)) {
                    $score += 2;
                    continue 2;
                }
            }
            // A cell that parses as a number or a date is DATA, and a row of
            // data is evidence against it being the heading.
            if (Values::amount((string) $cell) !== null || Values::date((string) $cell) !== null) {
                $score -= 1;
            }
        }

        return $score;
    }

    /**
     * @param list<string> $headers
     * @param list<string> $words
     * @param list<int> $taken
     */
    private static function match(array $headers, array $words, array $taken): ?int
    {
        $best = null;
        $bestLength = 0;

        foreach ($headers as $index => $header) {
            if (in_array($index, $taken, true)) {
                continue;
            }
            $normalised = self::normalise((string) $header);
            if ($normalised === '') {
                continue;
            }

            foreach ($words as $word) {
                // Exact beats contained: "Bill No" must not be claimed by the
                // "no" entry when "billno" is right there.
                if ($normalised === $word) {
                    return $index;
                }
                if (strlen($word) >= 3 && str_contains($normalised, $word) && strlen($word) > $bestLength) {
                    $best = $index;
                    $bestLength = strlen($word);
                }
            }
        }

        return $best;
    }

    private static function normalise(string $value): string
    {
        return (string) preg_replace('/[^a-z0-9]/', '', strtolower(trim($value)));
    }

    /**
     * The data rows, with the preamble and the heading removed.
     *
     * @return list<list<string>>
     */
    public function dataRows(Table $table): array
    {
        $all = [$table->headers, ...$table->rows];

        return array_values(array_slice($all, $this->headerRow + 1));
    }

    public function cell(array $row, string $field): string
    {
        $index = $this->columns[$field] ?? null;

        return $index === null ? '' : trim((string) ($row[$index] ?? ''));
    }

    /**
     * The amount for a row, from a single column or a debit/credit pair.
     *
     * A statement line is one or the other, never both; when both carry a
     * figure the debit wins, because a purchase ledger's debits are the
     * invoices and that is what a reconciliation is about.
     */
    public function amount(array $row): ?string
    {
        $single = $this->cell($row, 'amount');
        if ($single !== '') {
            $parsed = Values::amount($single);
            if ($parsed !== null) {
                return $parsed;
            }
        }

        $debit = Values::amount($this->cell($row, 'debit'));
        if ($debit !== null) {
            return $debit;
        }

        return Values::amount($this->cell($row, 'credit'));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'header_row' => $this->headerRow,
            'headers'    => $this->headers,
            'columns'    => $this->columns,
            'notes'      => $this->notes,
            'vocabulary' => array_keys(self::VOCABULARY),
        ];
    }

    /**
     * Replace the detected mapping with the one a person chose.
     *
     * @param array<string, int|null> $overrides
     */
    public function withOverrides(array $overrides): self
    {
        $columns = $this->columns;
        foreach ($overrides as $field => $index) {
            if (array_key_exists($field, $columns)) {
                $columns[$field] = $index === null ? null : (int) $index;
            }
        }

        return new self($this->headerRow, $columns, $this->headers, $this->notes);
    }
}
