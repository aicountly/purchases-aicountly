<?php

declare(strict_types=1);

namespace Aicountly\Api\Import;

/**
 * CSV, including the ones that are not commas.
 *
 * Indian accounting exports are semicolon-separated about as often as they are
 * comma-separated, tab-separated when they came out of a mainframe, and
 * pipe-separated when somebody wrote the exporter themselves. Asking the user
 * which one is asking them to open the file in a text editor to find out.
 *
 * The delimiter is DECIDED BY COUNTING, not guessed: whichever candidate
 * produces the most consistent column count across the first rows wins, and a
 * tie goes to the comma. That is testable and it is right for the reason it is
 * right, rather than by luck.
 */
final class CsvReader
{
    private const CANDIDATES = [',', ';', "\t", '|'];
    private const SNIFF_ROWS = 20;

    public static function read(string $content, string $source = 'CSV'): Table
    {
        $notes = [];

        $content = self::decode($content, $notes);
        $delimiter = self::sniff($content);
        if ($delimiter !== ',') {
            $notes[] = 'Read as ' . self::name($delimiter) . '-separated, not comma-separated.';
        }

        $handle = fopen('php://memory', 'r+');
        if ($handle === false) {
            return new Table([], [], $source, ['Could not open the file for reading.']);
        }
        fwrite($handle, $content);
        rewind($handle);

        $rows = [];
        while (($row = fgetcsv($handle, 0, $delimiter, '"', '\\')) !== false) {
            // fgetcsv reports a blank line as [null]; that is a blank line, not
            // a row with one null cell.
            if ($row === [null]) {
                continue;
            }
            $rows[] = array_map(static fn ($cell) => trim((string) ($cell ?? '')), $row);
        }
        fclose($handle);

        if ($rows === []) {
            return new Table([], [], $source, [...$notes, 'The file has no rows.']);
        }

        $headers = array_shift($rows);

        return (new Table($headers, array_values($rows), $source, $notes))->tidy();
    }

    /**
     * Get the bytes into UTF-8, and say so when a guess was needed.
     *
     * A statement saved from Excel on a Windows machine is very often CP1252,
     * and the giveaway is a rupee sign or a supplier name turning into mojibake
     * rather than the file failing to open.
     *
     * @param list<string> $notes
     */
    private static function decode(string $content, array &$notes): string
    {
        // UTF-8 BOM: strip it, or the first header carries three invisible
        // characters and never matches anything.
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            return substr($content, 3);
        }
        if (str_starts_with($content, "\xFF\xFE") || str_starts_with($content, "\xFE\xFF")) {
            $converted = @iconv(str_starts_with($content, "\xFF\xFE") ? 'UTF-16LE' : 'UTF-16BE', 'UTF-8//IGNORE', substr($content, 2));
            $notes[] = 'The file was UTF-16; it has been read as text.';

            return $converted === false ? $content : $converted;
        }

        if (function_exists('mb_check_encoding') && mb_check_encoding($content, 'UTF-8')) {
            return $content;
        }

        $converted = @iconv('CP1252', 'UTF-8//IGNORE', $content);
        if ($converted !== false) {
            $notes[] = 'The file was not UTF-8; it has been read as Windows-1252. Check any accented names.';

            return $converted;
        }

        $notes[] = 'The file’s encoding could not be identified; unusual characters may be wrong.';

        return $content;
    }

    private static function sniff(string $content): string
    {
        $lines = array_slice(preg_split('/\r\n|\r|\n/', $content) ?: [], 0, self::SNIFF_ROWS);
        $lines = array_values(array_filter($lines, static fn (string $line) => trim($line) !== ''));
        if ($lines === []) {
            return ',';
        }

        $best = ',';
        $bestScore = -1.0;

        foreach (self::CANDIDATES as $candidate) {
            $counts = [];
            foreach ($lines as $line) {
                $counts[] = substr_count($line, $candidate);
            }
            $max = max($counts);
            if ($max === 0) {
                continue;
            }
            // Consistency first — a delimiter that yields the same column count
            // on every line is the delimiter. Width only breaks ties.
            $mode = self::mode($counts);
            $consistency = count(array_filter($counts, static fn (int $n) => $n === $mode)) / count($counts);
            $score = $consistency * 100 + $mode;

            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $candidate;
            }
        }

        return $best;
    }

    /** @param list<int> $values */
    private static function mode(array $values): int
    {
        $tally = array_count_values($values);
        arsort($tally);

        return (int) array_key_first($tally);
    }

    private static function name(string $delimiter): string
    {
        return match ($delimiter) {
            ';'  => 'semicolon',
            "\t" => 'tab',
            '|'  => 'pipe',
            default => 'comma',
        };
    }
}
