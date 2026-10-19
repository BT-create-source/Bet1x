-- =================================================================================================
-- Migration 015 — one account per username and per email, ignoring upper/lower case.
--
-- "User"."username" was unique only case-SENSITIVELY, while every lookup in the code is
-- case-INsensitive (LOWER("username") = LOWER(?)). Two signups racing each other ("Bob" and "bob")
-- could both pass the "already taken?" check and both be created. After that, every statement that
-- matched players by LOWER(username) touched BOTH rows — a Teen Patti win was credited to both
-- accounts — and a name lookup returned whichever row the database happened to find first.
--
-- The same goes for email, which login and password reset look up case-insensitively.
--
-- lib/helpers.php applies this itself on first use (ensure_user_identity_indexes), so running this
-- file is optional. If either CREATE fails, two existing accounts already collide: find them with
--   SELECT LOWER("username"), COUNT(*) FROM "User" GROUP BY 1 HAVING COUNT(*) > 1;
--   SELECT LOWER("email"),    COUNT(*) FROM "User" WHERE "email" <> '' GROUP BY 1 HAVING COUNT(*) > 1;
-- rename or merge them by hand, then run this again. Idempotent: safe to re-run.
-- =================================================================================================

CREATE UNIQUE INDEX IF NOT EXISTS "User_username_lower_key" ON "User" (LOWER("username"));
CREATE UNIQUE INDEX IF NOT EXISTS "User_email_lower_key" ON "User" (LOWER("email"))
  WHERE "email" IS NOT NULL AND "email" <> '';
