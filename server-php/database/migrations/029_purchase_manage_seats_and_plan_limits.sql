-- Migration 029: Purchases profiles seated by AICOUNTLY Manage (CONTRACTS §5) and plan limits (§3.6).
--
-- Manage signs and POSTs /api/internal/manage/seats after it grants a person Purchases on a company (a
-- share, an approved access request, a creator's own access in a shared workspace), so the person holds
-- the starter profile Manage named instead of "You have no access to Purchases in this company" until
-- someone with access.manage assigns one. Signed exactly like /api/internal/manage/company-lifecycle
-- (LIFECYCLE_WEBHOOK_SECRET, the lifecycle kit's Signature).
--
--   purchase_manage_seat_events   every signed seat event, once (event_id is the idempotency key);
--                                 outcome is what it did: seated | already | revoked
--
-- purchases.max_purchase_orders_per_month counts the company's new orders (version 0) since the start of
-- the calendar month before each create (PlanEntitlements); the index keeps that a range scan.
--
-- Additive and re-runnable. Listed in config/lifecycle-manifest.php (purged with the company).

CREATE TABLE IF NOT EXISTS purchase_manage_seat_events (
    event_id      UUID          PRIMARY KEY,
    cmp_id        BIGINT        NOT NULL,
    user_uuid     TEXT          NOT NULL,
    action        TEXT          NOT NULL CHECK (action IN ('grant', 'revoke')),
    role_key      TEXT,
    outcome       TEXT          NOT NULL,
    payload       JSONB         NOT NULL,
    received_at   TIMESTAMPTZ   NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_purchase_manage_seat_events_company ON purchase_manage_seat_events (cmp_id, user_uuid, received_at);

CREATE INDEX IF NOT EXISTS idx_purchase_orders_created ON purchase_orders (cmp_id, created_at) WHERE version_no = 0;
