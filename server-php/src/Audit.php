<?php

declare(strict_types=1);

namespace Aicountly\Api;

/**
 * Append-only audit of THIS product's own actions.
 *
 * Books audits its vouchers and Inventory audits its movements; neither is
 * copied here. What this records is what only this product knows: who approved
 * a discount beyond their limit, who revised a quotation, who cancelled an
 * order and why.
 *
 * TWO WAYS TO WRITE A ROW (AICOUNTLY audit-trail standard v1):
 *
 *   record()    best-effort. An audit write must never be the reason a user's
 *               save fails, so a failure is logged loudly and swallowed. Inside
 *               a transaction it runs under a SAVEPOINT: on PostgreSQL a failed
 *               statement poisons the whole transaction, and "best-effort" that
 *               aborts the caller's work is not best-effort.
 *   material()  fail-closed, for approvals, cancellations, posting and access
 *               changes. Called INSIDE the business transaction, so the change
 *               and its row commit together or not at all: a PO approved with no
 *               record of who approved it is worse than a PO not approved.
 *
 * Every row carries the record's human label (its number or name at the time),
 * a one-line summary, the field-by-field change, a severity, and the request id,
 * address and browser it came from. Secrets never reach it (changes() and
 * scrub()), and the table refuses UPDATE and DELETE (migration 003).
 */
final class Audit
{
    public const TABLE = 'purchase_audit_log';

    public const SEVERITIES = ['info', 'material', 'security'];

    /**
     * Name parts that mark a field as a secret. Matched against the field name's
     * `_`-separated parts, so `pin` catches `pin` and `upi_pin` but not `shipping`.
     */
    private const SECRET_PARTS = ['password', 'passwd', 'secret', 'token', 'otp', 'pin', 'cvv', 'credential', 'credentials', 'signature', 'apikey'];
    private const SECRET_NAMES = ['api_key', 'private_key', 'ses_key', 'session_key', 'service_key', 'auth_token', 'access_token', 'refresh_token'];

    /**
     * The snapshot key that already names each record type, checked before the database is
     * asked (a deleted record can only be named from its snapshot). Per type, because one
     * snapshot can name two records: an RFQ converted to an order carries both numbers.
     *
     * @var array<string, list<string>>
     */
    private const LABEL_KEYS = [
        'purchase_order'        => ['po_no'],
        'requisition'           => ['requisition_no'],
        'rfq'                   => ['rfq_no'],
        'bill_request'          => ['supplier_invoice_no'],
        'bill'                  => ['supplier_invoice_no'],
        'receipt_request'       => ['inventory_document_no', 'receipt_no'],
        'purchase_return'       => ['return_no'],
        'claim'                 => ['claim_no'],
        'permission_profile'    => ['profile_name'],
        'permission_assignment' => ['profile_name'],
        'match_policy'          => ['policy_name'],
        'supplier'              => ['supplier_name', 'supplier'],
    ];

    /**
     * How each record type is named, and where its number lives. The SQL is this
     * codebase's, never a request's, and always scoped to the row's company.
     *
     * @var array<string, array{0: string, 1: ?string}>
     */
    private const ENTITIES = [
        'purchase_order'        => ['Purchase order', 'SELECT po_no FROM purchase_orders WHERE po_id = :id AND cmp_id = :cmp'],
        'requisition'           => ['Requisition', 'SELECT requisition_no FROM purchase_requisitions WHERE requisition_id = :id AND cmp_id = :cmp'],
        'rfq'                   => ['RFQ', 'SELECT rfq_no FROM purchase_rfqs WHERE rfq_id = :id AND cmp_id = :cmp'],
        'bill_request'          => ['Bill', 'SELECT supplier_invoice_no FROM purchase_bill_requests WHERE request_id = :id AND cmp_id = :cmp'],
        'bill'                  => ['Bill', 'SELECT supplier_invoice_no FROM purchase_bill_requests WHERE request_id = :id AND cmp_id = :cmp'],
        'receipt_request'       => ['Goods receipt', "SELECT COALESCE(NULLIF(inventory_document_no, ''), NULLIF(receipt_no, '')) FROM purchase_receipt_requests WHERE request_id = :id AND cmp_id = :cmp"],
        'purchase_return'       => ['Purchase return', 'SELECT return_no FROM purchase_returns WHERE return_id = :id AND cmp_id = :cmp'],
        'claim'                 => ['Claim', 'SELECT claim_no FROM purchase_claims WHERE claim_id = :id AND cmp_id = :cmp'],
        'permission_profile'    => ['Permission profile', 'SELECT profile_name FROM purchase_permission_profiles WHERE profile_id = :id AND cmp_id = :cmp'],
        'match_policy'          => ['Match policy', 'SELECT policy_name FROM purchase_match_policies WHERE policy_id = :id AND cmp_id = :cmp'],
        'permission_assignment' => ['Access grant', null],
        'match_exception'       => ['Match exception', null],
        'supplier'              => ['Supplier', null],
        'settings'              => ['Purchases settings', null],
        'integration_command'   => ['Integration command', null],
        'purchase_order_line'   => ['Order line', null],
        'books_voucher'         => ['Books voucher', null],
        'audit_log'             => ['Audit trail', null],
    ];

    /** Readable names for the fields that turn up most in `changes`; anything else is humanised. */
    private const FIELD_LABELS = [
        'status' => 'Status', 'permissions' => 'Permissions', 'profile_name' => 'Profile name', 'is_active' => 'Active',
        'sod_policy' => 'Segregation of duties', 'over_receipt_tolerance_pc' => 'Over-receipt tolerance %',
        'receive_stock_at_grn' => 'Goods receipt moves stock', 'qty_tolerance_pc' => 'Quantity tolerance %',
        'rate_tolerance_pc' => 'Rate tolerance %', 'value_tolerance_amt' => 'Value tolerance', 'freight_tolerance_amt' => 'Freight tolerance',
        'auto_match_below_amt' => 'Auto-match below', 'is_default' => 'Default policy', 'policy_name' => 'Policy name',
        'po_approval_above_amount' => 'PO approval above', 'requisition_approval_above_amount' => 'Requisition approval above',
        'qualification_status' => 'Qualification', 'revision' => 'Revision', 'bill_status' => 'Bill status',
        'po_prefix' => 'PO prefix', 'requisition_prefix' => 'Requisition prefix', 'rfq_prefix' => 'RFQ prefix',
        'return_prefix' => 'Return prefix', 'claim_prefix' => 'Claim prefix',
    ];

    private static ?string $requestId = null;

    /**
     * Best-effort: never breaks the request. For everything that is not material.
     *
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     */
    public static function record(
        Context $ctx,
        Auth $auth,
        string $action,
        string $entityType,
        int|string|null $entityId,
        ?array $before = null,
        ?array $after = null,
        string $reason = '',
        string $entityLabel = '',
        string $summary = '',
        string $severity = '',
    ): void {
        $pdo = null;
        $savepoint = false;
        try {
            $pdo = Db::connect();
            if ($pdo->inTransaction()) {
                $pdo->exec('SAVEPOINT purchase_audit_record');
                $savepoint = true;
            }
            self::write($ctx, $auth, $action, $entityType, $entityId, $before, $after, $reason, $entityLabel, $summary, $severity !== '' ? $severity : self::severityOf($action, false));
            if ($savepoint) {
                $pdo->exec('RELEASE SAVEPOINT purchase_audit_record');
            }
        } catch (\Throwable $e) {
            if ($savepoint && $pdo !== null) {
                try {
                    $pdo->exec('ROLLBACK TO SAVEPOINT purchase_audit_record');
                    $pdo->exec('RELEASE SAVEPOINT purchase_audit_record');
                } catch (\Throwable) {
                    // The transaction was already beyond saving; the caller's own next statement will say so.
                }
            }
            // An audit write must never be the reason a user's save fails. It is
            // logged loudly instead, because a silently missing audit row is
            // worse than a noisy one.
            error_log('[audit] failed to record ' . $action . ' on ' . $entityType . ': ' . $e->getMessage());
        }
    }

    /**
     * Fail-closed: throws when the row cannot be written. Call it inside the same
     * Db::transaction() as the change it records, so neither commits without the other.
     *
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     */
    public static function material(
        Context $ctx,
        Auth $auth,
        string $action,
        string $entityType,
        int|string|null $entityId,
        ?array $before = null,
        ?array $after = null,
        string $reason = '',
        string $entityLabel = '',
        string $summary = '',
        string $severity = '',
    ): void {
        try {
            self::write($ctx, $auth, $action, $entityType, $entityId, $before, $after, $reason, $entityLabel, $summary, $severity !== '' ? $severity : self::severityOf($action, true));
        } catch (\Throwable $e) {
            error_log('[audit] REFUSED ' . $action . ' on ' . $entityType . ': the audit row could not be written: ' . $e->getMessage());

            throw new \RuntimeException('The change was not saved because its audit record could not be written. Nothing was changed; try again.', 0, $e);
        }
    }

    /**
     * Field-by-field difference between two snapshots: [{field, label, old, new}], unchanged
     * fields and secrets left out. A field only one side has counts as changed (old or new null).
     *
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     * @param array<string, string>     $labels field => readable label, overriding the defaults
     * @return list<array{field: string, label: string, old: mixed, new: mixed}>
     */
    public static function changes(?array $before, ?array $after, array $labels = []): array
    {
        if ($before === null || $after === null) {
            return [];
        }
        $out = [];
        foreach (array_unique(array_merge(array_keys($before), array_keys($after))) as $field) {
            $field = (string) $field;
            if (self::isSecret($field)) {
                continue;
            }
            $old = $before[$field] ?? null;
            $new = $after[$field] ?? null;
            if (self::same($old, $new)) {
                continue;
            }
            $out[] = ['field' => $field, 'label' => $labels[$field] ?? self::fieldLabel($field), 'old' => $old, 'new' => $new];
        }

        return $out;
    }

    /** Whether a field name is a secret that is never written to the trail. */
    public static function isSecret(string $field): bool
    {
        $name = strtolower($field);
        if (in_array($name, self::SECRET_NAMES, true)) {
            return true;
        }
        foreach (preg_split('/[^a-z0-9]+/', $name) ?: [] as $part) {
            if (in_array($part, self::SECRET_PARTS, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A snapshot with every secret field's value replaced, at any depth.
     *
     * @param array<string|int, mixed>|null $state
     * @return array<string|int, mixed>|null
     */
    public static function scrub(?array $state): ?array
    {
        if ($state === null) {
            return null;
        }
        $out = [];
        foreach ($state as $key => $value) {
            if (is_string($key) && self::isSecret($key)) {
                $out[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $out[$key] = self::scrub($value);
            } else {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    /** The readable name of a record type ("Purchase order"), for summaries and the screen. */
    public static function entityName(string $entityType): string
    {
        return self::ENTITIES[$entityType][0] ?? ucfirst(str_replace('_', ' ', $entityType));
    }

    /**
     * One id per request, so the rows one request wrote can be read together. An upstream
     * X-Request-Id is kept when it looks like one; otherwise a fresh one is made.
     */
    public static function requestId(): string
    {
        if (self::$requestId !== null) {
            return self::$requestId;
        }
        $given = Http::header('X-Request-Id');
        if (preg_match('/^[A-Za-z0-9._:-]{8,128}$/', $given) === 1) {
            return self::$requestId = $given;
        }

        return self::$requestId = bin2hex(random_bytes(12));
    }

    /**
     * The caller's address. REMOTE_ADDR is what the connection actually came from and is
     * preferred; X-Forwarded-For is read only when REMOTE_ADDR is a private or loopback
     * address (a proxy in front of this server), and then its LAST entry — the one that
     * proxy appended — because everything to its left is whatever the client chose to send.
     */
    public static function clientIp(): ?string
    {
        $remote = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
        $remoteValid = filter_var($remote, FILTER_VALIDATE_IP) !== false;
        $remotePublic = $remoteValid && filter_var($remote, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;

        if ($remoteValid && $remotePublic) {
            return $remote;
        }

        $forwarded = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
        if (is_string($forwarded) && $forwarded !== '') {
            $hops = array_values(array_filter(array_map('trim', explode(',', $forwarded)), static fn (string $h) => $h !== ''));
            $last = $hops === [] ? '' : $hops[count($hops) - 1];
            if (filter_var($last, FILTER_VALIDATE_IP) !== false) {
                return $last;
            }
        }

        return $remoteValid ? $remote : null;
    }

    /**
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     */
    private static function write(
        Context $ctx,
        Auth $auth,
        string $action,
        string $entityType,
        int|string|null $entityId,
        ?array $before,
        ?array $after,
        string $reason,
        string $entityLabel,
        string $summary,
        string $severity,
    ): void {
        $before = self::scrub($before);
        $after = self::scrub($after);
        $id = $entityId === null ? null : (string) $entityId;
        $label = $entityLabel !== '' ? $entityLabel : self::labelFor($ctx, $entityType, $id, $before, $after);
        $changes = self::changes($before, $after);

        Db::insert(self::TABLE, [
            'cmp_id'       => $ctx->cmpId,
            'fy_id'        => $ctx->fyId,
            'bo_id'        => $ctx->boId,
            'actor_uuid'   => $auth->uuid,
            'actor_kind'   => $auth->kind,
            'source_app'   => $auth->sourceApp,
            'action'       => $action,
            'entity_type'  => $entityType,
            'entity_id'    => $id,
            'entity_label' => $label,
            'summary'      => $summary !== '' ? mb_substr($summary, 0, 500) : self::summarise($action, $entityType, $id, $label),
            'before_state' => $before,
            'after_state'  => $after,
            'changes'      => $changes === [] ? null : $changes,
            'reason'       => $reason !== '' ? $reason : null,
            'severity'     => in_array($severity, self::SEVERITIES, true) ? $severity : 'info',
            'ip_address'   => self::clientIp(),
            'user_agent'   => self::userAgent(),
            'request_id'   => self::requestId(),
            'created_at'   => gmdate('Y-m-d H:i:s'),
        ], 'audit_id');
    }

    /**
     * The record's number or name: from the snapshots first (they hold it as it was, and a
     * deleted record can only be named that way), then from its own row.
     *
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     */
    private static function labelFor(Context $ctx, string $entityType, ?string $id, ?array $before, ?array $after): ?string
    {
        foreach ([$after, $before] as $state) {
            foreach ([...(self::LABEL_KEYS[$entityType] ?? []), 'label'] as $key) {
                $value = $state[$key] ?? null;
                if (is_scalar($value) && trim((string) $value) !== '') {
                    return mb_substr(trim((string) $value), 0, 200);
                }
            }
        }

        $sql = self::ENTITIES[$entityType][1] ?? null;
        if ($sql === null || $id === null || preg_match('/^\d{1,18}$/', $id) !== 1) {
            return null;
        }
        $value = Db::scalar($sql, ['id' => (int) $id, 'cmp' => $ctx->cmpId]);

        return is_scalar($value) && trim((string) $value) !== '' ? mb_substr(trim((string) $value), 0, 200) : null;
    }

    /** "Purchase order PO/88/0001: approved". */
    private static function summarise(string $action, string $entityType, ?string $id, ?string $label): string
    {
        $verb = str_contains($action, '.') ? substr($action, strpos($action, '.') + 1) : $action;
        // The settings row is the company's own; its id says nothing a reader needs.
        $what = self::entityName($entityType) . ' ' . ($label ?? ($id !== null && $entityType !== 'settings' ? '#' . $id : ''));

        return trim($what) . ': ' . str_replace(['_', '.'], ' ', $verb);
    }

    /**
     * Access and identity changes are security events; decisions that move money, stock or a
     * document's standing are material; everything else is information.
     */
    private static function severityOf(string $action, bool $material): string
    {
        if (preg_match('/^(access|sod|connect)\./', $action) === 1 || $action === 'integration.key_reissued') {
            return 'security';
        }
        if ($material || preg_match('/(approve|reject|cancel|post|delete|close|reopen|amend|award|debit|settle|accept|revers|return|override|controls|resolution_completed)/', $action) === 1) {
            return 'material';
        }

        return 'info';
    }

    private static function userAgent(): ?string
    {
        $agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        if (!is_string($agent) || trim($agent) === '') {
            return null;
        }

        return mb_substr((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', trim($agent)), 0, 255);
    }

    private static function fieldLabel(string $field): string
    {
        return self::FIELD_LABELS[$field] ?? ucfirst(str_replace('_', ' ', $field));
    }

    private static function same(mixed $a, mixed $b): bool
    {
        if (is_numeric($a) && is_numeric($b)) {
            return (float) $a === (float) $b;
        }
        if (is_bool($a) || is_bool($b)) {
            return self::truthy($a) === self::truthy($b) && ($a !== null && $b !== null);
        }

        return json_encode($a) === json_encode($b);
    }

    private static function truthy(mixed $v): bool
    {
        return in_array($v, [true, 1, '1', 't', 'true', 'yes', 'on'], true);
    }
}
