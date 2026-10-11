# E8-PUR — Purchases lifecycle, matching and payables (launch readiness 2026-10)

Branch `claude/e8-pur` in purchases-aicountly, Inventory-aicountly and books-react-app, redone on
current `main` (purchases 8ea952f at start). Every finding was first confirmed present at main HEAD.

Evidence labels: **EXECUTED** (run here on PostgreSQL 16, over the HTTP stub or a real receiver),
**CONTRACT** (Purchases' real code against the real Inventory front controller), **STATIC**,
**OPERATIONALLY-PENDING** (needs an operator or a deployed environment). Nothing here was verified
against a live AICOUNTLY host.

## How to run the proofs

```bash
# All suites (PostgreSQL + stub): console_database, integration, ai_gateway, remediation,
# launch_readiness (one check per finding), http_gate (every route over the real front controller)
TEST_DB_NAME=… TEST_DB_USER=… TEST_DB_PASS=… STUB_PORT=… server-php/tests/run.sh

# Plus Purchases against the REAL Inventory (its own front controller on a disposable database)
INVENTORY_CONTRACT_ROOT=…/Inventory-aicountly/server-php INVENTORY_CONTRACT_DB=<disposable inventory test db> \
INVENTORY_CONTRACT_DB_USER=… INVENTORY_CONTRACT_DB_PASSWORD=… server-php/tests/run.sh

# Browser suites (stack on your own ports, see docs/DEVELOPMENT.md)
npm --prefix web run test:ui          # 36/36
npm --prefix web run test:remediation # 17/17
npm --prefix web test && npm --prefix web run build
```

Last local run (2026-10-11): console 151/0, integration 150/0, ai_gateway 23/0, remediation 89/0,
launch_readiness 50/0, http_gate 10/0, contract_receivers (real Inventory) 5/0; test:ui 36/36,
test:remediation 17/17; web unit 14/14; Inventory integration suite and Books unit suite green.

## Migrations (staged, idempotent, additive)

| File | What |
| --- | --- |
| 016_e8pu_match_decisions.sql | match `bill_revision`; exception fingerprint / po_line_id / carried decisions; bills with a REJECTED latest exception move to REJECTED |
| 017_e8pu_direct_bill_open_receipts.sql | `open_grn_confirmation` on bills (A09/F010) |
| 018_e8pu_po_amendments_and_controls.sql | `purchase_po_amendments`, amendment links, cancelled POs' pending approvals withdrawn |
| 019_e8pu_bill_reverse_charge.sql | `is_reverse_charge`, `rcm_basis`, `supplier_gstin_reason` |
| 020_e8pu_receipt_guards.sql | `request_fingerprint`, `duplicate_dc_reason`, DC index |
| 021_e8pu_rfq_invitation_names.sql | `supplier_name_snapshot` on RFQ invitations |
| 022_e8pu_invoice_parts.sql | `invoice_part`, `invoice_part_reason`; duplicate-invoice index includes the part (rebuilt only where it exists) |

Historical data is not rewritten beyond what each migration states; `bin/receipt-repair.php`
reports what needs a person (dry-run report and plan; `--apply` needs `--cmp`, an actor, a reason,
and writes a snapshot first).

## Status per finding

| Id | Status | What changed | Evidence |
| --- | --- | --- | --- |
| A09 | implemented | a direct/on_invoice bill over open GRN quantities of the supplier is refused (409 `open_goods_receipts`, naming the receipts) unless confirmed with a reason; Inventory refuses an on_invoice purchase beside an open physical inward challan (`open_inward_challan`); Books passes the confirmation and shows a modal | EXECUTED (launch_readiness, Inventory OpenReceiptGuardTest, Books OpenInwardChallanBridgeTest) |
| A13 | implemented | company-level (bo_id 0) bills send the company state from Manage `ro_state`; refusal text names the remedy, not "branch #0"; Books ManageBranchService reads `ro_state` | EXECUTED |
| A26 | implemented | a REJECTED exception always blocks posting; the bill moves to REJECTED; only Revise (re-matched) or Cancel | EXECUTED |
| A31 | implemented | match on NET unit price, every bill line aggregated per order line, per-line and aggregate checks, optional value tolerance | EXECUTED |
| F007 | implemented | 5xx after a write / unreadable 2xx / in-flight → UNCERTAIN, never withdrawable; withdraw of a once-sent receipt asks Inventory first; found documents are applied once | EXECUTED + CONTRACT (real Inventory 404 is a definite absence) |
| F009 | implemented | expiry/mfg dates on receipt lines create the batch; expiry-tracked items need batch + expiry (422 before anything is recorded); expired batch refused; Inventory refuses a new expiry-tracked batch without expiry and reports `track_expiry` | EXECUTED + CONTRACT |
| F010 | implemented | see A09 | EXECUTED |
| F017 | partially_implemented | the return screen sends back in one step (dispatch + debit note), so stock falls at dispatch whenever Books answers; a dispatched return states `goods_still_counted_on_hand` | EXECUTED. Handoff E8-INV: a physical outward challan for purchase returns needs an Inventory/Books accrual effect |
| F025 | implemented | approved-vendor control: barred suppliers refused unless overridden with a reason and supplier.approve (audited), re-checked at submit/approve/issue; settings copy honest | EXECUTED |
| F028 | implemented | Ask concentration on the dashboards' base (all issued orders) and the branch scope everywhere | EXECUTED |
| F120 | implemented | GRN lines go to Inventory at rupee rate (net × exchange rate) with fc_rate/fc_amount/exchange_rate | EXECUTED + CONTRACT (real Inventory values USD@83 at 830) |
| F122 | implemented | reverse charge (marked or import of services), supply nature, typed GSTIN differing from the ledger needs a reason (422 `gstin_differs_from_ledger`), GSTIN check digit | EXECUTED |
| F145 | implemented | orders, bills, approvals, exceptions, claims page through the server total | EXECUTED (tsc) / browser suites |
| F146 | implemented | failed loads show "Could not load" with retry, never an empty state; Settings shows no form/Save over a failed load; a dead session returns to sign-in | EXECUTED (test:remediation F146 x2) |
| F163 | partially_implemented | declared charges (the order's freight / other charges) are matched against the order's figure; the screen says they are expensed | EXECUTED. Books refuses landed cost on from_challan bills — capitalisation handed to E8-BK-POST/E8-INV |
| F167 | implemented | PO amendments (versioned, approved, applied), reopen | EXECUTED |
| F170 | partially_implemented | a supplier invoice spanning orders/kinds is entered as numbered parts under the supplier's own number (reason sent to Books' duplicate register) | EXECUTED. One Books voucher spanning several orders not implemented |
| F176 | implemented | supplier confirmed as a Books creditor; Books' name is the snapshot | EXECUTED |
| F212 | implemented | stock_busy / pre-write gates are FAILED (retryable), never BLOCKED | EXECUTED + CONTRACT (real Inventory lock held → FAILED → retry accepted) |
| F219 | implemented | `tests/http_gate.php`: every route over the real front controller, members/strangers/other tenants/revoked | EXECUTED (CI workflows stay manual-only) |
| F220 | implemented | see F122 | EXECUTED |
| F221 | implemented | repair reports bill double posts and the missing invoice index; apply scoped by company with a snapshot; migrate warns | EXECUTED |
| F222 | partially_implemented | Purchases proved against the REAL Inventory (`tests/contract_receivers.php`); regression checks for the money-path findings | CONTRACT. Real Books/Manage/my.aicountly receivers and CI execution are OPERATIONALLY-PENDING (E8-HARNESS) |
| F223 | partially_implemented | stub shapes corrected (companyinfo 404, lost-claim 409, html 2xx, replenishment keys and envelope, top_suppliers label, fiscal calendar); real Inventory contract | CONTRACT. Real Books contract pending (E8-HARNESS) |
| F226 | implemented | RFQ screens and API say nothing is sent; invitees/quoting suppliers must be creditors; tenant-checked; no unusable token | EXECUTED (+ test:remediation) |
| F228 | implemented | rematch only for MATCHING/MATCHED/EXCEPTION; post refuses cancelled | EXECUTED |
| F234 | implemented | reconcile/withdraw read only JSON answers; splash page/bare 404 → UNCERTAIN/503 | EXECUTED |
| F246 | implemented | debit notes name the original purchase (original_document) and settle its bill (against_bills) | EXECUTED |
| F265 | implemented | SoD on exceptions, returns, claims, resolutions; financial authority | EXECUTED |
| F266 | implemented | dates/values validated (422), PDO errors mapped (503 only for outages) | EXECUTED |
| F274 | implemented | open items keep Cr payables, Dr credits and ON ACCOUNT apart; net payable is Books'; planner never schedules a Dr row | EXECUTED |
| F275 | implemented | supplier names on bills, payables, returns, claims, profiles, RFQs; Books top_suppliers `label` read | EXECUTED |
| F281 | implemented | duplicate supplier DC on an order refused naming the GRN (confirm + reason for a genuine split); HTTP API requires a client token | EXECUTED |
| F283 | implemented | classify(): completed / uncertain / failed / blocked by answer shape | EXECUTED + CONTRACT |
| F301 | implemented | expired-lease POSTING receipts offer Reconcile/Retry | EXECUTED |
| F305 | implemented | AI commentary separate from the factual answer and dropped when it states ungrounded figures; 8 s bound; product-key faults read as configuration | EXECUTED |
| F306 | implemented | profile edits keep the access-management floor | EXECUTED |
| F310 | implemented | phone overflow (verified at 390px), person name, picker "more matches", Overview wording, bulk-post refresh, revoked member 403 | EXECUTED (test:ui) |
| F357 | implemented | product seat required for company configuration and people lists | EXECUTED (http_gate) |
| F373 | implemented | dashboards and Ask count issued orders only | EXECUTED |
| F374 | implemented | dead Books/Inventory client methods with wrong contracts removed; a test fails on any method without a caller | EXECUTED |
| F388 | implemented | received_at a real date, inside the FY, not future, company-local default; fy_range sent | EXECUTED |
| F402 | implemented | one Inventory read per order (document_ids + include_lines, Inventory receiver added); unreadable stops the bill (503) | EXECUTED + CONTRACT |
| F420 | implemented | amend/reopen; items, warehouses, supplier confirmed with Inventory/Books at create | EXECUTED |
| F424 | implemented | Manage refusal → 403 `company_access_denied`; unreadable → 503 | EXECUTED |
| F430 | implemented | PO header/line validation (rates, discount, tax, FX, dates) | EXECUTED |
| F448 | implemented | per-run stub state | EXECUTED |
| F449 | implemented | tenant ownership of master ids | EXECUTED |
| F450 | implemented | see F424 | EXECUTED |
| F451 | partially_implemented | browser suites updated to the rebuilt UI: test:ui 36/36, test:remediation 17/17 locally | EXECUTED. Not wired into CI: workflows are manual-only by rule — OPERATIONALLY-PENDING |
| F452 | implemented | fixed-point money in the web app with unit tests | EXECUTED |
| F458 | implemented | replenishment read maps Inventory's keys; stub matches Inventory | EXECUTED |
| F482 | implemented | approvals withdrawn on cancel; UNCERTAIN notice; Reconcile/Retry; same token + different body → 409; company-local dates; registered serials are reused on re-registration | EXECUTED |
| F483 | implemented | xlsx unpack caps and row cap; bin scripts 404 under a web SAPI | EXECUTED. Host 404 for /api/bin and /api/tests is OPERATIONALLY-PENDING |
| F489 | implemented | re-entry guard knows every product (+ configured keys); tests prove a call back is refused | EXECUTED |
| F497 | implemented | rematch needs bill.enter; scorecard POST needs supplier.manage, ordered_value cost.view; catalog profile only with supplier.view | EXECUTED |
| F510 | implemented | see F374; .env.example no longer offers BOOKS_SERVICE_KEY | EXECUTED |
| F517 | implemented | README lists the live modules; remediation doc corrected | STATIC |

## Handoffs

- **E8-INV**: purchase-return physical outward challan (accrual effect in Inventory and Books) — F017.
- **E8-BK-POST / E8-INV**: landed cost on bills that settle goods receipts (Books refuses landed cost
  on `from_challan`) — F163; one voucher spanning several orders — F170.
- **E8-HARNESS**: real Books / Manage / my.aicountly receivers for F222/F223, and CI execution
  (workflows here stay manual-only).
- **Operator**: confirm the host answers 404 for `/api/bin` and `/api/tests` (F483); run
  `bin/receipt-repair.php --cmp=<id>` per company after deploy and act on `bill_double_posts`,
  `duplicate_invoices`, `missing_indexes` (F221).

## Merging beside the other packages' branches

`origin/main` is fully merged into `claude/e8-pur` in all three repositories (2026-10-11). Textual
conflicts expected when the package branches are combined, and how to resolve them:

- purchases vs `claude/e8-id`: `src/Context.php` — keep this branch's 403 `company_access_denied`
  (`retryable: false`; `tests/http_gate.php` and `launch_readiness.php` assert that code) with E8-ID's
  503 wording; `src/IntegrationCommand.php` — keep this branch's `classify()` (it already honours
  E8-ID's `outcome_unknown`, tested); `src/Clients/BooksClient.php` — keep the deletions (F374/F510:
  E8-ID touched methods that have no caller).
- Inventory vs `claude/e8-inv`: `app/Services/IntegrationHealthService.php` — keep both health checks.
- Books vs `claude/e8-inv`: `CommercialVoucherShell.jsx`, `voucherPostFlow.test.js` — keep both
  (this branch's open-inward-challan confirmation beside E8-INV's additions).

