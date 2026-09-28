-- ---------------------------------------------------------------------------
-- Aicountly Purchases — retry-safe integration commands, and receipts that are
-- documents of their own
--
-- Two defects, one migration, because the second cannot be fixed without the
-- first:
--
-- 1. A command's idempotency key had a random tail and was minted after a plain
--    SELECT found no row. Two requests arriving together each minted a key and
--    each called the other product. The key is now derived from the business
--    operation (company, command, entity, revision), one row per operation is
--    enforced here, and the exact request body is stored with it — Inventory and
--    Books refuse a reused key whose body differs, so a retry must send the same
--    bytes the first attempt sent.
--
-- 2. Every receipt against a purchase order was posted to Inventory under the
--    ORDER's identity, so Inventory's one-document-per-source guard answered the
--    second delivery with the first delivery's document. A receipt now has its
--    own uuid and number, and that is the identity Inventory is given; the order
--    is carried beside it as a reference.
-- ---------------------------------------------------------------------------

-- --- Integration commands ---------------------------------------------------

ALTER TABLE purchase_integration_commands
    -- A legitimate amendment (a bill corrected after Books refused it) is a new
    -- operation and gets a new revision, therefore a new key. A retry is not.
    ADD COLUMN IF NOT EXISTS revision         INT          NOT NULL DEFAULT 0,
    -- The body sent on the first attempt, replayed verbatim by every retry.
    -- Our request, not a copy of anything the other product owns.
    ADD COLUMN IF NOT EXISTS request_payload  JSONB,
    -- Who holds the in-flight attempt, and until when. No row lock is held
    -- across the network call; an attempt that dies leaves a lease that
    -- expires, and the next attempt may take it.
    ADD COLUMN IF NOT EXISTS lease_token      TEXT,
    ADD COLUMN IF NOT EXISTS lease_expires_at TIMESTAMPTZ,
    -- The last HTTP status seen, 0 for no response at all.
    ADD COLUMN IF NOT EXISTS last_status_code INT,
    -- How the outcome was established: response | replay | reconcile.
    ADD COLUMN IF NOT EXISTS resolved_by      TEXT;

-- Rows raced into existence before this constraint existed keep their history:
-- each extra row of the same operation becomes a later revision of it, so the
-- unique index below can be created without deleting anything. The repair
-- report lists them (bin/receipt-repair.php).
WITH ranked AS (
    SELECT command_id,
           ROW_NUMBER() OVER (PARTITION BY cmp_id, command_type, entity_type, entity_id
                              ORDER BY command_id) - 1 AS rn
      FROM purchase_integration_commands
)
UPDATE purchase_integration_commands c
   SET revision = ranked.rn
  FROM ranked
 WHERE c.command_id = ranked.command_id
   AND ranked.rn > 0
   AND c.revision = 0;

CREATE UNIQUE INDEX IF NOT EXISTS uq_purchase_commands_operation
    ON purchase_integration_commands (cmp_id, command_type, entity_type, entity_id, revision);

-- UNCERTAIN joins the open set: the other product may or may not have acted,
-- and until somebody retries or reconciles, the document is not settled.
DROP INDEX IF EXISTS idx_purchase_commands_open;
CREATE INDEX IF NOT EXISTS idx_purchase_commands_open ON purchase_integration_commands (cmp_id, status)
    WHERE status IN ('PENDING', 'POSTING', 'FAILED', 'UNCERTAIN', 'BLOCKED');

-- --- Receipts ------------------------------------------------------------------

ALTER TABLE purchase_receipt_requests
    ADD COLUMN IF NOT EXISTS receipt_uuid         UUID        NOT NULL DEFAULT gen_random_uuid(),
    ADD COLUMN IF NOT EXISTS receipt_no           TEXT,
    -- The form instance that submitted it. A replayed submission finds its own
    -- receipt instead of recording the same delivery twice; a second delivery
    -- is a second form, and a second receipt.
    ADD COLUMN IF NOT EXISTS client_token         TEXT,
    -- The identity Inventory was given. 'purchases.order' marks a receipt
    -- posted under its order's identity before this migration.
    ADD COLUMN IF NOT EXISTS source_document_type TEXT,
    -- What the GRN is in Inventory, and how it moved stock.
    ADD COLUMN IF NOT EXISTS document_type        TEXT,
    ADD COLUMN IF NOT EXISTS stock_effect         TEXT,
    -- Set in the same transaction that adds this receipt to the order's
    -- received quantities, and only when it is still NULL: that is what makes
    -- the addition happen exactly once, whether the confirmation came from the
    -- response, a replay or a reconcile.
    ADD COLUMN IF NOT EXISTS applied_at           TIMESTAMPTZ,
    ADD COLUMN IF NOT EXISTS applied_lines        JSONB,
    ADD COLUMN IF NOT EXISTS inspection_note      TEXT,
    ADD COLUMN IF NOT EXISTS over_receipt_reason  TEXT,
    ADD COLUMN IF NOT EXISTS over_receipt_by      TEXT,
    ADD COLUMN IF NOT EXISTS cancelled_at         TIMESTAMPTZ,
    ADD COLUMN IF NOT EXISTS cancel_reason        TEXT;

-- Everything recorded before now was posted under the order's identity and
-- had its quantities added when it was accepted.
UPDATE purchase_receipt_requests
   SET source_document_type = 'purchases.order'
 WHERE source_document_type IS NULL;

UPDATE purchase_receipt_requests
   SET applied_at = COALESCE(updated_at, created_at)
 WHERE status = 'ACCEPTED' AND applied_at IS NULL;

ALTER TABLE purchase_receipt_requests
    ALTER COLUMN source_document_type SET DEFAULT 'purchases.receipt',
    ALTER COLUMN source_document_type SET NOT NULL;

CREATE UNIQUE INDEX IF NOT EXISTS uq_purchase_receipts_uuid ON purchase_receipt_requests (receipt_uuid);
CREATE UNIQUE INDEX IF NOT EXISTS uq_purchase_receipts_no
    ON purchase_receipt_requests (cmp_id, receipt_no) WHERE receipt_no IS NOT NULL;
CREATE UNIQUE INDEX IF NOT EXISTS uq_purchase_receipts_client_token
    ON purchase_receipt_requests (cmp_id, po_id, client_token) WHERE client_token IS NOT NULL;

-- --- Settings ------------------------------------------------------------------

ALTER TABLE purchase_settings
    ADD COLUMN IF NOT EXISTS receipt_prefix TEXT NOT NULL DEFAULT 'GRN',
    -- Over-receipt is refused by default. A company may allow a small
    -- percentage over the ordered quantity; beyond that, only somebody holding
    -- receipt.over_tolerance may accept it, and must say why.
    ADD COLUMN IF NOT EXISTS over_receipt_tolerance_pc NUMERIC(6,3) NOT NULL DEFAULT 0;
