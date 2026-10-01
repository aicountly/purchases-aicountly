<?php

declare(strict_types=1);

namespace Aicountly\Api;

/**
 * One business operation that asks another product to do something, and its outcome.
 *
 * THIS IS NOT AN OUTBOX OF DATA. A row holds a COMMAND ("receive this delivery in
 * Inventory", "post this bill in Books"), the idempotency key that makes retrying it
 * safe, the exact request body that key was first sent with, and the reference the
 * other product handed back. It never holds a copy of the other product's document,
 * balance or master, and nothing sweeps these rows to compare them against anything.
 *
 *   PENDING ──▶ POSTING ──▶ COMPLETED    the other product accepted; its reference is stored
 *                  │
 *                  ├──────▶ FAILED       it answered with a server error: nothing to
 *                  │                     suggest it acted, and the same key may be sent again
 *                  ├──────▶ UNCERTAIN    no answer at all, or "still processing": it may have
 *                  │                     acted. Retrying on the same key is safe and settles
 *                  │                     it; so does reconciling by our source identity
 *                  └──────▶ BLOCKED      a business refusal. Retrying will not help;
 *                                        something has to change, and a changed request is a
 *                                        new revision with a new key
 *
 * ONE ROW PER OPERATION. (company, command, entity, revision) is unique in the database,
 * and the row is created with INSERT … ON CONFLICT DO NOTHING, so two requests racing to
 * post the same document find the same row instead of each minting a key of its own.
 *
 * THE KEY IS DERIVED, NOT RANDOM. It is a function of the operation alone, so the same
 * operation always presents the same key — to Inventory and Books, which replay their
 * first answer to it. Both refuse a reused key whose body differs, so the body is stored
 * here on first use and replayed verbatim by every retry.
 *
 * NO LOCK ACROSS THE NETWORK. An attempt claims the row with a short lease (an atomic
 * UPDATE), makes the call with no transaction open, and records the outcome only if it
 * still holds the lease. An attempt that dies leaves a lease that expires; the next one
 * may take it and will present the same key and body.
 *
 * WHY NO CRON. A retry runs when somebody presses Retry or Reconcile on the document.
 * A scheduled job walking this table would be the synchronisation this architecture
 * exists to avoid, and would hide failures from the person who can act on them.
 */
final class IntegrationCommand
{
    public const PENDING   = 'PENDING';
    public const POSTING   = 'POSTING';
    public const COMPLETED = 'COMPLETED';
    public const FAILED    = 'FAILED';
    public const UNCERTAIN = 'UNCERTAIN';
    public const BLOCKED   = 'BLOCKED';
    /** Withdrawn before it reached the other product: the document it carried was cancelled or superseded. */
    public const CANCELLED = 'CANCELLED';

    /** Statuses a document still has work on. */
    public const OPEN = [self::PENDING, self::POSTING, self::FAILED, self::UNCERTAIN, self::BLOCKED];

    public const TABLE = 'purchase_integration_commands';

    /**
     * Longer than the slowest call this product makes (ApiClient: 20s total for a required
     * call, times the two calls a Books draft-then-post takes), short enough that a crashed
     * attempt does not hold a document for long.
     */
    public const LEASE_SECONDS = 90;

    /**
     * The command for this operation, created if it does not exist yet.
     *
     * Never creates a second row for the same operation: the unique index decides, not a
     * prior SELECT. The payload is stored only by the call that creates the row; later
     * calls get the stored one back, which is what a retry must send.
     *
     * @param array<string, mixed> $payload the request body, exactly as it will be sent
     * @param array<string, mixed> $summary what a person needs to recognise the command
     * @return array<string, mixed>
     */
    public static function ensure(
        Context $ctx,
        string $targetService,
        string $commandType,
        string $entityType,
        int $entityId,
        array $payload,
        array $summary = [],
        int $revision = 0,
    ): array {
        $now = self::now();
        // No conflict target: the operation and its idempotency key are both unique, and
        // they are the same identity. Naming only one lets a racing insert trip the other
        // and fail with a unique violation instead of doing nothing.
        Db::run(
            'INSERT INTO ' . self::TABLE . ' (
                 cmp_id, fy_id, bo_id, target_service, command_type, entity_type, entity_id, revision,
                 idempotency_key, status, attempts, request_summary, request_payload, created_at, updated_at
             ) VALUES (
                 :cmp, :fy, :bo, :target, :ctype, :etype, :eid, :rev,
                 :ikey, :status, 0, :summary, :payload, :now, :now
             )
             ON CONFLICT DO NOTHING',
            [
                'cmp'     => $ctx->cmpId,
                'fy'      => $ctx->fyId,
                'bo'      => $ctx->boId,
                'target'  => $targetService,
                'ctype'   => $commandType,
                'etype'   => $entityType,
                'eid'     => $entityId,
                'rev'     => $revision,
                'ikey'    => self::key($ctx->cmpId, $commandType, $entityType, $entityId, $revision),
                'status'  => self::PENDING,
                'summary' => json_encode($summary, JSON_UNESCAPED_UNICODE),
                'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
                'now'     => $now,
            ],
        );

        return self::find($ctx->cmpId, $commandType, $entityType, $entityId, $revision) ?? [];
    }

    /**
     * Take the operation for one attempt, or learn why it cannot be taken.
     *
     * Atomic: one UPDATE decides, so two attempts cannot both hold the lease. Claimable
     * while nothing is known to have happened (PENDING), after a server error (FAILED),
     * while the outcome is unknown (UNCERTAIN), and once an abandoned attempt's lease has
     * run out. Never while another attempt holds a live lease, and never once COMPLETED
     * or BLOCKED.
     *
     * @return array{claimed: bool, command: array<string, mixed>}
     */
    public static function claim(int $commandId): array
    {
        $token = bin2hex(random_bytes(12));
        $row = Db::first(
            'UPDATE ' . self::TABLE . "
                SET status = 'POSTING', lease_token = :token,
                    lease_expires_at = NOW() + make_interval(secs => :lease),
                    attempts = attempts + 1, last_attempt_at = NOW(), updated_at = NOW()
              WHERE command_id = :id
                AND (status IN ('PENDING', 'FAILED', 'UNCERTAIN')
                     OR (status = 'POSTING' AND (lease_expires_at IS NULL OR lease_expires_at < NOW())))
             RETURNING *",
            ['token' => $token, 'lease' => self::LEASE_SECONDS, 'id' => $commandId],
        );
        if ($row !== null) {
            return ['claimed' => true, 'command' => self::decode($row)];
        }

        return ['claimed' => false, 'command' => self::byId($commandId) ?? []];
    }

    /**
     * Take a command out of reach of every future attempt, atomically.
     *
     * Only from a state in which nothing can be in flight — never sent, failed before
     * the other product wrote anything, or refused. A command being sent, or whose
     * outcome is unknown, or that completed, is not withdrawn: the caller is told so
     * and the cancel it was part of is refused. Because this and claim() are each one
     * UPDATE on the same row, a cancel and a retry arriving together cannot both win.
     */
    public static function withdraw(int $commandId, string $reason, string $resolvedBy = 'withdrawn'): bool
    {
        return Db::first(
            'UPDATE ' . self::TABLE . "
                SET status = 'CANCELLED', last_error = :reason, resolved_by = :by,
                    lease_token = NULL, lease_expires_at = NULL, updated_at = NOW()
              WHERE command_id = :id AND status IN ('PENDING', 'FAILED', 'BLOCKED')
             RETURNING command_id",
            ['reason' => mb_substr($reason, 0, 480), 'by' => $resolvedBy, 'id' => $commandId],
        ) !== null;
    }

    /**
     * Put a command Books refused ONLY because its Idempotency-Key was too long back where an
     * attempt may take it, so it is sent again — under the key it now goes out with.
     *
     * Not a general "un-block": a business refusal stays refused. This is for the one refusal
     * that was our own defect — a key longer than Books' 64 characters, refused 400 before Books
     * wrote anything — and only after the caller has confirmed with Books that it holds nothing
     * for the document (Domain\BooksKeyRecovery). Atomic and guarded on the exact key and the
     * exact refusal, so it re-arms once, and never a command that has moved on since.
     */
    public static function rearmAfterKeyRefusal(int $commandId, string $storedKey, string $note): bool
    {
        return Db::first(
            'UPDATE ' . self::TABLE . "
                SET status = 'PENDING', last_error = :note, resolved_by = :by,
                    lease_token = NULL, lease_expires_at = NULL, updated_at = NOW()
              WHERE command_id = :id AND idempotency_key = :ikey AND target_service = 'books'
                AND status = 'BLOCKED' AND last_status_code = 400 AND last_error ILIKE :refusal
             RETURNING command_id",
            [
                'note'    => mb_substr($note, 0, 480),
                'by'      => self::KEY_RECOVERY,
                'id'      => $commandId,
                'ikey'    => $storedKey,
                'refusal' => '%' . self::KEY_TOO_LONG_REFUSAL . '%',
            ],
        ) !== null;
    }

    /** resolved_by of a command re-armed by rearmAfterKeyRefusal(). */
    public const KEY_RECOVERY = 'key_recovery';

    /** What Books says when it refuses a key it cannot store (IdempotencyKeyService::tooLongBody). */
    public const KEY_TOO_LONG_REFUSAL = 'Idempotency-Key must be at most';

    /**
     * The other product accepted. Store WHAT IT CALLED THE RESULT — ids, a uuid, a
     * number — and nothing else from its response body.
     *
     * @param array<string, mixed> $reference
     */
    public static function complete(int $commandId, string $leaseToken, array $reference, string $resolvedBy = 'response', ?int $statusCode = null): bool
    {
        return self::settle($commandId, $leaseToken, self::COMPLETED, [
            'external_reference' => json_encode($reference, JSON_UNESCAPED_UNICODE),
            'last_error'         => null,
            'completed_at'       => self::now(),
            'resolved_by'        => $resolvedBy,
            'last_status_code'   => $statusCode,
        ]);
    }

    /** A server error: the same key may be presented again. */
    public static function fail(int $commandId, string $leaseToken, string $error, int $statusCode): bool
    {
        return self::settle($commandId, $leaseToken, self::FAILED, [
            'last_error' => self::trimError($error), 'last_status_code' => $statusCode,
        ]);
    }

    /** No answer, or "still processing": the other product may have acted. */
    public static function uncertain(int $commandId, string $leaseToken, string $error, int $statusCode = 0): bool
    {
        return self::settle($commandId, $leaseToken, self::UNCERTAIN, [
            'last_error' => self::trimError($error), 'last_status_code' => $statusCode,
        ]);
    }

    /**
     * A business refusal — period locked, stock blocked, a document the other product
     * will not take as it stands. Kept apart from FAILED because retrying it is
     * pointless, and a screen that offers Retry here teaches people to press it twice.
     */
    public static function block(int $commandId, string $leaseToken, string $reason, int $statusCode): bool
    {
        return self::settle($commandId, $leaseToken, self::BLOCKED, [
            'last_error' => self::trimError($reason), 'last_status_code' => $statusCode,
        ]);
    }

    /**
     * Record an outcome learned WITHOUT an attempt of our own — a reconcile that found the
     * document by our source identity. Only from a state that is not already settled.
     *
     * @param array<string, mixed> $reference
     */
    public static function completeByReconcile(int $commandId, array $reference): bool
    {
        $count = Db::run(
            'UPDATE ' . self::TABLE . "
                SET status = 'COMPLETED', external_reference = :ref, last_error = NULL,
                    completed_at = NOW(), resolved_by = 'reconcile', lease_token = NULL,
                    lease_expires_at = NULL, updated_at = NOW()
              WHERE command_id = :id AND status IN ('PENDING', 'FAILED', 'UNCERTAIN', 'POSTING')",
            ['ref' => json_encode($reference, JSON_UNESCAPED_UNICODE), 'id' => $commandId],
        )->rowCount();

        return $count === 1;
    }

    /**
     * One attempt at a command: claim it, send its stored body on its key, classify the
     * answer. The outcome is NOT recorded for 'completed' — the caller checks the answer
     * is really what was asked for before it calls complete() or block() with the lease.
     * Every other outcome is recorded here.
     *
     * @param array<string, mixed> $command a row from ensure()
     * @param callable(array<string, mixed> $body, string $key): array{ok:bool, status:int, body:?array, error:?string} $send
     * @return array{outcome: 'completed'|'failed'|'uncertain'|'blocked'|'in_progress'|'already_completed'|'already_blocked',
     *               command: array<string, mixed>, lease: ?string, response: ?array, message: ?string}
     */
    public static function attempt(array $command, callable $send): array
    {
        $commandId = (int) $command['command_id'];
        if ($command['status'] === self::COMPLETED) {
            return ['outcome' => 'already_completed', 'command' => $command, 'lease' => null, 'response' => null, 'message' => null];
        }
        if ($command['status'] === self::BLOCKED) {
            return ['outcome' => 'already_blocked', 'command' => $command, 'lease' => null, 'response' => null, 'message' => (string) ($command['last_error'] ?? '')];
        }
        if ($command['status'] === self::CANCELLED) {
            return ['outcome' => 'withdrawn', 'command' => $command, 'lease' => null, 'response' => null, 'message' => (string) ($command['last_error'] ?? '')];
        }

        $claim = self::claim($commandId);
        if (!$claim['claimed']) {
            $current = $claim['command'];
            $outcome = match ($current['status'] ?? '') {
                self::COMPLETED => 'already_completed',
                self::BLOCKED   => 'already_blocked',
                self::CANCELLED => 'withdrawn',
                default         => 'in_progress',
            };

            return ['outcome' => $outcome, 'command' => $current, 'lease' => null, 'response' => null, 'message' => (string) ($current['last_error'] ?? '')];
        }

        $row = $claim['command'];
        $lease = (string) $row['lease_token'];
        $body = is_array($row['request_payload'] ?? null) ? $row['request_payload'] : [];
        $response = $send($body, (string) $row['idempotency_key']);
        $outcome = self::classify($response);
        $message = $response['ok'] ? null : (string) ($response['error'] ?? ('HTTP ' . $response['status']));

        match ($outcome) {
            'failed'    => self::fail($commandId, $lease, (string) $message, (int) $response['status']),
            'uncertain' => self::uncertain($commandId, $lease, (string) $message, (int) $response['status']),
            'blocked'   => self::block($commandId, $lease, (string) $message, (int) $response['status']),
            default     => null,
        };

        return ['outcome' => $outcome, 'command' => $row, 'lease' => $lease, 'response' => $response, 'message' => $message];
    }

    /**
     * Classify an ApiClient response into the command outcome it implies.
     *
     * @param array{ok:bool, status:int, body:?array, error:?string} $response
     * @return 'completed'|'failed'|'uncertain'|'blocked'
     */
    public static function classify(array $response): string
    {
        if ($response['ok']) {
            return 'completed';
        }
        $status = (int) $response['status'];
        $code = (string) ($response['body']['error']['code'] ?? $response['body']['code'] ?? '');
        if ($status === 0 || $code === 'request_in_progress' || $status === 408 || $status === 504) {
            return 'uncertain';
        }
        if ($status === 401 || $status === 403 || $status === 429 || $status >= 500) {
            // A session to renew, a permission to grant, a rate limit, a server fault:
            // nothing was written, and the same request can succeed later.
            return 'failed';
        }

        return 'blocked';
    }

    /** @return array<string, mixed>|null */
    public static function find(int $cmpId, string $commandType, string $entityType, int $entityId, int $revision = 0): ?array
    {
        $row = Db::first(
            'SELECT * FROM ' . self::TABLE . '
              WHERE cmp_id = :cmp AND command_type = :ctype AND entity_type = :etype
                AND entity_id = :eid AND revision = :rev',
            ['cmp' => $cmpId, 'ctype' => $commandType, 'etype' => $entityType, 'eid' => $entityId, 'rev' => $revision],
        );

        return $row === null ? null : self::decode($row);
    }

    /** The newest revision of an operation. @return array<string, mixed>|null */
    public static function latest(int $cmpId, string $commandType, string $entityType, int $entityId): ?array
    {
        $row = Db::first(
            'SELECT * FROM ' . self::TABLE . '
              WHERE cmp_id = :cmp AND command_type = :ctype AND entity_type = :etype AND entity_id = :eid
              ORDER BY revision DESC LIMIT 1',
            ['cmp' => $cmpId, 'ctype' => $commandType, 'etype' => $entityType, 'eid' => $entityId],
        );

        return $row === null ? null : self::decode($row);
    }

    /** @return array<string, mixed>|null */
    public static function byId(int $commandId): ?array
    {
        $row = Db::first('SELECT * FROM ' . self::TABLE . ' WHERE command_id = :id', ['id' => $commandId]);

        return $row === null ? null : self::decode($row);
    }

    /**
     * Commands attached to one of our documents, for the status strip on its screen.
     *
     * @return list<array<string, mixed>>
     */
    public static function forEntity(Context $ctx, string $entityType, int $entityId): array
    {
        return self::forEntities($ctx, [[$entityType, $entityId]]);
    }

    /**
     * Commands attached to several documents at once — a purchase order's strip shows its
     * receipts', bills' and returns' commands, which are filed under those documents.
     *
     * @param list<array{0: string, 1: int}> $entities
     * @return list<array<string, mixed>>
     */
    public static function forEntities(Context $ctx, array $entities): array
    {
        if ($entities === []) {
            return [];
        }
        $conds = [];
        $params = ['cmp' => $ctx->cmpId];
        foreach (array_values($entities) as $i => [$type, $id]) {
            $conds[] = "(entity_type = :t{$i} AND entity_id = :e{$i})";
            $params["t{$i}"] = $type;
            $params["e{$i}"] = $id;
        }

        return array_map(
            static fn (array $row) => self::decode($row),
            Db::all(
                'SELECT command_id, target_service, command_type, entity_type, entity_id, revision, status,
                        attempts, last_error, last_status_code, external_reference, resolved_by,
                        last_attempt_at, completed_at, lease_expires_at, created_at
                   FROM ' . self::TABLE . '
                  WHERE cmp_id = :cmp AND (' . implode(' OR ', $conds) . ')
                  ORDER BY command_id',
                $params,
            ),
        );
    }

    /** Everything still unresolved, so the UI can show a real count instead of hiding it. */
    public static function outstanding(Context $ctx, int $limit = 100): array
    {
        [$scope, $params] = $ctx->scopeClause();

        return array_map(
            static fn (array $row) => self::decode($row),
            Db::all(
                'SELECT * FROM ' . self::TABLE . "
                  WHERE {$scope} AND status IN ('PENDING', 'POSTING', 'FAILED', 'UNCERTAIN', 'BLOCKED')
                  ORDER BY updated_at DESC LIMIT " . max(1, min(500, $limit)),
                $params,
            ),
        );
    }

    /**
     * The idempotency key of an operation: stable for the operation, unique across
     * companies, different for a new revision. No random part — that is the point.
     *
     * This is the LOGICAL key, kept here whole. What another product receives is derived from
     * it on the way out and sized to that product's column (IdempotencyKey, applied by
     * ApiClient to every call): Books keeps 64 characters, and a debit note's key with its
     * `:draft` step is longer than that.
     */
    public static function key(int $cmpId, string $commandType, string $entityType, int $entityId, int $revision = 0): string
    {
        return sprintf(
            '%s:%d:%s:%s:%d:r%d',
            Env::get('APP_PRODUCT_KEY', 'purchases'),
            $cmpId,
            $commandType,
            $entityType,
            $entityId,
            $revision,
        );
    }

    /** @param array<string, mixed> $values */
    private static function settle(int $commandId, string $leaseToken, string $status, array $values): bool
    {
        $sets = ['status = :status', 'lease_token = NULL', 'lease_expires_at = NULL', 'updated_at = NOW()'];
        $params = ['status' => $status, 'id' => $commandId, 'token' => $leaseToken];
        foreach ($values as $column => $value) {
            $sets[] = Db::quoteIdentifier($column) . ' = :v_' . $column;
            $params['v_' . $column] = $value;
        }

        // Only the attempt that holds the lease may record its outcome. One whose lease
        // ran out while it waited on the network has been superseded; its answer is the
        // same one the newer attempt will get for the same key, so nothing is lost.
        $count = Db::run(
            'UPDATE ' . self::TABLE . ' SET ' . implode(', ', $sets) . "
              WHERE command_id = :id AND lease_token = :token AND status = 'POSTING'",
            $params,
        )->rowCount();

        return $count === 1;
    }

    /** @param array<string, mixed> $row */
    private static function decode(array $row): array
    {
        foreach (['request_summary', 'request_payload', 'external_reference'] as $column) {
            if (array_key_exists($column, $row)) {
                $row[$column] = $row[$column] === null ? null : Db::jsonColumn($row[$column]);
            }
        }

        return $row;
    }

    /** Keep the cause, drop the novel — an error column is not a log. */
    private static function trimError(string $error): string
    {
        $clean = trim(preg_replace('/\s+/', ' ', $error) ?? $error);

        return mb_substr($clean, 0, 480);
    }

    private static function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }
}
