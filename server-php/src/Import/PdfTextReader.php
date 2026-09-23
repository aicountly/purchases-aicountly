<?php

declare(strict_types=1);

namespace Aicountly\Api\Import;

/**
 * Text out of a PDF, when the PDF has text in it.
 *
 * A supplier statement emailed straight from an accounting package is a text
 * PDF: the characters are in the file and this reads them exactly. A statement
 * that was printed, signed and scanned is an IMAGE, and no amount of parsing
 * will find characters that were never written. This reader says which of the
 * two it was given rather than returning an empty table and letting the screen
 * imply the file was blank.
 *
 * HOW A PAGE BECOMES ROWS. PDF has no concept of a table — it has instructions
 * that place glyphs at coordinates, and a "table" is glyphs that happen to line
 * up. So every text fragment is collected WITH its position, fragments sharing
 * a baseline become a line, and a horizontal gap wider than a space becomes a
 * column break. That is how a human reads a printed statement too.
 *
 * ENTIRELY DETERMINISTIC. No model is consulted here and none is needed: the
 * characters are in the file. The AI path exists for the scanned case only,
 * where there is genuinely nothing to parse — and it is the caller's decision,
 * not this reader's.
 */
final class PdfTextReader
{
    /** Below this, a page is treated as having no text layer rather than a sparse one. */
    private const MIN_CHARS = 24;

    /** A gap wider than this many points between fragments starts a new column. */
    private const COLUMN_GAP = 12.0;

    /** Baselines within this many points are the same line. */
    private const LINE_TOLERANCE = 3.0;

    public static function read(string $content, string $source = 'PDF'): Table
    {
        if (!str_starts_with($content, '%PDF-')) {
            return new Table([], [], $source, ['That file is not a PDF.']);
        }

        if (self::isEncrypted($content)) {
            return new Table([], [], $source, [
                'This PDF is password-protected, so its text cannot be read. Save an unprotected copy and try again.',
            ]);
        }

        $streams = self::contentStreams($content);
        if ($streams === []) {
            return new Table([], [], $source, [
                'Nothing in this PDF could be decompressed. If it was produced by an unusual tool, export it as CSV or XLSX instead.',
            ]);
        }

        $fragments = [];
        foreach ($streams as $stream) {
            foreach (self::fragments($stream) as $fragment) {
                $fragments[] = $fragment;
            }
        }

        $characters = array_sum(array_map(static fn (array $f) => mb_strlen($f['text']), $fragments));
        if ($characters < self::MIN_CHARS) {
            return new Table([], [], $source, [
                'This PDF has no text layer — it is a scan or a picture of a document. '
                . 'Nothing can be read from it directly.',
            ]);
        }

        $rows = self::lines($fragments);
        if ($rows === []) {
            return new Table([], [], $source, ['Text was found but could not be arranged into rows.']);
        }

        $headers = array_shift($rows);

        return (new Table($headers, array_values($rows), $source, [
            'Read from the PDF’s own text. Check the column split before importing — a PDF has no columns, only positions.',
        ]))->tidy();
    }

    /** True when the trailer names an encryption dictionary. */
    private static function isEncrypted(string $content): bool
    {
        return preg_match('/\/Encrypt\s+\d+\s+\d+\s+R/', $content) === 1;
    }

    /**
     * Every stream in the file, decompressed where it is Flate-encoded.
     *
     * @return list<string>
     */
    private static function contentStreams(string $content): array
    {
        $out = [];
        $offset = 0;

        while (($start = strpos($content, 'stream', $offset)) !== false) {
            $dictionaryStart = strrpos(substr($content, 0, $start), 'obj');
            $dictionary = $dictionaryStart === false ? '' : substr($content, $dictionaryStart, $start - $dictionaryStart);

            // The data begins after the EOL that follows the keyword.
            $dataStart = $start + 6;
            if (substr($content, $dataStart, 2) === "\r\n") {
                $dataStart += 2;
            } elseif (in_array(substr($content, $dataStart, 1), ["\n", "\r"], true)) {
                $dataStart += 1;
            }

            $end = strpos($content, 'endstream', $dataStart);
            if ($end === false) {
                break;
            }

            $raw = substr($content, $dataStart, $end - $dataStart);
            $offset = $end + 9;

            // An image is a stream too, and inflating a megapixel scan to hunt
            // for text operators is wasted work on every page of every scan.
            if (preg_match('/\/Subtype\s*\/Image/', $dictionary) === 1) {
                continue;
            }

            if (preg_match('/\/Filter\s*(\[[^\]]*\]|\/\w+)/', $dictionary, $match) === 1) {
                if (!str_contains($match[1], 'FlateDecode')) {
                    // LZW, RunLength, DCT and friends: not worth hand-rolling,
                    // and rare outside images.
                    continue;
                }
                $decoded = @gzuncompress($raw);
                if ($decoded === false) {
                    // Some writers emit raw deflate with no zlib header.
                    $decoded = @gzinflate($raw);
                }
                if ($decoded === false) {
                    continue;
                }
                $out[] = $decoded;
                continue;
            }

            $out[] = $raw;
        }

        return $out;
    }

    /**
     * Text-showing operations, each with the position it was drawn at.
     *
     * @return list<array{x: float, y: float, text: string}>
     */
    private static function fragments(string $stream): array
    {
        $out = [];

        // Only the text objects matter; everything between them is graphics.
        if (preg_match_all('/BT(.*?)ET/s', $stream, $blocks) === false) {
            return $out;
        }

        foreach ($blocks[1] ?? [] as $block) {
            $x = 0.0;
            $y = 0.0;
            $lineX = 0.0;
            $lineY = 0.0;
            $leading = 0.0;

            // NAMED groups, not numbered. A numbered alternation of nine
            // branches is off-by-one the moment a branch is added, and the
            // symptom is not an error — it is silently reading the wrong
            // coordinate and stacking every fragment at the origin.
            preg_match_all(
                '/(?:\[(?P<arr>(?:[^\[\]\\\\]|\\\\.)*)\]\s*TJ)'
                . '|(?:\((?P<lit>(?:[^()\\\\]|\\\\.)*)\)\s*Tj)'
                . '|(?:<(?P<hex>[0-9A-Fa-f\s]*)>\s*Tj)'
                . '|(?:\((?P<nextlit>(?:[^()\\\\]|\\\\.)*)\)\s*\x27)'
                . '|(?:(?P<tdx>-?[\d.]+)\s+(?P<tdy>-?[\d.]+)\s+Td)'
                . '|(?:(?P<tdx2>-?[\d.]+)\s+(?P<tdy2>-?[\d.]+)\s+TD)'
                . '|(?:(?P<tl>-?[\d.]+)\s+TL)'
                . '|(?P<star>T\*)'
                . '|(?:-?[\d.]+\s+-?[\d.]+\s+-?[\d.]+\s+-?[\d.]+\s+(?P<tme>-?[\d.]+)\s+(?P<tmf>-?[\d.]+)\s+Tm)/',
                $block,
                $matches,
                PREG_SET_ORDER,
            );

            foreach ($matches as $match) {
                $has = static fn (string $name): bool => isset($match[$name]) && $match[$name] !== '';

                if ($has('tme')) {
                    $x = $lineX = (float) $match['tme'];
                    $y = $lineY = (float) $match['tmf'];
                    continue;
                }
                if ($has('tl')) {
                    $leading = (float) $match['tl'];
                    continue;
                }
                if ($has('star')) {
                    $x = $lineX;
                    $y = $lineY -= $leading;
                    continue;
                }
                if ($has('tdx2')) {
                    // TD also sets the leading, to the negative of its y move.
                    $leading = -(float) $match['tdy2'];
                    $x = $lineX += (float) $match['tdx2'];
                    $y = $lineY += (float) $match['tdy2'];
                    continue;
                }
                if ($has('tdx')) {
                    $x = $lineX += (float) $match['tdx'];
                    $y = $lineY += (float) $match['tdy'];
                    continue;
                }

                $text = '';
                if ($has('arr')) {
                    $text = self::fromArray($match['arr']);
                } elseif ($has('lit')) {
                    $text = self::unescape($match['lit']);
                } elseif ($has('hex')) {
                    $text = self::fromHex($match['hex']);
                } elseif ($has('nextlit')) {
                    $x = $lineX;
                    $y = $lineY -= $leading;
                    $text = self::unescape($match['nextlit']);
                }

                if (trim($text) !== '') {
                    $out[] = ['x' => $x, 'y' => $y, 'text' => $text];
                    // Advance roughly, so two fragments drawn without an
                    // explicit move do not stack on one coordinate.
                    $x += mb_strlen($text) * 5.0;
                }
            }
        }

        return $out;
    }

    /**
     * A TJ array: strings interleaved with kerning numbers.
     *
     * A large negative number is how a PDF writes a space it did not draw, so
     * the threshold is what turns "TataSteelLtd" into "Tata Steel Ltd".
     */
    private static function fromArray(string $body): string
    {
        preg_match_all('/\(((?:[^()\\\\]|\\\\.)*)\)|<([0-9A-Fa-f\s]*)>|([-\d.]+)/', $body, $parts, PREG_SET_ORDER);

        $text = '';
        foreach ($parts as $part) {
            if (($part[1] ?? '') !== '' || (isset($part[1]) && $part[0] !== '' && $part[0][0] === '(')) {
                $text .= self::unescape($part[1] ?? '');
            } elseif (($part[2] ?? '') !== '') {
                $text .= self::fromHex($part[2]);
            } elseif (($part[3] ?? '') !== '' && (float) $part[3] < -120) {
                $text .= ' ';
            }
        }

        return $text;
    }

    private static function fromHex(string $hex): string
    {
        $clean = preg_replace('/\s+/', '', $hex) ?? '';
        if ($clean === '') {
            return '';
        }
        if (strlen($clean) % 2 === 1) {
            $clean .= '0';
        }

        $bytes = (string) hex2bin($clean);

        // Two-byte codes are UTF-16BE in practice for anything non-Latin.
        if (preg_match('/^(\x00.)+$/s', $bytes) === 1) {
            $converted = @iconv('UTF-16BE', 'UTF-8//IGNORE', $bytes);

            return $converted === false ? $bytes : $converted;
        }

        return $bytes;
    }

    /**
     * A literal string carries the FONT's encoding, which is usually not UTF-8.
     *
     * With WinAnsiEncoding — what almost every generator emits — a rupee sign
     * or an accented supplier name arrives as a single high byte. Left alone it
     * is invalid UTF-8, and everything downstream that touches it (json_encode,
     * a database write, the screen) either fails or mangles it. Text that is
     * already valid UTF-8 is left exactly as it is.
     */
    private static function toUtf8(string $value): string
    {
        if ($value === '' || (function_exists('mb_check_encoding') && mb_check_encoding($value, 'UTF-8'))) {
            return $value;
        }

        $converted = @iconv('Windows-1252', 'UTF-8//IGNORE', $value);

        return $converted === false ? $value : $converted;
    }

    private static function unescape(string $raw): string
    {
        return self::toUtf8((string) preg_replace_callback(
            '/\\\\(n|r|t|b|f|\(|\)|\\\\|[0-7]{1,3})/',
            static function (array $m): string {
                return match ($m[1]) {
                    'n' => "\n",
                    'r' => "\r",
                    't' => "\t",
                    'b' => "\x08",
                    'f' => "\x0C",
                    '(' => '(',
                    ')' => ')',
                    '\\' => '\\',
                    default => chr(octdec($m[1])),
                };
            },
            $raw,
        ));
    }

    /**
     * Fragments to rows: same baseline is one line, a wide gap is a new column.
     *
     * @param list<array{x: float, y: float, text: string}> $fragments
     * @return list<list<string>>
     */
    private static function lines(array $fragments): array
    {
        if ($fragments === []) {
            return [];
        }

        // Top of the page first, then left to right — reading order.
        usort($fragments, static function (array $a, array $b): int {
            if (abs($a['y'] - $b['y']) > self::LINE_TOLERANCE) {
                return $b['y'] <=> $a['y'];
            }

            return $a['x'] <=> $b['x'];
        });

        $rows = [];
        $current = [];
        $currentY = null;
        $lastX = null;

        foreach ($fragments as $fragment) {
            if ($currentY !== null && abs($fragment['y'] - $currentY) > self::LINE_TOLERANCE) {
                $rows[] = $current;
                $current = [];
                $lastX = null;
            }
            $currentY = $fragment['y'];

            $text = trim($fragment['text']);
            if ($text === '') {
                continue;
            }

            if ($lastX !== null && $fragment['x'] - $lastX < self::COLUMN_GAP && $current !== []) {
                // Close enough to be the same cell, split only by kerning.
                $current[count($current) - 1] = rtrim($current[count($current) - 1]) . ' ' . $text;
            } else {
                $current[] = $text;
            }

            $lastX = $fragment['x'] + mb_strlen($text) * 5.0;
        }

        if ($current !== []) {
            $rows[] = $current;
        }

        return array_values(array_filter($rows, static fn (array $row) => $row !== []));
    }
}
