<?php

declare(strict_types=1);

namespace Aicountly\Api\Pdf;

/**
 * A TrueType font, read just far enough to embed it in a PDF.
 *
 * WHY THIS EXISTS (ANA-13, D-19). The renderer used the base-fourteen Helvetica,
 * which can only write WinAnsi: "Café Müller" printed as "Caf Mller", an en
 * dash vanished and the rupee sign became "INR" or "?". A PDF can carry any
 * script a font has glyphs for, if the font travels with it — so the report
 * embeds DejaVu Sans (server-php/resources/fonts, Bitstream Vera licence) as a
 * CID font with Identity-H encoding and a ToUnicode map.
 *
 * WHAT IT READS: the cmap (format 4 and 12), advance widths, the bounding box
 * and vertical metrics, and the glyf/loca outlines. WHAT IT WRITES: a subset —
 * the same font with every glyph the report did not use emptied out (glyph ids
 * are kept, so the PDF maps CID = glyph id), carrying only the tables a PDF
 * reader needs (ISO 32000-1 §9.9: head, hhea, hmtx, loca, maxp, glyf). A report
 * page costs a few kilobytes of font, not 140.
 *
 * No composer and no library, for the same reason as PdfDocument and
 * XlsxWriter: this API has no third-party dependencies, and a font table walk
 * is small enough to keep honest.
 */
final class TrueTypeFont
{
    public readonly string $postscriptName;
    public readonly int $unitsPerEm;
    public readonly int $ascent;
    public readonly int $descent;
    public readonly int $capHeight;
    public readonly int $italicAngle;
    /** @var array{0:int,1:int,2:int,3:int} */
    public readonly array $bbox;
    public readonly int $numGlyphs;

    /** @var array<string, array{offset:int, length:int}> */
    private array $tables = [];

    /** @var array<int, int> code point → glyph id */
    private array $cmap = [];

    /** @var list<int> advance width per glyph id, in font units */
    private array $advances = [];

    /** @var array<int, int> glyph id → the first code point that maps to it */
    private array $reverse = [];

    private function __construct(private readonly string $data)
    {
        $this->readDirectory();

        $head = $this->table('head');
        $this->unitsPerEm = $this->u16($head + 18);
        $this->bbox = [$this->i16($head + 36), $this->i16($head + 38), $this->i16($head + 40), $this->i16($head + 42)];

        $hhea = $this->table('hhea');
        $hheaAscent = $this->i16($hhea + 4);
        $hheaDescent = $this->i16($hhea + 6);
        $hMetrics = $this->u16($hhea + 34);

        $this->numGlyphs = $this->u16($this->table('maxp') + 4);

        $capHeight = (int) round($hheaAscent * 0.72);
        if (isset($this->tables['OS/2'])) {
            $os2 = $this->tables['OS/2']['offset'];
            if ($this->u16($os2) >= 2 && $this->tables['OS/2']['length'] >= 90) {
                $capHeight = $this->i16($os2 + 88);
            }
        }
        $this->ascent = $hheaAscent;
        $this->descent = $hheaDescent;
        $this->capHeight = $capHeight;

        $italic = 0;
        if (isset($this->tables['post'])) {
            $italic = (int) round($this->i32($this->tables['post']['offset'] + 4) / 65536);
        }
        $this->italicAngle = $italic;

        $hmtx = $this->table('hmtx');
        $last = 0;
        for ($gid = 0; $gid < $this->numGlyphs; $gid++) {
            if ($gid < $hMetrics) {
                $last = $this->u16($hmtx + $gid * 4);
            }
            $this->advances[] = $last;
        }

        $this->readCmap();
        foreach ($this->cmap as $code => $gid) {
            $this->reverse[$gid] ??= $code;
        }

        $this->postscriptName = $this->readPostscriptName() ?? 'EmbeddedFont';
    }

    public static function fromFile(string $path): self
    {
        $data = @file_get_contents($path);
        if ($data === false || strlen($data) < 12) {
            throw new \RuntimeException('Font not readable: ' . basename($path));
        }

        return new self($data);
    }

    /** The glyph for a code point, or null when the font has none. */
    public function glyph(int $codePoint): ?int
    {
        $gid = $this->cmap[$codePoint] ?? null;

        return $gid === null || $gid === 0 ? null : $gid;
    }

    /** Advance width of a glyph in font units. */
    public function advance(int $gid): int
    {
        return $this->advances[$gid] ?? 0;
    }

    /** The code point a glyph was reached from (for the ToUnicode map). */
    public function codePointOf(int $gid): ?int
    {
        return $this->reverse[$gid] ?? null;
    }

    /**
     * A copy of the font holding only these glyphs' outlines (plus .notdef and
     * any component a composite glyph draws). Glyph ids are unchanged.
     *
     * @param list<int> $glyphs
     */
    public function subset(array $glyphs): string
    {
        $keep = [0 => true];
        foreach ($glyphs as $gid) {
            if ($gid >= 0 && $gid < $this->numGlyphs) {
                $keep[$gid] = true;
            }
        }

        $glyf = $this->table('glyf');
        $locaFormat = $this->i16($this->table('head') + 50);
        $loca = $this->table('loca');
        $offsetOf = function (int $gid) use ($loca, $locaFormat): int {
            return $locaFormat === 0 ? $this->u16($loca + $gid * 2) * 2 : $this->u32($loca + $gid * 4);
        };

        // Composite glyphs draw other glyphs: keep those too, to any depth.
        $queue = array_keys($keep);
        while ($queue !== []) {
            $gid = array_pop($queue);
            $start = $offsetOf($gid);
            $end = $offsetOf($gid + 1);
            if ($end - $start < 10 || $this->i16($glyf + $start) >= 0) {
                continue;
            }
            $at = $glyf + $start + 10;
            do {
                $flags = $this->u16($at);
                $component = $this->u16($at + 2);
                if (!isset($keep[$component])) {
                    $keep[$component] = true;
                    $queue[] = $component;
                }
                $at += 4 + (($flags & 0x0001) !== 0 ? 4 : 2);
                if (($flags & 0x0008) !== 0) {
                    $at += 2;
                } elseif (($flags & 0x0040) !== 0) {
                    $at += 4;
                } elseif (($flags & 0x0080) !== 0) {
                    $at += 8;
                }
            } while (($flags & 0x0020) !== 0);
        }

        // New glyf and a long-format loca: unused glyphs become empty.
        $newGlyf = '';
        $newLoca = '';
        for ($gid = 0; $gid < $this->numGlyphs; $gid++) {
            $newLoca .= pack('N', strlen($newGlyf));
            if (!isset($keep[$gid])) {
                continue;
            }
            $start = $offsetOf($gid);
            $length = $offsetOf($gid + 1) - $start;
            if ($length > 0) {
                $newGlyf .= substr($this->data, $glyf + $start, $length);
                $newGlyf .= str_repeat("\0", (4 - strlen($newGlyf) % 4) % 4);
            }
        }
        $newLoca .= pack('N', strlen($newGlyf));

        $head = $this->raw('head');
        $head = substr_replace($head, pack('N', 0), 8, 4);   // checkSumAdjustment, set below
        $head = substr_replace($head, pack('n', 1), 50, 2);  // indexToLocFormat: long

        $tables = [
            'glyf' => $newGlyf,
            'head' => $head,
            'hhea' => $this->raw('hhea'),
            'hmtx' => $this->raw('hmtx'),
            'loca' => $newLoca,
            'maxp' => $this->raw('maxp'),
        ];
        foreach (['cvt ', 'fpgm', 'prep'] as $hinting) {
            if (isset($this->tables[$hinting])) {
                $tables[$hinting] = $this->raw($hinting);
            }
        }
        ksort($tables, SORT_STRING);

        $count = count($tables);
        $power = 1;
        $log = 0;
        while ($power * 2 <= $count) {
            $power *= 2;
            $log++;
        }
        $font = pack('Nnnnn', 0x00010000, $count, $power * 16, $log, $count * 16 - $power * 16);

        $offset = 12 + 16 * $count;
        $directory = '';
        $body = '';
        $headAt = 0;
        foreach ($tables as $tag => $content) {
            $padded = $content . str_repeat("\0", (4 - strlen($content) % 4) % 4);
            if ($tag === 'head') {
                $headAt = $offset;
            }
            $directory .= $tag . pack('NNN', self::checksum($padded), $offset, strlen($content));
            $body .= $padded;
            $offset += strlen($padded);
        }

        $out = $font . $directory . $body;
        $adjustment = (0xB1B0AFBA - self::checksum($out)) & 0xFFFFFFFF;

        return substr_replace($out, pack('N', $adjustment), $headAt + 8, 4);
    }

    // -----------------------------------------------------------------------

    private function readDirectory(): void
    {
        $version = $this->u32(0);
        if ($version !== 0x00010000 && $version !== 0x74727565) {
            throw new \RuntimeException('Not a TrueType font (outline format not supported).');
        }
        $count = $this->u16(4);
        for ($i = 0; $i < $count; $i++) {
            $at = 12 + $i * 16;
            $tag = substr($this->data, $at, 4);
            $this->tables[$tag] = ['offset' => $this->u32($at + 8), 'length' => $this->u32($at + 12)];
        }
        foreach (['head', 'hhea', 'hmtx', 'maxp', 'loca', 'glyf', 'cmap'] as $required) {
            if (!isset($this->tables[$required])) {
                throw new \RuntimeException('The font has no ' . $required . ' table.');
            }
        }
    }

    private function readCmap(): void
    {
        $cmap = $this->table('cmap');
        $count = $this->u16($cmap + 2);
        $best = null;
        $bestRank = -1;
        for ($i = 0; $i < $count; $i++) {
            $at = $cmap + 4 + $i * 8;
            $platform = $this->u16($at);
            $encoding = $this->u16($at + 2);
            $offset = $cmap + $this->u32($at + 4);
            $format = $this->u16($offset);
            $rank = match (true) {
                $platform === 3 && $encoding === 10 && $format === 12 => 3,
                $platform === 0 && $format === 12                     => 2,
                $platform === 3 && $encoding === 1 && $format === 4   => 1,
                $platform === 0 && $format === 4                      => 0,
                default                                              => -1,
            };
            if ($rank > $bestRank) {
                $bestRank = $rank;
                $best = $offset;
            }
        }
        if ($best === null) {
            throw new \RuntimeException('The font has no Unicode cmap.');
        }

        if ($this->u16($best) === 12) {
            $groups = $this->u32($best + 12);
            for ($g = 0; $g < $groups; $g++) {
                $at = $best + 16 + $g * 12;
                $start = $this->u32($at);
                $end = min($this->u32($at + 4), $start + 0xFFFF);
                $glyph = $this->u32($at + 8);
                for ($code = $start; $code <= $end; $code++) {
                    $this->cmap[$code] = $glyph + ($code - $start);
                }
            }

            return;
        }

        $segments = $this->u16($best + 6) / 2;
        $ends = $best + 14;
        $starts = $ends + $segments * 2 + 2;
        $deltas = $starts + $segments * 2;
        $ranges = $deltas + $segments * 2;
        for ($s = 0; $s < $segments; $s++) {
            $end = $this->u16($ends + $s * 2);
            $start = $this->u16($starts + $s * 2);
            $delta = $this->i16($deltas + $s * 2);
            $rangeAt = $ranges + $s * 2;
            $range = $this->u16($rangeAt);
            for ($code = $start; $code <= $end && $code !== 0xFFFF; $code++) {
                if ($range === 0) {
                    $glyph = ($code + $delta) & 0xFFFF;
                } else {
                    $glyph = $this->u16($rangeAt + $range + ($code - $start) * 2);
                    if ($glyph !== 0) {
                        $glyph = ($glyph + $delta) & 0xFFFF;
                    }
                }
                if ($glyph !== 0) {
                    $this->cmap[$code] = $glyph;
                }
            }
        }
    }

    private function readPostscriptName(): ?string
    {
        if (!isset($this->tables['name'])) {
            return null;
        }
        $name = $this->tables['name']['offset'];
        $count = $this->u16($name + 2);
        $strings = $name + $this->u16($name + 4);
        for ($i = 0; $i < $count; $i++) {
            $at = $name + 6 + $i * 12;
            if ($this->u16($at + 6) !== 6) {
                continue;
            }
            $platform = $this->u16($at);
            $raw = substr($this->data, $strings + $this->u16($at + 10), $this->u16($at + 8));
            $text = $platform === 3 || $platform === 0 ? (string) @mb_convert_encoding($raw, 'UTF-8', 'UTF-16BE') : $raw;
            $clean = (string) preg_replace('/[^A-Za-z0-9-]/', '', $text);
            if ($clean !== '') {
                return $clean;
            }
        }

        return null;
    }

    private function table(string $tag): int
    {
        return $this->tables[$tag]['offset'];
    }

    private function raw(string $tag): string
    {
        return substr($this->data, $this->tables[$tag]['offset'], $this->tables[$tag]['length']);
    }

    private function u16(int $at): int
    {
        return (ord($this->data[$at]) << 8) | ord($this->data[$at + 1]);
    }

    private function i16(int $at): int
    {
        $v = $this->u16($at);

        return $v >= 0x8000 ? $v - 0x10000 : $v;
    }

    private function u32(int $at): int
    {
        return (int) unpack('N', substr($this->data, $at, 4))[1];
    }

    private function i32(int $at): int
    {
        $v = $this->u32($at);

        return $v >= 0x80000000 ? $v - 0x100000000 : $v;
    }

    private static function checksum(string $data): int
    {
        $data .= str_repeat("\0", (4 - strlen($data) % 4) % 4);
        $sum = 0;
        foreach (unpack('N*', $data) ?: [] as $word) {
            $sum = ($sum + $word) & 0xFFFFFFFF;
        }

        return $sum;
    }
}
