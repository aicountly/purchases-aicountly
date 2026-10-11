-- 020 (E8-INV A11): a NEW company's goods receipts put the goods in stock.
--
-- purchase_settings.receive_stock_at_grn defaulted to FALSE (migration 006), so every company
-- created for the launch started in challan_only: goods accepted at a GRN sat on Inventory's
-- pending-in register, out of on-hand and availability, with no GRNI accrual and no provisional
-- valuation, until the supplier bill posted. The intended workflow is the physical receipt at the
-- GRN at the order's net rate, accrued to GRNI, then the bill trues the cost up without receiving
-- the goods again (Inventory ReceiptCostTrueUpService + Books GRNI journal).
--
-- New rows now default to TRUE. EXISTING rows keep their value: a company's receipts already
-- recorded keep the stock_effect stored on them (a bill spanning both kinds is refused by
-- Inventory), and switching an existing company is the per-company operator step in
-- docs/launch-readiness-2026-10/E8-INV.md (GRNI ledger present in Books, Inventory true-up
-- deployed, a test GRN -> bill on that company).
--
-- Who changed the mode and when are recorded so Settings and the order's receive panel can say it.
-- Additive and re-runnable.

ALTER TABLE purchase_settings ALTER COLUMN receive_stock_at_grn SET DEFAULT TRUE;
ALTER TABLE purchase_settings ADD COLUMN IF NOT EXISTS receive_stock_at_grn_changed_at TIMESTAMPTZ;
ALTER TABLE purchase_settings ADD COLUMN IF NOT EXISTS receive_stock_at_grn_changed_by TEXT;
