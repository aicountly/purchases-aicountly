<?php

declare(strict_types=1);

namespace Aicountly\Api\Import;

/**
 * A parsed document, as rows of strings and nothing more.
 *
 * WHY EVERY CELL IS A STRING. A supplier's statement holds 1,23,456.78 and
 * 1.234,56 and (2,500.00) and "2500-", all meaning a number, and every one of
 * them becomes a different float depending on who parses it. Interpretation is
 * a separate, explicit step with its own rules and its own tests; the reader's
 * only job is to get the characters out of the file intact.
 *
 * `notes` is what the reader noticed and could not fix — a sheet it skipped, an
 * encoding it had to guess, a page with no text layer. It is carried to the
 * screen rather than logged, because the person who chose the file is the only
 * one who can say whether the guess was right.
 */
final class Table
{
    /**
     * @param list<string> $headers
     * @param list<list<string>> $rows
     * @param list<string> $notes
     */
    public function __construct(
        public readonly array $headers,
        public readonly array $rows,
        public readonly string $source,
        public readonly array $notes = [],
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->rows === [];
    }

    public function width(): int
    {
        $width = count($this->headers);
        foreach ($this->rows as $row) {
            $width = max($width, count($row));
        }

        return $width;
    }

    /** @return list<string> */
    public function column(int $index): array
    {
        return array_map(static fn (array $row) => $row[$index] ?? '', $this->rows);
    }

    /** @param list<string> $notes */
    public function withNotes(array $notes): self
    {
        return new self($this->headers, $this->rows, $this->source, [...$this->notes, ...$notes]);
    }

    /**
     * Drop rows that are entirely blank, and trailing blank columns.
     *
     * Spreadsheets are full of both: a row somebody cleared but did not delete
     * still occupies a row, and a sheet dragged wider than its data carries
     * empty columns to the right of everything.
     */
    public function tidy(): self
    {
        $rows = array_values(array_filter(
            $this->rows,
            static fn (array $row) => array_filter($row, static fn (string $cell) => trim($cell) !== '') !== [],
        ));

        $width = 0;
        foreach ([$this->headers, ...$rows] as $row) {
            for ($i = count($row) - 1; $i >= 0; $i--) {
                if (trim((string) ($row[$i] ?? '')) !== '') {
                    $width = max($width, $i + 1);
                    break;
                }
            }
        }

        $trim = static fn (array $row): array => array_slice(array_pad($row, $width, ''), 0, $width);

        return new self(
            $trim($this->headers),
            array_map($trim, $rows),
            $this->source,
            $this->notes,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(int $sampleRows = 50): array
    {
        return [
            'source'      => $this->source,
            'headers'     => $this->headers,
            'rows'        => array_slice($this->rows, 0, $sampleRows),
            'row_count'   => count($this->rows),
            'truncated'   => count($this->rows) > $sampleRows,
            'notes'       => $this->notes,
        ];
    }
}
