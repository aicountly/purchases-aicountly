# Release runbook — Purchases phone app

Everything here is started by hand. No workflow runs on push, and nothing is submitted to a store
without the steps below.

## One-time set-up

1. **EAS project.** `cd mobile && npx eas-cli@latest init` links the app to an EAS project and writes
   `extra.eas.projectId` into `app.json`. Commit that change.
2. **Repository secrets** (Settings → Secrets and variables → Actions):
   - `EXPO_TOKEN` — an Expo access token for the account that owns the project.
   - `ASC_APP_ID` — App Store Connect → the Purchases app → App Information → Apple ID (a number).
   - `GOOGLE_PLAY_SERVICE_ACCOUNT_JSON` — a Play service account with release rights on
     `com.aicountly.purchases`.
3. **Repository variable** `IOS_SUBMISSION_APPROVED` — leave unset (or `false`). It is set to `true`
   only when the founder clears this app for iOS (AICOUNTLY's iOS apps are released one at a time).
4. **Store records.** Create `com.aicountly.purchases` in App Store Connect (iPhone only) and in Play
   Console. Upload the first Android build by hand: Play accepts API uploads only after that.
5. **Apple capabilities.** Sign in with Apple enabled for the bundle id (EAS does this from
   `ios.usesAppleSignIn`). The identity service must accept the audience `com.aicountly.purchases`.
6. **Portal registration (my-aicountly-com).** Add to `ProductRegistry` under `purchases`:
   `'schemes' => ['aicountlypurchases'], 'native_pkce' => 'required'`, and deploy the portal.
   Until then Google sign-in from the phone cannot return to the app (email and Apple are unaffected).

## Each release

1. `cd mobile && npm ci && npx tsc --noEmit && npx eslint . && npx jest && node scripts/check-store-copy.mjs`.
2. Bump `expo.version` in `app.json` for a user-visible release (build numbers are remote:
   `appVersionSource: remote`, `autoIncrement` on production).
3. Actions → **Mobile EAS build (Android)** / **(iOS)** → profile `preview` for testers (sandbox API),
   `production` for the stores. Each runs the checks first.
4. Test the preview build against the sandbox with the demo account (APP_REVIEW_READINESS.md):
   approve, reject with reason, receive with a scan and a rejection, reconcile a stuck receipt,
   supplier dues, close-account screen (do not finish closing the demo account).
5. Actions → **Mobile EAS submit** with the EAS build id:
   - Android → internal track, draft. Promote in Play Console.
   - iOS → refused unless `IOS_SUBMISSION_APPROVED` is `true`; then TestFlight. App Review is sent from
     App Store Connect after the review notes are pasted (fill `[DEMO_EMAIL]` / `[DEMO_PASSWORD]` there,
     and check the filled text with `node scripts/check-store-copy.mjs --final <file outside the repo>`).

## Server side for this release

- Deploy `server-php` with `GET v1/suppliers/{id}/position` before the app reaches anyone; an older
  API answers 404 and the supplier screen shows its error state.
- The API is called from phones directly (no browser, no CORS). If cPanel's Imunify360 WebShield
  challenges mobile networks, the app sees a non-JSON answer and reports Purchases as unavailable:
  allow-list the API path for the app's `X-Source-App: purchases-mobile` traffic or disable the
  challenge for `/api`.

## Rollback

Stores cannot un-ship a binary. Halt a staged Play rollout in Play Console; on iOS, remove the
version from sale or submit the previous build again. The server endpoint is read-only and additive;
leaving it deployed is harmless.
