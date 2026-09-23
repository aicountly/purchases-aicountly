<?php

declare(strict_types=1);

namespace Aicountly\Api\Pdf;

/**
 * A PDF, written directly.
 *
 * WHY NOT A LIBRARY. This API has no third-party dependencies at all — no
 * composer, no vendor directory — and a report renderer is not the thing to
 * break that for. Nor is a headless browser: Gotenberg or wkhtmltopdf means a
 * second service to deploy, keep patched and watch, so that a table of numbers
 * can be laid out by a rendering engine built for web pages.
 *
 * PDF is a text format. A page is a content stream of operators, and everything
 * a procurement report needs — positioned text, rules, filled rectangles — is
 * four of them. The fonts are the base fourteen, which every conforming reader
 * is REQUIRED to have, so nothing is embedded and the output is a few kilobytes
 * rather than a few megabytes.
 *
 * WHAT IT DELIBERATELY DOES NOT DO: images, transparency, embedded fonts, and
 * therefore any script Helvetica cannot write. A Devanagari supplier name will
 * not render, and the renderer says so on the page rather than dropping the row
 * — a report that silently omits a supplier is worse than one that admits it
 * could not print their name.
 */
final class PdfDocument
{
    public const A4_WIDTH = 595.28;
    public const A4_HEIGHT = 841.89;

    public const FONT_REGULAR = 'F1';
    public const FONT_BOLD = 'F2';

    /** @var list<string> finished page content streams */
    private array $pages = [];

    private string $current = '';
    private float $cursorY;

    public function __construct(
        public readonly float $width = self::A4_WIDTH,
        public readonly float $height = self::A4_HEIGHT,
        public readonly float $margin = 40.0,
    ) {
        $this->cursorY = $this->height - $this->margin;
    }

    // ---------------------------------------------------------------- layout

    public function y(): float
    {
        return $this->cursorY;
    }

    public function moveTo(float $y): void
    {
        $this->cursorY = $y;
    }

    public function advance(float $points): void
    {
        $this->cursorY -= $points;
    }

    public function contentWidth(): float
    {
        return $this->width - 2 * $this->margin;
    }

    /** Would `$needed` points fit before the bottom margin? */
    public function fits(float $needed): bool
    {
        return $this->cursorY - $needed >= $this->margin;
    }

    public function newPage(): void
    {
        $this->pages[] = $this->current;
        $this->current = '';
        $this->cursorY = $this->height - $this->margin;
    }

    // ----------------------------------------------------------------- marks

    public function text(
        string $value,
        float $x,
        float $size = 10.0,
        string $font = self::FONT_REGULAR,
        string $colour = '0 0 0',
        ?float $y = null,
    ): void {
        $encoded = self::escape(self::toWinAnsi($value));
        $at = $y ?? $this->cursorY;
        $this->current .= sprintf(
            "BT /%s %.2F Tf %s rg %.2F %.2F Td (%s) Tj ET\n",
            $font,
            $size,
            $colour,
            $x,
            $at,
            $encoded,
        );
    }

    /** Right-aligned at `$right`, which is what every money column needs. */
    public function textRight(
        string $value,
        float $right,
        float $size = 10.0,
        string $font = self::FONT_REGULAR,
        string $colour = '0 0 0',
        ?float $y = null,
    ): void {
        $this->text($value, $right - self::widthOf($value, $size, $font), $size, $font, $colour, $y);
    }

    public function rule(float $x1, float $x2, ?float $y = null, string $colour = '0.85 0.87 0.85', float $thickness = 0.6): void
    {
        $at = $y ?? $this->cursorY;
        $this->current .= sprintf(
            "%s RG %.2F w %.2F %.2F m %.2F %.2F l S\n",
            $colour,
            $thickness,
            $x1,
            $at,
            $x2,
            $at,
        );
    }

    public function fill(float $x, float $y, float $width, float $height, string $colour): void
    {
        $this->current .= sprintf("%s rg %.2F %.2F %.2F %.2F re f\n", $colour, $x, $y, $width, $height);
    }

    // ------------------------------------------------------------- measuring

    /**
     * Helvetica's own advance widths, so a right-aligned column actually lines
     * up. Measuring by character count puts "1,11,111" and "8,88,888" in
     * different places, which is exactly what a money column must not do.
     *
     * Digits, space and punctuation are the glyphs a report is mostly made of;
     * anything else takes the average, which is close enough for a label.
     */
    public static function widthOf(string $value, float $size, string $font = self::FONT_REGULAR): float
    {
        $bold = $font === self::FONT_BOLD;
        $total = 0.0;

        foreach (str_split(self::toWinAnsi($value)) as $char) {
            $total += match (true) {
                $char === ' '  => 278,
                $char === '.'  => 278,
                $char === ','  => 278,
                $char === ':'  => $bold ? 333 : 278,
                $char === '-'  => 333,
                $char === '('  => 333,
                $char === ')'  => 333,
                $char === '%'  => $bold ? 889 : 889,
                ctype_digit($char) => $bold ? 556 : 556,
                ctype_upper($char) => $bold ? 722 : 667,
                ctype_lower($char) => $bold ? 556 : 500,
                default => $bold ? 600 : 550,
            } / 1000 * $size;
        }

        return $total;
    }

    /**
     * Break a string to fit a width, on word boundaries where it can.
     *
     * @return list<string>
     */
    public static function wrap(string $value, float $width, float $size, string $font = self::FONT_REGULAR): array
    {
        $words = preg_split('/\s+/', trim($value)) ?: [];
        if ($words === []) {
            return [''];
        }

        $lines = [];
        $line = '';
        foreach ($words as $word) {
            $candidate = $line === '' ? $word : $line . ' ' . $word;
            if (self::widthOf($candidate, $size, $font) <= $width || $line === '') {
                $line = $candidate;
                continue;
            }
            $lines[] = $line;
            $line = $word;
        }
        $lines[] = $line;

        return $lines;
    }

    /** One line, cut with an ellipsis rather than overrunning its column. */
    public static function clip(string $value, float $width, float $size, string $font = self::FONT_REGULAR): string
    {
        if (self::widthOf($value, $size, $font) <= $width) {
            return $value;
        }

        $out = $value;
        while ($out !== '' && self::widthOf($out . '…', $size, $font) > $width) {
            $out = mb_substr($out, 0, mb_strlen($out) - 1);
        }

        return $out . '…';
    }

    // ------------------------------------------------------------- assembling

    public function render(string $title = 'Aicountly Purchases'): string
    {
        $pages = $this->pages;
        if ($this->current !== '' || $pages === []) {
            $pages[] = $this->current;
        }

        $objects = [];
        $pageCount = count($pages);

        // 1 catalog, 2 pages, 3..(2+n) page objects, then contents, then fonts.
        $firstPageObject = 3;
        $firstContentObject = $firstPageObject + $pageCount;
        $fontRegular = $firstContentObject + $pageCount;
        $fontBold = $fontRegular + 1;

        $kids = [];
        for ($i = 0; $i < $pageCount; $i++) {
            $kids[] = ($firstPageObject + $i) . ' 0 R';
        }

        $objects[1] = "<< /Type /Catalog /Pages 2 0 R >>";
        $objects[2] = sprintf('<< /Type /Pages /Count %d /Kids [%s] >>', $pageCount, implode(' ', $kids));

        for ($i = 0; $i < $pageCount; $i++) {
            $objects[$firstPageObject + $i] = sprintf(
                '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2F %.2F] /Resources << /Font << /%s %d 0 R /%s %d 0 R >> >> /Contents %d 0 R >>',
                $this->width,
                $this->height,
                self::FONT_REGULAR,
                $fontRegular,
                self::FONT_BOLD,
                $fontBold,
                $firstContentObject + $i,
            );

            $stream = $pages[$i];
            $objects[$firstContentObject + $i] = sprintf(
                "<< /Length %d >>\nstream\n%s\nendstream",
                strlen($stream),
                $stream,
            );
        }

        // WinAnsi, so the rupee sign and accented names survive. The base
        // fourteen need no embedding, which is why this file is small.
        $objects[$fontRegular] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objects[$fontBold] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';

        $out = "%PDF-1.4\n";
        // A binary comment, so anything transferring this file treats it as
        // binary rather than helpfully rewriting its line endings.
        $out .= "%\xE2\xE3\xCF\xD3\n";

        $offsets = [];
        ksort($objects);
        foreach ($objects as $number => $body) {
            $offsets[$number] = strlen($out);
            $out .= $number . " 0 obj\n" . $body . "\nendobj\n";
        }

        $xrefAt = strlen($out);
        $count = count($objects) + 1;

        $out .= "xref\n0 " . $count . "\n";
        $out .= "0000000000 65535 f \n";
        for ($number = 1; $number < $count; $number++) {
            $out .= sprintf("%010d 00000 n \n", $offsets[$number] ?? 0);
        }

        $out .= sprintf(
            "trailer\n<< /Size %d /Root 1 0 R /Info << /Title (%s) /Producer (Aicountly Purchases) >> >>\nstartxref\n%d\n%%%%EOF\n",
            $count,
            self::escape(self::toWinAnsi($title)),
            $xrefAt,
        );

        return $out;
    }

    // ---------------------------------------------------------------- helpers

    /**
     * UTF-8 to WinAnsi, naming what it could not carry.
     *
     * The base-fourteen fonts cannot write Devanagari, Tamil or Chinese. A
     * renderer that dropped those characters would print a supplier called
     * "Pvt Ltd" and look like it worked; this substitutes a visible marker so
     * the reader can see a name did not survive the format.
     */
    private static function toWinAnsi(string $value): string
    {
        // The rupee sign is not in WinAnsi at all. Rs. is what a PDF reader can
        // show, and it is what Indian invoices printed for decades.
        $value = str_replace(
            // The arrows are the ones that matter most: a comparison printed as
            // "? 12.0% vs last month" looks like the renderer broke, and the
            // direction — the whole point of the sentence — is lost.
            ['₹', '▲', '▼', '—', '–', '’', '‘', '“', '”', '…', '•', '×', '→', '·', '≥', '≤'],
            ['Rs. ', '+', '-', '-', '-', "'", "'", '"', '"', '...', '-', 'x', '->', '-', '>=', '<='],
            $value,
        );

        $converted = @iconv('UTF-8', 'Windows-1252//TRANSLIT', $value);
        if ($converted === false) {
            $converted = @iconv('UTF-8', 'Windows-1252//IGNORE', $value);
        }
        if ($converted === false) {
            return preg_replace('/[^\x20-\x7E]/', '?', $value) ?? $value;
        }

        return $converted;
    }

    private static function escape(string $value): string
    {
        return str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', '', ' '], $value);
    }
}
