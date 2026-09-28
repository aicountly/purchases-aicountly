-- ---------------------------------------------------------------------------
-- Aicountly Purchases — segregation of duties as a stated policy; awards that become
-- orders once
--
-- sod_policy   strict             nobody approves a document they raised
--              owner_with_reason  as strict, except the company owner, who may approve
--                                 their own document with a reason — recorded and audited
--                                 (sod.owner_exception). The default, because owners could
--                                 always approve their own; what changes is that they now
--                                 say why, and the audit trail shows it.
--
-- purchase_bid_awards.po_id   the order an award became. An award converts once; a
--                             second conversion is refused while that order stands (a
--                             cancelled order frees the award to be converted again).
-- ---------------------------------------------------------------------------

ALTER TABLE purchase_settings
    ADD COLUMN IF NOT EXISTS sod_policy TEXT NOT NULL DEFAULT 'owner_with_reason';

ALTER TABLE purchase_bid_awards
    ADD COLUMN IF NOT EXISTS po_id        BIGINT REFERENCES purchase_orders(po_id) ON DELETE SET NULL,
    ADD COLUMN IF NOT EXISTS converted_at TIMESTAMPTZ,
    ADD COLUMN IF NOT EXISTS converted_by TEXT;

CREATE INDEX IF NOT EXISTS idx_purchase_bid_awards_quote ON purchase_bid_awards (cmp_id, quote_id);
