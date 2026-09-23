<?php

declare(strict_types=1);

namespace Aicountly\Api\Import;

use Aicountly\Api\Context;
use Aicountly\Api\Db;
use Aicountly\Api\Domain\ReturnClaimService;

/**
 * Reading a spreadsheet of returns, and saying what is wrong with it.
 *
 * READ AND REPORT, NEVER WRITE. This class produces a verdict per row and a set
 * of drafts the caller may then create; it inserts nothing itself. That split
 * is the whole design: a file with a mis-read quantity column imports
 * confidently and completely wrongly, and the only defence is showing somebody
 * what was read BEFORE anything is created.
 *
 * DETERMINISTIC, LIKE THE STATEMENT READER BESIDE IT. The header row is found
 * by scoring rows against a fixed vocabulary — statements and exports alike
 * start with a letterhead — and a column the vocabulary cannot justify is
 * reported as unmapped rather than guessed at.
 *
 * WHAT IT REFUSES TO INVENT. A supplier that is not a number this company can
 * act on, an item this product cannot name, a quantity that is not a quantity:
 * each is an error ON THAT ROW with the cell quoted back, never a silent
 * default. Purchases does not look up items or suppliers to fill in blanks; the
 * products that own them are asked when the draft is worked on, not here.
 */
final class ReturnImport
{
    /** How many rows from the top to consider as the header. */
    private const HEADER_SEARCH_DEPTH = 15;

    /** Largest file this will read in one go. Beyond it, split the export. */
    public const MAX_ROWS = 2000;

    /**
     * The vocabulary, lower-case with punctuation removed.
     *
     * @var array<string, list<string>>
     */
    public const VOCABULARY = [
        'return_date'  => ['returndate', 'date', 'dt', 'docdate', 'documentdate'],
        'supplier'     => ['supplieraccountid', 'supplieraccount', 'supplierid', 'accountid', 'suppliercode', 'accid'],
        'supplier_name' => ['suppliername', 'supplier', 'vendor', 'vendorname', 'party', 'partyname'],
        'source'       => ['sourcedocument', 'source', 'pono', 'ponumber', 'purchaseorder', 'purchaseorderno', 'reference', 'refno', 'billno'],
        'item'         => ['itemid', 'itemcode', 'item', 'sku', 'productcode', 'productid'],
        'quantity'     => ['quantity', 'qty', 'returnqty', 'returnquantity', 'qtyreturned'],
        'rate'         => ['rate', 'price', 'unitrate', 'unitprice', 'cost'],
        'reason'       => ['reason', 'reasoncode', 'returnreason'],
        'warehouse'    => ['warehouse', 'warehouseid', 'godown', 'godownid', 'location', 'locationid'],
        'notes'        => ['notes', 'note', 'remarks', 'narration', 'description', 'particulars'],
    ];

    /** Without these a row cannot become a return line at all. */
    private const REQUIRED = ['supplier', 'quantity'];

    /**
     * @param array<string, int|null> $columns
     * @param list<string>            $headers
     * @param list<string>            $notes
     */
    private function __construct(
        public readonly int $headerRow,
        public readonly array $columns,
        public readonly array $headers,
        public readonly array $notes,
    ) {
    }

    /**
     * Work out which column is which.
     *
     * The row that matches the most known labels is the header, and everything
     * above it is preamble — the same rule ColumnMap uses, for the same reason.
     */
    public static function detect(Table $table): self
    {
        // The first row of a CSV is already in `headers`; a workbook with a
        // letterhead has its real heading somewhere in `rows`. Both are searched
        // as one list, which is the convention ColumnMap established.
        $all = [$table->headers, ...$table->rows];

        $bestRow = 0;
        $bestScore = 0;
        $bestMap = [];

        $depth = min(self::HEADER_SEARCH_DEPTH, count($all));
        for ($index = 0; $index < $depth; $index++) {
            [$score, $map] = self::score($all[$index] ?? []);
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestRow = $index;
                $bestMap = $map;
            }
        }

        $notes = [];
        if ($bestScore === 0) {
            $notes[] = 'No header row could be recognised. Name the columns Return date, Supplier account, Item, Quantity, Rate and Reason.';
        }
        foreach (self::REQUIRED as $field) {
            if (($bestMap[$field] ?? null) === null) {
                $notes[] = 'No "' . str_replace('_', ' ', $field) . '" column was found.';
            }
        }
        if ($bestRow > 0) {
            $notes[] = 'The header was found on row ' . ($bestRow + 1) . '; the rows above it were read as a letterhead and skipped.';
        }

        $columns = [];
        foreach (array_keys(self::VOCABULARY) as $field) {
            $columns[$field] = $bestMap[$field] ?? null;
        }

        return new self($bestRow, $columns, $all[$bestRow] ?? [], $notes);
    }

    /**
     * Read every row, check it, and group what survives into draft returns.
     *
     * Lines are grouped by supplier, source document and date — which is what a
     * return IS. Ten lines against one purchase order are one return with ten
     * lines, not ten returns; getting that wrong produces ten debit notes.
     *
     * @return array<string, mixed>
     */
    public function read(Table $table, Context $ctx): array
    {
        $dataRows = array_values(array_slice([$table->headers, ...$table->rows], $this->headerRow + 1));
        $truncated = count($dataRows) > self::MAX_ROWS;
        if ($truncated) {
            $dataRows = array_slice($dataRows, 0, self::MAX_ROWS);
        }

        // Purchase order numbers are resolved against THIS company's orders.
        // A reference that names no order is reported, never invented.
        $orders = [];
        foreach (Db::all(
            'SELECT po_id, po_no, supplier_account_id, supplier_name_snapshot FROM purchase_orders WHERE cmp_id = :cmp AND fy_id = :fy',
            ['cmp' => $ctx->cmpId, 'fy' => $ctx->fyId],
        ) as $order) {
            $orders[Values::reference((string) $order['po_no'])] = $order;
        }

        $rows = [];
        $groups = [];
        $rowNumber = $this->headerRow + 1;

        foreach ($dataRows as $raw) {
            $rowNumber++;
            if (self::isBlank($raw)) {
                continue;
            }

            $errors = [];
            $cells = [
                'return_date' => $this->cell($raw, 'return_date'),
                'supplier'    => $this->cell($raw, 'supplier'),
                'supplier_name' => $this->cell($raw, 'supplier_name'),
                'source'      => $this->cell($raw, 'source'),
                'item'        => $this->cell($raw, 'item'),
                'quantity'    => $this->cell($raw, 'quantity'),
                'rate'        => $this->cell($raw, 'rate'),
                'reason'      => $this->cell($raw, 'reason'),
                'warehouse'   => $this->cell($raw, 'warehouse'),
                'notes'       => $this->cell($raw, 'notes'),
            ];

            $supplierId = self::wholeNumber($cells['supplier']);
            if ($supplierId === null || $supplierId <= 0) {
                $errors[] = $cells['supplier'] === ''
                    ? 'No supplier account on this row.'
                    : 'Supplier account "' . $cells['supplier'] . '" is not an account id.';
            }

            $quantity = Values::amount($cells['quantity']);
            if ($quantity === null || (float) $quantity <= 0) {
                $errors[] = $cells['quantity'] === ''
                    ? 'No quantity on this row.'
                    : 'Quantity "' . $cells['quantity'] . '" could not be read as a number above zero.';
            }

            $rate = $cells['rate'] === '' ? '0' : Values::amount($cells['rate']);
            if ($rate === null) {
                $errors[] = 'Rate "' . $cells['rate'] . '" could not be read as an amount.';
                $rate = '0';
            }

            $itemId = $cells['item'] === '' ? null : self::wholeNumber($cells['item']);
            if ($cells['item'] !== '' && $itemId === null) {
                // Items belong to Inventory and are referenced by id. A code
                // that is not one is not looked up here: Purchases does not hold
                // an item catalogue to look it up in.
                $errors[] = 'Item "' . $cells['item'] . '" is not an Inventory item id.';
            }

            $warehouseId = $cells['warehouse'] === '' ? null : self::wholeNumber($cells['warehouse']);
            if ($cells['warehouse'] !== '' && $warehouseId === null) {
                $errors[] = 'Warehouse "' . $cells['warehouse'] . '" is not a warehouse id.';
            }

            $date = $cells['return_date'] === '' ? gmdate('Y-m-d') : Values::date($cells['return_date']);
            if ($date === null) {
                $errors[] = 'Date "' . $cells['return_date'] . '" could not be read.';
                $date = gmdate('Y-m-d');
            }

            $reason = self::reasonCode($cells['reason']);
            if ($cells['reason'] !== '' && $reason === null) {
                $errors[] = 'Reason "' . $cells['reason'] . '" is not one of: ' . implode(', ', array_keys(ReturnClaimService::REASONS)) . '.';
            }

            $order = null;
            if ($cells['source'] !== '') {
                $order = $orders[Values::reference($cells['source'])] ?? null;
                if ($order === null) {
                    $errors[] = 'No purchase order "' . $cells['source'] . '" in this financial year.';
                } elseif ($supplierId !== null && (int) $order['supplier_account_id'] !== $supplierId) {
                    $errors[] = 'Purchase order "' . $cells['source'] . '" belongs to a different supplier.';
                }
            }

            $row = [
                'row'        => $rowNumber,
                'ok'         => $errors === [],
                'errors'     => $errors,
                'cells'      => $cells,
                'return_date' => $date,
                'supplier_account_id' => $supplierId,
                'supplier_name' => $cells['supplier_name'] !== '' ? $cells['supplier_name'] : ($order['supplier_name_snapshot'] ?? null),
                'po_id'      => $order === null ? null : (int) $order['po_id'],
                'po_no'      => $order === null ? null : (string) $order['po_no'],
                'item_id'    => $itemId,
                'warehouse_id' => $warehouseId,
                'return_qty' => $quantity ?? '0',
                'rate'       => $rate,
                'reason_code' => $reason,
                'notes'      => $cells['notes'] === '' ? null : $cells['notes'],
            ];
            $rows[] = $row;

            if ($errors !== []) {
                continue;
            }

            // One return per supplier, source document and date.
            $key = $supplierId . '|' . ($row['po_id'] ?? '-') . '|' . $date;
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'supplier_account_id' => $supplierId,
                    'supplier_name'       => $row['supplier_name'],
                    'po_id'               => $row['po_id'],
                    'po_no'               => $row['po_no'],
                    'return_date'         => $date,
                    'reason_code'         => $reason,
                    'reason_note'         => $row['notes'],
                    'lines'               => [],
                ];
            }
            // The return's reason is the first one its lines agree on; a return
            // whose lines disagree carries none, and each line keeps its own.
            if ($groups[$key]['reason_code'] !== $reason) {
                $groups[$key]['reason_code'] = null;
            }
            $groups[$key]['lines'][] = [
                'item_id'      => $itemId,
                'warehouse_id' => $warehouseId,
                'return_qty'   => (float) $row['return_qty'],
                'rate'         => (float) $rate,
                'reason_code'  => $reason,
            ];
        }

        $valid = 0;
        foreach ($rows as $row) {
            if ($row['ok']) {
                $valid++;
            }
        }

        $notes = $this->notes;
        if ($truncated) {
            $notes[] = 'Only the first ' . self::MAX_ROWS . ' rows were read. Split the file and import the rest separately.';
        }

        return [
            'mapping' => [
                'header_row' => $this->headerRow + 1,
                'headers'    => $this->headers,
                'columns'    => $this->columns,
                'unmapped'   => array_values(array_keys(array_filter($this->columns, static fn ($index) => $index === null))),
            ],
            'notes'   => $notes,
            'rows'    => $rows,
            'summary' => [
                'rows'    => count($rows),
                'valid'   => $valid,
                'errors'  => count($rows) - $valid,
                'returns' => count($groups),
            ],
            'returns' => array_values($groups),
        ];
    }

    /**
     * The text in one mapped column, or an empty string.
     *
     * @param list<string> $row
     */
    public function cell(array $row, string $field): string
    {
        $index = $this->columns[$field] ?? null;

        return $index === null ? '' : trim((string) ($row[$index] ?? ''));
    }

    // -----------------------------------------------------------------------

    /**
     * @param list<string> $row
     * @return array{0:int, 1:array<string, int>}
     */
    private static function score(array $row): array
    {
        $score = 0;
        $map = [];

        foreach ($row as $index => $cell) {
            $normalised = self::normalise((string) $cell);
            if ($normalised === '') {
                continue;
            }
            foreach (self::VOCABULARY as $field => $labels) {
                if (isset($map[$field]) || !in_array($normalised, $labels, true)) {
                    continue;
                }
                $map[$field] = $index;
                $score++;
                break;
            }
        }

        return [$score, $map];
    }

    private static function normalise(string $label): string
    {
        return (string) preg_replace('/[^a-z0-9]/', '', strtolower(trim($label)));
    }

    /** @param list<string> $row */
    private static function isBlank(array $row): bool
    {
        foreach ($row as $cell) {
            if (trim((string) $cell) !== '') {
                return false;
            }
        }

        return true;
    }

    private static function wholeNumber(string $raw): ?int
    {
        $text = trim($raw);
        if ($text === '' || preg_match('/^\d+$/', $text) !== 1) {
            return null;
        }

        return (int) $text;
    }

    /**
     * A reason as the vocabulary spells it.
     *
     * The label is accepted as readily as the code, because a file exported
     * from this product's own register carries the label.
     */
    private static function reasonCode(string $raw): ?string
    {
        $text = self::normalise($raw);
        if ($text === '') {
            return null;
        }

        foreach (ReturnClaimService::REASONS as $code => $label) {
            if ($text === self::normalise($code) || $text === self::normalise($label)) {
                return $code;
            }
        }

        return null;
    }
}
