<?php

declare(strict_types=1);

namespace Aicountly\Api\Import;

/**
 * One door for every file somebody uploads.
 *
 * THE TYPE COMES FROM THE BYTES, NOT THE NAME. A filename is whatever the
 * person typed and a browser's content type is whatever their operating system
 * guessed; neither is evidence. Every format here has a signature in its first
 * few bytes, and that is what decides how the file is read.
 *
 * This matters beyond tidiness: a .csv that is really a ZIP is how an upload
 * turns into an unzip somewhere it should not, and a reader that trusted the
 * extension would hand it straight to the XLSX path.
 */
final class DocumentReader
{
    /** Refused above this, before anything is parsed. */
    public const MAX_BYTES = 12 * 1024 * 1024;

    public const KIND_CSV = 'csv';
    public const KIND_XLSX = 'xlsx';
    public const KIND_PDF = 'pdf';
    public const KIND_IMAGE = 'image';
    public const KIND_UNKNOWN = 'unknown';

    /**
     * What this file is, by its own first bytes.
     */
    public static function sniff(string $head): string
    {
        if (str_starts_with($head, '%PDF-')) {
            return self::KIND_PDF;
        }
        // PK\003\004 — a ZIP. Every .xlsx is one; so is .docx, which the caller
        // will find out when the workbook has no sheets.
        if (str_starts_with($head, "PK\x03\x04")) {
            return self::KIND_XLSX;
        }
        if (str_starts_with($head, "\xFF\xD8\xFF")) {
            return self::KIND_IMAGE;      // JPEG
        }
        if (str_starts_with($head, "\x89PNG\r\n\x1a\n")) {
            return self::KIND_IMAGE;      // PNG
        }
        if (str_starts_with($head, "\xD0\xCF\x11\xE0")) {
            return 'xls';                 // the old binary Excel format
        }

        // Anything left that is mostly printable is treated as delimited text.
        // A statement exported as .txt is a CSV wearing a different extension.
        $sample = substr($head, 0, 512);
        $printable = strlen(preg_replace('/[^\P{C}\n\r\t]/u', '', $sample) ?? $sample);
        if ($sample !== '' && $printable / strlen($sample) > 0.9) {
            return self::KIND_CSV;
        }

        return self::KIND_UNKNOWN;
    }

    /**
     * Read a file from disk into a table.
     *
     * @return array{table: Table, kind: string}
     */
    public static function read(string $path, string $originalName = ''): array
    {
        $size = @filesize($path);
        if ($size === false) {
            return ['table' => new Table([], [], $originalName, ['The uploaded file could not be read.']), 'kind' => self::KIND_UNKNOWN];
        }
        if ($size > self::MAX_BYTES) {
            return [
                'table' => new Table([], [], $originalName, [
                    sprintf('That file is %s. The limit is %s — split it, or export a narrower date range.',
                        self::size($size), self::size(self::MAX_BYTES)),
                ]),
                'kind' => self::KIND_UNKNOWN,
            ];
        }

        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return ['table' => new Table([], [], $originalName, ['The uploaded file could not be opened.']), 'kind' => self::KIND_UNKNOWN];
        }
        $head = (string) fread($handle, 1024);
        fclose($handle);

        $kind = self::sniff($head);
        $label = $originalName === '' ? strtoupper($kind) : $originalName;

        return match ($kind) {
            self::KIND_XLSX => ['table' => XlsxReader::read($path, $label), 'kind' => $kind],
            self::KIND_PDF  => ['table' => PdfTextReader::read((string) @file_get_contents($path), $label), 'kind' => $kind],
            self::KIND_CSV  => ['table' => CsvReader::read((string) @file_get_contents($path), $label), 'kind' => $kind],
            self::KIND_IMAGE => [
                'table' => new Table([], [], $label, [
                    'This is a picture, not a document with text in it. Reading figures out of a photograph '
                    . 'needs optical character recognition, which is not installed on this server — '
                    . 'export the statement as CSV, XLSX or a text PDF instead.',
                ]),
                'kind' => $kind,
            ],
            'xls' => [
                'table' => new Table([], [], $label, [
                    'This is the old binary .xls format. Open it and save as .xlsx or .csv, and it will import.',
                ]),
                'kind' => 'xls',
            ],
            default => [
                'table' => new Table([], [], $label, ['This file is not a CSV, an XLSX or a PDF.']),
                'kind' => self::KIND_UNKNOWN,
            ],
        };
    }

    private static function size(int $bytes): string
    {
        return $bytes >= 1048576
            ? round($bytes / 1048576, 1) . ' MB'
            : max(1, (int) round($bytes / 1024)) . ' KB';
    }
}
