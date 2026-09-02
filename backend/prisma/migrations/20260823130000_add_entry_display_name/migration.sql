-- The name shown on the leaderboard, when it differs from the account that owns the entry.
--
-- This exists for the house entry. It is placed by a real house account, which is what pays the
-- entry fee into the pool and what the operator console filters on (`is_house`), but that account
-- name must never appear in the player-facing leaderboard — an entrant called "house" alongside
-- ordinary usernames is the most obvious possible tell. The display name comes from the same filler
-- generator that already names simulated Teen Patti players.
--
-- NULL for every real entry, which reads through as the username, so nothing existing changes.
ALTER TABLE "CricketEntry" ADD COLUMN "display_name" TEXT;
