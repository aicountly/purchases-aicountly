<?php

declare(strict_types=1);

namespace Aicountly\Api;

use Aicountly\Api\Clients\ManageClient;

/**
 * The company / branch / financial year every scoped request carries.
 *
 * Three ids and nothing else. The names and dates behind them belong to Manage
 * and are read from Manage at the point of use.
 *
 * TENANT ISOLATION: `cmp_id` arriving in a query string is a claim, not a fact.
 * assertAllowed() checks it against what Manage says this session may open, and
 * a company the session has no access to is a 403 — never a query that simply
 * returns nothing, which would leak the difference between "no rows" and "not
 * yours" and would break the moment a query forgot its WHERE clause.
 */
final class Context
{
    /**
     * Company id + session -> the role Manage reported, memoised for the request.
     *
     * Holds the access type rather than a bare `true` so the second and later
     * calls in a request can still tell Auth what Manage said. A null entry means
     * the company checked out but Manage named no role.
     *
     * @var array<string, ?int>
     */
    private static array $verified = [];

    /**
     * The financial year's dates as Manage reported them, by "cmp:fy", for checks that
     * need them (a bill's posting date). Only for a year Manage confirmed.
     *
     * @var array<string, array{from: string, to: string}>
     */
    private static array $fyRanges = [];

    private function __construct(
        public readonly int $cmpId,
        public readonly int $fyId,
        /** 0 = consolidated, all branches. */
        public readonly int $boId,
    ) {
    }

    /** Read the scope out of the request, refusing anything incomplete. */
    public static function fromRequest(): self
    {
        $cmpId = Http::intParam('cmp_id', 0) ?? 0;
        $fyId  = Http::intParam('fy_id', 0) ?? 0;
        $boId  = Http::intParam('bo_id', 0) ?? 0;

        if ($cmpId <= 0 || $fyId <= 0) {
            Http::error(400, 'context_required', 'Pick a company and financial year first (cmp_id and fy_id are required).');
        }

        return new self($cmpId, $fyId, max(0, $boId));
    }

    /**
     * Confirm this session may open this company, per Manage.
     *
     * Memoised per request because it runs on every scoped endpoint; a failure to
     * reach Manage is a 503 and not an allow, because the alternative is serving
     * one tenant's data to another whenever Manage has a bad minute.
     */
    public function assertAllowed(Auth $auth): void
    {
        if ($auth->isService()) {
            // A service key is issued to a product, not to a person, and the
            // owning product has already checked the human behind it. What it
            // must still not do is act on a company outside the key's scope,
            // which ServiceKeyAuthenticator enforces when the key is resolved.
            return;
        }

        $key = $this->cmpId . ':' . $auth->fingerprint();
        if (array_key_exists($key, self::$verified)) {
            // Re-noted, not skipped: the memo saves the round trip, not the
            // answer. Returning here without telling Auth the role would leave
            // every request after the first one looking like a stranger.
            $auth->noteCompanyAccess($this->cmpId, self::$verified[$key]);

            return;
        }

        $result = (new ManageClient())->withSession($auth->sesKey())->companyInfo($this->cmpId);

        if (!$result['ok']) {
            // Unreachable is not "allowed". A tenant check that fails open is not
            // a tenant check.
            Http::error(503, 'context_unavailable', 'Cannot confirm company access right now. Please retry.');
        }

        $body = $result['body'] ?? [];
        $company = $body['data'] ?? $body['company'] ?? $body;
        $resolved = (int) ($company['cmp_id'] ?? $company['comp_id'] ?? $company['id'] ?? 0);

        if ($resolved !== $this->cmpId) {
            Http::forbidden('You do not have access to this company.');
        }

        // The year and branch are claims too, and the same answer settles them. A year that
        // is not this company's would scope every query to rows that belong to nobody, and
        // a posting to a year Manage does not know. Manage says nothing about a year being
        // closed — carrying balances forward does not close one — so only membership is
        // checked here; Books refuses what it will not post.
        $fyList = is_array($company['fy_list'] ?? null) ? $company['fy_list'] : null;
        if ($fyList === null) {
            Http::error(503, 'context_unavailable', 'Manage did not list this company\'s financial years, so the year cannot be confirmed. Please retry.');
        }
        $fy = null;
        foreach ($fyList as $candidate) {
            if (is_array($candidate) && (int) ($candidate['fy_id'] ?? $candidate['comp_fy_id'] ?? $candidate['id'] ?? 0) === $this->fyId) {
                $fy = $candidate;
                break;
            }
        }
        if ($fy === null) {
            Http::error(403, 'fy_not_in_company', 'That financial year is not one of this company\'s. Pick a year from the company header.');
        }
        if ($this->boId > 0) {
            $branchIds = array_map(
                static fn ($b) => is_array($b) ? (int) ($b['id'] ?? $b['bo_id'] ?? $b['branch_id'] ?? 0) : 0,
                is_array($company['branch_list'] ?? null) ? $company['branch_list'] : [],
            );
            if (!in_array($this->boId, $branchIds, true)) {
                Http::error(403, 'branch_not_in_company', 'That branch is not one of this company\'s.');
            }
        }
        $from = substr((string) ($fy['fy_start'] ?? ''), 0, 10);
        $to = substr((string) ($fy['fy_end'] ?? ''), 0, 10);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) === 1 && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) === 1) {
            self::$fyRanges[$this->cmpId . ':' . $this->fyId] = ['from' => $from, 'to' => $to];
        }

        // The same answer, read twice. Manage was already asked whether this
        // session may open this company; it also says in what capacity, and
        // discarding that was why a company owner arrived here with no
        // permissions. One call, both questions — no second round trip, and
        // nothing about a role stored in this product's database.
        $accessType = CompanyAccess::fromPayload(is_array($company) ? $company : []);
        if ($accessType === null) {
            $accessType = CompanyAccess::fromPayload(is_array($body) ? $body : []);
        }
        if ($accessType === null) {
            error_log(sprintf(
                '[context] Manage reported no role for company %d; owner access will not apply. Payload keys: %s',
                $this->cmpId,
                implode(',', array_slice(array_keys(is_array($company) ? $company : []), 0, 20)),
            ));
        }

        $auth->noteCompanyAccess($this->cmpId, $accessType);
        self::$verified[$key] = $accessType;
    }

    /**
     * The scope a stored command was first sent under.
     *
     * A retry must send the same body its first attempt sent — Inventory and Books refuse
     * a reused idempotency key whose body differs — and the company context is part of
     * that body. Only for replaying a command of a company this request has already been
     * authorised for; never built from request input.
     */
    public static function of(int $cmpId, int $fyId, int $boId): self
    {
        return new self($cmpId, $fyId, max(0, $boId));
    }

    /** Drop the memoised company checks. Tests only — a request never needs it. */
    public static function forgetVerified(): void
    {
        self::$verified = [];
        self::$fyRanges = [];
    }

    /**
     * This year's dates, as Manage reported them when it confirmed the year; null when this
     * request has not asked (a service caller, or a replayed command's scope).
     *
     * @return array{from: string, to: string}|null
     */
    public function fyRange(): ?array
    {
        return self::$fyRanges[$this->cmpId . ':' . $this->fyId] ?? null;
    }

    /** @return array{cmp_id:int, fy_id:int, bo_id:int} */
    public function asQuery(): array
    {
        return ['cmp_id' => $this->cmpId, 'fy_id' => $this->fyId, 'bo_id' => $this->boId];
    }

    /** @return array{cmp_id:int, fy_id:int, bo_id:int} */
    public function asBody(): array
    {
        return $this->asQuery();
    }

    /**
     * The WHERE fragment and bindings every query in this product starts with.
     *
     * `bo_id` 0 means consolidated, so it narrows only when it is set — a branch
     * user sees their branch, a company user sees everything.
     *
     * @return array{0:string, 1:array<string, mixed>}
     */
    public function scopeClause(string $alias = ''): array
    {
        $prefix = $alias === '' ? '' : $alias . '.';
        $sql = $prefix . 'cmp_id = :ctx_cmp_id AND ' . $prefix . 'fy_id = :ctx_fy_id';
        $params = ['ctx_cmp_id' => $this->cmpId, 'ctx_fy_id' => $this->fyId];

        if ($this->boId > 0) {
            $sql .= ' AND (' . $prefix . 'bo_id = :ctx_bo_id OR ' . $prefix . 'bo_id = 0)';
            $params['ctx_bo_id'] = $this->boId;
        }

        return [$sql, $params];
    }
}
