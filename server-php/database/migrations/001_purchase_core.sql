-- ---------------------------------------------------------------------------
-- Aicountly Purchases — procurement workflow
--
-- Purchases answers exactly one question: "what do we need to buy, from whom,
-- on what terms, through what approval and purchase order?"
--
-- It does NOT answer:
--   "what actually arrived and what is it worth?"   -> Inventory
--   "what did the supplier bill us and what do we owe?"  -> Smart Books
--   "which company, branch and financial year?"     -> Manage
--
-- So there is deliberately no table here for:
--   an item master, a supplier ledger, a stock balance, a warehouse master,
--   a GRN (Inventory owns the physical receipt — we keep its uuid),
--   a purchase invoice (Books owns it — we keep its uuid),
--   a payable balance, an input-GST or TDS figure.
--
-- A PO line's agreed rate IS stored, and that is not duplication: it is the
-- price we committed to on that order, and it stays true when the supplier's
-- price list changes next month.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS purchase_permission_profiles (
    profile_id      BIGSERIAL PRIMARY KEY,
    cmp_id          BIGINT       NOT NULL,
    profile_name    TEXT         NOT NULL,
    description     TEXT,
    permissions     JSONB        NOT NULL DEFAULT '[]'::jsonb,
    is_system       BOOLEAN      NOT NULL DEFAULT FALSE,
    is_active       BOOLEAN      NOT NULL DEFAULT TRUE,
    created_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    UNIQUE (cmp_id, profile_name)
);

CREATE TABLE IF NOT EXISTS purchase_permission_assignments (
    assignment_id   BIGSERIAL PRIMARY KEY,
    cmp_id          BIGINT       NOT NULL,
    -- The portal uuid. Not a copy of the user.
    user_uuid       TEXT         NOT NULL,
    profile_id      BIGINT       NOT NULL REFERENCES purchase_permission_profiles(profile_id) ON DELETE CASCADE,
    created_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    UNIQUE (cmp_id, user_uuid, profile_id)
);

CREATE INDEX IF NOT EXISTS idx_purchase_perm_assign_user ON purchase_permission_assignments (cmp_id, user_uuid);

-- --------------------------------------------------------------------------
-- Supplier PROCUREMENT profile
--
-- The supplier's legal identity is Contacts', and their account ledger is
-- Books'. What lives here is only what procurement decides about them:
-- are they approved, how well do they perform, what is their real lead time.
-- --------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS purchase_supplier_profiles (
    profile_id      BIGSERIAL PRIMARY KEY,
    cmp_id          BIGINT       NOT NULL,
    -- Books' account id for the supplier. A reference; the ledger stays there.
    supplier_account_id BIGINT   NOT NULL,
    contact_id      TEXT,
    -- draft | pending_approval | approved | suspended | blacklisted
    qualification_status TEXT    NOT NULL DEFAULT 'draft',
    is_preferred    BOOLEAN      NOT NULL DEFAULT FALSE,
    approved_categories JSONB    NOT NULL DEFAULT '[]'::jsonb,
    operational_lead_days INT,
    payment_terms   TEXT,
    incoterm        TEXT,
    risk_flag       TEXT,
    notes           TEXT,
    approved_by     TEXT,
    approved_at     TIMESTAMPTZ,
    created_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    UNIQUE (cmp_id, supplier_account_id)
);

-- --------------------------------------------------------------------------
-- Requisitions — somebody needs something
-- --------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS purchase_requisitions (
    requisition_id  BIGSERIAL PRIMARY KEY,
    requisition_uuid UUID        NOT NULL DEFAULT gen_random_uuid(),
    cmp_id          BIGINT       NOT NULL,
    fy_id           BIGINT       NOT NULL,
    bo_id           BIGINT       NOT NULL DEFAULT 0,
    requisition_no  TEXT         NOT NULL,
    requisition_date DATE        NOT NULL,
    -- DRAFT | SUBMITTED | APPROVAL_PENDING | APPROVED | REJECTED
    -- | SOURCING | ORDERED | CLOSED | CANCELLED
    status          TEXT         NOT NULL DEFAULT 'DRAFT',
    requester_uuid  TEXT         NOT NULL,
    department      TEXT,
    -- Cost centre and project masters belong to Books. Ids only.
    cost_centre_id  BIGINT,
    project_id      BIGINT,
    required_by     DATE,
    priority        TEXT         NOT NULL DEFAULT 'normal',
    justification   TEXT,
    -- emergency | single_source | non_preferred_vendor — drives approval routing
    exception_flags JSONB        NOT NULL DEFAULT '[]'::jsonb,
    estimated_value NUMERIC(18,4) NOT NULL DEFAULT 0,
    approved_by     TEXT,
    approved_at     TIMESTAMPTZ,
    created_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    UNIQUE (cmp_id, requisition_no)
);

CREATE INDEX IF NOT EXISTS idx_purchase_req_scope ON purchase_requisitions (cmp_id, fy_id, status, requisition_date DESC);

CREATE TABLE IF NOT EXISTS purchase_requisition_lines (
    line_id         BIGSERIAL PRIMARY KEY,
    requisition_id  BIGINT       NOT NULL REFERENCES purchase_requisitions(requisition_id) ON DELETE CASCADE,
    cmp_id          BIGINT       NOT NULL,
    line_no         INT          NOT NULL,
    -- Inventory's ids. A service requisition carries none and says so.
    item_id         BIGINT,
    unit_id         BIGINT,
    warehouse_id    BIGINT,
    is_service      BOOLEAN      NOT NULL DEFAULT FALSE,
    description     TEXT,
    required_qty    NUMERIC(18,4) NOT NULL DEFAULT 0,
    -- What the requester expects it to cost, for approval routing only. Not a
    -- price, not a valuation, and never read as either.
    estimated_rate  NUMERIC(18,4) NOT NULL DEFAULT 0,
    required_by     DATE,
    -- How much of this line sourcing has already turned into purchase orders.
    ordered_qty     NUMERIC(18,4) NOT NULL DEFAULT 0,
    notes           TEXT,
    created_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    UNIQUE (requisition_id, line_no)
);

CREATE INDEX IF NOT EXISTS idx_purchase_req_lines_item ON purchase_requisition_lines (cmp_id, item_id);

-- --------------------------------------------------------------------------
-- Sourcing — RFQ, supplier responses, comparison
-- --------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS purchase_rfqs (
    rfq_id          BIGSERIAL PRIMARY KEY,
    rfq_uuid        UUID         NOT NULL DEFAULT gen_random_uuid(),
    cmp_id          BIGINT       NOT NULL,
    fy_id           BIGINT       NOT NULL,
    bo_id           BIGINT       NOT NULL DEFAULT 0,
    rfq_no          TEXT         NOT NULL,
    rfq_date        DATE         NOT NULL,
    title           TEXT,
    -- DRAFT | ISSUED | RESPONSES_OPEN | EVALUATING | AWARDED | CANCELLED | CLOSED
    status          TEXT         NOT NULL DEFAULT 'DRAFT',
    response_deadline TIMESTAMPTZ,
    delivery_warehouse_id BIGINT,
    required_by     DATE,
    commercial_terms TEXT,
    technical_terms  TEXT,
    currency_code   TEXT         NOT NULL DEFAULT 'INR',
    created_by      TEXT         NOT NULL,
    created_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    UNIQUE (cmp_id, rfq_no)
);

CREATE TABLE IF NOT EXISTS purchase_rfq_lines (
    line_id         BIGSERIAL PRIMARY KEY,
    rfq_id          BIGINT       NOT NULL REFERENCES purchase_rfqs(rfq_id) ON DELETE CASCADE,
    cmp_id          BIGINT       NOT NULL,
    line_no         INT          NOT NULL,
    requisition_line_id BIGINT   REFERENCES purchase_requisition_lines(line_id) ON DELETE SET NULL,
    item_id         BIGINT,
    unit_id         BIGINT,
    is_service      BOOLEAN      NOT NULL DEFAULT FALSE,
    description     TEXT,
    required_qty    NUMERIC(18,4) NOT NULL DEFAULT 0,
    required_by     DATE,
    specification   TEXT,
    created_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    UNIQUE (rfq_id, line_no)
);

CREATE TABLE IF NOT EXISTS purchase_rfq_invitations (
    invitation_id   BIGSERIAL PRIMARY KEY,
    rfq_id          BIGINT       NOT NULL REFERENCES purchase_rfqs(rfq_id) ON DELETE CASCADE,
    cmp_id          BIGINT       NOT NULL,
    supplier_account_id BIGINT   NOT NULL,
    -- INVITED | VIEWED | RESPONDED | DECLINED | NO_RESPONSE
    status          TEXT         NOT NULL DEFAULT 'INVITED',
    invited_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    responded_at    TIMESTAMPTZ,
    -- Hashed, long and single-purpose: a guessable supplier-portal link is one
    -- loop away from every competitor's quoted price.
    portal_token_hash TEXT,
    token_expires_at TIMESTAMPTZ,
    UNIQUE (rfq_id, supplier_account_id)
);

CREATE TABLE IF NOT EXISTS purchase_quotes (
    quote_id        BIGSERIAL PRIMARY KEY,
    cmp_id          BIGINT       NOT NULL,
    rfq_id          BIGINT       NOT NULL REFERENCES purchase_rfqs(rfq_id) ON DELETE CASCADE,
    supplier_account_id BIGINT   NOT NULL,
    quote_ref       TEXT,
    quote_date      DATE,
    revision_no     INT          NOT NULL DEFAULT 0,
    -- RECEIVED | UNDER_REVIEW | SHORTLISTED | AWARDED | REJECTED | WITHDRAWN
    status          TEXT         NOT NULL DEFAULT 'RECEIVED',
    currency_code   TEXT         NOT NULL DEFAULT 'INR',
    exchange_rate   NUMERIC(18,6) NOT NULL DEFAULT 1,
    -- The commercial terms THIS supplier offered. Procurement facts, ours.
    payment_terms   TEXT,
    delivery_days   INT,
    warranty_terms  TEXT,
    freight_amount  NUMERIC(18,4) NOT NULL DEFAULT 0,
    other_charges   NUMERIC(18,4) NOT NULL DEFAULT 0,
    valid_until     DATE,
    notes           TEXT,
    created_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    UNIQUE (rfq_id, supplier_account_id, revision_no)
);

CREATE TABLE IF NOT EXISTS purchase_quote_lines (
    line_id         BIGSERIAL PRIMARY KEY,
    quote_id        BIGINT       NOT NULL REFERENCES purchase_quotes(quote_id) ON DELETE CASCADE,
    rfq_line_id     BIGINT       REFERENCES purchase_rfq_lines(line_id) ON DELETE SET NULL,
    cmp_id          BIGINT       NOT NULL,
    line_no         INT          NOT NULL,
    item_id         BIGINT,
    unit_id         BIGINT,
    quoted_qty      NUMERIC(18,4) NOT NULL DEFAULT 0,
    quoted_rate     NUMERIC(18,4) NOT NULL DEFAULT 0,
    discount_pc     NUMERIC(6,3)  NOT NULL DEFAULT 0,
    -- The supplier's own tax expectation. Books calculates what is actually
    -- charged when the bill arrives; this is for comparison only, and the
    -- column name says so.
    estimated_tax_pc NUMERIC(6,3) NOT NULL DEFAULT 0,
    moq             NUMERIC(18,4),
    lead_days       INT,
    line_amount     NUMERIC(18,4) NOT NULL DEFAULT 0,
    remarks         TEXT,
    created_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    UNIQUE (quote_id, line_no)
);

-- The award decision: which supplier won which line, and why. A Purchases fact.
CREATE TABLE IF NOT EXISTS purchase_bid_awards (
    award_id        BIGSERIAL PRIMARY KEY,
    cmp_id          BIGINT       NOT NULL,
    rfq_id          BIGINT       NOT NULL REFERENCES purchase_rfqs(rfq_id) ON DELETE CASCADE,
    rfq_line_id     BIGINT       REFERENCES purchase_rfq_lines(line_id) ON DELETE CASCADE,
    quote_id        BIGINT       NOT NULL REFERENCES purchase_quotes(quote_id) ON DELETE CASCADE,
    awarded_qty     NUMERIC(18,4) NOT NULL DEFAULT 0,
    awarded_rate    NUMERIC(18,4) NOT NULL DEFAULT 0,
    -- Why this supplier and not the cheapest. The single most useful thing an
    -- auditor can read six months later.
    rationale       TEXT,
    decided_by      TEXT         NOT NULL,
    decided_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_purchase_awards_rfq ON purchase_bid_awards (cmp_id, rfq_id);
