<?php
/**
 * "Your Eleven" — daily fantasy cricket. Shared domain helpers.
 *
 * =================================================================================================
 * ISOLATION CONTRACT
 * =================================================================================================
 * This module is additive and self-contained:
 *
 *   - Every table it touches is prefixed "fantasy_" (see sql/migration-006-fantasy-postgres.sql).
 *     It never reads or writes "GameState", "RecentResult", "GameBet", the colour/aviator/mines/
 *     teenpatti state, or any existing config key.
 *   - It defines NO user table and NO balance column. Teams and entries carry the existing
 *     "User"."id", and money moves only through the existing debit_wallet() / credit_wallet() /
 *     insert_transaction() helpers in lib/helpers.php, inside the existing tx() wrapper — so the
 *     ledger, the admin revenue figures and the profile's game history all stay correct for free.
 *   - Nothing here runs unless FANTASY_ENABLED is true. With the flag off, index.php mounts no
 *     fantasy route at all, so the module is inert rather than merely hidden.
 *
 * The flag is read straight from the environment rather than added to config.php's $CONFIG array,
 * so that file stays untouched.
 *
 * =================================================================================================
 * WHY THE RULE NUMBERS LOOK FAMILIAR
 * =================================================================================================
 * The squad constraints below are not invented here. They are the same rules the (undeployed) Node
 * build already implements and tests in backend/lib/cricket/contests.js — validateLineup() there
 * enforces the identical squad size, role minimum/maximum, max-per-real-team, credit cap and
 * distinct captain/vice-captain checks, and backend/test_cricket.js asserts them. Keeping one set
 * of numbers means the two implementations cannot quietly disagree about what a legal team is, and
 * the Node suite stays a usable reference for this port.
 */

// -------------------------------------------------------------------------------------------------
// Feature gate
// -------------------------------------------------------------------------------------------------

/**
 * Is the fantasy module switched on for this deployment?
 *
 * Defaults to false, which is what keeps this whole feature invisible and inert on the live site
 * until an operator deliberately turns it on with FANTASY_ENABLED=true in php-backend/.env.
 */
function fantasy_enabled() {
    return env_bool('FANTASY_ENABLED', false);
}

// -------------------------------------------------------------------------------------------------
// Rules
// -------------------------------------------------------------------------------------------------

/**
 * The squad constraints a saved team must satisfy. Mirrors validateLineup() in
 * backend/lib/cricket/contests.js; see the note at the top of this file.
 */
function fantasy_rules() {
    return [
        'squad_size'        => 11,
        'credit_cap'        => 100.0,
        'max_per_real_team' => 7,
        'role_limits'       => [
            'WK'   => ['min' => 1, 'max' => 4],
            'BAT'  => ['min' => 3, 'max' => 6],
            'ALL'  => ['min' => 1, 'max' => 4],
            'BOWL' => ['min' => 3, 'max' => 6],
        ],
        'captain_multiplier'      => 2.0,
        'vice_captain_multiplier' => 1.5,
    ];
}

/** The four roles, in the order the team-builder screen shows its tabs. */
function fantasy_roles() {
    return ['WK', 'BAT', 'ALL', 'BOWL'];
}

/**
 * Map whatever a source calls a role onto one of our four.
 *
 * Schedule pages are inconsistent here ("Wicketkeeper Batter", "Batting Allrounder", "wk-bat"),
 * and the column has a CHECK constraint, so an unmapped value would abort the whole squad insert.
 * Anything unrecognised becomes BAT, which is the safe default: it is the least advantaged role in
 * the composition rules, so a mis-typed player can never unlock a team shape that should have been
 * illegal.
 */
function fantasy_normalise_role($raw) {
    $s = strtolower(trim((string) $raw));
    if ($s === '') return 'BAT';

    // Order matters: keeper first, because "wicketkeeper batter" contains "bat" too, and
    // all-rounder before the plain bat/bowl checks for the same reason.
    if (strpos($s, 'keep') !== false || strpos($s, 'wk') !== false) return 'WK';
    if (strpos($s, 'all') !== false || strpos($s, 'rounder') !== false) return 'ALL';
    if (strpos($s, 'bowl') !== false) return 'BOWL';
    if (strpos($s, 'bat') !== false) return 'BAT';
    return 'BAT';
}

/**
 * Clamp a credit value into the 8.0–10.5 band the column's CHECK constraint allows, rounded to one
 * decimal place. A value outside the band is a source/derivation bug, and clamping keeps one bad
 * number from rejecting an entire squad.
 */
function fantasy_clamp_credits($value) {
    $n = (float) $value;
    if (!is_finite($n) || $n <= 0) $n = 8.0;
    if ($n < 8.0)  $n = 8.0;
    if ($n > 10.5) $n = 10.5;
    return round($n, 1);
}

// -------------------------------------------------------------------------------------------------
// Ingestion (called by cron/fantasy-sync-matches.php)
// -------------------------------------------------------------------------------------------------

/**
 * Insert or update one fixture, keyed on external_key, and return its local id.
 *
 * Deliberately a SELECT-then-INSERT/UPDATE rather than an ON CONFLICT upsert: the same code then
 * works on both the MySQL and PostgreSQL connection layers this backend supports. The caller holds
 * a named lock for the whole sync, so there is no concurrent writer to race with.
 *
 * An existing row keeps its id, so teams and contests already attached to a fixture survive a
 * re-scrape. Status is NOT overwritten here — once a match has gone LIVE or SETTLED, a schedule
 * page still listing it as upcoming must not drag it backwards.
 */
function fantasy_upsert_match(array $m) {
    $key = (string) ($m['external_key'] ?? '');
    if ($key === '') throw new InvalidArgumentException('fantasy_upsert_match: external_key required');

    $existing = one('SELECT "id","status" FROM "fantasy_matches" WHERE "external_key" = ?', [$key]);

    $fields = [
        'series_name'          => (string) ($m['series_name'] ?? ''),
        'match_title'          => (string) ($m['match_title'] ?? ''),
        'team_a'               => (string) ($m['team_a'] ?? ''),
        'team_b'               => (string) ($m['team_b'] ?? ''),
        'team_a_short'         => (string) ($m['team_a_short'] ?? ''),
        'team_b_short'         => (string) ($m['team_b_short'] ?? ''),
        'team_a_logo'          => $m['team_a_logo'] ?? null,
        'team_b_logo'          => $m['team_b_logo'] ?? null,
        'format'               => (string) ($m['format'] ?? 'T20'),
        'venue'                => $m['venue'] ?? null,
        'start_time'           => ms_to_sql((int) $m['start_time_ms']),
        'lock_time'            => ms_to_sql((int) ($m['lock_time_ms'] ?? $m['start_time_ms'])),
        'scorecard_source_url' => $m['scorecard_source_url'] ?? null,
    ];

    if ($existing) {
        $sets = [];
        $args = [];
        foreach ($fields as $col => $val) { $sets[] = '"' . $col . '" = ?'; $args[] = $val; }
        $sets[] = '"last_synced_at" = ?';  $args[] = ms_to_sql(now_ms());
        $sets[] = '"updated_at" = ?';      $args[] = ms_to_sql(now_ms());
        $args[] = (int) $existing['id'];
        q('UPDATE "fantasy_matches" SET ' . implode(', ', $sets) . ' WHERE "id" = ?', $args);
        return (int) $existing['id'];
    }

    $cols = array_keys($fields);
    $cols[] = 'external_key';
    $cols[] = 'status';
    $cols[] = 'last_synced_at';
    $args = array_values($fields);
    $args[] = $key;
    $args[] = 'UPCOMING';
    $args[] = ms_to_sql(now_ms());

    $quoted = '"' . implode('","', $cols) . '"';
    $marks  = implode(',', array_fill(0, count($cols), '?'));
    q('INSERT INTO "fantasy_matches" (' . $quoted . ') VALUES (' . $marks . ')', $args);

    return (int) scalar('SELECT "id" FROM "fantasy_matches" WHERE "external_key" = ?', [$key], 0);
}

/**
 * Insert or update one squad member for a match, keyed on (match_id, external_key).
 *
 * Credits are only written on INSERT. Once a fixture is live in the lobby, silently re-pricing a
 * player under teams that were already built against the old number would change what those teams
 * cost after the fact, so a re-scrape leaves credits alone.
 */
function fantasy_upsert_player($matchId, array $p) {
    $matchId = (int) $matchId;
    $key = (string) ($p['external_key'] ?? '');
    if ($key === '') return 0;

    $existing = one(
        'SELECT "id" FROM "fantasy_players" WHERE "match_id" = ? AND "external_key" = ?',
        [$matchId, $key]
    );

    $name     = (string) ($p['name'] ?? '');
    $fullName = (string) ($p['full_name'] ?? $name);
    $team     = (string) ($p['team_name'] ?? '');
    $role     = fantasy_normalise_role($p['role'] ?? '');

    if ($existing) {
        q('UPDATE "fantasy_players" SET "name" = ?, "full_name" = ?, "team_name" = ?, "role" = ? WHERE "id" = ?',
          [$name, $fullName, $team, $role, (int) $existing['id']]);
        return (int) $existing['id'];
    }

    q('INSERT INTO "fantasy_players" ("match_id","external_key","name","full_name","team_name","role","credits") '
      . 'VALUES (?,?,?,?,?,?,?)',
      [$matchId, $key, $name, $fullName, $team, $role, fantasy_clamp_credits($p['credits'] ?? 8.0)]);

    return (int) scalar(
        'SELECT "id" FROM "fantasy_players" WHERE "match_id" = ? AND "external_key" = ?',
        [$matchId, $key], 0
    );
}

/**
 * Can a legal XI actually be built from what we ingested for this match?
 *
 * This is the gate for showing a fixture in the lobby, and it asks the real question rather than a
 * proxy like "did we store at least 22 rows". A squad can be the right size and still be unusable —
 * a scrape that missed the keepers, or mapped every all-rounder to BAT, leaves a pool that cannot
 * satisfy the role minimums, and the player would only discover that halfway through building a
 * team. Checking it here means a broken scrape shows no card instead of a dead end.
 */
function fantasy_squad_is_usable($matchId) {
    $rules   = fantasy_rules();
    $grouped = fantasy_players_grouped($matchId);

    $total = 0;
    foreach ($grouped as $list) $total += count($list);
    if ($total < $rules['squad_size']) return false;

    foreach ($rules['role_limits'] as $role => $limit) {
        if (count($grouped[$role] ?? []) < (int) $limit['min']) return false;
    }
    return true;
}

/** Flag a fixture as having a usable squad, so the lobby will show it. */
function fantasy_mark_squads_ready($matchId, $ready = true) {
    q('UPDATE "fantasy_matches" SET "squads_ready" = ?, "updated_at" = ? WHERE "id" = ?',
      [$ready ? 1 : 0, ms_to_sql(now_ms()), (int) $matchId]);
}

/** Record that a worker ran, so an operator can see the pipeline is alive without a shell. */
function fantasy_log_sync($worker, $status, $detail = null) {
    try {
        q('INSERT INTO "fantasy_sync_log" ("worker","status","detail","ran_at") VALUES (?,?,?,?)',
          [(string) $worker, (string) $status, $detail === null ? null : (string) $detail, ms_to_sql(now_ms())]);
    } catch (Throwable $e) {
        // Bookkeeping must never be the thing that fails a sync.
        log_error('fantasy: sync log write failed: ' . $e->getMessage());
    }
}

// -------------------------------------------------------------------------------------------------
// Read helpers (used by routes/fantasy.php)
// -------------------------------------------------------------------------------------------------

/**
 * Shape one "fantasy_matches" row for the API.
 *
 * Timestamps go out in the same naive "Y-m-d H:i:s.v" UTC form every other endpoint in this backend
 * uses, because assets/js/ui-common.js already knows to read that as UTC (see profileFmtDate). The
 * countdown is sent as "seconds_to_lock" as well, so the lobby does not have to trust the device
 * clock to decide whether a match is still joinable — a phone with a wrong clock would otherwise
 * show a live match as open, or hide one that is not.
 */
function fantasy_public_match(array $row) {
    $startMs = sql_to_ms($row['start_time'] ?? null);
    $lockMs  = sql_to_ms($row['lock_time'] ?? null);
    $nowMs   = now_ms();

    return [
        'id'            => (int) $row['id'],
        'series_name'   => (string) $row['series_name'],
        'match_title'   => (string) $row['match_title'],
        'team_a'        => (string) $row['team_a'],
        'team_b'        => (string) $row['team_b'],
        'team_a_short'  => (string) $row['team_a_short'],
        'team_b_short'  => (string) $row['team_b_short'],
        'team_a_logo'   => $row['team_a_logo'],
        'team_b_logo'   => $row['team_b_logo'],
        'format'        => (string) $row['format'],
        'venue'         => $row['venue'],
        'start_time'    => $row['start_time'],
        'lock_time'     => $row['lock_time'],
        'status'        => (string) $row['status'],
        'squads_ready'  => ((int) $row['squads_ready']) === 1,
        // Negative once the deadline has passed; the lobby clamps at zero for display.
        'seconds_to_start' => $startMs === null ? null : (int) floor(($startMs - $nowMs) / 1000),
        'seconds_to_lock'  => $lockMs  === null ? null : (int) floor(($lockMs  - $nowMs) / 1000),
        'is_locked'        => $lockMs === null ? true : ($nowMs >= $lockMs),
    ];
}

/**
 * Fixtures for the lobby, soonest first.
 *
 * UPCOMING deliberately means "upcoming AND still joinable AND has a squad": a match whose lock time
 * has passed is filtered out here rather than being shown with a frozen 00:00 countdown, and one
 * with no squad yet is hidden because tapping it would open an empty team builder.
 */
function fantasy_list_matches($status = 'UPCOMING', $limit = 60) {
    $status = strtoupper(trim((string) $status));
    if (!in_array($status, ['UPCOMING', 'LIVE', 'SETTLED', 'CANCELLED'], true)) {
        $status = 'UPCOMING';
    }
    $limit = max(1, min(200, (int) $limit));

    if ($status === 'UPCOMING') {
        return all(
            'SELECT * FROM "fantasy_matches" WHERE "status" = ? AND "squads_ready" = 1 AND "lock_time" > ? '
            . 'ORDER BY "start_time" ASC LIMIT ' . $limit,
            ['UPCOMING', ms_to_sql(now_ms())]
        );
    }
    return all(
        'SELECT * FROM "fantasy_matches" WHERE "status" = ? ORDER BY "start_time" DESC LIMIT ' . $limit,
        [$status]
    );
}

/** One fixture by id, or null. */
function fantasy_find_match($id) {
    $row = one('SELECT * FROM "fantasy_matches" WHERE "id" = ?', [(int) $id]);
    return $row ?: null;
}

/**
 * A match's squad, grouped by role in team-builder tab order, with each group ordered by credits
 * descending so the players worth picking are at the top of the list.
 */
function fantasy_players_grouped($matchId) {
    $rows = all(
        'SELECT "id","name","full_name","team_name","role","credits","is_playing" '
        . 'FROM "fantasy_players" WHERE "match_id" = ? ORDER BY "credits" DESC, "name" ASC',
        [(int) $matchId]
    );

    $grouped = [];
    foreach (fantasy_roles() as $role) $grouped[$role] = [];

    foreach ($rows as $r) {
        $role = fantasy_normalise_role($r['role']);
        $grouped[$role][] = [
            'id'         => (int) $r['id'],
            'name'       => (string) $r['name'],
            'full_name'  => (string) $r['full_name'],
            'team_name'  => (string) $r['team_name'],
            'role'       => $role,
            'credits'    => (float) $r['credits'],
            // null until a confirmed XI is published; the UI shows no badge in that case.
            'is_playing' => $r['is_playing'] === null ? null : (((int) $r['is_playing']) === 1),
        ];
    }
    return $grouped;
}
