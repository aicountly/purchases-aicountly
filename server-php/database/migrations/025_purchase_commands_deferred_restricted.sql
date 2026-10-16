-- Company lifecycle (AICOUNTLY Manage): Smart Books / Inventory answer 423 COMPANY_RESTRICTED for a
-- company whose subscription to them lapsed (read-only for 45 days, then purged). A command they
-- refuse that way is kept as DEFERRED_RESTRICTED — not FAILED, UNCERTAIN or BLOCKED, not stale —
-- until the company is renewed there (bin/command-monitor.php asks Manage at most hourly), or
-- becomes DROPPED_COMPANY_DELETED with an audit entry once its data there is gone. status is TEXT.
--
--   restricted_until      the date the other product gave
--   restriction_check_at  when the monitor next asks Manage about it
--
-- Additive, idempotent.
ALTER TABLE purchase_integration_commands ADD COLUMN IF NOT EXISTS restricted_until TIMESTAMPTZ;
ALTER TABLE purchase_integration_commands ADD COLUMN IF NOT EXISTS restriction_check_at TIMESTAMPTZ;
CREATE INDEX IF NOT EXISTS idx_purchase_commands_deferred_restricted
    ON purchase_integration_commands (restriction_check_at, cmp_id) WHERE status = 'DEFERRED_RESTRICTED';
