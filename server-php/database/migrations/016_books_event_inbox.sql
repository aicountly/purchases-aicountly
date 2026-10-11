-- 016 (E8-BK-POST A16): what Books told us about the purchase vouchers it holds for our bills.
--
-- A bill cancelled in Books used to stay POSTED here: the duplicate-invoice guard refused the same
-- supplier invoice number for ever (an accounts-payable dead end) and the PO stayed billed. Books
-- now sends voucher.cancelled / voucher.revised to POST /api/v1/integration/books/voucher-events
-- (service key + signature). Each event is applied once (event_id) and its outcome kept.
CREATE TABLE IF NOT EXISTS purchase_books_event_inbox (
    event_id     TEXT         PRIMARY KEY,
    cmp_id       BIGINT       NOT NULL,
    vch_txn_id   BIGINT       NOT NULL,
    event_type   TEXT         NOT NULL,
    revision     INTEGER      NOT NULL DEFAULT 0,
    outcome      TEXT         NOT NULL,
    reference    TEXT         NOT NULL,
    reason       TEXT,
    payload      JSONB        NOT NULL DEFAULT '{}'::jsonb,
    received_at  TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_purchase_books_event_inbox_vch ON purchase_books_event_inbox (cmp_id, vch_txn_id);

-- A bill Books cancelled is CANCELLED here (so the supplier's invoice number can be entered again)
-- and says when Books did it; an edited one keeps the revision Books reported.
ALTER TABLE purchase_bill_requests ADD COLUMN IF NOT EXISTS cancelled_in_books_at TIMESTAMPTZ;
ALTER TABLE purchase_bill_requests ADD COLUMN IF NOT EXISTS revision_in_books INTEGER;
