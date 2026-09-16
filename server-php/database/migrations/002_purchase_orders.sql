-- ---------------------------------------------------------------------------
-- Aicountly Purchases — orders, receipts, matching, claims
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS purchase_orders (
    po_id           BIGSERIAL PRIMARY KEY,
    po_uuid         UUID         NOT NULL DEFAULT gen_random_uuid(),
    cmp_id          BIGINT       NOT NULL,
    fy_id           BIGINT       NOT NULL,
    bo_id           BIGINT       NOT NULL DEFAULT 0,
    po_no           TEXT         NOT NULL,
    po_date         DATE         NOT NULL,
    version_no      INT          NOT NULL DEFAULT 0,
    supersedes_id   BIGINT       REFERENCES purchase_orders(po_id) ON DELETE SET NULL,

    requisition_id  BIGINT       REFERENCES purchase_requisitions(requisition_id) ON DELETE SET NULL,
    rfq_id          BIGINT       REFERENCES purchase_rfqs(rfq_id) ON DELETE SET NULL,
    quote_id        BIGINT       REFERENCES purchase_quotes(quote_id) ON DELETE SET NULL,
    agreement_id    BIGINT,

    -- Books' account id for the supplier. The ledger and the payable stay there.
    supplier_account_id BIGINT   NOT NULL,
    contact_id      TEXT,
    -- Printed on the PO, frozen for the document. Not a supplier master.
    supplier_name_snapshot TEXT,

    -- DRAFT | APPROVAL_PENDING | APPROVED | ISSUED | ACKNOWLEDGED
    -- | PARTIALLY_RECEIVED | RECEIVED | CLOSED | CANCELLED
    --
    -- OUR workflow state. What actually arrived is Inventory's answer and is
    -- read from Inventory; this column never pretends to know it.
    status          TEXT         NOT NULL DEFAULT 'DRAFT',

    delivery_warehouse_id BIGINT,
    delivery_address JSONB,
    promised_date   DATE,
    payment_terms   TEXT,
    incoterm        TEXT,
    currency_code   TEXT         NOT NULL DEFAULT 'INR',
    exchange_rate   NUMERIC(18,6) NOT NULL DEFAULT 1,

    subtotal_amount      NUMERIC(18,4) NOT NULL DEFAULT 0,
    discount_amount      NUMERIC(18,4) NOT NULL DEFAULT 0,
    freight_amount       NUMERIC(18,4) NOT NULL DEFAULT 0,
    other_charges        NUMERIC(18,4) NOT NULL DEFAULT 0,
    -- An estimate, for approval routing and for the supplier's benefit. Books
    -- computes the tax actually charged when the bill is entered.
    estimated_tax_amount NUMERIC(18,4) NOT NULL DEFAULT 0,
    total_amount         NUMERIC(18,4) NOT NULL DEFAULT 0,

    terms_text      TEXT,
    notes           TEXT,
    created_by      TEXT         NOT NULL,
    approved_by     TEXT,
    approved_at     TIMESTAMPTZ,
    issued_at       TIMESTAMPTZ,
    acknowledged_at TIMESTAMPTZ,
    cancelled_at    TIMESTAMPTZ,
    cancel_reason   TEXT,
    created_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    UNIQUE (cmp_id, po_no)
);

CREATE INDEX IF NOT EXISTS idx_purchase_orders_scope    ON purchase_orders (cmp_id, fy_id, status, po_date DESC);
CREATE INDEX IF NOT EXISTS idx_purchase_orders_supplier ON purchase_orders (cmp_id, supplier_account_id);
CREATE UNIQUE INDEX IF NOT EXISTS idx_purchase_orders_uuid ON purchase_orders (po_uuid);

CREATE TABLE IF NOT EXISTS purchase_order_lines (
    line_id         BIGSERIAL PRIMARY KEY,
    po_id           BIGINT       NOT NULL REFERENCES purchase_orders(po_id) ON DELETE CASCADE,
    cmp_id          BIGINT       NOT NULL,
    line_no         INT          NOT NULL,
    requisition_line_id BIGINT   REFERENCES purchase_requisition_lines(line_id) ON DELETE SET NULL,
    quote_line_id   BIGINT       REFERENCES purchase_quote_lines(line_id) ON DELETE SET NULL,

    item_id         BIGINT,
    unit_id         BIGINT,
    warehouse_id    BIGINT,
    is_service      BOOLEAN      NOT NULL DEFAULT FALSE,
    description     TEXT,

    ordered_qty     NUMERIC(18,4) NOT NULL DEFAULT 0,

    -- Progress against OUR commitment, written from Inventory's and Books' own
    -- responses. These answer "is this PO complete?" — never "what is in stock?"
    -- or "what do we owe?", which belong to Inventory and Books respectively.
    received_qty    NUMERIC(18,4) NOT NULL DEFAULT 0,
    rejected_qty    NUMERIC(18,4) NOT NULL DEFAULT 0,
    billed_qty      NUMERIC(18,4) NOT NULL DEFAULT 0,
    returned_qty    NUMERIC(18,4) NOT NULL DEFAULT 0,

    -- The price we agreed on THIS order. Still true when the supplier's list
    -- price changes next month, which is why it is stored.
    agreed_rate     NUMERIC(18,4) NOT NULL DEFAULT 0,
    discount_pc     NUMERIC(6,3)  NOT NULL DEFAULT 0,
    discount_amount NUMERIC(18,4) NOT NULL DEFAULT 0,
    tax_cat_id      BIGINT,
    estimated_tax_pc NUMERIC(6,3) NOT NULL DEFAULT 0,
    line_amount     NUMERIC(18,4) NOT NULL DEFAULT 0,

    promised_date   DATE,
    hsn_sac         TEXT,
    created_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    UNIQUE (po_id, line_no)
);

CREATE INDEX IF NOT EXISTS idx_purchase_order_lines_item ON purchase_order_lines (cmp_id, item_id);

CREATE TABLE IF NOT EXISTS purchase_delivery_schedules (
    schedule_id     BIGSERIAL PRIMARY KEY,
    po_id           BIGINT       NOT NULL REFERENCES purchase_orders(po_id) ON DELETE CASCADE,
    line_id         BIGINT       REFERENCES purchase_order_lines(line_id) ON DELETE CASCADE,
    cmp_id          BIGINT       NOT NULL,
    scheduled_date  DATE         NOT NULL,
    scheduled_qty   NUMERIC(18,4) NOT NULL DEFAULT 0,
    warehouse_id    BIGINT,
    -- PLANNED | CONFIRMED | DELAYED | RECEIVED | CANCELLED
    status          TEXT         NOT NULL DEFAULT 'PLANNED',
    supplier_note   TEXT,
    created_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

-- --------------------------------------------------------------------------
-- Blanket agreements / rate contracts
--
-- The legal contract document, where Aicountly Contracts is deployed, belongs
-- there; we keep contract_uuid and the commercial configuration procurement
-- actually releases against.
-- --------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS purchase_agreements (
    agreement_id    BIGSERIAL PRIMARY KEY,
    cmp_id          BIGINT       NOT NULL,
    fy_id           BIGINT       NOT NULL,
    agreement_no    TEXT         NOT NULL,
    supplier_account_id BIGINT   NOT NULL,
    contract_uuid   TEXT,
    title           TEXT,
    valid_from      DATE,
    valid_to        DATE,
    max_value       NUMERIC(18,4),
    committed_value NUMERIC(18,4) NOT NULL DEFAULT 0,
    -- DRAFT | ACTIVE | EXHAUSTED | EXPIRED | TERMINATED
    status          TEXT         NOT NULL DEFAULT 'DRAFT',
    release_rules   JSONB        NOT NULL DEFAULT '{}'::jsonb,
    created_by      TEXT         NOT NULL,
    created_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    UNIQUE (cmp_id, agreement_no)
);

CREATE TABLE IF NOT EXISTS purchase_agreement_lines (
    line_id         BIGSERIAL PRIMARY KEY,
    agreement_id    BIGINT       NOT NULL REFERENCES purchase_agreements(agreement_id) ON DELETE CASCADE,
    cmp_id          BIGINT       NOT NULL,
    item_id         BIGINT,
    unit_id         BIGINT,
    description     TEXT,
    agreed_rate     NUMERIC(18,4) NOT NULL DEFAULT 0,
    committed_qty   NUMERIC(18,4) NOT NULL DEFAULT 0,
    released_qty    NUMERIC(18,4) NOT NULL DEFAULT 0,
    created_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

-- --------------------------------------------------------------------------
-- Receipts — ORCHESTRATION ONLY
--
-- The GRN is Inventory's. This table records that we asked for one and what
-- Inventory called the result. Every received quantity a screen displays is
-- read from Inventory by that uuid; the received_qty on our PO line is our own
-- progress, written from Inventory's response.
--
-- There is deliberately no purchase_grn_lines table.
-- --------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS purchase_receipt_requests (
    request_id      BIGSERIAL PRIMARY KEY,
    cmp_id          BIGINT       NOT NULL,
    fy_id           BIGINT       NOT NULL,
    bo_id           BIGINT       NOT NULL DEFAULT 0,
    po_id           BIGINT       NOT NULL REFERENCES purchase_orders(po_id) ON DELETE CASCADE,
    -- REQUESTED | ACCEPTED | FAILED | CANCELLED
    status          TEXT         NOT NULL DEFAULT 'REQUESTED',
    received_at     DATE,
    warehouse_id    BIGINT,
    supplier_dc_no  TEXT,
    supplier_dc_date DATE,
    vehicle_no      TEXT,
    requested_lines JSONB        NOT NULL DEFAULT '[]'::jsonb,

    -- What Inventory made of it. Read the GRN itself from Inventory.
    inventory_document_id   BIGINT,
    inventory_document_uuid TEXT,
    inventory_document_no   TEXT,

    last_error      TEXT,
    requested_by    TEXT         NOT NULL,
    created_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_purchase_receipts_po ON purchase_receipt_requests (cmp_id, po_id);

-- --------------------------------------------------------------------------
-- Vendor bills — ORCHESTRATION ONLY
--
-- Books owns the purchase voucher, the input GST, the TDS and the payable.
-- We keep the uuid and read the rest.
-- --------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS purchase_bill_requests (
    request_id      BIGSERIAL PRIMARY KEY,
    cmp_id          BIGINT       NOT NULL,
    fy_id           BIGINT       NOT NULL,
    bo_id           BIGINT       NOT NULL DEFAULT 0,
    po_id           BIGINT       REFERENCES purchase_orders(po_id) ON DELETE SET NULL,
    supplier_account_id BIGINT   NOT NULL,
    supplier_invoice_no TEXT,
    supplier_invoice_date DATE,
    -- DRAFT | MATCHING | MATCHED | EXCEPTION | POSTED | FAILED | CANCELLED
    status          TEXT         NOT NULL DEFAULT 'DRAFT',
    requested_lines JSONB        NOT NULL DEFAULT '[]'::jsonb,
    -- The receipts this bill is against, as Inventory's uuids.
    receipt_references JSONB     NOT NULL DEFAULT '[]'::jsonb,

    books_voucher_id   BIGINT,
    books_voucher_uuid TEXT,
    books_voucher_no   TEXT,

    last_error      TEXT,
    requested_by    TEXT         NOT NULL,
    created_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

-- --------------------------------------------------------------------------
-- Three-way match
--
-- PO (ours) vs GRN (Inventory's) vs bill (Books'). The engine COMPOSES those
-- three live; what is stored is the policy, the verdict and the human decision
-- about an exception. The three documents stay where they belong, which is why
-- there is nothing here to reconcile.
-- --------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS purchase_match_policies (
    policy_id       BIGSERIAL PRIMARY KEY,
    cmp_id          BIGINT       NOT NULL,
    policy_name     TEXT         NOT NULL,
    -- Tolerances, as a percentage or an absolute amount.
    qty_tolerance_pc      NUMERIC(6,3) NOT NULL DEFAULT 0,
    rate_tolerance_pc     NUMERIC(6,3) NOT NULL DEFAULT 0,
    value_tolerance_amt   NUMERIC(18,4) NOT NULL DEFAULT 0,
    freight_tolerance_amt NUMERIC(18,4) NOT NULL DEFAULT 0,
    -- Bills under this value skip the match entirely.
    auto_match_below_amt  NUMERIC(18,4) NOT NULL DEFAULT 0,
    is_default      BOOLEAN      NOT NULL DEFAULT FALSE,
    is_active       BOOLEAN      NOT NULL DEFAULT TRUE,
    created_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    UNIQUE (cmp_id, policy_name)
);

CREATE TABLE IF NOT EXISTS purchase_match_results (
    match_id        BIGSERIAL PRIMARY KEY,
    cmp_id          BIGINT       NOT NULL,
    fy_id           BIGINT       NOT NULL,
    po_id           BIGINT       REFERENCES purchase_orders(po_id) ON DELETE CASCADE,
    bill_request_id BIGINT       REFERENCES purchase_bill_requests(request_id) ON DELETE CASCADE,
    policy_id       BIGINT       REFERENCES purchase_match_policies(policy_id) ON DELETE SET NULL,
    -- MATCHED | WITHIN_TOLERANCE | REVIEW_REQUIRED | BLOCKED
    verdict         TEXT         NOT NULL,
    -- The variances found, per line and in total. Our arithmetic on three live
    -- reads — not a copy of any of the three documents.
    variances       JSONB        NOT NULL DEFAULT '[]'::jsonb,
    -- Which documents were compared, as references. The reader fetches them.
    compared_references JSONB    NOT NULL DEFAULT '{}'::jsonb,
    matched_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    matched_by      TEXT         NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_purchase_match_po ON purchase_match_results (cmp_id, po_id);

CREATE TABLE IF NOT EXISTS purchase_match_exceptions (
    exception_id    BIGSERIAL PRIMARY KEY,
    match_id        BIGINT       NOT NULL REFERENCES purchase_match_results(match_id) ON DELETE CASCADE,
    cmp_id          BIGINT       NOT NULL,
    -- quantity | rate | value | tax | freight | missing_receipt | over_billed
    exception_kind  TEXT         NOT NULL,
    detail          TEXT,
    po_value        NUMERIC(18,4),
    receipt_value   NUMERIC(18,4),
    bill_value      NUMERIC(18,4),
    variance_value  NUMERIC(18,4),
    -- OPEN | ACCEPTED | REJECTED | RESOLVED
    status          TEXT         NOT NULL DEFAULT 'OPEN',
    decided_by      TEXT,
    decided_at      TIMESTAMPTZ,
    decision_note   TEXT,
    created_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_purchase_exceptions_open ON purchase_match_exceptions (cmp_id, status)
    WHERE status = 'OPEN';

-- --------------------------------------------------------------------------
-- Returns and claims
-- --------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS purchase_returns (
    return_id       BIGSERIAL PRIMARY KEY,
    return_uuid     UUID         NOT NULL DEFAULT gen_random_uuid(),
    cmp_id          BIGINT       NOT NULL,
    fy_id           BIGINT       NOT NULL,
    bo_id           BIGINT       NOT NULL DEFAULT 0,
    return_no       TEXT         NOT NULL,
    return_date     DATE         NOT NULL,
    po_id           BIGINT       REFERENCES purchase_orders(po_id) ON DELETE SET NULL,
    supplier_account_id BIGINT   NOT NULL,
    -- DRAFT | APPROVED | DISPATCHED | DEBITED | CLOSED | CANCELLED
    status          TEXT         NOT NULL DEFAULT 'DRAFT',
    reason_code     TEXT,
    reason_note     TEXT,
    -- Inventory moves the stock out; Books raises the debit note.
    inventory_document_uuid TEXT,
    books_debit_note_uuid   TEXT,
    books_debit_note_id     BIGINT,
    created_by      TEXT         NOT NULL,
    created_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    UNIQUE (cmp_id, return_no)
);

CREATE TABLE IF NOT EXISTS purchase_return_lines (
    line_id         BIGSERIAL PRIMARY KEY,
    return_id       BIGINT       NOT NULL REFERENCES purchase_returns(return_id) ON DELETE CASCADE,
    cmp_id          BIGINT       NOT NULL,
    line_no         INT          NOT NULL,
    po_line_id      BIGINT       REFERENCES purchase_order_lines(line_id) ON DELETE SET NULL,
    item_id         BIGINT,
    unit_id         BIGINT,
    warehouse_id    BIGINT,
    batch_id        BIGINT,
    return_qty      NUMERIC(18,4) NOT NULL DEFAULT 0,
    rate            NUMERIC(18,4) NOT NULL DEFAULT 0,
    line_amount     NUMERIC(18,4) NOT NULL DEFAULT 0,
    reason_code     TEXT,
    created_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    UNIQUE (return_id, line_no)
);

-- Claims matter in Indian distribution: shortage, damage, rate difference,
-- scheme and rebate are everyday commercial events between buyer and supplier.
CREATE TABLE IF NOT EXISTS purchase_claims (
    claim_id        BIGSERIAL PRIMARY KEY,
    cmp_id          BIGINT       NOT NULL,
    fy_id           BIGINT       NOT NULL,
    claim_no        TEXT         NOT NULL,
    claim_date      DATE         NOT NULL,
    supplier_account_id BIGINT   NOT NULL,
    po_id           BIGINT       REFERENCES purchase_orders(po_id) ON DELETE SET NULL,
    -- shortage | damage | rate_difference | scheme | rebate | quality | late_delivery | other
    claim_kind      TEXT         NOT NULL,
    -- DRAFT | SUBMITTED | SUPPLIER_RESPONDED | APPROVED | REJECTED | SETTLED | CLOSED
    status          TEXT         NOT NULL DEFAULT 'DRAFT',
    claimed_amount  NUMERIC(18,4) NOT NULL DEFAULT 0,
    settled_amount  NUMERIC(18,4) NOT NULL DEFAULT 0,
    description     TEXT,
    supplier_response TEXT,
    -- Where a claim has an accounting consequence, Books records it.
    books_debit_note_uuid TEXT,
    created_by      TEXT         NOT NULL,
    settled_at      TIMESTAMPTZ,
    created_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    UNIQUE (cmp_id, claim_no)
);

CREATE INDEX IF NOT EXISTS idx_purchase_claims_supplier ON purchase_claims (cmp_id, supplier_account_id, status);
