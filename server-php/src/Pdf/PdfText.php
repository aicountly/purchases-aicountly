<?php

declare(strict_types=1);

namespace Aicountly\Api\Pdf;

/**
 * The text of a PDF this renderer wrote, read back the way a PDF reader reads
 * it: deflated streams inflated, glyph ids turned back into characters through
 * each font's ToUnicode map.
 *
 * It exists so the suite (and anybody checking an export without poppler
 * installed) can assert what a report PRINTS — "Café Müller", "₹12,50,000.55" —
 * rather than what bytes happen to sit in the file. With an embedded CID font
 * the content stream holds glyph ids, not letters, so a byte search proves
 * nothing either way (ANA-13). Only this renderer's output is in scope: one
 * text-showing operator per BT…ET block, hex (Identity-H) or literal (the
 * Helvetica fallback) strings.
 */
final class PdfText
{
    /** One line per text block, in drawing order, pages separated by a form feed. */
    public static function extract(string $pdf): string
    {
        $objects = [];
        if (preg_match_all('/(\d+) 0 obj\n(.*?)\nendobj/s', $pdf, $matches, PREG_SET_ORDER) > 0) {
            foreach ($matches as $m) {
                $objects[(int) $m[1]] = $m[2];
            }
        }

        $maps = [];
        foreach ($objects as $number => $body) {
            if (str_contains($body, '/Subtype /Type0') && preg_match('#/ToUnicode (\d+) 0 R#', $body, $ref) === 1) {
                $maps[$number] = self::cmap(self::streamOf($objects[(int) $ref[1]] ?? ''));
            }
        }

        $pages = [];
        foreach ($objects as $body) {
            if (!str_contains($body, '/Type /Page ') && !str_contains($body, '/Type /Page>>')) {
                continue;
            }
            $fonts = [];
            if (preg_match('#/Font << (.*?)>>#', $body, $fontBlock) === 1
                && preg_match_all('#/(F\d+) (\d+) 0 R#', $fontBlock[1], $refs, PREG_SET_ORDER) > 0) {
                foreach ($refs as $r) {
                    $fonts[$r[1]] = $maps[(int) $r[2]] ?? null;
                }
            }
            if (preg_match('#/Contents (\d+) 0 R#', $body, $contents) !== 1) {
                continue;
            }
            $pages[] = self::page(self::streamOf($objects[(int) $contents[1]] ?? ''), $fonts);
        }

        return implode("\f", $pages);
    }

    /** @param array<string, array<int, string>|null> $fonts */
    private static function page(string $stream, array $fonts): string
    {
        $lines = [];
        if (preg_match_all('#BT /(F\d+) [\d.]+ Tf .*? Td (<[0-9A-Fa-f]*>|\((?:\\\\.|[^\\\\)])*\)) Tj ET#s', $stream, $runs, PREG_SET_ORDER) === false) {
            return '';
        }
        foreach ($runs as $run) {
            $operand = $run[2];
            if ($operand[0] === '<') {
                $map = $fonts[$run[1]] ?? [];
                $text = '';
                foreach (str_split(substr($operand, 1, -1), 4) as $hex) {
                    if (strlen($hex) === 4) {
                        $text .= $map[hexdec($hex)] ?? "\u{FFFD}";
                    }
                }
                $lines[] = $text;
                continue;
            }
            $literal = (string) preg_replace_callback('/\\\\(.)/s', static fn (array $e) => $e[1], substr($operand, 1, -1));
            $lines[] = (string) @iconv('Windows-1252', 'UTF-8//IGNORE', $literal);
        }

        return implode("\n", $lines);
    }

    /** @return array<int, string> glyph id → text */
    private static function cmap(string $stream): array
    {
        $map = [];
        if (preg_match_all('/<([0-9A-Fa-f]{4})> <([0-9A-Fa-f]+)>/', $stream, $pairs, PREG_SET_ORDER) > 0) {
            foreach ($pairs as $pair) {
                $map[hexdec($pair[1])] = (string) @mb_convert_encoding((string) hex2bin($pair[2]), 'UTF-8', 'UTF-16BE');
            }
        }

        return $map;
    }

    private static function streamOf(string $object): string
    {
        $start = strpos($object, "stream\n");
        $end = strrpos($object, "\nendstream");
        if ($start === false || $end === false) {
            return '';
        }
        $data = substr($object, $start + 7, $end - $start - 7);
        if (str_contains(substr($object, 0, $start), '/FlateDecode')) {
            $inflated = @gzuncompress($data);

            return $inflated === false ? '' : $inflated;
        }

        return $data;
    }
}
