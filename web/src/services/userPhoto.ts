/**
 * The signed-in person's profile photo, for the account menu.
 *
 * The photo lives on the portal (`GET my.aicountly.com/api/user/logo`, the caller's own ses_key,
 * an image body). The browser cannot ask for it: the portal's CORS allowlist does not name
 * product domains, so a cross-site call fails with no response at all. It is read through this
 * product's own API instead — `/api/global/user/logo`, the read-only relay in server-php's front
 * controller — which is same-origin and therefore passes.
 *
 * Every failure is "no photo": nobody uploaded one, the relay is down, the portal answered with
 * something that is not an image. The caller falls back to initials. A missing avatar is never an
 * error worth a toast (AICOUNTLY_ACCOUNT_MENU.md §6).
 */

import { getApiBaseUrl } from '../config'
import { ensureSesKey } from '../auth/portal'

/** The relay path, through this product's own API. Never the portal's own origin. */
export const USER_PHOTO_PATH = 'global/user/logo'

/**
 * An object URL for the photo, or null when there is none to show.
 *
 * The caller owns the URL and must revoke it (`URL.revokeObjectURL`) when the menu unmounts;
 * leaving it alive pins the image in memory for the life of the tab.
 */
export async function fetchUserPhotoUrl(): Promise<string | null> {
  try {
    const url = `${getApiBaseUrl()}/${USER_PHOTO_PATH}`
    const pull = (key: string): Promise<Response> =>
      fetch(url, { method: 'GET', headers: { Authorization: `Bearer ${key}`, Accept: 'image/*' } })

    let response = await pull(await ensureSesKey())
    // A key can be revoked before its local expiry, exactly as on every other call.
    if (response.status === 401) {
      response = await pull(await ensureSesKey(true))
    }

    // 204 is the relay saying there is no photo; 404 is the portal saying the same.
    if (!response.ok || response.status === 204) return null

    const contentType = response.headers.get('Content-Type') ?? ''
    // A JSON error body or the hosting's challenge page is not a photo, and must not be
    // handed to an <img> as though it were.
    if (!contentType.toLowerCase().startsWith('image/')) return null

    const blob = await response.blob()
    if (blob.size === 0) return null

    return URL.createObjectURL(blob)
  } catch {
    return null
  }
}
