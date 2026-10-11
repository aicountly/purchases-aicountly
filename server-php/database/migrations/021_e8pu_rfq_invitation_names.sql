-- ---------------------------------------------------------------------------
-- 021 — RFQ invitations name the supplier (launch readiness E8-PUR: F226, F275)
--
-- An invitation is a record that a supplier was asked, and nothing is sent from Purchases:
-- the RFQ screen says so. Each invitation now carries the supplier's name as Smart Books held
-- it when they were asked (confirmed a creditor at that moment), so the screen no longer shows
-- "Account 601". The unused portal token is no longer generated (no portal exists to use it);
-- existing hashes are left as they are — they open nothing.
--
-- Additive and idempotent.
-- ---------------------------------------------------------------------------

ALTER TABLE purchase_rfq_invitations
    ADD COLUMN IF NOT EXISTS supplier_name_snapshot TEXT;
