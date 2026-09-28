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
- [x] Database uniqueness on the operation; atomic acquisition (`INSERT … ON CONFLICT`), lease for in-flight attempts, no lock held across a network call — migration 006; race-tested with 6 processes
- [x] `UNCERTAIN` state for a lost response; upstream ids persisted; recovery reuses the same key and the stored body
- [x] Cancel withdraws the command atomically (`withdraw()`), so a cancel and a Retry cannot both win; a revised bill's refused revision is withdrawn as superseded
- [~] PO CommandStrip finds the order's receipt, bill and return commands (API done: `find()` returns them); retry and reconcile controls and Purchase labels in the UI still open

### 2. Receipts
- [x] Each receipt has its own stable identity (`receipt_uuid`, `receipt_no` GRN series); Inventory source identity is the receipt, the PO is a separate reference in metadata
- [x] Retry of one receipt → one Inventory document; separate deliveries → distinct documents
- [x] Quantities applied exactly once, including a duplicate response that is recovery of an interrupted post (`applyOnce`, Retry and Reconcile both tested)
- [x] Returned document identity and lines validated before quantities change (mismatch → BLOCKED, nothing counted)
- [x] Receipt status and three-way match aggregate every linked receipt, reversals included (`ReceiptLedger`)
- [x] Cumulative limit enforced atomically, in-flight receipts counted; duplicate submission (client token) ≠ second delivery — 5-process race test; lock order order → lines → receipt (fixed a deadlock the race test found)
- [ ] Per-line receipt entry in the UI: quantity, rejected, warehouse, batch/serial, inspection note
- [x] Read-only discrepancy report and reviewable repair procedure for receipts posted under the PO-keyed identity — `bin/receipt-repair.php` (report / `--plan` / `--apply` with re-validation and audit; never posts to Inventory or Books)

### 3. Purchase order lifecycle and procurement decisions
- [x] Receipt completion, billing completion and closure tracked separately; payment stays Books' (`PoProgress`, migration 007)
- [x] A PO never closes because what arrived so far was billed (PO 100 → receipt 40 → bill 40 stays open for 60)
- [~] Authorised short-close with reason, audit and remaining quantities (API + tests done; UI open)
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
- [~] Standalone / direct / service-expense bill entry in Purchase, reviewed, no stock movement for services (API + tests done; UI open)

### 5. Physical GRN and cost true-up
- [x] Inventory: a purchase that settles a physical inward challan without moving stock, trueing up cost (`PURCHASE_RECEIPT` + `from_physical_challan`; unabsorbed remainder warned; reversal unwinds; wrong challan kind refused; per-type effect validation; `GET v1/capabilities`) — 10 PostgreSQL tests
- [x] Books: passes that effect through for purchase bills; never falls back to receiving stock (`GET integration/capabilities`)
- [x] Purchase: GRN posts goods on hand at provisional cost; bill settles it (capability-gated; deploy Inventory and Books first)

### 6. Returns and claims
- [x] Runtime answer: does Books trigger Inventory for a Purchase debit note? **Yes** — Books sends the debit note's item lines to Inventory as `PURCHASE_RETURN` (books `DebitNoteReachesInventoryTest`); Books' own pending register skips Purchase's challans; Inventory moves the goods once (inventory `PurchaseReturnSingleMovementTest`)
- [x] One initiation path per physical movement — dispatch is a `challan_only` delivery challan (moves nothing), the debit note settles it (the one movement); Purchase never posts a `PURCHASE_RETURN` itself
- [x] Physical return: dispatch confirmed before the accounting step; financial-only adjustment separately authorised (`return.financial_adjustment`, reason, ledger; no stock); recall reverses the challan
- [x] Return quantities validated against receipts, prior returns and pending returns — received ∧ billed, less every undispatched return, under the order lock (race-tested)
- [d] Return of goods received but **not yet billed** — refused with guidance (bill what arrived, then return against the bill). A safe single-movement path needs an Inventory "GRN return" that settles the GRN's pending-in; deferred as a design item, not patched
- [x] Claim resolution types: financial adjustment, physical return, replacement, refund, non-financial; effect shown and authorised; settled only after the upstream operation (debit note posted, return debited, GRN applied, Books receipt verified); partial settlement; retries on the same key; direct "settle" retired

### 7. Supplier identity, duplicate bills, periods
- [x] Contacts client on Contacts' real routes; communication identity from Contacts, creditor from Books — company routes only (`/api/companies/{cmp}/contacts…`, the in-flight Contacts `fervent-volta` release), linked by Contacts' `books/ledger_account` identity reference, idempotent, nothing copied; personal contacts never read; undeployed Contacts reported as unavailable
- [x] Cross-app supplier-invoice duplicate protection at the Books boundary — Books migration 174 register (company, supplier, type, normalised number, April–March year); PostgreSQL race test; Purchase journey test; Purchase now reads Books' `messages.error` refusals
- [x] Branch and FY validated through Manage — the same `companyinfo` answer that confirms the company must list the year (`fy_list`) and branch (`branch_list`), else 403; its dates bound a bill's new posting date (migration 010), kept apart from the supplier's invoice date. Manage has no closed-year flag, so a carried-forward year is not treated as closed
- [x] Books' closed/locked-period policy enforced for Purchase postings — Purchase posts through Books' own draft→post path, which applies `FinancialYearPostingGuardService` (archived year, date within year); a refusal blocks the bill for revision (tested). Books has no separate lock-date setting today

### 8. Permissions and supplier communication
- [x] `v1/approvals` permission-gated (approve permission per kind, stage permission, this year; values with the document's view permission); legacy `v1/dashboard` retired (410 → `v1/dashboards/overview`; no caller — Insights uses `v1/dashboards/*`)
- [~] Amount/cost permissions consistent across APIs, exports and UI — rule: a document's own amounts go with its view permission; spend, historical prices and aggregates need `cost.view`/`reports.view`; exports and PDFs render the same withheld payload as the screen. API side done for approvals and dashboards; UI pass with item 5
- [x] PO PDF; prepared / sent / supplier-acknowledged distinguished; manual acknowledgement with source and evidence (migration 011); names read live from Manage and Inventory; fingerprint ties "sent" to the version
- [d] Sending the PO through a connected service (Email / Connect) — not built: no real supplier communication is allowed in this phase; the channel is recorded by the buyer. The Connect widget (section 9) is the intended channel

### 9. Connect, Pulse, Advisor
- [ ] Embedded Connect widget (Connect APIs, calling, live Contacts); user-facing "Connect"
- [ ] Pulse as a pinned conversation through Pulse's live API; Advisor as a deep link
- [ ] Document-share permission and recipient visibility validated
- [ ] Every AI feature through AI Pulse; "Using AI Pulse" shown

### 10. Verification
- [~] Journey tests on real PostgreSQL, concurrency included (`tests/remediation.php`, sections 1–4)
- [ ] Producer-verified contracts (Books, Inventory, Contacts, Connect)
- [~] SmartBooks regression — Books `scripts/check-unit-suite.php` 4577/0 new, security 119 OK, integration 427 with 1 pre-existing error (fails identically on base)
- [ ] Report: changes by repository, tests run, migrations/configuration, historical repair, remaining blockers

## Continuation checkpoint

Last verified: `server-php/tests/run.sh` → integration 141/0, ai_gateway 17/0, remediation 51/0
(remediation suite looped 6× for race stability).

Next, in order:
1. ~~`bin/receipt-repair.php`~~ done.
2. ~~Inventory producer change~~ done (Inventory-aicountly, same branch).
3. ~~Books producer change + supplier invoice register~~ done (books-react-app, same branch).
4. ~~Section 6~~ done (API; screens with item 5). Next: section 7 Contacts routes + Manage FY/branch; section 8; section 9.
5. Web UI for sections 1–4 (per-line receipt, CommandStrip controls, short-close, direct/service bill).
