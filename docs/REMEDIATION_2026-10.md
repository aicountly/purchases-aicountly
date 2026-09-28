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
- [ ] Read-only discrepancy report and reviewable repair procedure for receipts posted under the PO-keyed identity

### 3. Purchase order lifecycle and procurement decisions
- [x] Receipt completion, billing completion and closure tracked separately; payment stays Books' (`PoProgress`, migration 007)
- [x] A PO never closes because what arrived so far was billed (PO 100 → receipt 40 → bill 40 stays open for 60)
- [~] Authorised short-close with reason, audit and remaining quantities (API + tests done; UI open)
- [x] Bill entry and posting refuse invalid PO states, under a row lock; `CANCELLED` is never overwritten — cancel-vs-bill race tested
- [x] Over-receipt: zero tolerance by default, company tolerance on the order total (not per delivery), authorised exception with a reason
- [ ] Award carries supplier, lines, prices and terms into a PO draft; one award converts once
- [ ] Segregation of duties as a documented company policy; any owner exception explicit, reasoned and audited
- [ ] Requisition status enforced when an RFQ or PO references it

### 4. Books
- [x] User-initiated writes use the user's session; no generic service key for company writes (bills and debit notes)
- [~] Bill and debit-note payloads: party, bill (supplier invoice no/date, due date), lines, tax inputs, references — bill done; debit note with section 6
- [x] Supplier invoice reference kept apart from Books' voucher number (`bill.bill_ref`)
- [ ] Books refuses an empty or ambiguous financial posting (merge of Books `awesome-hypatia` + unknown stock effect from an integration refused)
- [x] Acceptance on ledger effect, creditor, bill reference and totals — not HTTP 200 (`posting_check`)
- [~] Standalone / direct / service-expense bill entry in Purchase, reviewed, no stock movement for services (API + tests done; UI open)

### 5. Physical GRN and cost true-up
- [ ] Inventory: a purchase that settles a physical inward challan without moving stock, trueing up cost
- [ ] Books: passes that effect through for purchase bills; never falls back to receiving stock
- [~] Purchase: GRN posts goods on hand at provisional cost; bill settles it (Purchase side done; posting gated on producer capabilities until Inventory and Books ship theirs)

### 6. Returns and claims
- [ ] Runtime answer: does Books trigger Inventory for a Purchase debit note?
- [ ] One initiation path per physical movement
- [ ] Physical return: dispatch confirmed before the accounting step; financial-only adjustment separately authorised
- [ ] Return quantities validated against receipts, prior returns and pending returns
- [ ] Claim resolution types: financial adjustment, physical, refund, non-financial; effect shown and authorised; settled only after the upstream operation

### 7. Supplier identity, duplicate bills, periods
- [ ] Contacts client on Contacts' real routes; communication identity from Contacts, creditor from Books
- [ ] Cross-app supplier-invoice duplicate protection at the Books boundary
- [ ] Branch and FY validated through Manage
- [ ] Books' closed/locked-period policy enforced for Purchase postings

### 8. Permissions and supplier communication
- [ ] `v1/approvals` permission-gated; legacy `v1/dashboard` retired or gated
- [ ] Amount/cost permissions consistent across APIs, exports and UI
- [ ] PO PDF; prepared / sent / supplier-acknowledged distinguished; manual acknowledgement with evidence

### 9. Connect, Pulse, Advisor
- [ ] Embedded Connect widget (Connect APIs, calling, live Contacts); user-facing "Connect"
- [ ] Pulse as a pinned conversation through Pulse's live API; Advisor as a deep link
- [ ] Document-share permission and recipient visibility validated
- [ ] Every AI feature through AI Pulse; "Using AI Pulse" shown

### 10. Verification
- [~] Journey tests on real PostgreSQL, concurrency included (`tests/remediation.php`, sections 1–4)
- [ ] Producer-verified contracts (Books, Inventory, Contacts, Connect)
- [ ] SmartBooks regression
- [ ] Report: changes by repository, tests run, migrations/configuration, historical repair, remaining blockers

## Continuation checkpoint

Last verified: `server-php/tests/run.sh` → integration 141/0, ai_gateway 17/0, remediation 28/0
(remediation suite looped 6× for race stability).

Next, in order:
1. `server-php/bin/receipt-repair.php` — read-only report (legacy PO-keyed receipts, duplicate
   supplier invoices blocking `uq_purchase_bills_supplier_invoice`, open commands) and a
   reviewable `--plan` output; applies nothing without `--apply` on a reviewed plan.
2. Inventory (`inventory-aicountly`): `PURCHASE_RECEIPT` + `from_physical_challan` (no movement,
   settles the challan, trues up cost); `stock_effect` validated per document type;
   `GET v1/capabilities`.
3. Books (`books-react-app`): pass `from_physical_challan` for purchase vouchers; refuse unknown
   stock effects from integration sources; `GET integration/capabilities`; supplier-invoice
   duplicate guard across Billing and Purchase; closed/locked period policy; SmartBooks regression.
4. Section 6 returns/claims; section 7 Contacts routes + Manage FY/branch; section 8; section 9.
5. Web UI for sections 1–4 (per-line receipt, CommandStrip controls, short-close, direct/service bill).
