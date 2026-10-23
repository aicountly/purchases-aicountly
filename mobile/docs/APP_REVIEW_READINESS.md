# App Review readiness — Purchases

Status on 23 Oct 2026. Each line says what is done in this repository and what is still open.

| Guideline | What it needs | State |
|---|---|---|
| 2.1 App completeness | A reviewer can sign in and reach every feature | **Open:** demo account (below) does not exist yet |
| 2.3 Accurate metadata | Listing describes only what the app does | Done: `store/app-store-listing.md`, guarded by `scripts/check-store-copy.mjs` |
| 3.1.1 In-app purchase | No prices, plans, upgrades or coupons | Done: no such wording; a plan-limit refusal shows only "This company's plan limit is reached. Contact your company administrator." (`src/core/problems.ts`, tests in `src/__tests__/http.test.ts`) |
| 4.2 Minimum functionality | Real phone work, not a web wrapper | Done: native desk, approvals, barcode receiving with offline-safe counts, tracking, supplier dues |
| 4.3(a) Spam | Distinct product, code and design | Done in code: written from scratch for Purchases (own auth screens, components, theme, icons, navigation, onboarding); own look reworked in October 2026 so it shares no palette, typeface, card shape or icon composition with Sales or Insights (see "Distinctness" below); review notes name no other app. Keep it that way: do not share screens with other apps |
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

## Distinctness (App Review 4.3(a))

Apple rejected all five earlier AICOUNTLY apps under 4.3(a). Sales, Purchases and Insights were first built with
look-alike identities (warm paper, IBM Plex, a dark icon with a tilted paper slip), so Purchases was reworked:

| | Before (1.0.0 draft) | Now |
| --- | --- | --- |
| Surfaces | Warm paper `#F3EEE4` | Cool stone grey `#DCDFE3`, near-white cards `#F8F9FA` |
| Bars | Ink black `#1B1A17` | Graphite `#26282D` |
| Accent | Saffron `#E3A008` | Deep plum `#6E2A80` (orchid `#D3A6E0` on graphite) |
| Status | Approve green, reject red, late amber, steel blue | Approve green, reject red, late amber, in-transit teal |
| Type | IBM Plex Sans + IBM Plex Mono | Public Sans + JetBrains Mono (codes and quantities) |
| Cards | Dockets with a perforated left edge, bordered stamps | Square cards with a graphite top cap; status chips with a square lamp |
| Icon | Tilted parchment docket and saffron stamp on ink black | Orchid arrow dropping into a stone tray with a graphite barcode, on plum |
| First screen | Paper, saffron kicker | Graphite strip with orchid kicker over stone grey; square-cornered scenes |

Nearest colour in the older apps (CIEDE2000; under about 5 reads as "the same colour", over 10 as clearly different):

| Purchases colour | Nearest in Books, Billing, Inventory, Notes, Drive | Nearest in Insights |
| --- | --- | --- |
| Stone ground `#DCDFE3` | Inventory background `#F3F6FB` (5.0) | paper `#F6F1E7` (8.2) |
| Graphite `#26282D` | Inventory text `#0F1B2D` (8.5) | ink `#1E1B16` (8.1) |
| Plum `#6E2A80` | Notes primary dark `#3730A3` (13.2) | slate `#2F64A8` (27.1) |
| Approve `#13784A` | Billing primary `#187A12` (10.4) | ink `#1E1B16` (35.0) |
| Reject `#C0271D` | Notes amber `#F59E0B` (38.3) | ember `#B9531C` (11.8) |
| Late `#9A5200` | Notes amber `#F59E0B` (28.2) | ember `#B9531C` (9.4) |
| In transit `#0F6E7A` | Inventory primary `#1E5BD8` (24.9) | slate `#2F64A8` (19.7) |

Near-white grounds are close in every app by nature; the identity is carried by the bars, accent, type, shapes and
icon. Against Sales: plum vs coral 42.9, graphite vs navy 15.6.
