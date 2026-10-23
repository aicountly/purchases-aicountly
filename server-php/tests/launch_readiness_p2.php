<?php

declare(strict_types=1);

/**
 * Launch readiness, phase 2 (docs/launch-readiness-2026-10/OPERATOR_RUNBOOK.md, the phase-2 audit):
 *
 *   P0-P1  /api/health can reach `ready` with exactly the documented settings — no BOOKS_SERVICE_KEY —
 *          and reports the optional ones as warnings; post-deploy and the env template agree
 *   P1-P4  the purchase order is GST-complete: GSTINs, HSN/SAC, place of supply, bill-to / ship-to,
 *          CGST + SGST or IGST, the total in words, an embedded Unicode font; the parts add up
 *   P1-P8  a missing supplier is added from the order editor as a Books creditor ledger, once
 *   sticky removal: a workspace member an administrator removed is not seated again on their next request
 *   P1-P3 / P1-P7  clickjacking headers on the SPA and the API; web unit tests in the manual CI
 *
 *   server-php/tests/run.sh
 */

namespace Aicountly\Api;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../src/Env.php';
require __DIR__ . '/../src/Autoload.php';

Env::load(__DIR__ . '/../.env');

require_once __DIR__ . '/../lib/company-lifecycle/autoload.php';

use Aicountly\Api\Controllers\AccessController;
use Aicountly\Api\Controllers\SuppliersController;
use Aicountly\Api\Domain\PurchaseOrderService;
use Aicountly\Api\Ops\OpsStatus;
use Aicountly\Api\Pdf\AmountInWords;
use Aicountly\Api\Pdf\PdfText;
use Aicountly\Api\Pdf\PurchaseOrderDocument;
use Aicountly\Api\Pdf\PurchaseOrderTax;
use Aicountly\Lifecycle\Signature;

$passed = 0;
$failed = 0;
$repo = dirname(__DIR__, 2);

function check(string $name, callable $fn): void
{
    global $passed, $failed;
    Context::forgetVerified();
    Permissions::forget();
    try {
        $fn();
        echo "  ok    {$name}\n";
        $passed++;
    } catch (\Throwable $e) {
        echo "  FAIL  {$name}\n        {$e->getMessage()}\n";
        if (getenv('VERBOSE')) {
            echo '        ' . $e->getFile() . ':' . $e->getLine() . "\n";
        }
        $failed++;
    }
}

function same(mixed $expected, mixed $actual, string $what): void
{
    if ($expected !== $actual) {
        throw new \RuntimeException(sprintf('%s: expected %s, got %s', $what, var_export($expected, true), var_export($actual, true)));
    }
}

function ok(bool $condition, string $what): void
{
    if (!$condition) {
        throw new \RuntimeException($what);
    }
}

function caller(string $uuid, string $role): Auth
{
    $r = new \ReflectionClass(Auth::class);
    $auth = $r->newInstanceWithoutConstructor();
    foreach (['uuid' => $uuid, 'kind' => 'user', 'sourceApp' => 'purchases', 'sesKey' => 'stub-ses-key.role-' . $role, 'session' => ['name' => $uuid]] as $prop => $value) {
        $r->getProperty($prop)->setValue($auth, $value);
    }

    return $auth;
}

function enter(int $cmpId, Auth $auth): Context
{
    $_GET = ['cmp_id' => (string) $cmpId, 'fy_id' => '6', 'bo_id' => '0'];
    Context::forgetVerified();
    Permissions::forget();
    $ctx = Context::fromRequest();
    $ctx->assertAllowed($auth);

    return $ctx;
}

function manageSays(array $byCompany): void
{
    file_put_contents(sys_get_temp_dir() . '/stub-manage-company-extra.json', json_encode($byCompany));
}

function stubMode(array $modes): void
{
    file_put_contents(sys_get_temp_dir() . '/stub-mode.json', json_encode($modes));
}

function resetAll(): void
{
    require_once __DIR__ . '/reset.php';
    resetPurchaseTables();
    Db::connect()->exec('TRUNCATE purchase_manage_seat_events');
    @unlink(sys_get_temp_dir() . '/stub-manage-company-extra.json');
    Permissions::forget();
}

/** Call a controller action as an HTTP request would reach it: query, body, headers, session. */
function endpoint(callable $action, Auth $auth, array $body = [], array $headers = [], array $query = ['cmp_id' => '88', 'fy_id' => '6', 'bo_id' => '0']): array
{
    $_GET = $query;
    (new \ReflectionProperty(Http::class, 'body'))->setValue(null, $body);
    foreach ($headers as $name => $value) {
        $_SERVER['HTTP_' . str_replace('-', '_', strtoupper($name))] = $value;
    }
    Auth::adopt($auth);
    Context::forgetVerified();
    try {
        $action();
    } catch (ResponseSent $sent) {
        return [$sent->status, $sent->payload];
    } finally {
        Auth::adopt(null);
        (new \ReflectionProperty(Http::class, 'body'))->setValue(null, null);
        foreach ($headers as $name => $value) {
            unset($_SERVER['HTTP_' . str_replace('-', '_', strtoupper($name))]);
        }
    }
    throw new \RuntimeException('the endpoint returned without responding');
}

/** @return list<string> */
function heldKeys(int $cmpId, string $uuid): array
{
    return array_map('strval', array_column(Db::all(
        'SELECT COALESCE(p.system_key, p.profile_name) AS k FROM purchase_permission_assignments a
           JOIN purchase_permission_profiles p ON p.profile_id = a.profile_id
          WHERE a.cmp_id = :cmp AND a.user_uuid = :uuid ORDER BY 1',
        ['cmp' => $cmpId, 'uuid' => $uuid],
    ), 'k'));
}

/** A deployment's settings, as OpsStatus::config() reads them. */
function settings(array $values): callable
{
    return static fn (string $name): string => (string) ($values[$name] ?? '');
}

const DOCUMENTED = [
    'INVENTORY_SERVICE_KEY'       => 'inv-key-for-purchases-0001',
    'BOOKS_EVENTS_SIGNING_SECRET' => '0123456789abcdef0123',
    'SERVICE_KEYS'                => 'books:books-key-0001,insights:insights-key-0001',
    'LIFECYCLE_WEBHOOK_SECRET'    => '0123456789abcdef0123456789abcdef',
    'MANAGE_API_URL'              => 'https://manage.aicountly.com',
];

/** An order as PurchaseOrderService stores it: lines with line_amount and estimated_tax_pc, totals from totals(). */
function order(array $lines, float $freight = 0, float $other = 0): array
{
    $rows = [];
    foreach ($lines as $i => [$qty, $rate, $discount, $pc]) {
        $amount = round($qty * $rate - $discount, 4);
        $rows[] = ['line_no' => $i + 1, 'item_id' => 201 + $i, 'description' => null, 'hsn_sac' => null, 'ordered_qty' => $qty, 'agreed_rate' => $rate,
            'discount_amount' => $discount, 'estimated_tax_pc' => $pc, 'line_amount' => $amount, 'estimated_tax' => round($amount * $pc / 100, 4)];
    }
    $t = PurchaseOrderService::totals($rows, $freight, $other);

    return ['po_no' => 'PO-0001', 'po_date' => '2026-10-20', 'cmp_id' => 88, 'supplier_account_id' => 601, 'supplier_name_snapshot' => 'Deccan Steel Traders',
        'currency_code' => 'INR', 'subtotal_amount' => $t['subtotal'], 'discount_amount' => $t['discount'], 'freight_amount' => $t['freight'],
        'other_charges' => $t['other'], 'estimated_tax_amount' => $t['tax'], 'total_amount' => $t['total'], 'lines' => $rows,
        'promised_date' => '2026-10-30', 'payment_terms' => '30 days'];
}

/** The printed parts of a summary, added up. */
function printedSum(array $s): float
{
    return round($s['subtotal'] - $s['discount'] + $s['freight'] + $s['other'] + $s['cgst'] + $s['sgst'] + $s['igst']
        + ($s['mode'] === PurchaseOrderTax::UNKNOWN ? $s['tax'] : 0) + $s['rounding'], 2);
}

echo "\nLaunch readiness phase 2 (P0-P1 health, P1-P4 GST purchase order, P1-P8 new supplier, sticky removal)\n";

// --- P0-P1: health ----------------------------------------------------------------------------------

check('health: exactly the documented settings make config ready — there is no BOOKS_SERVICE_KEY to set', function () {
    $c = OpsStatus::config(settings(DOCUMENTED));
    same(true, $c['ready'], 'ready with the five documented settings');
    same(['INVENTORY_SERVICE_KEY' => true, 'BOOKS_EVENTS_SIGNING_SECRET' => true, 'SERVICE_KEYS:books' => true, 'LIFECYCLE_WEBHOOK_SECRET' => true, 'MANAGE_API_URL' => true], $c['items'], 'items: booleans only');
    same(['PULSE_SERVICE_KEY', 'MANAGE_PRODUCT_SERVICE_KEY'], $c['warnings'], 'the optional ones that are missing are warnings');
    ok(!in_array('BOOKS_SERVICE_KEY', OpsStatus::REQUIRED_CONFIG, true) && !in_array('BOOKS_SERVICE_KEY', OpsStatus::OPTIONAL_CONFIG, true), 'BOOKS_SERVICE_KEY is neither required nor reported');
    ok(!in_array('PULSE_SERVICE_KEY', OpsStatus::REQUIRED_CONFIG, true), 'AI is optional: PULSE_SERVICE_KEY never blocks ready');

    $all = OpsStatus::config(settings(DOCUMENTED + ['PULSE_SERVICE_KEY' => 'pulse-key', 'MANAGE_PRODUCT_SERVICE_KEY' => 'mps-key']));
    same([true, []], [$all['ready'], $all['warnings']], 'every optional setting present: no warnings');
    foreach ($all as $part) {
        ok(!str_contains((string) json_encode($part), 'key-0001') && !str_contains((string) json_encode($part), '0123456789'), 'no value is ever reported');
    }
});

check('health: each required setting missing, a placeholder, or too short a secret is not ready', function () {
    foreach (array_keys(DOCUMENTED) as $name) {
        $values = DOCUMENTED;
        unset($values[$name]);
        $c = OpsStatus::config(settings($values));
        same(false, $c['ready'], $name . ' missing');
    }
    $cases = [
        'BOOKS_EVENTS_SIGNING_SECRET' => ['too-short-15chr', 'BOOKS_EVENTS_SIGNING_SECRET'],
        'LIFECYCLE_WEBHOOK_SECRET'    => [str_repeat('a', 31), 'LIFECYCLE_WEBHOOK_SECRET'],
        'INVENTORY_SERVICE_KEY'       => ['CHANGE_ME', 'INVENTORY_SERVICE_KEY'],
    ];
    foreach ($cases as $name => [$value, $item]) {
        $c = OpsStatus::config(settings([$name => $value] + DOCUMENTED));
        same([false, false], [$c['ready'], $c['items'][$item]], $name . '=' . $value);
    }
    foreach (['insights:k1', 'books:CHANGE_ME', 'books:', 'booksx:k1', ''] as $keys) {
        $c = OpsStatus::config(settings(['SERVICE_KEYS' => $keys] + DOCUMENTED));
        same(false, $c['items']['SERVICE_KEYS:books'], 'SERVICE_KEYS=' . $keys . ' has no books entry');
    }
    same(true, OpsStatus::config(settings(['SERVICE_KEYS' => ' Books : k1 '] + DOCUMENTED))['items']['SERVICE_KEYS:books'], 'case and spaces as ServiceKeys reads them');
});

check('health: the env template, the test runner and post-deploy agree with what ready needs', function () use ($repo) {
    $template = (string) file_get_contents($repo . '/server-php/.env.example');
    foreach (['INVENTORY_SERVICE_KEY=', 'BOOKS_EVENTS_SIGNING_SECRET=', 'SERVICE_KEYS=books:', 'LIFECYCLE_WEBHOOK_SECRET=', 'MANAGE_API_URL=',
        'BOOKS_SOURCE_EVENTS_PURCHASES_URL', 'BOOKS_SOURCE_EVENTS_PURCHASES_KEY', 'BOOKS_SOURCE_EVENTS_PURCHASES_SECRET', 'LIFECYCLE_WEBHOOK_SECRET_PURCHASES'] as $needle) {
        ok(str_contains($template, $needle), '.env.example documents ' . $needle);
    }
    ok(preg_match('/^BOOKS_SERVICE_KEY=/m', $template) !== 1, '.env.example offers no BOOKS_SERVICE_KEY');
    ok(!str_contains((string) file_get_contents($repo . '/server-php/tests/run.sh'), 'BOOKS_SERVICE_KEY'), 'the suite does not depend on BOOKS_SERVICE_KEY');
    $post = (string) file_get_contents($repo . '/scripts/ci/post-deploy-checks.sh');
    ok(str_contains($post, ".usable == true and .ready == true"), 'production post-deploy still requires ready');
    ok(str_contains($post, 'config.warnings') && str_contains($post, '|| echo "WARNING'), 'optional settings only warn');
    ok(preg_match('/^[^#\n]*BOOKS_SERVICE_KEY/m', $post) !== 1, 'post-deploy never asks for BOOKS_SERVICE_KEY (a comment says there is none)');
    $runbook = $repo . '/docs/launch-readiness-2026-10/OPERATOR_RUNBOOK.md';
    ok(is_file($runbook), 'the operator runbook exists');
    foreach (OpsStatus::REQUIRED_CONFIG as $name) {
        ok(str_contains((string) file_get_contents($runbook), explode(':', $name)[0]), 'the runbook covers ' . $name);
    }
});

// --- P1-P4: the GST purchase order --------------------------------------------------------------------

check('amount in words: Indian grouping, paise, rounding to the printed paisa', function () {
    same('Rupees Zero Only', AmountInWords::of(0), 'zero');
    same('Rupees One Lakh Twenty-Five Thousand and Fifty Paise Only', AmountInWords::of(125000.50), 'lakh and paise');
    same('Rupees Twelve Crore Thirty-Four Lakh Fifty-Six Thousand Seven Hundred Eighty-Nine and Ninety-Nine Paise Only', AmountInWords::of(123456789.99), 'crore');
    same('Rupees One Hundred and One Paise Only', AmountInWords::of(100.005), 'rounded like number_format: 100.01');
    same('Rupees Eleven Only', AmountInWords::of(11), 'teens');
    same('Rupees One Hundred Crore Only', AmountInWords::of(1000000000), 'a hundred crore');
    same('USD Twenty and Five Cents Only', AmountInWords::of(20.05, 'USD'), 'another currency by its code');
});

check('GST split: same state is CGST + SGST, half each, an odd paisa to SGST', function () {
    $po = order([[10, 250, 0, 18], [4, 250, 0, 5]]);
    $s = PurchaseOrderTax::summarise($po, ['pos_state_code' => '29', 'supply_nature' => null], '29');
    same(PurchaseOrderTax::INTRA, $s['mode'], 'intra-state');
    same([225.0, 225.0, 0.0], [$s['rates'][1]['cgst'], $s['rates'][1]['sgst'], $s['rates'][1]['igst']], '18% on 2,500: 9% + 9%');
    same([25.0, 25.0], [$s['rates'][0]['cgst'], $s['rates'][0]['sgst']], '5% on 1,000: 2.5% + 2.5%');
    same([250.0, 250.0, 500.0], [$s['cgst'], $s['sgst'], $s['tax']], 'totals');
    same(4000.0, printedSum($s), 'subtotal + CGST + SGST = total');
    same(4000.0, $s['total'], 'the order\'s own total');

    $odd = PurchaseOrderTax::summarise(order([[1, 0.83, 0, 18]]), ['pos_state_code' => '29', 'supply_nature' => null], '29');
    same([0.07, 0.08, 0.15], [$odd['cgst'], $odd['sgst'], $odd['tax']], '0.15 splits 0.07 + 0.08');
});

check('GST split: another state, an import and an SEZ unit are IGST; an unknown state is not split', function () {
    $po = order([[10, 250, 50, 18]], 100);
    $inter = PurchaseOrderTax::summarise($po, ['pos_state_code' => '27', 'supply_nature' => null], '29');
    same([PurchaseOrderTax::INTER, 441.0, 0.0, 0.0], [$inter['mode'], $inter['igst'], $inter['cgst'], $inter['sgst']], 'Maharashtra to Karnataka: IGST on 2,450');
    same($inter['total'], printedSum($inter), 'parts add up with discount and freight');
    same(PurchaseOrderTax::INTER, PurchaseOrderTax::summarise($po, ['pos_state_code' => '96', 'supply_nature' => null], '29')['mode'], 'import');
    same(PurchaseOrderTax::INTER, PurchaseOrderTax::summarise($po, ['pos_state_code' => '29', 'supply_nature' => 'sez'], '29')['mode'], 'an SEZ unit in the same state');
    $unknown = PurchaseOrderTax::summarise($po, ['pos_state_code' => null, 'supply_nature' => null], '29');
    same([PurchaseOrderTax::UNKNOWN, 0.0, 0.0, 441.0], [$unknown['mode'], $unknown['igst'], $unknown['cgst'], $unknown['tax']], 'supplier state unknown');
    same(PurchaseOrderTax::UNKNOWN, PurchaseOrderTax::summarise($po, null, '29')['mode'], 'ledger unreadable');
    same(PurchaseOrderTax::UNKNOWN, PurchaseOrderTax::summarise($po, ['pos_state_code' => '29', 'supply_nature' => null], null)['mode'], 'buyer state unknown');
});

check('GST split: 500 random orders — the printed parts always add up to the printed total, to the paisa', function () {
    mt_srand(20261023);
    $worstRounding = 0.0;
    for ($n = 0; $n < 500; $n++) {
        $lines = [];
        for ($i = 0, $k = mt_rand(1, 6); $i < $k; $i++) {
            $qty = mt_rand(1, 50000) / 1000;
            $rate = mt_rand(1, 9999999) / 1000;
            $discount = mt_rand(0, 3) === 0 ? round($qty * $rate * mt_rand(0, 20) / 100, 4) : 0.0;
            $lines[] = [$qty, $rate, $discount, [0, 0.25, 3, 5, 12, 18, 28][mt_rand(0, 6)]];
        }
        $po = order($lines, mt_rand(0, 1) ? mt_rand(0, 500000) / 100 : 0, mt_rand(0, 3) === 0 ? mt_rand(0, 9999) / 100 : 0);
        foreach ([['29', '29'], ['27', '29'], [null, '29']] as [$from, $to]) {
            $s = PurchaseOrderTax::summarise($po, ['pos_state_code' => $from, 'supply_nature' => null], $to);
            same($s['total'], printedSum($s), "order {$n} ({$from}->{$to})");
            same(round((float) $po['total_amount'], 2), $s['total'], "order {$n}: the total is the order's own");
            foreach ($s['rates'] as $row) {
                same($row['tax'], round($row['cgst'] + $row['sgst'] + $row['igst'] + ($s['mode'] === PurchaseOrderTax::UNKNOWN ? $row['tax'] : 0), 2), "order {$n}: the split is the rate's tax");
            }
            $worstRounding = max($worstRounding, abs($s['rounding']));
        }
    }
    ok($worstRounding <= 0.02, 'rounding stays within two paise: ' . $worstRounding);
});

check('the purchase order prints GSTINs, HSN/SAC, place of supply, bill-to / ship-to, the split and the words, in an embedded font', function () {
    $po = order([[10, 250, 0, 18], [4, 250, 0, 5]]);
    $po['lines'][1]['hsn_sac'] = '9973';
    $pdf = PurchaseOrderDocument::render($po, ['comp_name' => 'Stub Trading Co', 'ro_adrs1' => '12 MG Road', 'ro_city' => 'Bengaluru', 'ro_pin' => '560001', 'ro_state' => '29'], [201 => 'TMT Bar 12mm', 202 => 'Café Müller chairs'], str_repeat('a', 64), [
        'buyer'    => ['gstin' => '29AAACS1234A1ZY', 'state_code' => '29'],
        'supplier' => ['gstin' => '29AAACS1234A1ZY', 'state_code' => '29', 'supply_nature' => null, 'address' => 'Plot 4, Peenya, Bengaluru'],
        'ship_to'  => ['name' => 'Main Store', 'address' => 'Whitefield, Bengaluru'],
        'hsn'      => [201 => '7214'],
    ]);
    $text = PdfText::extract($pdf);
    foreach (['PURCHASE ORDER', 'GSTIN 29AAACS1234A1ZY', 'Supplier', 'Bill to', 'Ship to', 'Main Store', 'Whitefield, Bengaluru', 'Place of supply Karnataka (29)',
        'HSN/SAC', '7214', '9973', 'Taxable value', 'CGST @ 9% on 2,500.00', 'SGST @ 9% on 2,500.00', 'CGST @ 2.5% on 1,000.00', 'Total (₹)', '4,000.00',
        'Amount in words: Rupees Four Thousand Only', 'Café Müller chairs', 'Authorised signatory'] as $needle) {
        ok(str_contains($text, $needle), 'prints "' . $needle . '"');
    }
    ok(!str_contains($text, 'IGST'), 'no IGST on an intra-state order');
    ok(str_contains($pdf, '/FontFile2') && str_contains($pdf, '/Subtype /Type0'), 'a Unicode font is embedded');
    ok(!str_contains($pdf, '/BaseFont /Helvetica'), 'not Helvetica, which cannot print ₹');

    $inter = PdfText::extract(PurchaseOrderDocument::render($po, ['comp_name' => 'Stub Trading Co', 'ro_state' => '29'], [], str_repeat('b', 64), [
        'supplier' => ['gstin' => '27AAPFU0939F1ZV', 'state_code' => '27', 'supply_nature' => null],
    ]));
    ok(str_contains($inter, 'IGST @ 18% on 2,500.00') && !str_contains($inter, 'CGST'), 'another state: IGST only');
    ok(str_contains($inter, 'GSTIN Not known'), 'a buyer GSTIN that could not be read says so');
    ok(str_contains($inter, 'Supplier in another state: IGST.'), 'and the basis of the split is printed');
});

check('the order\'s document reads the supplier\'s ledger, the branch GST, the warehouse and HSN live (stub Books / Inventory)', function () {
    resetAll();
    $owner = caller('user-owner', '1');
    $ctx = enter(88, $owner);
    $orders = new PurchaseOrderService($ctx, $owner);
    $po = $orders->create(['supplier_account_id' => 601, 'supplier_name' => 'Deccan Steel Traders', 'po_date' => '2026-09-01', 'delivery_warehouse_id' => 3,
        'lines' => [['item_id' => 201, 'unit_id' => 1, 'ordered_qty' => 10, 'agreed_rate' => 250, 'estimated_tax_pc' => 18, 'warehouse_id' => 3]]]);
    $orders->submit((int) $po['po_id']);
    $orders->issue((int) $po['po_id']);
    $text = PdfText::extract($orders->document((int) $po['po_id'])['pdf']);
    foreach (['GSTIN 27AAPFU0939F1ZV', 'State Maharashtra (27)', 'Place of supply Karnataka (29)', 'Bay 3', '7214', 'IGST @ 18% on 2,500.00', 'Amount in words: Rupees Two Thousand Nine Hundred Fifty Only'] as $needle) {
        ok(str_contains($text, $needle), 'prints "' . $needle . '"');
    }

    // Raised under branch 2 (registered in Karnataka) for a supplier now in Karnataka too: CGST + SGST.
    file_put_contents(sys_get_temp_dir() . '/stub-accounts.json', json_encode(['601' => ['gstin' => '', 'state_code' => '29']]));
    try {
        $branchCtx = Context::of(88, 6, 2);
        $branchOrders = new PurchaseOrderService($branchCtx, $owner);
        $po2 = $branchOrders->create(['supplier_account_id' => 601, 'supplier_name' => 'Deccan Steel Traders', 'po_date' => '2026-09-02',
            'lines' => [['item_id' => 201, 'unit_id' => 1, 'ordered_qty' => 2, 'agreed_rate' => 100, 'estimated_tax_pc' => 12, 'warehouse_id' => 3]]]);
        $branchOrders->submit((int) $po2['po_id']);
        $branchOrders->issue((int) $po2['po_id']);
        $text2 = PdfText::extract($branchOrders->document((int) $po2['po_id'])['pdf']);
        ok(str_contains($text2, 'GSTIN 29AAACS1234A1ZY'), 'the buyer\'s GSTIN from Books\' branch GST');
        ok(str_contains($text2, 'CGST @ 6% on 200.00') && str_contains($text2, 'SGST @ 6% on 200.00'), 'same state: CGST + SGST');
    } finally {
        @unlink(sys_get_temp_dir() . '/stub-accounts.json');
    }
});

// --- P1-P8: a new supplier from the order editor ------------------------------------------------------

check('new supplier: a Books creditor ledger under Sundry Creditors, once per Idempotency-Key', function () {
    resetAll();
    $owner = caller('user-owner', '1');
    $body = ['name' => '  Konkan  Cement Works ', 'gstin' => '27aapfu0939f1zv', 'address' => 'MIDC', 'city' => 'Pune'];
    [$status, $payload] = endpoint([SuppliersController::class, 'createLedger'], $owner, $body, ['Idempotency-Key' => 'po-editor-0001']);
    same(201, $status, 'created');
    same(['acc_id' => 9101, 'acc_name' => 'Konkan Cement Works', 'mode' => 'created'], $payload['data'] ?? null, 'the new ledger');
    $sent = null;
    foreach (array_map(static fn ($l) => json_decode($l, true), file(sys_get_temp_dir() . '/stub-requests.jsonl') ?: []) as $r) {
        if (($r['method'] ?? '') === 'POST' && str_ends_with((string) ($r['path'] ?? ''), '/masters/accounts')) {
            $sent = $r;
        }
    }
    ok($sent !== null, 'Books was asked');
    same(['Konkan Cement Works', 12, 'supplier', '27AAPFU0939F1ZV', '27'], [$sent['body']['acc_name'], $sent['body']['acc_grp_id'], $sent['body']['party_type'], $sent['body']['gstin'], $sent['body']['state_code']], 'name, the creditors group read live, the GSTIN and its state');
    same('purchases-supplier-88-po-editor-0001', $sent['headers']['idempotency-key'] ?? null, 'under the form\'s key');

    [$again, $replay] = endpoint([SuppliersController::class, 'createLedger'], $owner, $body, ['Idempotency-Key' => 'po-editor-0001']);
    same([201, 9101, 'replayed'], [$again, $replay['data']['acc_id'] ?? null, $replay['data']['mode'] ?? null], 'a retry is Books\' replay of the same ledger');
});

check('new supplier: an existing creditor, a bad GSTIN, no key, no permission and no creditors group are refused', function () {
    resetAll();
    $owner = caller('user-owner', '1');
    [$status, $payload] = endpoint([SuppliersController::class, 'createLedger'], $owner, ['name' => 'northern distributors'], ['Idempotency-Key' => 'po-editor-0002']);
    same([409, 'supplier_exists', 501], [$status, $payload['error']['code'] ?? null, $payload['error']['details']['acc_id'] ?? null], 'the same name among the creditors: pick it instead');
    [$status, $payload] = endpoint([SuppliersController::class, 'createLedger'], $owner, ['name' => 'Some Supplier', 'gstin' => '27AAPFU0939F1ZA'], ['Idempotency-Key' => 'po-editor-0003']);
    same(422, $status, 'a GSTIN with a wrong check digit');
    [$status] = endpoint([SuppliersController::class, 'createLedger'], $owner, ['name' => 'Some Supplier', 'gstin' => '27AAPFU0939F1ZV', 'state_code' => '29'], ['Idempotency-Key' => 'po-editor-0004']);
    same(422, $status, 'a state that contradicts the GSTIN');
    [$status] = endpoint([SuppliersController::class, 'createLedger'], $owner, ['name' => 'Some Supplier']);
    same(422, $status, 'no Idempotency-Key');
    [$status] = endpoint([SuppliersController::class, 'createLedger'], caller('user-nobody', '0'), ['name' => 'Some Supplier'], ['Idempotency-Key' => 'po-editor-0005']);
    same(403, $status, 'no supplier.manage');
    stubMode(['no_creditors_group' => true]);
    try {
        [$status, $payload] = endpoint([SuppliersController::class, 'createLedger'], $owner, ['name' => 'Some Supplier'], ['Idempotency-Key' => 'po-editor-0006']);
        same([422, 'creditors_group_missing'], [$status, $payload['error']['code'] ?? null], 'no Sundry Creditors group in Books');
    } finally {
        @unlink(sys_get_temp_dir() . '/stub-mode.json');
    }
});

// --- sticky removal ------------------------------------------------------------------------------------

check('removal: a workspace member an administrator removed is not seated again on their next request', function () {
    resetAll();
    manageSays(['88' => ['app_access_source' => 'workspace', 'app_role_key' => 'buyer']]);
    $member = caller('user-ws-removed', '0');
    enter(88, $member);
    same(['buyer'], heldKeys(88, 'user-ws-removed'), 'seated on first entry');

    $owner = caller('user-owner', '1');
    $assignment = (int) Db::scalar("SELECT assignment_id FROM purchase_permission_assignments WHERE user_uuid = 'user-ws-removed'");
    [$status] = endpoint(static fn () => AccessController::unassign((string) $assignment), $owner);
    same(200, $status, 'the administrator removes the profile');
    same('user-owner', Db::scalar("SELECT removed_by FROM purchase_access_removals WHERE cmp_id = 88 AND user_uuid = 'user-ws-removed'"), 'the removal is recorded');

    enter(88, $member);
    enter(88, $member);
    same([], heldKeys(88, 'user-ws-removed'), 'not re-seated by workspace access');
});

check('removal: a grant here or Manage\'s signed seat event replaces it; removing one of two profiles is no removal', function () {
    resetAll();
    manageSays(['88' => ['app_access_source' => 'workspace', 'app_role_key' => 'buyer']]);
    $member = caller('user-ws-back', '0');
    $owner = caller('user-owner', '1');
    enter(88, $member);
    $assignment = (int) Db::scalar("SELECT assignment_id FROM purchase_permission_assignments WHERE user_uuid = 'user-ws-back'");
    endpoint(static fn () => AccessController::unassign((string) $assignment), $owner);
    same(true, ManageSeats::wasRemoved(88, 'user-ws-back'), 'removed');

    // The administrator grants a profile again: the removal no longer stands.
    $profile = (int) Db::scalar("SELECT profile_id FROM purchase_permission_profiles WHERE cmp_id = 88 AND system_key = 'buyer'");
    [$status] = endpoint([AccessController::class, 'assign'], $owner, ['user_uuid' => 'user-ws-back', 'profile_id' => $profile]);
    same(200, $status, 'assigned');
    same(false, ManageSeats::wasRemoved(88, 'user-ws-back'), 'a new grant replaces the removal');

    // Two profiles, one removed: they still hold Purchases, nothing to remember.
    $viewer = (int) Db::insert('purchase_permission_profiles', ['cmp_id' => 88, 'profile_name' => 'Read only', 'permissions' => ['po.view'], 'system_key' => 'viewer', 'is_active' => true], 'profile_id');
    endpoint([AccessController::class, 'assign'], $owner, ['user_uuid' => 'user-ws-back', 'profile_id' => $viewer]);
    $one = (int) Db::scalar('SELECT assignment_id FROM purchase_permission_assignments WHERE user_uuid = :u AND profile_id = :p', ['u' => 'user-ws-back', 'p' => $viewer]);
    endpoint(static fn () => AccessController::unassign((string) $one), $owner);
    same(false, ManageSeats::wasRemoved(88, 'user-ws-back'), 'still seated: no removal recorded');

    // Removed again, then Manage seats them explicitly (a share or an approved request): that stands.
    $last = (int) Db::scalar("SELECT assignment_id FROM purchase_permission_assignments WHERE user_uuid = 'user-ws-back'");
    endpoint(static fn () => AccessController::unassign((string) $last), $owner);
    same(true, ManageSeats::wasRemoved(88, 'user-ws-back'), 'removed again');
    $raw = (string) json_encode(['event_id' => '7d1d4c1e-0000-4000-8000-000000000301', 'cmp_id' => 88, 'platform_user_id' => 'user-ws-back', 'uuid' => 'user-ws-back', 'role_key' => 'approver', 'action' => 'grant'], JSON_UNESCAPED_SLASHES);
    $secret = 'test-lifecycle-secret-0123456789abcdef';
    $ts = time();
    [$seatStatus, $seatBody] = ManageSeats::handle($raw, ['X-Manage-Timestamp' => (string) $ts, 'X-Manage-Signature' => Signature::sign($secret, $ts, $raw), 'Content-Type' => 'application/json'], static fn (): ?string => $secret);
    same([200, 'seated'], [$seatStatus, $seatBody['data']['status'] ?? null], 'Manage seats them');
    same(false, ManageSeats::wasRemoved(88, 'user-ws-back'), 'Manage\'s explicit grant replaces the removal');
    same(['approver'], heldKeys(88, 'user-ws-back'), 'holding what Manage granted');
});

// --- P1-P3 / P1-P7: headers and CI ---------------------------------------------------------------------

check('clickjacking: the SPA and the API send frame-ancestors, X-Frame-Options and HSTS', function () use ($repo) {
    $web = (string) file_get_contents($repo . '/web/public/.htaccess');
    ok(str_contains($web, "Content-Security-Policy \"frame-ancestors 'self' https://*.aicountly.com\""), 'SPA: frame-ancestors as Sales and Inventory send it');
    ok(str_contains($web, 'X-Frame-Options "SAMEORIGIN"'), 'SPA: X-Frame-Options for browsers without CSP');
    ok(str_contains($web, 'Strict-Transport-Security "max-age=31536000"'), 'SPA: HSTS');
    $api = (string) file_get_contents($repo . '/server-php/.htaccess');
    ok(str_contains($api, "frame-ancestors 'self'") && str_contains($api, 'X-Frame-Options "SAMEORIGIN"') && str_contains($api, 'Strict-Transport-Security'), 'API: the same, same-origin only');
});

check('CI: the manual workflow runs the web unit tests, and no workflow runs on push or pull_request', function () use ($repo) {
    $ci = (string) file_get_contents($repo . '/.github/workflows/ci.yml');
    ok(preg_match('/run:\s*npm test\b/', $ci) === 1, 'ci.yml runs npm test in the web job');
    foreach (glob($repo . '/.github/workflows/*.yml') ?: [] as $file) {
        $yaml = (string) file_get_contents($file);
        $on = preg_match('/^on:\s*\n((?:[ \t]+.*\n|\s*\n)*)/m', $yaml, $m) === 1 ? $m[1] : '';
        ok(preg_match('/^\s+(push|pull_request|pull_request_target):/m', $on) !== 1, basename($file) . ' has no push / pull_request trigger');
    }
});

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
