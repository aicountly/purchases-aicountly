<?php

declare(strict_types=1);

/**
 * One action, in a process of its own, for the concurrency tests.
 *
 *   php tests/support/worker.php <action> '<json args>'
 *
 * Several of these are started at once against the same PostgreSQL database, so what
 * the tests observe is real contention — two PHP processes, two connections, two
 * transactions — not an interleaving simulated inside one process. Prints one line of
 * JSON: {ok, status, code, message, result}.
 */

namespace Aicountly\Api;

require __DIR__ . '/../../src/Env.php';
require __DIR__ . '/../../src/Autoload.php';

Env::load(__DIR__ . '/../../.env');

use Aicountly\Api\Domain\BillService;
use Aicountly\Api\Domain\PurchaseOrderService;
use Aicountly\Api\Domain\ReceiptService;

$action = $argv[1] ?? '';
$args = json_decode($argv[2] ?? '{}', true) ?: [];

$r = new \ReflectionClass(Context::class);
$ctx = $r->newInstanceWithoutConstructor();
foreach (['cmpId' => (int) ($args['cmp_id'] ?? 88), 'fyId' => 6, 'boId' => 0] as $prop => $value) {
    $p = $r->getProperty($prop);
    $p->setValue($ctx, $value);
}
$a = new \ReflectionClass(Auth::class);
$auth = $a->newInstanceWithoutConstructor();
foreach (['uuid' => (string) ($args['uuid'] ?? 'user-owner'), 'kind' => 'user', 'sourceApp' => 'purchases', 'sesKey' => 'stub-ses-key.role-1', 'session' => ['name' => 'worker']] as $prop => $value) {
    $p = $a->getProperty($prop);
    $p->setValue($auth, $value);
}
$auth->noteCompanyAccess($ctx->cmpId, 1);

// Start together: every worker waits for the same moment, so the race is real.
if (!empty($args['start_at'])) {
    $wait = (float) $args['start_at'] - microtime(true);
    if ($wait > 0) {
        usleep((int) ($wait * 1_000_000));
    }
}

try {
    $result = match ($action) {
        'receive'    => (new ReceiptService($ctx, $auth))->request((int) $args['po_id'], (array) ($args['input'] ?? [])),
        'enter_bill' => (new BillService($ctx, $auth))->enter((array) $args['input']),
        'post_bill'  => (new BillService($ctx, $auth))->post((int) $args['request_id']),
        'cancel_po'  => (new PurchaseOrderService($ctx, $auth))->cancel((int) $args['po_id'], ['reason' => 'race']),
        'cancel_bill' => (new BillService($ctx, $auth))->cancel((int) $args['request_id'], ['reason' => 'race']),
        'retry_receipt' => (new ReceiptService($ctx, $auth))->retry((int) $args['request_id']),
        'cancel_receipt' => (new ReceiptService($ctx, $auth))->cancel((int) $args['request_id'], ['reason' => 'race']),
        'ensure_claim' => (static function () use ($ctx, $args): array {
            $command = IntegrationCommand::ensure($ctx, 'inventory', 'test.race', 'race', (int) $args['entity_id'], ['n' => (int) ($args['n'] ?? 0)]);
            $claim = IntegrationCommand::claim((int) $command['command_id']);

            return ['command_id' => (int) $command['command_id'], 'claimed' => $claim['claimed'], 'key' => $command['idempotency_key'], 'payload' => $command['request_payload']];
        })(),
        default => throw new \InvalidArgumentException('unknown action ' . $action),
    };
    echo json_encode(['ok' => true, 'status' => 200, 'result' => $result]), "\n";
} catch (ResponseSent $e) {
    echo json_encode(['ok' => $e->status < 300, 'status' => $e->status, 'code' => $e->payload['error']['code'] ?? null, 'message' => $e->payload['error']['message'] ?? $e->payload['message'] ?? null]), "\n";
} catch (\Throwable $e) {
    echo json_encode(['ok' => false, 'status' => 0, 'code' => 'exception', 'message' => $e->getMessage()]), "\n";
}
