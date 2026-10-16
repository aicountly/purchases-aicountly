<?php

declare(strict_types=1);

namespace Aicountly\Api\Controllers;

use Aicountly\Api\Audit;
use Aicountly\Api\Auth;
use Aicountly\Api\Context;
use Aicountly\Api\Db;
use Aicountly\Api\Http;
use Aicountly\Api\LocalDate;
use Aicountly\Api\Permissions;

/**
 * The audit trail, read back.
 *
 *   GET v1/audit-log                        the company's trail, filtered and paged    audit.view
 *   GET v1/audit-log/export                 the same filters as CSV, capped             audit.export
 *   GET v1/audit-log/entity/{type}/{id}     one record's history                        audit.view
 *
 * Read-only, and only ever this company's rows: cmp_id comes from the checked scope
 * (Controller::enter), never from a filter. Nothing here writes to the trail except the
 * export itself, which is recorded — a copy of the trail leaving the product is an event.
 *
 * Actors are portal uuids, shown as stored. This product never copies a name from
 * my.aicountly.com (see AccessController), so the trail does not invent one either.
 */
final class AuditController extends Controller
{
    /** Rows in one CSV. Beyond this the file says it is partial, and the filters narrow it. */
    public const EXPORT_CAP = 5000;

    /**
     * Record types that are one record under two names: Books' voucher events wrote `bill`
     * for the same bill request the bill screens write as `bill_request`.
     */
    private const ENTITY_ALIASES = [
        'bill_request' => ['bill_request', 'bill'],
        'bill'         => ['bill_request', 'bill'],
    ];

    public static function index(): void
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'audit.view');

        $page = Http::listParams(['created_at'], 'created_at');
        [$where, $params] = self::filters($ctx);

        $total = (int) Db::scalar('SELECT COUNT(*) FROM ' . Audit::TABLE . ' WHERE ' . $where, $params);
        // Clamped by listParams, then interpolated: LIMIT is not bindable on every driver.
        $rows = Db::all(
            'SELECT * FROM ' . Audit::TABLE . ' WHERE ' . $where
            . ' ORDER BY created_at ' . $page['order'] . ', audit_id ' . $page['order']
            . ' LIMIT ' . $page['limit'] . ' OFFSET ' . $page['offset'],
            $params,
        );

        Http::list(
            array_map(static fn (array $row) => self::present($row, $auth), $rows),
            $total,
            $page['limit'],
            $page['offset'],
            ['entity_types' => self::entityTypes($ctx)],
        );
    }

    /** One record's history, newest first. */
    public static function entity(string $type, string $id): void
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'audit.view');

        $types = self::ENTITY_ALIASES[$type] ?? [$type];
        $params = ['cmp' => $ctx->cmpId, 'id' => $id];
        $in = [];
        foreach ($types as $i => $t) {
            $in[] = ':t' . $i;
            $params['t' . $i] = $t;
        }
        $limit = max(1, min(Http::MAX_LIMIT, Http::intParam('limit', 100) ?? 100));

        $rows = Db::all(
            'SELECT * FROM ' . Audit::TABLE . '
              WHERE cmp_id = :cmp AND entity_id = :id AND entity_type IN (' . implode(', ', $in) . ')
              ORDER BY created_at DESC, audit_id DESC
              LIMIT ' . $limit,
            $params,
        );

        Http::data(array_map(static fn (array $row) => self::present($row, $auth), $rows));
    }

    /**
     * The filtered trail as CSV — all of it up to EXPORT_CAP rows. A file that stops at the
     * cap says so, in an X-Export-Truncated header and in its last line, so a partial copy is
     * never mistaken for the whole trail.
     */
    public static function export(): void
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'audit.export');

        [$where, $params] = self::filters($ctx);
        $total = (int) Db::scalar('SELECT COUNT(*) FROM ' . Audit::TABLE . ' WHERE ' . $where, $params);
        $rows = Db::all(
            'SELECT * FROM ' . Audit::TABLE . ' WHERE ' . $where
            . ' ORDER BY created_at DESC, audit_id DESC LIMIT ' . self::EXPORT_CAP,
            $params,
        );
        $truncated = $total > count($rows);

        $lines = [[
            'When (UTC)', 'Action', 'Severity', 'Record type', 'Record id', 'Record', 'Summary', 'Changes',
            'Reason', 'Actor', 'Actor kind', 'Source app', 'IP address', 'User agent', 'Request id', 'Audit id',
        ]];
        foreach ($rows as $row) {
            $presented = self::present($row, $auth);
            $lines[] = [
                $presented['created_at'], $presented['action'], $presented['severity'], $presented['entity_type'],
                $presented['entity_id'], $presented['entity_label'], $presented['summary'],
                implode('; ', array_map(
                    static fn (array $c) => $c['label'] . ': ' . self::flat($c['old']) . ' -> ' . self::flat($c['new']),
                    $presented['changes'],
                )),
                $presented['reason'], $presented['actor_uuid'], $presented['actor_kind'], $presented['source_app'],
                $presented['ip_address'], $presented['user_agent'], $presented['request_id'], $presented['audit_id'],
            ];
        }
        if ($truncated) {
            $lines[] = ['PARTIAL: the first ' . count($rows) . ' of ' . $total . ' rows. Narrow the dates or filters to export the rest.'];
        }

        Audit::record($ctx, $auth, 'audit.exported', 'audit_log', null, null, [
            'rows' => count($rows), 'total' => $total, 'truncated' => $truncated, 'filters' => self::echoFilters(),
        ]);

        $csv = self::csv($lines);
        $file = 'purchases-audit-trail-' . LocalDate::today() . '.csv';

        if (PHP_SAPI === 'cli') {
            // Under test the CSV is returned as data so it can be asserted on.
            Http::data(['csv' => $csv, 'rows' => count($rows), 'total' => $total, 'truncated' => $truncated, 'file_name' => $file]);
        }

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $file . '"');
        header('Cache-Control: no-store');
        header('X-Export-Truncated: ' . ($truncated ? 'true' : 'false'));
        header('X-Export-Rows: ' . count($rows) . '/' . $total);
        echo $csv;
        exit;
    }

    /**
     * WHERE clause and parameters for the shared filters. Always this company first.
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    private static function filters(Context $ctx): array
    {
        $where = ['cmp_id = :cmp'];
        $params = ['cmp' => $ctx->cmpId];

        $from = Http::dateParam('from');
        $to = Http::dateParam('to');
        if ($from !== null && $to !== null && $from > $to) {
            Http::validationFailed('The period starts after it ends.', ['field' => 'from']);
        }
        // The company's calendar days (LocalDate), not UTC ones: "today" in Pune starts at 18:30 UTC.
        if ($from !== null) {
            $where[] = 'created_at >= CAST(:from_ts AS TIMESTAMPTZ)';
            $params['from_ts'] = self::utcStartOf($from);
        }
        if ($to !== null) {
            $where[] = 'created_at < CAST(:to_ts AS TIMESTAMPTZ)';
            $params['to_ts'] = self::utcStartOf((new \DateTimeImmutable($to))->modify('+1 day')->format('Y-m-d'));
        }

        $action = trim((string) (Http::param('action') ?? ''));
        if ($action !== '') {
            // "po." or "po.*" is every action on orders; anything else is one action.
            if (str_ends_with($action, '.*') || str_ends_with($action, '.')) {
                $where[] = 'action LIKE :action_prefix';
                $params['action_prefix'] = self::likeEscape(rtrim($action, '*')) . '%';
            } else {
                $where[] = 'action = :action';
                $params['action'] = $action;
            }
        }

        $entityType = trim((string) (Http::param('entity_type') ?? ''));
        if ($entityType !== '') {
            $types = self::ENTITY_ALIASES[$entityType] ?? [$entityType];
            $in = [];
            foreach ($types as $i => $t) {
                $in[] = ':et' . $i;
                $params['et' . $i] = $t;
            }
            $where[] = 'entity_type IN (' . implode(', ', $in) . ')';
        }

        $entityId = trim((string) (Http::param('entity_id') ?? ''));
        if ($entityId !== '') {
            $where[] = 'entity_id = :entity_id';
            $params['entity_id'] = $entityId;
        }

        $label = trim((string) (Http::param('entity_label') ?? ''));
        if ($label !== '') {
            $where[] = "entity_label ILIKE :entity_label ESCAPE '!'";
            $params['entity_label'] = '%' . self::likeEscape($label) . '%';
        }

        $actor = trim((string) (Http::param('actor_uuid') ?? Http::param('actor') ?? ''));
        if ($actor !== '') {
            $where[] = 'LOWER(actor_uuid) = LOWER(:actor)';
            $params['actor'] = $actor;
        }

        $severity = trim((string) (Http::param('severity') ?? ''));
        if ($severity !== '') {
            if (!in_array($severity, Audit::SEVERITIES, true)) {
                Http::validationFailed('Severity is info, material or security.', ['field' => 'severity']);
            }
            $where[] = 'severity = :severity';
            $params['severity'] = $severity;
        }

        $q = trim((string) (Http::param('q') ?? ''));
        if ($q !== '') {
            $where[] = "(summary ILIKE :q ESCAPE '!' OR entity_label ILIKE :q ESCAPE '!' OR action ILIKE :q ESCAPE '!'
                         OR entity_id ILIKE :q ESCAPE '!' OR reason ILIKE :q ESCAPE '!' OR actor_uuid ILIKE :q ESCAPE '!')";
            $params['q'] = '%' . self::likeEscape(mb_substr($q, 0, 120)) . '%';
        }

        return [implode(' AND ', $where), $params];
    }

    /**
     * One row as the screen and the CSV read it.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function present(array $row, Auth $auth): array
    {
        $before = $row['before_state'] === null ? null : Db::jsonColumn($row['before_state']);
        $after = $row['after_state'] === null ? null : Db::jsonColumn($row['after_state']);
        // Rows from before migration 024 carry no `changes`; they are worked out from the
        // snapshots the row did record, never from anything read now.
        $changes = $row['changes'] !== null ? Db::jsonColumn($row['changes']) : Audit::changes($before, $after);
        // jsonb keeps its own key order; the screen and the CSV read field, label, old, new.
        $changes = array_map(static fn (array $c): array => [
            'field' => (string) ($c['field'] ?? ''), 'label' => (string) ($c['label'] ?? ''), 'old' => $c['old'] ?? null, 'new' => $c['new'] ?? null,
        ], array_values(array_filter($changes, 'is_array')));
        $type = (string) $row['entity_type'];
        $id = $row['entity_id'] === null ? null : (string) $row['entity_id'];
        $label = $row['entity_label'] ?? null;

        return [
            'audit_id'     => (int) $row['audit_id'],
            'created_at'   => (string) $row['created_at'],
            'action'       => (string) $row['action'],
            'severity'     => (string) ($row['severity'] ?? 'info'),
            'actor_uuid'   => (string) $row['actor_uuid'],
            'actor_kind'   => (string) $row['actor_kind'],
            'source_app'   => (string) $row['source_app'],
            'is_you'       => $row['actor_uuid'] === $auth->uuid,
            'entity_type'  => $type,
            'entity_name'  => Audit::entityName($type),
            'entity_id'    => $id,
            'entity_label' => $label,
            'summary'      => (string) ($row['summary'] ?? '') !== ''
                ? (string) $row['summary']
                : Audit::entityName($type) . ' ' . ($label ?? ($id !== null ? '#' . $id : '')) . ': ' . str_replace(['_', '.'], ' ', (string) $row['action']),
            'reason'       => $row['reason'],
            'changes'      => $changes,
            'before'       => $before,
            'after'        => $after,
            'ip_address'   => $row['ip_address'],
            'user_agent'   => $row['user_agent'] ?? null,
            'request_id'   => $row['request_id'] ?? null,
            'fy_id'        => (int) $row['fy_id'],
            'bo_id'        => (int) $row['bo_id'],
        ];
    }

    /** @return list<string> the record types this company's trail holds, for the filter. */
    private static function entityTypes(Context $ctx): array
    {
        return array_map(
            static fn (array $r) => (string) $r['entity_type'],
            Db::all('SELECT DISTINCT entity_type FROM ' . Audit::TABLE . ' WHERE cmp_id = :cmp ORDER BY entity_type', ['cmp' => $ctx->cmpId]),
        );
    }

    /** @return array<string, string> */
    private static function echoFilters(): array
    {
        $out = [];
        foreach (['from', 'to', 'action', 'entity_type', 'entity_id', 'entity_label', 'actor_uuid', 'actor', 'severity', 'q'] as $key) {
            $value = Http::param($key);
            if ($value !== null && $value !== '') {
                $out[$key] = mb_substr($value, 0, 120);
            }
        }

        return $out;
    }

    private static function utcStartOf(string $date): string
    {
        return (new \DateTimeImmutable($date . ' 00:00:00', LocalDate::zone()))
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s+00');
    }

    private static function likeEscape(string $value): string
    {
        // '!' rather than a backslash: PDO's placeholder parser reads '\' as an escaped quote and
        // stops binding the parameters after it.
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }

    private static function flat(mixed $value): string
    {
        if ($value === null) {
            return '—';
        }
        if (is_bool($value)) {
            return $value ? 'yes' : 'no';
        }
        if (is_array($value)) {
            return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return (string) $value;
    }

    /** @param list<list<mixed>> $rows */
    private static function csv(array $rows): string
    {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            return '';
        }

        // A leading BOM so Excel opens ₹ and names in other scripts without mangling them.
        fwrite($handle, "\xEF\xBB\xBF");
        foreach ($rows as $row) {
            fputcsv($handle, array_map(static fn ($cell) => self::safeCell((string) $cell), $row), ',', '"', '\\');
        }
        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    /**
     * Stop a spreadsheet treating a cell as a formula. A reason or a label beginning with
     * = + - @ is a formula injection waiting for somebody to open the export.
     */
    private static function safeCell(string $value): string
    {
        return preg_match('/^[=+\-@\t\r]/', $value) === 1 ? "'" . $value : $value;
    }
}
