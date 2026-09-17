-- ---------------------------------------------------------------------------
-- Aicountly Purchases — access administration
--
-- The permission tables have existed since 001, and `access.manage` has been in
-- the catalogue just as long, but nothing could write to them: there was no
-- endpoint and no screen. So the company owner (acs_type = 1 from the portal)
-- held every permission implicitly and nobody else could be granted anything.
-- A single-owner company never noticed; the moment a buyer or an AP clerk was
-- added, they hit a wall no administrator could open.
--
-- This adds only what an assignment needs to be administrable. The profiles and
-- assignments themselves were already modelled correctly.
-- ---------------------------------------------------------------------------

ALTER TABLE purchase_permission_assignments
    -- A label the ADMINISTRATOR types, so a list of access grants reads as
    -- people rather than as uuids. It is never fetched from the portal and is
    -- never treated as the user's name: identity belongs to my.aicountly.com,
    -- and a copy of it here would go stale the day somebody marries.
    ADD COLUMN IF NOT EXISTS member_label TEXT,

    -- Why this person has this profile. The single most useful thing an auditor
    -- can read a year later, and the reason this is not just a checkbox.
    ADD COLUMN IF NOT EXISTS note TEXT,

    ADD COLUMN IF NOT EXISTS assigned_by TEXT,
    ADD COLUMN IF NOT EXISTS updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW();

-- The members screen lists by person, so that is how it is indexed.
CREATE INDEX IF NOT EXISTS idx_purchase_perm_assign_label
    ON purchase_permission_assignments (cmp_id, member_label);

-- A profile nobody can edit into uselessness. `is_system` already existed;
-- this records which starter set a system profile came from, so the bootstrap
-- can tell "already created" from "the administrator made one with this name".
ALTER TABLE purchase_permission_profiles
    ADD COLUMN IF NOT EXISTS system_key TEXT,
    ADD COLUMN IF NOT EXISTS created_by TEXT;

CREATE UNIQUE INDEX IF NOT EXISTS idx_purchase_perm_profile_system
    ON purchase_permission_profiles (cmp_id, system_key)
    WHERE system_key IS NOT NULL;
