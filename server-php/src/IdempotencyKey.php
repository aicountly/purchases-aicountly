<?php

declare(strict_types=1);

namespace Aicountly\Api;

/**
 * The Idempotency-Key this product puts ON THE WIRE, derived from the key it stored.
 *
 * An integration command stores its LOGICAL key — `purchases:{cmp}:{command}:{entity}:{id}:r{rev}`
 * (IntegrationCommand::key) — and a call adds the step it is (`:draft`, `:post`). Each product
 * keeps a caller's key in a column of its own width, and treats a longer one its own way:
 *
 *   books      VARCHAR(64) (books_idempotency_keys, books_voucher_drafts); a longer key is a
 *              400 `idempotency_key_too_long`, answered before anything is written
 *   inventory  silently CUT to 128 (BaseController::idempotencyKey)
 *   contacts   1–255 visible ASCII; anything else is a 400
 *   manage     takes no key; the fleet's narrowest width is assumed
 *
 * That is how every purchase-return and claim debit note came to be refused for good: its
 * logical key plus `:draft` is 66 characters or more, Books answered 400, a 400 is a business
 * refusal (BLOCKED), and the key is deterministic, so no retry could ever succeed. A bill of a
 * company or request id with enough digits crossed the same line.
 *
 * A silent cut is as bad as a refusal — two long keys that share a head become one key, and
 * the second operation replays the first one's answer — so a key is never cut here, it is
 * COMPRESSED: a readable head of the original and a sha256 of the WHOLE thing, sized to the
 * product's limit exactly. The step suffix is inside the hash, so a draft and its post never
 * share a key.
 *
 * DETERMINISTIC, because the key is the whole defence against a double post: a retry derives
 * the wire key again from the same stored key and suffix and arrives at the same bytes. A key
 * that already fits is sent UNCHANGED, so an operation already accepted under its plain key (a
 * bill of a small company, an Inventory receipt) is still recognised by the product that holds
 * it. A key that did not fit was never recorded by Books — it refused it before writing — so
 * moving it to the compressed form loses nothing.
 *
 * The same scheme as sales-aicountly's IdempotencyKey::forWire, so one rule reads every
 * product's keys in Books' tables.
 */
final class IdempotencyKey
{
    public const BOOKS     = 64;
    public const INVENTORY = 128;
    public const CONTACTS  = 255;

    /** The narrowest width in the fleet, for a product whose limit we do not know. */
    public const DEFAULT = 64;

    /** What every product accepts. */
    private const SAFE = '/^[A-Za-z0-9._:-]+$/';

    /** Shorter keys are compressed too: some products ignore a very short key. */
    private const MIN = 8;

    /** Hex digits of sha256 kept in a compressed key: 160 bits. */
    private const HASH_HEX = 40;

    /** The width of the key column in the product named (ApiClient::service()). */
    public static function limitFor(string $service): int
    {
        return match ($service) {
            'books'     => self::BOOKS,
            'inventory' => self::INVENTORY,
            'contacts'  => self::CONTACTS,
            default     => self::DEFAULT,
        };
    }

    /**
     * The key to send for this stored key (plus a step suffix such as `:draft`), no longer than
     * $limit, the same bytes every time for the same input.
     */
    public static function forWire(string $stored, string $suffix = '', int $limit = self::DEFAULT): string
    {
        $key = $stored . $suffix;
        if ($key === '') {
            return '';
        }
        if (self::fits($key, $limit)) {
            return $key;
        }

        $hash = hash('sha256', $key);
        $headLength = $limit - self::HASH_HEX - 1;
        if ($headLength < 1) {
            return substr($hash, 0, max(self::MIN, $limit));
        }
        $head = substr((string) preg_replace('/[^A-Za-z0-9._:]/', '_', $key), 0, $headLength);

        // The head is only for a person reading a log; the hash covers every byte, so two keys
        // that share a head are still two keys.
        return $head . '-' . substr($hash, 0, self::HASH_HEX);
    }

    /** Whether a key is sent as it is (true) or compressed (false). */
    public static function fits(string $key, int $limit = self::DEFAULT): bool
    {
        return strlen($key) <= $limit && strlen($key) >= self::MIN && preg_match(self::SAFE, $key) === 1;
    }
}
