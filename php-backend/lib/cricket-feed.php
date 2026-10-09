<?php
/**
 * The shared cricket feed — one pipeline that both Your 11 and Ball by Ball read from.
 *
 * =================================================================================================
 * THE SHAPE OF IT
 * =================================================================================================
 *
 *     Roanuz push (or the mock)                 cricket_feed_ingest()
 *         full match snapshot   ──────────────►  1. archive the raw body       (cricket_feed_raw)
 *                                                2. record match-level facts   (cricket_match_feed)
 *                                                3. upsert every delivery      (cricket_deliveries)
 *                                                4. tell both games            (cricket_after_ingest)
 *
 * Both games then DERIVE what they need from the ordered deliveries — the scoreboard, every
 * player's fantasy figures, each Ball by Ball result — recomputing from scratch every time instead
 * of incrementing. That is the property that makes the feed safe to receive from the real world:
 * Roanuz resends the whole match on every update, deliveries arrive late or twice, a scorer
 * corrects a ball a minute after the fact. A recompute over the stored deliveries converges on the
 * right answer in every one of those cases, where an increment would double-count or drift.
 *
 * =================================================================================================
 * THE ROANUZ VOCABULARY, AND WHAT IS STILL UNVERIFIED
 * =================================================================================================
 * Every field name Roanuz uses is read through cricket_pick() with a list of aliases, in this file
 * only. The aliases follow Roanuz's v5 documentation as far as it is publicly readable
 * (`play.innings`, `squad.a.playing_xi`, `toss.winner/elected`, ball objects with `overs: [o, b]`,
 * `ball_type`, `batsman.runs/is_four/is_six`, `bowler.is_wicket`, `team_score`, `wicket.kind`,
 * `fielders[]`). No real payload has been seen yet — there is no API key on the account — so the
 * first live match must be checked against cricket_feed_raw before real money rides on it. Because
 * the raw body is archived, a wrong alias is fixed by correcting it here and re-running
 * cricket_feed_replay(); nothing that was received is ever lost.
 */

require_once __DIR__ . '/json.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/logger.php';
require_once __DIR__ . '/cricket-mock.php';

// -------------------------------------------------------------------------------------------------
// Configuration
// -------------------------------------------------------------------------------------------------

/** Are the cricket games switched on for this deployment at all? */
function cricket_enabled() {
    // CRICKET_ENABLED, unless the production guard in config.php is holding it off.
    return (bool) cfg('CRICKET_LIVE', false);
}

/**
 * 'roanuz', 'sportmonks' (polled; only when CRICKET_SOURCE=sportmonks says so) or 'mock'. Left unset it resolves itself from whether Roanuz credentials exist, so
 * dropping a real key into .env is the entire switch from simulated to live.
 */
function cricket_source_mode() {
    // Belt and braces behind the config guard: production never resolves to the simulator.
    if (cfg('IS_PRODUCTION') && !cfg('CRICKET_LIVE')) return 'off';
    $explicit = strtolower(trim((string) env_get('CRICKET_SOURCE', '')));
    if (in_array($explicit, ['mock', 'roanuz', 'sportmonks'], true)) return $explicit;
    return (trim((string) env_get('ROANUZ_API_KEY', '')) !== '' && trim((string) env_get('ROANUZ_PROJECT_KEY', '')) !== '')
        ? 'roanuz' : 'mock';
}

// -------------------------------------------------------------------------------------------------
// Small readers
// -------------------------------------------------------------------------------------------------

/** First present, non-empty value among dotted-path keys. Never throws. */
function cricket_pick($obj, array $paths, $default = null) {
    foreach ($paths as $path) {
        $cur = $obj;
        $ok = true;
        foreach (explode('.', $path) as $part) {
            if (is_array($cur) && array_key_exists($part, $cur)) { $cur = $cur[$part]; }
            else { $ok = false; break; }
        }
        if ($ok && $cur !== null && $cur !== '') return $cur;
    }
    return $default;
}

function cricket_bool($v) {
    return $v === true || $v === 1 || $v === '1' || $v === 'true' || $v === 'yes';
}

/** WK / BAT / ALL / BOWL from whatever a provider calls a role. */
function cricket_role($raw) {
    $r = strtolower((string) $raw);
    if (strpos($r, 'keep') !== false || strpos($r, 'wk') !== false) return 'WK';
    if (strpos($r, 'all') !== false) return 'ALL';
    if (strpos($r, 'bowl') !== false) return 'BOWL';
    return 'BAT';
}

// -------------------------------------------------------------------------------------------------
// Parsing a snapshot
// -------------------------------------------------------------------------------------------------

/**
 * Normalise our internal match status from Roanuz's two-level status.
 *
 * Returns one of: not_started, live, innings_break, completed, abandoned.
 * "abandoned" covers every way a match ends without a result — rain, no result, cancelled — because
 * every one of them must refund rather than settle.
 */
function cricket_normalise_status($status, $playStatus, $resultText = '') {
    $s = strtolower((string) $status);
    $p = strtolower((string) $playStatus);
    $r = strtolower((string) $resultText);
    foreach (['abandon', 'no_result', 'no result', 'cancel', 'washed'] as $needle) {
        if (strpos($p, $needle) !== false || strpos($r, $needle) !== false || strpos($s, $needle) !== false) return 'abandoned';
    }
    if ($s === 'completed' || $s === 'complete' || $p === 'result' || $s === 'finished') return 'completed';
    if ($p === 'innings_break') return 'innings_break';
    if ($s === 'started' || $s === 'live' || in_array($p, ['in_play', 'live', 'stumps', 'rain_delay', 'drinks', 'strategic_timeout'], true)) return 'live';
    return 'not_started';
}

/**
 * One raw ball object -> the canonical delivery.
 *
 * $inningsIndex maps an innings key ("a_1") to its 1-based position in the match.
 */
function cricket_parse_ball(array $raw, array $inningsIndex, $format = 'T20') {
    $innKey = (string) cricket_pick($raw, ['innings', 'innings_key'], '');
    if (isset($inningsIndex[$innKey])) {
        $innings = $inningsIndex[$innKey];
    } elseif (is_numeric($innKey)) {
        $innings = (int) $innKey;
    } else {
        $innings = 1;
    }

    $overs = cricket_pick($raw, ['overs', 'over'], [0, 0]);
    if (is_array($overs)) {
        $overNo = (int) ($overs[0] ?? 0);
        $ballNo = (int) ($overs[1] ?? 0);
    } else {
        $parts = explode('.', (string) $overs);
        $overNo = (int) $parts[0];
        $ballNo = (int) ($parts[1] ?? 0);
    }

    $type = strtolower(str_replace([' ', '-'], '_', (string) cricket_pick($raw, ['ball_type', 'type'], 'normal')));
    $extraType = null;
    if (strpos($type, 'wide') !== false) $extraType = 'wide';
    elseif (strpos($type, 'no_ball') !== false || $type === 'noball' || $type === 'nb') $extraType = 'noball';
    elseif (strpos($type, 'leg_bye') !== false || $type === 'legbye' || $type === 'lb') $extraType = 'legbye';
    elseif ($type === 'bye' || $type === 'b') $extraType = 'bye';
    elseif (strpos($type, 'penalty') !== false) $extraType = 'penalty';

    $batsmanRuns = (int) cricket_pick($raw, ['batsman.runs', 'batsman_runs', 'score.batsman_runs'], 0);
    $totalRuns   = cricket_pick($raw, ['team_score.runs', 'runs', 'score.runs'], null);
    $extraRuns   = cricket_pick($raw, ['team_score.extras', 'extras', 'extra_runs', 'score.extras'], null);
    if ($extraRuns === null) $extraRuns = $totalRuns !== null ? max(0, (int) $totalRuns - $batsmanRuns) : ($extraType ? 1 : 0);
    $extraRuns = (int) $extraRuns;

    $isFour = cricket_bool(cricket_pick($raw, ['batsman.is_four', 'is_four'], false));
    $isSix  = cricket_bool(cricket_pick($raw, ['batsman.is_six', 'is_six'], false));
    $boundary = $isSix ? 6 : ($isFour ? 4 : 0);

    $wicket = cricket_pick($raw, ['wicket'], null);
    $isWicket = cricket_bool(cricket_pick($raw, ['team_score.is_wicket', 'is_wicket', 'bowler.is_wicket'], false))
                || (is_array($wicket) && cricket_pick($wicket, ['player_key', 'player.key', 'kind'], null) !== null);
    $wicketType = null; $outKey = null;
    if ($isWicket) {
        $wicketType = strtolower(str_replace([' ', '-'], '_', (string) cricket_pick($raw, ['wicket.kind', 'wicket.wicket_type', 'wicket_type', 'dismissal_type'], 'unknown')));
        if ($wicketType === 'run_out' || $wicketType === 'runout') $wicketType = 'run_out';
        $outKey = cricket_pick($raw, ['wicket.player_key', 'wicket.player.key', 'out_player.key', 'out_player'], null);
        if ($outKey === null) $outKey = cricket_pick($raw, ['batsman.player_key', 'batsman.key'], null);
        if ($wicketType === 'retired_hurt' || $wicketType === 'retired_not_out') $isWicket = false;
    }

    $fielders = [];
    foreach ((array) cricket_pick($raw, ['fielders', 'wicket.fielders'], []) as $f) {
        if (!is_array($f)) { $fielders[] = ['key' => (string) $f, 'catch' => false, 'stumping' => false, 'runout' => false, 'direct' => false]; continue; }
        $fk = cricket_pick($f, ['player_key', 'key', 'player.key'], null);
        if ($fk === null) continue;
        $fielders[] = [
            'key'      => (string) $fk,
            'catch'    => cricket_bool(cricket_pick($f, ['is_catch', 'catch'], false)),
            'stumping' => cricket_bool(cricket_pick($f, ['is_stumps', 'is_stumping', 'stumping'], false)),
            'runout'   => cricket_bool(cricket_pick($f, ['is_run_out', 'run_out'], false)) || cricket_bool(cricket_pick($f, ['is_direct_run_out'], false)),
            'direct'   => cricket_bool(cricket_pick($f, ['is_direct_run_out', 'direct'], false)),
        ];
    }
    // A caught dismissal the feed did not attach a fielder to, but flags as caught-and-bowled.
    if ($isWicket && $wicketType === 'caught_and_bowled' && !$fielders) {
        $bk = cricket_pick($raw, ['bowler.player_key', 'bowler.key', 'bowler'], null);
        if ($bk) $fielders[] = ['key' => (string) $bk, 'catch' => true, 'stumping' => false, 'runout' => false, 'direct' => false];
    }

    $isSuper = cricket_bool(cricket_pick($raw, ['is_super_over', 'super_over'], false))
               || stripos($innKey, 'super') !== false
               || (strtoupper($format) !== 'TEST' && $innings > 2);

    // When the provider stamps the delivery itself, keep it: the Ball by Ball latency guard then uses
    // when the ball was BOWLED, not merely when we heard about it, which closes the gap a late or
    // batched update would otherwise open.
    $ts = cricket_pick($raw, ['timestamp', 'ball_time', 'created_at'], null);
    $tsMs = is_numeric($ts) ? ((int) $ts) * ((int) $ts > 100000000000 ? 1 : 1000) : null;

    $uid = (string) cricket_pick($raw, ['key', 'ball_key', 'id'], '');
    if ($uid === '') {
        $uid = 'h' . substr(sha1(json_encode([$innKey, $overNo, $ballNo, $type,
            cricket_pick($raw, ['batsman.player_key'], ''), cricket_pick($raw, ['bowler.player_key'], ''),
            $batsmanRuns, $extraRuns, $isWicket])), 0, 24);
    }

    return [
        'uid'             => $uid,
        'index'           => cricket_pick($raw, ['index', 'ball_index', 'sequence'], null),
        'innings'         => $innings,
        'over_no'         => $overNo,
        'ball_no'         => $ballNo,
        'is_legal'        => ($extraType === 'wide' || $extraType === 'noball') ? 0 : 1,
        'is_super_over'   => $isSuper ? 1 : 0,
        'batting_team'    => cricket_pick($raw, ['batting_team'], null),
        'batsman_key'     => cricket_pick($raw, ['batsman.player_key', 'batsman.key', 'striker.player_key'], null),
        'non_striker_key' => cricket_pick($raw, ['non_striker.player_key', 'non_striker.key', 'non_striker'], null),
        'bowler_key'      => cricket_pick($raw, ['bowler.player_key', 'bowler.key'], null),
        'batsman_runs'    => $batsmanRuns,
        'extra_type'      => $extraType,
        'extra_runs'      => $extraRuns,
        'is_boundary'     => $boundary,
        'is_wicket'       => $isWicket ? 1 : 0,
        'wicket_type'     => $wicketType,
        'out_player_key'  => $outKey,
        'fielders'        => $fielders,
        'commentary'      => cricket_pick($raw, ['comment', 'commentary'], null),
        'ts_ms'           => $tsMs,
    ];
}

/**
 * A full Roanuz match snapshot -> canonical match facts plus canonical deliveries.
 *
 * Accepts the snapshot itself, or the {data: {...}} envelope Roanuz's REST endpoints wrap it in.
 */
function cricket_parse_snapshot(array $body) {
    $m = isset($body['data']) && is_array($body['data']) && isset($body['data']['key']) ? $body['data'] : $body;

    $key = (string) cricket_pick($m, ['key', 'match_key', 'match.key'], '');
    $format = strtoupper((string) cricket_pick($m, ['format', 'match_format'], 'T20'));
    if ($format === 'ONEDAY' || $format === 'ODI' || $format === 'ONE_DAY') $format = 'ODI';
    elseif (strpos($format, 'TEST') !== false) $format = 'TEST';
    elseif (strpos($format, 'T10') !== false) $format = 'T10';
    elseif (strpos($format, 'HUNDRED') !== false || $format === 'T100' || $format === '100_BALL') $format = 'HUNDRED';
    else $format = 'T20';

    $teams = [];
    foreach (['a', 'b'] as $side) {
        $teams[$side] = [
            'key'   => (string) cricket_pick($m, ["teams.$side.key"], $side),
            'name'  => (string) cricket_pick($m, ["teams.$side.name"], strtoupper($side)),
            'code'  => (string) cricket_pick($m, ["teams.$side.code", "teams.$side.short_name"], strtoupper($side)),
        ];
    }

    $result = cricket_pick($m, ['play.result', 'result'], null);
    $resultText = is_array($result) ? (string) cricket_pick($result, ['msg', 'message', 'text'], '') : (string) $result;
    $status = cricket_normalise_status(cricket_pick($m, ['status'], ''), cricket_pick($m, ['play_status', 'play.status'], ''), $resultText);

    // Players: a flat key -> facts map, plus which side each belongs to.
    $players = [];
    foreach ((array) cricket_pick($m, ['players'], []) as $pk => $p) {
        if (!is_array($p)) continue;
        $info = isset($p['player']) && is_array($p['player']) ? $p['player'] : $p;
        $k = (string) cricket_pick($info, ['key', 'player_key'], $pk);
        $players[$k] = [
            'name' => (string) cricket_pick($info, ['name', 'jersey_name', 'legal_name'], $k),
            'role' => cricket_role(cricket_pick($info, ['seasonal_role', 'role', 'playing_role'], '')),
            'side' => null,
        ];
    }
    $lineups = [];
    $squadKeys = [];
    foreach (['a', 'b'] as $side) {
        $xi = cricket_pick($m, ["squad.$side.playing_xi", "squad.$side.playing_11", "playing_xi.$side"], null);
        if (is_array($xi) && $xi) $lineups[$side] = array_values(array_map('strval', $xi));
        $all = cricket_pick($m, ["squad.$side.player_keys", "squad.$side.players"], []);
        $squadKeys[$side] = is_array($all) ? array_values(array_map('strval', array_keys($all) === range(0, count($all) - 1) ? $all : array_keys($all))) : [];
        foreach (array_merge($squadKeys[$side], $lineups[$side] ?? []) as $k) {
            if (!isset($players[$k])) $players[$k] = ['name' => $k, 'role' => 'BAT', 'side' => $side];
            $players[$k]['side'] = $side;
        }
    }

    $tossWinner = cricket_pick($m, ['toss.winner', 'toss.winner.key'], null);
    $toss = $tossWinner ? ['winner' => (string) $tossWinner, 'elected' => (string) cricket_pick($m, ['toss.elected', 'toss.decision'], '')] : null;

    $order = cricket_pick($m, ['play.innings_order', 'innings_order'], []);
    $inningsIndex = [];
    foreach ((array) $order as $i => $k) $inningsIndex[(string) $k] = $i + 1;

    $balls = [];
    $rawBalls = cricket_pick($m, ['related_balls', 'balls', 'play.related_balls', 'play.balls'], []);
    if (is_array($rawBalls) && array_keys($rawBalls) !== range(0, count($rawBalls) - 1)) $rawBalls = array_values($rawBalls);
    foreach ((array) $rawBalls as $raw) {
        if (!is_array($raw)) continue;
        $innKey = (string) cricket_pick($raw, ['innings', 'innings_key'], '');
        if ($innKey !== '' && !isset($inningsIndex[$innKey]) && !is_numeric($innKey)) {
            $inningsIndex[$innKey] = count($inningsIndex) + 1;
        }
        $balls[] = cricket_parse_ball($raw, $inningsIndex, $format);
    }

    $startAt = cricket_pick($m, ['start_at', 'start_date.timestamp'], null);
    $startMs = $startAt !== null ? ((int) $startAt) * ((int) $startAt > 100000000000 ? 1 : 1000) : null;

    return [
        'key'           => $key,
        'format'        => $format,
        'title'         => (string) cricket_pick($m, ['name', 'title'], ''),
        'short_name'    => (string) cricket_pick($m, ['short_name'], ''),
        'teams'         => $teams,
        'start_ms'      => $startMs,
        'status'        => $status,
        'status_text'   => (string) cricket_pick($m, ['play_status', 'status_note', 'status'], ''),
        'toss'          => $toss,
        'lineups'       => $lineups,
        'squads'        => $squadKeys,
        'players'       => $players,
        'innings_order' => array_keys($inningsIndex),
        'target'        => cricket_pick($m, ['play.target', 'target'], null),
        'result_text'   => $resultText !== '' ? $resultText : null,
        // The winning side ('a' / 'b'), or null for a tie / no result. Match Odds settle on this.
        'winner'        => is_array($result) ? (cricket_pick($result, ['winner', 'winning_team', 'winner.key'], null) ?: null) : null,
        'venue'         => (string) cricket_pick($m, ['venue.name', 'venue'], ''),
        'balls'         => $balls,
        // The source lists every delivery of the match each time (see the deletion step in the ingest).
        'balls_complete' => !empty($m['_balls_complete']),
    ];
}

/** Is this body a match snapshot at all? */
function cricket_looks_like_snapshot($body) {
    if (!is_array($body)) return false;
    $m = isset($body['data']) && is_array($body['data']) ? $body['data'] : $body;
    return cricket_pick($m, ['key', 'match_key'], null) !== null
        && (isset($m['play']) || isset($m['related_balls']) || isset($m['squad']) || isset($m['status']) || isset($m['teams']));
}

// -------------------------------------------------------------------------------------------------
// Ingest
// -------------------------------------------------------------------------------------------------

/** Fingerprint of everything about a delivery that a correction could change. */
function cricket_ball_fp(array $b) {
    return sha1(json_encode([$b['innings'], $b['over_no'], $b['ball_no'], $b['is_legal'], $b['batsman_key'],
        $b['non_striker_key'], $b['bowler_key'], $b['batsman_runs'], $b['extra_type'], $b['extra_runs'],
        $b['is_boundary'], $b['is_wicket'], $b['wicket_type'], $b['out_player_key'], $b['fielders']]));
}

/**
 * Ingest one snapshot. The single entry point for the webhook, the mock and replays.
 *
 * Returns ['ok' => bool, 'match_key' => string, 'duplicate' => bool, 'new_balls' => int,
 *          'changed_balls' => int, 'status' => string].
 *
 * Idempotent at two levels: an identical body is recognised by its hash and does nothing at all;
 * a body that resends known deliveries leaves them untouched unless their content changed.
 */
function cricket_feed_ingest(array $body, $source = 'push', $receivedMs = null) {
    if (!cricket_looks_like_snapshot($body)) {
        return ['ok' => false, 'error' => 'not a match snapshot'];
    }
    $snap = cricket_parse_snapshot($body);
    if ($snap['key'] === '') return ['ok' => false, 'error' => 'snapshot has no match key'];

    $now = $receivedMs ?? now_ms();
    $nowSql = ms_to_sql($now);
    $raw = json_encode($body);
    $sha = sha1($raw);
    $key = $snap['key'];

    $out = tx(function () use ($snap, $raw, $sha, $key, $source, $nowSql, $now) {
        $inserted = affected(
            'INSERT INTO "cricket_feed_raw" ("match_key","body_sha","body","source","received_at") VALUES (?,?,?,?,?) '
            . 'ON CONFLICT ("match_key","body_sha") DO NOTHING',
            [$key, $sha, $raw, $source, $nowSql]
        );

        // Even an identical body proves the feed is alive, which is what the stall detector reads.
        if ($inserted === 0) {
            q('UPDATE "cricket_match_feed" SET "last_feed_at" = ?, "stalled" = 0 WHERE "match_key" = ?', [$nowSql, $key]);
            return ['duplicate' => true, 'new_balls' => 0, 'changed_balls' => 0];
        }

        // --- match-level facts ---
        $meta = [
            'innings_order' => $snap['innings_order'],
            'target'        => $snap['target'],
            'venue'         => $snap['venue'],
            'squads'        => $snap['squads'],
            'short_name'    => $snap['short_name'],
            'winner'        => $snap['winner'],
        ];
        $existing = one('SELECT "match_key","players" FROM "cricket_match_feed" WHERE "match_key" = ?', [$key]);
        // Player names arrive in full on the first snapshot; later ones may carry only keys. Merge so a
        // thin snapshot never erases a name we already know.
        $players = $snap['players'];
        if ($existing) {
            $known = json_decode((string) $existing['players'], true) ?: [];
            foreach ($players as $pk => $p) {
                if (isset($known[$pk]) && ($p['name'] === $pk || $p['name'] === '')) $players[$pk]['name'] = $known[$pk]['name'];
            }
            $players = $players + $known;
        }
        $fields = [
            'format' => $snap['format'], 'title' => $snap['title'],
            'team_a_key' => $snap['teams']['a']['key'], 'team_b_key' => $snap['teams']['b']['key'],
            'team_a' => $snap['teams']['a']['name'], 'team_b' => $snap['teams']['b']['name'],
            'team_a_short' => $snap['teams']['a']['code'], 'team_b_short' => $snap['teams']['b']['code'],
            'start_time' => $snap['start_ms'] ? ms_to_sql($snap['start_ms']) : null,
            'status' => $snap['status'], 'status_text' => $snap['status_text'],
            'toss' => json_encode($snap['toss'] ?: new stdClass()),
            'lineups' => json_encode($snap['lineups'] ?: new stdClass()),
            'players' => json_encode($players ?: new stdClass()),
            'result_text' => $snap['result_text'],
            'meta' => json_encode($meta),
            'last_feed_at' => $nowSql, 'stalled' => 0, 'updated_at' => $nowSql,
        ];
        if ($existing) {
            $sets = []; $args = [];
            foreach ($fields as $c => $v) { $sets[] = '"' . $c . '" = ?'; $args[] = $v; }
            $args[] = $key;
            q('UPDATE "cricket_match_feed" SET ' . implode(', ', $sets) . ' WHERE "match_key" = ?', $args);
        } else {
            $cols = array_merge(['match_key'], array_keys($fields));
            $args = array_merge([$key], array_values($fields));
            q('INSERT INTO "cricket_match_feed" ("' . implode('","', $cols) . '") VALUES ('
              . implode(',', array_fill(0, count($cols), '?')) . ')', $args);
        }

        // --- deliveries ---
        $known = [];
        foreach (all('SELECT "ball_uid","fp","seq","innings" FROM "cricket_deliveries" WHERE "match_key" = ?', [$key]) as $r) {
            $known[(string) $r['ball_uid']] = $r;
        }
        $nextSeq = [];
        foreach ($known as $r) {
            $inn = (int) $r['innings'];
            $nextSeq[$inn] = max($nextSeq[$inn] ?? ($inn * 100000), (int) $r['seq']);
        }

        $new = 0; $changed = 0;
        foreach ($snap['balls'] as $b) {
            $fp = cricket_ball_fp($b);
            if (isset($known[$b['uid']])) {
                if ($known[$b['uid']]['fp'] === $fp) continue;
                q('UPDATE "cricket_deliveries" SET "innings"=?,"over_no"=?,"ball_no"=?,"is_legal"=?,"is_super_over"=?,'
                  . '"batting_team"=?,"batsman_key"=?,"non_striker_key"=?,"bowler_key"=?,"batsman_runs"=?,"extra_type"=?,'
                  . '"extra_runs"=?,"is_boundary"=?,"is_wicket"=?,"wicket_type"=?,"out_player_key"=?,"fielders"=?,'
                  . '"commentary"=?,"fp"=?,"updated_at"=? WHERE "match_key"=? AND "ball_uid"=?',
                  [$b['innings'], $b['over_no'], $b['ball_no'], $b['is_legal'], $b['is_super_over'], $b['batting_team'],
                   $b['batsman_key'], $b['non_striker_key'], $b['bowler_key'], $b['batsman_runs'], $b['extra_type'],
                   $b['extra_runs'], $b['is_boundary'], $b['is_wicket'], $b['wicket_type'], $b['out_player_key'],
                   json_encode($b['fielders']), $b['commentary'], $fp, $nowSql, $key, $b['uid']]);
                $changed++;
                continue;
            }
            // Ordering: the provider's own index when it sends one, otherwise arrival order — a
            // snapshot lists its deliveries chronologically, and pushes arrive in real time.
            $inn = (int) $b['innings'];
            if ($b['index'] !== null && is_numeric($b['index'])) {
                $seq = $inn * 100000 + (int) $b['index'];
            } else {
                $seq = ($nextSeq[$inn] ?? ($inn * 100000)) + 1;
            }
            $nextSeq[$inn] = max($nextSeq[$inn] ?? 0, $seq);
            // First seen = the earlier of our receipt and the provider's own timestamp for the ball.
            $seenSql = ($b['ts_ms'] !== null && $b['ts_ms'] < $now) ? ms_to_sql($b['ts_ms']) : $nowSql;
            q('INSERT INTO "cricket_deliveries" ("match_key","ball_uid","seq","innings","over_no","ball_no","is_legal",'
              . '"is_super_over","batting_team","batsman_key","non_striker_key","bowler_key","batsman_runs","extra_type",'
              . '"extra_runs","is_boundary","is_wicket","wicket_type","out_player_key","fielders","commentary","fp",'
              . '"first_seen_at","updated_at") VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?) '
              . 'ON CONFLICT ("match_key","ball_uid") DO NOTHING',
              [$key, $b['uid'], $seq, $inn, $b['over_no'], $b['ball_no'], $b['is_legal'], $b['is_super_over'],
               $b['batting_team'], $b['batsman_key'], $b['non_striker_key'], $b['bowler_key'], $b['batsman_runs'],
               $b['extra_type'], $b['extra_runs'], $b['is_boundary'], $b['is_wicket'], $b['wicket_type'],
               $b['out_player_key'], json_encode($b['fielders']), $b['commentary'], $fp, $seenSql, $nowSql]);
            $new++;
        }
        if ($new > 0) {
            q('UPDATE "cricket_match_feed" SET "last_ball_at" = ? WHERE "match_key" = ?', [$nowSql, $key]);
        }
        // A source that always sends the complete ball list (Sportmonks) deletes a wrong entry by simply
        // no longer listing it. Only a handful at a time, and never from an empty list: a reply that
        // lost its balls altogether is a glitch, not thirty deletions.
        $deleted = 0;
        if (!empty($snap['balls_complete']) && $snap['balls']) {
            $listed = [];
            foreach ($snap['balls'] as $b) $listed[$b['uid']] = true;
            $gone = array_values(array_diff(array_keys($known), array_keys($listed)));
            if ($gone && count($gone) <= 3) {
                foreach ($gone as $uid) q('DELETE FROM "cricket_deliveries" WHERE "match_key" = ? AND "ball_uid" = ?', [$key, $uid]);
                $deleted = count($gone);
                log_info('cricket: provider withdrew deliveries', ['match' => $key, 'balls' => $gone]);
            } elseif ($gone) {
                log_error('cricket: snapshot is missing too many known deliveries; kept them', ['match' => $key, 'missing' => count($gone)]);
            }
        }
        return ['duplicate' => false, 'new_balls' => $new, 'changed_balls' => $changed, 'deleted_balls' => $deleted];
    });

    $out['ok'] = true;
    $out['match_key'] = $key;
    $out['status'] = $snap['status'];

    if (!$out['duplicate']) {
        cricket_after_ingest($key, $out, $now);
    }
    return $out;
}

/**
 * Create the match row for a fixture before its first push, so it can be listed and priced pre-match
 * (Match Odds and Bookmaker open as soon as a strength price exists). The first real snapshot simply
 * overwrites it. Never touches a row that already exists.
 */
function cricket_feed_placeholder(array $f) {
    if (empty($f['key'])) return;
    q('INSERT INTO "cricket_match_feed" ("match_key","format","title","team_a_key","team_b_key","team_a","team_b","team_a_short","team_b_short",'
      . '"start_time","status","meta","updated_at") VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?) ON CONFLICT ("match_key") DO NOTHING',
      [$f['key'], $f['format'] ?? 'T20', $f['name'] ?? '', $f['teams']['a']['key'] ?? 'a', $f['teams']['b']['key'] ?? 'b',
       $f['teams']['a']['name'] ?? '', $f['teams']['b']['name'] ?? '', $f['teams']['a']['code'] ?? '', $f['teams']['b']['code'] ?? '',
       !empty($f['start_ms']) ? ms_to_sql($f['start_ms']) : null, 'not_started', json_encode(['venue' => $f['venue'] ?? '']), ms_to_sql(now_ms())]);
}

/**
 * Fan an update out to both games. Each is wrapped on its own, so a bug in one game can never stop
 * the feed from being recorded or the other game from advancing.
 */
function cricket_after_ingest($key, array $summary, $nowMs) {
    if (function_exists('bbb_process_match')) {
        try { bbb_process_match($key, $nowMs); }
        catch (Throwable $e) { log_error('cricket: ball-by-ball processing failed', ['match' => $key, 'message' => $e->getMessage()]); }
    }
    if (function_exists('mx_process_match')) {
        try { mx_process_match($key, $nowMs); }
        catch (Throwable $e) { log_error('cricket: match-betting processing failed', ['match' => $key, 'message' => $e->getMessage()]); }
    }
    if (function_exists('fantasy_feed_sync')) {
        try { fantasy_feed_sync($key, false); }
        catch (Throwable $e) { log_error('cricket: fantasy sync from feed failed', ['match' => $key, 'message' => $e->getMessage()]); }
    }
}

/**
 * Re-derive a match from its archived raw bodies — the recovery path when an alias in this file was
 * wrong. Deliveries are rebuilt from scratch, then both games recompute.
 */
function cricket_feed_replay($key) {
    $rows = all('SELECT "body","received_at" FROM "cricket_feed_raw" WHERE "match_key" = ? ORDER BY "id" ASC', [$key]);
    q('DELETE FROM "cricket_deliveries" WHERE "match_key" = ?', [$key]);
    q('DELETE FROM "cricket_feed_raw" WHERE "match_key" = ?', [$key]);
    $n = 0;
    foreach ($rows as $r) {
        $body = json_decode((string) $r['body'], true);
        if (!is_array($body)) continue;
        cricket_feed_ingest($body, 'replay', sql_to_ms($r['received_at']));
        $n++;
    }
    return ['ok' => true, 'replayed' => $n];
}

// -------------------------------------------------------------------------------------------------
// Reads
// -------------------------------------------------------------------------------------------------

function cricket_match_feed_row($key) {
    $row = one('SELECT * FROM "cricket_match_feed" WHERE "match_key" = ?', [(string) $key]);
    if (!$row) return null;
    foreach (['toss', 'lineups', 'players', 'meta'] as $c) $row[$c] = json_decode((string) $row[$c], true) ?: [];
    return $row;
}

/** Every delivery of a match in bowling order, canonical shape. */
function cricket_match_deliveries($key) {
    $rows = all('SELECT * FROM "cricket_deliveries" WHERE "match_key" = ? ORDER BY "innings" ASC, "seq" ASC, "id" ASC', [(string) $key]);
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'uid' => (string) $r['ball_uid'], 'seq' => (int) $r['seq'], 'innings' => (int) $r['innings'],
            'over_no' => (int) $r['over_no'], 'ball_no' => (int) $r['ball_no'], 'is_legal' => (int) $r['is_legal'],
            'is_super_over' => (int) $r['is_super_over'], 'batting_team' => $r['batting_team'],
            'batsman_key' => $r['batsman_key'], 'non_striker_key' => $r['non_striker_key'], 'bowler_key' => $r['bowler_key'],
            'batsman_runs' => (int) $r['batsman_runs'], 'extra_type' => $r['extra_type'], 'extra_runs' => (int) $r['extra_runs'],
            'is_boundary' => (int) $r['is_boundary'], 'is_wicket' => (int) $r['is_wicket'], 'wicket_type' => $r['wicket_type'],
            'out_player_key' => $r['out_player_key'], 'fielders' => json_decode((string) $r['fielders'], true) ?: [],
            'commentary' => $r['commentary'], 'first_seen_ms' => sql_to_ms($r['first_seen_at']),
            // When WE received it (first_seen may be earlier, from the provider's own timestamp).
            'received_ms' => sql_to_ms($r['updated_at']),
        ];
    }
    return $out;
}

// -------------------------------------------------------------------------------------------------
// Derivations — pure functions over ordered deliveries
// -------------------------------------------------------------------------------------------------

/** Total runs a delivery added to the batting side. */
function cricket_ball_total(array $b) { return (int) $b['batsman_runs'] + (int) $b['extra_runs']; }

/** Runs charged to the bowler: off the bat plus wides and no-balls. Byes and leg-byes are not. */
function cricket_ball_bowler_runs(array $b) {
    return (int) $b['batsman_runs'] + (in_array($b['extra_type'], ['wide', 'noball'], true) ? (int) $b['extra_runs'] : 0);
}

/** Is this dismissal credited to the bowler? */
function cricket_bowler_wicket(array $b) {
    if (empty($b['is_wicket'])) return false;
    return !in_array($b['wicket_type'], ['run_out', 'retired_hurt', 'retired_out', 'obstructing_the_field',
                                           'timed_out', 'handled_the_ball', 'retired_not_out'], true);
}

/** The short token a scoreboard shows for one delivery: 0 1 4 6 W Wd 2Wd Nb+4 1lb 4b. */
function cricket_ball_token(array $b) {
    if (!empty($b['is_wicket'])) return 'W';
    $e = $b['extra_type'];
    if ($e === 'wide')   return ($b['extra_runs'] > 1 ? $b['extra_runs'] : '') . 'Wd';
    if ($e === 'noball') return 'Nb' . ($b['batsman_runs'] ? '+' . $b['batsman_runs'] : '');
    if ($e === 'legbye') return $b['extra_runs'] . 'lb';
    if ($e === 'bye')    return $b['extra_runs'] . 'b';
    return (string) $b['batsman_runs'];
}

/** "12.3" from a count of legal balls. */
function cricket_overs_str($legalBalls) {
    return intdiv((int) $legalBalls, 6) . '.' . ((int) $legalBalls % 6);
}

/**
 * Every player's cumulative match figures, the input to the fantasy points engine.
 *
 * Super overs are excluded: Dream11 awards no points for them, because they are not part of a
 * player's official record either.
 *
 * $lineups: ['a' => [keys], 'b' => [keys]] — anyone outside the announced elevens who bats or bowls
 * is a playing substitute (impact player, concussion replacement); anyone outside them who only
 * fields is an ordinary substitute fielder, and earns nothing.
 */
function cricket_derive_player_stats(array $deliveries, array $lineups = []) {
    $blank = ['runs' => 0, 'balls' => 0, 'fours' => 0, 'sixes' => 0, 'is_out' => 0, 'did_bat' => 0,
              'wickets' => 0, 'balls_bowled' => 0, 'overs' => 0.0, 'maidens' => 0, 'runs_conceded' => 0,
              'bowled_lbw' => 0, 'dot_balls' => 0, 'catches' => 0, 'stumpings' => 0,
              'runouts_direct' => 0, 'runouts_shared' => 0, 'in_lineup' => 0, 'is_substitute' => 0,
              'batted_or_bowled' => 0, 'fielded' => 0];
    $s = [];
    $touch = function ($k) use (&$s, $blank) { if ($k !== null && $k !== '' && !isset($s[$k])) $s[$k] = $blank; };

    $overRuns = [];   // [innings|over|bowler] => ['legal' => n, 'runs' => n]

    foreach ($deliveries as $b) {
        if (!empty($b['is_super_over'])) continue;

        $bat = $b['batsman_key']; $ns = $b['non_striker_key']; $bowl = $b['bowler_key'];
        $touch($bat); $touch($ns); $touch($bowl);

        if ($bat !== null) {
            $s[$bat]['did_bat'] = 1;
            $s[$bat]['batted_or_bowled'] = 1;
            $s[$bat]['runs'] += (int) $b['batsman_runs'];
            if ($b['extra_type'] !== 'wide') $s[$bat]['balls']++;
            if ((int) $b['is_boundary'] === 4) $s[$bat]['fours']++;
            if ((int) $b['is_boundary'] === 6) $s[$bat]['sixes']++;
        }
        if ($ns !== null) { $s[$ns]['did_bat'] = 1; $s[$ns]['batted_or_bowled'] = 1; }

        if ($bowl !== null) {
            $s[$bowl]['batted_or_bowled'] = 1;
            if (!empty($b['is_legal'])) {
                $s[$bowl]['balls_bowled']++;
                if ((int) $b['batsman_runs'] === 0) $s[$bowl]['dot_balls']++;
            }
            $s[$bowl]['runs_conceded'] += cricket_ball_bowler_runs($b);
            if (cricket_bowler_wicket($b)) {
                $s[$bowl]['wickets']++;
                if (in_array($b['wicket_type'], ['bowled', 'lbw'], true)) $s[$bowl]['bowled_lbw']++;
            }
            $ok = $b['innings'] . '|' . $b['over_no'] . '|' . $bowl;
            if (!isset($overRuns[$ok])) $overRuns[$ok] = ['legal' => 0, 'runs' => 0];
            if (!empty($b['is_legal'])) $overRuns[$ok]['legal']++;
            $overRuns[$ok]['runs'] += cricket_ball_bowler_runs($b);
        }

        if (!empty($b['is_wicket']) && $b['out_player_key'] !== null) {
            $touch($b['out_player_key']);
            $s[$b['out_player_key']]['is_out'] = 1;
            $s[$b['out_player_key']]['did_bat'] = 1;
        }

        // Fielding. Run outs: a lone fielder is a direct hit; otherwise only the last two fielders
        // to touch the ball are credited, each as "not a direct hit".
        $fl = (array) $b['fielders'];
        $runoutFielders = array_values(array_filter($fl, function ($f) { return !empty($f['runout']); }));
        if ($runoutFielders) {
            if (count($runoutFielders) === 1) {
                // Dream11's definition: a direct hit is the fielder who is the ONLY one to touch the
                // ball. A lone credited fielder is therefore direct whatever the feed's flag says.
                $k = $runoutFielders[0]['key']; $touch($k);
                $s[$k]['runouts_direct']++;
                $s[$k]['fielded'] = 1;
            } else {
                foreach (array_slice($runoutFielders, -2) as $f) {
                    $touch($f['key']); $s[$f['key']]['runouts_shared']++; $s[$f['key']]['fielded'] = 1;
                }
            }
        }
        // Caught and bowled: the bowler is the catcher even when the feed attaches no fielder entry.
        if (!empty($b['is_wicket']) && $b['wicket_type'] === 'caught_and_bowled' && $bowl !== null) {
            $hasCatch = false; foreach ($fl as $f) if (!empty($f['catch'])) $hasCatch = true;
            if (!$hasCatch) { $s[$bowl]['catches']++; $s[$bowl]['fielded'] = 1; }
        }
        foreach ($fl as $f) {
            if (!empty($f['runout'])) continue;
            $touch($f['key']);
            if (!empty($f['catch']))    { $s[$f['key']]['catches']++;   $s[$f['key']]['fielded'] = 1; }
            if (!empty($f['stumping'])) { $s[$f['key']]['stumpings']++; $s[$f['key']]['fielded'] = 1; }
        }
    }

    foreach ($overRuns as $ok => $o) {
        if ($o['legal'] >= 6 && $o['runs'] === 0) {
            $bowl = explode('|', $ok, 3)[2];
            $s[$bowl]['maidens']++;
        }
    }

    $xi = [];
    foreach ($lineups as $side => $keys) foreach ((array) $keys as $k) $xi[(string) $k] = true;
    foreach ($xi as $k => $_) { $touch($k); }
    foreach ($s as $k => &$row) {
        $row['overs'] = (float) (intdiv($row['balls_bowled'], 6) + ($row['balls_bowled'] % 6) / 10);
        if ($xi) {
            $row['in_lineup'] = isset($xi[$k]) ? 1 : 0;
            $row['is_substitute'] = (!isset($xi[$k]) && $row['batted_or_bowled']) ? 1 : 0;
        }
    }
    unset($row);
    return $s;
}

/**
 * The live scoreboard both games show: innings totals, the batting and bowling cards of the current
 * innings, the current over, and the last few deliveries.
 */
function cricket_derive_scoreboard(array $feed, array $deliveries) {
    $players = $feed['players'] ?? [];
    $name = function ($k) use ($players) { return $k !== null && isset($players[$k]) ? $players[$k]['name'] : (string) $k; };
    $meta = $feed['meta'] ?? [];
    $order = $meta['innings_order'] ?? [];
    $sideOfInnings = function ($inn) use ($order) {
        $k = $order[$inn - 1] ?? null;
        return $k ? substr($k, 0, 1) : null;
    };

    $innings = [];
    foreach ($deliveries as $b) {
        if (!empty($b['is_super_over'])) continue;
        $i = $b['innings'];
        if (!isset($innings[$i])) {
            $side = $b['batting_team'] ?: $sideOfInnings($i);
            $innings[$i] = ['innings' => $i, 'side' => $side, 'runs' => 0, 'wickets' => 0, 'legal' => 0, 'extras' => 0,
                            'batting' => [], 'bowling' => [], 'balls' => []];
        }
        $inn = &$innings[$i];
        $inn['runs'] += cricket_ball_total($b);
        if (!empty($b['is_legal'])) $inn['legal']++;
        $inn['extras'] += (int) $b['extra_runs'];
        if (!empty($b['is_wicket'])) $inn['wickets']++;
        $inn['balls'][] = $b;

        foreach ([$b['batsman_key'], $b['non_striker_key']] as $k) {
            if ($k !== null && !isset($inn['batting'][$k])) {
                $inn['batting'][$k] = ['key' => $k, 'name' => $name($k), 'runs' => 0, 'balls' => 0, 'fours' => 0, 'sixes' => 0, 'out' => null];
            }
        }
        if ($b['batsman_key'] !== null) {
            $bt = &$inn['batting'][$b['batsman_key']];
            $bt['runs'] += (int) $b['batsman_runs'];
            if ($b['extra_type'] !== 'wide') $bt['balls']++;
            if ((int) $b['is_boundary'] === 4) $bt['fours']++;
            if ((int) $b['is_boundary'] === 6) $bt['sixes']++;
            unset($bt);
        }
        if (!empty($b['is_wicket']) && $b['out_player_key'] !== null) {
            if (!isset($inn['batting'][$b['out_player_key']])) {
                $inn['batting'][$b['out_player_key']] = ['key' => $b['out_player_key'], 'name' => $name($b['out_player_key']), 'runs' => 0, 'balls' => 0, 'fours' => 0, 'sixes' => 0, 'out' => null];
            }
            $how = str_replace('_', ' ', (string) $b['wicket_type']);
            $inn['batting'][$b['out_player_key']]['out'] = $how . ($b['bowler_key'] && cricket_bowler_wicket($b) ? ' b ' . $name($b['bowler_key']) : '');
        }
        if ($b['bowler_key'] !== null) {
            $k = $b['bowler_key'];
            if (!isset($inn['bowling'][$k])) $inn['bowling'][$k] = ['key' => $k, 'name' => $name($k), 'legal' => 0, 'runs' => 0, 'wickets' => 0, 'maidens' => 0];
            if (!empty($b['is_legal'])) $inn['bowling'][$k]['legal']++;
            $inn['bowling'][$k]['runs'] += cricket_ball_bowler_runs($b);
            if (cricket_bowler_wicket($b)) $inn['bowling'][$k]['wickets']++;
        }
        unset($inn);
    }

    $teamName = function ($side) use ($feed) {
        if ($side === 'a') return ['name' => $feed['team_a'], 'short' => $feed['team_a_short']];
        if ($side === 'b') return ['name' => $feed['team_b'], 'short' => $feed['team_b_short']];
        return ['name' => '', 'short' => ''];
    };

    $outInnings = [];
    foreach ($innings as $i => $inn) {
        $t = $teamName($inn['side']);
        $batting = array_values(array_map(function ($r) {
            $r['sr'] = $r['balls'] ? round($r['runs'] * 100 / $r['balls'], 1) : 0;
            return $r;
        }, $inn['batting']));
        $bowling = array_values(array_map(function ($r) {
            $r['overs'] = cricket_overs_str($r['legal']);
            $r['econ'] = $r['legal'] ? round($r['runs'] * 6 / $r['legal'], 2) : 0;
            return $r;
        }, $inn['bowling']));
        $outInnings[] = [
            'innings' => $i, 'side' => $inn['side'], 'team' => $t['name'], 'team_short' => $t['short'],
            'runs' => $inn['runs'], 'wickets' => $inn['wickets'], 'overs' => cricket_overs_str($inn['legal']),
            'legal_balls' => $inn['legal'], 'extras' => $inn['extras'],
            'run_rate' => $inn['legal'] ? round($inn['runs'] * 6 / $inn['legal'], 2) : 0,
            'batting' => $batting, 'bowling' => $bowling,
        ];
    }

    $current = $outInnings ? $outInnings[count($outInnings) - 1] : null;
    $lastBalls = [];
    $thisOver = [];
    $striker = null; $nonStriker = null; $bowler = null;
    if ($current) {
        $curBalls = $innings[$current['innings']]['balls'];
        $last = end($curBalls);
        foreach (array_slice($curBalls, -12) as $b) {
            $lastBalls[] = ['token' => cricket_ball_token($b), 'over' => $b['over_no'] . '.' . $b['ball_no'], 'uid' => $b['uid']];
        }
        if ($last) {
            foreach ($curBalls as $b) if ($b['over_no'] === $last['over_no']) $thisOver[] = cricket_ball_token($b);
            $striker = $last['batsman_key']; $nonStriker = $last['non_striker_key']; $bowler = $last['bowler_key'];
            if (!empty($last['is_wicket']) && $last['out_player_key'] === $striker) $striker = null;
            if (!empty($last['is_wicket']) && $last['out_player_key'] === $nonStriker) $nonStriker = null;
        }
        $find = function ($list, $k) { foreach ($list as $r) if ($r['key'] === $k) return $r; return null; };
        $current['striker'] = $striker ? $find($current['batting'], $striker) : null;
        $current['non_striker'] = $nonStriker ? $find($current['batting'], $nonStriker) : null;
        $current['bowler'] = $bowler ? $find($current['bowling'], $bowler) : null;
    }

    $target = $meta['target'] ?? null;
    if ($target === null && count($outInnings) >= 2 && strtoupper((string) ($feed['format'] ?? 'T20')) !== 'TEST') {
        $target = $outInnings[0]['runs'] + 1;
    }
    $chase = null;
    if ($target && $current && $current['innings'] === 2) {
        $fmtU = strtoupper((string) $feed['format']);
        $maxBalls = $fmtU === 'ODI' ? 300 : ($fmtU === 'T10' ? 60 : ($fmtU === 'HUNDRED' ? 100 : 120));
        $need = $target - $current['runs'];
        $left = $maxBalls - $current['legal_balls'];
        $chase = ['target' => (int) $target, 'need' => max(0, $need), 'balls_left' => max(0, $left),
                  'rrr' => $left > 0 ? round(max(0, $need) * 6 / $left, 2) : null];
    }

    return [
        'innings'    => $outInnings,
        'current'    => $current,
        'this_over'  => $thisOver,
        'last_balls' => $lastBalls,
        'chase'      => $chase,
    ];
}

/**
 * A compact public view of a match for list screens: status, the score line, toss, result.
 */
function cricket_match_summary(array $feed, array $board = null) {
    $toss = $feed['toss'] ?? [];
    $tossText = null;
    if (!empty($toss['winner'])) {
        $winner = $toss['winner'] === 'a' || $toss['winner'] === $feed['team_a_key'] ? $feed['team_a'] : $feed['team_b'];
        $tossText = $winner . ' won the toss and chose to ' . ($toss['elected'] === 'bat' ? 'bat' : 'bowl');
    }
    $score = [];
    if ($board) foreach ($board['innings'] as $inn) {
        $score[] = ['team_short' => $inn['team_short'], 'runs' => $inn['runs'], 'wickets' => $inn['wickets'], 'overs' => $inn['overs']];
    }
    return [
        'match_key'    => $feed['match_key'],
        'title'        => $feed['title'],
        'format'       => $feed['format'],
        'team_a'       => $feed['team_a'], 'team_a_short' => $feed['team_a_short'],
        'team_b'       => $feed['team_b'], 'team_b_short' => $feed['team_b_short'],
        'start_time_ms' => $feed['start_time'] ? sql_to_ms($feed['start_time']) : null,
        'status'       => $feed['status'],
        'toss_text'    => $tossText,
        'lineups_out'  => !empty($feed['lineups']),
        'result_text'  => $feed['result_text'],
        'score'        => $score,
        'stalled'      => (int) $feed['stalled'] === 1,
        'last_feed_ms' => $feed['last_feed_at'] ? sql_to_ms($feed['last_feed_at']) : null,
    ];
}

// -------------------------------------------------------------------------------------------------
// Mock driving
// -------------------------------------------------------------------------------------------------

/**
 * In mock mode, push the current snapshot of every active mock match through the real ingest path.
 * Throttled per process with a short state key so that a page poll can call this freely.
 */
function cricket_mock_pump($nowMs = null, $minIntervalMs = 2500) {
    if (cricket_source_mode() !== 'mock') return ['ok' => true, 'skipped' => 'not mock'];
    $now = $nowMs ?? now_ms();
    $last = state_get('cricket_mock_pump_at');
    if ($minIntervalMs > 0 && is_array($last) && isset($last['ms']) && $now - (int) $last['ms'] < $minIntervalMs) {
        return ['ok' => true, 'skipped' => 'throttled'];
    }
    state_set('cricket_mock_pump_at', ['ms' => $now]);
    $done = [];
    $keys = cricket_mock_active_keys($now);
    // A mock match still marked live but outside the active window (the pump was off when it finished,
    // or its rows were edited by hand) would otherwise sit in the lobby as "live" forever. One more
    // snapshot brings it to its true final state.
    foreach (all('SELECT "match_key" FROM "cricket_match_feed" WHERE "status" IN (?, ?) AND "match_key" LIKE ?', ['live', 'innings_break', 'mock\_%']) as $r) {
        if (!in_array($r['match_key'], $keys, true)) $keys[] = $r['match_key'];
    }
    foreach ($keys as $key) {
        $snap = cricket_mock_snapshot($key, $now);
        if ($snap) $done[$key] = cricket_feed_ingest($snap, 'mock', $now);
    }
    return ['ok' => true, 'ingested' => $done];
}
