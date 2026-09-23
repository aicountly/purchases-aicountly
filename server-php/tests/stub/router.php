<?php
/**
 * A stand-in for Books and Inventory, for the integration tests.
 *
 * It answers the handful of endpoints the Sales services call, in the envelope
 * shape the real contracts document. It also records every request it received
 * so a test can assert on the IDEMPOTENCY KEY — which is the one thing these
 * tests exist to prove.
 */
declare(strict_types=1);

$log = getenv('STUB_LOG') ?: sys_get_temp_dir() . '/stub-requests.jsonl';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$body = json_decode((string) file_get_contents('php://input'), true) ?: [];

$headers = [];
foreach ($_SERVER as $k => $v) {
    if (str_starts_with($k, 'HTTP_')) {
        $headers[strtolower(str_replace('_', '-', substr($k, 5)))] = $v;
    }
}

file_put_contents($log, json_encode([
    'method'  => $method,
    'path'    => $path,
    'headers' => $headers,
    'body'    => $body,
    'query'   => $_GET,
]) . "\n", FILE_APPEND);

header('Content-Type: application/json');

/**
 * Forced failures, controlled through a file rather than the environment.
 *
 * The stub runs in its own process, started before the tests. putenv() in the
 * test process cannot reach it, so the control has to be something both
 * processes can see: {"path": "reservations", "status": 422}.
 */
$controlFile = sys_get_temp_dir() . '/stub-control.json';
$control = is_file($controlFile) ? (json_decode((string) file_get_contents($controlFile), true) ?: []) : [];
if (!empty($control['path']) && str_contains($path, (string) $control['path'])) {
    http_response_code((int) ($control['status'] ?? 500));
    echo json_encode([
        'error'   => ['code' => 'stub_forced', 'message' => 'Forced failure for test'],
        'message' => 'Forced failure for test',
    ]);
    exit;
}

/**
 * Replay by idempotency key, exactly as Books and Inventory do. Two calls with
 * the same key must produce ONE document — that is what the tests check.
 */
$store = sys_get_temp_dir() . '/stub-idempotency.json';
$seen = is_file($store) ? (json_decode((string) file_get_contents($store), true) ?: []) : [];
$key = $headers['idempotency-key'] ?? '';

function remember(string $store, array $seen, string $key, array $payload): array {
    if ($key !== '') {
        $seen[$key] = $payload;
        file_put_contents($store, json_encode($seen));
    }
    return $payload;
}

if ($key !== '' && isset($seen[$key])) {
    echo json_encode(['data' => $seen[$key] + ['duplicate' => true]]);
    exit;
}

$n = count($seen) + 1;

// --- Portal (my.aicountly.com) --------------------------------------------
//
// Only reached when PORTAL_AUTH_BASE points here, which is the local preview
// harness. Deployed builds always talk to the real portal.
if (str_contains($path, '/seskey')) {
    // The role in the auth token rides through into the ses key, so a local
    // preview can sign in as a delegate and see what a delegate sees:
    //   localStorage.setItem('auth_token', 'preview-auth-token.role-0')
    // Nothing like this exists in the real portal — a ses key there is opaque.
    $incoming = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    $role = preg_match('/role-([a-z0-9]+)/i', $incoming, $m) === 1 ? '.role-' . strtolower($m[1]) : '';
    echo json_encode(['ses_key' => 'preview-ses-key' . $role, 'expires_in' => 900]);
    exit;
}

if (str_contains($path, '/validatesession')) {
    // EXACTLY what my.aicountly.com sends, and no more. AppCommonModel::validateSesKey
    // returns status, uuid_aictly, aic_auth_id and aic_ses_id — the portal is pure
    // authentication and holds no company, so there is no acs_type here to read.
    // This stub used to invent one, which is precisely how a product shipped whose
    // owner bypass could never fire: the fixture answered a question the real portal
    // is never asked.
    // The uuid rides in the key (`preview-ses-key.as-buyer`) so a preview or a
    // browser check can act as a second person — which the segregation-of-duties
    // rules need: an approval inbox that showed you your own orders would be
    // testing nothing. The real portal's keys are opaque and carry none of this.
    $incoming = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    $uuid = preg_match('/as-([a-z0-9_-]+)/i', $incoming, $m) === 1 ? strtolower($m[1]) : 'user-owner';
    echo json_encode([
        'status'      => 1,
        'uuid_aictly' => $uuid,
        'aic_auth_id' => 1,
        'aic_ses_id'  => 1,
    ]);
    exit;
}

// --- Manage ---------------------------------------------------------------
if (str_contains($path, '/companies') && !str_contains($path, '/companyinfo')) {
    // Manage's company list, as CompanyModel::listCompanies shapes it. Enough
    // rows to exercise the launcher's search, sort and default handling.
    $rows = [
        ['comp_id' => 88, 'comp_name' => 'Shivansh Enterprises', 'ownership' => 'owner', 'is_creator' => true],
        ['comp_id' => 91, 'comp_name' => 'Aicountly Interactive Services Pvt Ltd', 'ownership' => 'owner', 'is_creator' => true],
        ['comp_id' => 92, 'comp_name' => 'Deccan Steel Traders', 'ownership' => 'shared', 'is_creator' => false],
        ['comp_id' => 93, 'comp_name' => 'Metro Electricals', 'ownership' => 'shared', 'is_creator' => false],
        ['comp_id' => 94, 'comp_name' => 'Pioneer Packaging', 'ownership' => 'owner', 'is_creator' => true],
    ];
    echo json_encode(['success' => '1', 'data' => $rows, 'total' => count($rows)]);
    exit;
}

if (str_contains($path, '/companyinfo')) {
    // Manage's real answer shape — CompanyModel::companyInfo reports the caller's
    // role three ways for three generations of caller. `role` here is the stub's
    // own switch so a test can ask for a delegate or for a Manage that names no
    // role at all; Manage itself has no such parameter.
    // WHO is asking decides the role, which is the whole point: Manage answers per
    // user, and a stub that answered per company would let a test "prove" a
    // non-owner is refused while the real resolution never ran. The role rides in
    // the test's bearer token (`...role-1`, `role-0`, `role-silent`) because that
    // is the only thing about the caller the real ManageClient sends. Default is
    // owner, so the local preview signs in as one.
    $cmpId = (int) ($_GET['comp_id'] ?? 0);
    $bearer = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    $role = preg_match('/role-([a-z0-9]+)/i', $bearer, $m) === 1 ? strtolower($m[1]) : '1';

    $company = [
        'comp_id' => $cmpId,
        'cmp_id' => $cmpId,
        'comp_name' => 'Stub Trading Co',
        // Manage sends its years newest first; the launcher relies on that
        // ordering to preselect the current one.
        'fy_list' => [
            ['fy_id' => 6, 'fy_start' => '2026-04-01', 'fy_end' => '2027-03-31'],
            ['fy_id' => 5, 'fy_start' => '2025-04-01', 'fy_end' => '2026-03-31'],
        ],
        'branch_list' => [
            ['id' => 30, 'name' => 'Main Branch'],
            ['id' => 31, 'name' => 'Warehouse South'],
        ],
    ];
    if ($role === '1') {
        $company += ['is_creator' => true, 'ownership' => 'owner', 'access_type' => 1];
    } elseif ($role !== 'silent') {
        // A real delegated row: Manage reports whatever access_type it stored.
        $company += ['is_creator' => false, 'ownership' => 'shared', 'access_type' => (int) $role];
    }
    // 'silent' adds nothing: a Manage that names no role at all.
    echo json_encode(['success' => '1', 'data' => $company]);
    exit;
}

// --- Inventory ------------------------------------------------------------
if (str_contains($path, '/v1/valuation/unit-costs')) {
    $ids = array_filter(explode(',', (string) ($_GET['item_ids'] ?? '')));
    echo json_encode(['data' => array_map(static fn ($id) => ['item_id' => (int) $id, 'unit_cost' => 80.0], $ids)]);
    exit;
}
if (str_contains($path, '/v1/availability/check')) {
    echo json_encode(['data' => array_map(static fn ($l) => [
        'item_id' => $l['item_id'], 'available' => 500.0, 'shortfall' => 0.0, 'ok' => true,
    ], $body['lines'] ?? [])]);
    exit;
}
if (str_contains($path, '/v1/reservations') && $method === 'POST') {
    $payload = [
        'reservation_id'   => 9000 + $n,
        'reservation_uuid' => 'resv-' . $n,
        'lines' => array_map(static fn ($l) => [
            'source_line_ref' => $l['source_line_ref'],
            'reservation_id'  => 9000 + $n,
            'reservation_uuid' => 'resv-' . $n,
        ], $body['lines'] ?? []),
    ];
    echo json_encode(['data' => remember($store, $seen, $key, $payload)]);
    exit;
}
/**
 * Posted documents are remembered by their source so `by-source` can answer,
 * which is what the three-way match reads to learn what actually arrived.
 */
$documentStore = sys_get_temp_dir() . '/stub-documents.json';
$documents = is_file($documentStore) ? (json_decode((string) file_get_contents($documentStore), true) ?: []) : [];

if (str_contains($path, '/v1/inventory-documents/by-source')) {
    $sourceKey = ($_GET['source_app'] ?? '') . '|' . ($_GET['source_document_type'] ?? '') . '|' . ($_GET['source_document_id'] ?? '');
    echo json_encode(['data' => $documents[$sourceKey] ?? []]);
    exit;
}

if (str_contains($path, '/v1/inventory-documents/by-uuid/')) {
    $uuid = rawurldecode(basename($path));
    echo json_encode(['data' => [
        'document_uuid' => $uuid,
        'document_no'   => 'SO/' . substr($uuid, -4),
        'document_type' => 'PURCHASE_RETURN',
        'status'        => 'POSTED',
        'posted_at'     => gmdate('c'),
    ]]);
    exit;
}

if (str_contains($path, '/v1/inventory-documents/post')) {
    $payload = [
        'document_id'   => 7000 + $n,
        'document_uuid' => 'invdoc-' . $n,
        'document_no'   => 'SI/' . str_pad((string) $n, 4, '0', STR_PAD_LEFT),
        'status'        => 'POSTED',
        'lines' => array_map(static fn ($l) => [
            'source_line_ref' => $l['source_line_ref'] ?? null,
            'item_id'         => $l['item_id'] ?? null,
            'qty'             => $l['qty'] ?? 0,
            'valuation_rate'  => 80.0,
        ], $body['lines'] ?? []),
    ];

    // Only an inward document counts as a receipt for by-source purposes; a
    // return going out must not read back as more goods arriving.
    if (($body['document_type'] ?? '') === 'PURCHASE_RECEIPT') {
        $sourceKey = ($body['source_app'] ?? '') . '|' . ($body['source_document_type'] ?? '') . '|' . ($body['source_document_id'] ?? '');
        $documents[$sourceKey][] = $payload;
        file_put_contents($documentStore, json_encode($documents));
    }

    echo json_encode(['data' => remember($store, $seen, $key, $payload)]);
    exit;
}

if (str_contains($path, '/v1/items/bulk-lookup')) {
    $ids = $body['ids'] ?? [];
    echo json_encode(['data' => array_map(static fn ($id) => [
        'item_id'   => (int) $id,
        'item_name' => 'Stub Item ' . $id,
        'item_code' => 'SKU-' . $id,
        'uom'       => 'Nos',
        'group_name' => 'Stub Group',
    ], $ids)]);
    exit;
}
if (str_contains($path, '/v1/reports/replenishment')) {
    echo json_encode(['data' => [
        'as_of' => gmdate('Y-m-d'),
        'rows'  => [
            ['item_id' => 7001, 'item_name' => 'OPC Cement 50kg', 'uom' => 'Bags', 'available_qty' => 320.0, 'reorder_level' => 500.0, 'safety_stock' => 200.0, 'suggested_qty' => 1000.0, 'lead_days' => 6],
            ['item_id' => 7002, 'item_name' => 'TMT Steel 12mm',  'uom' => 'MT',   'available_qty' => 1.2,   'reorder_level' => 5.0,   'safety_stock' => 2.0,   'suggested_qty' => 5.0,    'lead_days' => 4],
            ['item_id' => 7003, 'item_name' => 'Wire 2.5 sqmm',   'uom' => 'Coils', 'available_qty' => 8.0,  'reorder_level' => 25.0,  'safety_stock' => 10.0,  'suggested_qty' => null,   'lead_days' => null],
        ],
    ]]);
    exit;
}
if (str_contains($path, '/v1/warehouses')) {
    echo json_encode(['data' => [
        ['warehouse_id' => 1, 'warehouse_name' => 'Main Store'],
        ['warehouse_id' => 2, 'warehouse_name' => 'Site Store'],
    ]]);
    exit;
}

// --- Books ----------------------------------------------------------------

/**
 * The party ledger LIST, which is what every supplier picker in Purchases
 * searches. Declared before the by-id route below, which matches on a trailing
 * slash and would otherwise never see this path anyway.
 *
 * Filtered by `q` the way Books filters it, so a picker that searches for
 * "Metro" gets Metro back and a picker that searches for nothing gets the lot.
 */
if (preg_match('#/masters/accounts/?$#', $path) === 1) {
    $all = [
        ['acc_id' => 601, 'acc_name' => 'Metro Electronics Pvt. Ltd.', 'gstin' => '27AABCM1234C1Z5'],
        ['acc_id' => 602, 'acc_name' => 'Shree Traders',              'gstin' => '27AABCS5678D1Z2'],
        ['acc_id' => 603, 'acc_name' => 'Global Tech Supplies',       'gstin' => '29AABCG9012E1Z8'],
        ['acc_id' => 604, 'acc_name' => 'R.K. Enterprises',           'gstin' => '24AABCR3456F1Z1'],
        ['acc_id' => 605, 'acc_name' => 'National Components',        'gstin' => '06AABCN7890G1Z4'],
    ];
    $term = trim((string) ($_GET['q'] ?? ''));
    $rows = $term === ''
        ? $all
        : array_values(array_filter($all, static fn (array $a) => stripos($a['acc_name'], $term) !== false));

    echo json_encode(['data' => $rows, 'meta' => ['total' => count($rows), 'limit' => 50, 'offset' => 0]]);
    exit;
}

if (str_contains($path, '/masters/accounts/')) {
    echo json_encode(['data' => ['acc_id' => 501, 'acc_name' => 'Northern Distributors', 'credit_limit' => 500000, 'credit_days' => 30]]);
    exit;
}
/**
 * bill-by-bill, exactly as Books behaves: acc_id is REQUIRED and the endpoint
 * answers 400 without one. Purchases once called it without an acc_id, so the
 * stub enforcing this is what stops that regression coming back.
 */
if (str_contains($path, '/reports/bill-by-bill')) {
    $accId = (int) ($_GET['acc_id'] ?? 0);
    if ($accId <= 0) {
        http_response_code(400);
        echo json_encode(['error' => ['code' => 'bad_request', 'message' => 'acc_id required'], 'message' => 'acc_id required']);
        exit;
    }
    echo json_encode(['data' => ['acc_id' => $accId, 'rows' => [
        ['bill_ref' => 'INV/0001', 'bill_date' => '2026-08-01', 'due_date' => '2026-08-31', 'pending_amount' => 120000.5, 'amount' => 200000.0],
        ['bill_ref' => 'INV/0002', 'bill_date' => '2026-08-10', 'due_date' => null,         'pending_amount' => 45000.25, 'amount' => 45000.25],
        ['bill_ref' => 'INV/0003', 'bill_date' => '2026-08-15', 'due_date' => '2099-01-01', 'pending_amount' => 10000.0,  'amount' => 10000.0],
        ['bill_ref' => 'INV/0004', 'bill_date' => '2026-08-20', 'due_date' => '2026-09-01', 'pending_amount' => 0.0,      'amount' => 5000.0],
    ]]]);
    exit;
}

// Books' purchase dashboard — the source for every posted purchase figure.
if (str_contains($path, '/dashboard/purchase')) {
    echo json_encode(['data' => [
        'context' => ['from' => $_GET['from'] ?? null, 'to' => $_GET['to'] ?? null, 'period_label' => 'Stub period'],
        'kpis' => [
            'total_purchases'   => 4860000.4567,
            'taxable_purchases' => 4200000.0,
            'input_gst'         => 660000.4567,
            'total_invoices'    => 27,
            'avg_invoice_value' => 180000.0,
            'payables'          => 2240000.75,
            'overdue_payables'  => 420000.25,
            'purchase_returns'  => 35000.0,
        ],
        'prev_period_kpis' => [
            'total_purchases'  => 4339285.0,
            'payables'         => 1898305.0,
            'overdue_payables' => 512195.0,
        ],
        'trend' => ['granularity' => 'day', 'points' => [
            ['date' => '2026-09-01', 'amount' => 310000.0],
            ['date' => '2026-09-02', 'amount' => 428000.5],
            ['date' => '2026-09-03', 'amount' => 265000.0],
        ]],
        'top_suppliers' => [
            ['acc_id' => 501, 'acc_name' => 'Northern Distributors', 'amount' => 1166400.0],
            ['acc_id' => 502, 'acc_name' => 'Metro Electricals',     'amount' => 874800.0],
        ],
        'payables_ageing' => [
            'not_due' => 1200000.0, 'b_0_30' => 320000.0, 'b_31_60' => 100000.0,
            'b_61_90' => 0.0, 'b_90_plus' => 620000.75, 'total' => 2240000.75,
        ],
        'register_summary' => [],
    ]]);
    exit;
}
if (preg_match('#/vouchers/(\d+)$#', $path, $m) === 1 && $method === 'GET') {
    echo json_encode(['data' => [
        'vch_txn_id' => (int) $m[1],
        'vch_uuid'   => 'vch-' . $m[1],
        'vch_no'     => 'DN/' . str_pad($m[1], 4, '0', STR_PAD_LEFT),
        'vch_type'   => 'DEBIT_NOTE',
        'status'     => 'POSTED',
    ]]);
    exit;
}

if (str_contains($path, '/vouchers/drafts') && str_contains($path, '/post')) {
    $payload = ['vch_txn_id' => 4000 + $n, 'vch_uuid' => 'vch-' . $n, 'vch_no' => 'INV/' . str_pad((string) $n, 4, '0', STR_PAD_LEFT), 'status' => 'POSTED'];
    echo json_encode(['data' => remember($store, $seen, $key, $payload)]);
    exit;
}
if (str_contains($path, '/vouchers/drafts') && $method === 'POST') {
    $payload = ['draft_id' => 3000 + $n, 'status' => 'DRAFT'];
    echo json_encode(['data' => remember($store, $seen, $key, $payload)]);
    exit;
}
if (str_contains($path, '/dashboard/sales')) {
    echo json_encode(['data' => ['total_sales' => 985000.0, 'invoice_count' => 42]]);
    exit;
}

http_response_code(404);
echo json_encode(['error' => ['code' => 'not_found', 'message' => 'Stub has no route for ' . $path], 'message' => 'no stub route']);
