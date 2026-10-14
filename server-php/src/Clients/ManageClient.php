<?php

declare(strict_types=1);

namespace Aicountly\Api\Clients;

/**
 * Live reads against manage.aicountly.com.
 *
 * Manage owns the company, the branch and the financial year. This product
 * stores `cmp_id`, `bo_id` and `fy_id` as bare references and nothing else — no
 * company name, no branch master, no financial-year dates. A screen that needs
 * the company's name or the year's start date asks here, on that request.
 *
 * That is not pedantry: a financial year whose dates were copied once is a year
 * that keeps the old dates after somebody corrects them, and every document
 * dated near the boundary then lands in the wrong year.
 */
final class ManageClient extends ApiClient
{
    private string $authorization = '';

    public function service(): string
    {
        return 'manage';
    }

    protected function productionBase(): string
    {
        return 'https://manage.aicountly.com';
    }

    protected function sandboxBase(): string
    {
        return 'https://manage.gh.aicountly.com';
    }

    protected function baseEnvKey(): string
    {
        return 'MANAGE_API_BASE';
    }

    public function withSession(string $sesKey): self
    {
        $this->authorization = 'Bearer ' . $sesKey;

        return $this;
    }

    /**
     * Company, its branches and its financial years, in one call.
     *
     * Deliberately one call and memoised for the request: the context check runs
     * on every scoped endpoint, and asking per endpoint would put Manage in the
     * hot path of every screen this product draws.
     */
    public function companyInfo(int $cmpId): array
    {
        return $this->request('GET', 'companyinfo' . self::query(['comp_id' => $cmpId]), null, ['Authorization' => $this->authorization]);
    }

    /**
     * Where a company stands in Manage's data lifecycle for ONE product, asked product-to-product:
     * GET internal/company-status?ids=<id>&app=<app> with MANAGE_PRODUCT_SERVICE_KEY. Read for
     * commands held because that product answered 423 COMPANY_RESTRICTED (RestrictedTarget).
     * known false: no key, no answer, or an answer not in Manage's shape — never read as "active".
     *
     * @return array{known: bool, status: ?string, lifecycle: ?string, restricted_until: ?string}
     */
    public function companyLifecycle(int $cmpId, string $app): array
    {
        $unknown = ['known' => false, 'status' => null, 'lifecycle' => null, 'restricted_until' => null];
        $key = trim(\Aicountly\Api\Env::get('MANAGE_PRODUCT_SERVICE_KEY'));
        if ($key === '' || str_starts_with($key, 'CHANGE_ME') || $cmpId <= 0) {
            return $unknown;
        }
        $result = $this->request('GET', 'internal/company-status' . self::query(['ids' => (string) $cmpId, 'app' => strtolower($app)]), null, ['Authorization' => 'Bearer ' . $key]);
        $companies = $result['ok'] ? ($result['body']['data']['companies'] ?? null) : null;
        if (!is_array($companies)) {
            return $unknown;
        }
        $row = $companies[$cmpId] ?? $companies[(string) $cmpId] ?? null;
        if (!is_array($row)) {
            return ['known' => true, 'status' => 'unknown', 'lifecycle' => null, 'restricted_until' => null];
        }

        return [
            'known'            => true,
            'status'           => is_string($row['status'] ?? null) ? strtolower($row['status']) : null,
            'lifecycle'        => is_string($row['lifecycle'] ?? null) ? strtolower($row['lifecycle']) : null,
            'restricted_until' => is_string($row['restricted_until'] ?? null) && $row['restricted_until'] !== '' ? $row['restricted_until'] : null,
        ];
    }

    /** Companies this session may open — the company switcher. */
    public function companies(array $filters = []): array
    {
        return $this->request('GET', 'companies' . self::query($filters), null, ['Authorization' => $this->authorization]);
    }
}
