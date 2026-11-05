<?php

declare(strict_types=1);

namespace Aicountly\Api\Support;

use Aicountly\Api\Auth;
use Aicountly\Api\Context;
use Aicountly\Api\Env;
use Aicountly\Api\Http;

/**
 * A ceiling on the requests one signed-in person can make to the endpoints that cost something.
 *
 * Purchases had NO rate limiting of any kind before this: not on the authenticated routes, and
 * not on the portal auth relay in `index.php` either. Both halves arrive together — the relay is
 * limited per client address (the shape that fits a caller who has not signed in yet, since the
 * portal sees this server's address for every relayed call and would otherwise hold one bucket
 * for everybody), and the routes below are limited per signed-in person.
 *
 * WHAT THIS IS FOR, and what it is not. It is not a defence against a determined attacker with a
 * stolen session: that is what the permissions and the company gate are for. It is a ceiling that
 * no real person reaches and a runaway script does — a loop left running against the dashboards,
 * a scraper walking the purchase ledger out through CSV, a client retrying a failed AI call
 * forever. Each costs a PHP worker, a fan-out to Smart Books or Inventory, or real money, and
 * each is cheaper to stop here than to explain afterwards.
 *
 * THE LIMITS ARE DELIBERATELY GENEROUS. A limit a busy purchase desk can hit during a rush is
 * worse than no limit at all, because it fails at exactly the moment the desk needs it. Every one
 * below is set where a person cannot plausibly reach it, and every one is configurable per
 * deployment without a release.
 *
 * WHO IS COUNTED. The signed-in person, by their session fingerprint — never the ses_key, which
 * must not reach a cache key — and, for the load-related buckets, the company they are working
 * in, because the cost of a dashboard is a fan-out to that company's books. EXPORTS and AI are
 * counted per PERSON ALONE: what is limited there is how much data leaves and how much money is
 * spent, and neither gets three allowances because somebody has access to three companies.
 *
 * ANOTHER AICOUNTLY SERVER IS NOT COUNTED. A sibling product calling with its own service key is
 * doing work a person asked for somewhere else, at a volume this ceiling was not set for, and a
 * false positive there breaks a cross-product feature rather than slowing an abuser down. Service
 * keys are held to the service-key rules instead (`ServiceKeys`).
 *
 * `v1/insights/ask` is the one route here that spends money: `Ai\AskEngine` classifies the
 * question and narrates the answer with a model. It gets the tightest ceiling of the four.
 *
 * IT FAILS OPEN. A cache directory that cannot be written refuses nothing: `Throttle::allow()`
 * returns true on any disk trouble. A broken disk must never stop a desk raising an order.
 *
 * CONFIGURATION (server-php/.env), one per bucket, `limit/seconds` or a bare limit:
 *
 *   PURCHASE_RATE_LIMIT_DASHBOARDS  default 240/60
 *   PURCHASE_RATE_LIMIT_CATALOGUE   default 240/60
 *   PURCHASE_RATE_LIMIT_EXPORTS     default 30/300
 *   PURCHASE_RATE_LIMIT_AI          default 20/300
 *
 * `0` switches a bucket off entirely.
 */
final class RateLimit
{
    public const DASHBOARDS = 'dashboards';
    public const CATALOGUE = 'catalogue';
    public const EXPORTS = 'exports';
    public const AI = 'ai';

    /**
     * bucket => [limit, window seconds, what the person was doing, per-company?]
     *
     * @var array<string, array{0: int, 1: int, 2: string, 3: bool}>
     */
    private const BUCKETS = [
        // A dashboard refresh reads several of these.
        self::DASHBOARDS => [240, 60, 'opening dashboards', true],
        // Global search is debounced in the browser, so typing a supplier name costs a handful.
        self::CATALOGUE => [240, 60, 'searching', true],
        // A dashboard taken away as a file, and the audit log.
        self::EXPORTS => [30, 300, 'exporting data', false],
        // `v1/insights/ask` reaches a model through AskEngine, and that costs real money.
        self::AI => [20, 300, 'asking AI', false],
    ];

    /**
     * Count one request against `$bucket` and refuse it with 429 once the ceiling is passed.
     *
     * Returns normally when the request may proceed; never returns when it may not.
     */
    public static function check(string $bucket, Auth $auth, ?Context $ctx = null): void
    {
        // Another AICOUNTLY server is not a person and is not counted as one.
        if ($auth->isService()) {
            return;
        }
        [$limit, $window, $doing, $perCompany] = self::settings($bucket);
        if ($limit <= 0) {
            return;
        }

        $key = $bucket . '|' . $auth->fingerprint();
        if ($perCompany && $ctx !== null) {
            $key .= '|' . $ctx->cmpId;
        }
        if (Throttle::allow('ratelimit', $key, $limit, $window)) {
            return;
        }

        Http::error(
            429,
            'rate_limited',
            sprintf(
                'You have been %s a lot in a short time. Wait about %s and try again.',
                $doing,
                self::inWords(self::retryAfter($window)),
            ),
            ['retry_after' => self::retryAfter($window), 'limit' => $limit, 'window_seconds' => $window],
        );
    }

    /**
     * The bucket's limit and window, after any per-deployment override.
     *
     * An override that cannot be read as a limit is ignored rather than guessed at: a typo in a
     * .env must not silently switch a ceiling off, nor set it to something nobody intended.
     *
     * @return array{0: int, 1: int, 2: string, 3: bool}
     */
    public static function settings(string $bucket): array
    {
        $defaults = self::BUCKETS[$bucket] ?? null;
        if ($defaults === null) {
            // An unknown bucket is a programming mistake, not a request to allow everything.
            return [1, 60, 'doing that', true];
        }
        $configured = trim(Env::get('PURCHASE_RATE_LIMIT_' . strtoupper($bucket)));
        if ($configured === '') {
            return $defaults;
        }
        if (!preg_match('/^(\d+)(?:\s*\/\s*(\d+))?$/', $configured, $m)) {
            return $defaults;
        }
        $limit = (int) $m[1];
        $window = isset($m[2]) ? max(1, (int) $m[2]) : $defaults[1];

        return [$limit, $window, $defaults[2], $defaults[3]];
    }

    /** Seconds until this fixed window ends — what a client should actually wait. */
    public static function retryAfter(int $window, ?int $now = null): int
    {
        $window = max(1, $window);
        $now ??= time();

        return $window - ($now % $window) ?: $window;
    }

    /** "30 seconds" / "2 minutes" — for a sentence a person reads, not a header. */
    public static function inWords(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds . ($seconds === 1 ? ' second' : ' seconds');
        }
        $minutes = (int) ceil($seconds / 60);

        return $minutes . ($minutes === 1 ? ' minute' : ' minutes');
    }
}
