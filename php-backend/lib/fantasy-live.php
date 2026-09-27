<?php
/**
 * "Your Eleven" — live stats: mapping scraped figures onto our players, storing them, and
 * recomputing every team's points.
 *
 * =================================================================================================
 * MATCHING: ID FIRST, NAME ONLY AS A LAST RESORT, NEVER A GUESS
 * =================================================================================================
 * The scorecard and the squad page come from the same source and share an identifier space, so
 * almost every row maps by exact key ("cb:8271"). Fuzzy name matching exists for the row whose id we
 * never ingested — a late replacement, or a squad page that was stale when we read it — and it is
 * deliberately conservative:
 *
 *   1. exact match on a normalised name
 *   2. initial-plus-surname ("v kohli" for "Virat Kohli"), which is the common abbreviation
 *   3. Levenshtein distance, but only inside a similarity threshold
 *
 * and at every stage, if two candidates are equally good the row is NOT matched. It is reported as
 * unmatched instead. Two players called "M Shahzad" in one squad is not a solvable problem, and
 * picking one would mispay real money — so the answer is to surface it for an operator, who has the
 * stat-override route for exactly this.
 *
 * =================================================================================================
 * RECOMPUTE, NEVER INCREMENT
 * =================================================================================================
 * Stored figures are the match totals the source reports, and each poll REPLACES a player's row.
 * Points are then recomputed from those totals rather than being added to. A missed poll, a repeated
 * poll, an out-of-order poll or a corrected scorecard therefore all converge on the right answer —
 * the same guarantee the Node build gets from replaying its event log.
 */

require_once __DIR__ . '/fantasy.php';
require_once __DIR__ . '/fantasy-scoring.php';

// -------------------------------------------------------------------------------------------------
// Name matching
// -------------------------------------------------------------------------------------------------

/**
 * Reduce a name to something comparable: lowercase, no punctuation, no accents, single spaces.
 *
 * Accents are folded because a source may write "Müller" where the squad says "Muller"; without it
 * those two never match and a genuine player is reported unmatched every poll.
 */
function fantasy_norm_name($name) {
    $s = (string) $name;
    $s = strtr($s, [
        'á'=>'a','à'=>'a','ä'=>'a','â'=>'a','ã'=>'a','å'=>'a',
        'é'=>'e','è'=>'e','ë'=>'e','ê'=>'e',
        'í'=>'i','ì'=>'i','ï'=>'i','î'=>'i',
        'ó'=>'o','ò'=>'o','ö'=>'o','ô'=>'o','õ'=>'o',
        'ú'=>'u','ù'=>'u','ü'=>'u','û'=>'u',
        'ñ'=>'n','ç'=>'c',
    ]);
    $s = strtolower($s);
    $s = preg_replace('/[^a-z0-9 ]+/', ' ', $s);
    $s = preg_replace('/\s+/', ' ', $s);
    return trim($s);
}

/** "virat kohli" -> "v kohli". Empty when there is only one word. */
function fantasy_initial_surname($normalised) {
    $parts = explode(' ', $normalised);
    if (count($parts) < 2) return '';
    return substr($parts[0], 0, 1) . ' ' . $parts[count($parts) - 1];
}

/**
 * Find the one player a scraped name refers to.
 *
 * $candidates: [player_id => name]. Returns
 *   ['id' => int, 'method' => 'exact'|'initial'|'fuzzy', 'score' => float]
 * or
 *   ['id' => null, 'method' => 'none'|'ambiguous', 'score' => float]
 *
 * `ambiguous` is a deliberate outcome, not a failure to try harder: it means two candidates were
 * equally close, and on a path that ends in a payout the right answer is to tell an operator rather
 * than pick one.
 */
function fantasy_fuzzy_match_player($name, array $candidates, $threshold = 0.82) {
    $target = fantasy_norm_name($name);
    if ($target === '' || !$candidates) return ['id' => null, 'method' => 'none', 'score' => 0.0];

    $norm = [];
    foreach ($candidates as $id => $cand) $norm[(int) $id] = fantasy_norm_name($cand);

    // 1. exact
    $hits = [];
    foreach ($norm as $id => $c) if ($c === $target) $hits[] = $id;
    if (count($hits) === 1) return ['id' => $hits[0], 'method' => 'exact', 'score' => 1.0];
    if (count($hits) > 1)  return ['id' => null, 'method' => 'ambiguous', 'score' => 1.0];

    // 2. initial + surname, in either direction: the scraped name may be the abbreviated one, or
    //    the squad may be the one holding "V Kohli".
    $targetInitial = fantasy_initial_surname($target);
    $hits = [];
    foreach ($norm as $id => $c) {
        $candInitial = fantasy_initial_surname($c);
        if (($targetInitial !== '' && $targetInitial === $c)
            || ($candInitial !== '' && $candInitial === $target)
            || ($targetInitial !== '' && $candInitial !== '' && $targetInitial === $candInitial)) {
            $hits[] = $id;
        }
    }
    if (count($hits) === 1) return ['id' => $hits[0], 'method' => 'initial', 'score' => 0.95];
    if (count($hits) > 1)  return ['id' => null, 'method' => 'ambiguous', 'score' => 0.95];

    // 3. Levenshtein, normalised to a 0-1 similarity so one threshold works for short and long names.
    $best = -1.0;
    $bestIds = [];
    foreach ($norm as $id => $c) {
        if ($c === '') continue;
        $max = max(strlen($c), strlen($target));
        if ($max === 0) continue;
        // levenshtein() is byte-based and caps at 255 bytes per argument; names are far shorter, but
        // truncate defensively so an absurd input cannot make it return -1 and look like a match.
        $sim = 1.0 - (levenshtein(substr($target, 0, 250), substr($c, 0, 250)) / $max);
        if ($sim > $best + 0.0001) { $best = $sim; $bestIds = [$id]; }
        elseif (abs($sim - $best) <= 0.0001) { $bestIds[] = $id; }
    }
    if ($best < $threshold) return ['id' => null, 'method' => 'none', 'score' => max(0.0, $best)];
    if (count($bestIds) > 1) return ['id' => null, 'method' => 'ambiguous', 'score' => $best];
    return ['id' => $bestIds[0], 'method' => 'fuzzy', 'score' => round($best, 3)];
}

// -------------------------------------------------------------------------------------------------
// Mapping a scraped scorecard onto our player rows
// -------------------------------------------------------------------------------------------------

/**
 * Turn [external_key => stats] from the source into [player_id => stats].
 *
 * Returns ['mapped' => [...], 'unmatched' => [ ['name'=>..,'key'=>..,'reason'=>..], ... ]].
 *
 * Unmatched rows are returned rather than swallowed: they are the signal that the squad we ingested
 * and the squad that actually played have diverged, which is precisely when an operator needs to
 * know before settlement.
 */
function fantasy_map_stats($matchId, array $sourcePlayers) {
    $rows = all('SELECT "id","external_key","name","full_name","role" FROM "fantasy_players" WHERE "match_id" = ?',
                [(int) $matchId]);

    $byKey = [];
    $candidates = [];
    $roleById = [];
    foreach ($rows as $r) {
        $byKey[(string) $r['external_key']] = (int) $r['id'];
        // Full name first: it is the longer, less abbreviated form, so it gives the comparison more
        // to work with. The short name is added too so either spelling can match.
        $candidates[(int) $r['id']] = ((string) $r['full_name']) !== '' ? $r['full_name'] : $r['name'];
        $roleById[(int) $r['id']] = fantasy_normalise_role($r['role']);
    }
    $altCandidates = [];
    foreach ($rows as $r) $altCandidates[(int) $r['id']] = (string) $r['name'];

    $mapped = [];
    $unmatched = [];

    foreach ($sourcePlayers as $key => $stats) {
        $id = null;

        // 1. The normal path: the same identifier space, so an exact key hit.
        if (isset($byKey[(string) $key])) {
            $id = $byKey[(string) $key];
        } else {
            // 2. Fallback. Try the full names, then the short names.
            $m = fantasy_fuzzy_match_player($stats['name'] ?? '', $candidates);
            if ($m['id'] === null && $m['method'] !== 'ambiguous') {
                $m = fantasy_fuzzy_match_player($stats['name'] ?? '', $altCandidates);
            }
            if ($m['id'] !== null) {
                $id = $m['id'];
            } else {
                $unmatched[] = [
                    'key'    => (string) $key,
                    'name'   => (string) ($stats['name'] ?? ''),
                    'reason' => $m['method'] === 'ambiguous'
                        ? 'more than one squad player matches this name equally well'
                        : 'no squad player matches this name',
                    'score'  => $m['score'],
                ];
                continue;
            }
        }

        // Two source rows must never both land on one player: that would double a batting innings.
        if (isset($mapped[$id])) {
            $unmatched[] = [
                'key' => (string) $key, 'name' => (string) ($stats['name'] ?? ''),
                'reason' => 'a different source row already mapped to this player', 'score' => 0.0,
            ];
            continue;
        }

        $stats['role'] = $roleById[$id] ?? 'BAT';
        $mapped[$id] = $stats;
    }

    return ['mapped' => $mapped, 'unmatched' => $unmatched];
}

// -------------------------------------------------------------------------------------------------
// Persistence
// -------------------------------------------------------------------------------------------------

/** The columns of "fantasy_player_live_stats" that a source or an operator may set. */
function fantasy_live_stat_fields() {
    return ['runs', 'balls', 'fours', 'sixes', 'wickets', 'overs', 'maidens', 'runs_conceded',
            'bowled_lbw', 'catches', 'stumpings', 'runouts_direct', 'runouts_shared',
            'is_out', 'did_bat'];
}

/**
 * Write one player's figures, replacing whatever was there, with their points recomputed.
 *
 * Replace rather than merge: the source reports match totals, so the newest read is the whole truth
 * for that player. Merging would let a transient parse gap leave a stale higher figure in place.
 */
function fantasy_store_player_stats($matchId, $playerId, array $stats) {
    $fields = fantasy_live_stat_fields();
    $vals = [];
    foreach ($fields as $f) {
        $v = isset($stats[$f]) ? $stats[$f] : 0;
        $vals[$f] = ($f === 'overs') ? round((float) $v, 1) : (int) $v;
    }

    $scored = fantasy_score_player($stats, fantasy_scoring_rules());
    $points = $scored['points'];

    $existing = one('SELECT "id" FROM "fantasy_player_live_stats" WHERE "match_id" = ? AND "player_id" = ?',
                    [(int) $matchId, (int) $playerId]);

    if ($existing) {
        $sets = [];
        $args = [];
        foreach ($vals as $col => $v) { $sets[] = '"' . $col . '" = ?'; $args[] = $v; }
        $sets[] = '"calculated_fantasy_points" = ?'; $args[] = $points;
        $sets[] = '"updated_at" = ?';                $args[] = ms_to_sql(now_ms());
        $args[] = (int) $existing['id'];
        q('UPDATE "fantasy_player_live_stats" SET ' . implode(', ', $sets) . ' WHERE "id" = ?', $args);
    } else {
        $cols = array_keys($vals);
        $args = array_values($vals);
        $cols[] = 'match_id';                  $args[] = (int) $matchId;
        $cols[] = 'player_id';                 $args[] = (int) $playerId;
        $cols[] = 'calculated_fantasy_points'; $args[] = $points;
        $cols[] = 'updated_at';                $args[] = ms_to_sql(now_ms());
        q('INSERT INTO "fantasy_player_live_stats" ("' . implode('","', $cols) . '") VALUES ('
          . implode(',', array_fill(0, count($cols), '?')) . ')', $args);
    }

    return $points;
}

/** [player_id => stats + points] for a match, as stored. */
function fantasy_stored_stats($matchId) {
    $rows = all('SELECT * FROM "fantasy_player_live_stats" WHERE "match_id" = ?', [(int) $matchId]);
    $out = [];
    foreach ($rows as $r) {
        $s = [];
        foreach (fantasy_live_stat_fields() as $f) {
            $s[$f] = ($f === 'overs') ? (float) $r[$f] : (int) $r[$f];
        }
        $s['points'] = (float) $r['calculated_fantasy_points'];
        $out[(int) $r['player_id']] = $s;
    }
    return $out;
}

/**
 * Recompute total_points for every saved team on a match, and for every contest entry.
 *
 * Bulk-reads the squads and the stats once and does the arithmetic in PHP, so the cost is one query
 * for the teams' players plus one UPDATE per team rather than a query per team. On a fixture with
 * thousands of teams this is still the expensive part of a poll, which is why the live worker runs on
 * its own schedule rather than inside a web request.
 */
function fantasy_recompute_team_points($matchId) {
    $matchId = (int) $matchId;
    $rules = fantasy_scoring_rules();
    $stats = fantasy_stored_stats($matchId);

    $rows = all(
        'SELECT t."id" AS "team_id", t."captain_player_id", t."vice_captain_player_id", '
        . 'tp."player_id", p."role", p."name" '
        . 'FROM "fantasy_user_teams" t '
        . 'JOIN "fantasy_team_players" tp ON tp."user_team_id" = t."id" '
        . 'LEFT JOIN "fantasy_players" p ON p."id" = tp."player_id" '
        . 'WHERE t."match_id" = ?',
        [$matchId]
    );

    $teams = [];
    foreach ($rows as $r) {
        $tid = (int) $r['team_id'];
        if (!isset($teams[$tid])) $teams[$tid] = [];
        $pid = (int) $r['player_id'];
        $teams[$tid][] = [
            'id' => $pid,
            'name' => (string) ($r['name'] ?? ''),
            'role' => (string) ($r['role'] ?? 'BAT'),
            'is_captain' => $pid === (int) $r['captain_player_id'],
            'is_vice_captain' => $pid === (int) $r['vice_captain_player_id'],
        ];
    }

    $totals = [];
    foreach ($teams as $tid => $players) {
        $scored = fantasy_score_team($players, $stats, $rules);
        $totals[$tid] = $scored['total'];
        q('UPDATE "fantasy_user_teams" SET "total_points" = ?, "updated_at" = ? WHERE "id" = ?',
          [$scored['total'], ms_to_sql(now_ms()), $tid]);
    }

    // Entries carry their own copy of the points so a leaderboard is one indexed read rather than a
    // join plus a recompute on every request.
    $entries = all(
        'SELECT e."id", e."user_team_id" FROM "fantasy_contest_entries" e '
        . 'JOIN "fantasy_contests" c ON c."id" = e."contest_id" WHERE c."match_id" = ?',
        [$matchId]
    );
    foreach ($entries as $e) {
        $tid = (int) $e['user_team_id'];
        if (!isset($totals[$tid])) continue;
        q('UPDATE "fantasy_contest_entries" SET "points" = ? WHERE "id" = ?', [$totals[$tid], (int) $e['id']]);
    }

    return ['teams' => count($teams), 'entries' => count($entries)];
}

/**
 * Ingest one poll: map, store, recompute.
 *
 * Returns a summary including any unmatched source rows, which the worker logs and an operator can
 * act on with the override route.
 */
function fantasy_ingest_scorecard($matchId, array $sourcePlayers) {
    $map = fantasy_map_stats($matchId, $sourcePlayers);

    $stored = 0;
    foreach ($map['mapped'] as $playerId => $stats) {
        fantasy_store_player_stats($matchId, $playerId, $stats);
        $stored++;
    }

    $recomputed = fantasy_recompute_team_points($matchId);

    q('UPDATE "fantasy_matches" SET "last_synced_at" = ?, "updated_at" = ? WHERE "id" = ?',
      [ms_to_sql(now_ms()), ms_to_sql(now_ms()), (int) $matchId]);

    return [
        'players_stored' => $stored,
        'unmatched'      => $map['unmatched'],
        'teams_scored'   => $recomputed['teams'],
        'entries_scored' => $recomputed['entries'],
    ];
}

// -------------------------------------------------------------------------------------------------
// Operator override
// -------------------------------------------------------------------------------------------------

/**
 * Set one player's figures by hand and re-score the match.
 *
 * This is the escape hatch for the scraper being wrong: a source that changes shape mid-match, a name
 * that could not be mapped, or a scorecard correction the source has not picked up. Only the known
 * stat columns are accepted, and points are recomputed by the same engine rather than being settable
 * directly — an operator can correct the FACTS, but not hand out points, which keeps the scoring rules
 * the single authority on what a performance is worth.
 */
function fantasy_override_player_stats($matchId, $playerId, array $stats) {
    $matchId = (int) $matchId;
    $playerId = (int) $playerId;

    $match = fantasy_find_match($matchId);
    if (!$match) return ['ok' => false, 'error' => 'Match not found.', 'status' => 404];

    $player = one('SELECT "id","role","name" FROM "fantasy_players" WHERE "id" = ? AND "match_id" = ?',
                  [$playerId, $matchId]);
    if (!$player) return ['ok' => false, 'error' => 'That player is not in this match\'s squad.', 'status' => 404];

    // Start from what is stored, so an operator can correct one figure without having to restate all
    // fifteen — and a typo in one field cannot silently zero the rest.
    $current = fantasy_stored_stats($matchId);
    $base = isset($current[$playerId]) ? $current[$playerId] : [];

    $clean = [];
    foreach (fantasy_live_stat_fields() as $f) {
        if (array_key_exists($f, $stats)) {
            if (!is_numeric($stats[$f])) {
                return ['ok' => false, 'error' => "Field '$f' must be a number.", 'status' => 422];
            }
            $v = ($f === 'overs') ? round((float) $stats[$f], 1) : (int) $stats[$f];
            if ($v < 0) return ['ok' => false, 'error' => "Field '$f' cannot be negative.", 'status' => 422];
            $clean[$f] = $v;
        } else {
            $clean[$f] = isset($base[$f]) ? $base[$f] : 0;
        }
    }
    $clean['role'] = fantasy_normalise_role($player['role']);

    $points = fantasy_store_player_stats($matchId, $playerId, $clean);
    $recomputed = fantasy_recompute_team_points($matchId);

    return [
        'ok' => true,
        'player'       => ['id' => $playerId, 'name' => (string) $player['name']],
        'stats'        => $clean,
        'points'       => $points,
        'teams_scored' => $recomputed['teams'],
    ];
}

// -------------------------------------------------------------------------------------------------
// Read for the UI
// -------------------------------------------------------------------------------------------------

/** Per-player points for a match, highest first, for the Points tab. */
function fantasy_live_scoreboard($matchId) {
    $rows = all(
        'SELECT p."id", p."name", p."team_name", p."role", s.* '
        . 'FROM "fantasy_players" p '
        . 'JOIN "fantasy_player_live_stats" s ON s."player_id" = p."id" '
        . 'WHERE p."match_id" = ? ORDER BY s."calculated_fantasy_points" DESC, p."name" ASC',
        [(int) $matchId]
    );
    $out = [];
    foreach ($rows as $r) {
        $stats = [];
        foreach (fantasy_live_stat_fields() as $f) {
            $stats[$f] = ($f === 'overs') ? (float) $r[$f] : (int) $r[$f];
        }
        $out[] = [
            'id'        => (int) $r['id'],
            'name'      => (string) $r['name'],
            'team_name' => (string) $r['team_name'],
            'role'      => fantasy_normalise_role($r['role']),
            'points'    => (float) $r['calculated_fantasy_points'],
            'stats'     => $stats,
            'updated_at' => $r['updated_at'],
        ];
    }
    return $out;
}
