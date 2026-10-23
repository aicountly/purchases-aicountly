# App Review readiness — Purchases

Status on 23 Oct 2026. Each line says what is done in this repository and what is still open.

| Guideline | What it needs | State |
|---|---|---|
| 2.1 App completeness | A reviewer can sign in and reach every feature | **Open:** demo account (below) does not exist yet |
| 2.3 Accurate metadata | Listing describes only what the app does | Done: `store/app-store-listing.md`, guarded by `scripts/check-store-copy.mjs` |
| 3.1.1 In-app purchase | No prices, plans, upgrades or coupons | Done: no such wording; a plan-limit refusal shows only "This company's plan limit is reached. Contact your company administrator." (`src/core/problems.ts`, tests in `src/__tests__/http.test.ts`) |
| 4.2 Minimum functionality | Real phone work, not a web wrapper | Done: native desk, approvals, barcode receiving with offline-safe counts, tracking, supplier dues |
| 4.3(a) Spam | Distinct product, code and design | Done in code: written from scratch for Purchases (own auth screens, components, theme, icons, navigation, onboarding); review notes name no other app. Keep it that way: do not share screens with other apps |
| 4.8 Login services | Sign in with Apple wherever another social sign-in appears | Done: Apple's own button on iOS next to Google |
| 5.1.1(i) Privacy | Privacy policy link; data use disclosed | Done: links on sign-in and Account; privacy manifest in `app.json` |
| 5.1.1(v) Account deletion | Delete the account from inside the app | Done: Account → Delete my AICOUNTLY account (`src/app/delete-account.tsx`), web hand-off only when the identity service lacks the endpoint |
| 5.1.2 Data use | Permission strings only for what is used | Done: camera only (barcode reading). No microphone, photo library, location or Face ID strings |
| Encryption | Export compliance | `ITSAppUsesNonExemptEncryption: false` |
| Device family | iPhone only unless iPad is designed for | `supportsTablet: false` |

## Privacy manifest (app.json → ios.privacyManifests)

- Tracking: none.
- Required-reason APIs: UserDefaults `CA92.1` (AsyncStorage / SecureStore), file timestamp `C617.1`,
  disk space `E174.1`, system boot time `35F9.1` (React Native and Expo modules).
- Collected data (linked, not tracking, app functionality): name, email address, user ID, other user
  content (approvals, receipts and their notes).

## Demo account (needed before any submission)

App Review and Play review need an **email + password** account (OTP is not offered, Apple/Google
accounts cannot be shared). Create it in production and enter it only in App Store Connect / Play
Console, never in the repository:

- A company named **Demo Traders** (financial year covering the review date) where the account has
  Purchases access with `po.approve`, `requisition.approve`, `receipt.request`, `po.view`,
  `supplier.view` and `cost.view` (an owner holds all of them).
- Raised by **another** member (so segregation of duties does not ask the reviewer for a reason):
  two purchase orders in **Awaiting approval** (one to reject, one to approve) above the company's
  approval threshold.
- One **issued** purchase order with three stock lines, items in Inventory that have UPC barcodes
  (print the barcodes into the order notes so a reviewer can scan them from a second screen), and
  one line batch-tracked.
- A supplier ledger in Smart Books with at least one posted, unpaid bill (so "Suppliers" shows dues).
- Reset after each review: the approved order and the receipt change the data. Keep a second issued
  order ready, or re-seed.

## Still open (outside this repository)

1. The demo account and its data (above).
2. Portal: register the `aicountlypurchases` scheme with `native_pkce: required` for `purchases`
   (my-aicountly-com `ProductRegistry`), otherwise Google sign-in from the phone cannot finish.
3. Identity service: accept `com.aicountly.purchases` as a Sign in with Apple audience.
4. App Store Connect / Play records, EAS project id, secrets (RELEASE_RUNBOOK.md).
5. Founder's release order: iOS submission stays blocked by `IOS_SUBMISSION_APPROVED` until this
   app's turn.
6. Screenshots (six, listed in the listing) from a preview build with the demo company.
