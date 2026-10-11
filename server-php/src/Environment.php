<?php

declare(strict_types=1);

namespace Aicountly\Api;

/**
 * Which AICOUNTLY environment this deployment belongs to: decided by SERVER CONFIGURATION ONLY
 * (E8-ID A20 / F062 / F210 / F284 / F413).
 *
 * WHY. Every sibling base (Books, Inventory, Manage, Contacts, Pay, Pulse, ...) used to be chosen
 * from $_SERVER['HTTP_HOST'] when no *_API_BASE was set: a request carrying
 * `Host: anything.gh.aicountly.com` made a production deployment check company membership and
 * ownership against the SANDBOX Manage (which accepts the same my.aicountly.com session and has an
 * overlapping cmp_id sequence), and a CLI repair tool with no host at all talked to sandbox Books
 * with production data. The Host header is chosen by whoever sends the request. It is never read
 * here.
 *
 * THE RULE.
 *   1. AIC_ENVIRONMENT (the estate-wide name of a deployment), when set;
 *   2. else APP_ENV;
 *   production | prod | live                                   -> production
 *   sandbox | staging | development | dev | local | testing    -> sandbox (a non-production
 *                                                                 deployment never reaches production
 *                                                                 by default)
 *   unset                                                      -> production, reported by /api/health
 *                                                                 as `environment.source = default`
 *   anything else                                              -> NOT CONFIGURED: derived bases are
 *                                                                 refused (fail closed) and health says so.
 *
 * An explicit *_API_BASE still wins, as before (local stacks, a cutover). In production a base that
 * points at a sandbox or loopback host is refused, not used: a production .env copied from a sandbox
 * one must not quietly post production money to sandbox Books.
 */
final class Environment
{
    public const PRODUCTION = 'production';
    public const SANDBOX = 'sandbox';

    private const PRODUCTION_NAMES = ['production', 'prod', 'live'];
    private const SANDBOX_NAMES = ['sandbox', 'staging', 'development', 'dev', 'local', 'testing', 'test'];

    /**
     * production | sandbox, or null when the configuration names something unrecognised.
     */
    public static function name(): ?string
    {
        return self::resolve()['name'];
    }

    public static function isProduction(): bool
    {
        return self::name() === self::PRODUCTION;
    }

    /**
     * @return array{name: ?string, source: string, variable: ?string}
     *         source: aic_environment | app_env | default | unrecognised
     */
    public static function resolve(): array
    {
        foreach (['AIC_ENVIRONMENT' => 'aic_environment', 'APP_ENV' => 'app_env'] as $variable => $source) {
            $value = strtolower(trim(Env::get($variable)));
            if ($value === '') {
                continue;
            }
            if (in_array($value, self::PRODUCTION_NAMES, true)) {
                return ['name' => self::PRODUCTION, 'source' => $source, 'variable' => $variable];
            }
            if (in_array($value, self::SANDBOX_NAMES, true)) {
                return ['name' => self::SANDBOX, 'source' => $source, 'variable' => $variable];
            }

            return ['name' => null, 'source' => 'unrecognised', 'variable' => $variable];
        }

        return ['name' => self::PRODUCTION, 'source' => 'default', 'variable' => null];
    }

    /**
     * The base for a sibling product: the explicit setting when there is one, else the origin for
     * this deployment's configured environment. Null when nothing can be chosen honestly.
     */
    public static function pick(string $configured, string $production, string $sandbox): ?string
    {
        $configured = trim($configured);
        if ($configured !== '') {
            return rtrim($configured, '/');
        }

        return match (self::name()) {
            self::PRODUCTION => $production,
            self::SANDBOX    => $sandbox,
            default          => null,
        };
    }

    /**
     * Why a base must not be used by this deployment, or null when it may. A fixed code, never the URL.
     *
     *   environment_not_configured   AIC_ENVIRONMENT/APP_ENV names something unrecognised and no explicit base is set
     *   production_base_not_production  production deployment, base points at a sandbox or loopback host
     */
    public static function baseProblem(?string $base): ?string
    {
        if ($base === null || trim($base) === '') {
            return 'environment_not_configured';
        }
        if (self::isProduction() && self::isNonProductionUrl($base)) {
            return 'production_base_not_production';
        }

        return null;
    }

    /** A sandbox (*.gh.aicountly.com, gh-*.aicountly.com) or loopback/localhost URL. */
    public static function isNonProductionUrl(string $url): bool
    {
        $host = strtolower((string) (parse_url(trim($url), PHP_URL_HOST) ?? ''));
        if ($host === '') {
            return true;
        }

        return $host === 'localhost'
            || str_ends_with($host, '.localhost')
            || str_starts_with($host, '127.')
            || $host === '::1' || $host === '[::1]'
            || str_ends_with($host, '.gh.aicountly.com')
            || preg_match('/^gh-[a-z0-9-]+\.aicountly\.com$/', $host) === 1;
    }

    /**
     * For /api/health: which environment this deployment believes it is and every base problem, as
     * fixed codes and variable names. Never a key; base origins are public hostnames.
     *
     * @param array<string, ?string> $bases variable name => resolved base (null when it could not be chosen)
     * @return array{name: ?string, source: string, problems: list<array{variable: string, problem: string}>, ok: bool}
     */
    public static function report(array $bases): array
    {
        $resolved = self::resolve();
        $problems = [];
        if ($resolved['name'] === null) {
            $problems[] = ['variable' => (string) $resolved['variable'], 'problem' => 'environment_unrecognised'];
        }
        foreach ($bases as $variable => $base) {
            $problem = self::baseProblem($base);
            if ($problem !== null) {
                $problems[] = ['variable' => $variable, 'problem' => $problem];
            }
        }

        return [
            'name'     => $resolved['name'],
            'source'   => $resolved['source'],
            'problems' => $problems,
            'ok'       => $problems === [],
        ];
    }
}
