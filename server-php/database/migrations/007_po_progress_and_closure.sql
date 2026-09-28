-- ---------------------------------------------------------------------------
-- Aicountly Purchases — what "done" means for a purchase order
--
-- A purchase order was closed the moment everything received so far had been
-- billed: order 100, receive 40, bill 40, and the order closed with 60 never
-- delivered and gone from every open list. Receiving, billing and closure are
-- three different facts and are now recorded as three:
--
--   receipt_status  NOT_STARTED | PARTIAL | COMPLETE
--                   every stock line's net received quantity has reached what
--                   is still wanted (ordered less short-closed)
--   billing_status  NOT_BILLED | PARTIAL | COMPLETE
--                   everything received has been billed, and nothing wanted is
--                   still to arrive
--   status          CLOSED only when both are COMPLETE and no bill against the
--                   order has an open match exception — or by explicit action
--
-- Whether the supplier has been PAID is none of these: that is the payable, and
-- it is Books'.
-- ---------------------------------------------------------------------------

ALTER TABLE purchase_orders
    ADD COLUMN IF NOT EXISTS receipt_status TEXT NOT NULL DEFAULT 'NOT_STARTED',
    ADD COLUMN IF NOT EXISTS billing_status TEXT NOT NULL DEFAULT 'NOT_BILLED',
    ADD COLUMN IF NOT EXISTS closed_at      TIMESTAMPTZ,
    ADD COLUMN IF NOT EXISTS closed_by      TEXT,
    -- auto (both halves complete) | short_close (explicit, with a reason)
    ADD COLUMN IF NOT EXISTS closure_kind   TEXT,
    ADD COLUMN IF NOT EXISTS closure_reason TEXT;

ALTER TABLE purchase_order_lines
    -- What will never be delivered, by decision. Receipts and liabilities already
    -- recorded are untouched; this only stops the rest being waited for.
    ADD COLUMN IF NOT EXISTS short_closed_qty NUMERIC(18,4) NOT NULL DEFAULT 0,
    -- Returned goods whose debit note Books has posted: billed and then credited
    -- back, so no longer part of what the order still owes a bill for.
    ADD COLUMN IF NOT EXISTS debited_qty      NUMERIC(18,4) NOT NULL DEFAULT 0;

-- Orders closed by the old rule while goods were still due are reopened for
-- receiving. CANCELLED is never touched.
UPDATE purchase_orders p
   SET status = CASE WHEN EXISTS (SELECT 1 FROM purchase_order_lines l
                                   WHERE l.po_id = p.po_id AND l.received_qty > 0)
                     THEN 'PARTIALLY_RECEIVED' ELSE 'ISSUED' END,
       updated_at = NOW()
 WHERE p.status = 'CLOSED'
   AND p.closure_kind IS NULL
   AND EXISTS (SELECT 1 FROM purchase_order_lines l
                WHERE l.po_id = p.po_id AND l.is_service = FALSE
                  AND l.received_qty - l.returned_qty < l.ordered_qty - l.short_closed_qty);

-- Every order's two progress figures, from its lines as they stand.
UPDATE purchase_orders p
   SET receipt_status = CASE
           WHEN NOT EXISTS (SELECT 1 FROM purchase_order_lines l WHERE l.po_id = p.po_id AND l.is_service = FALSE)
                THEN 'COMPLETE'
           WHEN NOT EXISTS (SELECT 1 FROM purchase_order_lines l
                             WHERE l.po_id = p.po_id AND l.is_service = FALSE
                               AND l.received_qty - l.returned_qty < l.ordered_qty - l.short_closed_qty)
                THEN 'COMPLETE'
           WHEN EXISTS (SELECT 1 FROM purchase_order_lines l WHERE l.po_id = p.po_id AND l.received_qty > 0)
                THEN 'PARTIAL'
           ELSE 'NOT_STARTED' END,
       billing_status = CASE
           WHEN NOT EXISTS (SELECT 1 FROM purchase_order_lines l WHERE l.po_id = p.po_id AND l.billed_qty > 0)
                THEN 'NOT_BILLED'
           ELSE 'PARTIAL' END;

-- A closed order that the rule above left closed was genuinely complete.
UPDATE purchase_orders
   SET closure_kind = 'auto', closed_at = COALESCE(closed_at, updated_at), billing_status = 'COMPLETE'
 WHERE status = 'CLOSED' AND closure_kind IS NULL;

CREATE INDEX IF NOT EXISTS idx_purchase_orders_progress
    ON purchase_orders (cmp_id, receipt_status, billing_status)
    WHERE status NOT IN ('CANCELLED', 'CLOSED');
