# Purchase remediation — final report (2026-09-28)

Scope: the procurement-to-payment audit of 2026-09-27, implemented against current code for the
2026-10-11 soft launch. Finding-by-finding status and evidence: [`REMEDIATION_2026-10.md`](REMEDIATION_2026-10.md).
Nothing was deployed, no production record was touched, no supplier was contacted and no payment
was initiated. Every repository's deploy workflows run only on `main` or by hand; all work is on
`claude/loving-pasteur-ra5quc`.

## Changes by repository

### purchases-aicountly (10 commits)

| Area | What changed |
|---|---|
| Integration commands | Deterministic key per operation and revision, unique in the database; stored body replayed on every retry; lease instead of a lock across the network; `UNCERTAIN` for a lost answer, `CANCELLED` by an atomic `withdraw()` so a cancel and a Retry cannot both win |
| Receipts | One receipt = one delivery = one GRN (`receipt_uuid`, GRN series); applied exactly once (`applyOnce`); returned identity and lines validated before anything is counted; cumulative over-receipt limit (zero tolerance by default) under the order lock, in-flight receipts counted; client token separates a double-click from a second delivery |
| PO lifecycle | Receipt, billing and closure tracked apart (`PoProgress`); authorised short-close with reason; state guards under row locks; `CANCELLED` never overwritten; awards convert to draft orders once; requisition gate on RFQs and orders; segregation of duties as company policy (`strict` / `owner_with_reason`) |
| Bills | User's own session to Books; party/bill/lines payload with the supplier invoice apart from Books' voucher number; posting accepted only on verified ledger effect, creditor, reference and totals (`posting_check`); PO, direct and service bills; revise/cancel; posting date apart from invoice date, bounded by the Manage-confirmed year |
| Physical GRN | GRN puts goods on hand at the order's rate; the bill settles it (`from_physical_challan`) and trues cost up — capability-checked against Inventory and Books |
| Returns and claims | Physical return = challan dispatch, settled by the debit note (one movement); financial-only return separately permitted; quantities bounded by received ∧ billed less every pending return; claim resolutions (financial, physical return, replacement, refund verified in Books, non-financial) settle only on the upstream fact |
| Identity and periods | Supplier contact from Contacts' company contacts, linked by its `books/ledger_account` reference; FY and branch confirmed by Manage (`fy_list`, `branch_list`) |
| Permissions and documents | `v1/approvals` gated per kind and stage, values withheld without view permission; legacy `v1/dashboard` retired (410); PO PDF; prepared / sent / acknowledged recorded with channel and evidence (no supplier portal, no sending) |
| Connect / Pulse / Advisor / AI | Connect's own Embed SDK v1 widget in the shell (calling, company contacts, Pulse pinned, Advisor link out via portal SSO); "Discuss in Connect" on orders, bills, returns, requisitions, RFQs; `v1/connect/share-check` and `v1/connect/context/{type}/{id}`; all AI through the AI Pulse gateway, "Using AI Pulse" / "Rules only" shown |
| Web | Every flow above has its screen: per-line receiving, command strip Retry / Reconcile / Withdraw, supplier trail and PDF, short-close, bill editor (PO / direct / service), return editor, claim resolutions, withheld values, SoD and tolerance settings, supplier contact card |
| Repair | `bin/receipt-repair.php` (below) |

### Inventory-aicountly (2 commits)
`PURCHASE_RECEIPT` with `from_physical_challan`: settles a physical inward challan without moving
stock, trues GRN cost up (unabsorbed remainder warned), reversal unwinds, wrong challan kind
refused; per-type stock-effect validation; `GET v1/capabilities`; a purchase return proven to move
its goods once.

### books-react-app (3 commits)
Purchase bills pass `from_physical_challan` through and never fall back to receiving stock; an
integration's declared stock effect is honoured or refused (422); `GET integration/capabilities`;
supplier invoice register (migration 174) — one live purchase voucher per supplier, invoice number
and April–March year, claimed inside the posting transaction, whichever product posts it; debit
note items reach Inventory as a purchase return settling the dispatch.

### connect-aicountly (1 commit, on top of the in-flight `fervent-volta` Embed SDK v1)
Purchase registered for record sharing (six document types, verified against Purchase's own read
endpoints with each person's session; labels are document numbers only); Purchase origins in CORS;
Pulse relay sandbox default `pulse.gh.aicountly.com`; docs and unit tests.

### Not changed
contacts-react-app, pulse-aicountly, advisor-aicountly, manage-aicountly, billing, pay — used as
they are (Contacts' and Pulse's in-flight releases), or not needed.

## Tests executed

| Suite | Result |
|---|---|
| Purchase `server-php/tests/run.sh` — integration / AI gateway / remediation (real PostgreSQL, 6-process races) | 141/0 · 17/0 · 54/0 |
| Purchase `npm run build` (tsc + vite) | clean |
| Purchase `web/tests/remediation.mjs` (Chromium, every changed screen) | 14/14; 14/14 again with Connect unreachable |
| Purchase `web/tests/dashboards.mjs` | 25/35 — the 10 failures look for markup that is not in `web/src` on this branch or its base (8be4231); pre-existing drift, not a regression |
| Cross-app Connect run: Purchase page → built widget → Connect API (PostgreSQL) → Purchase API | 12/12 browser checks + API: delegate without `po.view` refused (403) and shown redacted; owner and `po.view` holder see `PO/6/0001` |
| Contacts contract against a live Contacts (`fervent-volta`, PostgreSQL) | candidates, link, idempotent re-link (one reference), conflict 409, outsider 403, foreign contact 404 |
| Inventory full suite (PostgreSQL) incl. `PurchaseSettlesPhysicalGrnTest` (10), `PurchaseReturnSingleMovementTest` (1) | 852 OK |
| Books `scripts/check-unit-suite.php` (SmartBooks regression) | 4577/0; security 119 OK; integration 427 with 1 pre-existing error (`VendorReconciliationImportIntegrationTest`, identical on base) |
| Books new: supplier invoice register (6), PostgreSQL race, physical GRN effect (4), debit note → Inventory | pass |
| Connect phpunit · web unit · `test:embed` (hostile host page) | 207 OK · 73/73 · 40/40 |

## Migrations and configuration

**Deploy order:** Inventory → Books → Purchase. Purchase checks both producers' capabilities and
refuses to post rather than receive goods twice if either is older.

| Repository | Migrations | Configuration |
|---|---|---|
| Inventory | none | — |
| Books | `174_books_supplier_invoice_register.sql` (additive, backfills) | — |
| Purchase | `006`–`012` (`bin/migrate.php`) | `PURCHASE_GRN_STOCK_EFFECT` = `physical` (default) or `challan_only`; `CONTACTS_API_BASE` optional (derived from host); web `VITE_CONNECT_ORIGIN` optional (derived; `off` disables) |
| Connect | none | built-in CORS for `purchase[.gh]`; `PURCHASES_API_BASE` optional; `PULSE_API_BASE` optional |

**Release dependencies:** Contacts' company-contacts release (`fervent-volta`) for supplier
contacts; Connect's Embed SDK v1 (`fervent-volta` + this branch) for the widget; Pulse's gateway
and `pulse.gh` host (`hopeful-meitner`) for AI and for Pulse inside Connect, with a model bound for
Purchases in Console. Each is absent from its repository's `main` today; until deployed, Purchase
reports the feature as unavailable and everything else works.

## Historical repair

- `bin/receipt-repair.php` — read-only report of receipts posted under the old PO-keyed identity
  (counter drift, orphaned commands, duplicate supplier invoices); `--plan` writes a reviewable
  plan; `--apply --actor=<name> --reason=<text>` re-validates each action, applies only local
  bookkeeping (recount, withdraw, create the invoice index once no duplicate remains), audits each
  one, and never posts to Inventory or Books. Re-applying a plan changes nothing.
- Books 174 registers existing live vouchers from bill-by-bill history; where history already holds
  a duplicate supplier invoice the first voucher keeps the number and the others are left for
  review.
- Orders received before the physical-GRN model keep their stock where it is; their bills are posted
  in Books (Purchase refuses to receive them again).

## Remaining blockers and deferrals

1. **Deploy the in-flight producer releases** above (Contacts, Connect, Pulse). `origin/main` of
   Purchase still calls Gemini directly; this branch removes that.
2. **Connect has two incompatible designs in flight** for verifying shared documents
   (`fervent-volta`: product read endpoint; `clever-darwin`: `v1/connect/share-check`). Purchase
   answers both; Connect's owners need to choose before either merges.
3. **Advisor accepts no deep link or context** — its SSO callback returns to `/`. Purchase links to
   Advisor's home through the portal jump; a context deep link needs an Advisor change.
4. **Deferred by design:** return of goods received but not yet billed (needs an Inventory
   GRN-return that settles pending-in; refused with guidance meanwhile); sending a PO through a
   connected channel (no real supplier communication in this phase — the buyer records it).
5. **Pre-existing, not caused here:** Purchase `dashboards.mjs` selector drift (10 checks); Books
   `VendorReconciliationImportIntegrationTest` (1 error on base too).
