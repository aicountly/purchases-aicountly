-- ---------------------------------------------------------------------------
-- 014 — place of supply and the tax a debit note reverses (launch, October 2026)
--
-- Books composes a purchase's GST from party.pos_state_code (the counterparty's state,
-- compared with the branch's) and refuses a GST-categorised voucher whose place of supply
-- is unknown. Purchase now sends it — from the supplier's GSTIN, or the GSTIN on the bill
-- when the supplier invoices from another registration — and carries the billed tax
-- category onto the debit notes that reverse a purchase.
--
-- purchase_bill_requests.supplier_gstin   the GSTIN printed on the supplier's invoice,
--                                         when it is not the one on their Books ledger
--                                         (a supplier registered in several states).
--                                         NULL = the ledger's.
-- purchase_return_lines.tax_cat_id/hsn_sac   the Books tax category (and HSN/SAC) the goods
--                                         or the adjustment were billed under; the debit note
--                                         reverses the same tax. NULL = Books decides (item
--                                         default), as before.
-- purchase_claim_resolutions.tax_cat_id/hsn_sac   the same for a claim settled by a debit note.
--
-- Additive and nullable: nothing existing changes meaning.
-- ---------------------------------------------------------------------------

ALTER TABLE purchase_bill_requests
    ADD COLUMN IF NOT EXISTS supplier_gstin TEXT;

ALTER TABLE purchase_return_lines
    ADD COLUMN IF NOT EXISTS tax_cat_id BIGINT,
    ADD COLUMN IF NOT EXISTS hsn_sac    TEXT;

ALTER TABLE purchase_claim_resolutions
    ADD COLUMN IF NOT EXISTS tax_cat_id BIGINT,
    ADD COLUMN IF NOT EXISTS hsn_sac    TEXT;
