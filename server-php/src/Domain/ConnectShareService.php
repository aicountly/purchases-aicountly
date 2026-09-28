<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain;

use Aicountly\Api\Audit;
use Aicountly\Api\Auth;
use Aicountly\Api\Context;
use Aicountly\Api\Db;
use Aicountly\Api\Http;
use Aicountly\Api\Permissions;

/**
 * What Aicountly Connect may say about a Purchases document shared in a conversation.
 *
 * Connect never decides this itself. Before it attaches a document it asks, with the SHARER's
 * session, whether that person may share it and which of the people in the conversation could
 * open it themselves (share-check); whenever somebody looks at it, it asks again with THAT
 * person's session what they may see (context). Both answers come from the same permission the
 * document's own screen enforces, so being in a conversation never opens a document to anyone.
 *
 * The label carries the document's kind and number only — never an amount or a supplier — because
 * it is shown to everybody in the conversation, including people who may not open the document.
 */
final class ConnectShareService
{
    /** Each document Connect may point at, and the permission its own screen enforces. */
    public const ENTITIES = [
        'purchase_order' => [
            'permission' => 'po.view', 'table' => 'purchase_orders', 'key' => 'po_id', 'number' => 'po_no',
            'noun' => 'Purchase order', 'open' => '/purchase-orders/%d', 'amount' => 'total_amount', 'party' => 'supplier_name_snapshot', 'title' => null,
        ],
        'purchase_bill' => [
            'permission' => 'match.view', 'table' => 'purchase_bill_requests', 'key' => 'request_id', 'number' => 'supplier_invoice_no',
            'noun' => 'Supplier bill', 'open' => '/bills/%d', 'amount' => null, 'party' => null, 'title' => null,
        ],
        'purchase_return' => [
            'permission' => 'return.create', 'table' => 'purchase_returns', 'key' => 'return_id', 'number' => 'return_no',
            'noun' => 'Purchase return', 'open' => '/returns/%d', 'amount' => null, 'party' => null, 'title' => null,
        ],
        'claim' => [
            'permission' => 'claim.create', 'table' => 'purchase_claims', 'key' => 'claim_id', 'number' => 'claim_no',
            'noun' => 'Supplier claim', 'open' => '/claims', 'amount' => 'claimed_amount', 'party' => null, 'title' => 'description',
        ],
        'requisition' => [
            'permission' => 'requisition.view', 'table' => 'purchase_requisitions', 'key' => 'requisition_id', 'number' => 'requisition_no',
            'noun' => 'Requisition', 'open' => '/requisitions/%d', 'amount' => 'estimated_value', 'party' => null, 'title' => 'justification',
        ],
        'rfq' => [
            'permission' => 'rfq.view', 'table' => 'purchase_rfqs', 'key' => 'rfq_id', 'number' => 'rfq_no',
            'noun' => 'RFQ', 'open' => '/rfqs/%d', 'amount' => null, 'party' => null, 'title' => 'title',
        ],
    ];

    public const MAX_RECIPIENTS = 50;

    public function __construct(
        private readonly Context $ctx,
        private readonly Auth $auth,
    ) {
    }

    /**
     * May the caller share this document, and which of these people could open it?
     *
     * @param array<string, mixed> $input {entity_type, entity_id, recipient_uuids[]}
     * @return array{allowed: bool, reason: ?string, label: ?string, recipients: list<array{uuid: string, can_view: bool}>}
     */
    public function shareCheck(array $input): array
    {
        $type = (string) ($input['entity_type'] ?? '');
        $entity = self::ENTITIES[$type] ?? null;
        if ($entity === null) {
            Http::error(422, 'unsupported_entity', 'Purchases does not share that kind of document.', ['supported' => array_keys(self::ENTITIES)]);
        }
        $id = self::positiveInt($input['entity_id'] ?? null);
        if ($id === null) {
            Http::validationFailed('entity_id must be a positive whole number.', ['field' => 'entity_id']);
        }
        $recipients = self::recipients($input['recipient_uuids'] ?? []);

        if (!Permissions::allows($this->ctx, $this->auth, $entity['permission'])) {
            return [
                'allowed'    => false,
                'reason'     => sprintf('You cannot open this %s in Purchases, so you cannot share it.', strtolower($entity['noun'])),
                'label'      => null,
                'recipients' => array_map(static fn (string $uuid) => ['uuid' => $uuid, 'can_view' => false], $recipients),
            ];
        }
        $row = $this->record($type, $id);
        if ($row === null) {
            Http::notFound(sprintf('That %s is not one of this company\'s.', strtolower($entity['noun'])));
        }

        $grants = Permissions::grantedTo($this->ctx->cmpId, $recipients);
        $self = strtolower($this->auth->uuid);
        $answer = [];
        foreach ($recipients as $uuid) {
            $answer[] = [
                'uuid'     => $uuid,
                'can_view' => $uuid === $self || in_array($entity['permission'], $grants[$uuid] ?? [], true),
            ];
        }
        Audit::record($this->ctx, $this->auth, 'connect.share_checked', $type, $id, null, [
            'recipients' => count($recipients),
            'can_view'   => count(array_filter($answer, static fn (array $r) => $r['can_view'])),
        ]);

        return ['allowed' => true, 'reason' => null, 'label' => self::label($entity, $row), 'recipients' => $answer];
    }

    /**
     * What the caller may see of a shared document right now — asked with their own session.
     *
     * @return array{label: string, title: ?string, status: ?string, amount: ?string, party_name: ?string, open_path: string}
     */
    public function context(string $type, int $id): array
    {
        $entity = self::ENTITIES[$type] ?? null;
        if ($entity === null || $id <= 0) {
            Http::notFound('Purchases has no such document.');
        }
        Permissions::assert($this->ctx, $this->auth, $entity['permission']);
        $row = $this->record($type, $id);
        if ($row === null) {
            Http::notFound(sprintf('That %s is not one of this company\'s.', strtolower($entity['noun'])));
        }

        $title = $entity['title'] === null ? null : self::text($row[$entity['title']] ?? null, 200);

        return [
            'label'      => self::label($entity, $row),
            'title'      => $title,
            'status'     => self::text($row['status'] ?? null, 60),
            'amount'     => $entity['amount'] === null || ($row[$entity['amount']] ?? null) === null ? null : (string) $row[$entity['amount']],
            'party_name' => $entity['party'] === null ? null : self::text($row[$entity['party']] ?? null, 200),
            'open_path'  => str_contains($entity['open'], '%d') ? sprintf($entity['open'], $id) : $entity['open'],
        ];
    }

    /**
     * The financial year a document was raised in — the scope a context read runs under when
     * Connect does not know the viewer's year. Null when this company has no such document.
     */
    public static function yearOf(int $cmpId, string $type, int $id): ?int
    {
        $entity = self::ENTITIES[$type] ?? null;
        if ($entity === null || $id <= 0) {
            return null;
        }
        $fy = Db::scalar(
            sprintf('SELECT fy_id FROM %s WHERE %s = :id AND cmp_id = :cmp', $entity['table'], $entity['key']),
            ['id' => $id, 'cmp' => $cmpId],
        );

        return $fy === null ? null : (int) $fy;
    }

    /** @return array<string, mixed>|null */
    private function record(string $type, int $id): ?array
    {
        $entity = self::ENTITIES[$type];

        return Db::first(
            sprintf('SELECT * FROM %s WHERE %s = :id AND cmp_id = :cmp', $entity['table'], $entity['key']),
            ['id' => $id, 'cmp' => $this->ctx->cmpId],
        );
    }

    /** @param array<string, mixed> $entity @param array<string, mixed> $row */
    private static function label(array $entity, array $row): string
    {
        $number = self::text($row[$entity['number']] ?? null, 120);

        return $number === null ? $entity['noun'] : $entity['noun'] . ' ' . $number;
    }

    /** @return list<string> */
    private static function recipients(mixed $raw): array
    {
        if (!is_array($raw) || !array_is_list($raw)) {
            Http::validationFailed('recipient_uuids must be a list.', ['field' => 'recipient_uuids']);
        }
        if (count($raw) > self::MAX_RECIPIENTS) {
            Http::validationFailed(sprintf('At most %d people can be asked about at once.', self::MAX_RECIPIENTS), ['field' => 'recipient_uuids']);
        }
        $out = [];
        foreach ($raw as $uuid) {
            $uuid = is_string($uuid) ? strtolower(trim($uuid)) : '';
            if ($uuid === '' || strlen($uuid) > 64 || preg_match('/^[a-z0-9._:-]+$/', $uuid) !== 1) {
                Http::validationFailed('Each recipient must be a user id.', ['field' => 'recipient_uuids']);
            }
            $out[$uuid] = true;
        }

        return array_keys($out);
    }

    private static function positiveInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (is_string($value) && preg_match('/^[1-9][0-9]{0,17}$/', $value) === 1) {
            return (int) $value;
        }

        return null;
    }

    private static function text(mixed $value, int $max): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $max);
    }
}
