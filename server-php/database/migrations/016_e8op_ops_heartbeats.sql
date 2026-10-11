-- E8-OPS (F262, F160): when each scheduled job of this product last ran, for /api/health and the deploy.
-- Expand-only and idempotent: a new table, nothing existing changes.
CREATE TABLE IF NOT EXISTS purchases_ops_heartbeats (
    job_name         TEXT        PRIMARY KEY,
    last_started_at  TIMESTAMPTZ,
    last_finished_at TIMESTAMPTZ,
    last_ok          BOOLEAN,
    last_report      JSONB       NOT NULL DEFAULT '{}'::jsonb,
    runs             BIGINT      NOT NULL DEFAULT 0,
    updated_at       TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- The monitor and /api/health count open commands by age; status + updated_at keeps that cheap.
CREATE INDEX IF NOT EXISTS purchase_integration_commands_open_age_idx ON purchase_integration_commands (status, updated_at);
