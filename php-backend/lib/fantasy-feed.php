<?php
/**
 * "Your Eleven" on the shared cricket feed.
 *
 * Replaces scraping as the default source. Fixtures and squads come from Roanuz's free tournament
 * endpoints (or the mock world), and every live figure is DERIVED from the same stored deliveries Ball
 * by Ball settles on — so the two games can never disagree about what happened on a ball.
 *
 *   fantasy_feed_sync_fixtures()   schedule + squads + credits + the standard contest set
 *   fantasy_feed_sync($feedKey)    lineups, live stats, team points, history — after every push
 *
 * The scraper (FANTASY_SOURCE=cricbuzz) remains available as a fallback, but is no longer the default.
 */

require_once __DIR__ . '/fantasy.php';
require_once __DIR__ . '/fantasy-teams.php';
require_once __DIR__ . '/fantasy-contests.php';
require_once __DIR__ . '/fantasy-scoring.php';
require_once __DIR__ . '/fantasy-live.php';
require_once __DIR__ . '/fantasy-settle.php';
require_once __DIR__ . '/fantasy-source.php';
require_once __DIR__ . '/cricket-feed.php';
require_once __DIR__ . '/cricket-roanuz.php';

/** Does Your 11 take its data from the shared feed? (The default; 'cricbuzz' is the old scraper.) */
function fantasy_uses_feed() {
    $m = strtolower(trim((string) env_get('FANTASY_SOURCE', 'feed')));
    return $m !== 'cricbuzz';
}

// -------------------------------------------------------------------------------------------------
// Credits
// -------------------------------------------------------------------------------------------------

/**
 * A player's credit value.
 *
 * No feed sells credits for free (Roanuz charges ₹250/match), so they are priced here, in order of
 * how much evidence there is:
 *   1. the player's average fantasy points over their last ten matches on this platform, when there
 *      are at least three — the same signal the big apps price from: a 60-point regular is ~11, a
 *      20-point squad player ~7.5;
 *   2. a skill rating where the source provides one (the mock does);
 *   3. otherwise a role default, for an operator to adjust per match before the deadline.
 * Rounded to the half credit and kept inside 6.0–10.5, so the 100-credit cap is a real trade-off.
 */
function fantasy_feed_credits($playerKey, $role, $skill = null, $format = 'T20') {
    $hist = all('SELECT "points" FROM "fantasy_player_history" WHERE "player_key" = ? AND "format" = ? ORDER BY "played_at" DESC LIMIT 10',
                [(string) $playerKey, fantasy_format_key($format)]);
    if (count($hist) >= 3) {
        $avg = array_sum(array_map(function ($r) { return (float) $r['points']; }, $hist)) / count($hist);
        $c = 6.0 + $avg / 12;
    } elseif ($skill !== null) {
        // Centred so an average XI costs ~95 of the 100 credits: skill 0.4 -> 7.5, 0.9 -> 10.
        $c = 5.5 + ((float) $skill) * 5.0;
    } else {
        $c = ['WK' => 8.0, 'BAT' => 8.0, 'ALL' => 8.5, 'BOWL' => 8.0][$role] ?? 8.0;
    }
    $c = round($c * 2) / 2;
    return max(6.0, min(10.5, $c));
}

// -------------------------------------------------------------------------------------------------
// Contest templates
// -------------------------------------------------------------------------------------------------

/**
 * The standard contest set every fixture gets, like the lobby of any established fantasy app.
 *
 * Every non-practice contest is FLEXIBLE: if fewer than min_entries join, it is cancelled and every
 * fee refunded (a mega refunds below the deepest paid rank, the small ones unless full). Nothing is
 * guaranteed out of the house's pocket by default; an operator can add guaranteed contests by hand.
 * Stored under the "fantasy_contest_templates" state key so an operator can change the set.
 */
function fantasy_contest_templates() {
    $stored = state_get('fantasy_contest_templates');
    if (is_array($stored) && $stored) return $stored;
    return [
        ['template_key' => 'mega', 'title' => 'Mega Contest', 'contest_type' => 'mega', 'entry_fee' => 29,
         'total_spots' => 200, 'max_entries_per_user' => 6, 'rake_pct' => 15,
         'prize_rules' => [['from' => 1, 'to' => 1, 'pct' => 15], ['from' => 2, 'to' => 2, 'pct' => 10],
                           ['from' => 3, 'to' => 3, 'pct' => 7], ['from' => 4, 'to' => 5, 'pct' => 4],
                           ['from' => 6, 'to' => 10, 'pct' => 2.4], ['from' => 11, 'to' => 50, 'pct' => 1.2]]],
        ['template_key' => 'h2h', 'title' => 'Head to Head', 'contest_type' => 'h2h', 'entry_fee' => 50,
         'total_spots' => 2, 'max_entries_per_user' => 1, 'rake_pct' => 15, 'min_entries' => 2,
         'prize_rules' => [['from' => 1, 'to' => 1, 'pct' => 100]]],
        ['template_key' => 'small', 'title' => 'Small League', 'contest_type' => 'small', 'entry_fee' => 25,
         'total_spots' => 10, 'max_entries_per_user' => 2, 'rake_pct' => 15, 'min_entries' => 6,
         'prize_rules' => [['from' => 1, 'to' => 1, 'pct' => 50], ['from' => 2, 'to' => 2, 'pct' => 30], ['from' => 3, 'to' => 3, 'pct' => 20]]],
        ['template_key' => 'wta', 'title' => 'Winner Takes All', 'contest_type' => 'winner_takes_all', 'entry_fee' => 100,
         'total_spots' => 5, 'max_entries_per_user' => 1, 'rake_pct' => 15, 'min_entries' => 5,
         'prize_rules' => [['from' => 1, 'to' => 1, 'pct' => 100]]],
        ['template_key' => 'practice', 'title' => 'Practice Contest', 'contest_type' => 'practice', 'entry_fee' => 0,
         'total_spots' => 10000, 'max_entries_per_user' => 6, 'rake_pct' => 0, 'prize_rules' => []],
    ];
}

/** Create any template contest a match does not have yet. Idempotent by (match_id, template_key). */
function fantasy_ensure_template_contests($matchId) {
    if (!env_bool('FANTASY_AUTO_CONTESTS', true)) return 0;
    $have = [];
    foreach (all('SELECT "template_key" FROM "fantasy_contests" WHERE "match_id" = ? AND "template_key" IS NOT NULL', [(int) $matchId]) as $r) {
        $have[(string) $r['template_key']] = true;
    }
    $made = 0;
    foreach (fantasy_contest_templates() as $t) {
        if (isset($have[$t['template_key']])) continue;
        try {
            $r = fantasy_create_contest((int) $matchId, $t);
            if ($r['ok']) $made++;
            else log_warn('fantasy: template contest rejected', ['match' => $matchId, 'template' => $t['template_key'], 'error' => $r['error']]);
        } catch (Throwable $e) {
            // A unique violation means a concurrent run created it first, which is fine.
            if (!fantasy_is_unique_violation($e)) throw $e;
        }
    }
    return $made;
}

// -------------------------------------------------------------------------------------------------
// Fixtures and squads
// -------------------------------------------------------------------------------------------------

/**
 * Pull the schedule, upsert fixtures, fetch squads for any fixture not yet playable, price credits,
 * subscribe the match to push delivery, and lay out the contest set. Safe to run on any schedule.
 */
function fantasy_feed_sync_fixtures() {
    $summary = ['fixtures' => 0, 'upserted' => 0, 'squads' => 0, 'ready' => 0, 'contests' => 0, 'subscribed' => 0, 'errors' => []];
    $fx = roanuz_fixtures();
    if (!$fx['ok']) { $summary['errors'][] = (string) $fx['error']; return $summary; }
    $now = now_ms();

    foreach ($fx['fixtures'] as $f) {
        $summary['fixtures']++;
        if ($f['key'] === '' || $f['start_ms'] === null) continue;
        // Only matches still to come, or in play. Finished ones are not new business.
        if (in_array(strtolower($f['status']), ['completed', 'complete', 'finished'], true) && $f['start_ms'] < $now) continue;
        if ($f['start_ms'] > $now + 7 * 86400000) continue;

        try {
            $r = fantasy_feed_upsert_fixture($f);
            $summary['upserted']++;
            $summary['subscribed'] += $r['subscribed'];
            $summary['squads'] += $r['squads'];
            $summary['ready'] += $r['ready'];
            $summary['contests'] += $r['contests'];
            foreach ($r['errors'] as $e) $summary['errors'][] = $e;
        } catch (Throwable $e) {
            $summary['errors'][] = $f['key'] . ': ' . $e->getMessage();
        }
    }
    return $summary;
}


/**
 * Upsert one normalised fixture: the match row, its push subscription, both squads with credits,
 * and — once a legal XI can be built — the standard contest set. Returns counts for the caller's log.
 */
function fantasy_feed_upsert_fixture(array $f) {
    $out = ['match_id' => 0, 'subscribed' => 0, 'squads' => 0, 'ready' => 0, 'contests' => 0, 'errors' => []];
    $title = $f['teams']['a']['name'] . ' vs ' . $f['teams']['b']['name'];
    if ($f['name'] !== '' && strpos($f['name'], ' vs ') !== false) $title = $f['name'];
    $matchId = fantasy_upsert_match([
        'external_key'  => 'feed:' . $f['key'],
        'series_name'   => $f['tournament_name'],
        'match_title'   => $title,
        'team_a'        => $f['teams']['a']['name'], 'team_a_short' => $f['teams']['a']['code'],
        'team_b'        => $f['teams']['b']['name'], 'team_b_short' => $f['teams']['b']['code'],
        'team_a_logo'   => $f['teams']['a']['logo'] ?? null, 'team_b_logo' => $f['teams']['b']['logo'] ?? null,
        'format'        => $f['format'],
        'venue'         => $f['venue'],
        'start_time_ms' => $f['start_ms'],
        'lock_time_ms'  => $f['start_ms'],
        'scorecard_source_url' => null,
    ]);
    $out['match_id'] = $matchId;
    q('UPDATE "fantasy_matches" SET "feed_key" = ? WHERE "id" = ? AND ("feed_key" IS NULL OR "feed_key" <> ?)',
      [$f['key'], $matchId, $f['key']]);

    $sub = roanuz_subscribe($f['key']);
    if ($sub['ok'] && empty($sub['already'])) $out['subscribed']++;
    cricket_feed_placeholder($f);

    $row = fantasy_find_match($matchId);
    if ((int) $row['squads_ready'] !== 1) {
        foreach (['a', 'b'] as $side) {
            $team = $f['teams'][$side];
            $sq = roanuz_team_squad($f['tournament_key'], $team['key']);
            $out['squads']++;
            if (!$sq['ok']) { $out['errors'][] = $f['key'] . ' squad ' . $team['key'] . ': ' . $sq['error']; continue; }
            foreach ($sq['players'] as $p) {
                fantasy_upsert_player($matchId, [
                    'external_key' => $p['key'],
                    'name'         => $p['name'],
                    'full_name'    => $p['name'],
                    'team_name'    => $team['name'],
                    'role'         => $p['role'],
                    'credits'      => fantasy_feed_credits($p['key'], $p['role'], $p['skill'], $f['format']),
                ]);
            }
        }
        if (fantasy_squad_is_usable($matchId)) {
            fantasy_mark_squads_ready($matchId, true);
            $out['ready']++;
        }
    }
    $row = fantasy_find_match($matchId);
    if ((int) $row['squads_ready'] === 1 && strtoupper((string) $row['status']) === 'UPCOMING') {
        $out['contests'] += fantasy_ensure_template_contests($matchId);
    }
    return $out;
}

// -------------------------------------------------------------------------------------------------
// Live
// -------------------------------------------------------------------------------------------------

/**
 * Bring one fixture up to date with its feed: announced lineups, match state, every player's figures,
 * every team's and entry's points, and — once the match is over — the history credits are priced from.
 *
 * Throttled to one full recompute every FANTASY_RECOMPUTE_SECONDS while play is on, because a push can
 * arrive every few seconds and re-scoring thousands of teams on each one is wasted work; lineups,
 * the end of the match and $force always run immediately.
 */
function fantasy_feed_sync($feedKey, $force = false) {
    $match = one('SELECT * FROM "fantasy_matches" WHERE "feed_key" = ?', [(string) $feedKey]);
    if (!$match) return ['ok' => true, 'skipped' => 'no fantasy fixture for this match'];
    $matchId = (int) $match['id'];
    $feed = cricket_match_feed_row($feedKey);
    if (!$feed) return ['ok' => false, 'error' => 'no feed row'];

    $now = now_ms();
    $final = in_array($feed['status'], ['completed', 'abandoned'], true);
    $lineupsNew = !empty($feed['lineups']) && (int) $match['lineups_announced'] !== 1;
    $throttleKey = 'fantasy_feed_sync_' . $matchId;
    if (!$force && !$final && !$lineupsNew) {
        $last = state_get($throttleKey);
        $gap = max(5, (int) env_get('FANTASY_RECOMPUTE_SECONDS', 15)) * 1000;
        if (is_array($last) && $now - (int) ($last['ms'] ?? 0) < $gap) return ['ok' => true, 'skipped' => 'throttled'];
    }
    state_set($throttleKey, ['ms' => $now]);

    $players = all('SELECT "id","external_key","role","is_playing" FROM "fantasy_players" WHERE "match_id" = ?', [$matchId]);
    $idByKey = [];
    foreach ($players as $p) $idByKey[(string) $p['external_key']] = (int) $p['id'];

    // --- announced lineups: who is playing, who is not ---
    if (!empty($feed['lineups'])) {
        $xi = [];
        foreach ($feed['lineups'] as $side => $keys) foreach ((array) $keys as $k) $xi[(string) $k] = true;
        foreach ($players as $p) {
            $playing = isset($xi[(string) $p['external_key']]) ? 1 : 0;
            if ($p['is_playing'] === null || (int) $p['is_playing'] !== $playing) {
                q('UPDATE "fantasy_players" SET "is_playing" = ? WHERE "id" = ?', [$playing, (int) $p['id']]);
            }
        }
        $tossText = cricket_match_summary($feed)['toss_text'];
        q('UPDATE "fantasy_matches" SET "lineups_announced" = 1, "toss_text" = ?, "updated_at" = ? WHERE "id" = ?',
          [$tossText, ms_to_sql($now), $matchId]);
    }

    // --- match state ---
    if (strtoupper((string) $match['status']) === 'UPCOMING' && $now >= (int) sql_to_ms($match['lock_time'])) {
        q('UPDATE "fantasy_matches" SET "status" = ?, "updated_at" = ? WHERE "id" = ? AND "status" = ?',
          ['LIVE', ms_to_sql($now), $matchId, 'UPCOMING']);
    }
    q('UPDATE "fantasy_matches" SET "source_state" = ?, "source_status_text" = ?, "last_synced_at" = ? WHERE "id" = ?',
      [$feed['status'], $feed['result_text'] ?: $feed['status_text'], ms_to_sql($now), $matchId]);

    // --- figures and points (only once there is anything to score) ---
    $deliveries = cricket_match_deliveries($feedKey);
    $stored = 0; $unmatched = [];
    if ($deliveries || !empty($feed['lineups'])) {
        $derived = cricket_derive_player_stats($deliveries, $feed['lineups']);
        $roleById = [];
        foreach ($players as $p) $roleById[(int) $p['id']] = $p['role'];
        foreach ($derived as $key => $s) {
            if (!isset($idByKey[$key])) { $unmatched[] = $key; continue; }
            $pid = $idByKey[$key];
            $s['role'] = $roleById[$pid] ?? 'BAT';
            fantasy_store_player_stats($matchId, $pid, $s);
            $stored++;
        }
        fantasy_recompute_team_points($matchId);
    }

    // --- the record credits are priced from ---
    if ($feed['status'] === 'completed') {
        $fmt = fantasy_format_key($match['format']);
        foreach (all('SELECT p."external_key", s."calculated_fantasy_points", s."in_lineup", s."is_substitute" '
                   . 'FROM "fantasy_player_live_stats" s JOIN "fantasy_players" p ON p."id" = s."player_id" WHERE s."match_id" = ?', [$matchId]) as $r) {
            if ((int) $r['in_lineup'] !== 1 && (int) $r['is_substitute'] !== 1) continue;
            q('INSERT INTO "fantasy_player_history" ("player_key","match_id","format","points","played_at") VALUES (?,?,?,?,?) '
              . 'ON CONFLICT ("player_key","match_id") DO UPDATE SET "points" = EXCLUDED."points"',
              [(string) $r['external_key'], $matchId, $fmt, (float) $r['calculated_fantasy_points'], ms_to_sql($now)]);
        }
    }

    return ['ok' => true, 'match_id' => $matchId, 'players_stored' => $stored, 'unmatched' => $unmatched, 'status' => $feed['status']];
}

/**
 * Selection statistics for a fixture's squad: what share of all teams picked each player, and made
 * them captain or vice-captain — the "Sel by", "C by" and "VC by" figures on every app's team builder.
 */
function fantasy_selection_stats($matchId) {
    $teams = (int) scalar('SELECT COUNT(*) FROM "fantasy_user_teams" WHERE "match_id" = ?', [(int) $matchId], 0);
    $out = ['teams' => $teams, 'players' => []];
    if ($teams === 0) return $out;
    foreach (all('SELECT tp."player_id", COUNT(*) AS "n" FROM "fantasy_team_players" tp JOIN "fantasy_user_teams" t ON t."id" = tp."user_team_id" '
               . 'WHERE t."match_id" = ? GROUP BY tp."player_id"', [(int) $matchId]) as $r) {
        $out['players'][(int) $r['player_id']]['sel'] = round(((int) $r['n']) * 100 / $teams, 1);
    }
    foreach (all('SELECT "captain_player_id" AS "p", COUNT(*) AS "n" FROM "fantasy_user_teams" WHERE "match_id" = ? GROUP BY "captain_player_id"', [(int) $matchId]) as $r) {
        $out['players'][(int) $r['p']]['c'] = round(((int) $r['n']) * 100 / $teams, 1);
    }
    foreach (all('SELECT "vice_captain_player_id" AS "p", COUNT(*) AS "n" FROM "fantasy_user_teams" WHERE "match_id" = ? GROUP BY "vice_captain_player_id"', [(int) $matchId]) as $r) {
        $out['players'][(int) $r['p']]['vc'] = round(((int) $r['n']) * 100 / $teams, 1);
    }
    return $out;
}

/**
 * Any entry's team, for the leaderboard's "view team" — but only after the deadline. Before it, a
 * rival's XI is private on every established app, and showing it would let late joiners copy it.
 */
function fantasy_entry_team_public($entryId) {
    $e = one('SELECT e."id", e."user_team_id", e."points", e."rank", u."username", c."match_id", t."team_name", '
           . 't."captain_player_id", t."vice_captain_player_id" FROM "fantasy_contest_entries" e '
           . 'JOIN "fantasy_contests" c ON c."id" = e."contest_id" JOIN "User" u ON u."id" = e."user_id" '
           . 'JOIN "fantasy_user_teams" t ON t."id" = e."user_team_id" WHERE e."id" = ?', [(int) $entryId]);
    if (!$e) return ['ok' => false, 'status' => 404, 'error' => 'Entry not found.'];
    $match = fantasy_find_match((int) $e['match_id']);
    if ($match && now_ms() < (int) sql_to_ms($match['lock_time'])) {
        return ['ok' => false, 'status' => 403, 'error' => 'Other teams are revealed when the match starts.'];
    }
    $rows = all('SELECT p."id", p."name", p."role", p."team_name" FROM "fantasy_team_players" tp JOIN "fantasy_players" p ON p."id" = tp."player_id" '
              . 'WHERE tp."user_team_id" = ?', [(int) $e['user_team_id']]);
    $players = array_map(function ($r) use ($e) {
        return ['id' => (int) $r['id'], 'name' => $r['name'], 'role' => $r['role'], 'team_name' => $r['team_name'],
                'is_captain' => (int) $r['id'] === (int) $e['captain_player_id'],
                'is_vice_captain' => (int) $r['id'] === (int) $e['vice_captain_player_id']];
    }, $rows);
    $scored = fantasy_score_team($players, fantasy_stored_stats((int) $e['match_id']), fantasy_rules_for_match((int) $e['match_id']));
    return ['ok' => true, 'entry_id' => (int) $e['id'], 'username' => $e['username'], 'team_name' => $e['team_name'],
            'points' => (float) $e['points'], 'rank' => $e['rank'] === null ? null : (int) $e['rank'],
            'total' => $scored['total'], 'players' => $scored['players']];
}
