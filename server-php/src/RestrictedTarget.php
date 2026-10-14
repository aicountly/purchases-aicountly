<?php

declare(strict_types=1);

namespace Aicountly\Api;

use Aicountly\Api\Clients\ManageClient;

/**
 * Smart Books or Inventory holds the target company READ-ONLY (AICOUNTLY Manage's company
 * lifecycle: the company's subscription to that product lapsed — view and export only for 45 days,
 * then its data there is purged). Both refuse a write into such a company with
 *
 *     HTTP 423 {code: COMPANY_RESTRICTED, message, restricted_until, lifecycle}
 *
 * and for this product that answer means "deferred, not failed":
 *
 *   - ApiClient recognises the answer ({@see refusal()}) and gives the caller this product's
 *     sentence instead of the receiver's ({@see userMessage()}).
 *   - IntegrationCommand::attempt() keeps the command as DEFERRED_RESTRICTED (the contract's
 *     `deferred_restricted`) with restricted_until — never FAILED, UNCERTAIN or BLOCKED, and not
 *     counted as a stale command; the caller sees a retryable 'failed' outcome with the sentence.
 *   - bin/command-monitor.php (every five minutes) asks Manage about a deferred company at most
 *     hourly ({@see decide()}): renewed, or restricted_until passed → the command is sendable again
 *     (FAILED, with a sentence saying so); still read-only → it waits; the company deleted, or the
 *     receiver answering `lifecycle: purged` → DROPPED_COMPANY_DELETED with an audit entry.
 *     Nothing is lost without a trace.
 *
 * A command is sent on the person's own session (Books accepts a voucher only from a person), so
 * after renewal it goes on that person's Retry — this product never sends it on its own
 * (IntegrationCommand: "WHY NO CRON").
 */
final class RestrictedTarget
{
    public const CODE = 'COMPANY_RESTRICTED';

    /** A deferred company is looked at again at most this often. */
    public const RECHECK_SECONDS = 3600;

    private const NAMES = ['books' => 'Smart Books', 'inventory' => 'Inventory'];

    /** @var array<string, array{at: float, refusal: array{restricted_until: ?string, lifecycle: string, message: string}}> service => last refusal */
    private static array $seen = [];

    /**
     * The receiver's read-only answer, or null for any other answer.
     *
     * @return array{restricted_until: ?string, lifecycle: string, message: string}|null
     */
    public static function refusal(int $status, mixed $body): ?array
    {
        if ($status !== 423 || !is_array($body)) {
            return null;
        }
        $error = is_array($body['error'] ?? null) ? $body['error'] : [];
        $details = is_array($error['details'] ?? null) ? $error['details'] : [];
        if (($body['code'] ?? ($error['code'] ?? null)) !== self::CODE) {
            return null;
        }
        $until = $body['restricted_until'] ?? ($error['restricted_until'] ?? ($details['restricted_until'] ?? null));
        $lifecycle = $body['lifecycle'] ?? ($error['lifecycle'] ?? ($details['lifecycle'] ?? 'restricted'));
        $message = $body['message'] ?? ($error['message'] ?? null);

        return [
            'restricted_until' => is_string($until) && trim($until) !== '' ? trim($until) : null,
            'lifecycle'        => is_string($lifecycle) && $lifecycle !== '' ? strtolower($lifecycle) : 'restricted',
            'message'          => is_string($message) && trim($message) !== '' ? trim($message) : 'The company is read-only there.',
        ];
    }

    public static function isGone(array $refusal): bool
    {
        return ($refusal['lifecycle'] ?? '') === 'purged';
    }

    /** The sentence the person reads. */
    public static function userMessage(string $service, ?string $restrictedUntil, string $lifecycle = 'restricted'): string
    {
        $name = self::NAMES[strtolower($service)] ?? ucfirst($service);
        if ($lifecycle === 'purged') {
            return 'The target company\'s data in ' . $name . ' has been deleted (its ' . $name . ' subscription ended), so this cannot be sent there.';
        }
        $date = self::displayDate($restrictedUntil);

        // Not "sent automatically": a write to Books goes on a person's session, so it is that
        // person's Retry (or the document's next save) that sends it once the company is renewed.
        return 'The target company in ' . $name . ' is read-only' . ($date !== null ? ' until ' . $date : '')
            . '; this is kept and will be sent when it is renewed there — press Retry once it is.';
    }

    /** "28 Nov 2026" in India time, null when absent or unreadable. */
    public static function displayDate(?string $iso): ?string
    {
        $t = self::timestamp($iso);

        return $t === null ? null : (new \DateTimeImmutable('@' . $t))->setTimezone(new \DateTimeZone('Asia/Kolkata'))->format('j M Y');
    }

    /** Remember a refusal from $service for the attempt in flight (ApiClient). */
    public static function remember(string $service, array $refusal): void
    {
        self::$seen[strtolower($service)] = ['at' => microtime(true), 'refusal' => $refusal];
    }

    /**
     * The refusal $service gave during an attempt that started at $since, if any.
     *
     * @return array{restricted_until: ?string, lifecycle: string, message: string}|null
     */
    public static function since(string $service, float $since): ?array
    {
        $seen = self::$seen[strtolower($service)] ?? null;

        return $seen !== null && $seen['at'] >= $since ? $seen['refusal'] : null;
    }

    /** Test seam: a new request. */
    public static function forget(): void
    {
        self::$seen = [];
    }

    /** When a deferred command is looked at again: at restricted_until if within the hour, else in an hour. */
    public static function nextCheckAt(?string $restrictedUntil, int $now): int
    {
        $hour = $now + self::RECHECK_SECONDS;
        $until = self::timestamp($restrictedUntil);

        return $until !== null && $until > $now ? min($until, $hour) : $hour;
    }

    /**
     * resume | drop | wait, from Manage's word on the company for that product.
     *
     * @param array{known: bool, status?: ?string, lifecycle?: ?string, restricted_until?: ?string} $manage
     * @return array{action: string, restricted_until: ?string, next_check_at: ?int, reason: string}
     */
    public static function decide(array $manage, ?string $storedUntil, int $now): array
    {
        $wait = static fn (?string $until, string $why): array => ['action' => 'wait', 'restricted_until' => $until, 'next_check_at' => self::nextCheckAt($until, $now), 'reason' => $why];
        $resume = static fn (?string $until, string $why): array => ['action' => 'resume', 'restricted_until' => $until, 'next_check_at' => null, 'reason' => $why];
        $passed = static fn (?string $until): bool => ($t = self::timestamp($until)) !== null && $t <= $now;

        if (!($manage['known'] ?? false)) {
            return $passed($storedUntil)
                ? $resume($storedUntil, 'the read-only date has passed')
                : $wait($storedUntil, 'Manage could not be asked');
        }
        $status = strtolower((string) ($manage['status'] ?? ''));
        if (in_array($status, ['deleted', 'unknown'], true)) {
            return ['action' => 'drop', 'restricted_until' => $storedUntil, 'next_check_at' => null, 'reason' => 'the company is ' . $status . ' in Manage'];
        }
        $lifecycle = strtolower((string) ($manage['lifecycle'] ?? ''));
        $until = is_string($manage['restricted_until'] ?? null) && $manage['restricted_until'] !== '' ? $manage['restricted_until'] : $storedUntil;

        return match (true) {
            $lifecycle === 'active' && $status !== 'recycled' => $resume(null, 'the company was renewed'),
            $lifecycle === 'purge_pending'                      => $resume($until, 'its data there is being deleted; the next attempt finds out'),
            $passed($until)                                     => $resume($until, 'the read-only date has passed'),
            default                                             => $wait($until, 'the company is still ' . ($lifecycle !== '' ? $lifecycle : 'not active')),
        };
    }

    /**
     * Manage's word on one company for one product (MANAGE_PRODUCT_SERVICE_KEY).
     *
     * @return array{known: bool, status: ?string, lifecycle: ?string, restricted_until: ?string}
     */
    public static function manageStatus(int $cmpId, string $service): array
    {
        return (new ManageClient())->companyLifecycle($cmpId, $service);
    }

    public static function timestamp(?string $iso): ?int
    {
        if ($iso === null || trim($iso) === '') {
            return null;
        }
        $t = strtotime($iso);

        return $t === false ? null : $t;
    }
}
