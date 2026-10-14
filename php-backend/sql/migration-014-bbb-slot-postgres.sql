-- =================================================================================================
-- Migration 014 — Ball by Ball markets identified by their ball, not by list position.
--
-- A market used to be tied to "the Nth delivery in the innings". Feeds correct themselves — a ball
-- first posted as a wide becomes a legal four, a wrong entry is withdrawn — and every such fix moved
-- the later deliveries along the list, so a market labelled 19.4 could settle on 19.5. A market is
-- now the ball its label names: "slot" = legal balls bowled before it (19.4 -> 117), "attempt" = how
-- many wides/no-balls were already bowled in that slot (0 for the first try, 1 for its re-bowl ...).
-- delivery_idx stays, for information only, and is no longer unique.
--
-- lib/bbb.php applies the same change itself on first use (bbb_ensure_schema), so running this file
-- is optional; it is here so the schema is written down. Additive and idempotent: safe to re-run.
-- Rows from before the change keep slot NULL and settle by their position, as they always did.
-- =================================================================================================

ALTER TABLE "bbb_rounds" ADD COLUMN IF NOT EXISTS "slot" INTEGER;
ALTER TABLE "bbb_rounds" ADD COLUMN IF NOT EXISTS "attempt" INTEGER;
ALTER TABLE "bbb_rounds" DROP CONSTRAINT IF EXISTS "bbb_rounds_one_per_delivery";
CREATE UNIQUE INDEX IF NOT EXISTS "bbb_rounds_one_per_ball" ON "bbb_rounds" ("match_key", "innings", "slot", "attempt");
