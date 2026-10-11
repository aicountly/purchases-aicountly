-- ---------------------------------------------------------------------------
-- 022 — one supplier invoice, several bills, the invoice number kept (E8-PUR: F170)
--
-- A supplier invoice that covers two purchase orders, or goods from a challan-only and a
-- physical goods receipt, cannot be one Books voucher (one voucher has one stock effect and one
-- order here). It used to be refused, and the only way through was to change the invoice number
-- (INV-MIX-1, INV-MIX-2) — so Books' bill reference no longer matched the supplier's invoice,
-- the supplier statement and GSTR-2B. Each bill is now a numbered PART of the supplier invoice:
-- the invoice number stays exactly the supplier's, the part is kept beside it, and part 2 onwards
-- needs a stated reason (sent to Books, which records it against its own duplicate register).
--
-- The duplicate-invoice index now includes the part. It is rebuilt only where it exists (where
-- migration 009 could create it, the data is unique per invoice, so it is unique per part too);
-- where it is missing, bin/receipt-repair.php creates the new definition once duplicates are
-- resolved. Idempotent.
-- ---------------------------------------------------------------------------

ALTER TABLE purchase_bill_requests
    ADD COLUMN IF NOT EXISTS invoice_part INT NOT NULL DEFAULT 1,
    ADD COLUMN IF NOT EXISTS invoice_part_reason TEXT;

DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_indexes WHERE indexname = 'uq_purchase_bills_supplier_invoice')
       AND NOT EXISTS (SELECT 1 FROM pg_indexes WHERE indexname = 'uq_purchase_bills_supplier_invoice' AND indexdef ILIKE '%invoice_part%') THEN
        DROP INDEX uq_purchase_bills_supplier_invoice;
        CREATE UNIQUE INDEX uq_purchase_bills_supplier_invoice
            ON purchase_bill_requests (cmp_id, supplier_account_id, lower(supplier_invoice_no), invoice_part)
            WHERE status <> 'CANCELLED' AND supplier_invoice_no IS NOT NULL;
    END IF;
END $$;
