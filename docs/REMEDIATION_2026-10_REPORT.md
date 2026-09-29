# Purchase remediation — final report (2026-09-29)

Scope: the procurement-to-payment audit of 2026-09-27, implemented against current code for the
2026-10-11 soft launch. Finding-by-finding status and evidence: [`REMEDIATION_2026-10.md`](REMEDIATION_2026-10.md).
Nothing was deployed, no production record was touched, no supplier was contacted and no payment
was initiated. The work is merged into `main` of Purchase, Inventory, Books and Connect; every
deploy workflow in those repositories runs only by hand (Connect's were switched to manual on its
`main` before this merge), so merging deployed nothing.

**Reconciled with parallel work.** While this ran, the in-flight `awesome-hypatia` branches were
merged to `main` (Purchase #4, Inventory #50, Books #887) and Connect `main` took the per-recipient
share-check design. Where they solved the same finding differently, `main`'s implementation was
kept and this work withdrew its own: the physical goods receipt (Inventory's `ReceiptCostTrueUpService`,
Purchase's per-company `receive_stock_at_grn`) replaced this branch's `from_physical_challan`
passthrough, capability checks and environment switch; Purchase is registered on Connect `main`'s
design rather than the unmerged Embed SDK branch's.

## Changes by repository

### purchases-aicountly

| Area | What changed |
|---|---|
| Integration commands | Deterministic key per operation and revision, unique in the database; stored body replayed on every retry; lease instead of a lock across the network; `UNCERTAIN` for a lost answer, `CANCELLED` by an atomic `withdraw()` so a cancel and a Retry cannot both win |
| Receipts | One receipt = one delivery = one GRN (`receipt_uuid`, GRN series); applied exactly once (`applyOnce`); returned identity and lines validated before anything is counted; cumulative over-receipt limit (zero tolerance by default) under the order lock, in-flight receipts counted; client token separates a double-click from a second delivery |
| PO lifecycle | Receipt, billing and closure tracked apart (`PoProgress`); authorised short-close with reason; state guards under row locks; `CANCELLED` never overwritten; awards convert to draft orders once; requisition gate on RFQs and orders; segregation of duties as company policy (`strict` / `owner_with_reason`) |
| Bills | User's own session to Books; party/bill/lines payload with the supplier invoice apart from Books' voucher number; posting accepted only on verified ledger effect, creditor, reference and totals (`posting_check`); PO, direct and service bills; revise/cancel; posting date apart from invoice date, bounded by the Manage-confirmed year |
| Goods receipt stock | `main`'s per-company `receive_stock_at_grn` (off by default) kept; each receipt records what it did; bills always send `from_challan` and a bill over both kinds is refused before Books is asked |
| Returns and claims | Physical return = challan dispatch, settled by the debit note (one movement); financial-only return separately permitted; quantities bounded by received ∧ billed less every pending return; claim resolutions (financial, physical return, replacement, refund verified in Books, non-financial) settle only on the upstream fact |
| Identity and periods | Supplier contact from Contacts' company contacts, linked by its `books/ledger_account` reference; FY and branch confirmed by Manage (`fy_list`, `branch_list`) |
| Permissions and documents | `v1/approvals` gated per kind and stage, values withheld without view permission; legacy `v1/dashboard` retired (410); PO PDF; prepared / sent / acknowledged recorded with channel and evidence (no supplier portal, no sending) |
| Connect / Pulse / Advisor / AI | Connect's own Embed SDK v1 widget in the shell (calling, company contacts, Pulse pinned, Advisor link out via portal SSO); "Discuss in Connect" on orders, bills, returns, requisitions, RFQs; `v1/connect/share-check` and `v1/connect/context/{type}/{id}`; all AI through the AI Pulse gateway, "Using AI Pulse" / "Rules only" shown |
| Web | Every flow above has its screen: per-line receiving, command strip Retry / Reconcile / Withdraw, supplier trail and PDF, short-close, bill editor (PO / direct / service), return editor, claim resolutions, withheld values, SoD and tolerance settings, supplier contact card |
| Repair | `bin/receipt-repair.php` (below) |

### Inventory-aicountly
Net change on `main`: a test proving a purchase return moves its goods once (challan dispatch,
debit-note settlement). This branch's physical-GRN settlement was reverted in favour of `main`'s
`0d31106`, which does the same more completely (GRNI accrual, year replay, price variance).

### books-react-app
Supplier invoice register (migration 176) — one live purchase voucher per supplier, invoice number
and April–March year, claimed inside the posting transaction, whichever product posts it; an
integration's declared stock effect is posted as declared or refused 422, never read as
`on_invoice`; debit-note items reach Inventory as a purchase return settling the dispatch (test).
The `from_physical_challan` passthrough and `integration/capabilities` were dropped: `main` sends
`from_challan` and Inventory decides.

### connect-aicountly (1 commit on `main`, c0b15fa)
Connect `main` settled on the per-recipient share-check design (307ec4c). Purchase is registered
there as a document product beside Billing (six document types, numeric ids, the sharer's and each
viewer's own session; labels are kind and number only); Purchase origins in CORS; Pulse sandbox
default `pulse.gh.aicountly.com`; unit tests. An earlier registration built on the `fervent-volta`
design was not merged, because it would have overwritten `main`'s design.

### Not changed
contacts-react-app, pulse-aicountly, advisor-aicountly, manage-aicountly, billing, pay — used as
they are (Contacts' and Pulse's in-flight releases), or not needed.

## Tests executed

| Suite | Result |
|---|---|
| Purchase `server-php/tests/run.sh` after merging `main` — integration / AI gateway / remediation (real PostgreSQL, 6-process races) | 143/0 · 17/0 · 54/0 (incl. `main`'s two GRN-setting tests) |
| Purchase `npm run build` (tsc + vite) | clean |
| Purchase `web/tests/remediation.mjs` (Chromium, every changed screen) | 14/14; 14/14 again with Connect unreachable |
| Purchase `web/tests/dashboards.mjs` | 25/35 — the 10 failures look for markup that is not in `web/src` on this branch or its base (8be4231); pre-existing drift, not a regression |
| Connect `main` API (PostgreSQL) ↔ Purchase API, live | owner shares an order; `po.view` holder `can_open` and reads amount, supplier and link; delegate without it: label only, 403 on the read, sharing refused (`share_check_denied`) |
| Embed SDK v1 widget (Connect `fervent-volta` build) in the Purchase page, live | 12/12 browser checks: launcher "Connect", Discuss in Connect, Pulse pinned, Advisor SSO link without token, record opens in Purchase, removed on sign-out |
| Contacts contract against a live Contacts (`fervent-volta`, PostgreSQL) | candidates, link, idempotent re-link (one reference), conflict 409, outsider 403, foreign contact 404 |
| Inventory CI steps on `main` + this change: PHP lint, PHPUnit (PostgreSQL) · web typecheck, tests, build | 854 OK · 4178/4178, build clean |
| Books CI steps on `main` + this change (SmartBooks regression): PHP lint, `scripts/check-unit-suite.php` | lint clean · 4776 ran, 0 failing |
| Books new: supplier invoice register, PostgreSQL race (4 processes), declared stock effect, debit note → Inventory | 12 pass · race 2/2 on PostgreSQL |
| Connect `main` + this change: phpunit · web unit · `build:cpanel` | 226 OK · 55/55 · clean |

## Migrations and configuration

**Deploy order:** Inventory → Books → Purchase (the goods-receipt model needs Inventory's
true-up and Books' GRNI journals before a company turns `receive_stock_at_grn` on).

| Repository | Migrations | Configuration |
|---|---|---|
| Inventory | none | — |
| Books | `176_books_supplier_invoice_register.sql` (additive, backfills) | — |
| Purchase | `006` (`main`'s `receive_stock_at_grn`) and `007`–`013` (`bin/migrate.php`, tracked by filename) | per-company `receive_stock_at_grn` (Settings → approval controls, off by default); `CONTACTS_API_BASE` optional (derived from host); web `VITE_CONNECT_ORIGIN` optional (derived; `off` disables) |
| Connect | none | built-in CORS for `purchase[.gh]`; `PURCHASES_API_BASE` optional; `PULSE_API_BASE` optional |

**Release dependencies:** Contacts' company-contacts release (`fervent-volta`) for supplier
contacts; Connect's Embed SDK v1 (`fervent-volta`, not on Connect `main`) for the widget — until then Purchase shows no widget; Pulse's gateway
and `pulse.gh` host (`hopeful-meitner`) for AI and for Pulse inside Connect, with a model bound for
Purchases in Console. Each is absent from its repository's `main` today; until deployed, Purchase
reports the feature as unavailable and everything else works.

## Historical repair

- `bin/receipt-repair.php` — read-only report of receipts posted under the old PO-keyed identity
  (counter drift, orphaned commands, duplicate supplier invoices); `--plan` writes a reviewable
  plan; `--apply --actor=<name> --reason=<text>` re-validates each action, applies only local
  bookkeeping (recount, withdraw, create the invoice index once no duplicate remains), audits each
  one, and never posts to Inventory or Books. Re-applying a plan changes nothing.
- Books 176 registers existing live vouchers from bill-by-bill history; where history already holds
  a duplicate supplier invoice the first voucher keeps the number and the others are left for
  review.
- Orders received before the physical-GRN model keep their stock where it is; their bills are posted
  in Books (Purchase refuses to receive them again).

## Remaining blockers and deferrals

1. **Deploy the in-flight producer releases** above (Contacts, Connect, Pulse). Purchase `main`
   no longer calls a model provider directly (the AI Pulse gateway branch is merged with this work),
   so AI answers are "Rules only" until Pulse's gateway is live.
2. **Connect's embeddable widget is not on `main`.** `main` took the share-check design; the Embed
   SDK v1 widget (`fervent-volta`) was built on the other design and now conflicts with `main` in
   ten files. It needs rebasing onto `main` by its owner before any product can embed Connect.
3. **Advisor accepts no deep link or context** — its SSO callback returns to `/`. Purchase links to
   Advisor's home through the portal jump; a context deep link needs an Advisor change.
4. **Deferred by design:** return of goods received but not yet billed (needs an Inventory
   GRN-return that settles pending-in; refused with guidance meanwhile); sending a PO through a
   connected channel (no real supplier communication in this phase — the buyer records it).
5. **Pre-existing, not caused here:** Purchase `dashboards.mjs` selector drift (10 checks); Books
   `VendorReconciliationImportIntegrationTest` (1 error on base too).
