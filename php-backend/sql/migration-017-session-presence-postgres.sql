-- =================================================================================================
-- Migration 017 — sign-in presence (who is currently logged in).
--
-- Player sessions are stateless JWTs, so nothing recorded who was online. lib/presence.php writes a
-- last-seen timestamp here on each authenticated request (throttled), and the super admin's test-
-- account manager lists accounts seen in the last 15 minutes.
--
-- lib/presence.php creates this itself on first use (presence_ensure), so running this file is
-- optional. Additive and idempotent: safe to re-run.
-- =================================================================================================

CREATE TABLE IF NOT EXISTS "SessionPresence" (
  "username"  TEXT PRIMARY KEY,
  "last_seen" BIGINT NOT NULL,
  "role"      TEXT
);
CREATE INDEX IF NOT EXISTS "SessionPresence_last_seen_idx" ON "SessionPresence" ("last_seen");
