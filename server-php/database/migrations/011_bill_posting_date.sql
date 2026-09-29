-- ---------------------------------------------------------------------------
-- Aicountly Purchases — a bill's posting date, apart from the supplier's invoice date
--
-- The supplier's invoice date is a fact about the supplier's document; the date the
-- bill is booked on is ours. They differ whenever an invoice dated in one financial
-- year is booked in the next (dated 31 March, received 3 April). Books keeps them
-- apart — vch_date is the posting date, bill.bill_date the supplier's — and the bill
-- used to send the supplier's date as both, so such an invoice could only be refused.
-- NULL means the supplier's invoice date (every bill entered before this).
-- ---------------------------------------------------------------------------

ALTER TABLE purchase_bill_requests
    ADD COLUMN IF NOT EXISTS posting_date DATE;
