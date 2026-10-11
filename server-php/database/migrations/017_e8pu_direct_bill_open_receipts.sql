-- ---------------------------------------------------------------------------
-- 017 — a direct bill over goods already received (launch readiness E8-PUR: A09, F010)
--
-- A bill with no purchase order receives its goods itself (stock_effect on_invoice). Over goods a
-- goods receipt of the same supplier already brought in and nobody has billed, that receives them
-- a second time. A direct bill for stock items is now checked against Inventory's open receipts
-- of the supplier when it is entered; going ahead needs a stated confirmation that these are
-- different goods, which is kept here (who, when, why, which receipts) and carried to Books and
-- Inventory with the bill (confirm_no_open_grn).
--
-- Additive and idempotent: one nullable column. Existing bills are untouched.
-- ---------------------------------------------------------------------------

ALTER TABLE purchase_bill_requests
    ADD COLUMN IF NOT EXISTS open_grn_confirmation JSONB;
