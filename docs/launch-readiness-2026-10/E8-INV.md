# E8-INV — Purchases part (goods receipt stock contract)

Package E8-INV (Inventory and Books stock accounting). This repository carries finding **A11**; the
rest of the package is in Inventory-aicountly and books-react-app (same file name there).

## A11 — accepted GRN moves no stock until the bill (implemented)

Owner decision applied (brief §5): the intended receipt workflow is the default **for new
companies**; historical receipts keep their recorded stock effect.

- `server-php/database/migrations/020_e8in_grn_receives_stock_default.sql`: `purchase_settings.receive_stock_at_grn`
  defaults to TRUE for new rows; adds `receive_stock_at_grn_changed_at` / `_changed_by`. Existing rows
  are not changed.
- `ReceiptService::stockEffectFor()`: a company with no settings row and no receipt is new and takes
  the default (physical); one with receipts and no row keeps `challan_only` until switched.
- `SettingsController`: the first settings row is written with the mode the company already works
  under (opening Settings never flips it); a switch records who and when and is audited
  (`settings.controls_changed`).
- The order's receive panel and Settings → Purchase profile show the current mode and who last
  changed it (`grn_stock_effect_changed`, `stockModeChangedText`).

Evidence (EXECUTED, local PostgreSQL 16, `TEST_DB_NAME=e8in_pur_test ... bash server-php/tests/run.sh`):
151 + 150 + 23 + 89 passed, 0 failed — including the new A11 checks
("a new company puts goods into stock at the goods receipt; an existing one keeps its mode",
"switching the receipt stock mode records who and when, and the order says it"). Tests that pinned
the old default now set `receive_stock_at_grn = false` explicitly (they test the challan-only path).
Web: `npm test` 11 pass (stockMode.test.ts), `npx tsc -b` clean.

The bill over a physical GRN is settled by Inventory without receiving again, the true-up uses the
NET billed rate (Inventory A04) and Books clears GRNI — covered in Inventory
`tests/integration/GoodsReceiptBeforeBillTest.php` and `DiscountedPurchaseValuationTest.php`.
Preventing a direct bill from receiving goods already covered by an open GRN is E8-PUR's
`017_e8pu_direct_bill_open_receipts` (branch claude/e8-pur).

### Operator enablement checklist for an EXISTING company (operationally pending — never automatic)

1. Books has a Goods Received Not Invoiced ledger mapped for the company (Inventory journal accounts).
2. The Inventory release carrying `ReceiptCostTrueUpService` and A04 (net billed rate) is deployed.
3. No open (unbilled) challan-only receipt that a future bill would combine with a physical one —
   bill or return them first (a mixed bill is refused, "bill them separately").
4. Switch in Settings → Purchase profile → "Put received goods into stock at the goods receipt"
   (recorded with who/when), then run one test GRN → bill on that company and check: on hand up at
   the order rate at the GRN, GRNI accrued; bill posts with stock unchanged, GRNI cleared, variance
   journalled.

Deploy order: Inventory (A04 + true-up) → Books (GRNI) → Purchases (this migration).
