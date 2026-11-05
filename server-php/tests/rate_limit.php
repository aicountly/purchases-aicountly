<?php

declare(strict_types=1);

/**
 * The ceiling on the endpoints that cost something (Support\RateLimit).
 *
 * Purchases had no rate limiting of any kind before this — not on the authenticated routes, and not on
 * the portal auth relay. What these checks pin is the half that is easy to get wrong when it is
 * copied between products: WHICH of this app's routes are held to WHICH ceiling. The policy
 * itself (how an override is read, how long a client is told to wait) is pinned here too, because
 * a ceiling nobody can configure correctly is a ceiling somebody switches off.
 *
 *   php server-php/tests/rate_limit.php
 *
 * No database and no network: the route map and the settings are pure.
 */

$root = dirname(__DIR__);
require $root . '/src/Env.php';
require $root . '/src/Autoload.php';

use Aicountly\Api\Controllers\Controller;
use Aicountly\Api\Support\RateLimit;

$passed = 0;
$failed = 0;

function check(string $name, callable $fn): void
{
    global $passed, $failed;
    try {
        $fn();
        echo "  ok    {$name}\n";
        $passed++;
    } catch (\Throwable $e) {
        echo "  FAIL  {$name}\n        " . $e->getMessage() . "\n";
        $failed++;
    }
}

function assertSame(mixed $expected, mixed $actual, string $what): void
{
    if ($expected !== $actual) {
        throw new \RuntimeException(sprintf('%s: expected %s, got %s', $what, var_export($expected, true), var_export($actual, true)));
    }
}

function assertTrue(bool $condition, string $what): void
{
    if (!$condition) {
        throw new \RuntimeException($what);
    }
}

/** Controller::rateLimitBucket() is protected; the map it holds is the thing worth pinning. */
function bucketFor(string $route): ?string
{
    static $method = null;
    $method ??= new \ReflectionMethod(Controller::class, 'rateLimitBucket');

    return $method->invoke(null, $route);
}

/** Run $fn with one PURCHASE_RATE_LIMIT_* set, and put it back afterwards. */
function withRateLimitEnv(string $bucket, string $value, callable $fn): void
{
    $name = 'PURCHASE_RATE_LIMIT_' . strtoupper($bucket);
    putenv($name . '=' . $value);
    try {
        $fn();
    } finally {
        putenv($name);
    }
}

echo "\nRate limiting — the ceiling on what costs something\n";

check('every costly route is held to a ceiling, matched by shape rather than by a list', function () {
    assertSame(RateLimit::DASHBOARDS, bucketFor('GET v1/dashboard'), 'the landing dashboard');
    assertSame(RateLimit::DASHBOARDS, bucketFor('GET v1/dashboards/{view}'), 'a dashboard');
    assertSame(RateLimit::CATALOGUE, bucketFor('GET v1/catalog/items/search'), 'global search');
    assertSame(RateLimit::CATALOGUE, bucketFor('GET v1/catalog/suppliers'), 'the supplier picker');

    // The one route that spends money, and it wins over the prefix it sits under.
    assertSame(RateLimit::AI, bucketFor('POST v1/insights/ask'), 'asking AI');

    // Anything that hands over a file.
    foreach (['GET v1/dashboards/{view}/export', 'GET v1/audit-log/export'] as $route) {
        assertSame(RateLimit::EXPORTS, bucketFor($route), $route . ' is an export');
    }

    // An export of a dashboard is an export, not a dashboard: the suffix is tested first.
    assertSame(RateLimit::EXPORTS, bucketFor('GET v1/dashboards/{view}/export'), 'the suffix wins over the prefix');
});

check('an ordinary route is held to no ceiling at all', function () {
    foreach (['GET v1/session', 'POST v1/orders', 'GET v1/claims', 'GET v1/settings', 'GET v1/access/catalogue'] as $route) {
        assertSame(null, bucketFor($route), $route . ' is not throttled');
    }
});

check('a bucket keeps its shipped default when nothing overrides it', function () {
    [$limit, $window] = RateLimit::settings(RateLimit::EXPORTS);
    assertSame(30, $limit, 'exports default limit');
    assertSame(300, $window, 'exports default window');
    assertSame(240, RateLimit::settings(RateLimit::DASHBOARDS)[0], 'dashboards default limit');
    assertSame(20, RateLimit::settings(RateLimit::AI)[0], 'the AI ceiling is the tightest');
    assertSame(300, RateLimit::settings(RateLimit::AI)[1], 'over five minutes');
});

check('an override is read as limit/seconds, or as a bare limit keeping the window', function () {
    withRateLimitEnv('exports', '5/30', function () {
        assertSame([5, 30], array_slice(RateLimit::settings(RateLimit::EXPORTS), 0, 2), 'limit and window');
    });
    withRateLimitEnv('exports', '7', function () {
        assertSame([7, 300], array_slice(RateLimit::settings(RateLimit::EXPORTS), 0, 2), 'a bare limit keeps the default window');
    });
});

check('a typo in an override is ignored rather than guessed at', function () {
    foreach (['abc', '5/', '/30', '5 per 30', '-4', ''] as $nonsense) {
        withRateLimitEnv('exports', $nonsense, function () use ($nonsense) {
            assertSame(30, RateLimit::settings(RateLimit::EXPORTS)[0], 'the default survives ' . var_export($nonsense, true));
        });
    }
});

check('0 switches a bucket off, which is the only way to switch one off', function () {
    withRateLimitEnv('exports', '0', function () {
        assertSame(0, RateLimit::settings(RateLimit::EXPORTS)[0], 'nothing is counted');
    });
});

check('an unknown bucket refuses rather than allows — a typo in the code is not an open door', function () {
    assertSame(1, RateLimit::settings('nonesuch')[0], 'an unknown bucket is held to one');
});

check('Retry-After is the time left in THIS window, and is never zero', function () {
    assertSame(60, RateLimit::retryAfter(60, 600), 'exactly on a boundary, the whole window rather than nothing');
    assertSame(45, RateLimit::retryAfter(60, 615), 'a quarter in, three quarters left');
    assertSame(1, RateLimit::retryAfter(60, 659), 'the last second of a window');
    for ($t = 0; $t < 400; $t++) {
        assertTrue(RateLimit::retryAfter(60, $t) > 0, 'never zero, at t=' . $t);
    }
});

check('the wait is said in words a person reads', function () {
    assertSame('1 second', RateLimit::inWords(1), 'one second');
    assertSame('45 seconds', RateLimit::inWords(45), 'under a minute');
    assertSame('1 minute', RateLimit::inWords(60), 'exactly a minute');
    assertSame('2 minutes', RateLimit::inWords(61), 'rounded up, never down');
    assertSame('5 minutes', RateLimit::inWords(300), 'five minutes');
});

check('the AI ceiling is tighter than every other, because it is the one that spends money', function () {
    $ai = RateLimit::settings(RateLimit::AI);
    foreach ([RateLimit::DASHBOARDS, RateLimit::CATALOGUE, RateLimit::EXPORTS] as $bucket) {
        $other = RateLimit::settings($bucket);
        assertTrue(
            $ai[0] / $ai[1] < $other[0] / $other[1],
            'AI is tighter per second than ' . $bucket,
        );
    }
});


echo "\n------------------------------------------------------------\n";
echo "{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
