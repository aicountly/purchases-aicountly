-- ---------------------------------------------------------------------------
-- 019 — reverse charge and the supplier GSTIN on a bill (launch readiness E8-PUR: F122, F220)
--
-- Purchase never told Books a bill was under reverse charge, so an import of services or an
-- RCM-notified supply was booked as an ordinary purchase: no RCM liability, the GST outcome
-- silently wrong. A bill now carries is_reverse_charge (marked by the buyer, defaulted from the
-- supplier's ledger for services from abroad) and sends it to Books; rcm_basis says why.
--
-- A GSTIN typed on the bill that differs from the one on the supplier's ledger in Books changes
-- which tax is charged (it decides the place of supply); it now needs a stated reason, kept in
-- supplier_gstin_reason, and a typed GSTIN must pass the GSTIN check digit.
--
-- Additive and idempotent. Existing bills are not reverse charge, as they were booked.
-- ---------------------------------------------------------------------------

ALTER TABLE purchase_bill_requests
    ADD COLUMN IF NOT EXISTS is_reverse_charge BOOLEAN NOT NULL DEFAULT FALSE,
    ADD COLUMN IF NOT EXISTS rcm_basis TEXT,
    ADD COLUMN IF NOT EXISTS supplier_gstin_reason TEXT;
