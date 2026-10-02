-- ---------------------------------------------------------------------------
-- 015 — goods received but not billed, given back (launch, October 2026; C10)
--
-- A GRN Inventory holds and no bill has settled can now be undone from here, through
-- Inventory's own endpoints (docs/INVENTORY_API_CONTRACT.md, "Returning goods received but
-- not billed"): all of it with reverse, part of it with revise to the quantity kept.
--
-- purchase_receipt_requests.status gains
--   RETURNING   a return or reversal is on its way to Inventory: the receipt is not billable
--               until Inventory has answered
--   REVERSED    Inventory holds nothing of it any more; it counts for nothing
-- (a receipt partly returned stays ACCEPTED, for the quantity kept, under the replacement
-- document Inventory made — inventory_document_id/uuid/no are the replacement's).
--
-- pending_return   what the return in flight will do once Inventory confirms it
-- return_history   each return or reversal applied: when, by whom, why, which quantities,
--                  the document before and the document after. Append-only by convention.
-- ---------------------------------------------------------------------------

ALTER TABLE purchase_receipt_requests
    ADD COLUMN IF NOT EXISTS pending_return JSONB,
    ADD COLUMN IF NOT EXISTS return_history JSONB NOT NULL DEFAULT '[]'::jsonb,
    ADD COLUMN IF NOT EXISTS reversed_at    TIMESTAMPTZ;
