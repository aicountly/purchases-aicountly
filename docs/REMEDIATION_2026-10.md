# Purchase remediation for the 2026-10-11 soft launch

Finding-to-fix checklist for the procurement-to-payment audit of 2026-09-27, and the
continuation checkpoint for this work. Status is updated in the same commit as the change
it describes. Branch in every repository: `claude/loving-pasteur-ra5quc`.

Legend: `[ ]` open · `[~]` in progress · `[x]` done and tested · `[d]` deferred (reason given)

## Base

This branch is built on the in-flight corrections, merged rather than re-implemented:

| Repository | Merged branch | What it carries |
|---|---|---|
| purchases-aicountly | `claude/awesome-hypatia-wba25w` | nested `party`/`bill` bill payload; GRN as challan; `block_bill_on_match_failure` read fix |
| purchases-aicountly | `claude/hopeful-meitner-2ctk5i` | all Purchase AI through the AI Pulse gateway |
| connect-aicountly | `main` (share-check design, 307ec4c) | Purchase registered there as a document product (c0b15fa). The Embed SDK v1 widget is still on the unmerged `claude/fervent-volta-k2w05d` |

## Dependency order

1. Integration commands (everything cross-app depends on them)
2. Receipt identity, exactly-once application, cumulative limits, aggregation
3. PO lifecycle (receipt / billing / closure), short-close, state guards
4. Books: authentication and payload together; Books refuses empty or ambiguous postings
5. Physical GRN at provisional cost, billed without receiving again (Inventory + Books + Purchase)
6. Returns and claims with one owner per movement
7. Supplier identity (Contacts), cross-app duplicate bills, period controls
8. Permissions, PO document and supplier communication
9. Connect, Pulse, Advisor
10. Historical repair report, journey tests, SmartBooks regression

## Checklist

### 1. Integration commands
- [x] Deterministic operation identity (company, operation, entity, revision); no random tail — `IntegrationCommand::key()`
- [x] Database uniqueness on the operation; atomic acquisition (`INSERT … ON CONFLICT`), lease for in-flight attempts, no lock held across a network call — migration 007; race-tested with 6 processes
- [x] `UNCERTAIN` state for a lost response; upstream ids persisted; recovery reuses the same key and the stored body
- [x] Cancel withdraws the command atomically (`withdraw()`), so a cancel and a Retry cannot both win; a revised bill's refused revision is withdrawn as superseded
- [x] PO CommandStrip finds the order's receipt, bill and return commands; Retry / Reconcile (for `UNCERTAIN`) / Withdraw in the UI with Purchase wording (`CommandStrip.recoveryPath`)

### 2. Receipts
- [x] Each receipt has its own stable identity (`receipt_uuid`, `receipt_no` GRN series); Inventory source identity is the receipt, the PO is a separate reference in metadata
- [x] Retry of one receipt → one Inventory document; separate deliveries → distinct documents
- [x] Quantities applied exactly once, including a duplicate response that is recovery of an interrupted post (`applyOnce`, Retry and Reconcile both tested)
- [x] Returned document identity and lines validated before quantities change (mismatch → BLOCKED, nothing counted)
- [x] Receipt status and three-way match aggregate every linked receipt, reversals included (`ReceiptLedger`)
- [x] Cumulative limit enforced atomically, in-flight receipts counted; duplicate submission (client token) ≠ second delivery — 5-process race test; lock order order → lines → receipt (fixed a deadlock the race test found)
- [x] Per-line receipt entry in the UI: quantity, rejected (with reason), warehouse, batch, serials, inspection note, client token, over-receipt reason (`purchase-order/OrderPanels.tsx`; browser-tested: a partial delivery becomes its own GRN)
- [x] Read-only discrepancy report and reviewable repair procedure for receipts posted under the PO-keyed identity — `bin/receipt-repair.php` (report / `--plan` / `--apply` with re-validation and audit; never posts to Inventory or Books)

### 3. Purchase order lifecycle and procurement decisions
- [x] Receipt completion, billing completion and closure tracked separately; payment stays Books' (`PoProgress`, migration 008)
- [x] A PO never closes because what arrived so far was billed (PO 100 → receipt 40 → bill 40 stays open for 60)
- [x] Authorised short-close with reason, audit and remaining quantities (API, tests and UI)
- [x] Bill entry and posting refuse invalid PO states, under a row lock; `CANCELLED` is never overwritten — cancel-vs-bill race tested
- [x] Over-receipt: zero tolerance by default, company tolerance on the order total (not per delivery), authorised exception with a reason
- [x] Award carries supplier, lines, prices and terms into a PO draft; one award converts once (`convertAward`, row-locked, race-tested; a cancelled order frees it; `award()` now locks the RFQ and refuses a row with no quotation)
- [x] Segregation of duties as a documented company policy; any owner exception explicit, reasoned and audited (`sod_policy`: `strict` | `owner_with_reason` default — preserves what owners could do, now with a recorded reason; changes to it are audited)
- [x] Requisition status enforced when an RFQ or PO references it (approved/sourcing only; line references must belong; no ordering beyond what was asked, under the requisition's lock)

### 4. Books
- [x] User-initiated writes use the user's session; no generic service key for company writes (bills and debit notes)
- [x] Bill and debit-note payloads: party, bill (supplier invoice no/date, due date / return no), lines, tax inputs, references
- [x] Supplier invoice reference kept apart from Books' voucher number (`bill.bill_ref`)
- [x] Books refuses an empty or ambiguous financial posting — empty commercial voucher refused (Books `awesome-hypatia`, merged); a stock effect an integration declares is posted as declared or refused 422 (books `InventorySettlementService::assertDeclaredEffectHonoured`)
- [x] Acceptance on ledger effect, creditor, bill reference and totals — not HTTP 200 (`posting_check`)
- [x] Standalone / direct / service-expense bill entry in Purchase, reviewed, no stock movement for services (API, tests and UI; service lines book to Books' Purchase / Direct / Indirect expense ledgers via `v1/catalog/ledgers`)

### 5. Physical GRN and cost true-up
Delivered by the shared `awesome-hypatia` work now on each `main` (Purchase #4, Inventory #50, Books #887); this branch's parallel implementation was withdrawn in favour of it on 2026-09-29.
- [x] Inventory: a bill whose settled receipts moved stock becomes `from_physical_challan` and moves nothing; the receipt accrues through GRNI; `ReceiptCostTrueUpService` trues the cost up, replays the year or states a price variance; reversal withdraws it (Inventory `0d31106`)
- [x] Books: journals the accrual and its clearing from Inventory's stated effects; purchase bills are sent `from_challan`, and an integration's declared effect is posted as declared or refused 422 — never read as `on_invoice` (`DeclaredStockEffectTest`)
- [x] Purchase: per-company `receive_stock_at_grn` (migration 006, off by default); each receipt records what it did; a bill over both kinds is refused before Books is asked

### 6. Returns and claims
- [x] Runtime answer: does Books trigger Inventory for a Purchase debit note? **Yes** — Books sends the debit note's item lines to Inventory as `PURCHASE_RETURN` (books `DebitNoteReachesInventoryTest`); Books' own pending register skips Purchase's challans; Inventory moves the goods once (inventory `PurchaseReturnSingleMovementTest`)
- [x] One initiation path per physical movement — dispatch is a `challan_only` delivery challan (moves nothing), the debit note settles it (the one movement); Purchase never posts a `PURCHASE_RETURN` itself
- [x] Physical return: dispatch confirmed before the accounting step; financial-only adjustment separately authorised (`return.financial_adjustment`, reason, ledger; no stock); recall reverses the challan
- [x] Return quantities validated against receipts, prior returns and pending returns — received ∧ billed, less every undispatched return, under the order lock (race-tested)
- [d] Return of goods received but **not yet billed** — refused with guidance (bill what arrived, then return against the bill). A safe single-movement path needs an Inventory "GRN return" that settles the GRN's pending-in; deferred as a design item, not patched
- [x] Claim resolution types: financial adjustment, physical return, replacement, refund, non-financial; effect shown and authorised; settled only after the upstream operation (debit note posted, return debited, GRN applied, Books receipt verified); partial settlement; retries on the same key; direct "settle" retired

### 7. Supplier identity, duplicate bills, periods
- [x] Contacts client on Contacts' real routes; communication identity from Contacts, creditor from Books — company routes only (`/api/companies/{cmp}/contacts…`, the in-flight Contacts `fervent-volta` release), linked by Contacts' `books/ledger_account` identity reference, idempotent, nothing copied; personal contacts never read; undeployed Contacts reported as unavailable
- [x] Cross-app supplier-invoice duplicate protection at the Books boundary — Books migration 176 register (company, supplier, type, normalised number, April–March year); PostgreSQL race test; Purchase journey test; Purchase now reads Books' `messages.error` refusals
- [x] Branch and FY validated through Manage — the same `companyinfo` answer that confirms the company must list the year (`fy_list`) and branch (`branch_list`), else 403; its dates bound a bill's new posting date (migration 011), kept apart from the supplier's invoice date. Manage has no closed-year flag, so a carried-forward year is not treated as closed
- [x] Books' closed/locked-period policy enforced for Purchase postings — Purchase posts through Books' own draft→post path, which applies `FinancialYearPostingGuardService` (archived year, date within year); a refusal blocks the bill for revision (tested). Books has no separate lock-date setting today

### 8. Permissions and supplier communication
- [x] `v1/approvals` permission-gated (approve permission per kind, stage permission, this year; values with the document's view permission); legacy `v1/dashboard` retired (410 → `v1/dashboards/overview`; no caller — Insights uses `v1/dashboards/*`)
- [x] Amount/cost permissions consistent across APIs, exports and UI — rule: a document's own amounts go with its view permission; spend, historical prices and aggregates need `cost.view`/`reports.view`. The PO PDF needs `po.view` like the screen; Approvals shows "withheld" where the API withholds; the Connect label never carries an amount or supplier
- [x] PO PDF; prepared / sent / supplier-acknowledged distinguished; manual acknowledgement with source and evidence (migration 012); names read live from Manage and Inventory; fingerprint ties "sent" to the version
- [d] Sending the PO through a connected service (Email / Connect) — not built: no real supplier communication is allowed in this phase; the channel is recorded by the buyer. The Connect widget (section 9) is the intended channel

### 9. Connect, Pulse, Advisor
- [x] Embedded Connect widget — Connect's own Embed SDK v1 loader, not a fork, loaded only when Connect serves SDK v1 (`version === '1'`; Connect `main` still serves the older loader, which Purchase never initialises — the v1 widget is on Connect's unmerged `fervent-volta` branch): conversations, calling, company contacts from Contacts, launcher labelled "Connect"; the person's own ses_key, scope kept in step, destroyed on sign-out; "Discuss in Connect" on orders, bills, returns, requisitions and RFQs; a shared record opens in Purchase's router. Connect down → no widget, Purchase unaffected (browser-tested both ways)
- [x] Pulse pinned first in Connect, served by Pulse's live session API through Connect's relay (sandbox default now `pulse.gh.aicountly.com`). Advisor is a link out through the portal's SSO jump with no token in the URL — Advisor accepts no deep path or context today (its SSO callback returns to `/`), so it lands on Advisor's home
- [x] Document-share permission and recipient visibility — Purchase answers Connect's contract (`POST v1/connect/share-check` with the sharer's session, per-recipient `can_view`; `GET v1/connect/context/{type}/{id}` with each viewer's), registered in Connect `main`'s `ProductRegistry`. Live against Connect `main`: owner shares; `po.view` holder `can_open` and reads the details; a delegate without it gets the label only, 403 on the read, and is refused sharing (`share_check_denied`)
- [x] Every AI feature through AI Pulse (gateway `/api/ai/v1/*`, `X-Pulse-Product: purchases`, the user's own session; no provider key or host anywhere — `tests/ai_gateway.php` enforces it); "Using AI Pulse" shown on the Ask drawer and on each Payables answer AI took part in, "Rules only" otherwise

### 10. Verification
- [x] Journey tests on real PostgreSQL, concurrency included (`tests/remediation.php`, 54 tests, sections 1–9) and browser checks of every changed screen (`web/tests/remediation.mjs`)
- [x] Producer-verified contracts — Books and Inventory by their own PostgreSQL suites on this branch; Connect by live runs against both of its designs (real Connect API + real Purchase API); Contacts by Purchase's API against a live Contacts on its `fervent-volta` release (candidates, link, idempotent re-link — one `books/ledger_account` reference — conflict 409, outsider 403, foreign contact 404)
- [x] SmartBooks regression — Books `scripts/check-unit-suite.php` 4577/0, security 119 OK, integration 427 with 1 pre-existing error (`VendorReconciliationImportIntegrationTest`, fails identically on base)
- [x] Report: changes by repository, tests run, migrations/configuration, historical repair, remaining blockers — [`REMEDIATION_2026-10_REPORT.md`](REMEDIATION_2026-10_REPORT.md)

## Continuation checkpoint

Last verified (2026-09-29, after merging `main` — awesome-hypatia #4 — into this work):
`server-php/tests/run.sh` → integration 143/0 (incl. `main`'s two GRN-setting tests), ai_gateway
17/0, remediation 54/0; `npm run build`; `web/tests/remediation.mjs` 14/14; live Connect `main` ↔
Purchase share checks; `web/tests/dashboards.mjs` 25/35 — the 10 failures target markup
(`.purchase-switcher`, `.purchase-monitor__pill`, `.purchase-metric__footer`) that is not in
`web/src` on this branch or its base (8be4231): test drift from the dashboard rebuild, not a
regression.

Done: sections 1–9 and the producer checks. Open:
1. Deferred by design: unbilled-goods returns (needs an Inventory GRN-return); PO sending through
   a connected channel (no real supplier communication in this phase).
