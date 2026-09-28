<?php

declare(strict_types=1);

namespace Aicountly\Api\Pdf;

/**
 * The purchase order as the supplier receives it.
 *
 * Drawn from the order as stored, plus the names other products own read at the moment of
 * printing (the company from Manage, the items from Inventory) — never stored here. A name that
 * could not be read prints as its id rather than stopping the document.
 */
final class PurchaseOrderDocument
{
    private const INK = '0.08 0.09 0.10';
    private const MUTED = '0.42 0.45 0.43';
    private const BRAND = '0.09 0.48 0.07';
    private const BAND = '0.97 0.98 0.97';

    /**
     * @param array<string, mixed> $po           the order with its lines
     * @param array<string, mixed> $company      Manage's companyinfo, or []
     * @param array<int, string>   $itemNames    item_id => name, from Inventory
     */
    public static function render(array $po, array $company, array $itemNames, string $fingerprint): string
    {
        $pdf = new PdfDocument();
        $left = $pdf->margin;
        $right = $pdf->width - $pdf->margin;

        $pdf->text('PURCHASE ORDER', $left, 8, PdfDocument::FONT_BOLD, self::BRAND);
        $pdf->advance(18);
        $pdf->text((string) ($company['comp_name'] ?? $company['print_name'] ?? ('Company ' . ($po['cmp_id'] ?? ''))), $left, 16, PdfDocument::FONT_BOLD, self::INK);
        $pdf->textRight((string) $po['po_no'], $right, 16, PdfDocument::FONT_BOLD, self::INK);
        $pdf->advance(14);
        $address = trim(implode(', ', array_filter([(string) ($company['ro_adrs1'] ?? ''), (string) ($company['ro_adrs2'] ?? ''), (string) ($company['ro_city'] ?? ''), (string) ($company['ro_pin'] ?? '')])));
        if ($address !== '') {
            $pdf->text(PdfDocument::clip($address, $pdf->contentWidth() * 0.6, 9), $left, 9, PdfDocument::FONT_REGULAR, self::MUTED);
        }
        $pdf->textRight('Dated ' . $po['po_date'] . ((int) ($po['version_no'] ?? 0) > 0 ? '  ·  Amendment ' . (int) $po['version_no'] : ''), $right, 9, PdfDocument::FONT_REGULAR, self::MUTED);
        $pdf->advance(22);

        $pdf->text('To', $left, 8, PdfDocument::FONT_BOLD, self::MUTED);
        $pdf->text('Deliver by', $left + 300, 8, PdfDocument::FONT_BOLD, self::MUTED);
        $pdf->advance(12);
        $pdf->text((string) ($po['supplier_name_snapshot'] ?? ('Supplier account ' . $po['supplier_account_id'])), $left, 11, PdfDocument::FONT_BOLD, self::INK);
        $pdf->text((string) ($po['promised_date'] ?? 'As agreed'), $left + 300, 11, PdfDocument::FONT_REGULAR, self::INK);
        $pdf->advance(24);

        // Lines.
        $cols = ['#' => $left, 'Item' => $left + 22, 'Qty' => $right - 200, 'Rate' => $right - 120, 'Amount' => $right];
        $pdf->fill($left - 4, $pdf->y() - 4, $pdf->contentWidth() + 8, 16, self::BAND);
        $pdf->text('#', $cols['#'], 8, PdfDocument::FONT_BOLD, self::MUTED);
        $pdf->text('Item', $cols['Item'], 8, PdfDocument::FONT_BOLD, self::MUTED);
        $pdf->textRight('Qty', $cols['Qty'], 8, PdfDocument::FONT_BOLD, self::MUTED);
        $pdf->textRight('Rate', $cols['Rate'], 8, PdfDocument::FONT_BOLD, self::MUTED);
        $pdf->textRight('Amount', $cols['Amount'], 8, PdfDocument::FONT_BOLD, self::MUTED);
        $pdf->advance(18);
        foreach ((array) ($po['lines'] ?? []) as $line) {
            if (!$pdf->fits(30)) {
                $pdf->newPage();
            }
            $name = $line['item_id'] !== null
                ? ($itemNames[(int) $line['item_id']] ?? ('Item #' . (int) $line['item_id']))
                : (string) ($line['description'] ?? 'Service');
            $pdf->text((string) $line['line_no'], $cols['#'], 9, PdfDocument::FONT_REGULAR, self::INK);
            $pdf->text(PdfDocument::clip($name, $cols['Qty'] - $cols['Item'] - 70, 9), $cols['Item'], 9, PdfDocument::FONT_REGULAR, self::INK);
            $pdf->textRight(self::qty((float) $line['ordered_qty']), $cols['Qty'], 9, PdfDocument::FONT_REGULAR, self::INK);
            $pdf->textRight(self::money((float) ($line['agreed_rate'] ?? 0)), $cols['Rate'], 9, PdfDocument::FONT_REGULAR, self::INK);
            $pdf->textRight(self::money((float) ($line['line_amount'] ?? 0)), $cols['Amount'], 9, PdfDocument::FONT_REGULAR, self::INK);
            $pdf->advance(12);
            if ($line['item_id'] !== null && trim((string) ($line['description'] ?? '')) !== '') {
                $pdf->text(PdfDocument::clip((string) $line['description'], $cols['Qty'] - $cols['Item'] - 70, 8), $cols['Item'], 8, PdfDocument::FONT_REGULAR, self::MUTED);
                $pdf->advance(11);
            }
            $pdf->rule($left, $right);
            $pdf->advance(8);
        }

        $pdf->advance(6);
        foreach ([
            'Subtotal' => (float) ($po['subtotal_amount'] ?? 0),
            'Discount' => -(float) ($po['discount_amount'] ?? 0),
            'Freight' => (float) ($po['freight_amount'] ?? 0),
            'Other charges' => (float) ($po['other_charges'] ?? 0),
            'Estimated tax' => (float) ($po['estimated_tax_amount'] ?? 0),
        ] as $label => $value) {
            if (abs($value) < 0.005 && $label !== 'Subtotal') {
                continue;
            }
            $pdf->textRight($label, $right - 110, 9, PdfDocument::FONT_REGULAR, self::MUTED);
            $pdf->textRight(self::money($value), $right, 9, PdfDocument::FONT_REGULAR, self::INK);
            $pdf->advance(13);
        }
        $pdf->textRight('Total (' . ($po['currency_code'] ?? 'INR') . ')', $right - 110, 11, PdfDocument::FONT_BOLD, self::INK);
        $pdf->textRight(self::money((float) ($po['total_amount'] ?? 0)), $right, 11, PdfDocument::FONT_BOLD, self::INK);
        $pdf->advance(26);

        foreach (['Payment terms' => $po['payment_terms'] ?? null, 'Terms' => $po['terms_text'] ?? null, 'Notes' => $po['notes'] ?? null] as $label => $value) {
            if (trim((string) $value) === '') {
                continue;
            }
            $pdf->text($label, $left, 8, PdfDocument::FONT_BOLD, self::MUTED);
            $pdf->advance(12);
            foreach (PdfDocument::wrap((string) $value, $pdf->contentWidth(), 9) as $row) {
                if (!$pdf->fits(14)) {
                    $pdf->newPage();
                }
                $pdf->text($row, $left, 9, PdfDocument::FONT_REGULAR, self::INK);
                $pdf->advance(12);
            }
            $pdf->advance(6);
        }

        $pdf->advance(10);
        $pdf->text('Document reference ' . substr($fingerprint, 0, 16) . '  ·  Tax is estimated; the supplier\'s invoice decides it.', $left, 7, PdfDocument::FONT_REGULAR, self::MUTED);

        return $pdf->render('Purchase order ' . $po['po_no']);
    }

    private static function money(float $value): string
    {
        return number_format($value, 2, '.', ',');
    }

    private static function qty(float $value): string
    {
        return rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');
    }
}
