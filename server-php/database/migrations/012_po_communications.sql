-- ---------------------------------------------------------------------------
-- Aicountly Purchases — what happened between the order and the supplier
--
-- Three different facts used to be one status:
--   prepared      the order's document was produced (downloaded / printed), with the
--                 fingerprint of exactly what it said
--   sent          it reached the supplier, by a named channel, to a named recipient
--   acknowledged  the supplier confirmed it — and how we know: the source (their
--                 email, a call, their own document, in person) and the evidence
--
-- ISSUED still means "released to the supplier"; it is no longer taken to mean
-- the supplier has it. Acknowledgement needs a recorded sending first, and its
-- evidence is required. There is no supplier portal: an acknowledgement is recorded
-- by the buyer, from what the supplier sent.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS purchase_po_communications (
    communication_id  BIGSERIAL    PRIMARY KEY,
    cmp_id            BIGINT       NOT NULL,
    po_id             BIGINT       NOT NULL REFERENCES purchase_orders(po_id) ON DELETE CASCADE,
    kind              TEXT         NOT NULL,        -- prepared | sent | acknowledged
    channel           TEXT,                          -- download | email | whatsapp | courier | hand_delivered | connect | other
    recipient         TEXT,
    source            TEXT,                          -- acknowledgements: supplier_email | phone | supplier_document | in_person | other
    evidence          TEXT,
    note              TEXT,
    document_fingerprint TEXT,
    occurred_on       DATE,
    recorded_by       TEXT         NOT NULL,
    created_at        TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_purchase_po_communications_po ON purchase_po_communications (cmp_id, po_id, created_at);

ALTER TABLE purchase_orders
    ADD COLUMN IF NOT EXISTS sent_at                TIMESTAMPTZ,
    ADD COLUMN IF NOT EXISTS acknowledged_by        TEXT,
    ADD COLUMN IF NOT EXISTS acknowledgement_source TEXT;
