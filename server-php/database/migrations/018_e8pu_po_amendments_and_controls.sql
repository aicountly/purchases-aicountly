-- ---------------------------------------------------------------------------
-- 018 — amending an issued purchase order, and controls that hold through its life
--        (launch readiness E8-PUR: F167, F420, F025, F482)
--
-- An order agreed with the supplier could not be changed once issued: price or quantity
-- corrections meant cancelling (impossible once goods arrived) or living with permanent
-- variances. An amendment is now a versioned change to the order's lines and terms, audited,
-- with the old and new terms kept here; one that raises the value above the company's approval
-- threshold waits for an approver (with segregation of duties) before it applies, and the order
-- keeps working on its current terms meanwhile. purchase_orders.version_no counts applied
-- amendments; purchase_orders.amendment_pending_id names the one waiting, if any.
--
-- A cancelled order's PENDING approval request is withdrawn (it used to stay in the inbox, and
-- approving it then answered 409); existing ones are withdrawn here, once.
--
-- Additive and idempotent.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS purchase_po_amendments (
    amendment_id    BIGSERIAL PRIMARY KEY,
    cmp_id          BIGINT       NOT NULL,
    po_id           BIGINT       NOT NULL REFERENCES purchase_orders(po_id) ON DELETE CASCADE,
    from_version    INT          NOT NULL,
    to_version      INT          NOT NULL,
    -- PENDING_APPROVAL | APPLIED | REJECTED | WITHDRAWN
    status          TEXT         NOT NULL,
    reason          TEXT         NOT NULL,
    before_terms    JSONB        NOT NULL,
    after_terms     JSONB        NOT NULL,
    value_before    NUMERIC(18,4) NOT NULL,
    value_after     NUMERIC(18,4) NOT NULL,
    requested_by    TEXT         NOT NULL,
    decided_by      TEXT,
    decided_at      TIMESTAMPTZ,
    decision_note   TEXT,
    created_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_purchase_po_amendments_po ON purchase_po_amendments (cmp_id, po_id, amendment_id DESC);

-- One amendment per version of an order (a draft's edits keep its version and are not counted).
CREATE UNIQUE INDEX IF NOT EXISTS uq_purchase_po_amendments_version
    ON purchase_po_amendments (po_id, to_version)
 WHERE status IN ('APPLIED', 'PENDING_APPROVAL') AND to_version > from_version;

ALTER TABLE purchase_orders
    ADD COLUMN IF NOT EXISTS amendment_pending_id BIGINT;

ALTER TABLE purchase_approval_requests
    ADD COLUMN IF NOT EXISTS amendment_id BIGINT;

UPDATE purchase_approval_requests a
   SET status = 'WITHDRAWN', decision_note = COALESCE(a.decision_note, 'The purchase order was cancelled.'), decided_at = COALESCE(a.decided_at, NOW())
 WHERE a.entity_type = 'purchase_order' AND a.status = 'PENDING'
   AND EXISTS (SELECT 1 FROM purchase_orders p WHERE p.po_id = a.entity_id AND p.cmp_id = a.cmp_id AND p.status = 'CANCELLED');
