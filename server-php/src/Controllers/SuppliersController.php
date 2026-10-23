<?php

declare(strict_types=1);

namespace Aicountly\Api\Controllers;

use Aicountly\Api\Domain\SupplierNames;
use Aicountly\Api\Audit;
use Aicountly\Api\Clients\BooksClient;
use Aicountly\Api\Clients\InventoryClient;
use Aicountly\Api\Domain\PlaceOfSupply;
use Aicountly\Api\Db;
use Aicountly\Api\Domain\SupplierContactService;
use Aicountly\Api\Http;
use Aicountly\Api\Permissions;

/**
 * Supplier PROCUREMENT profiles and scorecards.
 *
 * The supplier's legal identity belongs to Contacts and their account ledger to
 * Books. What lives here is only what procurement decides about them: approved
 * or not, preferred or not, how they actually perform.
 */
final class SuppliersController extends Controller
{
    public static function index(): void
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'supplier.view');

        Http::data(SupplierNames::attach($ctx, $auth, Db::all(
            'SELECT * FROM purchase_supplier_profiles WHERE cmp_id = :cmp ORDER BY is_preferred DESC, supplier_account_id',
            ['cmp' => $ctx->cmpId],
        )));
    }

    /**
     * POST v1/suppliers/ledger — a supplier the company has never bought from, added from the purchase
     * order editor (launch-readiness P1-P8). A supplier IS a Books creditor ledger (Books owns the
     * accounting identity; this product keeps only its procurement profile), so this asks Smart Books to
     * create one, as the signed-in person, under the Sundry Creditors group read live:
     *
     *   body     {name, gstin?, state_code?, address?, city?, pincode?}
     *   header   Idempotency-Key: one per "New supplier" form, so a retry after a lost answer is Books'
     *            replay of the same ledger, never a second one
     *
     * The same name among the company's creditors is 409 supplier_exists (with the ledger to pick
     * instead); a GSTIN is checked here (shape, state, check digit) and decides the state. Answers
     * 201 {acc_id, acc_name, created|replayed}. Books' own refusal (permission, duplicate) is passed on.
     */
    public static function createLedger(): void
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'supplier.manage');

        $body = Http::body();
        $name = trim((string) preg_replace('/\s+/u', ' ', (string) ($body['name'] ?? '')));
        if ($name === '' || mb_strlen($name) > 150) {
            Http::validationFailed('The supplier\'s name is required (at most 150 characters).', ['field' => 'name']);
        }
        $gstin = PlaceOfSupply::normaliseGstin($body['gstin'] ?? null, 'gstin');
        $typedState = trim((string) ($body['state_code'] ?? ''));
        $state = $gstin !== null ? PlaceOfSupply::stateOfGstin($gstin) : null;
        if ($typedState !== '') {
            $typed = preg_match('/^\d{1,2}$/', $typedState) === 1 ? str_pad($typedState, 2, '0', STR_PAD_LEFT) : null;
            if ($typed === null || !isset(\Aicountly\Api\Pdf\PurchaseOrderTax::STATES[$typed])) {
                Http::validationFailed('Choose the supplier\'s state from the list.', ['field' => 'state_code']);
            }
            if ($state !== null && $typed !== $state) {
                Http::validationFailed('The GSTIN is registered in another state (' . $state . '); the state must match it.', ['field' => 'state_code']);
            }
            $state = $typed;
        }
        $requestKey = trim(Http::header('Idempotency-Key'));
        if (preg_match('/^[A-Za-z0-9_-]{8,64}$/', $requestKey) !== 1) {
            Http::validationFailed('An Idempotency-Key header (8-64 letters, digits, - or _) is required, one per new supplier.', ['field' => 'Idempotency-Key']);
        }

        $books = (new BooksClient())->withSession($auth->sesKey());
        $found = $books->accounts($ctx, ['q' => $name, 'anchor_code' => 'SUNDRY_CREDITORS', 'per_page' => 50]);
        if (!$found['ok']) {
            self::booksRefused($found);
        }
        foreach ((array) ($found['body']['data'] ?? []) as $row) {
            if (mb_strtolower(trim((string) ($row['acc_name'] ?? ''))) === mb_strtolower($name)) {
                Http::error(409, 'supplier_exists', 'Smart Books already has a supplier called "' . $name . '". Choose it instead of adding it again.', [
                    'acc_id' => (int) ($row['acc_id'] ?? 0), 'acc_name' => (string) $row['acc_name'],
                ]);
            }
        }

        $groups = $books->accountGroups($ctx);
        if (!$groups['ok']) {
            self::booksRefused($groups);
        }
        $groupId = null;
        foreach ((array) ($groups['body']['data'] ?? $groups['body'] ?? []) as $group) {
            if (is_array($group) && strtoupper((string) ($group['anchor_code'] ?? '')) === 'SUNDRY_CREDITORS') {
                $groupId = (int) ($group['acc_grp_id'] ?? $group['id'] ?? 0) ?: null;
                break;
            }
        }
        if ($groupId === null) {
            Http::error(422, 'creditors_group_missing', 'Smart Books has no Sundry Creditors group for this company, so a supplier cannot be added. Open Masters → Account Groups in Smart Books.');
        }

        $payload = array_filter([
            'acc_name'     => $name,
            'acc_grp_id'   => $groupId,
            'party_type'   => 'supplier',
            'is_active'    => 1,
            'gstin'        => $gstin,
            'state_code'   => $state,
            'address'      => self::text($body['address'] ?? null),
            'city'         => self::text($body['city'] ?? null),
            'pincode'      => self::text($body['pincode'] ?? null),
            'country_code' => 'IN',
        ], static fn ($v) => $v !== null && $v !== '');
        $result = $books->createAccount($ctx, $payload, 'purchases-supplier-' . $ctx->cmpId . '-' . $requestKey);
        if (!$result['ok']) {
            self::booksRefused($result);
        }
        $data = is_array($result['body']['data'] ?? null) ? $result['body']['data'] : (array) ($result['body'] ?? []);
        $accountId = (int) ($data['acc_id'] ?? $data['id'] ?? 0);
        if ($accountId <= 0) {
            Http::error(502, 'upstream_unclear', 'Smart Books did not say which ledger it created. Search for the supplier before adding it again.');
        }
        $replayed = ($result['body']['idempotent_replay'] ?? false) === true;
        if (!$replayed) {
            // Best effort: the ledger exists in Books whatever happens to this row.
            Audit::record($ctx, $auth, 'supplier.ledger_created', 'supplier', $accountId, null, ['acc_name' => $name, 'gstin' => $gstin, 'state_code' => $state], 'created in Smart Books from Purchases');
        }

        Http::data(['acc_id' => $accountId, 'acc_name' => (string) ($data['acc_name'] ?? $name), 'mode' => $replayed ? 'replayed' : 'created'], 201);
    }

    /** Books' refusal as the person should read it: its own message, or "could not reach". */
    private static function booksRefused(array $result): never
    {
        $status = (int) ($result['status'] ?? 0);
        if ($status === 0 || $status >= 500) {
            Http::error(503, 'upstream_unavailable', 'Could not reach Smart Books. Nothing was created — try again.', ['retryable' => true]);
        }
        $body = (array) ($result['body'] ?? []);
        $messages = $body['messages'] ?? $body['error']['message'] ?? $body['message'] ?? $result['error'] ?? 'Smart Books refused this.';
        $message = is_array($messages) ? implode(' ', array_map('strval', array_values(array_filter($messages, 'is_scalar')))) : (string) $messages;
        $code = is_array($body['error'] ?? null) ? (string) ($body['error']['code'] ?? 'books_refused') : 'books_refused';
        Http::error(in_array($status, [401, 403, 409, 422], true) ? $status : 422, $code, $message === '' ? 'Smart Books refused this.' : $message);
    }

    public static function upsert(): void
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'supplier.manage');

        $body = Http::body();
        $supplierId = (int) ($body['supplier_account_id'] ?? 0);
        if ($supplierId <= 0) {
            Http::validationFailed('Say which supplier this profile is for.', ['field' => 'supplier_account_id']);
        }

        $existing = Db::first(
            'SELECT * FROM purchase_supplier_profiles WHERE cmp_id = :cmp AND supplier_account_id = :supplier',
            ['cmp' => $ctx->cmpId, 'supplier' => $supplierId],
        );

        $fields = [
            // contact_id is NOT taken from the body: it is a cache of the link Contacts holds,
            // written only by SupplierContactService (G14#10). Kept as it is.
            'contact_id'            => $existing['contact_id'] ?? null,
            'is_preferred'          => (bool) ($body['is_preferred'] ?? ($existing['is_preferred'] ?? false)),
            'approved_categories'   => is_array($body['approved_categories'] ?? null) ? $body['approved_categories'] : Db::jsonColumn($existing['approved_categories'] ?? null),
            'operational_lead_days' => isset($body['operational_lead_days']) ? (int) $body['operational_lead_days'] : ($existing['operational_lead_days'] ?? null),
            'payment_terms'         => self::text($body['payment_terms'] ?? null) ?? ($existing['payment_terms'] ?? null),
            'incoterm'              => self::text($body['incoterm'] ?? null) ?? ($existing['incoterm'] ?? null),
            'risk_flag'             => self::text($body['risk_flag'] ?? null) ?? ($existing['risk_flag'] ?? null),
            'notes'                 => self::text($body['notes'] ?? null) ?? ($existing['notes'] ?? null),
            'updated_at'            => gmdate('Y-m-d H:i:s'),
        ];

        if ($existing === null) {
            Db::insert('purchase_supplier_profiles', $fields + [
                'cmp_id'              => $ctx->cmpId,
                'supplier_account_id' => $supplierId,
                'qualification_status' => 'draft',
            ], 'profile_id');
        } else {
            Db::update('purchase_supplier_profiles', $fields, ['profile_id' => (int) $existing['profile_id']]);
        }

        Audit::record($ctx, $auth, 'supplier.profile_saved', 'supplier', $supplierId, $existing, $fields);

        Http::data(Db::first(
            'SELECT * FROM purchase_supplier_profiles WHERE cmp_id = :cmp AND supplier_account_id = :supplier',
            ['cmp' => $ctx->cmpId, 'supplier' => $supplierId],
        ) ?? []);
    }

    /**
     * Approve, suspend or blacklist a supplier.
     *
     * A separate permission from editing the profile: deciding who this company
     * is allowed to buy from is not the same job as recording their lead time.
     */
    public static function setStatus(string $supplierId): void
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'supplier.approve');

        $status = (string) (Http::param('qualification_status') ?? '');
        if (!in_array($status, ['draft', 'pending_approval', 'approved', 'suspended', 'blacklisted'], true)) {
            Http::validationFailed('Status must be draft, pending_approval, approved, suspended or blacklisted.', ['field' => 'qualification_status']);
        }

        $existing = Db::first(
            'SELECT * FROM purchase_supplier_profiles WHERE cmp_id = :cmp AND supplier_account_id = :supplier',
            ['cmp' => $ctx->cmpId, 'supplier' => (int) $supplierId],
        );
        if ($existing === null) {
            Http::notFound('That supplier has no procurement profile yet.');
        }

        Db::update('purchase_supplier_profiles', [
            'qualification_status' => $status,
            'approved_by'          => $status === 'approved' ? $auth->uuid : null,
            'approved_at'          => $status === 'approved' ? gmdate('Y-m-d H:i:s') : null,
            'updated_at'           => gmdate('Y-m-d H:i:s'),
        ], ['profile_id' => (int) $existing['profile_id']]);

        Audit::record($ctx, $auth, 'supplier.status_changed', 'supplier', (int) $supplierId, [
            'qualification_status' => $existing['qualification_status'],
        ], ['qualification_status' => $status], (string) (Http::param('note') ?? ''));

        Http::data(Db::first(
            'SELECT * FROM purchase_supplier_profiles WHERE profile_id = :id',
            ['id' => (int) $existing['profile_id']],
        ) ?? []);
    }

    /**
     * Compute and store a supplier scorecard for a period.
     *
     * The SCORE is ours — it is a procurement judgement. The facts behind it are
     * read live from the products that own them (delivery dates from Inventory's
     * receipts, orders from ours) and are NOT stored; only the resulting metrics
     * and a summary of what went into them are.
     */
    public static function scorecard(string $supplierId): void
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'supplier.view');
        // Computing it is a read; storing it is a change to the supplier's record (F497).
        if (Http::method() === 'POST') {
            Permissions::assert($ctx, $auth, 'supplier.manage');
        }

        $from = self::dateParam('from', gmdate('Y-m-d', strtotime('-90 days')));
        $to = self::dateParam('to', gmdate('Y-m-d'));
        if ($from > $to) {
            Http::validationFailed('The period starts after it ends.', ['field' => 'from']);
        }

        // Our own facts: what we ordered and what we committed to.
        $orders = Db::first(
            "SELECT
                COUNT(*)                                                    AS po_count,
                COUNT(*) FILTER (WHERE acknowledged_at IS NOT NULL)          AS acknowledged_count,
                COALESCE(SUM(total_amount), 0)                               AS ordered_value,
                AVG(EXTRACT(EPOCH FROM (acknowledged_at - issued_at)) / 3600) AS ack_hours
             FROM purchase_orders
             WHERE cmp_id = :cmp AND supplier_account_id = :supplier
               AND po_date BETWEEN :from AND :to AND status <> 'CANCELLED'",
            ['cmp' => $ctx->cmpId, 'supplier' => (int) $supplierId, 'from' => $from, 'to' => $to],
        ) ?? [];

        $lines = Db::first(
            "SELECT
                COALESCE(SUM(l.ordered_qty), 0)  AS ordered_qty,
                COALESCE(SUM(l.received_qty), 0) AS received_qty,
                COALESCE(SUM(l.rejected_qty), 0) AS rejected_qty
             FROM purchase_order_lines l
             JOIN purchase_orders p ON p.po_id = l.po_id
             WHERE p.cmp_id = :cmp AND p.supplier_account_id = :supplier
               AND p.po_date BETWEEN :from AND :to AND p.status <> 'CANCELLED'",
            ['cmp' => $ctx->cmpId, 'supplier' => (int) $supplierId, 'from' => $from, 'to' => $to],
        ) ?? [];

        // On-time is judged by comparing what we promised against when Inventory
        // recorded the receipt — the receipt date is Inventory's fact, read from
        // our own request rows which carry it as the date we asked on.
        $delivery = Db::first(
            "SELECT
                COUNT(*)                                                                  AS receipt_count,
                COUNT(*) FILTER (WHERE r.received_at <= p.promised_date)                   AS on_time_count
             FROM purchase_receipt_requests r
             JOIN purchase_orders p ON p.po_id = r.po_id
             WHERE r.cmp_id = :cmp AND p.supplier_account_id = :supplier
               AND r.status = 'ACCEPTED' AND p.promised_date IS NOT NULL
               AND r.received_at BETWEEN :from AND :to",
            ['cmp' => $ctx->cmpId, 'supplier' => (int) $supplierId, 'from' => $from, 'to' => $to],
        ) ?? [];

        $claims = (int) Db::scalar(
            'SELECT COUNT(*) FROM purchase_claims
             WHERE cmp_id = :cmp AND supplier_account_id = :supplier AND claim_date BETWEEN :from AND :to',
            ['cmp' => $ctx->cmpId, 'supplier' => (int) $supplierId, 'from' => $from, 'to' => $to],
        );

        $orderedQty = (float) ($lines['ordered_qty'] ?? 0);
        $receivedQty = (float) ($lines['received_qty'] ?? 0);
        $rejectedQty = (float) ($lines['rejected_qty'] ?? 0);
        $receiptCount = (int) ($delivery['receipt_count'] ?? 0);

        $onTimePc = $receiptCount > 0 ? round(((int) ($delivery['on_time_count'] ?? 0) / $receiptCount) * 100, 3) : null;
        $rejectionPc = $receivedQty > 0 ? round(($rejectedQty / $receivedQty) * 100, 3) : null;
        $fulfilmentPc = $orderedQty > 0 ? round(($receivedQty / $orderedQty) * 100, 3) : null;

        // An overall score needs at least one signal; averaging nothing gives a
        // confident-looking zero for a supplier nobody has bought from.
        $signals = array_values(array_filter([$onTimePc, $rejectionPc === null ? null : 100 - $rejectionPc, $fulfilmentPc], static fn ($v) => $v !== null));
        $overall = $signals === [] ? null : round(array_sum($signals) / count($signals), 3);

        $scorecard = [
            'cmp_id'                   => $ctx->cmpId,
            'fy_id'                    => $ctx->fyId,
            'supplier_account_id'      => (int) $supplierId,
            'period_start'             => $from,
            'period_end'               => $to,
            'on_time_delivery_pc'      => $onTimePc,
            'quality_rejection_pc'     => $rejectionPc,
            'po_acknowledgement_hours' => isset($orders['ack_hours']) ? round((float) $orders['ack_hours'], 2) : null,
            'fulfilment_pc'            => $fulfilmentPc,
            'claim_count'              => $claims,
            'overall_score'            => $overall,
            'basis_summary'            => [
                'po_count'      => (int) ($orders['po_count'] ?? 0),
                // What was spent with the supplier is a cost figure: for cost.view only (F497).
                'ordered_value' => Permissions::allows($ctx, $auth, 'cost.view') ? (float) ($orders['ordered_value'] ?? 0) : null,
                'ordered_value_withheld' => !Permissions::allows($ctx, $auth, 'cost.view'),
                'receipt_count' => $receiptCount,
                'note'          => 'Computed from Purchases documents and the receipts Inventory recorded. No stock or ledger data is stored here.',
            ],
            'computed_by'              => $auth->uuid,
        ];

        if (Http::method() === 'POST') {
            Db::run(
                'INSERT INTO purchase_supplier_scorecards
                    (cmp_id, fy_id, supplier_account_id, period_start, period_end, on_time_delivery_pc,
                     quality_rejection_pc, po_acknowledgement_hours, fulfilment_pc, claim_count,
                     overall_score, basis_summary, computed_by)
                 VALUES (:cmp, :fy, :supplier, :from, :to, :ontime, :rejection, :ack, :fulfil, :claims, :overall, :basis, :by)
                 ON CONFLICT (cmp_id, supplier_account_id, period_start, period_end)
                 DO UPDATE SET on_time_delivery_pc = EXCLUDED.on_time_delivery_pc,
                               quality_rejection_pc = EXCLUDED.quality_rejection_pc,
                               po_acknowledgement_hours = EXCLUDED.po_acknowledgement_hours,
                               fulfilment_pc = EXCLUDED.fulfilment_pc,
                               claim_count = EXCLUDED.claim_count,
                               overall_score = EXCLUDED.overall_score,
                               basis_summary = EXCLUDED.basis_summary,
                               computed_at = NOW(),
                               computed_by = EXCLUDED.computed_by',
                [
                    'cmp' => $ctx->cmpId, 'fy' => $ctx->fyId, 'supplier' => (int) $supplierId,
                    'from' => $from, 'to' => $to,
                    'ontime' => $onTimePc, 'rejection' => $rejectionPc,
                    'ack' => $scorecard['po_acknowledgement_hours'], 'fulfil' => $fulfilmentPc,
                    'claims' => $claims, 'overall' => $overall,
                    'basis' => json_encode($scorecard['basis_summary']), 'by' => $auth->uuid,
                ],
            );
            // Stored is a change to the supplier's record (F497): the figures it was given, and for when.
            Audit::record($ctx, $auth, 'supplier.scorecard_saved', 'supplier', (int) $supplierId, null, [
                'period_start' => $from, 'period_end' => $to, 'overall_score' => $overall,
                'on_time_delivery_pc' => $onTimePc, 'quality_rejection_pc' => $rejectionPc,
                'fulfilment_pc' => $fulfilmentPc, 'claim_count' => $claims,
            ]);
        }

        Http::data($scorecard);
    }

    /** A Y-m-d query parameter that is a real date, or the default; anything else is 422 (F266). */
    private static function dateParam(string $name, string $default): string
    {
        $value = Http::param($name);
        if ($value === null || trim((string) $value) === '') {
            return $default;
        }
        $value = trim((string) $value);
        $parts = explode('-', $value);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1 || !checkdate((int) $parts[1], (int) $parts[2], (int) $parts[0])) {
            Http::validationFailed(sprintf('"%s" is not a date (YYYY-MM-DD).', $value), ['field' => $name]);
        }

        return $value;
    }

    private static function text(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /** Who to talk to at this supplier: the company contact Contacts links to its ledger. */
    public static function contact(string $supplierId): void
    {
        [$auth, $ctx] = self::enter();
        Http::data((new SupplierContactService($ctx, $auth))->contactFor((int) $supplierId));
    }

    public static function linkContact(string $supplierId): void
    {
        [$auth, $ctx] = self::enter();
        Http::data((new SupplierContactService($ctx, $auth))->link((int) $supplierId, Http::body()));
    }

    /** Removes the link in Contacts (Contacts decides whether this person may). */
    public static function unlinkContact(string $supplierId): void
    {
        [$auth, $ctx] = self::enter();
        Http::data((new SupplierContactService($ctx, $auth))->unlink((int) $supplierId));
    }

    public static function contactCandidates(): void
    {
        [$auth, $ctx] = self::enter();
        $result = (new SupplierContactService($ctx, $auth))->candidates(
            (string) (Http::param('q') ?? ''),
            Http::intParam('page', 1) ?? 1,
            Http::intParam('per_page', 20) ?? 20,
        );
        Http::json(200, $result);
    }
}
