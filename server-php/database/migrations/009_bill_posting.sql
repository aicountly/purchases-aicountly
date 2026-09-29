-- ---------------------------------------------------------------------------
-- Aicountly Purchases — bills that post what they say, once
--
-- A bill is posted to Books as a purchase voucher composed from its party, its
-- supplier invoice and its lines. What changes here:
--
--   revision            a bill corrected after Books refused it is a new operation
--                       with a new idempotency key; a retry is not
--   bill_kind           po | direct | service — a bill without an order is a real
--                       journey (services, expenses, direct purchases), reviewed
--                       before it posts, not a UI dead end
--   due_date            the supplier's due date, when the bill states one
--   stock_effect        how its goods reach stock in Inventory, decided when it is
--   stock_settlements   sent: which GRNs it settles and how much of each — stored,
--                       so a second bill against the same order cannot settle the
--                       same goods while the first is still on its way to Books
--   posting_check       our verdict on what Books actually recorded, read back after
--                       posting: balanced, the right creditor, the right bill
--                       reference, the right total. An HTTP 200 is not the
--                       acceptance test. A verdict and its reasons — not a copy of
--                       the voucher, which stays in Books.
-- ---------------------------------------------------------------------------

ALTER TABLE purchase_bill_requests
    ADD COLUMN IF NOT EXISTS revision            INT          NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS bill_kind           TEXT         NOT NULL DEFAULT 'po',
    ADD COLUMN IF NOT EXISTS due_date            DATE,
    ADD COLUMN IF NOT EXISTS stock_effect        TEXT,
    ADD COLUMN IF NOT EXISTS stock_settlements   JSONB,
    ADD COLUMN IF NOT EXISTS posting_check       JSONB,
    ADD COLUMN IF NOT EXISTS posted_at           TIMESTAMPTZ,
    ADD COLUMN IF NOT EXISTS posted_by           TEXT,
    ADD COLUMN IF NOT EXISTS cancelled_at        TIMESTAMPTZ,
    ADD COLUMN IF NOT EXISTS cancel_reason       TEXT;

UPDATE purchase_bill_requests SET bill_kind = 'direct' WHERE po_id IS NULL AND bill_kind = 'po';

-- The exact repeat — same supplier, same invoice number — is refused by the service
-- first; this makes it impossible for two submissions arriving together to both pass
-- that check. CANCELLED bills do not hold the number.
--
-- A database that already holds such a pair (entered before this) keeps working: the
-- index is created only when the data allows it, and bin/receipt-repair.php lists the
-- pairs and creates the index once they are resolved.
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM purchase_bill_requests
         WHERE status <> 'CANCELLED' AND supplier_invoice_no IS NOT NULL
         GROUP BY cmp_id, supplier_account_id, lower(supplier_invoice_no)
        HAVING COUNT(*) > 1
    ) THEN
        CREATE UNIQUE INDEX IF NOT EXISTS uq_purchase_bills_supplier_invoice
            ON purchase_bill_requests (cmp_id, supplier_account_id, lower(supplier_invoice_no))
            WHERE status <> 'CANCELLED' AND supplier_invoice_no IS NOT NULL;
    ELSE
        RAISE WARNING 'purchase_bill_requests holds duplicate supplier invoices; uq_purchase_bills_supplier_invoice not created. Run bin/receipt-repair.php.';
    END IF;
END $$;
