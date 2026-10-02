<?php
/**
 * Purchases ↔ Aicountly Contacts, against a REAL Contacts (and real Manage), not the stub.
 *
 *   CONTACTS_CONTRACT_BASE=http://127.0.0.1:19800/api \
 *   CONTACTS_CONTRACT_FAULTY_BASE=http://127.0.0.1:19805/api \
 *   MANAGE_API_BASE=http://127.0.0.1:19801 \
 *   SES_A=… SES_B=… SES_D=… php tests/contacts_contract.php
 *
 * Built for the ecosystem e2e harness (/home/user/e2e, `source run/<id>/stack.env`): A owns
 * company 501, B is a member, D was removed. Skips (exit 0, says so) when CONTACTS_CONTRACT_BASE
 * is unset, so the ordinary suite never needs a Contacts. Uses the Purchases test database from
 * server-php/.env (tests/run.sh writes it) for the profile cache and the audit trail.
 *
 * What it settles that the stub cannot: the URL Purchases composes reaches Contacts' route, the
 * by-reference LIST, lookup, references POST/DELETE, resolve, Contacts' error codes, and what a
 * Contacts outage looks like to a Purchases user.
 */
declare(strict_types=1);

namespace Aicountly\Api;

require __DIR__ . '/../src/Env.php';
require __DIR__ . '/../src/Autoload.php';
Env::load(__DIR__ . '/../.env');

use Aicountly\Api\Clients\ContactsClient;
use Aicountly\Api\Domain\SupplierContactService;

$base = getenv('CONTACTS_CONTRACT_BASE') ?: '';
if ($base === '') {
    echo "contacts contract: skipped (CONTACTS_CONTRACT_BASE unset)\n";
    exit(0);
}
putenv('CONTACTS_API_BASE=' . $base);
if (getenv('MANAGE_API_BASE')) {
    putenv('MANAGE_API_BASE=' . getenv('MANAGE_API_BASE'));
}
$cmp = (int) (getenv('CONTRACT_CMP') ?: 501);
$ledger = 970000 + random_int(1, 9999); // a ledger id no earlier run used

$passed = 0;
$failed = 0;
function check(string $name, callable $fn): void
{
    global $passed, $failed;
    Context::forgetVerified();
    try {
        $fn();
        echo "  ok    {$name}\n";
        $passed++;
    } catch (\Throwable $e) {
        echo "  FAIL  {$name}\n        {$e->getMessage()}\n";
        $failed++;
    }
}
function same(mixed $expected, mixed $actual, string $what): void
{
    if ($expected !== $actual) {
        throw new \RuntimeException($what . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}
/** @return array{status:int, code:?string, message:string, details:array<string,mixed>} */
function refused(callable $fn, string $code, string $what): array
{
    try {
        $fn();
    } catch (ResponseSent $e) {
        $got = (string) ($e->payload['error']['code'] ?? '');
        if ($got !== $code) {
            throw new \RuntimeException($what . ': expected ' . $code . ', got ' . $got . ' (' . $e->getMessage() . ')');
        }

        return ['status' => $e->status, 'code' => $got, 'message' => $e->getMessage(), 'details' => (array) ($e->payload['error']['details'] ?? [])];
    }
    throw new \RuntimeException($what . ': expected a refusal, none came');
}
/** A signed-in person as the portal knows them, with Manage asked for real by Context::assertAllowed. */
function person(string $uuid, string $sesKey): Auth
{
    $r = new \ReflectionClass(Auth::class);
    $auth = $r->newInstanceWithoutConstructor();
    foreach (['uuid' => $uuid, 'kind' => 'user', 'sourceApp' => 'purchases', 'sesKey' => $sesKey, 'session' => ['name' => $uuid]] as $prop => $value) {
        $r->getProperty($prop)->setValue($auth, $value);
    }

    return $auth;
}
/** Raw call to real Contacts, as a person — to set the scene, never as the thing under test. */
function contacts(string $method, string $path, string $sesKey, ?array $body = null): array
{
    $ch = curl_init(rtrim((string) getenv('CONTACTS_CONTRACT_BASE'), '/') . '/' . ltrim($path, '/'));
    $headers = ['Authorization: Bearer ' . $sesKey, 'Accept: application/json'];
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20]);
    $raw = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    return ['status' => $status, 'body' => json_decode($raw, true)];
}

$sesA = (string) getenv('SES_A');
$sesB = (string) getenv('SES_B');
$sesD = (string) getenv('SES_D');
$ownerA = person('101', $sesA);
$ctx = Context::of($cmp, (int) (getenv('CONTRACT_FY') ?: 1), 0);
$ctx->assertAllowed($ownerA); // real Manage: A owns the company

$suffix = substr(bin2hex(random_bytes(3)), 0, 6);
$gstin = '27AAPFU' . str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT) . 'F1ZV';
$made = contacts('POST', "companies/{$cmp}/contacts", $sesA, ['displayName' => 'Deccan Steel ' . $suffix, 'organizationName' => 'Deccan Steel Traders', 'emails' => [['value' => "orders.{$suffix}@deccan.example"]], 'taxIds' => [['type' => 'GSTIN', 'value' => $gstin]]]);
$second = contacts('POST', "companies/{$cmp}/contacts", $sesA, ['displayName' => 'Konkan Metals ' . $suffix, 'emails' => [['value' => "ravi.{$suffix}@konkan.example"]]]);
$deccan = (string) ($made['body']['data']['id'] ?? '');
$konkan = (string) ($second['body']['data']['id'] ?? '');
echo "setup: contacts {$deccan} ({$made['status']}), {$konkan} ({$second['status']}); ledger {$ledger}\n";

echo "\nPurchases → real Contacts\n";

check('the composed URL reaches Contacts\' company route (no /api/api)', function () use ($cmp) {
    $url = (new ContactsClient())->urlFor($cmp, '/by-reference');
    same(1, substr_count($url, '/api/'), 'one /api in ' . $url);
    $raw = contacts('GET', "api/companies/{$cmp}/contacts/by-reference?product=books&ref_type=ledger_account&ref=1", (string) getenv('SES_A'));
    same(404, $raw['status'], 'the old /api/api address is a 404 at real Contacts');
});

check('a wrong Contacts address is a set-up fault (502 contacts_route_missing), never "no contact" — real Contacts envelopes that 404', function () use ($ctx, $ownerA, $ledger) {
    putenv('CONTACTS_API_BASE=' . rtrim((string) getenv('CONTACTS_CONTRACT_BASE'), '/') . '/api');
    try {
        $r = refused(fn () => (new SupplierContactService($ctx, $ownerA))->contactFor($ledger), 'contacts_route_missing', 'doubled /api');
    } finally {
        putenv('CONTACTS_API_BASE=' . getenv('CONTACTS_CONTRACT_BASE'));
    }
    same(502, $r['status'], 'a set-up fault on our side');
});

check('an unlinked ledger is "no contact" — by-reference is a list, empty', function () use ($ctx, $ownerA, $ledger) {
    $card = (new SupplierContactService($ctx, $ownerA))->contactFor($ledger);
    same(false, $card['linked'], 'not linked');
});

check('the picker finds company contacts by name, GSTIN and e-mail through company routes', function () use ($ctx, $ownerA, $deccan, $suffix, $gstin) {
    $svc = new SupplierContactService($ctx, $ownerA);
    $byName = $svc->candidates('Deccan Steel ' . $suffix);
    same(true, in_array($deccan, array_column($byName['data'], 'id'), true), 'by name (meta.total ' . ($byName['meta']['total'] ?? '?') . ')');
    $byTax = $svc->candidates($gstin);
    same('tax_id', $byTax['meta']['searched_by'], 'a GSTIN is a lookup');
    same([$deccan], array_column($byTax['data'], 'id'), 'exactly that contact');
    $byMail = $svc->candidates("orders.{$suffix}@deccan.example");
    same([$deccan], array_column($byMail['data'], 'id'), 'by e-mail');
});

check('link, read back, unlink and link again — Contacts really holds each state', function () use ($ctx, $ownerA, $deccan, $ledger) {
    $svc = new SupplierContactService($ctx, $ownerA);
    $linked = $svc->link($ledger, ['contact_id' => $deccan]);
    same(true, $linked['linked'], 'linked');
    same('active', $linked['state'], 'active');
    same(true, $linked['reference_id'] !== null, 'Contacts names the reference');
    same(false, $svc->unlink($ledger)['linked'], 'unlinked in Contacts');
    same(true, $svc->link($ledger, ['contact_id' => $deccan])['linked'], 'linked again — a new attempt, a new key, a real link');
});

check('a ledger linked elsewhere is refused by Contacts and passed on', function () use ($ctx, $ownerA, $konkan, $ledger) {
    refused(fn () => (new SupplierContactService($ctx, $ownerA))->link($ledger, ['contact_id' => $konkan]), 'supplier_contact_conflict', 'reference_conflict');
});

check('an archived contact is shown archived (real resolve) and cannot be linked', function () use ($ctx, $ownerA, $cmp, $deccan, $konkan, $ledger, $sesA) {
    $archived = contacts('PATCH', "companies/{$cmp}/contacts/{$deccan}/archive", $sesA);
    same(200, $archived['status'], 'archived in Contacts');
    $card = (new SupplierContactService($ctx, $ownerA))->contactFor($ledger);
    same('archived', $card['state'], 'the card says archived');
    $r = refused(fn () => (new SupplierContactService($ctx, $ownerA))->link($ledger + 1, ['contact_id' => $deccan]), 'contact_archived', 'archived contact');
    same(409, $r['status'], 'a refusal, not an outage');
    same(false, (new SupplierContactService($ctx, $ownerA))->unlink($ledger)['linked'], 'the archived link can be removed from Purchases');
    same(true, (new SupplierContactService($ctx, $ownerA))->link($ledger, ['contact_id' => $konkan])['linked'], 'and replaced');
});

check('a merged-away contact is refused with the survivor to link instead', function () use ($ctx, $ownerA, $cmp, $sesA, $suffix) {
    $tax = '29AAPFU' . str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT) . 'F1ZV';
    $p = contacts('POST', "companies/{$cmp}/contacts", $sesA, ['displayName' => 'Pune Bearings ' . $suffix, 'taxIds' => [['type' => 'GSTIN', 'value' => $tax]]]);
    $q = contacts('POST', "companies/{$cmp}/contacts", $sesA, ['displayName' => 'Pune Bearings Two ' . $suffix, 'taxIds' => [['type' => 'GSTIN', 'value' => $tax]], 'resolution' => 'create_new']);
    $pid = (string) ($p['body']['data']['id'] ?? '');
    $qid = (string) ($q['body']['data']['id'] ?? '');
    $pairs = contacts('GET', "companies/{$cmp}/contact-duplicates", $sesA);
    $pair = null;
    foreach ((array) ($pairs['body']['data'] ?? []) as $row) {
        $ids = [strtolower((string) ($row['contactA']['id'] ?? $row['contact_a_id'] ?? '')), strtolower((string) ($row['contactB']['id'] ?? $row['contact_b_id'] ?? ''))];
        if (in_array($pid, $ids, true) && in_array($qid, $ids, true)) {
            $pair = (string) ($row['id'] ?? '');
        }
    }
    same(true, $pair !== null && $pair !== '', 'Contacts queued the duplicate pair (create ' . $p['status'] . '/' . $q['status'] . ')');
    $merge = contacts('POST', "companies/{$cmp}/contact-duplicates/{$pair}/resolve", $sesA, ['action' => 'merge', 'into' => $pid]);
    same(200, $merge['status'], 'merged in Contacts');
    $r = refused(fn () => (new SupplierContactService($ctx, $ownerA))->link(990001, ['contact_id' => $qid]), 'contact_merged', 'merged contact');
    same($pid, $r['details']['into'] ?? null, 'the survivor is named');
});

check('a removed member is told Contacts refuses them — not "no contact"', function () use ($ledger) {
    $d = person('104', (string) getenv('SES_D'));
    $ctxD = Context::of((int) (getenv('CONTRACT_CMP') ?: 501), (int) (getenv('CONTRACT_FY') ?: 1), 0);
    $d->noteCompanyAccess($ctxD->cmpId, 1); // pretend Purchases let D in; Contacts must still refuse
    refused(fn () => (new SupplierContactService($ctxD, $d))->contactFor($ledger), 'contacts_forbidden', 'D is not in the company per Manage');
});

check('a member is held to their Contacts role: a viewer is refused by role, an editor links', function () use ($ledger, $konkan, $cmp) {
    $role = (string) (contacts('GET', "companies/{$cmp}/contacts?per_page=1", (string) getenv('SES_B'))['body']['access']['role'] ?? '');
    $b = person('102', (string) getenv('SES_B'));
    $ctxB = Context::of($cmp, (int) (getenv('CONTRACT_FY') ?: 1), 0);
    $b->noteCompanyAccess($cmp, 1); // Purchases lets B manage suppliers; Contacts decides its own role
    if ($role === 'viewer') {
        $r = refused(fn () => (new SupplierContactService($ctxB, $b))->link($ledger + 7, ['contact_id' => $konkan]), 'contacts_role_insufficient', 'viewer');
        same(403, $r['status'], 'a role refusal (Contacts says B is ' . $role . ')');
    } else {
        same(true, (new SupplierContactService($ctxB, $b))->link($ledger + 7, ['contact_id' => $konkan])['linked'], 'B (' . $role . ') links');
    }
    echo "        (Contacts role of B in {$cmp}: {$role})\n";
});

$faulty = getenv('CONTACTS_CONTRACT_FAULTY_BASE') ?: '';
if ($faulty !== '') {
    check('Contacts down (fault proxy 503) is "unavailable", retryable — not "no contact"', function () use ($ctx, $ownerA, $ledger, $faulty) {
        putenv('CONTACTS_API_BASE=' . $faulty);
        try {
            $r = refused(fn () => (new SupplierContactService($ctx, $ownerA))->contactFor($ledger), 'contacts_unavailable', 'outage');
            same(true, (bool) ($r['details']['retryable'] ?? false), 'retryable');
        } finally {
            putenv('CONTACTS_API_BASE=' . getenv('CONTACTS_CONTRACT_BASE'));
        }
    });
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
