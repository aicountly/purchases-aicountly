-- --------------------------------------------------------------------------
-- The audit trail, readable on its own (AICOUNTLY audit-trail standard v1).
--
-- purchase_audit_log (003) already held who, what, which record and the state
-- before and after, append-only. What it did not hold is what makes a row read
-- without a second query: the record's own number at the time ("PO/1/0042",
-- not 117), one line saying what happened, the field-by-field change, how
-- material it was, and which request, address and browser it came from.
--
-- Additive only. Existing rows keep NULLs (the reader derives what it can from
-- before_state / after_state); nothing here rewrites a row, and the BEFORE
-- UPDATE / BEFORE DELETE triggers from 003 stay exactly as they are. ADD COLUMN
-- with a constant default does not rewrite or update existing rows, so the
-- append-only triggers are not tripped by this file.
-- --------------------------------------------------------------------------

ALTER TABLE purchase_audit_log ADD COLUMN IF NOT EXISTS entity_label TEXT;
ALTER TABLE purchase_audit_log ADD COLUMN IF NOT EXISTS summary      TEXT;
-- [{field, label, old, new}] for an update; secrets are never in it (Audit::changes).
ALTER TABLE purchase_audit_log ADD COLUMN IF NOT EXISTS changes      JSONB;
ALTER TABLE purchase_audit_log ADD COLUMN IF NOT EXISTS severity     TEXT NOT NULL DEFAULT 'info';
ALTER TABLE purchase_audit_log ADD COLUMN IF NOT EXISTS request_id   TEXT;
ALTER TABLE purchase_audit_log ADD COLUMN IF NOT EXISTS user_agent   TEXT;

DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'purchase_audit_log_severity_check') THEN
        ALTER TABLE purchase_audit_log
            ADD CONSTRAINT purchase_audit_log_severity_check CHECK (severity IN ('info', 'material', 'security'));
    END IF;
END $$;

-- The read API filters by record, action and person within a company, newest first.
CREATE INDEX IF NOT EXISTS idx_purchase_audit_entity_time ON purchase_audit_log (cmp_id, entity_type, entity_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_purchase_audit_action_time ON purchase_audit_log (cmp_id, action, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_purchase_audit_actor_time  ON purchase_audit_log (cmp_id, actor_uuid, created_at DESC);
