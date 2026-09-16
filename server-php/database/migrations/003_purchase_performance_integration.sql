-- ---------------------------------------------------------------------------
-- Aicountly Purchases — approvals, supplier scorecards, integration, audit
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS purchase_approval_rules (
    rule_id         BIGSERIAL PRIMARY KEY,
    cmp_id          BIGINT       NOT NULL,
    rule_name       TEXT         NOT NULL,
    -- requisition | purchase_order | match_exception | claim
    applies_to      TEXT         NOT NULL,
    -- Conditions: amount bands, categories, departments, exception flags.
    conditions      JSONB        NOT NULL DEFAULT '{}'::jsonb,
    -- Ordered stages, each naming the permission that may decide it.
    stages          JSONB        NOT NULL DEFAULT '[]'::jsonb,
    priority        INT          NOT NULL DEFAULT 100,
    is_active       BOOLEAN      NOT NULL DEFAULT TRUE,
    created_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    UNIQUE (cmp_id, rule_name)
);

CREATE TABLE IF NOT EXISTS purchase_approval_requests (
    approval_id     BIGSERIAL PRIMARY KEY,
    cmp_id          BIGINT       NOT NULL,
    fy_id           BIGINT       NOT NULL,
    entity_type     TEXT         NOT NULL,   -- requisition | purchase_order | match_exception | claim
    entity_id       BIGINT       NOT NULL,
    rule_id         BIGINT       REFERENCES purchase_approval_rules(rule_id) ON DELETE SET NULL,
    stage_no        INT          NOT NULL DEFAULT 1,
    stage_name      TEXT,
    required_permission TEXT,
    reason_kind     TEXT         NOT NULL,
    reason_detail   TEXT,
    threshold_value NUMERIC(18,4),
    actual_value    NUMERIC(18,4),
    -- PENDING | APPROVED | REJECTED | WITHDRAWN | SKIPPED
    status          TEXT         NOT NULL DEFAULT 'PENDING',
    requested_by    TEXT         NOT NULL,
    decided_by      TEXT,
    decided_at      TIMESTAMPTZ,
    decision_note   TEXT,
    created_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_purchase_approvals_pending ON purchase_approval_requests (cmp_id, status, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_purchase_approvals_entity  ON purchase_approval_requests (cmp_id, entity_type, entity_id);

-- --------------------------------------------------------------------------
-- Supplier scorecards
--
-- The SCORE is ours: it is a procurement judgement computed from facts that
-- live elsewhere (delivery dates from Inventory receipts, spend from Books).
-- Storing the score is right — re-deriving last quarter's rating from today's
-- data would silently restate a supplier's record. Storing the facts it came
-- from would not be, so only references are kept.
-- --------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS purchase_supplier_scorecards (
    scorecard_id    BIGSERIAL PRIMARY KEY,
    cmp_id          BIGINT       NOT NULL,
    fy_id           BIGINT       NOT NULL,
    supplier_account_id BIGINT   NOT NULL,
    period_start    DATE         NOT NULL,
    period_end      DATE         NOT NULL,
    on_time_delivery_pc NUMERIC(6,3),
    quality_rejection_pc NUMERIC(6,3),
    quote_response_hours NUMERIC(10,2),
    po_acknowledgement_hours NUMERIC(10,2),
    price_variance_pc   NUMERIC(6,3),
    fulfilment_pc       NUMERIC(6,3),
    claim_count         INT       NOT NULL DEFAULT 0,
    overall_score       NUMERIC(6,3),
    -- What went into the score, as counts and references. Not copied rows.
    basis_summary   JSONB        NOT NULL DEFAULT '{}'::jsonb,
    computed_at     TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    computed_by     TEXT,
    UNIQUE (cmp_id, supplier_account_id, period_start, period_end)
);

-- --------------------------------------------------------------------------
-- Integration commands — intent and outcome, never data
--
-- See src/IntegrationCommand.php. There is no reconciliation job in this
-- product because there is no second copy of anything to reconcile.
-- --------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS purchase_integration_commands (
    command_id      BIGSERIAL PRIMARY KEY,
    cmp_id          BIGINT       NOT NULL,
    fy_id           BIGINT       NOT NULL,
    bo_id           BIGINT       NOT NULL DEFAULT 0,
    target_service  TEXT         NOT NULL,     -- books | inventory | contacts
    command_type    TEXT         NOT NULL,
    entity_type     TEXT         NOT NULL,
    entity_id       BIGINT       NOT NULL,
    -- Minted once, before the first call, reused by every retry. This is what
    -- stops a timeout becoming a second GRN or a second vendor bill.
    idempotency_key TEXT         NOT NULL,
    status          TEXT         NOT NULL DEFAULT 'PENDING',
    attempts        INT          NOT NULL DEFAULT 0,
    request_summary JSONB        NOT NULL DEFAULT '{}'::jsonb,
    external_reference JSONB,
    last_error      TEXT,
    last_attempt_at TIMESTAMPTZ,
    completed_at    TIMESTAMPTZ,
    created_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    UNIQUE (cmp_id, idempotency_key)
);

CREATE INDEX IF NOT EXISTS idx_purchase_commands_entity ON purchase_integration_commands (cmp_id, entity_type, entity_id);
CREATE INDEX IF NOT EXISTS idx_purchase_commands_open   ON purchase_integration_commands (cmp_id, status)
    WHERE status IN ('PENDING', 'POSTING', 'FAILED', 'BLOCKED');

-- --------------------------------------------------------------------------
-- Audit — ours only, append-only and enforced
-- --------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS purchase_audit_log (
    audit_id        BIGSERIAL PRIMARY KEY,
    cmp_id          BIGINT       NOT NULL,
    fy_id           BIGINT       NOT NULL,
    bo_id           BIGINT       NOT NULL DEFAULT 0,
    actor_uuid      TEXT         NOT NULL,
    actor_kind      TEXT         NOT NULL,
    source_app      TEXT         NOT NULL,
    action          TEXT         NOT NULL,
    entity_type     TEXT         NOT NULL,
    entity_id       TEXT,
    before_state    JSONB,
    after_state     JSONB,
    reason          TEXT,
    ip_address      TEXT,
    created_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_purchase_audit_entity ON purchase_audit_log (cmp_id, entity_type, entity_id);
CREATE INDEX IF NOT EXISTS idx_purchase_audit_time   ON purchase_audit_log (cmp_id, created_at DESC);

-- Statutory retention is eight years, and a trail that can be edited is not a
-- trail. Row-level triggers deliberately do not fire on TRUNCATE, which is what
-- lets a test suite reset its schema; nothing in production truncates this.
CREATE OR REPLACE FUNCTION purchase_audit_immutable() RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION 'purchase_audit_log is append-only (attempted %)', TG_OP;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_purchase_audit_no_update ON purchase_audit_log;
CREATE TRIGGER trg_purchase_audit_no_update
    BEFORE UPDATE ON purchase_audit_log
    FOR EACH ROW EXECUTE FUNCTION purchase_audit_immutable();

DROP TRIGGER IF EXISTS trg_purchase_audit_no_delete ON purchase_audit_log;
CREATE TRIGGER trg_purchase_audit_no_delete
    BEFORE DELETE ON purchase_audit_log
    FOR EACH ROW EXECUTE FUNCTION purchase_audit_immutable();

-- --------------------------------------------------------------------------
-- Settings and preferences
-- --------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS purchase_settings (
    cmp_id          BIGINT       PRIMARY KEY,
    requisition_prefix TEXT      NOT NULL DEFAULT 'PR',
    rfq_prefix      TEXT         NOT NULL DEFAULT 'RFQ',
    po_prefix       TEXT         NOT NULL DEFAULT 'PO',
    return_prefix   TEXT         NOT NULL DEFAULT 'PRET',
    claim_prefix    TEXT         NOT NULL DEFAULT 'CLM',
    -- Above this value a purchase order needs an approval.
    po_approval_above_amount NUMERIC(18,4) NOT NULL DEFAULT 0,
    requisition_approval_above_amount NUMERIC(18,4) NOT NULL DEFAULT 0,
    -- Refuse a PO to a supplier who is not approved, or only warn.
    enforce_approved_vendors BOOLEAN NOT NULL DEFAULT FALSE,
    -- Refuse a bill whose three-way match is BLOCKED.
    block_bill_on_match_failure BOOLEAN NOT NULL DEFAULT TRUE,
    default_warehouse_id BIGINT,
    updated_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS purchase_user_preferences (
    preference_id   BIGSERIAL PRIMARY KEY,
    cmp_id          BIGINT       NOT NULL,
    user_uuid       TEXT         NOT NULL,
    scope           TEXT         NOT NULL,
    payload         JSONB        NOT NULL DEFAULT '{}'::jsonb,
    updated_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    UNIQUE (cmp_id, user_uuid, scope)
);
