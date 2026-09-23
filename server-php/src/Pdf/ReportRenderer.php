<?php

declare(strict_types=1);

namespace Aicountly\Api\Pdf;

/**
 * A dashboard as a printable report.
 *
 * SAME PAYLOAD AS THE SCREEN AND THE CSV. All three render the array the
 * dashboard class built, so a figure cannot differ between what somebody saw,
 * what they exported and what they printed. The alternative — a report that
 * re-queries — is how a PDF ends up disagreeing with the screen it was printed
 * from, and nobody can say which one is wrong.
 *
 * AN UNAVAILABLE FIGURE PRINTS AS "Unavailable". On paper this matters more
 * than anywhere else: a screen can be refreshed and questioned, and a printout
 * gets circulated, filed and quoted six months later. A dash or a zero standing
 * in for "we could not ask" becomes a fact the moment it is printed.
 */
final class ReportRenderer
{
    private const INK = '0.08 0.09 0.10';
    private const MUTED = '0.42 0.45 0.43';
    private const BRAND = '0.09 0.48 0.07';
    private const RULE = '0.87 0.89 0.87';
    private const BAND = '0.97 0.98 0.97';

    /** @param array<string, mixed> $payload */
    public static function render(array $payload, string $title, string $companyLabel): string
    {
        $pdf = new PdfDocument();
        $left = $pdf->margin;
        $right = $pdf->width - $pdf->margin;

        self::heading($pdf, $title, $companyLabel, $payload);
        self::sources($pdf, $payload, $left, $right);
        self::metrics($pdf, $payload, $left, $right);
        self::panels($pdf, $payload, $left, $right);
        self::footer($pdf, $payload, $left, $right);

        return $pdf->render($title);
    }

    /** @param array<string, mixed> $payload */
    private static function heading(PdfDocument $pdf, string $title, string $companyLabel, array $payload): void
    {
        $left = $pdf->margin;
        $right = $pdf->width - $pdf->margin;
        $period = (array) ($payload['period'] ?? []);
        $scope = (array) ($payload['scope'] ?? []);

        $pdf->text('AICOUNTLY PURCHASES', $left, 7.5, PdfDocument::FONT_BOLD, self::BRAND);
        $pdf->advance(16);
        $pdf->text($title, $left, 20, PdfDocument::FONT_BOLD, self::INK);
        $pdf->advance(16);
        $pdf->text($companyLabel, $left, 10, PdfDocument::FONT_REGULAR, self::MUTED);
        $pdf->advance(13);
        $pdf->text(
            trim(((string) ($period['label'] ?? '')) . '  ·  ' . ((string) ($scope['branch_label'] ?? ''))),
            $left,
            10,
            PdfDocument::FONT_REGULAR,
            self::MUTED,
        );
        $pdf->advance(12);
        $pdf->rule($left, $right, null, self::RULE, 1.0);
        $pdf->advance(20);
    }

    /** @param array<string, mixed> $payload */
    private static function sources(PdfDocument $pdf, array $payload, float $left, float $right): void
    {
        $sources = (array) ($payload['sources'] ?? []);
        if ($sources === []) {
            return;
        }

        $parts = [];
        foreach ($sources as $source) {
            $parts[] = ((string) ($source['label'] ?? '')) . ': ' . ((string) ($source['status_label'] ?? ''));
        }

        $pdf->text('Sources — ' . implode('   ·   ', $parts), $left, 8.5, PdfDocument::FONT_REGULAR, self::MUTED);
        $pdf->advance(14);

        // A source that did not answer gets its reason printed, not just a
        // status word: the reader of a printout cannot hover over anything.
        foreach ($sources as $source) {
            $message = trim((string) ($source['message'] ?? ''));
            if ($message === '' || ($source['status'] ?? '') === 'ready') {
                continue;
            }
            foreach (PdfDocument::wrap($source['label'] . ': ' . $message, $right - $left, 8.5) as $line) {
                $pdf->text($line, $left, 8.5, PdfDocument::FONT_REGULAR, self::MUTED);
                $pdf->advance(10);
            }
        }

        $pdf->advance(8);
    }

    /** @param array<string, mixed> $payload */
    private static function metrics(PdfDocument $pdf, array $payload, float $left, float $right): void
    {
        $metrics = (array) ($payload['metrics'] ?? []);
        if ($metrics === []) {
            return;
        }

        self::sectionTitle($pdf, 'Key figures', $left, $right);

        foreach ($metrics as $metric) {
            $available = ($metric['status'] ?? '') === 'ready';
            $value = $available
                ? (string) ($metric['formatted_value'] ?? '')
                : 'Unavailable';
            $note = $available
                ? (string) ($metric['comparison_text'] ?? '')
                : (string) ($metric['unavailable_reason'] ?? 'Could not be read.');

            $basis = PdfDocument::wrap((string) ($metric['basis'] ?? ''), $right - $left - 170, 8);

            // Keep a whole card together rather than splitting its basis across
            // a page break from its number.
            $needed = 20 + count($basis) * 9.5;
            if (!$pdf->fits($needed)) {
                $pdf->newPage();
            }

            $pdf->fill($left - 4, $pdf->y() - 6, $right - $left + 8, $needed, self::BAND);

            $pdf->text(PdfDocument::clip((string) ($metric['label'] ?? ''), 240, 10, PdfDocument::FONT_BOLD), $left, 10, PdfDocument::FONT_BOLD, self::INK);
            $pdf->textRight($value, $right, $available ? 12 : 9.5, PdfDocument::FONT_BOLD, $available ? self::INK : self::MUTED);
            $pdf->advance(12);
            $pdf->text(PdfDocument::clip($note, $right - $left, 8.5), $left, 8.5, PdfDocument::FONT_REGULAR, self::MUTED);
            $pdf->advance(10);

            foreach ($basis as $line) {
                $pdf->text($line, $left, 8, PdfDocument::FONT_REGULAR, self::MUTED);
                $pdf->advance(9.5);
            }

            $pdf->advance(8);
        }
    }

    /**
     * Every panel that carries a table, printed as one.
     *
     * A panel this cannot render — a chart, a drawer, a free-form note — is
     * NAMED and skipped rather than silently dropped, so the reader knows the
     * screen has more on it than the paper does.
     *
     * @param array<string, mixed> $payload
     */
    private static function panels(PdfDocument $pdf, array $payload, float $left, float $right): void
    {
        $skipped = [];

        foreach ((array) ($payload['panels'] ?? []) as $key => $panel) {
            if (!is_array($panel)) {
                continue;
            }

            $label = ucfirst(str_replace('_', ' ', (string) $key));

            if (($panel['available'] ?? true) === false) {
                self::sectionTitle($pdf, $label, $left, $right);
                foreach (PdfDocument::wrap('Unavailable. ' . (string) ($panel['reason'] ?? ''), $right - $left, 9) as $line) {
                    $pdf->text($line, $left, 9, PdfDocument::FONT_REGULAR, self::MUTED);
                    $pdf->advance(11);
                }
                $pdf->advance(8);
                continue;
            }

            $rows = self::tabular($panel);
            if ($rows === null) {
                $skipped[] = $label;
                continue;
            }

            self::table($pdf, $label, $rows, $left, $right);
        }

        if ($skipped !== []) {
            $pdf->advance(4);
            foreach (PdfDocument::wrap(
                'Not printed, because they are charts or interactive panels rather than tables: '
                . implode(', ', $skipped) . '. They are on the screen this was printed from.',
                $right - $left,
                8,
            ) as $line) {
                $pdf->text($line, $left, 8, PdfDocument::FONT_REGULAR, self::MUTED);
                $pdf->advance(9.5);
            }
        }
    }

    /**
     * The first list-of-rows in a panel, and its column names.
     *
     * @param array<string, mixed> $panel
     * @return array{0: list<string>, 1: list<list<string>>}|null
     */
    private static function tabular(array $panel): ?array
    {
        foreach (['rows', 'suppliers', 'points', 'buckets', 'stages', 'items'] as $key) {
            $candidate = $panel[$key] ?? null;
            if (!is_array($candidate) || $candidate === [] || !is_array($candidate[0] ?? null)) {
                continue;
            }

            // Scalar columns only. A nested array in a cell is a structure, and
            // flattening it into a table cell produces "Array" or a wall of
            // JSON — neither is a report.
            // Columns a reader would use, not the ones the screen needs to
            // work. An id, a route and a filter set are how the app navigates
            // itself; on paper they are noise that pushes the real figures off
            // the edge. And where a value has a formatted twin, the raw one is
            // dropped: printing 1166400 beside Rs. 11,66,400.00 is the same
            // number twice, and the unformatted one is the less readable half.
            $plumbing = ['id', 'route', 'filters', 'key', 'kind', 'entity_id', 'tone'];
            $keys = array_keys($candidate[0]);
            // Both spellings are in use across the panels: `amount_formatted`
            // and `formatted_amount`. Handling only one leaves the raw twin on
            // the page next to its readable version.
            $formattedTwins = [];
            foreach ($keys as $key) {
                $name = (string) $key;
                if (str_ends_with($name, '_formatted')) {
                    $formattedTwins[] = substr($name, 0, -10);
                } elseif (str_starts_with($name, 'formatted_')) {
                    $formattedTwins[] = substr($name, 10);
                }
            }

            $columns = [];
            foreach ($keys as $column) {
                $name = (string) $column;
                if (in_array($name, $plumbing, true) || str_ends_with($name, '_raw') || str_ends_with($name, '_id')) {
                    continue;
                }
                if (in_array($name, $formattedTwins, true)) {
                    continue;
                }

                $scalarEverywhere = true;
                foreach ($candidate as $row) {
                    $value = $row[$column] ?? null;
                    if (is_array($value) || is_object($value)) {
                        $scalarEverywhere = false;
                        break;
                    }
                }
                if ($scalarEverywhere) {
                    $columns[] = $name;
                }
            }

            if ($columns === []) {
                continue;
            }

            $columns = array_slice($columns, 0, 7);
            $rows = [];
            foreach (array_slice($candidate, 0, 80) as $row) {
                $out = [];
                foreach ($columns as $column) {
                    $value = $row[$column] ?? null;
                    $out[] = match (true) {
                        $value === null => '—',
                        is_bool($value) => $value ? 'Yes' : 'No',
                        default => (string) $value,
                    };
                }
                $rows[] = $out;
            }

            return [array_map(static fn (string $c) => ucfirst(str_replace('_', ' ', $c)), $columns), $rows];
        }

        return null;
    }

    /** @param array{0: list<string>, 1: list<list<string>>} $table */
    private static function table(PdfDocument $pdf, string $label, array $table, float $left, float $right): void
    {
        [$headers, $rows] = $table;
        $count = count($headers);
        if ($count === 0) {
            return;
        }

        $width = ($right - $left) / $count;
        $columnX = [];
        for ($i = 0; $i < $count; $i++) {
            $columnX[] = $left + $i * $width;
        }

        self::sectionTitle($pdf, $label, $left, $right);

        $head = function () use ($pdf, $headers, $columnX, $width, $left, $right): void {
            foreach ($headers as $i => $header) {
                $pdf->text(PdfDocument::clip($header, $width - 6, 8, PdfDocument::FONT_BOLD), $columnX[$i], 8, PdfDocument::FONT_BOLD, self::MUTED);
            }
            $pdf->advance(6);
            $pdf->rule($left, $right, null, self::RULE);
            $pdf->advance(12);
        };

        $head();

        foreach ($rows as $row) {
            if (!$pdf->fits(24)) {
                $pdf->newPage();
                self::sectionTitle($pdf, $label . ' (continued)', $left, $right);
                $head();
            }

            foreach ($row as $i => $cell) {
                $pdf->text(PdfDocument::clip($cell, $width - 6, 8.5), $columnX[$i] ?? $left, 8.5, PdfDocument::FONT_REGULAR, self::INK);
            }
            $pdf->advance(5);
            $pdf->rule($left, $right, null, '0.94 0.95 0.94', 0.4);
            $pdf->advance(11);
        }

        $pdf->advance(10);
    }

    private static function sectionTitle(PdfDocument $pdf, string $title, float $left, float $right): void
    {
        if (!$pdf->fits(40)) {
            $pdf->newPage();
        }
        $pdf->text(strtoupper($title), $left, 8, PdfDocument::FONT_BOLD, self::BRAND);
        $pdf->advance(14);
    }

    /** @param array<string, mixed> $payload */
    private static function footer(PdfDocument $pdf, array $payload, float $left, float $right): void
    {
        // Only break for the footer if it genuinely will not fit. It is two
        // lines; giving it a page of its own reads as a printing fault.
        if (!$pdf->fits(26)) {
            $pdf->newPage();
        }
        $pdf->advance(6);
        $pdf->rule($left, $right, null, self::RULE);
        $pdf->advance(12);
        $pdf->text(
            'Generated ' . ((string) ($payload['generated_at'] ?? '')) . ' by Aicountly Purchases. '
            . 'Figures are read live from their owning application at the moment of printing.',
            $left,
            7.5,
            PdfDocument::FONT_REGULAR,
            self::MUTED,
        );
    }
}
