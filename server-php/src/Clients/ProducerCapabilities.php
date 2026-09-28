<?php

declare(strict_types=1);

namespace Aicountly\Api\Clients;

use Aicountly\Api\Auth;
use Aicountly\Api\Context;
use Aicountly\Api\Http;

/**
 * What Books and Inventory say they can do, asked before relying on it.
 *
 * A bill that settles a physical GRN must reach Books and Inventory as a purchase that
 * moves NO stock — the goods came in at the GRN. A Books that does not know that stock
 * effect used to fall back to receiving the goods on the invoice, and an Inventory that
 * does not know it treats it as an ordinary receipt: either way the goods would be in
 * stock twice, silently. So the bill is not sent until both have said they settle a
 * physical challan, and refuse a stock effect they do not know. An older deployment of
 * either answers 404 here, and the bill waits with that reason on it instead of posting.
 *
 * Asked once per request; the answer is not stored.
 */
final class ProducerCapabilities
{
    /** @var array<string, array<string, mixed>|null> */
    private static array $memo = [];

    /**
     * Refuse, with the reason, unless Books and Inventory both settle a physical GRN from
     * a purchase (and a physical return from a debit note) without moving stock again.
     *
     * @param list<string> $effects the stock effects this posting relies on, e.g. ['11:from_physical_challan']
     */
    public static function requireStockEffects(Context $ctx, Auth $auth, array $effects): void
    {
        $missing = self::missing($ctx, $auth, $effects);
        if ($missing !== []) {
            Http::error(
                409,
                'producer_capability_missing',
                'Smart Books and Inventory must both be on the release that settles a goods receipt from the supplier\'s bill before this can be posted — otherwise the goods would be received a second time. '
                . implode(' ', $missing),
                ['missing' => $missing, 'retryable' => true],
            );
        }
    }

    /**
     * @param list<string> $effects "<books vch type>:<effect>" pairs
     * @return list<string> what is missing, as sentences
     */
    public static function missing(Context $ctx, Auth $auth, array $effects): array
    {
        $books = self::read('books', $ctx, $auth);
        $inventory = self::read('inventory', $ctx, $auth);

        $missing = [];
        if ($books === null) {
            $missing[] = 'Smart Books did not report its capabilities.';
        } elseif (empty($books['refuses_unknown_stock_effect'])) {
            $missing[] = 'Smart Books does not refuse a stock effect it does not know.';
        }
        if ($inventory === null) {
            $missing[] = 'Inventory did not report its capabilities.';
        } elseif (empty($inventory['validates_stock_effect'])) {
            $missing[] = 'Inventory does not validate stock effects.';
        }

        $inventoryType = [11 => 'PURCHASE_RECEIPT', 3 => 'PURCHASE_RETURN'];
        foreach ($effects as $pair) {
            [$vchType, $effect] = explode(':', $pair, 2) + [1 => ''];
            if ($books !== null && !in_array($effect, (array) ($books['stock_effects'][(string) $vchType] ?? []), true)) {
                $missing[] = sprintf('Smart Books does not accept stock effect "%s" on voucher type %s.', $effect, $vchType);
            }
            $docType = $inventoryType[(int) $vchType] ?? null;
            if ($inventory !== null && $docType !== null && !in_array($effect, (array) ($inventory['stock_effects'][$docType] ?? []), true)) {
                $missing[] = sprintf('Inventory does not accept stock effect "%s" on %s.', $effect, $docType);
            }
        }

        return $missing;
    }

    /** Tests only. */
    public static function forget(): void
    {
        self::$memo = [];
    }

    /** @return array<string, mixed>|null */
    private static function read(string $service, Context $ctx, Auth $auth): ?array
    {
        $key = $service . ':' . $ctx->cmpId;
        if (array_key_exists($key, self::$memo)) {
            return self::$memo[$key];
        }

        $response = $service === 'books'
            ? (new BooksClient())->withSession($auth->sesKey())->capabilities($ctx)
            : (new InventoryClient())->withService($auth->uuid)->capabilities($ctx);

        $data = $response['ok'] ? ($response['body']['data'] ?? null) : null;

        return self::$memo[$key] = is_array($data) ? $data : null;
    }
}
