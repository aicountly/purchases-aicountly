<?php

declare(strict_types=1);

namespace Aicountly\Api\Pdf;

/**
 * The purchase order as the supplier receives it.
 *
 * Drawn from the order as stored, plus what other products own read at the moment of printing —
 * never stored here: the company from Manage, the buyer's GST registration from Smart Books' branch
 * GST, the supplier's GSTIN, state and address from their Books ledger, the ship-to warehouse and
 * the items (names, HSN/SAC) from Inventory. Anything that could not be read prints as "Not known"
 * (or the id) rather than stopping the document.
 *
 * GST-complete for an Indian supplier (launch-readiness P1-P4): buyer and supplier GSTIN, bill-to
 * and ship-to, place of supply, HSN/SAC per line, taxable value and rate per line, CGST + SGST or
 * IGST (PurchaseOrderTax), the total in words. Text is drawn with the embedded DejaVu Sans
 * (PdfDocument), so the rupee sign and accented names print as themselves.
 *
 * The tax is the order's ESTIMATE: Books computes the tax actually charged when the supplier's bill
 * is entered, and the document says so.
 */
final class PurchaseOrderDocument
{
    private const INK = '0.08 0.09 0.10';
    private const MUTED = '0.42 0.45 0.43';
    private const BRAND = '0.09 0.48 0.07';
    private const BAND = '0.97 0.98 0.97';

    /**
     * @param array<string, mixed> $po        the order with its lines
     * @param array<string, mixed> $company   Manage's companyinfo, or []
     * @param array<int, string>   $itemNames item_id => name, from Inventory
     * @param array<string, mixed> $parties   read now from Books / Inventory (PurchaseOrderService::document):
     *   buyer    {gstin, state_code}           the GST registration this order is raised under
     *   supplier {gstin, state_code, supply_nature, address}  from the supplier's ledger (PlaceOfSupply::decide)
     *   ship_to  {name, address}               the delivery warehouse, or the order's own delivery address
     *   hsn      item_id => HSN/SAC            from Inventory, where the line does not carry its own
     */
    public static function render(array $po, array $company, array $itemNames, string $fingerprint, array $parties = []): string
    {
        $pdf = new PdfDocument();
        $left = $pdf->margin;
        $right = $pdf->width - $pdf->margin;
        $currency = (string) ($po['currency_code'] ?? 'INR');

        $buyer = (array) ($parties['buyer'] ?? []);
        $supplier = (array) ($parties['supplier'] ?? []);
        $shipTo = (array) ($parties['ship_to'] ?? []);
        $hsn = (array) ($parties['hsn'] ?? []);

        $companyName = (string) ($company['comp_name'] ?? $company['print_name'] ?? ('Company ' . ($po['cmp_id'] ?? '')));
        $companyAddress = self::joinAddress([$company['ro_adrs1'] ?? '', $company['ro_adrs2'] ?? '', $company['ro_city'] ?? '', $company['ro_pin'] ?? '']);
        $buyerState = self::code($buyer['state_code'] ?? null) ?? self::code($company['ro_state'] ?? null);
        $placeOfSupply = self::code($parties['place_of_supply'] ?? null) ?? $buyerState;
        $supplierDecision = $supplier === [] ? null : [
            'pos_state_code' => self::code($supplier['state_code'] ?? null),
            'supply_nature'  => isset($supplier['supply_nature']) ? (string) $supplier['supply_nature'] : null,
        ];
        $tax = PurchaseOrderTax::summarise($po, $supplierDecision, $placeOfSupply);

        // Header.
        $pdf->text('PURCHASE ORDER', $left, 8, PdfDocument::FONT_BOLD, self::BRAND);
        $pdf->advance(18);
        $pdf->text(PdfDocument::clip($companyName, $pdf->contentWidth() * 0.62, 16, PdfDocument::FONT_BOLD), $left, 16, PdfDocument::FONT_BOLD, self::INK);
        $pdf->textRight((string) $po['po_no'], $right, 16, PdfDocument::FONT_BOLD, self::INK);
        $pdf->advance(14);
        if ($companyAddress !== '') {
            $pdf->text(PdfDocument::clip($companyAddress, $pdf->contentWidth() * 0.62, 9), $left, 9, PdfDocument::FONT_REGULAR, self::MUTED);
        }
        $pdf->textRight('Dated ' . $po['po_date'] . ((int) ($po['version_no'] ?? 0) > 0 ? '  ·  Amendment ' . (int) $po['version_no'] : ''), $right, 9, PdfDocument::FONT_REGULAR, self::MUTED);
        $pdf->advance(12);
        $pdf->text('GSTIN ' . self::orNotKnown($buyer['gstin'] ?? null) . '  ·  State ' . PurchaseOrderTax::stateLabel($buyerState), $left, 9, PdfDocument::FONT_REGULAR, self::MUTED);
        $pdf->advance(20);

        // Parties: supplier, bill to, ship to.
        $colWidth = ($pdf->contentWidth() - 20) / 3;
        $blocks = [
            ['Supplier', (string) ($po['supplier_name_snapshot'] ?? ('Supplier account ' . $po['supplier_account_id'])), (string) ($supplier['address'] ?? ''),
                ['GSTIN ' . self::orNotKnown($supplier['gstin'] ?? null), 'State ' . PurchaseOrderTax::stateLabel(self::code($supplier['state_code'] ?? null))]],
            ['Bill to', $companyName, $companyAddress,
                ['GSTIN ' . self::orNotKnown($buyer['gstin'] ?? null), 'State ' . PurchaseOrderTax::stateLabel($buyerState)]],
            ['Ship to', (string) ($shipTo['name'] ?? $companyName), (string) ($shipTo['address'] ?? ($shipTo === [] ? $companyAddress : '')), []],
        ];
        $top = $pdf->y();
        $lowest = $top;
        foreach ($blocks as $i => [$label, $name, $address, $extra]) {
            $x = $left + $i * ($colWidth + 10);
            $y = $top;
            $pdf->text($label, $x, 8, PdfDocument::FONT_BOLD, self::MUTED, $y);
            $y -= 12;
            $pdf->text(PdfDocument::clip($name, $colWidth, 10, PdfDocument::FONT_BOLD), $x, 10, PdfDocument::FONT_BOLD, self::INK, $y);
            $y -= 12;
            foreach (array_slice(PdfDocument::wrap($address, $colWidth, 8), 0, 3) as $row) {
                if (trim($row) === '') {
                    continue;
                }
                $pdf->text(PdfDocument::clip($row, $colWidth, 8), $x, 8, PdfDocument::FONT_REGULAR, self::INK, $y);
                $y -= 10;
            }
            foreach ($extra as $row) {
                $pdf->text(PdfDocument::clip($row, $colWidth, 8), $x, 8, PdfDocument::FONT_REGULAR, self::MUTED, $y);
                $y -= 10;
            }
            $lowest = min($lowest, $y);
        }
        $pdf->moveTo($lowest - 8);

        $facts = ['Place of supply ' . PurchaseOrderTax::stateLabel($placeOfSupply), 'Deliver by ' . (string) ($po['promised_date'] ?? 'as agreed')];
        $pdf->text(PdfDocument::clip(implode('  ·  ', $facts), $pdf->contentWidth(), 9), $left, 9, PdfDocument::FONT_REGULAR, self::INK);
        $pdf->advance(20);

        // Lines.
        $cols = [
            '#' => $left, 'Item' => $left + 18, 'HSN/SAC' => $left + 228,
            'Qty' => $left + 335, 'Rate' => $left + 398, 'GST' => $left + 440, 'Taxable value' => $right,
        ];
        $itemWidth = $cols['HSN/SAC'] - $cols['Item'] - 8;
        $pdf->fill($left - 4, $pdf->y() - 4, $pdf->contentWidth() + 8, 16, self::BAND);
        $pdf->text('#', $cols['#'], 8, PdfDocument::FONT_BOLD, self::MUTED);
        $pdf->text('Item', $cols['Item'], 8, PdfDocument::FONT_BOLD, self::MUTED);
        $pdf->text('HSN/SAC', $cols['HSN/SAC'], 8, PdfDocument::FONT_BOLD, self::MUTED);
        foreach (['Qty', 'Rate', 'GST', 'Taxable value'] as $heading) {
            $pdf->textRight($heading === 'GST' ? 'GST %' : $heading, $cols[$heading], 8, PdfDocument::FONT_BOLD, self::MUTED);
        }
        $pdf->advance(18);
        foreach ((array) ($po['lines'] ?? []) as $line) {
            if (!$pdf->fits(30)) {
                $pdf->newPage();
            }
            $itemId = $line['item_id'] === null ? null : (int) $line['item_id'];
            $name = $itemId !== null
                ? ($itemNames[$itemId] ?? ('Item #' . $itemId))
                : (string) ($line['description'] ?? 'Service');
            $code = trim((string) ($line['hsn_sac'] ?? ''));
            if ($code === '' && $itemId !== null) {
                $code = trim((string) ($hsn[$itemId] ?? ''));
            }
            $pdf->text((string) $line['line_no'], $cols['#'], 9, PdfDocument::FONT_REGULAR, self::INK);
            $pdf->text(PdfDocument::clip($name, $itemWidth, 9), $cols['Item'], 9, PdfDocument::FONT_REGULAR, self::INK);
            $pdf->text($code === '' ? '—' : $code, $cols['HSN/SAC'], 9, PdfDocument::FONT_REGULAR, self::INK);
            $pdf->textRight(self::qty((float) $line['ordered_qty']), $cols['Qty'], 9, PdfDocument::FONT_REGULAR, self::INK);
            $pdf->textRight(self::money((float) ($line['agreed_rate'] ?? 0)), $cols['Rate'], 9, PdfDocument::FONT_REGULAR, self::INK);
            $pdf->textRight(self::rate((float) ($line['estimated_tax_pc'] ?? 0)), $cols['GST'], 9, PdfDocument::FONT_REGULAR, self::INK);
            $pdf->textRight(self::money((float) ($line['line_amount'] ?? 0)), $cols['Taxable value'], 9, PdfDocument::FONT_REGULAR, self::INK);
            $pdf->advance(12);
            $note = [];
            if ($itemId !== null && trim((string) ($line['description'] ?? '')) !== '' && trim((string) $line['description']) !== $name) {
                $note[] = trim((string) $line['description']);
            }
            if ((float) ($line['discount_amount'] ?? 0) > 0.004) {
                $note[] = 'less discount ' . self::money((float) $line['discount_amount']);
            }
            if ($note !== []) {
                $pdf->text(PdfDocument::clip(implode('  ·  ', $note), $itemWidth + 100, 8), $cols['Item'], 8, PdfDocument::FONT_REGULAR, self::MUTED);
                $pdf->advance(11);
            }
            $pdf->rule($left, $right);
            $pdf->advance(8);
        }

        // Totals, with the GST split by rate.
        $pdf->advance(6);
        $rows = [['Subtotal', $tax['subtotal'], true]];
        $rows[] = ['Discount', -$tax['discount'], false];
        $rows[] = ['Freight', $tax['freight'], false];
        $rows[] = ['Other charges', $tax['other'], false];
        foreach ($tax['rates'] as $r) {
            $on = ' on ' . self::money($r['taxable']);
            if ($tax['mode'] === PurchaseOrderTax::INTRA) {
                $half = self::rate($r['rate'] / 2);
                $rows[] = ['CGST @ ' . $half . '%' . $on, $r['cgst'], true];
                $rows[] = ['SGST @ ' . $half . '%' . $on, $r['sgst'], true];
            } elseif ($tax['mode'] === PurchaseOrderTax::INTER) {
                $rows[] = ['IGST @ ' . self::rate($r['rate']) . '%' . $on, $r['igst'], true];
            } else {
                $rows[] = ['GST (estimated) @ ' . self::rate($r['rate']) . '%' . $on, $r['tax'], true];
            }
        }
        $rows[] = ['Rounding', $tax['rounding'], false];
        foreach ($rows as [$label, $value, $always]) {
            if (!$always && abs($value) < 0.005) {
                continue;
            }
            if (!$pdf->fits(14)) {
                $pdf->newPage();
            }
            $pdf->textRight($label, $right - 110, 9, PdfDocument::FONT_REGULAR, self::MUTED);
            $pdf->textRight(self::money($value), $right, 9, PdfDocument::FONT_REGULAR, self::INK);
            $pdf->advance(13);
        }
        $pdf->textRight('Total (' . ($currency === 'INR' ? '₹' : $currency) . ')', $right - 110, 11, PdfDocument::FONT_BOLD, self::INK);
        $pdf->textRight(self::money($tax['total']), $right, 11, PdfDocument::FONT_BOLD, self::INK);
        $pdf->advance(16);
        foreach (PdfDocument::wrap('Amount in words: ' . AmountInWords::of($tax['total'], $currency), $pdf->contentWidth(), 9) as $row) {
            $pdf->text($row, $left, 9, PdfDocument::FONT_BOLD, self::INK);
            $pdf->advance(12);
        }
        $pdf->text(PdfDocument::clip($tax['reason'] . ' Tax is estimated; the supplier\'s tax invoice decides it.', $pdf->contentWidth(), 8), $left, 8, PdfDocument::FONT_REGULAR, self::MUTED);
        $pdf->advance(20);

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
        if (!$pdf->fits(30)) {
            $pdf->newPage();
        }
        $pdf->textRight('For ' . $companyName, $right, 9, PdfDocument::FONT_BOLD, self::INK);
        $pdf->advance(30);
        $pdf->textRight('Authorised signatory', $right, 8, PdfDocument::FONT_REGULAR, self::MUTED);
        $pdf->advance(16);
        $pdf->text('Document reference ' . substr($fingerprint, 0, 16) . '  ·  Please quote the order number on your invoice and delivery challan.', $left, 7, PdfDocument::FONT_REGULAR, self::MUTED);

        return $pdf->render('Purchase order ' . $po['po_no']);
    }

    /** @param list<mixed> $parts */
    private static function joinAddress(array $parts): string
    {
        return trim(implode(', ', array_filter(array_map(static fn ($p) => is_scalar($p) ? trim((string) $p) : '', $parts), static fn (string $p) => $p !== '')));
    }

    /** A two-digit GST state code, or null. */
    private static function code(mixed $value): ?string
    {
        $raw = is_scalar($value) ? trim((string) $value) : '';
        if (preg_match('/^\d{1,2}$/', $raw) !== 1) {
            return null;
        }
        $code = str_pad($raw, 2, '0', STR_PAD_LEFT);

        return isset(PurchaseOrderTax::STATES[$code]) ? $code : null;
    }

    private static function orNotKnown(mixed $value): string
    {
        $v = is_scalar($value) ? strtoupper(trim((string) $value)) : '';

        return $v === '' ? 'Not known' : $v;
    }

    private static function money(float $value): string
    {
        return number_format($value, 2, '.', ',');
    }

    private static function rate(float $value): string
    {
        return rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.');
    }

    private static function qty(float $value): string
    {
        return rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');
    }
}
