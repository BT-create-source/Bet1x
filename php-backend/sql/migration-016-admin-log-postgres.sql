-- =================================================================================================
-- Migration 016 — operator action log.
--
-- Records every successful action taken with an admin or superadmin session (balance adjustments,
-- deposit / withdrawal decisions, settings and game-config changes, bot takeover changes ...), so the
-- super admin console can show who did what and when. Nothing recorded operator activity before.
-- Secrets are never written (lib/adminlog.php drops password / token / key / OTP / hash fields).
--
-- lib/adminlog.php applies this itself on first use (admin_log_ensure), so running this file is
-- optional; it is here so the schema is written down. Additive and idempotent: safe to re-run.
-- =================================================================================================

CREATE TABLE IF NOT EXISTS "AdminLog" (
  "id"         SERIAL PRIMARY KEY,
  "created_at" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  "actor"      TEXT,
  "role"       TEXT,
  "method"     TEXT,
  "path"       TEXT,
  "action"     TEXT,
  "summary"    TEXT,
  "status"     INTEGER,
  "ip"         TEXT
);
CREATE INDEX IF NOT EXISTS "AdminLog_created_at_idx" ON "AdminLog" ("created_at");
