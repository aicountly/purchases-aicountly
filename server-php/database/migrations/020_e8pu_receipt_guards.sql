-- ---------------------------------------------------------------------------
-- 020 — goods receipt guards (launch readiness E8-PUR: F281, F482, F009)
--
-- request_fingerprint: a digest of what a receipt request asked for (lines, dates, delivery
--   challan), so a replay of the same client token with a DIFFERENT body is refused (409) instead
--   of silently answering with the first receipt. NULL on receipts recorded before this; those
--   keep answering by token alone.
-- duplicate_dc_reason: why a second receipt naming the same supplier delivery challan on the same
--   order was recorded anyway (a confirmed split delivery under one challan). Refused without it.
--
-- Additive and idempotent; no existing row is changed. Expiry and manufacturing dates travel on
-- the receipt's requested_lines JSON, so they need no column.
-- ---------------------------------------------------------------------------

ALTER TABLE purchase_receipt_requests
    ADD COLUMN IF NOT EXISTS request_fingerprint TEXT,
    ADD COLUMN IF NOT EXISTS duplicate_dc_reason TEXT;

CREATE INDEX IF NOT EXISTS idx_purchase_receipt_requests_dc
    ON purchase_receipt_requests (cmp_id, po_id, lower(supplier_dc_no))
    WHERE supplier_dc_no IS NOT NULL;
