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
// `after: true` does the work and THEN fails the response — the lost-response case: the
// other product recorded the document, the caller never heard. Handled at the end.
$failAfter = !empty($control['path']) && str_contains($path, (string) $control['path']) && !empty($control['after']);
if (!empty($control['path']) && str_contains($path, (string) $control['path']) && !$failAfter) {
    http_response_code((int) ($control['status'] ?? 500));
    // `message` lets a test answer with the receiver's own words (Books' refusal of a key it
    // cannot store, say), so what Purchase records is what the real refusal would leave.
    $forcedMessage = (string) ($control['message'] ?? 'Forced failure for test');
    echo json_encode([
        'error'   => ['code' => (string) ($control['code'] ?? 'stub_forced'), 'message' => $forcedMessage],
        'message' => $forcedMessage,
    ]);
    exit;
}
if ($failAfter) {
    ob_start();
    register_shutdown_function(static function () use ($control): void {
        ob_end_clean();
        http_response_code((int) ($control['status'] ?? 504));
        header('Content-Type: application/json');
        echo json_encode(['error' => ['code' => 'stub_lost_response', 'message' => 'Response lost after the work was done'], 'message' => 'Response lost']);
    });
}

/**
 * Behaviour switches for contract tests, in a file both processes can see:
 *   inventory_wrong_source  a post answers with a document filed under another source
 *   books_mangle            a posted voucher is recorded without the supplier's bill
 *                           reference and without crediting the supplier — the shape an
 *                           ill-composed voucher had — so the read-back check must see it
 */
$modes = is_file(sys_get_temp_dir() . '/stub-mode.json') ? (json_decode((string) file_get_contents(sys_get_temp_dir() . '/stub-mode.json'), true) ?: []) : [];

/**
 * The width each product keeps a caller's Idempotency-Key in, enforced the way each does it,
 * BEFORE anything else — as the real services check it first:
 *
 *   Books      books_idempotency_keys / books_voucher_drafts are VARCHAR(64): a longer key is
 *              400 idempotency_key_too_long (IdempotencyKeyService::tooLong / tooLongBody),
 *              answered before anything is written. This stub used to take any length, which is
 *              how every Purchase debit note (66+ characters) passed here and was refused by Books.
 *   Inventory  BaseController::idempotencyKey() silently cuts the key to 128.
 *
 * Books' routes reach the stub at the root (a localhost base has no /api); Inventory's are v1/.
 */
$stubKey = (string) ($headers['idempotency-key'] ?? '');
if ($stubKey !== '' && preg_match('#^/(api/)?(vouchers|masters|receipt-vouchers|payment-vouchers|gst)(/|$)#', $path) === 1 && strlen(trim($stubKey)) > 64) {
    http_response_code(400);
    echo json_encode([
        'error'   => ['code' => 'idempotency_key_too_long', 'message' => 'Idempotency-Key must be at most 64 characters.', 'details' => ['max_length' => 64]],
        'message' => 'Idempotency-Key must be at most 64 characters.',
    ]);
    exit;
}
if ($stubKey !== '' && str_starts_with($path, '/v1/')) {
    $headers['idempotency-key'] = substr(trim($stubKey), 0, 128);
}

/**
 * Replay by idempotency key, exactly as Books and Inventory do. Two calls with
 * the same key must produce ONE document — that is what the tests check.
 */
$store = sys_get_temp_dir() . '/stub-idempotency.json';
// One writer at a time, like the real services' per-key serialisation: concurrency tests
// fire several requests at once, and a stub that lost updates would "prove" nothing.
$lockHandle = fopen(sys_get_temp_dir() . '/stub.lock', 'c');
flock($lockHandle, LOCK_EX);
$seen = is_file($store) ? (json_decode((string) file_get_contents($store), true) ?: []) : [];
$key = $headers['idempotency-key'] ?? '';
// The request body the key was first used with. Inventory and Books both refuse a reused
// key whose body differs (409 idempotency_conflict) — the stub has to, or a retry that
// rebuilt its body differently would pass here and fail in production.
$bodyHash = hash('sha256', json_encode(stubNormalise($body)));

function stubNormalise(mixed $v): mixed {
    if (is_array($v)) {
        if (array_is_list($v)) {
            return array_map('stubNormalise', $v);
        }
        ksort($v);
        return array_map('stubNormalise', $v);
    }
    return $v;
}

function remember(string $store, array $seen, string $key, array $payload): array {
    if ($key !== '') {
        global $bodyHash;
        $seen[$key] = $payload + ['__hash' => $bodyHash];
        file_put_contents($store, json_encode($seen));
    }
    return $payload;
}

if ($key !== '' && isset($seen[$key])) {
    $stored = $seen[$key];
    if (isset($stored['__hash']) && $stored['__hash'] !== $bodyHash) {
        http_response_code(409);
        echo json_encode(['error' => ['code' => 'idempotency_conflict', 'message' => 'Idempotency-Key was already used with a different request body'], 'message' => 'Idempotency-Key reused with different payload']);
        exit;
    }
    unset($stored['__hash']);
    echo json_encode(['data' => $stored + ['duplicate' => true], 'duplicate' => true]);
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
    // `.ai-on` rides through the same way, for a preview against a Pulse that has
    // a model bound (see the AI Pulse section below).
    $ai = str_contains($incoming, '.ai-on') ? '.ai-on' : '';
    echo json_encode(['ses_key' => 'preview-ses-key' . $role . $ai, 'expires_in' => 900]);
    exit;
}

// --- AI Pulse (the AI gateway) --------------------------------------------
//
// Only reached when PULSE_API_ORIGIN points here, which tests/run.sh and the
// local preview both do. It answers in the shape pulse-aicountly's
// docs/AI_GATEWAY.md documents, and checks what Pulse checks before anything
// else: a product header, and a signed-in user's session.
//
// By default it is a Pulse with NO model bound in Console — the state these
// tests were written against when "no model configured" meant no key in this
// product's own .env. A bearer carrying `.ai-on` gets a Pulse whose economy tier
// is bound, with answers fixed so a test can recognise them. Nothing like this
// exists in the real Pulse.
if (str_starts_with($path, '/api/ai/v1/')) {
    $bearer = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    $product = strtolower(trim((string) ($headers['x-pulse-product'] ?? '')));
    $pulseError = static function (int $status, string $code, string $message, bool $retryable = false): void {
        http_response_code($status);
        echo json_encode(['status' => 0, 'code' => $code, 'message' => $message, 'retryable' => $retryable]);
        exit;
    };
    if (preg_match('/^[a-z][a-z0-9_]{1,31}$/', $product) !== 1) {
        $pulseError(400, 'product_required', 'Send X-Pulse-Product: <product>.');
    }
    if (!str_starts_with($bearer, 'Bearer ') || trim(substr($bearer, 7)) === '') {
        $pulseError(401, 'unauthenticated', 'Send the user\'s session key (Authorization: Bearer …).');
    }
    $bound = str_contains($bearer, '.ai-on');

    if ($path === '/api/ai/v1/status' && $method === 'GET') {
        echo json_encode(['status' => 1, 'data' => [
            'enabled'   => true,
            'available' => $bound,
            'reason'    => $bound ? null : 'module_not_bound',
            'tiers'     => ['economy' => $bound, 'strong' => false],
            'caller'    => ['product' => $product, 'auth' => 'user'],
        ]]);
        exit;
    }

    if ($path === '/api/ai/v1/generate' && $method === 'POST') {
        if (!$bound) {
            $pulseError(503, 'ai_unavailable', 'No AI model is bound for this product in Console.');
        }
        $feature = (string) ($body['feature'] ?? '');
        echo json_encode(['status' => 1, 'data' => [
            'id'          => 'stub-task-' . $n,
            'text'        => $feature === 'insight.ask_intent' ? 'delayed_orders' : 'Stub summary written through AI Pulse.',
            'json'        => null,
            'tool_calls'  => [],
            'stop_reason' => 'end',
            'model'       => 'stub-model',
            'provider'    => 'stub',
            'tier'        => (string) ($body['tier'] ?? 'strong'),
            'usage'       => ['input_tokens' => 10, 'output_tokens' => 5, 'cached_input_tokens' => 0],
            'cost_usd'    => null,
            'latency_ms'  => 1,
            'attempts'    => 1,
            'replayed'    => false,
            'cached'      => false,
        ]]);
        exit;
    }

    $pulseError(404, 'not_found', 'The stub Pulse has no route for ' . $path . '.');
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
// --- Contacts (company contacts, the in-flight company-scope release) ---------
//
// Only {base}/companies/{cmp}/contacts…: a user's personal contacts are never asked for. The
// books/ledger_account reference is Contacts' identity link, one contact per ledger per company.
// Mode contacts_undeployed answers these routes the way a Contacts without the release does.
// Contacts is deployed under /api/ with routes `companies/…`, so a deployed base answers
// /api/companies/…; a localhost base is served at the root. /api/api/companies/… — the
// doubled prefix — is no route at all, as in Contacts.
if (preg_match('#^(?:/api)?/companies/(\d+)/contacts#', $path, $cm) === 1) {
    // Payload shapes copied from the real Contacts handlers; the authoritative check is
    // tests/contacts_contract.php, which runs the same calls against a real Contacts.
    if (!empty($modes['contacts_undeployed'])) {
        // A wrong address: real Contacts answers an unknown route with a bare 404.
        http_response_code(404);
        echo '""';
        exit;
    }
    if (!empty($modes['contacts_scope_off'])) {
        http_response_code(503);
        echo json_encode(['status' => 0, 'message' => 'Company contacts are not enabled on this server yet.', 'error' => ['code' => 'company_scope_unavailable', 'message' => 'Company contacts are not enabled on this server yet.']]);
        exit;
    }
    $contactStore = sys_get_temp_dir() . '/stub-contacts.json';
    $cstate = is_file($contactStore) ? (json_decode((string) file_get_contents($contactStore), true) ?: []) : [];
    $cstate += ['contacts' => [
        '0b0e8c7e-1111-4a4a-9c9c-000000000001' => ['id' => '0b0e8c7e-1111-4a4a-9c9c-000000000001', 'displayName' => 'Anita Rao', 'organizationName' => 'Deccan Steel Traders', 'emails' => [['value' => 'orders@deccansteel.example']], 'phones' => [['value' => '+919800000001']], 'taxIds' => [['type' => 'GSTIN', 'value' => '27AAPFU0939F1ZV']], 'cmpId' => (int) $cm[1], 'visibility' => 'company', 'archivedAt' => null, 'state' => 'active', 'mergedIntoId' => null],
        '0b0e8c7e-2222-4a4a-9c9c-000000000002' => ['id' => '0b0e8c7e-2222-4a4a-9c9c-000000000002', 'displayName' => 'Ravi Kulkarni', 'organizationName' => 'Konkan Metals', 'emails' => [['value' => 'ravi@konkan.example']], 'phones' => [], 'taxIds' => [], 'cmpId' => (int) $cm[1], 'visibility' => 'company', 'archivedAt' => null, 'state' => 'active', 'mergedIntoId' => null],
    ], 'references' => [], 'keys' => []];
    $rest = substr($path, strlen($cm[0]));
    $save = static function () use ($contactStore, &$cstate): void {
        file_put_contents($contactStore, json_encode($cstate));
    };
    $refRow = static fn (string $key, array $r) => ['id' => $r['id'], 'contactId' => $r['contactId'], 'cmpId' => (int) $cm[1], 'product' => explode('/', $key)[0], 'refType' => explode('/', $key)[1], 'ref' => explode('/', $key)[2], 'caption' => ''];

    if ($rest === '/by-reference' && $method === 'GET') {
        $ref = ($_GET['product'] ?? '') . '/' . ($_GET['ref_type'] ?? '') . '/' . ($_GET['ref'] ?? '');
        $row = $cstate['references'][$ref] ?? null;
        echo json_encode(['status' => 1, 'data' => $row === null ? [] : [$cstate['contacts'][$row['contactId']]], 'references' => $row === null ? [] : [$refRow($ref, $row)], 'identity' => true]);
        exit;
    }
    if ($rest === '/lookup' && $method === 'GET') {
        $hits = array_values(array_filter($cstate['contacts'], static function ($c) {
            if (!empty($c['archivedAt'])) {
                return false;
            }
            if (isset($_GET['tax_id'])) {
                return in_array(strtoupper((string) $_GET['tax_id']), array_map(static fn ($t) => $t['value'], $c['taxIds']), true);
            }
            if (isset($_GET['email'])) {
                return in_array(strtolower((string) $_GET['email']), array_map(static fn ($e) => $e['value'], $c['emails']), true);
            }

            return false;
        }));
        echo json_encode(['status' => 1, 'data' => $hits]);
        exit;
    }
    if ($rest === '' && $method === 'GET') {
        $q = strtolower((string) ($_GET['q'] ?? ''));
        $hits = array_values(array_filter($cstate['contacts'], static fn ($c) => empty($c['archivedAt']) && ($q === '' || str_contains(strtolower($c['displayName'] . ' ' . $c['organizationName']), $q))));
        echo json_encode(['status' => 1, 'data' => $hits, 'meta' => ['page' => 1, 'per_page' => (int) ($_GET['per_page'] ?? 50), 'total' => count($hits), 'total_pages' => 1]]);
        exit;
    }
    if (preg_match('#^/([0-9a-f-]{36})/references/([0-9a-f-]{36})$#', $rest, $rm) === 1 && $method === 'DELETE') {
        foreach ($cstate['references'] as $key => $row) {
            if ($row['id'] === $rm[2] && $row['contactId'] === $rm[1]) {
                unset($cstate['references'][$key]);
                $save();
                http_response_code(204);
                exit;
            }
        }
        http_response_code(404);
        echo json_encode(['status' => 0, 'message' => 'Reference not found.', 'error' => ['code' => 'not_found', 'message' => 'Reference not found.']]);
        exit;
    }
    if (preg_match('#^/([0-9a-f-]{36})/references$#', $rest, $rm) === 1 && $method === 'POST') {
        // Contacts keeps a keyed answer and replays it for the same Idempotency-Key, whatever has
        // happened to the reference since — which is why a caller must not reuse a key across attempts.
        $ikey = $headers['idempotency-key'] ?? '';
        if ($ikey !== '' && isset($cstate['keys'][$ikey])) {
            header('Idempotent-Replayed: true');
            http_response_code(201);
            echo json_encode($cstate['keys'][$ikey]);
            exit;
        }
        $contact = $cstate['contacts'][$rm[1]] ?? null;
        if ($contact !== null && !empty($contact['archivedAt'])) {
            http_response_code(409);
            echo json_encode(['status' => 0, 'message' => 'This contact is archived; link the active contact instead.', 'error' => ['code' => 'contact_archived', 'message' => 'This contact is archived; link the active contact instead.']]);
            exit;
        }
        $ref = ($body['product'] ?? '') . '/' . ($body['ref_type'] ?? $body['refType'] ?? '') . '/' . ($body['ref'] ?? '');
        $row = $cstate['references'][$ref] ?? null;
        if ($row !== null && $row['contactId'] !== $rm[1]) {
            http_response_code(409);
            echo json_encode(['status' => 0, 'message' => 'That reference belongs to another contact.', 'error' => ['code' => 'reference_conflict', 'message' => 'That reference belongs to another contact.', 'details' => ['contactId' => $row['contactId']]], 'contactId' => $row['contactId']]);
            exit;
        }
        $created = $row === null;
        $row ??= ['id' => sprintf('5ef0%04x-0000-4000-8000-%012d', random_int(0, 65535), count($cstate['references']) + 1), 'contactId' => $rm[1]];
        $cstate['references'][$ref] = $row;
        $answer = ['status' => 1, 'created' => $created, 'data' => $refRow($ref, $row)];
        if ($ikey !== '') {
            $cstate['keys'][$ikey] = $answer;
        }
        $save();
        http_response_code($created ? 201 : 200);
        echo json_encode($answer);
        exit;
    }
    if (preg_match('#^/([0-9a-f-]{36})/resolve$#', $rest, $rm) === 1 && $method === 'GET') {
        $contact = $cstate['contacts'][$rm[1]] ?? null;
        if ($contact === null) {
            http_response_code(404);
            echo json_encode(['status' => 0, 'message' => 'Contact not found.', 'error' => ['code' => 'not_found', 'message' => 'Contact not found.']]);
            exit;
        }
        $data = !empty($contact['mergedIntoId'])
            ? ['id' => $rm[1], 'state' => 'merged', 'survivorId' => $contact['mergedIntoId'], 'survivorState' => 'active']
            : ['id' => $rm[1], 'state' => empty($contact['archivedAt']) ? 'active' : 'archived', 'survivorId' => $rm[1], 'contact' => $contact];
        echo json_encode(['status' => 1, 'data' => $data]);
        exit;
    }
    if (preg_match('#^/([0-9a-f-]{36})$#', $rest, $rm) === 1 && $method === 'GET') {
        $contact = $cstate['contacts'][$rm[1]] ?? null;
        if ($contact === null) {
            http_response_code(404);
            echo json_encode(['status' => 0, 'message' => 'Contact not found', 'error' => ['code' => 'not_found', 'message' => 'Contact not found']]);
            exit;
        }
        echo json_encode(['status' => 1, 'data' => $contact]);
        exit;
    }
    http_response_code(404);
    echo '""';
    exit;
}
if (preg_match('#^(?:/api)?/contacts#', $path) === 1) {
    // A personal-contacts request from a product is a bug: those are the user's own.
    http_response_code(418);
    echo json_encode(['message' => 'personal contacts requested by a product']);
    exit;
}

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
if (str_contains($path, '/v1/capabilities') && $method === 'GET') {
    $caps = is_file(sys_get_temp_dir() . '/stub-inventory-caps.json')
        ? json_decode((string) file_get_contents(sys_get_temp_dir() . '/stub-inventory-caps.json'), true)
        : ['validates_stock_effect' => true, 'stock_effects' => [
            'PURCHASE_RECEIPT' => ['on_invoice', 'defer_inward', 'from_challan', 'from_physical_challan'],
            'PURCHASE_RETURN'  => ['on_invoice', 'from_challan', 'from_physical_challan'],
            'INWARD_CHALLAN'   => ['challan_only', 'physical', 'settle_deferred'],
            'DELIVERY_CHALLAN' => ['challan_only', 'physical'],
        ]];
    if ($caps === null) {
        http_response_code(404);
        echo json_encode(['error' => ['code' => 'not_found', 'message' => 'no route'], 'message' => 'no route']);
        exit;
    }
    echo json_encode(['data' => $caps]);
    exit;
}
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
/*
 * Batches and serials (Inventory BatchesController / SerialsController), with ServiceCallerPolicy as
 * it stands: a product's key reads masters but writes none (purchases: inward/delivery challans
 * only), so registering a serial number or a batch needs the person's own session. Serials are
 * registered 'expected'; an inward document that posts them makes them in_stock (SerialGuard).
 */
$serialStore = sys_get_temp_dir() . '/stub-serials.json';
$serialState = is_file($serialStore) ? (json_decode((string) file_get_contents($serialStore), true) ?: []) : [];
$serialState += ['serials' => [], 'batches' => []];
$isServiceCall = trim((string) ($headers['x-service-key'] ?? '')) !== '';

function stubTracked(array $modes, int $itemId): array {
    return (array) ($modes['tracked_items'][(string) $itemId] ?? []);
}

if (preg_match('#/v1/(batches|serials/bulk)$#', $path, $mm) === 1 && $method === 'POST') {
    // Inventory 2880977: the purchases key may register (create) serials and batches; an Inventory
    // before it refused the key for any master write (stub mode inventory_pre_register_policy).
    if ($isServiceCall && !empty($modes['inventory_pre_register_policy'])) {
        http_response_code(403);
        $perm = $mm[1] === 'batches' ? 'masters.batches.write' : 'masters.serials.write';
        echo json_encode(['error' => ['code' => 'forbidden', 'message' => 'Service caller purchases may not ' . $perm], 'message' => 'forbidden']);
        exit;
    }
    $itemId = (int) ($body['item_id'] ?? 0);
    if ($mm[1] === 'batches') {
        $no = substr(trim((string) ($body['batch_no'] ?? '')), 0, 64);
        if ($no === '') {
            http_response_code(422);
            echo json_encode(['error' => ['code' => 'validation_failed', 'message' => 'batch_no is required'], 'message' => 'batch_no is required']);
            exit;
        }
        foreach ($serialState['batches'] as $batch) {
            if ((int) $batch['item_id'] === $itemId && $batch['batch_no'] === $no) {
                http_response_code(409);
                echo json_encode(['error' => ['code' => 'conflict', 'message' => 'Batch "' . $no . '" already exists for this item'], 'message' => 'conflict']);
                exit;
            }
        }
        $id = 61000 + count($serialState['batches']) + 1;
        $serialState['batches'][(string) $id] = ['batch_id' => $id, 'item_id' => $itemId, 'batch_no' => $no, 'status' => 'active'];
        file_put_contents($serialStore, json_encode($serialState));
        http_response_code(201);
        echo json_encode(['data' => $serialState['batches'][(string) $id]]);
        exit;
    }
    if ((int) (stubTracked($modes, $itemId)['serial'] ?? 0) !== 1) {
        http_response_code(422);
        echo json_encode(['error' => ['code' => 'validation_failed', 'message' => 'Item "Stub Item ' . $itemId . '" does not track serial numbers (track_serial)'], 'message' => 'not serial-tracked']);
        exit;
    }
    $created = [];
    $skipped = [];
    $wanted = [];
    foreach ((array) ($body['serial_nos'] ?? []) as $raw) {
        $no = is_array($raw) ? trim((string) ($raw['serial_no'] ?? '')) : trim((string) $raw);
        if ($no === '') {
            $skipped[] = ['serial_no' => $no, 'reason' => 'empty'];
            continue;
        }
        if (isset($wanted[$no])) {
            $skipped[] = ['serial_no' => $no, 'reason' => 'duplicate_in_request'];
            continue;
        }
        $wanted[$no] = true;
        $existing = null;
        foreach ($serialState['serials'] as $serial) {
            if ((int) $serial['item_id'] === $itemId && $serial['serial_no'] === $no) {
                $existing = $serial;
            }
        }
        if ($existing !== null) {
            $skipped[] = ['serial_no' => $no, 'reason' => 'already_registered', 'status' => $existing['status']];
            continue;
        }
        $id = 51000 + count($serialState['serials']) + 1;
        $serialState['serials'][(string) $id] = ['serial_id' => $id, 'item_id' => $itemId, 'serial_no' => $no, 'status' => 'expected',
            'warehouse_id' => isset($body['warehouse_id']) ? (int) $body['warehouse_id'] : null, 'batch_id' => isset($body['batch_id']) ? (int) $body['batch_id'] : null];
        $created[] = ['serial_id' => $id, 'serial_no' => $no];
    }
    file_put_contents($serialStore, json_encode($serialState));
    http_response_code(201);
    echo json_encode(['data' => ['item_id' => $itemId, 'created' => $created, 'skipped' => $skipped, 'created_count' => count($created), 'skipped_count' => count($skipped)]]);
    exit;
}
if (preg_match('#/v1/(batches|serials)$#', $path, $mm) === 1 && $method === 'GET') {
    $itemId = (int) ($_GET['item_id'] ?? 0);
    $q = mb_strtolower(trim((string) ($_GET['q'] ?? '')));
    $rows = [];
    foreach ($serialState[$mm[1]] as $row) {
        $no = mb_strtolower((string) ($mm[1] === 'batches' ? $row['batch_no'] : $row['serial_no']));
        if (($itemId === 0 || (int) $row['item_id'] === $itemId) && ($q === '' || str_contains($no, $q))) {
            $rows[] = $row;
        }
    }
    echo json_encode(['data' => $rows, 'meta' => ['total' => count($rows)]]);
    exit;
}

/**
 * DocumentService::serialIds + SerialGuard (Inventory 3f66a41, C6), for an inward line: serials are
 * ids (an id or {serial_id}) — a serial NUMBER is 422 — each this item's, named once, none already
 * in stock; a serial-tracked item names one per base unit, or none (policy validate; strict
 * refuses). Answers the 422 Inventory would, or the ids to bring into stock.
 *
 * @return list<int>
 */
function stubSerialGuard(array $modes, array $serialState, array $lines): array {
    $all = [];
    foreach ($lines as $idx => $line) {
        $ids = [];
        foreach ((array) ($line['serials'] ?? []) as $s) {
            $raw = is_array($s) ? ($s['serial_id'] ?? null) : $s;
            if (!(is_int($raw) || (is_string($raw) && ctype_digit($raw)))) {
                stubValidation('Line ' . ($idx + 1) . ': serials must be serial ids (an id or {serial_id}); register serial numbers first and send their ids');
            }
            $ids[] = (int) $raw;
        }
        $itemId = (int) ($line['item_id'] ?? 0);
        $tracked = stubTracked($modes, $itemId);
        if ($ids === []) {
            if ((int) ($tracked['serial'] ?? 0) === 1 && ($modes['serial_policy'] ?? 'validate') === 'strict') {
                stubValidation('Line #' . ($idx + 1) . ': item #' . $itemId . ' is serial-tracked, so the line must name its serial numbers (one per unit).', 'serials_required');
            }
            continue;
        }
        if (count($ids) !== count(array_unique($ids))) {
            stubValidation('Line #' . ($idx + 1) . ' names the same serial more than once');
        }
        foreach ($ids as $id) {
            $row = $serialState['serials'][(string) $id] ?? null;
            if ($row === null || (int) $row['item_id'] !== $itemId) {
                stubValidation('Line #' . ($idx + 1) . ': serial #' . $id . ' is not a serial of item #' . $itemId . ' in this company');
            }
            if (in_array($row['status'], ['in_stock', 'reserved'], true)) {
                stubValidation('Line #' . ($idx + 1) . ': serial #' . $id . ' is already in stock, so it cannot be received again');
            }
        }
        if ((int) ($tracked['serial'] ?? 0) === 1) {
            $factor = 1.0;
            foreach ((array) ($tracked['units'] ?? []) as $u) {
                if ((int) ($u['unit_id'] ?? 0) === (int) ($line['unit_id'] ?? 0)) {
                    $factor = (float) $u['conversion_factor'];
                }
            }
            $base = (float) ($line['qty'] ?? 0) * $factor;
            if (abs($base - count($ids)) > 0.0001) {
                stubValidation(sprintf('Line #%d: item #%d is serial-tracked; %s unit(s) need %s serial(s), %d named', $idx + 1, $itemId, $base, $base, count($ids)));
            }
        }
        $all = array_merge($all, $ids);
    }
    return $all;
}

function stubValidation(string $message, string $code = 'validation_failed'): void {
    http_response_code(422);
    echo json_encode(['error' => ['code' => $code, 'message' => $message], 'message' => $message]);
    exit;
}

/** @param list<int> $ids */
function stubSetSerials(string $serialStore, array $serialState, array $ids, string $status, bool $write = true): array {
    foreach ($ids as $id) {
        if (isset($serialState['serials'][(string) $id])) {
            $serialState['serials'][(string) $id]['status'] = $status;
        }
    }
    if ($write) {
        file_put_contents($serialStore, json_encode($serialState));
    }
    return $serialState;
}

/**
 * Posted documents, as Inventory keeps them: by id, and indexed by source.
 *
 * Inventory keeps ONE live document per (source_app, source_document_type,
 * source_document_id) — DocumentsController::createInternal's duplicate guard, backed by
 * uq_inv_documents_source. A second post under the same source identity answers 200 with
 * the FIRST document and duplicate:true, whatever its idempotency key. The stub used to
 * accept a second document per source, which is exactly how receipts keyed by their
 * order passed here and failed against the real service.
 */
$documentStore = sys_get_temp_dir() . '/stub-documents.json';
$documents = is_file($documentStore) ? (json_decode((string) file_get_contents($documentStore), true) ?: []) : [];
$documents += ['by_id' => [], 'by_source' => []];

function stubSourceKey(array $b): ?string {
    if (empty($b['source_document_type']) || empty($b['source_document_id'])) {
        return null;
    }
    return ($b['source_app'] ?? '') . '|' . $b['source_document_type'] . '|' . (int) $b['source_document_id'];
}

if (str_contains($path, '/v1/inventory-documents/by-source')) {
    $sourceKey = ($_GET['source_app'] ?? '') . '|' . ($_GET['source_document_type'] ?? '') . '|' . (int) ($_GET['source_document_id'] ?? 0);
    // Real findBySource: the newest live document, a single row.
    $id = $documents['by_source'][$sourceKey] ?? null;
    if ($id === null) {
        http_response_code(404);
        echo json_encode(['error' => ['code' => 'not_found', 'message' => 'No document for that source'], 'message' => 'not found']);
        exit;
    }
    echo json_encode(['data' => $documents['by_id'][(string) $id]]);
    exit;
}
/**
 * What a Books voucher has settled of an Inventory receipt: the challan_settlements of every live
 * voucher the stub holds (a bill posted from Purchase, or one a test files as entered in Smart
 * Books). Inventory refuses to reverse or revise a receipt any of which is settled
 * (DocumentPostingService::reverse → 409 invalid_state "...already settled by later documents...").
 */
function stubSettledQty(int $documentId): float {
    $file = sys_get_temp_dir() . '/stub-vouchers.json';
    $held = is_file($file) ? (json_decode((string) file_get_contents($file), true) ?: []) : [];
    $qty = 0.0;
    foreach ($held as $voucher) {
        if (!empty($voucher['cancelled'])) {
            continue;
        }
        foreach ((array) ($voucher['challan_settlements'] ?? []) as $settlement) {
            if ((int) ($settlement['source_document_id'] ?? 0) === $documentId) {
                $qty += (float) ($settlement['qty'] ?? 0);
            }
        }
    }
    return $qty;
}

function stubRefuseSettled(int $documentId): void {
    if (stubSettledQty($documentId) > 0.00005) {
        http_response_code(409);
        $message = 'Document has pending quantities that were already settled by later documents; reverse those first';
        echo json_encode(['error' => ['code' => 'invalid_state', 'message' => $message, 'details' => ['document_id' => $documentId]], 'message' => $message]);
        exit;
    }
}

if (preg_match('#/v1/inventory-documents/(\d+)/reverse$#', $path, $rm) === 1 && $method === 'POST') {
    $doc = $documents['by_id'][$rm[1]] ?? null;
    if ($doc === null) {
        http_response_code(404);
        echo json_encode(['error' => ['code' => 'not_found', 'message' => 'No such document'], 'message' => 'not found']);
        exit;
    }
    // DocumentPostingService::reverse: a reversed document answers as already done.
    if (($doc['status'] ?? '') === 'REVERSED') {
        $doc['duplicate'] = true;
        echo json_encode(['data' => remember($store, $seen, $key, $doc), 'duplicate' => true]);
        exit;
    }
    stubRefuseSettled((int) $rm[1]);
    $serialState = stubSetSerials($serialStore, $serialState, array_merge([], ...array_map(static fn ($l) => (array) ($l['serials'] ?? []), (array) ($doc['lines'] ?? []))), 'expected');
    $doc['status'] = 'REVERSED';
    $documents['by_id'][$rm[1]] = $doc;
    $sk = stubSourceKey($doc);
    if ($sk !== null && ($documents['by_source'][$sk] ?? null) === (int) $rm[1]) {
        unset($documents['by_source'][$sk]);
    }
    file_put_contents($documentStore, json_encode($documents));
    echo json_encode(['data' => remember($store, $seen, $key, $doc)]);
    exit;
}
/*
 * DocumentsController::revise / DocumentPostingService::revise (Inventory 702c177, C10): the old
 * document reversed (its reverse leg superseded) and the payload posted as its replacement, in one
 * transaction — 201 with the replacement, carrying replaced_document_id. Nothing but the type is
 * copied from the old document. Already reversed: the replacement it was given, 200 duplicate, or
 * 409 invalid_state when it was reversed outright. Settled by a bill: 409 invalid_state.
 */
if (preg_match('#/v1/inventory-documents/(\d+)/revise$#', $path, $vm) === 1 && $method === 'POST') {
    $old = $documents['by_id'][$vm[1]] ?? null;
    if ($old === null) {
        http_response_code(404);
        echo json_encode(['error' => ['code' => 'not_found', 'message' => 'No such document'], 'message' => 'not found']);
        exit;
    }
    if (($old['status'] ?? '') === 'REVERSED') {
        foreach ($documents['by_id'] as $candidate) {
            if ((int) ($candidate['replaced_document_id'] ?? 0) === (int) $vm[1]) {
                $candidate['duplicate'] = true;
                echo json_encode(['data' => remember($store, $seen, $key, $candidate), 'duplicate' => true]);
                exit;
            }
        }
        http_response_code(409);
        echo json_encode(['error' => ['code' => 'invalid_state', 'message' => 'This document has been reversed, so there is nothing left to revise. Create a new document instead.'], 'message' => 'reversed']);
        exit;
    }
    stubRefuseSettled((int) $vm[1]);
    // The reverse leg first — its serials leave stock — then the replacement is posted.
    $released = stubSetSerials($serialStore, $serialState, array_merge([], ...array_map(static fn ($l) => (array) ($l['serials'] ?? []), (array) ($old['lines'] ?? []))), 'expected', false);
    $inSerials = stubSerialGuard($modes, $released, (array) ($body['lines'] ?? []));
    $serialState = stubSetSerials($serialStore, $released, $inSerials, 'in_stock');
    $old['status'] = 'REVERSED';
    $documents['by_id'][$vm[1]] = $old;
    $type = strtoupper((string) ($body['document_type'] ?? $old['document_type'] ?? ''));
    $id = 7000 + $n;
    $replacement = [
        'document_id'          => $id,
        'document_uuid'        => 'invdoc-' . $n,
        'document_no'          => ($type === 'INWARD_CHALLAN' ? 'GRN/' : 'DOC/') . str_pad((string) $n, 4, '0', STR_PAD_LEFT),
        'document_type'        => $type,
        'stock_effect'         => $body['stock_effect'] ?? null,
        'document_date'        => $body['document_date'] ?? null,
        'status'               => 'POSTED',
        'party_ref'            => $body['party_ref'] ?? null,
        'source_app'           => $body['source_app'] ?? null,
        'source_document_type' => $body['source_document_type'] ?? null,
        'source_document_id'   => isset($body['source_document_id']) ? (int) $body['source_document_id'] : null,
        'source_document_uuid' => $body['source_document_uuid'] ?? null,
        'source_document_no'   => $body['source_document_no'] ?? null,
        'metadata'             => array_merge((array) ($body['metadata'] ?? []), ['revises_document_id' => (int) $vm[1], 'revision_reason' => (string) ($body['reason'] ?? '')]),
        'lines' => array_map(static fn ($l) => [
            'source_line_ref' => isset($l['source_line_ref']) ? (int) $l['source_line_ref'] : null,
            'item_id'         => $l['item_id'] ?? null,
            'qty'             => $l['qty'] ?? 0,
            'direction'       => 'in',
            'warehouse_id'    => $l['warehouse_id'] ?? null,
            'unit_id'         => $l['unit_id'] ?? null,
            'batch_id'        => $l['batch_id'] ?? null,
            'serials'         => array_map(static fn ($x) => (int) (is_array($x) ? ($x['serial_id'] ?? 0) : $x), (array) ($l['serials'] ?? [])),
            'valuation_rate'  => isset($l['rate']) ? (float) $l['rate'] : 80.0,
        ], $body['lines'] ?? []),
        'replaced_document_id' => (int) $vm[1],
        'replaced_status'      => 'REVERSED',
        'duplicate'            => false,
    ];
    $documents['by_id'][(string) $id] = $replacement;
    $sk = stubSourceKey($replacement);
    if ($sk !== null) {
        $documents['by_source'][$sk] = $id;
    }
    file_put_contents($documentStore, json_encode($documents));
    http_response_code(201);
    echo json_encode(['data' => remember($store, $seen, $key, $replacement), 'duplicate' => false]);
    exit;
}
if (preg_match('#/v1/inventory-documents/(\d+)$#', $path, $gm) === 1 && $method === 'GET') {
    $doc = $documents['by_id'][$gm[1]] ?? null;
    if ($doc === null) {
        http_response_code(404);
        echo json_encode(['error' => ['code' => 'not_found', 'message' => 'No such document'], 'message' => 'not found']);
        exit;
    }
    echo json_encode(['data' => $doc]);
    exit;
}

/**
 * The document types Inventory actually has — Inventory-aicountly
 * server-php/app/Config/DocumentTypeRegistry.php. An unknown type is the 422 the
 * real service answers; this stub used to accept anything. Keep the list in step
 * with the registry.
 */
const INVENTORY_DOCUMENT_TYPES = [
    'OPENING_STOCK', 'STOCK_TRANSFER', 'STOCK_JOURNAL', 'PHYSICAL_ADJUSTMENT', 'WRITE_OFF', 'WRITE_IN',
    'CONSUMPTION', 'MATERIAL_ISSUE', 'MATERIAL_RECEIPT', 'PRODUCTION', 'ASSEMBLY', 'DISASSEMBLY',
    'JOB_WORK_OUT', 'JOB_WORK_IN', 'BATCH_ADJUSTMENT', 'SERIAL_ADJUSTMENT', 'REVALUATION', 'LANDED_COST',
    'RESERVATION', 'RESERVATION_RELEASE', 'DELIVERY_CHALLAN', 'INWARD_CHALLAN', 'PACKING',
    'SALES_ISSUE', 'PURCHASE_RECEIPT', 'SALES_RETURN', 'PURCHASE_RETURN', 'JOURNAL_ADJUSTMENT',
];

if (str_contains($path, '/v1/inventory-documents/post')) {
    if (!in_array(strtoupper((string) ($body['document_type'] ?? '')), INVENTORY_DOCUMENT_TYPES, true)) {
        http_response_code(422);
        echo json_encode([
            'error'   => ['code' => 'validation_failed', 'message' => 'Unknown or missing document_type', 'details' => ['allowed' => INVENTORY_DOCUMENT_TYPES]],
            'message' => 'Unknown or missing document_type',
        ]);
        exit;
    }
    // The duplicate-posting guard on the source document, AFTER the key replay above —
    // the real order: a new key under an existing source identity still gets the first
    // document back.
    $sourceKey = stubSourceKey($body);
    if ($sourceKey !== null && isset($documents['by_source'][$sourceKey])) {
        $existing = $documents['by_id'][(string) $documents['by_source'][$sourceKey]];
        $existing['duplicate'] = true;
        remember($store, $seen, $key, $existing);
        echo json_encode(['data' => $existing, 'duplicate' => true]);
        exit;
    }

    $type = strtoupper((string) $body['document_type']);
    $direction = in_array($type, ['INWARD_CHALLAN', 'PURCHASE_RECEIPT', 'SALES_RETURN', 'OPENING_STOCK', 'WRITE_IN', 'MATERIAL_RECEIPT'], true) ? 'in' : 'out';
    $inSerials = $direction === 'in' ? stubSerialGuard($modes, $serialState, (array) ($body['lines'] ?? [])) : [];
    $id = 7000 + $n;
    $payload = [
        'document_id'          => $id,
        'document_uuid'        => 'invdoc-' . $n,
        'document_no'          => ($type === 'INWARD_CHALLAN' ? 'GRN/' : 'SI/') . str_pad((string) $n, 4, '0', STR_PAD_LEFT),
        'document_type'        => $type,
        'stock_effect'         => $body['stock_effect'] ?? null,
        'status'               => 'POSTED',
        'source_app'           => $body['source_app'] ?? null,
        'source_document_type' => $body['source_document_type'] ?? null,
        'source_document_id'   => isset($body['source_document_id']) ? (int) $body['source_document_id'] : null,
        'source_document_uuid' => $body['source_document_uuid'] ?? null,
        'source_document_no'   => $body['source_document_no'] ?? null,
        'metadata'             => $body['metadata'] ?? null,
        'lines' => array_map(static fn ($l) => [
            'source_line_ref' => isset($l['source_line_ref']) ? (int) $l['source_line_ref'] : null,
            'item_id'         => $l['item_id'] ?? null,
            'qty'             => $l['qty'] ?? 0,
            'direction'       => $direction,
            'warehouse_id'    => $l['warehouse_id'] ?? null,
            'batch_id'        => $l['batch_id'] ?? null,
            'unit_id'         => $l['unit_id'] ?? null,
            'serials'         => array_map(static fn ($x) => (int) (is_array($x) ? ($x['serial_id'] ?? 0) : $x), (array) ($l['serials'] ?? [])),
            'valuation_rate'  => isset($l['rate']) ? (float) $l['rate'] : 80.0,
        ], $body['lines'] ?? []),
        'duplicate' => false,
    ];
    if ($inSerials !== []) {
        $serialState = stubSetSerials($serialStore, $serialState, $inSerials, 'in_stock');
    }

    $documents['by_id'][(string) $id] = $payload;
    if ($sourceKey !== null) {
        $documents['by_source'][$sourceKey] = $id;
    }
    file_put_contents($documentStore, json_encode($documents));

    if (!empty($modes['inventory_wrong_source'])) {
        $payload['source_document_id'] = (int) ($payload['source_document_id'] ?? 0) + 100000;
    }
    echo json_encode(['data' => remember($store, $seen, $key, $payload), 'duplicate' => false]);
    exit;
}

/*
 * Inventory's ItemsController::bulkLookup: it reads `item_ids` (and `item_skus`) — anything else,
 * `ids` included, is ignored and answered with an empty list — and each row is an inv_items row
 * with its unit's symbol (LOOKUP_COLUMNS) plus the item's units. No item_code, uom or group name:
 * the group is an id, named by v1/item-groups.
 */
if (str_contains($path, '/v1/items/bulk-lookup')) {
    $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($body['item_ids'] ?? [])), static fn ($i) => $i > 0)));
    echo json_encode(['data' => array_map(static fn ($id) => [
        'item_id'              => $id,
        'item_uuid'            => 'item-' . $id,
        'item_name'            => 'Stub Item ' . $id,
        'item_alias'           => null,
        'item_sku'             => 'SKU-' . $id,
        'hsn_sac'              => '7214',
        'unit_id'              => 1,
        'unit_symbol'          => 'Nos',
        'books_tax_cat_id'     => 18,
        'default_warehouse_id' => 1,
        'item_grp_id'          => 5,
        'is_active'            => 1,
        // Tracking flags as inv_items holds them (LOOKUP_COLUMNS); stub mode tracked_items
        // {"<item_id>": {"serial": 1, "batch": 0, "units": [{"unit_id", "conversion_factor"}]}}.
        'track_serial'         => (int) ($modes['tracked_items'][(string) $id]['serial'] ?? 0),
        'track_batch'          => (int) ($modes['tracked_items'][(string) $id]['batch'] ?? 0),
        'units'                => $modes['tracked_items'][(string) $id]['units'] ?? [],
    ], $ids)]);
    exit;
}
if (str_contains($path, '/v1/item-groups') && $method === 'GET') {
    echo json_encode(['data' => [['item_grp_id' => 5, 'grp_name' => 'Stub Group', 'parent_grp_id' => null, 'is_primary' => 1, 'item_count' => 2]], 'meta' => ['total' => 1]]);
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
 * The account list, filtered the way Books filters it: anchor_code is a comma list of group
 * anchors, q a substring of the name. Creditors carry SUNDRY_CREDITORS.
 */
if (preg_match('#/masters/accounts$#', $path) === 1 && $method === 'GET') {
    $accounts = [
        ['acc_id' => 501, 'acc_name' => 'Northern Distributors', 'grp_name' => 'Sundry Creditors', 'grp_anchor_code' => 'SUNDRY_CREDITORS'],
        ['acc_id' => 502, 'acc_name' => 'Eastern Metals', 'grp_name' => 'Sundry Creditors', 'grp_anchor_code' => 'SUNDRY_CREDITORS'],
        ['acc_id' => 7101, 'acc_name' => 'Purchase - Raw Materials', 'grp_name' => 'Purchase Accounts', 'grp_anchor_code' => 'PURCHASE_ACCOUNTS'],
        ['acc_id' => 7201, 'acc_name' => 'Freight Inward', 'grp_name' => 'Direct Expenses', 'grp_anchor_code' => 'DIRECT_EXPENSES'],
        ['acc_id' => 7301, 'acc_name' => 'Repairs and Maintenance', 'grp_name' => 'Indirect Expenses', 'grp_anchor_code' => 'INDIRECT_EXPENSES'],
        ['acc_id' => 7401, 'acc_name' => 'Domestic Sales', 'grp_name' => 'Sales Accounts', 'grp_anchor_code' => 'SALES_ACCOUNTS'],
    ];
    $anchors = array_filter(array_map('trim', explode(',', (string) ($_GET['anchor_code'] ?? ''))));
    if (($_GET['nature'] ?? '') === 'sundry_creditors') {
        $anchors[] = 'SUNDRY_CREDITORS';
    }
    $term = strtolower(trim((string) ($_GET['q'] ?? '')));
    $rows = array_values(array_filter($accounts, static fn (array $a): bool =>
        ($anchors === [] || in_array($a['grp_anchor_code'], $anchors, true))
        && ($term === '' || str_contains(strtolower($a['acc_name']), $term))));
    echo json_encode(['data' => $rows, 'meta' => ['total' => count($rows)]]);
    exit;
}
/*
 * One ledger, as Books' AccountsController::show answers it: the account row (a.*), with the GST
 * identity a purchase's place of supply is read from — gstin, gst_reg_type, state_code,
 * country_code, is_non_resident. Supplier 601 is registered in Maharashtra (27); a test changes a
 * ledger by writing stub-accounts.json ({acc_id: {field: value}}).
 */
if (preg_match('#/masters/accounts/(\d+)$#', $path, $am) === 1 && $method === 'GET') {
    $overrides = is_file(sys_get_temp_dir() . '/stub-accounts.json') ? (json_decode((string) file_get_contents(sys_get_temp_dir() . '/stub-accounts.json'), true) ?: []) : [];
    $accId = (int) $am[1];
    $row = ['acc_id' => $accId, 'acc_name' => 'Northern Distributors', 'credit_limit' => 500000, 'credit_days' => 30,
        'gstin' => '27AAPFU0939F1ZV', 'gst_reg_type' => 'regular', 'state_code' => '27', 'country_code' => 'IN', 'is_non_resident' => 0];
    if (isset($overrides[(string) $accId]) && $overrides[(string) $accId] === null) {
        http_response_code(404);
        echo json_encode(['status' => 404, 'error' => 404, 'messages' => ['error' => 'Account not found']]);
        exit;
    }
    echo json_encode(['data' => array_merge($row, (array) ($overrides[(string) $accId] ?? []))]);
    exit;
}
/**
 * bill-by-bill, exactly as Books behaves: acc_id is REQUIRED and the endpoint
 * answers 400 without one. Purchases once called it without an acc_id, so the
 * stub enforcing this is what stops that regression coming back.
 *
 * The rows are Books' rows (ReportService::billByBill → BillAllocationService::listOutstanding):
 * books_bills b.* — original_amount and pending_amount as NUMERIC strings, dr_cr, the source
 * voucher's number — plus Books' ON ACCOUNT reconciliation row. There is no `amount`. A bill with
 * nothing pending is left out unless show_settled is the string '1'.
 */
if (str_contains($path, '/reports/bill-by-bill')) {
    $accId = (int) ($_GET['acc_id'] ?? 0);
    if ($accId <= 0) {
        http_response_code(400);
        echo json_encode(['status' => 400, 'error' => 400, 'messages' => ['error' => 'acc_id required'], 'message' => 'acc_id required']);
        exit;
    }
    $bill = static fn (int $id, string $ref, string $date, ?string $due, string $original, string $pending, int $flagOnAccount = 0) => [
        'bill_id' => $id, 'cmp_id' => (int) ($_GET['cmp_id'] ?? 0), 'fy_id' => (int) ($_GET['fy_id'] ?? 0), 'acc_id' => $accId,
        'bill_ref' => $ref, 'bill_date' => $date, 'due_date' => $due, 'original_amount' => $original, 'pending_amount' => $pending,
        'dr_cr' => 2, 'source_vch_txn_id' => $flagOnAccount ? null : 4100 + $id, 'source_vch_number' => $flagOnAccount ? null : 'PUR/' . (100 + $id),
        'source_vch_type_id' => $flagOnAccount ? null : 11, 'is_on_account' => $flagOnAccount, 'is_undefined_reference' => 0,
        'overdue_days' => 0, 'age_bucket' => 'not_due',
    ];
    $rows = [
        $bill(1, 'INV/0001', '2026-08-01', '2026-08-31', '200000.0000', '120000.5000'),
        $bill(2, 'INV/0002', '2026-08-10', null, '45000.2500', '45000.2500'),
        $bill(3, 'INV/0003', '2026-08-15', '2099-01-01', '10000.0000', '10000.0000'),
        $bill(4, 'INV/0004', '2026-08-20', '2026-09-01', '5000.0000', '0.0000'),
        $bill(5, 'ON ACCOUNT', '2026-04-01', null, '0.0000', '0.0000', 1),
    ];
    if (($_GET['show_settled'] ?? null) !== '1') {
        $rows = array_values(array_filter($rows, static fn (array $r) => (float) $r['pending_amount'] > 0));
    }
    echo json_encode(['data' => ['report' => 'bill_by_bill', 'acc_id' => $accId, 'rows' => $rows,
        'totals' => ['pending' => array_sum(array_map(static fn ($r) => (float) $r['pending_amount'], $rows)), 'overdue' => 0],
        'reconciliation' => ['is_reconciled' => true]]]);
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
/*
 * Books drafts, as Books' VoucherPostingService treats them.
 *
 * AUTHENTICATION FIRST, as Books' BaseController::auth() does: vouchers are written only on
 * `Authorization: Bearer <ses_key>`. Books has no X-Service-Key path on these routes — the
 * one place it reads a service key is its Inventory integration controller — so a caller
 * that sends only a service key gets 401 here, as it would from Books. Purchases used to.
 *
 * An invoice, bill or note (18, 11, 2, 3) with no party.acc_id and no journal lines is
 * refused 422; a stock effect Books does not know is refused 422 (the hardened Books,
 * rather than the old silent fallback to receiving on the invoice). A posted voucher is
 * kept so GET vouchers/{id} can answer with what Books composed: ledger lines, party, the
 * named bill and the tax summary — what Purchases reads back to verify a post.
 */
const BOOKS_STOCK_EFFECTS = [
    '11' => ['on_invoice', 'from_challan', 'defer_inward', 'from_physical_challan'],
    '3'  => ['on_invoice', 'from_challan'],
    '18' => ['on_invoice', 'from_challan'],
    '2'  => ['on_invoice', 'from_challan'],
];
$booksWrite = str_contains($path, '/vouchers/drafts') && $method === 'POST';
if ($booksWrite && !preg_match('/^Bearer\s+\S+/', (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? ''))) {
    http_response_code(401);
    echo json_encode(['status' => 401, 'error' => 401, 'messages' => ['error' => 'Invalid or expired session'], 'message' => 'Invalid or expired session']);
    exit;
}
if (str_contains($path, '/integration/capabilities') && $method === 'GET') {
    $caps = is_file(sys_get_temp_dir() . '/stub-books-caps.json')
        ? json_decode((string) file_get_contents(sys_get_temp_dir() . '/stub-books-caps.json'), true)
        : ['stock_effects' => BOOKS_STOCK_EFFECTS, 'refuses_unknown_stock_effect' => true];
    if ($caps === null) {
        http_response_code(404);
        echo json_encode(['error' => ['code' => 'not_found', 'message' => 'no route'], 'message' => 'no route']);
        exit;
    }
    echo json_encode(['data' => $caps]);
    exit;
}

$draftStore = sys_get_temp_dir() . '/stub-drafts.json';
$drafts = is_file($draftStore) ? (json_decode((string) file_get_contents($draftStore), true) ?: []) : [];
// Real Books' API accepts only a person's session (Authorization: Bearer) on its
// voucher routes — BaseController::auth(). A service key is refused, so a bill or a
// debit note must be posted AS the user.
if (str_contains($path, '/vouchers/drafts') && $method === 'POST'
    && !preg_match('/^Bearer\s+\S+/i', (string) ($headers['authorization'] ?? ''))) {
    http_response_code(401);
    echo json_encode(['status' => 401, 'error' => 401, 'messages' => ['error' => 'Books voucher routes need a signed-in session.']]);
    exit;
}

$voucherStore = sys_get_temp_dir() . '/stub-vouchers.json';
$vouchers = is_file($voucherStore) ? (json_decode((string) file_get_contents($voucherStore), true) ?: []) : [];

if (preg_match('#/vouchers/drafts/(\d+)/post$#', $path, $dm) === 1) {
    $draft = $drafts[$dm[1]] ?? null;
    $type = (int) ($draft['vch_type_id'] ?? 0);
    $voucherPayload = is_array($draft['payload'] ?? null) ? $draft['payload'] : [];
    if (in_array($type, [18, 11, 2, 3], true) && (int) ($voucherPayload['party']['acc_id'] ?? 0) <= 0 && empty($voucherPayload['lines'])) {
        http_response_code(422);
        echo json_encode(['status' => 422, 'error' => 422, 'messages' => ['error' => 'This voucher has no party account and no journal lines, so it would post with no sale, tax or balance on it. Send the party as party.acc_id with the item lines (inventory_lines) or service lines; nothing was posted.']]);
        exit;
    }
    $effect = (string) ($voucherPayload['stock_effect'] ?? 'on_invoice');
    if (!empty($voucherPayload['inventory_lines']) && isset(BOOKS_STOCK_EFFECTS[(string) $type]) && !in_array($effect, BOOKS_STOCK_EFFECTS[(string) $type], true)) {
        http_response_code(422);
        echo json_encode(['status' => 422, 'error' => 422, 'messages' => ['error' => 'Unknown stock_effect "' . $effect . '" for this voucher type; nothing was posted.']]);
        exit;
    }
    // VoucherPostingService::assertMaterialCentresOnInventoryLines: an item line of a purchase,
    // sale or note needs its material centre (mc_id) unless its goods come from a challan
    // (from_challan) or, on a purchase, arrive later on an inward challan (defer_inward).
    if (in_array($type, [11, 18, 2, 3], true) && $effect !== 'from_challan' && !($type === 11 && $effect === 'defer_inward')) {
        $lineNum = 0;
        foreach ($voucherPayload['inventory_lines'] ?? [] as $inv) {
            $lineNum++;
            if ((int) ($inv['item_id'] ?? 0) > 0 && (float) ($inv['qty'] ?? 0) > 0 && (int) ($inv['mc_id'] ?? 0) <= 0) {
                http_response_code(422);
                echo json_encode(['status' => 422, 'error' => 422, 'messages' => ['error' => 'Material centre is required on item line ' . $lineNum]]);
                exit;
            }
        }
    }
    if ($effect === 'from_physical_challan' && empty($voucherPayload['challan_settlements'])) {
        http_response_code(422);
        echo json_encode(['status' => 422, 'error' => 422, 'messages' => ['error' => 'Name the goods receipts this invoice settles (challan_settlements).']]);
        exit;
    }
    // Books' supplier invoice register (migration 174): one live purchase voucher per supplier,
    // supplier invoice number (trimmed, case- and space-insensitive) and April-March year —
    // whichever product posted the first one.
    if ($type === 11 && trim((string) ($voucherPayload['bill']['bill_ref'] ?? '')) !== '' && empty($voucherPayload['supplier_invoice_duplicate_reason'])) {
        $norm = static fn (string $r) => strtolower((string) preg_replace('/\s+/', '', trim($r)));
        $period = static function (?string $d): int {
            $d = (string) $d;
            return (int) substr($d, 5, 2) >= 4 ? (int) substr($d, 0, 4) : (int) substr($d, 0, 4) - 1;
        };
        $want = [(int) ($voucherPayload['party']['acc_id'] ?? 0), $norm((string) $voucherPayload['bill']['bill_ref']), $period($voucherPayload['bill']['bill_date'] ?? $voucherPayload['vch_date'] ?? null)];
        foreach ($vouchers as $held) {
            if ((int) ($held['vch_type_id'] ?? 0) === 11 && empty($held['cancelled'])
                && [(int) ($held['party']['acc_id'] ?? 0), $norm((string) ($held['bill']['bill_ref'] ?? '')), $period($held['bill']['bill_date'] ?? null)] === $want) {
                http_response_code(409);
                echo json_encode(['status' => 409, 'error' => 409, 'messages' => ['error' => sprintf('Supplier invoice %s from this supplier is already booked in voucher %s dated %s. The same invoice cannot be booked twice, whether it is entered here, in Aicountly Purchase or in Aicountly Billing.', $voucherPayload['bill']['bill_ref'], $held['vch_number'], $held['vch_date'])]]);
                exit;
            }
        }
    }
    // Books' CommercialVoucherComposer::guardGstSplit (books 7c10e6eb, C1): for a product posting
    // through the API (X-Saas-Origin other than books) a GST-rated line whose CGST/SGST-or-IGST split
    // is unknown — no party.pos_state_code — is a 422 and nothing is posted. Every categorised line
    // counts as GST-rated here. Mode books_before_c1 answers as Books did before it: posted, no GST.
    $posState = trim((string) ($voucherPayload['party']['pos_state_code'] ?? ''));
    $categorised = array_filter(array_merge($voucherPayload['inventory_lines'] ?? [], $voucherPayload['service_lines'] ?? []), static fn ($l) => !empty($l['tax_cat_id']) && abs((float) ($l['amount'] ?? 0)) >= 0.00005);
    $origin = strtolower(trim((string) ($headers['x-saas-origin'] ?? '')));
    if (empty($modes['books_before_c1']) && $origin !== '' && $origin !== 'books' && $categorised !== [] && $posState === '') {
        http_response_code(422);
        echo json_encode(['status' => 422, 'error' => 422, 'messages' => ['error' => sprintf(
            '%d line(s) carry GST, but the place of supply is not known, so Books cannot tell whether to charge CGST + SGST or IGST. '
            . 'Nothing was posted (it would have posted with no GST). Send party.pos_state_code (the state the goods or services are supplied to), or set the party\'s state.',
            count($categorised),
        )]]);
        exit;
    }
    foreach ($voucherPayload['service_lines'] ?? [] as $svc) {
        if ((int) ($svc['purchase_acc_id'] ?? $svc['sales_acc_id'] ?? $svc['line_acc_id'] ?? 0) <= 0) {
            http_response_code(422);
            echo json_encode(['status' => 422, 'error' => 422, 'messages' => ['error' => 'A service line needs the ledger it is booked to (purchase_acc_id).']]);
            exit;
        }
    }

    $stockLines = array_filter($voucherPayload['inventory_lines'] ?? [], static fn ($l) => !empty($l['item_id']) && (float) ($l['qty'] ?? 0) > 0);
    $id = 4000 + $n;
    $number = ($type === 3 ? 'DN/' : 'PUR/') . str_pad((string) $n, 4, '0', STR_PAD_LEFT);
    $stock = $stockLines === [] ? null : [
        'owner' => 'books', 'state' => 'COMPLETED', 'inventory_document_id' => 7500 + $n, 'inventory_document_uuid' => 'books-invdoc-' . $n,
        'stock_effect' => $effect, 'moved_stock' => !in_array($effect, ['from_physical_challan', 'defer_inward'], true),
    ];

    // What Books composes: the supplier credited with the total, the goods and services
    // debited. No tax by default (no rates bound), so the total is the taxable value. With the
    // opt-in mode books_gst_state (the branch's state code) every categorised line is taxed at
    // 18%, split as Books splits it: CGST + SGST when the place of supply is that state, IGST
    // when it is another, nothing when it is unknown (Books' behaviour before LB-2).
    $taxable = 0.0;
    $cgst = $sgst = $igst = 0.0;
    $branchState = (string) ($modes['books_gst_state'] ?? '');
    foreach (array_merge($voucherPayload['inventory_lines'] ?? [], $voucherPayload['service_lines'] ?? []) as $l) {
        $taxable += (float) ($l['amount'] ?? 0);
        if ($branchState !== '' && !empty($l['tax_cat_id']) && $posState !== '') {
            $gst = round((float) ($l['amount'] ?? 0) * 0.18, 4);
            if ($posState === $branchState) {
                $cgst += $gst / 2;
                $sgst += $gst / 2;
            } else {
                $igst += $gst;
            }
        }
    }
    $grandTotal = round($taxable + $cgst + $sgst + $igst, 4);
    $partyAcc = (int) ($voucherPayload['party']['acc_id'] ?? 0);
    $partySide = in_array($type, [11, 2], true) ? 2 : 1;
    $lines = [['acc_id' => $partyAcc, 'dr_cr' => $partySide, 'amount' => $grandTotal]];
    $lines[] = ['acc_id' => $type === 11 ? 9001 : 9002, 'dr_cr' => $partySide === 2 ? 1 : 2, 'amount' => round($taxable, 4)];
    if ($grandTotal > round($taxable, 4)) {
        $lines[] = ['acc_id' => 9100, 'dr_cr' => $partySide === 2 ? 1 : 2, 'amount' => round($grandTotal - $taxable, 4)];
    }
    $bill = is_array($voucherPayload['bill'] ?? null) ? $voucherPayload['bill'] : [];
    if (!empty($modes['books_mangle'])) {
        $bill['bill_ref'] = '';
        $lines = [['acc_id' => 9001, 'dr_cr' => 1, 'amount' => round($taxable, 4)], ['acc_id' => 9999, 'dr_cr' => 2, 'amount' => round($taxable, 4)]];
    }
    $vouchers[(string) $id] = [
        'vch_txn_id'  => $id,
        'vch_type_id' => $type,
        'vch_number'  => $number,
        'vch_date'    => $voucherPayload['vch_date'] ?? null,
        'party'       => ['acc_id' => $partyAcc],
        'bill'        => [
            'bill_ref'  => trim((string) ($bill['bill_ref'] ?? '')) !== '' ? $bill['bill_ref'] : $number,
            'bill_date' => $bill['bill_date'] ?? ($voucherPayload['vch_date'] ?? null),
            'due_date'  => $bill['due_date'] ?? null,
            'dr_cr'     => (int) ($bill['dr_cr'] ?? 1),
        ],
        'lines'       => $lines,
        'inventory_lines' => array_values($stockLines),
        'tax_summary' => ['taxable_value' => round($taxable, 4), 'cgst' => round($cgst, 4), 'sgst' => round($sgst, 4), 'igst' => round($igst, 4), 'grand_total' => $grandTotal],
        'pos_state_code' => $posState === '' ? null : $posState,
        'supply_nature' => $voucherPayload['supply_nature'] ?? null,
        'stock'       => $stock,
        'challan_settlements' => $voucherPayload['challan_settlements'] ?? [],
        'source_document_id'  => $voucherPayload['source_document_id'] ?? null,
    ];
    file_put_contents($voucherStore, json_encode($vouchers));

    $payload = ['vch_txn_id' => $id, 'vch_uuid' => 'vch-' . $n, 'vch_number' => $number, 'vch_no' => $number, 'status' => 'posted', 'stock' => $stock];
    if (isset($drafts[$dm[1]])) {
        $drafts[$dm[1]]['status'] = 'posted';
        $drafts[$dm[1]]['vch_txn_id'] = $id;
        file_put_contents($draftStore, json_encode($drafts));
    }
    echo json_encode(['data' => remember($store, $seen, $key, $payload)]);
    exit;
}
if (str_contains($path, '/vouchers/drafts') && $method === 'POST') {
    $payload = ['draft_id' => 3000 + $n, 'status' => 'DRAFT'];
    $drafts[(string) (3000 + $n)] = [
        'vch_type_id' => (int) ($body['vch_type_id'] ?? 0), 'payload' => $body['payload'] ?? [], 'status' => 'draft',
        'cmp_id' => (int) ($body['cmp_id'] ?? 0), 'fy_id' => (int) ($body['fy_id'] ?? 0),
    ];
    file_put_contents($draftStore, json_encode($drafts));
    echo json_encode(['data' => remember($store, $seen, $key, $payload)]);
    exit;
}
/*
 * GET vouchers/drafts — Books' DraftsController::index: drafts of this company and year, one
 * status (default 'draft'), optionally one type, newest first, at most `limit` (1–100), each
 * with the payload it was saved from.
 */
if (preg_match('#/vouchers/drafts$#', $path) === 1 && $method === 'GET') {
    $status = trim((string) ($_GET['status'] ?? 'draft'));
    $type = (int) ($_GET['vch_type_id'] ?? 0);
    $limit = max(1, min(100, (int) ($_GET['limit'] ?? 50)));
    $rows = [];
    foreach (array_reverse($drafts, true) as $draftId => $d) {
        if (($type > 0 && (int) $d['vch_type_id'] !== $type) || ($status !== '' && ($d['status'] ?? 'draft') !== $status)) {
            continue;
        }
        if ((int) ($d['fy_id'] ?? 0) > 0 && (int) ($_GET['fy_id'] ?? 0) > 0 && (int) $d['fy_id'] !== (int) $_GET['fy_id']) {
            continue;
        }
        $rows[] = ['draft_id' => (int) $draftId, 'vch_type_id' => (int) $d['vch_type_id'], 'status' => $d['status'] ?? 'draft', 'created_at' => '', 'updated_at' => '', 'payload' => $d['payload'] ?? []];
        if (count($rows) >= $limit) {
            break;
        }
    }
    echo json_encode(['data' => $rows]);
    exit;
}
/*
 * GET registers — Books' RegistersController::index, the part a reference search reads: posted
 * vouchers of a type whose number or bill reference CONTAINS bill_ref, case-insensitive (it is a
 * LIKE), each row the voucher header with its party and bill_ref attached.
 */
if (preg_match('#/registers$#', $path) === 1 && $method === 'GET') {
    $type = (int) ($_GET['vch_type_id'] ?? 0);
    $ref = strtolower(trim((string) ($_GET['bill_ref'] ?? '')));
    $rows = [];
    foreach ($vouchers as $v) {
        if (!empty($v['cancelled']) || ($type > 0 && (int) ($v['vch_type_id'] ?? 0) !== $type)) {
            continue;
        }
        $billRef = (string) ($v['bill']['bill_ref'] ?? '');
        if ($ref !== '' && !str_contains(strtolower((string) ($v['vch_number'] ?? '')), $ref) && !str_contains(strtolower($billRef), $ref)) {
            continue;
        }
        $rows[] = [
            'vch_txn_id' => (int) $v['vch_txn_id'], 'vch_type_id' => (int) $v['vch_type_id'], 'vch_number' => $v['vch_number'],
            'vch_date' => $v['vch_date'] ?? null, 'party_acc_id' => (int) ($v['party']['acc_id'] ?? 0), 'status' => 'posted',
            'bill_ref' => $billRef,
        ];
    }
    echo json_encode(['data' => $rows, 'meta' => ['voucher_count' => count($rows)]]);
    exit;
}
if (preg_match('#/vouchers/(\d+)$#', $path, $vm) === 1 && $method === 'GET') {
    $voucher = $vouchers[$vm[1]] ?? null;
    if ($voucher === null) {
        http_response_code(404);
        echo json_encode(['status' => 404, 'error' => 404, 'messages' => ['error' => 'Voucher not found'], 'message' => 'Voucher not found']);
        exit;
    }
    echo json_encode(['data' => $voucher]);
    exit;
}
if (str_contains($path, '/dashboard/sales')) {
    echo json_encode(['data' => ['total_sales' => 985000.0, 'invoice_count' => 42]]);
    exit;
}

http_response_code(404);
echo json_encode(['error' => ['code' => 'not_found', 'message' => 'Stub has no route for ' . $path], 'message' => 'no stub route']);
