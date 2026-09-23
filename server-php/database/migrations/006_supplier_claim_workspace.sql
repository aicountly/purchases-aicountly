-- ---------------------------------------------------------------------------
-- Aicountly Purchases — the supplier claim workspace
--
-- A claim used to be four facts: a supplier, a kind, an amount and a paragraph.
-- That is enough to record that money is owed and not nearly enough to recover
-- it. The supplier asks which order, which invoice, which delivery note, how
-- many pieces short and at what rate — and every one of those answers was
-- being typed into the description, where nothing can filter it, total it or
-- show it back on a list.
--
-- So the facts get columns, and the pieces-short get rows.
--
-- ADDITIVE ONLY. Every existing column keeps its meaning, every existing query
-- returns exactly what it returned before, and a claim raised by the old screen
-- is a valid claim here: the new columns all carry a default, and a claim with
-- no lines is still a claim with an amount on it.
--
-- WHAT IS DELIBERATELY NOT HERE:
--
--   No supplier, item, order or ledger is copied into these tables. A claim
--   holds the id of the thing it is about and reads the rest live from the
--   product that owns it -- Books for the party, Inventory for the item, and
--   this product's own orders, bills, receipts and returns for the references.
--
--   No accounting. Where an approved claim has a consequence for the payable,
--   Books records it and this row keeps the uuid of what Books wrote, exactly
--   as `books_debit_note_uuid` already does.
-- ---------------------------------------------------------------------------

ALTER TABLE purchase_claims
    -- The one line a buyer reads on a list. The description is the story; this
    -- is the headline, and without it every claim on a list read "shortage".
    ADD COLUMN IF NOT EXISTS subject TEXT NOT NULL DEFAULT '',

    -- What this claim is about, beside the `po_id` that was already here. All
    -- nullable and all SET NULL on delete: a claim outlives the document that
    -- prompted it, and losing the claim because a draft bill was removed would
    -- be losing the money.
    ADD COLUMN IF NOT EXISTS bill_request_id BIGINT REFERENCES purchase_bill_requests(request_id) ON DELETE SET NULL,
    ADD COLUMN IF NOT EXISTS receipt_request_id BIGINT REFERENCES purchase_receipt_requests(request_id) ON DELETE SET NULL,
    ADD COLUMN IF NOT EXISTS return_id BIGINT REFERENCES purchase_returns(return_id) ON DELETE SET NULL,

    -- What we are asking the supplier FOR. A claim settled as a credit note and
    -- a claim settled as a replacement are different negotiations, and which
    -- one it is was previously only ever stated in prose.
    -- credit_note | refund | replacement | rate_adjustment | future_adjustment | other
    ADD COLUMN IF NOT EXISTS requested_resolution TEXT,
    ADD COLUMN IF NOT EXISTS expected_resolution_date DATE,

    -- Who is chasing it, on both sides. Free text rather than a foreign key:
    -- the supplier's contact is the supplier's employee, who belongs to no
    -- table here, and the internal owner is a name the buyer types when it is
    -- not themselves.
    ADD COLUMN IF NOT EXISTS supplier_contact TEXT,
    ADD COLUMN IF NOT EXISTS internal_owner TEXT,

    -- low | normal | high | urgent
    ADD COLUMN IF NOT EXISTS priority TEXT NOT NULL DEFAULT 'normal',

    -- TWO NOTE FIELDS, AND THE SEPARATION IS THE POINT. `internal_notes` is
    -- what we say to each other about this supplier; `supplier_notes` is what
    -- we are willing to send them. One field for both is one field that ends up
    -- attached to an email.
    ADD COLUMN IF NOT EXISTS internal_notes TEXT,
    ADD COLUMN IF NOT EXISTS supplier_notes TEXT,

    ADD COLUMN IF NOT EXISTS tags JSONB NOT NULL DEFAULT '[]'::jsonb,

    -- Recorded, not acted on: this product sends the supplier nothing today.
    -- It is the buyer's stated intent, kept so the eventual notification has
    -- something to read rather than being retro-fitted with a guess.
    ADD COLUMN IF NOT EXISTS notify_supplier BOOLEAN NOT NULL DEFAULT FALSE;

-- The claim, itemised.
--
-- WHY A TABLE AND NOT JSONB. `purchase_receipt_requests.requested_lines` is
-- JSONB because it is a message to Inventory that is written once and read
-- back whole. Claim lines are neither: they are totalled, they are compared
-- with the order they came from, and the supplier disputes them one at a time.
-- Anything that gets aggregated belongs in rows.
CREATE TABLE IF NOT EXISTS purchase_claim_lines (
    line_id         BIGSERIAL PRIMARY KEY,
    cmp_id          BIGINT       NOT NULL,
    claim_id        BIGINT       NOT NULL REFERENCES purchase_claims(claim_id) ON DELETE CASCADE,
    line_no         INTEGER      NOT NULL,

    -- Inventory owns the item. This is its id and, beside it, the description
    -- as it read when the claim was raised -- a snapshot for the same reason
    -- `purchase_orders.supplier_name_snapshot` is one: the claim must still be
    -- readable in two years when the item has been renamed or retired.
    item_id         BIGINT,
    description     TEXT,

    -- Which document this line argues from, as a kind and the number the
    -- supplier will recognise. po | bill | grn | return | none
    reference_kind  TEXT         NOT NULL DEFAULT 'none',
    reference_no    TEXT,

    -- What was ordered and what actually arrived, carried onto the line so the
    -- arithmetic of a shortage is visible on the claim itself rather than only
    -- in the order it was derived from.
    ordered_qty     NUMERIC(18,4) NOT NULL DEFAULT 0,
    received_qty    NUMERIC(18,4) NOT NULL DEFAULT 0,

    claim_qty       NUMERIC(18,4) NOT NULL DEFAULT 0,
    rate            NUMERIC(18,4) NOT NULL DEFAULT 0,
    -- NOT always qty x rate. A scheme that was not passed on is an amount with
    -- no quantity behind it, and forcing one would mean inventing a quantity.
    claim_amount    NUMERIC(18,4) NOT NULL DEFAULT 0,

    reason          TEXT,
    created_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW(),

    UNIQUE (claim_id, line_no)
);

CREATE INDEX IF NOT EXISTS idx_purchase_claim_lines_claim ON purchase_claim_lines (cmp_id, claim_id);

-- The two lookups the new screen makes on every claim: "show me this supplier's
-- open claims" (already served by idx_purchase_claims_supplier) and "has this
-- order been claimed against before", which is what stops the same shortage
-- being raised twice.
CREATE INDEX IF NOT EXISTS idx_purchase_claims_po ON purchase_claims (cmp_id, po_id) WHERE po_id IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_purchase_claims_bill ON purchase_claims (cmp_id, bill_request_id) WHERE bill_request_id IS NOT NULL;
