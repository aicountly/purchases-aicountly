-- ---------------------------------------------------------------------------
-- Aicountly Purchases — the purchase return workspace
--
-- The returns register could answer "which returns exist" and almost nothing
-- else. A buyer working this screen asks four more questions before they act,
-- and none of them could be answered from these tables:
--
--   who is this return against          only an account id was stored
--   has the supplier credited us yet    nothing recorded it at all
--   when are the goods being collected  nothing recorded it at all
--   why was this one abandoned          CANCELLED with no reason beside it
--
-- WHAT THIS IS NOT. None of the columns below are a copy of anything Inventory
-- or Smart Books owns. The stock movement stays in Inventory and is read from
-- `inventory_document_uuid`; the debit note stays in Books and is read from
-- `books_debit_note_uuid`; the payable is Books' and is never mirrored here.
--
-- The supplier credit columns are the one that looks closest to accounting and
-- is not. A supplier-issued credit note is THEIR document, and what is kept
-- here is the buyer's side of the negotiation — whether it has arrived and
-- under what reference — which is the same class of fact as the return reason
-- and belongs to the procurement workflow this product owns. The accounting
-- consequence of it is Books', posted by Books, read from Books.
--
-- `supplier_name_snapshot` follows purchase_orders, which has carried one since
-- the first migration: the label as it read on the day, so a register lists
-- suppliers by name without asking Books for five hundred ledger names to draw
-- one page, and so a supplier later renamed does not rewrite last year's
-- documents.
--
-- ADDITIVE ONLY. Every existing query returns exactly what it returned before.
-- ---------------------------------------------------------------------------

ALTER TABLE purchase_returns
    -- The supplier's name as it read when the return was raised. A label, not
    -- the ledger: Books remains the only owner of the account itself.
    ADD COLUMN IF NOT EXISTS supplier_name_snapshot TEXT,

    -- When the goods are expected to be collected or sent back. Logistics
    -- coordination is this product's half of the return, and the calendar view
    -- plots it beside the return date.
    ADD COLUMN IF NOT EXISTS expected_pickup_date DATE,

    -- The supplier's own credit note against this return: PENDING, RECEIVED or
    -- NOT_REQUIRED. Deliberately distinct from `books_debit_note_uuid`, which
    -- is OUR accounting document for the same event. A return can have one
    -- without the other, and a screen that conflates them tells the buyer the
    -- supplier has paid up when only our own books say so.
    ADD COLUMN IF NOT EXISTS supplier_credit_status TEXT NOT NULL DEFAULT 'PENDING',
    ADD COLUMN IF NOT EXISTS supplier_credit_ref TEXT,
    ADD COLUMN IF NOT EXISTS supplier_credit_date DATE,
    ADD COLUMN IF NOT EXISTS supplier_credit_amount NUMERIC(18,4),

    -- Why a return was abandoned. purchase_orders records the same thing for
    -- the same reason: a cancelled document with no reason beside it is a
    -- question somebody has to go and ask a person.
    ADD COLUMN IF NOT EXISTS cancel_reason TEXT;

-- The name is only backfilled where this product already had it: the purchase
-- order the return was raised against. Nothing is fetched from Books to fill
-- these in — a backfill that calls another product is a migration that fails
-- when that product is down.
UPDATE purchase_returns r
   SET supplier_name_snapshot = p.supplier_name_snapshot
  FROM purchase_orders p
 WHERE p.po_id = r.po_id
   AND r.supplier_name_snapshot IS NULL
   AND p.supplier_name_snapshot IS NOT NULL;

-- The register is read by date within a company and financial year, filtered
-- by status, and totalled by month. These are the two indexes those reads want.
CREATE INDEX IF NOT EXISTS idx_purchase_returns_scope_date
    ON purchase_returns (cmp_id, fy_id, return_date DESC);

CREATE INDEX IF NOT EXISTS idx_purchase_returns_scope_status
    ON purchase_returns (cmp_id, fy_id, status);

-- Every line total on the register and every reason share on the donut is a
-- sum over this table keyed by its return.
CREATE INDEX IF NOT EXISTS idx_purchase_return_lines_return
    ON purchase_return_lines (return_id);
