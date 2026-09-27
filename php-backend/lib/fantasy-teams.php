<?php
/**
 * "Your Eleven" — team creation, lineup validation and the lock deadline.
 *
 * =================================================================================================
 * THIS IS A PORT, NOT A NEW RULE SET
 * =================================================================================================
 * fantasy_validate_lineup() below is a faithful port of validateLineup() in
 * backend/lib/cricket/contests.js — same checks, same order, same intent, including the two details
 * that are easy to lose:
 *
 *   1. An unpriced player defaults to the mid-band credit value, never to free. A missing credit
 *      row must not make a player CHEAPER than one that has been priced, or a bad ingest becomes a
 *      way to field a superteam inside the budget.
 *   2. The budget comparison carries a small epsilon. Credits are one-decimal values summed in
 *      floating point, and 11 of them can land at 100.00000000001; rejecting that as "over budget"
 *      would be wrong.
 *
 * Keeping the two implementations in step matters because the Node build's test_cricket.js already
 * asserts these rules, so that suite stays a usable reference for this port. If you change a rule
 * here, change it there.
 *
 * =================================================================================================
 * WHY VALIDATION LIVES ON THE SERVER
 * =================================================================================================
 * The team builder screen enforces the same rules as it goes, but that is a convenience, not the
 * guard. Every rule is re-checked here against the squad actually stored for the match, because the
 * builder is client-side code a player can edit: the only numbers that can be trusted are the ones
 * this function reads out of the database itself. Credits in particular are never taken from the
 * request — only player ids are — so a forged credit value cannot buy a team.
 */

require_once __DIR__ . '/fantasy.php';

// -------------------------------------------------------------------------------------------------
// Lock enforcement
// -------------------------------------------------------------------------------------------------

/**
 * Is this match still open for team edits and contest entries?
 *
 * Returns ['ok' => true] or ['ok' => false, 'error' => ...]. Two independent reasons to refuse:
 * the deadline has passed, or an operator has moved the match out of UPCOMING. Both are checked
 * against the stored row and the server clock, never against anything the client sent — a phone
 * with a wrong clock, or a stale page left open through the toss, must not be able to submit.
 */
function fantasy_match_open_for_entry(array $match) {
    $status = strtoupper((string) ($match['status'] ?? ''));
    if ($status !== 'UPCOMING') {
        return ['ok' => false, 'error' => 'This match is no longer open (' . strtolower($status) . ').'];
    }
    $lockMs = sql_to_ms($match['lock_time'] ?? null);
    if ($lockMs === null) {
        return ['ok' => false, 'error' => 'This match has no entry deadline set.'];
    }
    if (now_ms() >= $lockMs) {
        return ['ok' => false, 'error' => 'Entries for this match are closed.'];
    }
    return ['ok' => true];
}

// -------------------------------------------------------------------------------------------------
// Squad lookup
// -------------------------------------------------------------------------------------------------

/**
 * The match's squad as [player_id => row], which is the shape the validator wants.
 *
 * Credits come from here and only from here — see the note at the top about not trusting request
 * values.
 */
function fantasy_squad_map($matchId) {
    $rows = all(
        'SELECT "id","name","team_name","role","credits","is_playing" FROM "fantasy_players" WHERE "match_id" = ?',
        [(int) $matchId]
    );
    $map = [];
    foreach ($rows as $r) {
        $map[(int) $r['id']] = [
            'id'         => (int) $r['id'],
            'name'       => (string) $r['name'],
            'team_name'  => (string) $r['team_name'],
            'role'       => fantasy_normalise_role($r['role']),
            'credits'    => (float) $r['credits'],
            'is_playing' => $r['is_playing'],
        ];
    }
    return $map;
}

// -------------------------------------------------------------------------------------------------
// Lineup validation
// -------------------------------------------------------------------------------------------------

/**
 * Check a submitted XI against every rule.
 *
 * $lineup: ['players' => [int ids], 'captain' => int, 'vice_captain' => int]
 * $ctx:    ['squad' => fantasy_squad_map() output, 'rules' => fantasy_rules(), 'default_credits' => 8.0]
 *
 * Returns ['ok' => false, 'error' => 'human sentence'] on the first rule broken, or
 * ['ok' => true, 'credits_used' => float, 'role_counts' => [...], 'team_counts' => [...]].
 *
 * The errors are written to be shown to the player as-is, and each one names the actual number they
 * have, because "Pick at least 3 BOWL" without "(you have 2)" makes someone hunt for what is wrong.
 */
function fantasy_validate_lineup(array $lineup, array $ctx) {
    $squad  = isset($ctx['squad']) && is_array($ctx['squad']) ? $ctx['squad'] : [];
    $rules  = isset($ctx['rules']) && is_array($ctx['rules']) ? $ctx['rules'] : fantasy_rules();
    $defaultCredits = isset($ctx['default_credits']) ? (float) $ctx['default_credits'] : 8.0;

    $size = (int) ($rules['squad_size'] ?? 11);

    // Normalise to a list of ints. A non-numeric or zero id is dropped here so it cannot be
    // mistaken for a valid player later; the count check below then reports the shortfall.
    $players = [];
    foreach ((array) ($lineup['players'] ?? []) as $p) {
        $id = (int) $p;
        if ($id > 0) $players[] = $id;
    }
    $captain     = (int) ($lineup['captain'] ?? 0);
    $viceCaptain = (int) ($lineup['vice_captain'] ?? 0);

    if (count($players) !== $size) {
        return ['ok' => false,
                'error' => 'Pick exactly ' . $size . ' players (you picked ' . count($players) . ').'];
    }
    if (count(array_unique($players)) !== count($players)) {
        return ['ok' => false, 'error' => 'The same player cannot be picked twice.'];
    }

    $missing = [];
    foreach ($players as $id) {
        if (!isset($squad[$id])) $missing[] = $id;
    }
    if ($missing) {
        return ['ok' => false,
                'error' => 'Some of those players are not in this match\'s squad.'];
    }

    if ($captain <= 0 || !in_array($captain, $players, true)) {
        return ['ok' => false, 'error' => 'The captain must be one of your ' . $size . '.'];
    }
    if ($viceCaptain <= 0 || !in_array($viceCaptain, $players, true)) {
        return ['ok' => false, 'error' => 'The vice-captain must be one of your ' . $size . '.'];
    }
    if ($captain === $viceCaptain) {
        return ['ok' => false, 'error' => 'The captain and vice-captain must be different players.'];
    }

    $roleCounts = [];
    $teamCounts = [];
    $creditsUsed = 0.0;

    foreach ($players as $id) {
        $p = $squad[$id];
        $role = $p['role'];
        $roleCounts[$role] = ($roleCounts[$role] ?? 0) + 1;
        $team = $p['team_name'] !== '' ? $p['team_name'] : '(unknown team)';
        $teamCounts[$team] = ($teamCounts[$team] ?? 0) + 1;
        // Unpriced defaults to the mid-band, never to free — see the header note.
        $price = $p['credits'];
        $creditsUsed += (is_finite($price) && $price > 0) ? $price : $defaultCredits;
    }

    foreach (($rules['role_limits'] ?? []) as $role => $limit) {
        $count = $roleCounts[$role] ?? 0;
        $min = (int) ($limit['min'] ?? 0);
        $max = (int) ($limit['max'] ?? $size);
        if ($count < $min) {
            return ['ok' => false, 'error' => 'Pick at least ' . $min . ' ' . $role . ' (you have ' . $count . ').'];
        }
        if ($count > $max) {
            return ['ok' => false, 'error' => 'Pick at most ' . $max . ' ' . $role . ' (you have ' . $count . ').'];
        }
    }

    $maxPerTeam = (int) ($rules['max_per_real_team'] ?? $size);
    foreach ($teamCounts as $team => $count) {
        if ($count > $maxPerTeam) {
            return ['ok' => false,
                    'error' => 'At most ' . $maxPerTeam . ' players from one team (you have '
                             . $count . ' from ' . $team . ').'];
        }
    }

    $creditsUsed = round($creditsUsed, 2);
    $budget = (float) ($rules['credit_cap'] ?? 100.0);
    // Epsilon: summing one-decimal floats can overshoot by a fraction of a cent.
    if ($creditsUsed > $budget + 0.001) {
        return ['ok' => false,
                'error' => 'Over budget: ' . number_format($creditsUsed, 1) . ' of '
                         . number_format($budget, 0) . ' credits.'];
    }

    return [
        'ok'           => true,
        'credits_used' => $creditsUsed,
        'role_counts'  => $roleCounts,
        'team_counts'  => $teamCounts,
    ];
}

// -------------------------------------------------------------------------------------------------
// Persistence
// -------------------------------------------------------------------------------------------------

/**
 * Create or replace one of a player's teams for a match.
 *
 * $teamId null creates; an id replaces that team, which must belong to $userId. Returns
 * ['ok' => true, 'team_id' => int, 'credits_used' => float, ...] or ['ok' => false, 'error' => ...].
 *
 * Everything happens inside one transaction, and the eleven rows are DELETEd and re-INSERTed rather
 * than diffed: a half-applied edit that left a team with ten players (or twelve) would be a team
 * that could never have been validated, and is exactly the state worth making impossible.
 *
 * The lock is re-checked INSIDE the transaction as well as before it. Between a player pressing save
 * and the write landing, the deadline can pass; checking only up front leaves a window where a team
 * is accepted after entries closed.
 */
function fantasy_save_team($userId, $matchId, array $lineup, $teamId = null) {
    $userId  = (int) $userId;
    $matchId = (int) $matchId;

    $match = fantasy_find_match($matchId);
    if (!$match) return ['ok' => false, 'error' => 'Match not found.', 'status' => 404];

    $open = fantasy_match_open_for_entry($match);
    if (!$open['ok']) return ['ok' => false, 'error' => $open['error'], 'status' => 409];

    $rules = fantasy_rules();
    $squad = fantasy_squad_map($matchId);
    if (!$squad) return ['ok' => false, 'error' => 'This match has no squad yet.', 'status' => 409];

    $check = fantasy_validate_lineup($lineup, ['squad' => $squad, 'rules' => $rules]);
    if (!$check['ok']) return ['ok' => false, 'error' => $check['error'], 'status' => 422];

    $teamName = trim((string) ($lineup['team_name'] ?? ''));
    if ($teamName === '') $teamName = 'My Team';
    if (strlen($teamName) > 40) $teamName = substr($teamName, 0, 40);

    // An edit must target a team that is actually this player's. Checked before the transaction so
    // the error is a clean 404 rather than a rolled-back write.
    if ($teamId !== null) {
        $owned = one('SELECT "id" FROM "fantasy_user_teams" WHERE "id" = ? AND "user_id" = ? AND "match_id" = ?',
                     [(int) $teamId, $userId, $matchId]);
        if (!$owned) return ['ok' => false, 'error' => 'Team not found.', 'status' => 404];
    } else {
        $cap = (int) ($rules['max_teams_per_match'] ?? 11);
        $have = (int) scalar('SELECT COUNT(*) FROM "fantasy_user_teams" WHERE "user_id" = ? AND "match_id" = ?',
                             [$userId, $matchId], 0);
        if ($have >= $cap) {
            return ['ok' => false,
                    'error' => 'You already have ' . $cap . ' teams for this match.', 'status' => 409];
        }
    }

    $players = [];
    foreach ((array) $lineup['players'] as $p) $players[] = (int) $p;
    $captain = (int) $lineup['captain'];
    $vice    = (int) $lineup['vice_captain'];
    $nowSql  = ms_to_sql(now_ms());

    try {
        $savedId = tx(function () use ($userId, $matchId, $teamId, $teamName, $players, $captain,
                                       $vice, $check, $nowSql, $match) {
            // Re-check the deadline with the row locked for the duration of the write.
            $fresh = one('SELECT "status","lock_time" FROM "fantasy_matches" WHERE "id" = ?', [$matchId]);
            if (!$fresh) throw new RuntimeException('Match not found.');
            $reopen = fantasy_match_open_for_entry($fresh);
            if (!$reopen['ok']) throw new RuntimeException($reopen['error']);

            if ($teamId !== null) {
                q('UPDATE "fantasy_user_teams" SET "team_name" = ?, "captain_player_id" = ?, '
                  . '"vice_captain_player_id" = ?, "total_credits" = ?, "updated_at" = ? WHERE "id" = ?',
                  [$teamName, $captain, $vice, $check['credits_used'], $nowSql, (int) $teamId]);
                q('DELETE FROM "fantasy_team_players" WHERE "user_team_id" = ?', [(int) $teamId]);
                $id = (int) $teamId;
            } else {
                q('INSERT INTO "fantasy_user_teams" ("user_id","match_id","team_name","captain_player_id",'
                  . '"vice_captain_player_id","total_credits","created_at","updated_at") VALUES (?,?,?,?,?,?,?,?)',
                  [$userId, $matchId, $teamName, $captain, $vice, $check['credits_used'], $nowSql, $nowSql]);
                $id = (int) db_or_throw()->lastInsertId();
            }

            foreach ($players as $pid) {
                q('INSERT INTO "fantasy_team_players" ("user_team_id","player_id") VALUES (?,?)', [$id, $pid]);
            }
            return $id;
        });
    } catch (Throwable $e) {
        // The messages thrown above are already player-facing sentences.
        return ['ok' => false, 'error' => $e->getMessage(), 'status' => 409];
    }

    return [
        'ok'           => true,
        'team_id'      => (int) $savedId,
        'credits_used' => $check['credits_used'],
        'role_counts'  => $check['role_counts'],
        'team_counts'  => $check['team_counts'],
    ];
}

// -------------------------------------------------------------------------------------------------
// Reads
// -------------------------------------------------------------------------------------------------

/** One team with its eleven players, or null. Scoped to $userId so a team cannot be read by id alone. */
function fantasy_team_with_players($teamId, $userId) {
    $team = one('SELECT * FROM "fantasy_user_teams" WHERE "id" = ? AND "user_id" = ?',
                [(int) $teamId, (int) $userId]);
    if (!$team) return null;

    $rows = all(
        'SELECT p."id", p."name", p."full_name", p."team_name", p."role", p."credits", p."is_playing" '
        . 'FROM "fantasy_team_players" tp JOIN "fantasy_players" p ON p."id" = tp."player_id" '
        . 'WHERE tp."user_team_id" = ? ORDER BY p."credits" DESC, p."name" ASC',
        [(int) $teamId]
    );

    $captain = (int) $team['captain_player_id'];
    $vice    = (int) $team['vice_captain_player_id'];
    $players = [];
    foreach ($rows as $r) {
        $id = (int) $r['id'];
        $players[] = [
            'id'         => $id,
            'name'       => (string) $r['name'],
            'full_name'  => (string) $r['full_name'],
            'team_name'  => (string) $r['team_name'],
            'role'       => fantasy_normalise_role($r['role']),
            'credits'    => (float) $r['credits'],
            'is_playing' => $r['is_playing'] === null ? null : (((int) $r['is_playing']) === 1),
            'is_captain' => $id === $captain,
            'is_vice_captain' => $id === $vice,
        ];
    }

    return [
        'id'                     => (int) $team['id'],
        'match_id'               => (int) $team['match_id'],
        'team_name'              => (string) $team['team_name'],
        'captain_player_id'      => $captain,
        'vice_captain_player_id' => $vice,
        'total_credits'          => (float) $team['total_credits'],
        'total_points'           => (float) $team['total_points'],
        'rank'                   => $team['rank'] === null ? null : (int) $team['rank'],
        'created_at'             => $team['created_at'],
        'updated_at'             => $team['updated_at'],
        'players'                => $players,
    ];
}

/** A player's teams, newest first, optionally for one match only. */
function fantasy_list_user_teams($userId, $matchId = null) {
    if ($matchId === null) {
        $rows = all('SELECT "id" FROM "fantasy_user_teams" WHERE "user_id" = ? ORDER BY "id" DESC LIMIT 200',
                    [(int) $userId]);
    } else {
        $rows = all('SELECT "id" FROM "fantasy_user_teams" WHERE "user_id" = ? AND "match_id" = ? '
                    . 'ORDER BY "id" DESC LIMIT 200', [(int) $userId, (int) $matchId]);
    }
    $out = [];
    foreach ($rows as $r) {
        $team = fantasy_team_with_players((int) $r['id'], $userId);
        if ($team) $out[] = $team;
    }
    return $out;
}
