<?php

declare(strict_types=1);

namespace Aicountly\Api\Import;

/**
 * XLSX, read from the file rather than through a library.
 *
 * An .xlsx is a ZIP of XML, and both ZipArchive and SimpleXML are in PHP's
 * standard build. Pulling in a spreadsheet library to read a two-column
 * statement would add a dependency tree to an API that deliberately has none.
 *
 * THREE THINGS THAT GO WRONG, AND WHAT IS DONE ABOUT THEM:
 *
 *  1. Strings live in a shared table, not in the cell. A cell of type `s` holds
 *     an INDEX into xl/sharedStrings.xml. Read the cell alone and every text
 *     value is a small integer.
 *
 *  2. Dates are numbers. Excel stores 15 August 2026 as 46249, and which epoch
 *     that counts from depends on a workbook flag. The number formats decide
 *     which numeric cells are dates, so the formats are read too.
 *
 *  3. Empty cells are missing, not blank. A row skips from A to D when B and C
 *     are empty, so a reader that appends values in order silently shifts three
 *     columns left. Cells are placed by their column letter.
 */
final class XlsxReader
{
    /** Formats Excel reserves for dates, plus anything whose code contains a date token. */
    private const BUILTIN_DATE_FORMATS = [14, 15, 16, 17, 18, 19, 20, 21, 22, 27, 30, 36, 45, 46, 47, 50, 57];

    public static function read(string $path, string $source = 'XLSX'): Table
    {
        if (!class_exists(\ZipArchive::class)) {
            return new Table([], [], $source, ['This server cannot open .xlsx files: the zip extension is not installed.']);
        }

        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            return new Table([], [], $source, ['That file is not a readable .xlsx workbook.']);
        }

        try {
            $notes = [];
            $shared = self::sharedStrings($zip);
            [$dateStyles, $epoch1904] = self::styles($zip);
            [$sheetPath, $sheetName, $otherSheets] = self::firstSheet($zip);

            if ($sheetPath === null) {
                return new Table([], [], $source, ['The workbook has no sheets.']);
            }
            if ($otherSheets > 0) {
                $notes[] = sprintf(
                    'Read the first sheet%s. The other %d %s ignored.',
                    $sheetName === null ? '' : ' (“' . $sheetName . '”)',
                    $otherSheets,
                    $otherSheets === 1 ? 'sheet was' : 'sheets were',
                );
            }

            $xml = $zip->getFromName($sheetPath);
            if ($xml === false) {
                return new Table([], [], $source, ['The workbook’s first sheet could not be read.']);
            }

            $rows = self::rows($xml, $shared, $dateStyles, $epoch1904);
        } finally {
            $zip->close();
        }

        if ($rows === []) {
            return new Table([], [], $source, [...$notes, 'The sheet is empty.']);
        }

        $headers = array_shift($rows);

        return (new Table($headers, array_values($rows), $source, $notes))->tidy();
    }

    /** @return list<string> */
    private static function sharedStrings(\ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if ($xml === false) {
            return [];
        }

        $doc = self::parse($xml);
        if ($doc === null) {
            return [];
        }

        $out = [];
        foreach ($doc->si as $item) {
            // A string with mixed formatting is split across <r> runs; joining
            // them is the difference between "Tata Steel" and "Tata".
            $text = '';
            if (isset($item->t)) {
                $text = (string) $item->t;
            }
            foreach ($item->r as $run) {
                $text .= (string) $run->t;
            }
            $out[] = $text;
        }

        return $out;
    }

    /**
     * Which cell styles mean "this number is a date", and which epoch to use.
     *
     * @return array{0: array<int, bool>, 1: bool}
     */
    private static function styles(\ZipArchive $zip): array
    {
        $epoch1904 = false;
        $workbook = $zip->getFromName('xl/workbook.xml');
        if ($workbook !== false && preg_match('/date1904\s*=\s*"(1|true)"/i', $workbook) === 1) {
            $epoch1904 = true;
        }

        $xml = $zip->getFromName('xl/styles.xml');
        if ($xml === false) {
            return [[], $epoch1904];
        }

        $doc = self::parse($xml);
        if ($doc === null) {
            return [[], $epoch1904];
        }

        $custom = [];
        foreach ($doc->numFmts->numFmt ?? [] as $format) {
            $code = (string) $format['formatCode'];
            // A date token outside quoted literal text. y/m/d/h are the tokens
            // that matter; a currency format never contains them.
            $stripped = preg_replace('/"[^"]*"/', '', $code) ?? $code;
            $custom[(int) $format['numFmtId']] = preg_match('/[ymdhs]/i', $stripped) === 1;
        }

        $dateStyles = [];
        $index = 0;
        foreach ($doc->cellXfs->xf ?? [] as $xf) {
            $numFmtId = (int) ($xf['numFmtId'] ?? 0);
            $dateStyles[$index] = in_array($numFmtId, self::BUILTIN_DATE_FORMATS, true) || ($custom[$numFmtId] ?? false);
            $index++;
        }

        return [$dateStyles, $epoch1904];
    }

    /** @return array{0: ?string, 1: ?string, 2: int} */
    private static function firstSheet(\ZipArchive $zip): array
    {
        $workbook = $zip->getFromName('xl/workbook.xml');
        $name = null;
        $count = 0;

        if ($workbook !== false) {
            $doc = self::parse($workbook);
            if ($doc !== null) {
                $sheets = $doc->sheets->sheet ?? [];
                $count = count($sheets);
                if ($count > 0) {
                    $name = (string) $sheets[0]['name'];
                }
            }
        }

        // The relationship id maps to a part name, but the conventional path is
        // right for every writer in practice and the fallback covers the rest.
        foreach (['xl/worksheets/sheet1.xml', 'xl/worksheets/Sheet1.xml'] as $candidate) {
            if ($zip->locateName($candidate) !== false) {
                return [$candidate, $name, max(0, $count - 1)];
            }
        }

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = $zip->getNameIndex($i);
            if (is_string($entry) && str_starts_with($entry, 'xl/worksheets/') && str_ends_with($entry, '.xml')) {
                return [$entry, $name, max(0, $count - 1)];
            }
        }

        return [null, null, 0];
    }

    /**
     * @param list<string> $shared
     * @param array<int, bool> $dateStyles
     * @return list<list<string>>
     */
    private static function rows(string $xml, array $shared, array $dateStyles, bool $epoch1904): array
    {
        $doc = self::parse($xml);
        if ($doc === null) {
            return [];
        }

        $out = [];
        foreach ($doc->sheetData->row ?? [] as $row) {
            $cells = [];
            $widest = 0;

            foreach ($row->c ?? [] as $cell) {
                // Placed by column letter, never appended: an empty cell is
                // absent from the XML, and appending would shift the row left.
                $index = self::columnIndex((string) $cell['r']);
                $cells[$index] = self::value($cell, $shared, $dateStyles, $epoch1904);
                $widest = max($widest, $index + 1);
            }

            $flat = [];
            for ($i = 0; $i < $widest; $i++) {
                $flat[] = $cells[$i] ?? '';
            }
            $out[] = $flat;
        }

        return $out;
    }

    /**
     * @param list<string> $shared
     * @param array<int, bool> $dateStyles
     */
    private static function value(\SimpleXMLElement $cell, array $shared, array $dateStyles, bool $epoch1904): string
    {
        $type = (string) ($cell['t'] ?? 'n');

        if ($type === 's') {
            $index = (int) $cell->v;

            return $shared[$index] ?? '';
        }
        if ($type === 'inlineStr') {
            $text = '';
            if (isset($cell->is->t)) {
                $text = (string) $cell->is->t;
            }
            foreach ($cell->is->r ?? [] as $run) {
                $text .= (string) $run->t;
            }

            return $text;
        }
        if ($type === 'str') {
            return (string) $cell->v;
        }
        if ($type === 'b') {
            return ((string) $cell->v) === '1' ? 'TRUE' : 'FALSE';
        }
        if ($type === 'e') {
            return (string) $cell->v;
        }

        $raw = (string) $cell->v;
        if ($raw === '') {
            return '';
        }

        $style = (int) ($cell['s'] ?? 0);
        if (($dateStyles[$style] ?? false) && is_numeric($raw)) {
            return self::excelDate((float) $raw, $epoch1904);
        }

        // Otherwise the raw text IS the exact value the sheet stored. It is
        // returned untouched — rounding it here would be rounding somebody's
        // invoice before anybody had decided to.
        return $raw;
    }

    /**
     * Excel's serial day number as an ISO date.
     *
     * The 1900 epoch has a famous bug: Excel believes 1900 was a leap year, so
     * serials above 59 are one day ahead of reality. Subtracting the extra day
     * is not a workaround, it is the documented behaviour every other reader
     * implements, and getting it wrong dates every statement line one day late.
     */
    private static function excelDate(float $serial, bool $epoch1904): string
    {
        if ($epoch1904) {
            $timestamp = ($serial + 1462 - 25569) * 86400;
        } else {
            $days = $serial > 59 ? $serial - 1 : $serial;
            $timestamp = ($days - 25568) * 86400;
        }

        $date = (new \DateTimeImmutable('@' . (int) round($timestamp)))->setTimezone(new \DateTimeZone('UTC'));
        $hasTime = abs($serial - floor($serial)) > 1e-9;

        return $date->format($hasTime ? 'Y-m-d H:i' : 'Y-m-d');
    }

    /** "BC7" -> 54 (zero-based). */
    private static function columnIndex(string $reference): int
    {
        $letters = strtoupper(preg_replace('/[^A-Za-z]/', '', $reference) ?? '');
        if ($letters === '') {
            return 0;
        }

        $index = 0;
        foreach (str_split($letters) as $letter) {
            $index = $index * 26 + (ord($letter) - 64);
        }

        return $index - 1;
    }

    private static function parse(string $xml): ?\SimpleXMLElement
    {
        // Entities off. A workbook is a file somebody uploaded, and an XML
        // parser that resolves external entities on an uploaded file will read
        // /etc/passwd for whoever asks.
        $previous = libxml_use_internal_errors(true);
        try {
            $doc = simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOENT & 0);

            return $doc === false ? null : $doc;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }
}
