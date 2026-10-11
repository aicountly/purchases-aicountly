-- ---------------------------------------------------------------------------
-- 016 — match decisions that bind (launch readiness E8-PUR: A26, A31, F228, F265, F497)
--
-- A rejected match exception used to be "decided" and therefore resolved: the bill could then
-- be posted at the very variance somebody had just rejected. A rejection now stops the bill
-- (purchase_bill_requests.status REJECTED) until it is revised (a new revision, re-matched,
-- with fresh exceptions) or cancelled.
--
-- A decision belongs to the exact bill it was made on. Each match records the bill revision it
-- compared (bill_revision) and each exception a fingerprint of what it found (kind, order line,
-- values). Re-running the match on the SAME revision carries an earlier ACCEPTED or REJECTED
-- decision over to an identical new exception (carried_from_exception_id), so neither a re-run
-- nor a reader can undo a decision; anything that changed is a new, OPEN exception.
--
-- Staged and idempotent: columns are added if missing; bills whose latest match already holds a
-- REJECTED exception and which have not reached Smart Books are moved to REJECTED. Nothing
-- posted, cancelled or in flight is touched.
-- ---------------------------------------------------------------------------

ALTER TABLE purchase_match_results
    ADD COLUMN IF NOT EXISTS bill_revision INT;

ALTER TABLE purchase_match_exceptions
    ADD COLUMN IF NOT EXISTS fingerprint TEXT,
    ADD COLUMN IF NOT EXISTS po_line_id BIGINT,
    ADD COLUMN IF NOT EXISTS carried_from_exception_id BIGINT;

CREATE INDEX IF NOT EXISTS idx_purchase_match_results_bill
    ON purchase_match_results (bill_request_id, match_id DESC);

UPDATE purchase_bill_requests b
   SET status = 'REJECTED',
       last_error = COALESCE(b.last_error, 'A match exception on this bill was rejected. Revise the bill or cancel it.'),
       updated_at = NOW()
 WHERE b.status IN ('MATCHING', 'MATCHED', 'EXCEPTION', 'FAILED')
   AND EXISTS (
       SELECT 1
         FROM purchase_match_exceptions e
        WHERE e.status = 'REJECTED'
          AND e.match_id = (SELECT m.match_id FROM purchase_match_results m
                             WHERE m.bill_request_id = b.request_id
                             ORDER BY m.match_id DESC LIMIT 1)
   );
