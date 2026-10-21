/**
 * Manage's company registry (`/api/v1/*`), for this product.
 *
 * The browser reaches Manage through Purchases' own API (`/api/manage/v1/*`, server-php
 * src/ManageRelay.php) with the person's own session key, not directly: the hosting's bot
 * shield answers some networks (common Indian broadband) with a challenge page that has no
 * CORS headers, so a cross-site call to manage.aicountly.com failed with no response, while a
 * same-origin call passes. The server forwards the call unchanged and grants nothing. Nothing
 * here is stored: the directory and the access lists are read live from Manage, and the
 * server keeps deciding access on every scoped request (Context::assertAllowed asks
 * Manage companyinfo for `purchases`).
 *
 * Discovery is not access: a company listed here may still be closed to this person in
 * Purchases. Use `isOpenable()` before selecting one.
 */

import { ensureSesKey } from '../auth/portal'
// The client module directly, not the package index: the index also carries the access
// panels, which only the lazily loaded Users & Access page needs.
import { createCompanyRegistryClient } from '../company-access/companyRegistryClient.ts'
import { getApiBaseUrl } from '../config'

/** Manage's catalog id for this product. */
export const COMPANY_REGISTRY_APP_KEY = 'purchases'

/** How the product is named on the shared access screens. */
export const COMPANY_REGISTRY_APP_NAME = 'Aicountly Purchases'

/**
 * The person's Bearer key, minted on demand, with one silent retry on a 401 — a key can be
 * revoked before its local expiry, exactly as api.ts handles it for this product's own API.
 */
async function registryFetch(url: string, init: RequestInit): Promise<Response> {
  const withKey = (key: string): RequestInit => ({
    ...init,
    headers: { ...(init.headers as Record<string, string> | undefined), Authorization: `Bearer ${key}` },
  })

  const response = await fetch(url, withKey(await ensureSesKey()))
  if (response.status !== 401) return response

  return fetch(url, withKey(await ensureSesKey(true)))
}

export const companyRegistry = createCompanyRegistryClient({
  appKey: COMPANY_REGISTRY_APP_KEY,
  baseUrl: `${getApiBaseUrl()}/manage`,
  fetchImpl: registryFetch,
})
