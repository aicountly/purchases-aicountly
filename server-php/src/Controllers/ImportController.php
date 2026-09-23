<?php

declare(strict_types=1);

namespace Aicountly\Api\Controllers;

use Aicountly\Api\Audit;
use Aicountly\Api\Dashboards\BooksReader;
use Aicountly\Api\Http;
use Aicountly\Api\Import\ColumnMap;
use Aicountly\Api\Import\DocumentReader;
use Aicountly\Api\Import\Upload;
use Aicountly\Api\Import\StatementReconciler;
use Aicountly\Api\Permissions;

/**
 * Reading a file somebody uploaded, and comparing it with the ledger.
 *
 * NOTHING IS STORED. The upload is parsed in memory, answered, and the
 * temporary file is deleted before the response is written. That is a decision,
 * not an omission: a supplier statement is the supplier's document, it carries
 * their whole trading relationship with us, and a product that keeps a copy of
 * every one has quietly become a place where those documents live. There is
 * nothing this product does later that needs the file — the reconciliation is
 * read, acted on, and the same file can be uploaded again in a second.
 *
 * What IS recorded is that a reconciliation happened, by whom, for which
 * supplier: the audit trail is about the action, not the attachment.
 *
 * THE FILE IS UNTRUSTED. Its type comes from its first bytes rather than its
 * name or the browser's content type; XML entity resolution is off; the size is
 * refused before anything is parsed; and no value read out of it is ever
 * interpolated into SQL, a shell, or a path. It is data that arrived from
 * outside, and every line of the reading code treats it that way.
 */
final class ImportController extends Controller
{
    /**
     * Parse an upload and say what was found — no side effects at all.
     *
     * Deliberately a separate call from the one that acts. Somebody uploading a
     * statement should see how the columns were read BEFORE anything is
     * compared, because a mis-read amount column produces a confident,
     * completely wrong reconciliation.
     */
    public static function preview(): void
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'bill.enter');

        [$path, $name, $cleanup] = self::uploadedFile();

        try {
            $result = DocumentReader::read($path, $name);
            $table = $result['table'];
            $map = ColumnMap::detect($table);

            Http::data([
                'file' => [
                    'name' => $name,
                    'kind' => $result['kind'],
                    'readable' => !$table->isEmpty(),
                ],
                'table' => $table->toArray(),
                'mapping' => $map->toArray(),
                'sample' => array_slice($map->dataRows($table), 0, 25),
                'guidance' => $table->isEmpty()
                    ? 'Nothing could be read from this file. The notes above say why.'
                    : 'Check the column mapping against the sample rows before reconciling. '
                        . 'A statement read with the wrong amount column reconciles confidently and wrongly.',
            ]);
        } finally {
            $cleanup();
        }
    }

    /**
     * Compare an uploaded statement with what Smart Books holds for a supplier.
     *
     * Reports and stops. Smart Books owns the ledger; a statement is the
     * supplier's opinion of it, and the only thing this product may do with a
     * disagreement is show it to somebody who can decide.
     */
    public static function reconcile(): void
    {
        [$auth, $ctx] = self::enter();
        // Reading a supplier's whole bill history is a cost view, so it takes
        // the permission that guards costs rather than the one that guards
        // entering a bill.
        Permissions::assert($ctx, $auth, 'cost.view');

        $supplierId = Http::intParam('supplier_account_id', 0) ?? 0;
        if ($supplierId <= 0) {
            Http::validationFailed(
                'Choose which supplier this statement belongs to.',
                ['field' => 'supplier_account_id'],
            );
        }

        $from = (string) (Http::param('from') ?? '');
        $to = (string) (Http::param('to') ?? '');
        if ($from === '' || $to === '') {
            Http::validationFailed('Give the period the statement covers.', ['field' => 'from']);
        }

        [$path, $name, $cleanup] = self::uploadedFile();

        try {
            $result = DocumentReader::read($path, $name);
            $table = $result['table'];

            if ($table->isEmpty()) {
                Http::validationFailed(
                    'Nothing could be read from that file.',
                    ['notes' => $table->notes, 'kind' => $result['kind']],
                );
            }

            $map = ColumnMap::detect($table)->withOverrides(self::overrides());

            $reader = new BooksReader($ctx, $auth->sesKey());
            $ledger = $reader->supplierLedger($supplierId, $from, $to);

            if (!$ledger['ok']) {
                // Without the ledger there is nothing to reconcile against, and
                // showing the statement alone would invite somebody to read it
                // as agreement.
                Http::error(503, 'ledger_unavailable', (string) $ledger['error']);
            }

            $report = StatementReconciler::reconcile(
                $map->dataRows($table),
                $map,
                $ledger['rows'],
                'INR',
            );

            $outcome = [];
            foreach ($report['buckets'] as $bucket) {
                $outcome[$bucket['id']] = $bucket['count'];
            }

            // COUNTS ONLY, and in the "after" slot because nothing was before.
            // The statement's contents are the supplier's, not ours to keep —
            // what is recorded is that somebody reconciled, and how it came out.
            Audit::record(
                $ctx,
                $auth,
                'statement.reconciled',
                'supplier',
                $supplierId,
                null,
                [
                    'file_name'  => $name,
                    'file_kind'  => $result['kind'],
                    'from'       => $from,
                    'to'         => $to,
                    'lines_read' => $report['lines_read'],
                    'outcome'    => $outcome,
                ],
                'Supplier statement reconciled against Smart Books',
            );

            Http::data([
                'file' => ['name' => $name, 'kind' => $result['kind'], 'notes' => $table->notes],
                'mapping' => $map->toArray(),
                'supplier_account_id' => $supplierId,
                'period' => ['from' => $from, 'to' => $to],
                'report' => $report,
                'retention' => 'The uploaded file was read and discarded. Nothing about it is stored except '
                    . 'this action in the audit log.',
            ]);
        } finally {
            $cleanup();
        }
    }

    /**
     * The uploaded file, or a refusal.
     *
     * The checks live in Import\Upload, which the returns importer uses too.
     *
     * @return array{0: string, 1: string, 2: callable(): void}
     */
    private static function uploadedFile(): array
    {
        return Upload::file();
    }

    /**
     * Column choices a person made on the preview screen.
     *
     * @return array<string, int|null>
     */
    private static function overrides(): array
    {
        $raw = $_POST['columns'] ?? null;
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : null;
        }
        if (!is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $field => $index) {
            if (!is_string($field) || !array_key_exists($field, ColumnMap::VOCABULARY)) {
                continue;
            }
            $out[$field] = ($index === null || $index === '') ? null : (int) $index;
        }

        return $out;
    }
}
