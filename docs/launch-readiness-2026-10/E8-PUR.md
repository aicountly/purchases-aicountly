# E8-PUR — Purchases lifecycle, matching and payables (launch readiness 2026-10)

Branch `claude/e8-pur`, redone on current `main` (8ea952f at start). Every finding was first confirmed
present at main HEAD: the money paths it names (BillService, ThreeWayMatchService, IntegrationCommand,
ReceiptService, ReturnClaimService, PurchaseOrderService) had not changed since the inspection baseline
(migrations ended at 015).

Evidence labels: EXECUTED (run here, PostgreSQL + HTTP stub or real receiver), CONTRACT (real receiver
code over HTTP), STATIC, OPERATIONALLY-PENDING.

## Status per finding

| Id | Status | What changed | Evidence |
| --- | --- | --- | --- |
| F448 | implemented | `tests/run.sh` gives each run its own stub state directory (TMPDIR / STUB_STATE_DIR) | EXECUTED: run.sh |
| A26 | implemented | REJECTED exception always blocks posting (409 `match_exception_rejected`, nothing sent); bill moves to REJECTED; only Revise (matched afresh) or Cancel; migration 016 moves existing rejected bills | EXECUTED: launch_readiness.php A26 x2 (fail on baseline) |
| A31 | implemented | match compares NET unit prices (line_amount/qty vs bill amount/qty), aggregates every bill line per order line (qty and value), checks each line and the aggregate; optional absolute value tolerance; bill form carries the PO discount and shows the net agreed rate | EXECUTED: launch_readiness.php A31 x2 |
| F228 | implemented | rematch only for MATCHING/MATCHED/EXCEPTION (409 otherwise, status unchanged); post refuses any row with cancelled_at; UI hides Re-run for other states | EXECUTED |
| F265 | implemented | SoD (company policy) on accepting a bill exception, approving a return, a claim and a claim resolution; financial_adjustment resolutions need return.financial_adjustment; UI asks the owner for the reason (sod_reason_required) and resends | EXECUTED |
| F497 | partially (rematch) | rematch needs bill.enter; decisions carry over to identical findings on the same revision (fingerprint, carried_from_exception_id); exceptions of superseded matches cannot be decided | EXECUTED |
| F452 | implemented | `web/src/utils/decimal.ts` fixed-point money for PO and bill totals; node --test unit tests | EXECUTED: npm test |

(Updated as work proceeds.)
