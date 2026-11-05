<?php

declare(strict_types=1);

namespace Aicountly\Api\Controllers;

use Aicountly\Api\Auth;
use Aicountly\Api\Context;
use Aicountly\Api\Router;
use Aicountly\Api\Support\RateLimit;

/**
 * Shared entry work for every scoped endpoint.
 *
 * Authenticate, resolve the company scope, and CHECK THAT THIS SESSION MAY OPEN
 * THAT COMPANY — in that order, before a controller touches a row. The tenant
 * check is not optional and is not something an individual endpoint remembers to
 * do: it happens here, once, for all of them.
 */
abstract class Controller
{
    /** @return array{0: Auth, 1: Context} */
    protected static function enter(): array
    {
        $auth = Auth::require();
        $ctx = Context::fromRequest();
        $ctx->assertAllowed($auth);

        // The ceiling on the endpoints that cost something, for the same reason the tenant check
        // is here: a limit each endpoint had to remember to apply is a limit the next endpoint
        // forgets.
        $route = Router::current();
        $bucket = $route === null ? null : self::rateLimitBucket($route);
        if ($bucket !== null) {
            RateLimit::check($bucket, $auth, $ctx);
        }

        return [$auth, $ctx];
    }

    /**
     * Which ceiling a route is held to, or null for the great majority that are held to none.
     *
     * Matched by shape rather than by a list of routes, so an export added later is covered
     * without anybody remembering to add it here — the failure mode of a list being that the
     * newest, least-reviewed endpoint is the one missing from it.
     */
    protected static function rateLimitBucket(string $route): ?string
    {
        $path = str_contains($route, ' ') ? substr($route, strpos($route, ' ') + 1) : $route;

        // Anything that hands over a file: a dashboard's export, the audit log.
        if (str_ends_with($path, '/export')) {
            return RateLimit::EXPORTS;
        }
        // The one route that spends money: AskEngine classifies and narrates with a model.
        if ($path === 'v1/insights/ask') {
            return RateLimit::AI;
        }
        if ($path === 'v1/dashboard' || str_starts_with($path, 'v1/dashboard')) {
            return RateLimit::DASHBOARDS;
        }
        // Global search and the pickers behind it, each a fan-out to Books or Inventory.
        if (str_starts_with($path, 'v1/catalog/')) {
            return RateLimit::CATALOGUE;
        }

        return null;
    }
}
