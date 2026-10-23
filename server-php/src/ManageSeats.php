<?php

declare(strict_types=1);

namespace Aicountly\Api;

use Aicountly\Api\Controllers\AccessController;
use Aicountly\Lifecycle\Receiver;
use Aicountly\Lifecycle\Signature;

/**
 * Manage → Purchases: POST /api/internal/manage/seats (CONTRACTS §5, FLEET_CONTRACT E).
 *
 *   {"event_id":"uuid","cmp_id":42,"platform_user_id":"…","uuid":"…","role_key":"buyer","action":"grant|revoke"}
 *
 * Manage calls it after it grants a person Purchases on a company (a share, an approved access
 * request, a creator's own access in a shared workspace), so the person holds a Purchases profile
 * straight away instead of "You have no access to Purchases in this company" (F357) until somebody
 * with access.manage assigns one.
 *
 * Signed exactly like the company-lifecycle receiver and verified by the same code: the lifecycle
 * kit's Signature with LIFECYCLE_WEBHOOK_SECRET (X-Manage-Timestamp, X-Manage-Signature
 * sha256=HMAC("{ts}.{body}"), 300 s skew). Manage decided the grant; Purchases' own "you may only
 * hand out what you hold" rule (AccessController::assertMayGrant) is for a person assigning here.
 *
 *   role_key   a Purchases starter profile (AccessController::STARTER_PROFILES): buyer, approver,
 *              payables, viewer. Owner is never seated this way (ownership is Manage's word on the
 *              company row). The company's profile for that starter is used — created from the
 *              starter when the company has none yet, exactly as Settings → Access → bootstrap would.
 *   grant      the person holds that profile. Nothing they already hold is taken away (a grant never
 *              lowers anyone): "already" when they hold it.
 *   revoke     Manage withdrew Purchases: every Purchases profile the person holds in the company is
 *              released, as an administrator's unassign would. "already" when they held none.
 *
 *   200 {"success":true,"data":{"status":"seated|already|revoked"}}
 *   401 signature_* / timestamp_*   not signed by Manage
 *   503 lifecycle_not_configured    no usable LIFECYCLE_WEBHOOK_SECRET (Manage retries)
 *   422 invalid_event               a signed body that does not follow the contract
 *   422 ROLE_UNKNOWN                role_key is not a Purchases role Manage may seat
 *   503 event_not_recorded          the database failed (Manage retries)
 *
 * Idempotent by event_id: a redelivery answers the status the first delivery recorded.
 */
final class ManageSeats
{
    /** Assignments Manage seated, and the audit actor. */
    public const ACTOR = 'system:manage-seat';

    /** Assignments taken on first entry under workspace-wide access, and their audit actor. */
    public const WORKSPACE_ACTOR = 'system:manage-workspace';

    /** The least-privileged Purchases role: what an unnamed role would mean (never used to guess). */
    public const LEAST_PRIVILEGED = 'viewer';

    private const TABLE_EVENTS = 'purchase_manage_seat_events';

    private const MAX_BODY_BYTES = 16384;

    private const RACED = 'manage seat event recorded concurrently';

    /** @return list<string> the role keys Manage may name, in the order Purchases lists them */
    public static function roles(): array
    {
        return array_keys(AccessController::STARTER_PROFILES);
    }

    /** POST internal/manage/seats */
    public static function receive(): void
    {
        $headers = function_exists('getallheaders') ? (getallheaders() ?: $_SERVER) : $_SERVER;
        [$status, $body] = self::handle((string) file_get_contents('php://input'), $headers);
        Http::json($status, $body);
    }

    /**
     * @param array<string, mixed> $headers
     * @param (callable(): ?string)|null $secret test seam; default the lifecycle kit's configured secret
     * @return array{0: int, 1: array<string, mixed>}
     */
    public static function handle(string $raw, array $headers, ?callable $secret = null, ?int $now = null): array
    {
        require_once __DIR__ . '/../lib/company-lifecycle/autoload.php';
        if (strlen($raw) > self::MAX_BODY_BYTES) {
            return self::answer(413, 'payload_too_large', 'A seat event is at most 16 KiB.');
        }
        try {
            $key = $secret !== null ? $secret() : CompanyLifecycle::kit()->config->secret;
        } catch (\Throwable $e) {
            error_log('[purchases] manage seats: lifecycle kit unavailable: ' . $e->getMessage());
            $key = null;
        }
        $verdict = Signature::verify($key, Receiver::header($headers, Signature::HEADER_TIMESTAMP), $raw, Receiver::header($headers, Signature::HEADER_SIGNATURE), $now ?? time());
        if ($verdict === Signature::NOT_CONFIGURED) {
            error_log('[purchases] manage seats: refused, LIFECYCLE_WEBHOOK_SECRET is not set (or shorter than 32 characters)');

            return self::answer(503, $verdict, 'Purchases is not configured to receive seats from AICOUNTLY Manage.');
        }
        if ($verdict !== Signature::OK) {
            error_log('[purchases] manage seats: unsigned or badly signed event refused (' . $verdict . ')');

            return self::answer(401, $verdict, 'The seat event is not signed by AICOUNTLY Manage.');
        }

        $event = self::parse($raw, $error, $code);
        if ($event === null) {
            return self::answer(422, (string) $code, (string) $error);
        }

        try {
            [$status, $duplicate] = self::apply($event, $raw);
        } catch (\Throwable $e) {
            error_log('[purchases] manage seats: event ' . $event['event_id'] . ' not recorded: ' . $e->getMessage());

            return self::answer(503, 'event_not_recorded', 'Purchases could not record this seat. Retry later.');
        }

        return [200, ['success' => true, 'data' => ['status' => $status, 'event_id' => $event['event_id'], 'duplicate' => $duplicate]]];
    }

    /**
     * @param array{event_id: string, cmp_id: int, uuid: string, platform_user_id: ?string, role_key: ?string, action: string} $event
     * @return array{0: string, 1: bool} [status, duplicate]
     */
    private static function apply(array $event, string $raw): array
    {
        $seen = Db::scalar('SELECT outcome FROM ' . self::TABLE_EVENTS . ' WHERE event_id = CAST(:id AS uuid)', ['id' => $event['event_id']]);
        if (is_string($seen) && $seen !== '') {
            return [$seen, true];
        }

        $cmpId = $event['cmp_id'];
        $uuid = $event['uuid'];
        try {
            $result = Db::transaction(static function () use ($event, $raw, $cmpId, $uuid): array {
                if ($event['action'] === 'grant') {
                    $outcome = self::seat($cmpId, $uuid, (string) $event['role_key'], self::ACTOR, 'manage_seat:' . $event['event_id']) ? 'seated' : 'already';
                } else {
                    $removed = Db::all(
                        'DELETE FROM ' . Permissions::TABLE_ASSIGNMENTS . ' WHERE cmp_id = :cmp AND user_uuid = :uuid RETURNING assignment_id, profile_id',
                        ['cmp' => $cmpId, 'uuid' => $uuid],
                    );
                    foreach ($removed as $row) {
                        Audit::recordSystem($cmpId, 0, 0, self::ACTOR, 'access.profile_unassigned', 'permission_assignment', (int) $row['assignment_id'],
                            ['user_uuid' => $uuid, 'profile_id' => (int) $row['profile_id']], null, 'manage_seat:' . $event['event_id']);
                    }
                    $outcome = $removed !== [] ? 'revoked' : 'already';
                }

                $recorded = Db::run(
                    'INSERT INTO ' . self::TABLE_EVENTS . ' (event_id, cmp_id, user_uuid, action, role_key, outcome, payload)
                     VALUES (CAST(:id AS uuid), :cmp, :uuid, :action, :role, :outcome, CAST(:payload AS jsonb))
                     ON CONFLICT (event_id) DO NOTHING',
                    ['id' => $event['event_id'], 'cmp' => $cmpId, 'uuid' => $uuid, 'action' => $event['action'], 'role' => $event['role_key'], 'outcome' => $outcome, 'payload' => $raw],
                )->rowCount();
                if ($recorded === 0) {
                    // A concurrent delivery of the same event won: undo ours, answer with theirs.
                    throw new \RuntimeException(self::RACED);
                }

                return [$outcome, false];
            });
        } catch (\RuntimeException $e) {
            if ($e->getMessage() !== self::RACED) {
                throw $e;
            }
            $theirs = Db::scalar('SELECT outcome FROM ' . self::TABLE_EVENTS . ' WHERE event_id = CAST(:id AS uuid)', ['id' => $event['event_id']]);
            $result = [is_string($theirs) ? $theirs : 'already', true];
        }

        Permissions::forget();

        return $result;
    }

    /**
     * Give $uuid the company's profile for starter $role (created from the starter when the company
     * has none). True when an assignment was added; false when they already held it.
     */
    private static function seat(int $cmpId, string $uuid, string $role, string $actor, string $reason): bool
    {
        $profileId = self::profileFor($cmpId, $role, $actor);
        $assignmentId = Db::scalar(
            'INSERT INTO ' . Permissions::TABLE_ASSIGNMENTS . ' (cmp_id, user_uuid, profile_id, assigned_by, note, updated_at)
             VALUES (:cmp, :uuid, :profile, :by, :note, NOW())
             ON CONFLICT (cmp_id, user_uuid, profile_id) DO NOTHING
             RETURNING assignment_id',
            ['cmp' => $cmpId, 'uuid' => $uuid, 'profile' => $profileId, 'by' => $actor,
                'note' => $actor === self::WORKSPACE_ACTOR ? 'Workspace-wide access from AICOUNTLY Manage' : 'Granted in AICOUNTLY Manage'],
        );
        if ($assignmentId === null || $assignmentId === false) {
            return false;
        }
        Audit::recordSystem($cmpId, 0, 0, $actor, 'access.profile_assigned', 'permission_assignment', (int) $assignmentId, null,
            ['user_uuid' => $uuid, 'profile_id' => $profileId, 'role_key' => $role], $reason);

        return true;
    }

    /** The company's profile for a starter: by its system key, else by the starter's name, else created. */
    private static function profileFor(int $cmpId, string $role, string $actor): int
    {
        $starter = AccessController::STARTER_PROFILES[$role];
        $existing = Db::scalar(
            'SELECT profile_id FROM ' . Permissions::TABLE_PROFILES . '
              WHERE cmp_id = :cmp AND (system_key = :key OR lower(profile_name) = lower(:name))
              ORDER BY (system_key IS NOT NULL AND system_key = :key_again) DESC, profile_id LIMIT 1',
            ['cmp' => $cmpId, 'key' => $role, 'key_again' => $role, 'name' => $starter['name']],
        );
        if ($existing !== null && $existing !== false) {
            return (int) $existing;
        }
        $profileId = (int) Db::insert(Permissions::TABLE_PROFILES, [
            'cmp_id'       => $cmpId,
            'profile_name' => $starter['name'],
            'description'  => $starter['description'],
            'permissions'  => json_encode($starter['permissions']),
            'system_key'   => $role,
            'is_active'    => true,
            'created_by'   => $actor,
        ], 'profile_id');
        Audit::recordSystem($cmpId, 0, 0, $actor, 'access.profile_created', 'permission_profile', $profileId, null,
            ['profile_name' => $starter['name'], 'permissions' => $starter['permissions'], 'source' => 'manage_seat'], 'starter profile for a Manage seat');

        return $profileId;
    }

    /**
     * Workspace-wide access (FLEET_CONTRACT C): Manage's companyinfo (asked naming Purchases) says
     * `app_access_source: workspace` and `app_role_key` for a member the workspace owner gave every
     * company of the workspace. Manage stores no seat for that, so the person is given that Purchases
     * profile here on first entry — only when they hold no Purchases profile in the company yet (an
     * existing seat is never changed, so never lowered), never the owner, and an unknown or missing
     * role means the least-privileged one. Best effort: a failure leaves them unseated (403
     * no_purchases_access, as today), never refused company access.
     *
     * @param array<string, mixed> $company companyinfo's company row
     * @return bool whether a seat was created
     */
    public static function seatWorkspaceMember(int $cmpId, string $uuid, array $company): bool
    {
        $source = is_string($company['app_access_source'] ?? null) ? strtolower(trim($company['app_access_source'])) : '';
        if ($source !== 'workspace' || $cmpId <= 0 || !self::validUuid($uuid)) {
            return false;
        }
        $role = is_string($company['app_role_key'] ?? null) ? strtolower(trim($company['app_role_key'])) : '';
        if (!in_array($role, self::roles(), true)) {
            $role = self::LEAST_PRIVILEGED;
        }
        try {
            $held = (int) Db::scalar(
                'SELECT COUNT(*) FROM ' . Permissions::TABLE_ASSIGNMENTS . ' WHERE cmp_id = :cmp AND user_uuid = :uuid',
                ['cmp' => $cmpId, 'uuid' => $uuid],
            );
            if ($held > 0) {
                return false;
            }
            $added = (bool) Db::transaction(static fn (): bool => self::seat($cmpId, $uuid, $role, self::WORKSPACE_ACTOR, 'workspace_access'));
            if ($added) {
                Permissions::forget();
            }

            return $added;
        } catch (\Throwable $e) {
            error_log('[purchases] workspace member not seated for cmp=' . $cmpId . ': ' . $e->getMessage());

            return false;
        }
    }

    /**
     * The contract, field by field. On failure $code is invalid_event or ROLE_UNKNOWN.
     *
     * @return array{event_id: string, cmp_id: int, uuid: string, platform_user_id: ?string, role_key: ?string, action: string}|null
     */
    public static function parse(string $raw, ?string &$error = null, ?string &$code = null): ?array
    {
        $code = 'invalid_event';
        $body = json_decode($raw, true);
        if (!is_array($body) || array_is_list($body)) {
            $error = 'A JSON object body is required.';

            return null;
        }
        $eventId = is_string($body['event_id'] ?? null) ? strtolower(trim($body['event_id'])) : '';
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $eventId) !== 1) {
            $error = 'event_id must be a UUID.';

            return null;
        }
        $cmpRaw = $body['cmp_id'] ?? null;
        $cmpId = (is_int($cmpRaw) || (is_string($cmpRaw) && ctype_digit($cmpRaw))) ? (int) $cmpRaw : 0;
        if ($cmpId <= 0) {
            $error = 'cmp_id must be a positive integer.';

            return null;
        }
        $uuid = is_scalar($body['uuid'] ?? null) ? trim((string) $body['uuid']) : '';
        if (!self::validUuid($uuid)) {
            $error = 'uuid must be the person\'s AICOUNTLY id.';

            return null;
        }
        $platform = is_scalar($body['platform_user_id'] ?? null) ? trim((string) $body['platform_user_id']) : '';
        $action = is_string($body['action'] ?? null) ? strtolower(trim($body['action'])) : '';
        if (!in_array($action, ['grant', 'revoke'], true)) {
            $error = 'action must be grant or revoke.';

            return null;
        }
        $role = is_string($body['role_key'] ?? null) ? strtolower(trim($body['role_key'])) : '';
        if ($action === 'grant' || $role !== '') {
            if (!in_array($role, self::roles(), true)) {
                $code = 'ROLE_UNKNOWN';
                $error = $role === 'owner'
                    ? 'The owner is never seated through Manage.'
                    : 'role_key must be one of ' . implode(', ', self::roles()) . '.';

                return null;
            }
        }

        return [
            'event_id'         => $eventId,
            'cmp_id'           => $cmpId,
            'uuid'             => $uuid,
            'platform_user_id' => $platform !== '' ? $platform : null,
            'role_key'         => $role !== '' ? $role : null,
            'action'           => $action,
        ];
    }

    private static function validUuid(string $uuid): bool
    {
        return $uuid !== '' && strlen($uuid) <= 128 && preg_match('/^[A-Za-z0-9_-]+$/D', $uuid) === 1;
    }

    /** @return array{0: int, 1: array<string, mixed>} */
    private static function answer(int $status, string $code, string $message): array
    {
        return [$status, ['success' => false, 'error' => ['code' => $code, 'message' => $message, 'details' => []], 'message' => $message]];
    }
}
