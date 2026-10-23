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
 * a procurement document needs — positioned text, rules, filled rectangles — is
 * four of them.
 *
 * TEXT IS UNICODE (ANA-13, D-19). The base-fourteen Helvetica this used to
 * draw with can only write WinAnsi, and the conversion to it dropped every
 * accented letter ("Café Müller" printed as "Caf Mller"), the en dash and the
 * rupee sign. The text is now drawn with DejaVu Sans, embedded as a subset
 * (only the glyphs the report used, see TrueTypeFont) as a Type 0 / CIDFontType2
 * font with Identity-H encoding and a ToUnicode map — so "₹12,50,000.55" prints
 * as itself and is found by search and copy-paste. A script the font has no
 * glyphs for (Devanagari needs a shaping engine to be written correctly) is not
 * drawn; ReportRenderer prints a visible marker and says so in the notes.
 *
 * If the font files are missing from a deployment the document still renders,
 * with Helvetica and one WinAnsi conversion (₹ as "Rs."), and logs why: a
 * report with an unprintable accent is better than no report.
 */
final class PdfDocument
{
    public const A4_WIDTH = 595.28;
    public const A4_HEIGHT = 841.89;

    public const FONT_REGULAR = 'F1';
    public const FONT_BOLD = 'F2';

    private const FONT_FILES = [
        self::FONT_REGULAR => 'DejaVuSans.ttf',
        self::FONT_BOLD    => 'DejaVuSans-Bold.ttf',
    ];

    /** @var array<string, TrueTypeFont>|null loaded once per process; [] = unavailable */
    private static ?array $fonts = null;

    private static ?string $fontDirectory = null;

    /** @var list<string> finished page content streams */
    private array $pages = [];

    private string $current = '';
    private float $cursorY;

    /** @var array<string, array<int, int>> font → glyph id → the code point drawn with it */
    private array $used = [];

    public function __construct(
        public readonly float $width = self::A4_WIDTH,
        public readonly float $height = self::A4_HEIGHT,
        public readonly float $margin = 40.0,
    ) {
        $this->cursorY = $this->height - $this->margin;
    }

    /** Point the renderer at another font directory (tests; a missing one exercises the fallback). */
    public static function useFontDirectoryForTesting(?string $directory): void
    {
        self::$fontDirectory = $directory;
        self::$fonts = null;
    }

    /** Whether text is drawn with the embedded Unicode font (false: the Helvetica fallback). */
    public static function hasUnicodeFont(): bool
    {
        return self::fonts() !== null;
    }

    /**
     * Can this text be drawn as written? False when a character has no glyph
     * in the font — the caller decides how to say so.
     */
    public static function canDraw(string $value, string $font = self::FONT_REGULAR): bool
    {
        $fonts = self::fonts();
        foreach (self::codePoints($value) as $code) {
            if ($code < 0x20) {
                continue;
            }
            if ($fonts === null ? !self::winAnsiCanDraw($code) : $fonts[$font]->glyph($code) === null) {
                return false;
            }
        }

        return true;
    }

    /** Can this one code point be drawn? */
    public static function canDrawCodePoint(int $code, string $font = self::FONT_REGULAR): bool
    {
        $fonts = self::fonts();

        return $fonts === null ? self::winAnsiCanDraw($code) : $fonts[$font]->glyph($code) !== null;
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
        $at = $y ?? $this->cursorY;
        $this->current .= sprintf(
            "BT /%s %.2F Tf %s rg %.2F %.2F Td %s Tj ET\n",
            $font,
            $size,
            $colour,
            $x,
            $at,
            $this->encode($value, $font),
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
     * The width a string takes, from the font's own advance widths — so a
     * right-aligned money column lines up to the point, whatever the digits.
     */
    public static function widthOf(string $value, float $size, string $font = self::FONT_REGULAR): float
    {
        $fonts = self::fonts();
        if ($fonts === null) {
            return self::helveticaWidth($value, $size, $font);
        }

        $face = $fonts[$font];
        $total = 0;
        foreach (self::codePoints($value) as $code) {
            $gid = $face->glyph($code) ?? $face->glyph(0xFFFD) ?? $face->glyph(0x3F) ?? 0;
            $total += $face->advance($gid);
        }

        return $total / $face->unitsPerEm * $size;
    }

    /**
     * Break a string to fit a width, on word boundaries where it can.
     *
     * @return list<string>
     */
    public static function wrap(string $value, float $width, float $size, string $font = self::FONT_REGULAR): array
    {
        $words = preg_split('/\s+/u', trim($value)) ?: [];
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
        $fonts = self::fonts();

        // 1 catalog, 2 pages, 3..(2+n) page objects, then contents, then fonts.
        $firstPageObject = 3;
        $firstContentObject = $firstPageObject + $pageCount;
        $next = $firstContentObject + $pageCount;

        $fontRefs = [];
        if ($fonts === null) {
            foreach ([self::FONT_REGULAR => 'Helvetica', self::FONT_BOLD => 'Helvetica-Bold'] as $key => $base) {
                $objects[$next] = '<< /Type /Font /Subtype /Type1 /BaseFont /' . $base . ' /Encoding /WinAnsiEncoding >>';
                $fontRefs[$key] = $next++;
            }
        } else {
            foreach (self::FONT_FILES as $key => $file) {
                if (($this->used[$key] ?? []) === []) {
                    continue;
                }
                $fontRefs[$key] = $next;
                $next = $this->embedFont($objects, $next, $fonts[$key], $this->used[$key]);
            }
        }

        $kids = [];
        for ($i = 0; $i < $pageCount; $i++) {
            $kids[] = ($firstPageObject + $i) . ' 0 R';
        }

        $resources = '';
        foreach ($fontRefs as $key => $ref) {
            $resources .= '/' . $key . ' ' . $ref . ' 0 R ';
        }

        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[2] = sprintf('<< /Type /Pages /Count %d /Kids [%s] >>', $pageCount, implode(' ', $kids));

        for ($i = 0; $i < $pageCount; $i++) {
            $objects[$firstPageObject + $i] = sprintf(
                '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2F %.2F] /Resources << /Font << %s>> >> /Contents %d 0 R >>',
                $this->width,
                $this->height,
                $resources,
                $firstContentObject + $i,
            );
            $objects[$firstContentObject + $i] = self::stream($pages[$i]);
        }

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
        $count = max(array_keys($objects)) + 1;

        $out .= "xref\n0 " . $count . "\n";
        $out .= "0000000000 65535 f \n";
        for ($number = 1; $number < $count; $number++) {
            $out .= sprintf("%010d 00000 n \n", $offsets[$number] ?? 0);
        }

        $out .= sprintf(
            "trailer\n<< /Size %d /Root 1 0 R /Info << /Title %s /Producer (Aicountly Purchases) >> >>\nstartxref\n%d\n%%%%EOF\n",
            $count,
            self::textString($title),
            $xrefAt,
        );

        return $out;
    }

    // ---------------------------------------------------------------- helpers

    /** @return array<string, TrueTypeFont>|null */
    private static function fonts(): ?array
    {
        if (self::$fonts === null) {
            $directory = self::$fontDirectory ?? dirname(__DIR__, 2) . '/resources/fonts';
            try {
                $loaded = [];
                foreach (self::FONT_FILES as $key => $file) {
                    $loaded[$key] = TrueTypeFont::fromFile($directory . '/' . $file);
                }
                self::$fonts = $loaded;
            } catch (\Throwable $e) {
                error_log('[pdf] font unavailable, falling back to Helvetica: ' . $e->getMessage());
                self::$fonts = [];
            }
        }

        return self::$fonts === [] ? null : self::$fonts;
    }

    /** The PDF string operand for a run of text: glyph ids (hex) or WinAnsi. */
    private function encode(string $value, string $font): string
    {
        $fonts = self::fonts();
        if ($fonts === null) {
            return '(' . self::escape(self::toWinAnsi($value)) . ')';
        }

        $face = $fonts[$font];
        $hex = '';
        foreach (self::codePoints($value) as $code) {
            if ($code < 0x20) {
                $code = 0x20;
            }
            $gid = $face->glyph($code);
            if ($gid === null) {
                // ReportRenderer marks what cannot be drawn before it gets
                // here; this is the last line of defence, and it is visible.
                $code = $face->glyph(0xFFFD) !== null ? 0xFFFD : 0x3F;
                $gid = (int) $face->glyph($code);
            }
            $this->used[$font][$gid] ??= $code;
            $hex .= sprintf('%04X', $gid);
        }

        return '<' . $hex . '>';
    }

    /**
     * Type 0 font → CIDFontType2 → descriptor → subset font file, plus the
     * ToUnicode map. Returns the next free object number.
     *
     * @param array<int, string> $objects
     * @param array<int, int>    $used glyph id → code point
     */
    private function embedFont(array &$objects, int $first, TrueTypeFont $face, array $used): int
    {
        ksort($used);
        $glyphs = array_keys($used);
        // A subset is tagged with six capitals before its name (ISO 32000-1 §9.6.4).
        $tag = '';
        foreach (str_split(substr(md5(implode(',', $glyphs) . $face->postscriptName), 0, 6)) as $char) {
            $tag .= chr(65 + hexdec($char) % 26);
        }
        $name = $tag . '+' . $face->postscriptName;

        $type0 = $first;
        $cid = $first + 1;
        $descriptor = $first + 2;
        $file = $first + 3;
        $toUnicode = $first + 4;

        $scale = 1000 / $face->unitsPerEm;
        $widths = '';
        $run = null;
        $runStart = 0;
        $previous = -2;
        foreach ($glyphs as $gid) {
            $w = (int) round($face->advance($gid) * $scale);
            if ($gid !== $previous + 1) {
                if ($run !== null) {
                    $widths .= $runStart . ' [' . implode(' ', $run) . '] ';
                }
                $run = [];
                $runStart = $gid;
            }
            $run[] = $w;
            $previous = $gid;
        }
        if ($run !== null) {
            $widths .= $runStart . ' [' . implode(' ', $run) . ']';
        }

        $objects[$type0] = sprintf(
            '<< /Type /Font /Subtype /Type0 /BaseFont /%s /Encoding /Identity-H /DescendantFonts [%d 0 R] /ToUnicode %d 0 R >>',
            $name,
            $cid,
            $toUnicode,
        );
        $objects[$cid] = sprintf(
            '<< /Type /Font /Subtype /CIDFontType2 /BaseFont /%s /CIDSystemInfo << /Registry (Adobe) /Ordering (Identity) /Supplement 0 >> /FontDescriptor %d 0 R /DW %d /W [%s] /CIDToGIDMap /Identity >>',
            $name,
            $descriptor,
            (int) round($face->advance(0) * $scale),
            $widths,
        );
        [$x1, $y1, $x2, $y2] = $face->bbox;
        $objects[$descriptor] = sprintf(
            '<< /Type /FontDescriptor /FontName /%s /Flags 32 /FontBBox [%d %d %d %d] /ItalicAngle %d /Ascent %d /Descent %d /CapHeight %d /StemV 80 /FontFile2 %d 0 R >>',
            $name,
            (int) round($x1 * $scale),
            (int) round($y1 * $scale),
            (int) round($x2 * $scale),
            (int) round($y2 * $scale),
            $face->italicAngle,
            (int) round($face->ascent * $scale),
            (int) round($face->descent * $scale),
            (int) round($face->capHeight * $scale),
            $file,
        );

        $subset = $face->subset($glyphs);
        $objects[$file] = self::stream($subset, ['Length1' => strlen($subset)]);

        $map = '';
        foreach (array_chunk($used, 100, true) as $chunk) {
            $map .= count($chunk) . " beginbfchar\n";
            foreach ($chunk as $gid => $code) {
                $map .= sprintf("<%04X> <%s>\n", $gid, strtoupper(bin2hex((string) mb_convert_encoding(self::utf8($code), 'UTF-16BE', 'UTF-8'))));
            }
            $map .= "endbfchar\n";
        }
        $objects[$toUnicode] = self::stream(
            "/CIDInit /ProcSet findresource begin\n12 dict begin\nbegincmap\n"
            . "/CIDSystemInfo << /Registry (Adobe) /Ordering (UCS) /Supplement 0 >> def\n"
            . "/CMapName /Adobe-Identity-UCS def\n/CMapType 2 def\n"
            . "1 begincodespacerange\n<0000> <FFFF>\nendcodespacerange\n"
            . $map
            . "endcmap\nCMapName currentdict /CMap defineresource pop\nend\nend",
        );

        return $first + 5;
    }

    /**
     * A stream object, deflated when zlib is there.
     *
     * @param array<string, int> $extra
     */
    private static function stream(string $content, array $extra = []): string
    {
        $dictionary = '';
        foreach ($extra as $key => $value) {
            $dictionary .= ' /' . $key . ' ' . $value;
        }
        if (function_exists('gzcompress')) {
            $packed = gzcompress($content, 6);
            if ($packed !== false) {
                return sprintf("<< /Length %d /Filter /FlateDecode%s >>\nstream\n%s\nendstream", strlen($packed), $dictionary, $packed);
            }
        }

        return sprintf("<< /Length %d%s >>\nstream\n%s\nendstream", strlen($content), $dictionary, $content);
    }

    /** A text string for the document information dictionary: UTF-16BE with its mark. */
    private static function textString(string $value): string
    {
        $clean = (string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value);
        $utf16 = (string) @mb_convert_encoding($clean, 'UTF-16BE', 'UTF-8');

        return '<FEFF' . strtoupper(bin2hex($utf16)) . '>';
    }

    /** @return list<int> */
    private static function codePoints(string $value): array
    {
        $clean = (string) @mb_convert_encoding($value, 'UTF-8', 'UTF-8');
        $points = [];
        foreach (mb_str_split($clean) as $char) {
            $points[] = mb_ord($char, 'UTF-8');
        }

        return $points;
    }

    private static function utf8(int $code): string
    {
        return (string) mb_chr($code, 'UTF-8');
    }

    // ------------------------------------------------- the Helvetica fallback

    private static function winAnsiCanDraw(int $code): bool
    {
        if ($code < 0x80) {
            return true;
        }
        $char = self::utf8($code);

        return @iconv('UTF-8', 'Windows-1252', self::winAnsiReplacements($char)) !== false;
    }

    private static function winAnsiReplacements(string $value): string
    {
        // The rupee sign is not in WinAnsi at all; "Rs." is what Indian
        // invoices printed for decades. Converted ONCE, here (the old renderer
        // converted twice and the second pass deleted every accented letter).
        return str_replace(['₹', '▲', '▼', '→', '≥', '≤', '−'], ['Rs. ', '+', '-', '->', '>=', '<=', '-'], $value);
    }

    private static function toWinAnsi(string $value): string
    {
        $value = self::winAnsiReplacements($value);
        $converted = @iconv('UTF-8', 'Windows-1252//TRANSLIT', $value);
        if ($converted === false) {
            $converted = @iconv('UTF-8', 'Windows-1252//IGNORE', $value);
        }

        return $converted === false ? (string) preg_replace('/[^\x20-\x7E]/', '?', $value) : $converted;
    }

    private static function helveticaWidth(string $value, float $size, string $font): float
    {
        $bold = $font === self::FONT_BOLD;
        $total = 0.0;
        foreach (str_split(self::toWinAnsi($value)) as $char) {
            $total += match (true) {
                $char === ' ', $char === '.', $char === ',' => 278,
                $char === ':'  => $bold ? 333 : 278,
                $char === '-', $char === '(', $char === ')' => 333,
                $char === '%'  => 889,
                ctype_digit($char) => 556,
                ctype_upper($char) => $bold ? 722 : 667,
                ctype_lower($char) => $bold ? 556 : 500,
                default => $bold ? 600 : 550,
            } / 1000 * $size;
        }

        return $total;
    }

    private static function escape(string $value): string
    {
        return str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', '', ' '], $value);
    }
}
