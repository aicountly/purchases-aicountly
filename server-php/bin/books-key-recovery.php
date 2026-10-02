<?php

declare(strict_types=1);

/**
 * Recover what Books refused only because the Idempotency-Key was too long.
 *
 * Books keeps a key in 64 characters. Until keys were sized on the wire (src/IdempotencyKey.php),
 * every purchase-return and claim debit note — and a bill, once the company and request ids had
 * enough digits — went to Books with a longer key, was refused 400 before Books wrote anything,
 * and was recorded here as BLOCKED, which nothing ever sends again. This finds those commands for
 * ONE company, confirms with Books that it holds no voucher for each, and re-issues them under
 * the key they go out with now. See src/Domain/BooksKeyRecovery.php.
 *
 *   php bin/books-key-recovery.php --cmp=ID [--command=ID] [--json]
 *       DRY RUN, the default. Reads this product's database only and changes nothing: each
 *       command Books refused for its key's length, the key it was refused under, the keys it
 *       will be sent under, and what will be asked of Books.
 *
 *   RECOVERY_SES_KEY=… php bin/books-key-recovery.php --cmp=ID --check [--command=ID] [--json]
 *       Also asks Books — read-only, as the person whose portal session that is — whether it
 *       holds a posted voucher under each document's reference, or a draft from the document.
 *       Changes nothing.
 *
 *   RECOVERY_SES_KEY=… php bin/books-key-recovery.php --cmp=ID --apply --reason="…" [--command=ID] [--json]
 *       Asks Books again (never trusts an earlier run), and re-issues ONLY the commands Books
 *       confirms it holds nothing for: re-armed and sent through the same operation the screen
 *       runs, as that person, under the new key. Anything Books holds something for, or could not
 *       answer about, is left exactly as it is and reported. Running it again is safe: a command
 *       re-issued is no longer listed, and one re-armed whose send did not happen is sent then.
 *
 * The session is read from the environment, never from the command line (where it would reach
 * the shell history and the process list), and it is never printed. It must belong to a person
 * of the company who may post bills and raise debit notes here and in Books — usually the owner.
 *
 * Exit status: 0 nothing (left) to recover · 3 commands remain for a person to look at · 1 error.
 */

namespace Aicountly\Api;

require __DIR__ . '/../src/Env.php';
require __DIR__ . '/../src/Autoload.php';

Env::load(__DIR__ . '/../.env');

use Aicountly\Api\Clients\BooksClient;
use Aicountly\Api\Domain\BooksKeyRecovery;

$opts = getopt('', ['cmp:', 'command:', 'check', 'apply', 'reason:', 'json', 'help']);
if (isset($opts['help'])) {
    fwrite(STDOUT, "See the header of bin/books-key-recovery.php.\n");
    exit(0);
}

$cmpId = (int) ($opts['cmp'] ?? 0);
if ($cmpId <= 0) {
    fwrite(STDERR, "Name the company: --cmp=ID. This works on one company at a time.\n");
    exit(1);
}
$commandId = isset($opts['command']) ? (int) $opts['command'] : null;
$apply = isset($opts['apply']);
$check = $apply || isset($opts['check']);
$reason = trim((string) ($opts['reason'] ?? ''));
$json = isset($opts['json']);

if ($apply && $reason === '') {
    fwrite(STDERR, "Say why: --reason=\"…\" is recorded against every command re-issued.\n");
    exit(1);
}

try {
    Db::connect();
} catch (\Throwable $e) {
    fwrite(STDERR, "Cannot connect: {$e->getMessage()}\n");
    exit(1);
}

$auth = null;
$books = null;
if ($check) {
    $sesKey = trim((string) (getenv('RECOVERY_SES_KEY') ?: ''));
    if ($sesKey === '') {
        fwrite(STDERR, "Asking Books needs a person's portal session in RECOVERY_SES_KEY (Books takes no service key for vouchers).\n");
        exit(1);
    }
    $auth = Auth::cliSession($sesKey);
    if ($auth === null) {
        fwrite(STDERR, "The portal did not accept the session in RECOVERY_SES_KEY. Sign in again and use the new one.\n");
        exit(1);
    }
    $books = (new BooksClient())->withSession($sesKey);
}

$mode = $apply ? 'apply' : ($check ? 'check' : 'dry_run');
$report = [
    'generated_at' => gmdate('c'),
    'cmp_id'       => $cmpId,
    'mode'         => $mode,
    'actor'        => $auth?->uuid,
    'reason'       => $apply ? $reason : null,
    'commands'     => [],
];

foreach (BooksKeyRecovery::candidates($cmpId, $commandId) as $candidate) {
    $entry = $candidate;
    unset($entry['stored_key']);
    $entry['books_check'] = null;
    $entry['result'] = null;

    if ($check) {
        $entry['books_check'] = BooksKeyRecovery::checkBooks($books, $candidate);
    }
    if ($apply) {
        $entry['result'] = $entry['books_check']['verdict'] === 'no_voucher'
            ? BooksKeyRecovery::reissue($auth, $candidate, $reason)
            : ['outcome' => 'left_alone', 'detail' => $entry['books_check']['detail'], 'command_status' => $candidate['status'], 'reference' => []];
    }
    $report['commands'][] = $entry;
}

$remaining = count(array_filter(
    $report['commands'],
    static fn (array $c) => ($c['result']['outcome'] ?? null) !== 'reissued',
));

if ($json) {
    fwrite(STDOUT, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    exit($remaining > 0 ? 3 : 0);
}

$title = [
    'dry_run' => 'DRY RUN — reads this database only; nothing is changed and Books is not asked',
    'check'   => 'CHECK — Books is asked, read-only; nothing is changed',
    'apply'   => 'APPLY — re-issues what Books confirms it holds nothing for',
][$mode];
fwrite(STDOUT, sprintf("Books key-length recovery, company %d\n%s\n\n", $cmpId, $title));
if ($report['commands'] === []) {
    fwrite(STDOUT, "No command of this company is blocked by the length of its Idempotency-Key.\n");
    exit(0);
}
foreach ($report['commands'] as $c) {
    fwrite(STDOUT, sprintf(
        "  #%d  %s for %s %s  (year %d, branch %d)  %s%s\n",
        $c['command_id'],
        $c['what'],
        str_replace('_', ' ', $c['entity_type']),
        $c['document_no'] ?? ('#' . $c['entity_id']),
        $c['fy_id'],
        $c['bo_id'],
        $c['status'],
        $c['rearmed_earlier'] ? ' (re-armed by an earlier run, not yet sent)' : '',
    ));
    fwrite(STDOUT, sprintf("       refused under  %s  (%d characters)\n", $c['refused_key'], strlen($c['refused_key'])));
    fwrite(STDOUT, sprintf("       sent now as    %s  /  %s\n", $c['wire_keys']['draft'], $c['wire_keys']['post']));
    if ($c['books_check'] !== null) {
        fwrite(STDOUT, sprintf("       Books          %s — %s\n", $c['books_check']['verdict'], $c['books_check']['detail']));
        foreach ($c['books_check']['matches'] as $m) {
            fwrite(STDOUT, '                      found: ' . json_encode($m, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
        }
    } else {
        fwrite(STDOUT, sprintf("       Books          not asked (--check). Would look for a posted voucher %s and drafts from %s #%d.\n", $c['bill_ref'], $c['source']['source_document_type'], $c['source']['source_document_id']));
    }
    if ($c['result'] !== null) {
        fwrite(STDOUT, sprintf("       result         %s — %s\n", $c['result']['outcome'], $c['result']['detail']));
        if ($c['result']['reference'] !== []) {
            fwrite(STDOUT, '                      Books: ' . json_encode($c['result']['reference'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
        }
    }
    fwrite(STDOUT, "\n");
}
fwrite(STDOUT, match ($mode) {
    'dry_run' => sprintf("%d command(s). Run with --check to ask Books, then --apply --reason=\"…\" to re-issue.\n", count($report['commands'])),
    'check'   => sprintf("%d command(s). --apply --reason=\"…\" re-issues those Books holds nothing for.\n", count($report['commands'])),
    default   => sprintf("%d re-issued, %d left for a person to look at.\n", count($report['commands']) - $remaining, $remaining),
});
exit($remaining > 0 ? 3 : 0);
