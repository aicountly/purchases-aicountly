-- ---------------------------------------------------------------------------
-- Aicountly Purchases — returns with one owner per movement; claims settled by
-- what actually happened upstream
--
-- Returns
--   return_kind          physical  goods go back: Inventory records the dispatch (a
--                                  DELIVERY_CHALLAN, challan_only) and the debit note in
--                                  Books settles it (PURCHASE_RETURN from_challan) — the
--                                  goods leave stock once, with the debit note, and the
--                                  debit note is refused until the dispatch is confirmed.
--                        financial nothing goes back: a debit note on a ledger, no stock.
--                                  Separately authorised (return.financial_adjustment),
--                                  with a reason and the ledger it is booked to.
--   expect_replacement   whether the order still awaits the goods sent back. When not,
--                        the returned quantity is short-closed on the order line at
--                        dispatch (closes_order_qty) and re-opened if the return is
--                        recalled.
--   recall               a dispatched return that never reached a debit note can be
--                        brought back: Inventory reverses the challan.
--
-- Claims
--   A claim is settled by RESOLUTIONS, each proposed with the effect it would have,
--   approved, and completed only when the operation it depends on succeeded:
--     financial_adjustment  a debit note in Books
--     physical_return       a purchase return, completed when its debit note posts
--     replacement           goods received again on a GRN of the claim's order
--     refund                money the supplier paid back, recorded in Books and
--                           verified there — Purchase never records money itself
--     non_financial         resolved without money (a credit promised on the next
--                           order, an apology accepted), with a note
--   A claim is SETTLED when completed resolutions cover its approved amount, and
--   PARTIALLY_SETTLED before that. It can no longer be marked settled by hand.
-- ---------------------------------------------------------------------------

ALTER TABLE purchase_returns
    ADD COLUMN IF NOT EXISTS return_kind            TEXT        NOT NULL DEFAULT 'physical',
    ADD COLUMN IF NOT EXISTS expect_replacement     BOOLEAN     NOT NULL DEFAULT FALSE,
    ADD COLUMN IF NOT EXISTS adjustment_acc_id      BIGINT,
    ADD COLUMN IF NOT EXISTS adjustment_reason      TEXT,
    ADD COLUMN IF NOT EXISTS claim_id               BIGINT,
    ADD COLUMN IF NOT EXISTS inventory_document_id  BIGINT,
    ADD COLUMN IF NOT EXISTS approved_by            TEXT,
    ADD COLUMN IF NOT EXISTS approved_at            TIMESTAMPTZ,
    ADD COLUMN IF NOT EXISTS dispatched_at          TIMESTAMPTZ,
    ADD COLUMN IF NOT EXISTS debited_at             TIMESTAMPTZ,
    ADD COLUMN IF NOT EXISTS recalled_at            TIMESTAMPTZ,
    ADD COLUMN IF NOT EXISTS recall_reason          TEXT,
    ADD COLUMN IF NOT EXISTS cancelled_at           TIMESTAMPTZ,
    ADD COLUMN IF NOT EXISTS cancel_reason          TEXT;

ALTER TABLE purchase_return_lines
    ADD COLUMN IF NOT EXISTS closes_order_qty NUMERIC(18,4) NOT NULL DEFAULT 0;

CREATE INDEX IF NOT EXISTS idx_purchase_returns_po_open ON purchase_returns (cmp_id, po_id)
    WHERE status IN ('DRAFT', 'APPROVED', 'DISPATCHED');

ALTER TABLE purchase_claims
    ADD COLUMN IF NOT EXISTS approved_amount NUMERIC(18,4),
    ADD COLUMN IF NOT EXISTS closed_reason   TEXT;

-- Claims approved before this approved their whole amount.
UPDATE purchase_claims SET approved_amount = claimed_amount
 WHERE approved_amount IS NULL AND status IN ('APPROVED', 'SETTLED');

CREATE TABLE IF NOT EXISTS purchase_claim_resolutions (
    resolution_id    BIGSERIAL    PRIMARY KEY,
    cmp_id           BIGINT       NOT NULL,
    fy_id            BIGINT       NOT NULL,
    bo_id            BIGINT       NOT NULL DEFAULT 0,
    claim_id         BIGINT       NOT NULL REFERENCES purchase_claims(claim_id) ON DELETE CASCADE,
    kind             TEXT         NOT NULL,
    amount           NUMERIC(18,4) NOT NULL DEFAULT 0,
    -- PROPOSED → APPROVED → IN_PROGRESS → COMPLETED, or FAILED / UNCERTAIN / BLOCKED on the
    -- way (retryable, reconcilable, or needing a person), or CANCELLED.
    status           TEXT         NOT NULL DEFAULT 'PROPOSED',
    -- What approving it will do, in words and figures, shown before anyone approves it.
    proposed_effect  JSONB        NOT NULL DEFAULT '{}'::jsonb,
    adjustment_acc_id BIGINT,
    lines            JSONB,
    note             TEXT,
    -- What the other product called the result: a debit note, a return, a GRN, a receipt.
    reference        JSONB,
    last_error       TEXT,
    proposed_by      TEXT         NOT NULL,
    approved_by      TEXT,
    approved_at      TIMESTAMPTZ,
    completed_at     TIMESTAMPTZ,
    cancelled_at     TIMESTAMPTZ,
    cancel_reason    TEXT,
    created_at       TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    updated_at       TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_purchase_claim_resolutions_claim ON purchase_claim_resolutions (cmp_id, claim_id);
