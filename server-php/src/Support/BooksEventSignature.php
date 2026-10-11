<?php

declare(strict_types=1);

namespace Aicountly\Api\Support;

use Aicountly\Api\Env;

/**
 * The signature Books puts on a voucher event it sends this product (E8-BK-POST A16):
 *
 *   X-Books-Signature: t=<unix seconds>,v1=<hex HMAC-SHA256 of "<t>.<raw body>">
 *
 * with the secret both sides hold (BOOKS_EVENTS_SIGNING_SECRET here, BOOKS_SOURCE_EVENTS_SIGNING_SECRET
 * in Books). It proves the exact bytes came from Books; the X-Service-Key proves which product is
 * calling. A timestamp more than ten minutes away is refused, so a captured request cannot be
 * replayed later (and the receiver applies each event id once anyway).
 */
final class BooksEventSignature
{
    private const TOLERANCE_SECONDS = 600;

    public static function secret(): string
    {
        return trim(Env::get('BOOKS_EVENTS_SIGNING_SECRET'));
    }

    /** @return array{ok: bool, code?: string} */
    public static function verify(string $header, string $rawBody, ?int $now = null): array
    {
        $secret = self::secret();
        if (strlen($secret) < 16 || str_starts_with(strtoupper($secret), 'CHANGE_ME')) {
            return ['ok' => false, 'code' => 'not_configured'];
        }
        $t = null;
        $sigs = [];
        foreach (explode(',', $header) as $part) {
            $pair = explode('=', trim($part), 2);
            if (count($pair) !== 2) {
                continue;
            }
            if ($pair[0] === 't' && preg_match('/^\d{1,12}$/', $pair[1])) {
                $t = (int) $pair[1];
            } elseif ($pair[0] === 'v1' && preg_match('/^[0-9a-f]{64}$/i', $pair[1])) {
                $sigs[] = strtolower($pair[1]);
            }
        }
        if ($t === null || $sigs === []) {
            return ['ok' => false, 'code' => 'signature_missing'];
        }
        if (abs(($now ?? time()) - $t) > self::TOLERANCE_SECONDS) {
            return ['ok' => false, 'code' => 'signature_expired'];
        }
        $expected = hash_hmac('sha256', $t . '.' . $rawBody, $secret);
        foreach ($sigs as $sig) {
            if (hash_equals($expected, $sig)) {
                return ['ok' => true];
            }
        }

        return ['ok' => false, 'code' => 'signature_invalid'];
    }

    public static function sign(string $secret, string $rawBody, int $t): string
    {
        return 't=' . $t . ',v1=' . hash_hmac('sha256', $t . '.' . $rawBody, $secret);
    }
}
