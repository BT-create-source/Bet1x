<?php
/**
 * Sign-in presence — who is currently logged in to the platform.
 *
 * Player logins are stateless JWTs, so there was no way to list "who is signed in right now". This
 * records a last-seen timestamp per username on every authenticated request (throttled to at most
 * one write per ~20s per user), and reads it back as the online list. The super admin's test-account
 * manager uses it to show a real list of signed-in accounts to pick from, instead of only names that
 * have bet.
 *
 * Schema: sql/migration-017-session-presence-postgres.sql, also created on first use. Best-effort:
 * nothing here ever breaks the request it rides on.
 */

require_once __DIR__ . '/db.php';

const SESSION_ONLINE_TTL_MS = 900000;   // 15 minutes: "signed in now"

function presence_ensure() {
    static $done = null;
    if ($done !== null) return $done;
    try {
        if ((int) state_get('session_presence_schema_version') < 17) {
            q('CREATE TABLE IF NOT EXISTS "SessionPresence" (
                "username"  TEXT PRIMARY KEY,
                "last_seen" BIGINT NOT NULL,
                "role"      TEXT
            )');
            q('CREATE INDEX IF NOT EXISTS "SessionPresence_last_seen_idx" ON "SessionPresence" ("last_seen")');
            state_set('session_presence_schema_version', 17);
        }
        $done = true;
    } catch (Throwable $e) {
        $done = false;   // no database (JSON fallback): there is nothing to record into
    }
    return $done;
}

/** Record that this user was just seen. Throttled in the DB so a busy poller does not write each time. */
function touch_session_presence($username, $role = 'user') {
    $u = strtolower(trim((string) $username));
    if ($u === '' || !presence_ensure()) return;
    try {
        $now = now_ms();
        q('INSERT INTO "SessionPresence" ("username","last_seen","role") VALUES (?,?,?)
           ON CONFLICT ("username") DO UPDATE SET "last_seen" = EXCLUDED."last_seen", "role" = EXCLUDED."role"
           WHERE "SessionPresence"."last_seen" < ?',
          [$u, $now, $role, $now - 20000]);
        // Opportunistic cleanup, roughly 1 call in 200, of rows older than a day.
        if (random_int(1, 200) === 1) q('DELETE FROM "SessionPresence" WHERE "last_seen" < ?', [$now - 86400000]);
    } catch (Throwable $e) { /* presence is best-effort */ }
}

/** Usernames seen within $ttlMs (default 15 min). Operator accounts (admin/superadmin) are left out. */
function online_usernames($ttlMs = SESSION_ONLINE_TTL_MS) {
    if (!presence_ensure()) return [];
    try {
        $rows = all('SELECT "username" FROM "SessionPresence" WHERE "last_seen" >= ? AND COALESCE("role",\'user\') = \'user\' ORDER BY "last_seen" DESC',
                    [now_ms() - (int) $ttlMs]);
        return array_map(function ($r) { return $r['username']; }, is_array($rows) ? $rows : []);
    } catch (Throwable $e) { return []; }
}

/** Per-user last-seen map for the given usernames (lowercased), for showing "online N min ago". */
function session_last_seen_map(array $usernames) {
    if (!$usernames || !presence_ensure()) return [];
    $out = [];
    try {
        foreach ($usernames as &$u) $u = strtolower(trim((string) $u));
        unset($u);
        $usernames = array_values(array_unique(array_filter($usernames)));
        if (!$usernames) return [];
        $place = implode(',', array_fill(0, count($usernames), '?'));
        foreach (all('SELECT "username","last_seen" FROM "SessionPresence" WHERE "username" IN (' . $place . ')', $usernames) as $r) {
            $out[strtolower((string) $r['username'])] = (int) $r['last_seen'];
        }
    } catch (Throwable $e) {}
    return $out;
}
