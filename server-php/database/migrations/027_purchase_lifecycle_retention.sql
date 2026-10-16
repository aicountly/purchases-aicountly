-- ---------------------------------------------------------------------------
-- Company lifecycle, kit 1.1 (docs/COMPANY_LIFECYCLE.md § "What a purge deletes and keeps"):
--
-- 1. The purge worker — and only a session that says it is the purge worker — may DELETE rows of the
--    append-only audit trail: `--expire-retained` deletes a purged company's audit rows once their 8 years
--    have run out. The worker's own connection (config/lifecycle-kit.php, CLI only) sets
--    `aicountly.lifecycle_purge = on`; nothing in the web API does. Without it the function behaves exactly
--    as before: UPDATE is always refused, DELETE too.
--
-- 2. retention_anchor_at on purchase_returns and purchase_claims: the date their 8 years run from — the
--    latest of their own creation and of every return line / claim resolution recorded against them (kept
--    by triggers). Expiry deletes children first; a child recorded later than its parent would otherwise be
--    deleted with it by ON DELETE CASCADE, unarchived and inside its own period.
--
-- Additive and idempotent.
-- ---------------------------------------------------------------------------

CREATE OR REPLACE FUNCTION purchase_audit_immutable() RETURNS trigger AS $$
BEGIN
    IF TG_OP = 'DELETE' AND current_setting('aicountly.lifecycle_purge', true) = 'on' THEN
        RETURN OLD;
    END IF;
    RAISE EXCEPTION 'purchase_audit_log is append-only (attempted %)', TG_OP;
END;
$$ LANGUAGE plpgsql;

ALTER TABLE purchase_returns ADD COLUMN IF NOT EXISTS retention_anchor_at TIMESTAMPTZ;
UPDATE purchase_returns r
   SET retention_anchor_at = GREATEST(r.created_at, (SELECT MAX(l.created_at) FROM purchase_return_lines l WHERE l.return_id = r.return_id))
 WHERE r.retention_anchor_at IS NULL;
ALTER TABLE purchase_returns ALTER COLUMN retention_anchor_at SET DEFAULT NOW();
ALTER TABLE purchase_returns ALTER COLUMN retention_anchor_at SET NOT NULL;

ALTER TABLE purchase_claims ADD COLUMN IF NOT EXISTS retention_anchor_at TIMESTAMPTZ;
UPDATE purchase_claims c
   SET retention_anchor_at = GREATEST(c.created_at, (SELECT MAX(x.created_at) FROM purchase_claim_resolutions x WHERE x.claim_id = c.claim_id))
 WHERE c.retention_anchor_at IS NULL;
ALTER TABLE purchase_claims ALTER COLUMN retention_anchor_at SET DEFAULT NOW();
ALTER TABLE purchase_claims ALTER COLUMN retention_anchor_at SET NOT NULL;

-- TG_ARGV: parent table, its key column, the new row's column naming the parent.
CREATE OR REPLACE FUNCTION purchase_retention_anchor() RETURNS trigger AS $$
DECLARE
    target BIGINT;
    stamp  TIMESTAMPTZ;
BEGIN
    target := NULLIF(to_jsonb(NEW) ->> TG_ARGV[2], '')::BIGINT;
    IF target IS NULL THEN
        RETURN NULL;
    END IF;
    stamp := COALESCE(NULLIF(to_jsonb(NEW) ->> 'created_at', '')::TIMESTAMPTZ, NOW());
    EXECUTE format('UPDATE %I SET retention_anchor_at = $1 WHERE %I = $2 AND retention_anchor_at < $1', TG_ARGV[0], TG_ARGV[1])
        USING stamp, target;
    RETURN NULL;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_purchase_return_lines_retention_anchor ON purchase_return_lines;
CREATE TRIGGER trg_purchase_return_lines_retention_anchor
    AFTER INSERT OR UPDATE OF return_id ON purchase_return_lines
    FOR EACH ROW EXECUTE FUNCTION purchase_retention_anchor('purchase_returns', 'return_id', 'return_id');

DROP TRIGGER IF EXISTS trg_purchase_claim_resolutions_retention_anchor ON purchase_claim_resolutions;
CREATE TRIGGER trg_purchase_claim_resolutions_retention_anchor
    AFTER INSERT OR UPDATE OF claim_id ON purchase_claim_resolutions
    FOR EACH ROW EXECUTE FUNCTION purchase_retention_anchor('purchase_claims', 'claim_id', 'claim_id');
