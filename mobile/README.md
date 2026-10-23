# Purchases — the phone app

`Purchases - Aicountly` for Android and iPhone (bundle / package `com.aicountly.purchases`, scheme
`aicountlypurchases`). Expo SDK 57, Expo Router, TypeScript strict. It talks to this repository's
API (`server-php`, `https://purchase.aicountly.com/api`) with the person's own AICOUNTLY session,
exactly as the web app does, so every permission and segregation-of-duties rule on the server
applies to the phone unchanged.

## What it is for

An approvals and receiving tool, not a small copy of the web app. The first signed-in screen is
**the desk**, with two queues:

| Queue | Who sees it | What they do |
|---|---|---|
| **To approve** | holders of `po.approve` / `requisition.approve` (`GET v1/approvals`) | open a PO, a PO amendment or a requisition, read its lines and the reason it needs them, approve, or reject with a reason |
| **To receive** | holders of `receipt.request` (open POs that are `APPROVED`/`ISSUED`/`ACKNOWLEDGED`/`PARTIALLY_RECEIVED`, soonest promised first) | count a delivery against the order: scan item barcodes, tap ±, reject damaged goods with a reason, capture batch / expiry / serials, record the goods receipt once |

Underneath, a dock: **Track orders** (search by PO number or supplier; Open / Overdue / Awaiting
approval / All; an order's route — approved → issued → acknowledged → received → billed → closed —
its lines and its receipts, with Check / Retry for a receipt Inventory has not confirmed) and
**Suppliers** (Smart Books creditor ledgers; a supplier's standing, open orders and what the books
say is owed, by due date).

## Phone scope (`mobileScope`)

Decided from the web app's modules (`web/src/App.tsx`) and what each asks of the person.
The list lives in [`src/config/mobileScope.ts`](src/config/mobileScope.ts); Account → **On the web**
shows the same list in the app, and a test keeps the two lists apart.

On the phone:

- Approvals (purchase orders, amendments, requisitions)
- Goods receipts against a purchase order
- Order tracking (with receipt Check / Retry)
- Supplier lookup (standing, open orders, Books dues)

On the web, and why:

| Module | Why it stays on the web |
|---|---|
| Writing purchase orders | Many lines, each with item, unit, warehouse, rate, discount and tax category checked against Smart Books and Inventory. `PurchaseOrderService::create` validates all of them together; that is a desk job. |
| Issuing, amending, short-closing, cancelling | Changes the commitment to a supplier, with the PO document and its version history. |
| Requisitions and sourcing (RFQ, quotes, award) | Comparing several suppliers' quotes needs a wide screen. |
| Bills and three-way match | Supplier invoices matched line by line and posted to Smart Books. |
| Returns, debit notes, claims | Accounting documents that settle bills. |
| Receipt returns and reversals | Undoing stock already in Inventory is a correction made with the whole order in view. |
| Dashboards, reports, statements, Ask | Tables, charts and exports. |
| Settings, access profiles, Users & Access, audit log | Administration. |
| Importing statements and documents | File upload and column mapping. |

## Photos of damage — not in this version

The brief asked for a photo of damaged goods *if the server can store attachments*. It cannot:
Purchases keeps no attachments of its own (no attachment table or upload route in `server-php`),
and it has no Drive integration — no `document-links` call anywhere in the web app or the server
(launch-readiness audit P-P2-1). A photo sent from the phone straight to Drive would be filed
where no Purchases screen shows it. So the app takes no photos, asks for no photo-library
permission, and records damage as the **rejected quantity with its reason** plus the receipt's
**inspection note** — both stored on the goods receipt and sent to Inventory. When Purchases gains
Drive `document-links` on `purchases:receipt_request:{id}`, the receive screen is where a photo goes.

## Server endpoint added for the phone

`GET v1/suppliers/{id}/position` (`server-php/src/Controllers/SupplierPositionController.php`,
tests `server-php/tests/supplier_position.php`): one supplier's procurement profile, open orders and
Smart Books open items, under the Suppliers dashboard drawer's rules (`supplier.view`; orders need
`po.view`; values and dues need `cost.view` or `reports.view`). The dashboard already answered this,
but only after computing the whole company's supplier matrix. Read-only. Every other call is a route
the web already uses.

## Sign-in, session, account

- Email + password (`POST my.aicountly.com/api/login`), **Sign in with Apple** on iOS
  (`POST /api/auth/social_login`, audience `com.aicountly.purchases`), and Google through the portal
  in the system browser with **PKCE** (`/login/social_start/google?response_type=code…`, then
  `POST /api/sso/exchange`). A callback carrying a bare `auth_token` is refused.
- The sign-in token is kept in SecureStore; the 15-minute session key (`POST /api/seskey`,
  refresh `/api/seskey/refresh`) only in memory. A 401 is retried once with a refreshed key; a
  second one signs the person out.
- A closed account met at sign-in offers **Reopen my account** (the same sign-in with
  `reactivate: true`).
- **Account → Delete my AICOUNTLY account** uses `GET /api/auth/account/closure-summary` and
  `POST /api/auth/account/delete` (never retried); an older identity service hands off to
  https://aicountly.com/delete-account.
- Forgotten password opens the portal's own page in the browser; sign-up is not offered — a person
  uses Purchases in a company someone has shared with them.

## Refusals

| Answer | What the person sees |
|---|---|
| 402 plan limit | exactly "This company's plan limit is reached. Contact your company administrator." |
| 423 company restricted | a read-only banner; writes refused with the same sentence |
| 403 `company_access_denied` / `no_purchases_access` | "no access here", with Choose a company |
| 403 `sod_reason_required` | a sheet asking why the owner decides on their own document; the decision is sent again with the reason |
| 409 `vendor_not_approved` (holder of `supplier.approve`) | a sheet for the one-order override reason |
| 422 over tolerance (holder of `receipt.over_tolerance`) / 409 `duplicate_supplier_dc` | a sheet for the reason the server asks for |
| 502 `inventory_unavailable` / `inventory_uncertain` | "Saved, not yet in Inventory" with Check and Retry — never a recount |
| no connection | reads show what was loaded before, with a banner; writes are refused on the phone, nothing is queued |

A goods receipt is sent with a `client_token` (and `Idempotency-Key`) made once per count, so a
resend after a lost answer is recognised by the server instead of recorded twice. The count itself is
kept on the phone (AsyncStorage, per company and order, two days) until it is recorded or discarded,
and cleared on sign-out.

## Look

Its own "goods-in desk" (`src/ui/theme.ts`): cool stone-grey surfaces (`#DCDFE3`, near-white cards), graphite bars
(`#26282D`) on every signed-in screen and on the welcome strip, a deep plum (`#6E2A80`) for the one thing to do next,
and plain signal colours for status — approve green, reject red, late amber, in-transit teal. Body type is **Public
Sans**; document numbers, codes and quantities are **JetBrains Mono**. Shapes are square: cards with 3-point corners
and a graphite cap along the top edge, status chips with a square lamp and an uppercase label, square buttons. Its
own SVG glyphs (`src/ui/Icon.tsx`) and welcome scenes (`src/ui/illustrations.tsx`); a desk with a queue switch and a
dock rather than tabs.

The icon is an orchid arrow dropping into an open stone-white tray that carries a graphite barcode, on a deep plum
field. The icon, adaptive icon (plum background), themed monochrome icon and splash (plum) are drawn from vector shapes
by `npm run brand:assets` (`scripts/generate-brand-assets.mjs`).

How it differs from the other AICOUNTLY phone apps: Sales is navy and coral on a cool off-white with Space Grotesk +
DM Sans, rounded cards and a light icon (a speech bubble with a price tag); Insights is warm paper, ink and ember with
Newsreader, and an ink icon (a sun on a horizon). Books, Billing, Inventory, Notes and Drive are green, green/blue,
royal blue, indigo and blue-indigo; none uses plum, Public Sans or JetBrains Mono.

## Develop

```bash
cd mobile
npm ci
cp .env.example .env          # sandbox API by default
npx expo start                # needs a development build (camera, Apple sign-in)
npx tsc --noEmit && npx eslint . && npx jest
node scripts/check-store-copy.mjs && node --test scripts/check-store-copy.test.mjs
npx expo export --platform android --output-dir .export-android
```

Release: [`docs/RELEASE_RUNBOOK.md`](docs/RELEASE_RUNBOOK.md). App Review: [`docs/APP_REVIEW_READINESS.md`](docs/APP_REVIEW_READINESS.md).
