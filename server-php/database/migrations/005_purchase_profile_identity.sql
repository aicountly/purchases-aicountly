-- ---------------------------------------------------------------------------
-- Aicountly Purchases — purchase profile identity
--
-- `purchase_settings` has always BEEN the company's purchase profile: the
-- document series, the approval thresholds and the two procurement controls
-- every document in this product is written against. What it never had was a
-- name. A screen that asks somebody to configure their procurement rules and
-- cannot then tell them what they configured is a screen they have to
-- rediscover every time they open it.
--
-- ADDITIVE ONLY, and deliberately so. One row per company is not an accident
-- of this table, it is the contract five callers depend on -- NumberSeries,
-- RequisitionService, BillService, PurchaseOrderService and InsightRules all
-- read it as `WHERE cmp_id = :cmp` and expect exactly one answer. Turning it
-- into a set of profiles would mean each of those five picking one, and a
-- purchase order numbered from a profile nobody selected is a worse outcome
-- than a profile that is simply named.
--
-- So: identity columns on the row that already exists. Nothing moves, nothing
-- is copied, and every existing query returns exactly what it returned before.
-- ---------------------------------------------------------------------------

ALTER TABLE purchase_settings
    -- Short code the buyer recognises. Not a key: `cmp_id` is the key, and this
    -- is deliberately not unique across companies, because two companies in the
    -- same group calling their profile 'PR' is normal rather than a collision.
    ADD COLUMN IF NOT EXISTS profile_code TEXT NOT NULL DEFAULT '',

    ADD COLUMN IF NOT EXISTS profile_name TEXT NOT NULL DEFAULT '',

    -- Which kind of procurement this profile describes. The values are the
    -- catalogue in SettingsController::PROFILE_TYPES; it is stored as text
    -- rather than an enum type so adding a sixth kind is a code change and not
    -- a migration on a live table.
    ADD COLUMN IF NOT EXISTS profile_type TEXT NOT NULL DEFAULT 'STANDARD',

    ADD COLUMN IF NOT EXISTS description TEXT NOT NULL DEFAULT '',

    -- Whether this profile may be used for NEW documents. It does not, and must
    -- not, retire the numbering series or the thresholds behind existing ones:
    -- a purchase order raised last March was raised under the rules in force
    -- last March, and an inactive profile does not rewrite its history.
    ADD COLUMN IF NOT EXISTS is_active BOOLEAN NOT NULL DEFAULT TRUE;
