-- Migration 030: an administrator's removal of a person from Purchases in a company is remembered.
--
-- Workspace-wide access (company-access 1.2.0, ManageSeats::seatWorkspaceMember) gives a workspace member
-- the starter profile Manage names on their first entry to a company, because Manage stores no seat for
-- it. Without a record of a removal, an administrator who took that person's last Purchases profile away
-- (Administration -> Access) saw them re-seated on their very next request. A row here says "removed by
-- an administrator of this company": first-entry seating leaves that person alone until someone grants
-- them a profile again (here, or Manage's signed seat event), which deletes the row.
--
--   purchase_access_removals   one row per company and person whose last Purchases profile an
--                              administrator removed; deleted again on the next explicit grant
--
-- Additive and re-runnable. Listed in config/lifecycle-manifest.php (purged with the company).

CREATE TABLE IF NOT EXISTS purchase_access_removals (
    cmp_id        BIGINT        NOT NULL,
    user_uuid     TEXT          NOT NULL,
    removed_by    TEXT          NOT NULL,
    removed_at    TIMESTAMPTZ   NOT NULL DEFAULT NOW(),
    PRIMARY KEY (cmp_id, user_uuid)
);
