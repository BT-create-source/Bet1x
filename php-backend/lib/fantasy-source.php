<?php
/**
 * "Your Eleven" — fixture and squad source adapter.
 *
 * =================================================================================================
 * WHY THIS IS AN ADAPTER AND NOT A SCRAPER CALLED DIRECTLY
 * =================================================================================================
 * Nothing above this file knows where cricket data comes from. The ingestion cron asks for
 * fixtures and squads; this decides whether that means a real HTTP fetch or canned sample data,
 * exactly as backend/lib/cricket/roanuz-transport.js does for the Node build with its
 * HttpTransport/MockTransport pair. FANTASY_SOURCE picks:
 *
 *     FANTASY_SOURCE=mock        canned fixtures and squads, no network  (the default)
 *     FANTASY_SOURCE=cricbuzz    live fetch from the public Cricbuzz pages
 *
 * Mock is the default deliberately. It means the lobby, the team builder and later the contest and
 * settlement paths can all be built and tested end to end before pointing anything at a live
 * source, and it means a blocked or changed source degrades to "no new fixtures" rather than to a
 * half-parsed match with a wrong start time.
 *
 * =================================================================================================
 * HOW THE LIVE PARSE WORKS, AND WHY IT IS NOT CSS SELECTORS
 * =================================================================================================
 * The obvious approach — walk the HTML and pull values out of divs by class name — breaks on the
 * first redesign, and it breaks silently. These pages happen to ship their data as JSON embedded in
 * the server-rendered React payload, so this reads THAT instead:
 *
 *     "matchInfo":{"matchId":151532,"seriesName":"West Indies tour of India, 2026",
 *                  "matchDesc":"1st ODI","matchFormat":"ODI","startDate":1790497800000,
 *                  "state":"Preview","team1":{"teamName":"West Indies","teamSName":"WI",...},
 *                  "team2":{...},"venueInfo":{"ground":"...","city":"..."}}
 *
 *     "players":{"Squad":[{"id":8271,"name":"Sanju Samson","role":"WK-Batter","keeper":true,
 *                          "captain":false,"teamName":"IND",...}]}
 *
 * Those are real field names with real types — an epoch in milliseconds is unambiguous in a way that
 * a rendered "7:00 PM" in an unknown timezone is not. If the payload shape ever changes, json_decode
 * fails and the fixture is skipped with a logged reason, instead of a plausible-looking row landing
 * in the database with a wrong lock time.
 *
 * =================================================================================================
 * THE HONEST CAVEATS
 * =================================================================================================
 *  - Scraping a third-party site is against most sites' terms of use, and they are free to block
 *    the server at any time. ESPNcricinfo already returns 403 to a plain server-side request, which
 *    is why only Cricbuzz is implemented here.
 *  - A source that changes shape mid-tournament will stop producing data. Fixtures failing is
 *    harmless (the lobby just shows nothing new), but LIVE SCORING failing mid-match is not, which
 *    is why the admin stat-override route exists in the scoring phase and why settlement will hold
 *    rather than guess.
 *  - Credits are NOT provided by the source. The provisional model below is deterministic and
 *    documented, and is meant to be reviewed by an operator before real money rides on it.
 */

require_once __DIR__ . '/fantasy.php';

/** 'mock' (default) or 'cricbuzz'. */
function fantasy_source_mode() {
    $m = strtolower(trim((string) env_get('FANTASY_SOURCE', 'mock')));
    return in_array($m, ['mock', 'cricbuzz'], true) ? $m : 'mock';
}

// -------------------------------------------------------------------------------------------------
// HTTP
// -------------------------------------------------------------------------------------------------

/**
 * One GET. Returns ['ok' => bool, 'status' => int, 'body' => string, 'error' => string|null].
 *
 * Never throws: a source being unreachable is an expected condition for this module, not an
 * exception, and the caller decides what to do about it.
 */
function fantasy_http_get($url, $timeoutSec = 25) {
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'status' => 0, 'body' => '', 'error' => 'curl extension unavailable'];
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_TIMEOUT        => (int) $timeoutSec,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_ENCODING       => '',
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 '
                                . '(KHTML, like Gecko) Chrome/120.0 Safari/537.36',
        CURLOPT_HTTPHEADER     => ['Accept: text/html,application/xhtml+xml', 'Accept-Language: en-US,en;q=0.9'],
    ]);
    $body   = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err    = curl_error($ch);
    curl_close($ch);

    if ($body === false || $body === '') {
        return ['ok' => false, 'status' => $status, 'body' => '', 'error' => $err ?: 'empty response'];
    }
    if ($status < 200 || $status >= 300) {
        return ['ok' => false, 'status' => $status, 'body' => (string) $body, 'error' => 'HTTP ' . $status];
    }
    return ['ok' => true, 'status' => $status, 'body' => (string) $body, 'error' => null];
}

// -------------------------------------------------------------------------------------------------
// Embedded-JSON extraction
// -------------------------------------------------------------------------------------------------

/**
 * Undo the one level of backslash escaping the React payload applies to the JSON it embeds, so the
 * objects inside become parseable.
 */
function fantasy_unescape_payload($html) {
    return str_replace(['\\"', '\\\\'], ['"', '\\'], (string) $html);
}

/**
 * Pull every balanced {...} that immediately follows "<key>": out of a blob, decoded.
 *
 * Brace counting rather than a regex, because these objects nest (team1, venueInfo, imageDetails)
 * and a regex cannot match balanced delimiters. Strings are tracked so a brace inside a team name
 * cannot throw the depth off.
 */
function fantasy_extract_json_objects($blob, $key) {
    $needle = '"' . $key . '"';
    $out = [];
    $offset = 0;
    $len = strlen($blob);

    while (($pos = strpos($blob, $needle, $offset)) !== false) {
        $offset = $pos + strlen($needle);
        $start = strpos($blob, '{', $offset);
        if ($start === false) break;
        // Only accept a brace that follows the colon directly, so "matchInfoFoo" cannot match.
        $between = substr($blob, $offset, $start - $offset);
        if (trim($between) !== ':') continue;

        $depth = 0;
        $inStr = false;
        $esc = false;
        $end = -1;
        for ($i = $start; $i < $len; $i++) {
            $c = $blob[$i];
            if ($inStr) {
                if ($esc)            { $esc = false; }
                elseif ($c === '\\') { $esc = true; }
                elseif ($c === '"')  { $inStr = false; }
                continue;
            }
            if ($c === '"')      { $inStr = true; }
            elseif ($c === '{')  { $depth++; }
            elseif ($c === '}')  { $depth--; if ($depth === 0) { $end = $i; break; } }
        }
        if ($end < 0) break;

        $decoded = json_decode(substr($blob, $start, $end - $start + 1), true);
        if (is_array($decoded)) $out[] = $decoded;
        $offset = $end + 1;
    }
    return $out;
}

// -------------------------------------------------------------------------------------------------
// Credits (provisional)
// -------------------------------------------------------------------------------------------------

/**
 * Assign a starting credit value, since no public source publishes one.
 *
 * Deterministic on purpose: the same squad always prices the same way, so a re-scrape cannot
 * reshuffle what a team costs. All-rounders are worth most because they score off both bat and ball
 * under the points table, and the captain of a real side is a proxy for being a front-line player.
 * This is a provisional model to get the module running, NOT a considered pricing sheet — the
 * intended long-term answer is the form-weighted model in backend/lib/cricket/credits.js, or an
 * operator editing credits per fixture.
 */
function fantasy_provisional_credits(array $p) {
    $role = fantasy_normalise_role($p['role'] ?? '');
    $base = 8.5;
    if ($role === 'ALL') $base = 9.0;
    if (!empty($p['captain'])) $base += 1.0;
    if ($role === 'WK' && !empty($p['keeper'])) $base += 0.5;
    if (!empty($p['substitute'])) $base -= 0.5;
    return fantasy_clamp_credits($base);
}

// -------------------------------------------------------------------------------------------------
// Public API: fixtures
// -------------------------------------------------------------------------------------------------

/**
 * Upcoming fixtures, normalised into the shape fantasy_upsert_match() expects.
 *
 * Only genuinely future, time-announced fixtures are returned. A match whose start time has already
 * passed is dropped here rather than stored and filtered later, because a fantasy contest on it can
 * never legally open.
 */
function fantasy_source_fixtures() {
    if (fantasy_source_mode() === 'mock') return fantasy_mock_fixtures();

    $url = 'https://www.cricbuzz.com/cricket-schedule/upcoming-series/international';
    $res = fantasy_http_get($url);
    if (!$res['ok']) {
        return ['ok' => false, 'error' => 'fixtures fetch failed: ' . $res['error'], 'fixtures' => []];
    }
    return fantasy_parse_fixtures($res['body']);
}

/**
 * Parse fixtures out of already-fetched HTML.
 *
 * Deliberately separate from the fetch: parsing a third-party page is the part most likely to break,
 * and splitting it means the parser can be run against a real saved copy of the page in a test
 * without any network access. See test_fantasy.php.
 */
function fantasy_parse_fixtures($html) {
    $blob = fantasy_unescape_payload($html);
    $infos = fantasy_extract_json_objects($blob, 'matchInfo');
    if (!$infos) {
        return ['ok' => false, 'error' => 'no matchInfo objects found (source shape changed?)', 'fixtures' => []];
    }

    $nowMs = now_ms();
    $fixtures = [];
    $seen = [];

    foreach ($infos as $m) {
        $id    = isset($m['matchId']) ? (int) $m['matchId'] : 0;
        $start = isset($m['startDate']) ? (int) $m['startDate'] : 0;
        if ($id <= 0 || $start <= 0) continue;
        if (isset($seen[$id])) continue;

        // "Complete"/"Abandon" are finished; anything already started is not joinable.
        $state = strtolower((string) ($m['state'] ?? ''));
        if (in_array($state, ['complete', 'abandon', 'abandoned', 'cancelled', 'canceled'], true)) continue;
        if ($start <= $nowMs) continue;
        // A fixture with no announced time carries a placeholder start; locking entries against a
        // guess would be worse than not listing it.
        if (array_key_exists('isTimeAnnounced', $m) && !$m['isTimeAnnounced']) continue;

        $t1 = is_array($m['team1'] ?? null) ? $m['team1'] : [];
        $t2 = is_array($m['team2'] ?? null) ? $m['team2'] : [];
        $nameA = (string) ($t1['teamName'] ?? '');
        $nameB = (string) ($t2['teamName'] ?? '');
        if ($nameA === '' || $nameB === '') continue;

        $venue = is_array($m['venueInfo'] ?? null) ? $m['venueInfo'] : [];
        $venueText = trim((string) ($venue['ground'] ?? '') . (isset($venue['city']) ? ', ' . $venue['city'] : ''), ', ');

        $seen[$id] = true;
        $fixtures[] = [
            'external_key'  => 'cb:' . $id,
            'series_name'   => (string) ($m['seriesName'] ?? ''),
            'match_title'   => $nameA . ' vs ' . $nameB
                             . (isset($m['matchDesc']) && $m['matchDesc'] !== '' ? ' — ' . $m['matchDesc'] : ''),
            'team_a'        => $nameA,
            'team_b'        => $nameB,
            'team_a_short'  => (string) ($t1['teamSName'] ?? ''),
            'team_b_short'  => (string) ($t2['teamSName'] ?? ''),
            'team_a_logo'   => fantasy_cb_image($t1['imageId'] ?? null),
            'team_b_logo'   => fantasy_cb_image($t2['imageId'] ?? null),
            'format'        => strtoupper((string) ($m['matchFormat'] ?? 'T20')),
            'venue'         => $venueText !== '' ? $venueText : null,
            'start_time_ms' => $start,
            'lock_time_ms'  => $start,
            'scorecard_source_url' => 'https://www.cricbuzz.com/live-cricket-scorecard/' . $id,
            'source_match_id'      => $id,
        ];
    }

    return ['ok' => true, 'error' => null, 'fixtures' => $fixtures];
}

/**
 * Is this job title a coach or support-staff role rather than a playing one?
 *
 * Checked in addition to filtering by squad bucket, because the two failures are independent: a
 * bucket could be renamed, or staff could one day appear inside a player list. Either guard alone
 * keeps a "Head Coach" out of the team builder.
 */
function fantasy_is_support_role($role) {
    $s = strtolower(trim((string) $role));
    if ($s === '') return false;
    foreach (['coach', 'support', 'manager', 'physio', 'analyst', 'selector', 'mentor',
              'trainer', 'doctor', 'staff'] as $needle) {
        if (strpos($s, $needle) !== false) return true;
    }
    return false;
}

/**
 * Recover the source's own match id from an external_key ("cb:151532" -> 151532).
 *
 * The key is built by this file, so parsing it belongs here too. A key whose suffix is not a positive
 * integer returns 0, and the caller treats that as "cannot poll this fixture" rather than fetching
 * some arbitrary match.
 */
function fantasy_source_id_from_key($key) {
    $parts = explode(':', (string) $key);
    $last = trim(end($parts));
    return ctype_digit($last) ? (int) $last : 0;
}

/**
 * Has the source reported this match as over?
 *
 * "Stumps" is deliberately NOT final — a multi-day match resumes the next morning, and treating stumps
 * as the end would freeze the scoreboard and let settlement run a day early.
 */
function fantasy_state_is_final($state) {
    $s = strtolower(trim((string) $state));
    if ($s === '') return false;
    foreach (['complete', 'abandon', 'cancel', 'no result', 'washed out'] as $needle) {
        if (strpos($s, $needle) !== false) return true;
    }
    return false;
}

/** A team/player crest URL, or null when the source gave no image id. */
function fantasy_cb_image($imageId) {
    $id = (int) $imageId;
    if ($id <= 0) return null;
    return 'https://static.cricbuzz.com/a/img/v1/i1/c' . $id . '/i.jpg';
}

// -------------------------------------------------------------------------------------------------
// Public API: squads
// -------------------------------------------------------------------------------------------------

/**
 * The squad for one source match id, normalised for fantasy_upsert_player().
 *
 * Roles arrive as "WK-Batter", "Batting Allrounder", "Bowler" and so on;
 * fantasy_normalise_role() folds those onto WK/BAT/ALL/BOWL, and the explicit `keeper` flag is
 * preferred over the role text when deciding who is a wicket-keeper, because it is a boolean rather
 * than prose.
 */
function fantasy_source_squad($sourceMatchId) {
    if (fantasy_source_mode() === 'mock') return fantasy_mock_squad($sourceMatchId);

    $id = (int) $sourceMatchId;
    $res = fantasy_http_get('https://www.cricbuzz.com/cricket-match-squads/' . $id);
    if (!$res['ok']) {
        return ['ok' => false, 'error' => 'squad fetch failed: ' . $res['error'], 'players' => []];
    }
    return fantasy_parse_squad($res['body']);
}

/** Parse a squad out of already-fetched HTML. Split from the fetch for the same reason as above. */
function fantasy_parse_squad($html) {
    $blob = fantasy_unescape_payload($html);
    $groups = fantasy_extract_json_objects($blob, 'players');
    if (!$groups) {
        return ['ok' => false, 'error' => 'no players object found (source shape changed?)', 'players' => []];
    }

    $players = [];
    $seen = [];
    foreach ($groups as $group) {
        // This object is keyed by bucket, and the buckets are NOT all players: alongside "Squad" the
        // source ships a "support staff" list holding the head coach, batting coach and so on. Those
        // were being ingested as selectable players, which would have let someone spend a team slot
        // on a coach who can never score a point. Only player buckets are accepted, and an
        // unrecognised bucket name is skipped — which fails towards "no squad, match hidden" rather
        // than towards a squad with staff in it.
        foreach ($group as $bucket => $list) {
            if (!is_array($list)) continue;
            $b = strtolower((string) $bucket);
            if (strpos($b, 'squad') === false && strpos($b, 'xi') === false && strpos($b, 'bench') === false) {
                continue;
            }
            foreach ($list as $p) {
                if (!is_array($p)) continue;
                $pid  = isset($p['id']) ? (int) $p['id'] : 0;
                $name = trim((string) ($p['name'] ?? ''));
                if ($pid <= 0 || $name === '') continue;
                if (isset($seen[$pid])) continue;
                // Second, independent guard: even inside a player bucket, anything carrying a
                // coaching or support job title is not a player.
                if (fantasy_is_support_role($p['role'] ?? '')) continue;
                $seen[$pid] = true;

                $role = fantasy_normalise_role($p['role'] ?? '');
                if (!empty($p['keeper'])) $role = 'WK';

                $players[] = [
                    'external_key' => 'cb:' . $pid,
                    'name'         => $name,
                    'full_name'    => trim((string) ($p['fullName'] ?? $name)),
                    'team_name'    => trim((string) ($p['teamName'] ?? '')),
                    'role'         => $role,
                    'credits'      => fantasy_provisional_credits($p),
                ];
            }
        }
    }

    return ['ok' => true, 'error' => null, 'players' => $players];
}

// -------------------------------------------------------------------------------------------------
// Public API: live scorecard
// -------------------------------------------------------------------------------------------------

/**
 * Per-player match figures for one fixture.
 *
 * Returns ['ok' => bool, 'error' => ?string, 'players' => [external_key => stats], 'state' => string].
 *
 * Every figure is CUMULATIVE for the match, never a delta, which is what lets the caller overwrite
 * rather than add and makes a missed or repeated poll harmless.
 */
function fantasy_source_scorecard($sourceMatchId) {
    if (fantasy_source_mode() === 'mock') return fantasy_mock_scorecard($sourceMatchId);

    $id = (int) $sourceMatchId;
    $res = fantasy_http_get('https://www.cricbuzz.com/live-cricket-scorecard/' . $id);
    if (!$res['ok']) {
        return ['ok' => false, 'error' => 'scorecard fetch failed: ' . $res['error'], 'players' => []];
    }
    return fantasy_parse_scorecard($res['body'], $id);
}

/**
 * Parse a scorecard into per-player figures. Split from the fetch so it can be tested against a real
 * saved page.
 *
 * =================================================================================================
 * WHY THIS MAPS BY ID AND NOT BY NAME
 * =================================================================================================
 * The scorecard carries "batId" and "bowlerId", and those are the SAME identifiers the squads page
 * gives as "id" — so a player ingested as external_key "cb:8271" is the same person as batId 8271
 * here. Mapping is therefore an exact key match, not a name comparison.
 *
 * That matters because this feeds settlement. Fuzzy name matching exists as a documented FALLBACK in
 * lib/fantasy-live.php for the rare row whose id we never ingested, but it is not the primary
 * mechanism: "V Kohli" versus "Virat Kohli" is a solvable problem, whereas two players named
 * "M Shahzad" in one squad is not, and guessing between them would mispay real money.
 *
 * =================================================================================================
 * FIELDING IS DERIVED FROM DISMISSALS
 * =================================================================================================
 * There is no fielding table. Each dismissal in the batting list carries a wicketCode plus up to
 * three fielder ids, so catches, stumpings and run-outs are counted from the other side of each
 * wicket. A run-out with a second fielder is credited to both as shared rather than to one as direct.
 */
function fantasy_parse_scorecard($html, $expectMatchId = null) {
    $blob = fantasy_unescape_payload($html);

    // This page also carries other fixtures in its navigation, so a bare search for "state" would
    // find several. The match's own state is read from the matchInfo object whose matchId is the one
    // we asked for; anything else is left as null and the caller does not act on it, rather than a
    // neighbouring fixture's "Complete" being mistaken for this one's.
    $state = null;
    $statusText = null;
    if ($expectMatchId !== null) {
        foreach (fantasy_extract_json_objects($blob, 'matchInfo') as $info) {
            if (isset($info['matchId']) && (int) $info['matchId'] === (int) $expectMatchId) {
                $state = isset($info['state']) ? (string) $info['state'] : null;
                $statusText = isset($info['status']) ? (string) $info['status'] : null;
                break;
            }
        }
    }

    $batGroups  = fantasy_extract_json_objects($blob, 'batsmenData');
    $bowlGroups = fantasy_extract_json_objects($blob, 'bowlersData');
    if (!$batGroups && !$bowlGroups) {
        return ['ok' => false, 'error' => 'no batsmenData/bowlersData found (source shape changed?)',
                'players' => [], 'state' => $state, 'status_text' => $statusText];
    }

    $players = [];
    /** Start (or fetch) a player's accumulator. */
    $slot = function ($id, $name) use (&$players) {
        $key = 'cb:' . (int) $id;
        if (!isset($players[$key])) {
            $players[$key] = [
                'external_key' => $key, 'source_id' => (int) $id, 'name' => (string) $name,
                'runs' => 0, 'balls' => 0, 'fours' => 0, 'sixes' => 0,
                'is_out' => 0, 'did_bat' => 0,
                'wickets' => 0, 'overs' => 0.0, 'maidens' => 0, 'runs_conceded' => 0, 'bowled_lbw' => 0,
                'catches' => 0, 'stumpings' => 0, 'runouts_direct' => 0, 'runouts_shared' => 0,
            ];
        } elseif ($players[$key]['name'] === '' && $name !== '') {
            $players[$key]['name'] = (string) $name;
        }
        return $key;
    };

    // --- batting, accumulated across every innings on the page ---
    $dismissals = [];
    foreach ($batGroups as $group) {
        foreach ($group as $entry) {
            if (!is_array($entry) || !isset($entry['batId'])) continue;
            $id = (int) $entry['batId'];
            if ($id <= 0) continue;
            $key = $slot($id, $entry['batName'] ?? ($entry['batShortName'] ?? ''));

            $players[$key]['runs']  += (int) ($entry['runs'] ?? 0);
            $players[$key]['balls'] += (int) ($entry['balls'] ?? 0);
            $players[$key]['fours'] += (int) ($entry['fours'] ?? 0);
            $players[$key]['sixes'] += (int) ($entry['sixes'] ?? 0);
            $players[$key]['did_bat'] = 1;

            // An empty wicketCode is a not-out innings ("not out" in outDesc), which must not be
            // mistaken for a duck.
            $code = strtoupper(trim((string) ($entry['wicketCode'] ?? '')));
            if ($code !== '' && $code !== 'NOTOUT') {
                $players[$key]['is_out'] = 1;
                $dismissals[] = [
                    'code'   => $code,
                    'bowler' => (int) ($entry['bowlerId'] ?? 0),
                    'f1'     => (int) ($entry['fielderId1'] ?? 0),
                    'f2'     => (int) ($entry['fielderId2'] ?? 0),
                    'f3'     => (int) ($entry['fielderId3'] ?? 0),
                ];
            }
        }
    }

    // --- bowling ---
    foreach ($bowlGroups as $group) {
        foreach ($group as $entry) {
            if (!is_array($entry) || !isset($entry['bowlerId'])) continue;
            $id = (int) $entry['bowlerId'];
            if ($id <= 0) continue;
            $key = $slot($id, $entry['bowlName'] ?? ($entry['bowlShortName'] ?? ''));

            $players[$key]['wickets']  += (int) ($entry['wickets'] ?? 0);
            $players[$key]['maidens']  += (int) ($entry['maidens'] ?? 0);
            // NOTE: on a bowling row "runs" means runs CONCEDED, not runs scored. Adding it to the
            // batting total would hand every bowler a batting score they never made.
            $players[$key]['runs_conceded'] += (int) ($entry['runs'] ?? 0);
            $players[$key]['overs'] = round($players[$key]['overs'] + (float) ($entry['overs'] ?? 0), 1);
        }
    }

    // --- fielding and the bowled/LBW bonus, from the other side of each dismissal ---
    foreach ($dismissals as $d) {
        switch ($d['code']) {
            case 'BOWLED':
            case 'LBW':
                if ($d['bowler'] > 0) {
                    $key = $slot($d['bowler'], '');
                    $players[$key]['bowled_lbw']++;
                }
                break;

            case 'CAUGHT':
                // Caught-and-bowled arrives as CAUGHT with the fielder equal to the bowler; that is
                // still a catch, so no special case is needed.
                if ($d['f1'] > 0) {
                    $key = $slot($d['f1'], '');
                    $players[$key]['catches']++;
                }
                break;

            case 'STUMPED':
                if ($d['f1'] > 0) {
                    $key = $slot($d['f1'], '');
                    $players[$key]['stumpings']++;
                }
                break;

            case 'RUNOUT':
                // Two fielders involved means neither did it alone, so both are credited as shared
                // rather than one being paid the higher direct rate.
                $involved = array_values(array_filter([$d['f1'], $d['f2'], $d['f3']], function ($x) {
                    return $x > 0;
                }));
                if (count($involved) === 1) {
                    $players[$slot($involved[0], '')]['runouts_direct']++;
                } else {
                    foreach ($involved as $fid) $players[$slot($fid, '')]['runouts_shared']++;
                }
                break;

            default:
                // HITWICKET, RETIRED, OBSTRUCTING and anything new: the batter is out (already
                // recorded) and no fielder or bowler bonus is awarded. Unknown codes must not invent
                // credit for someone.
                break;
        }
    }

    return ['ok' => true, 'error' => null, 'players' => $players,
            'state' => $state, 'status_text' => $statusText];
}

// -------------------------------------------------------------------------------------------------
// Mock source
// -------------------------------------------------------------------------------------------------

/**
 * Two fixtures a few hours out, shaped exactly like the live parser's output.
 *
 * This exists so the whole module is developable and testable with no network and no dependence on
 * a third party's markup — the same reason the Node build ships MockTransport.
 */
function fantasy_mock_fixtures() {
    $now = now_ms();
    $hour = 3600 * 1000;
    return ['ok' => true, 'error' => null, 'fixtures' => [
        [
            'external_key'  => 'mock:1001',
            'series_name'   => 'Indian Premier League 2026',
            'match_title'   => 'Mumbai Indians vs Chennai Super Kings — Match 12',
            'team_a'        => 'Mumbai Indians',   'team_a_short' => 'MI',
            'team_b'        => 'Chennai Super Kings', 'team_b_short' => 'CSK',
            'team_a_logo'   => null, 'team_b_logo' => null,
            'format'        => 'T20',
            'venue'         => 'Wankhede Stadium, Mumbai',
            'start_time_ms' => $now + (4 * $hour),
            'lock_time_ms'  => $now + (4 * $hour),
            'scorecard_source_url' => null,
            'source_match_id'      => 1001,
        ],
        [
            'external_key'  => 'mock:1002',
            'series_name'   => 'T20 Internationals',
            'match_title'   => 'India vs Australia — 2nd T20I',
            'team_a'        => 'India',     'team_a_short' => 'IND',
            'team_b'        => 'Australia', 'team_b_short' => 'AUS',
            'team_a_logo'   => null, 'team_b_logo' => null,
            'format'        => 'T20',
            'venue'         => 'Narendra Modi Stadium, Ahmedabad',
            'start_time_ms' => $now + (27 * $hour),
            'lock_time_ms'  => $now + (27 * $hour),
            'scorecard_source_url' => null,
            'source_match_id'      => 1002,
        ],
    ]];
}

/**
 * A legal 22-man squad for a mock fixture: 11 per side, and a role spread that can actually satisfy
 * the composition rules (enough keepers, bowlers and all-rounders to build a valid XI from either
 * team). A mock squad that could not produce a legal team would make the builder untestable.
 */
function fantasy_mock_squad($sourceMatchId) {
    $sets = [
        1001 => ['Mumbai Indians' => 'MI', 'Chennai Super Kings' => 'CSK'],
        1002 => ['India' => 'IND', 'Australia' => 'AUS'],
    ];
    $teams = $sets[(int) $sourceMatchId] ?? $sets[1001];

    // 2 WK, 4 BAT, 2 ALL, 3 BOWL per side — comfortably inside every min/max.
    $template = [
        ['role' => 'WK',   'n' => 2],
        ['role' => 'BAT',  'n' => 4],
        ['role' => 'ALL',  'n' => 2],
        ['role' => 'BOWL', 'n' => 3],
    ];

    $players = [];
    $seq = 1;
    foreach ($teams as $teamName => $short) {
        foreach ($template as $spec) {
            for ($i = 1; $i <= $spec['n']; $i++) {
                $name = $short . ' ' . $spec['role'] . ' ' . $i;
                $players[] = [
                    'external_key' => 'mock:' . $sourceMatchId . ':' . $seq,
                    'name'         => $name,
                    'full_name'    => $name . ' (mock)',
                    'team_name'    => $teamName,
                    'role'         => $spec['role'],
                    // Spread across the band so the 100-credit cap is a real constraint rather
                    // than something every possible team trivially satisfies.
                    'credits'      => fantasy_clamp_credits(8.0 + (($seq % 6) * 0.5)),
                ];
                $seq++;
            }
        }
    }

    return ['ok' => true, 'error' => null, 'players' => $players];
}

/**
 * Deterministic match figures for a mock fixture's squad.
 *
 * Deterministic on purpose: the same fixture always produces the same scorecard, so a test can assert
 * an exact points total, and a developer watching the live worker sees numbers that stop moving once
 * the "match" is over rather than a jitter that makes real bugs hard to spot.
 *
 * The spread is chosen to exercise the interesting branches: a century, a fifty, a thirty, a duck, a
 * five-wicket haul, a three-wicket haul, maidens, each kind of fielding dismissal, and a player who
 * neither batted nor bowled.
 */
function fantasy_mock_scorecard($sourceMatchId) {
    $squad = fantasy_mock_squad($sourceMatchId);
    $players = [];
    $i = 0;

    foreach ($squad['players'] as $p) {
        $i++;
        $s = [
            'external_key' => $p['external_key'], 'source_id' => $i, 'name' => $p['name'],
            'runs' => 0, 'balls' => 0, 'fours' => 0, 'sixes' => 0, 'is_out' => 0, 'did_bat' => 0,
            'wickets' => 0, 'overs' => 0.0, 'maidens' => 0, 'runs_conceded' => 0, 'bowled_lbw' => 0,
            'catches' => 0, 'stumpings' => 0, 'runouts_direct' => 0, 'runouts_shared' => 0,
        ];

        switch ($i % 11) {
            case 1:  // century
                $s = array_merge($s, ['runs' => 104, 'balls' => 62, 'fours' => 9, 'sixes' => 6,
                                      'did_bat' => 1, 'is_out' => 1]);
                break;
            case 2:  // fifty, not out
                $s = array_merge($s, ['runs' => 57, 'balls' => 40, 'fours' => 5, 'sixes' => 2,
                                      'did_bat' => 1, 'is_out' => 0]);
                break;
            case 3:  // thirty
                $s = array_merge($s, ['runs' => 34, 'balls' => 25, 'fours' => 3, 'sixes' => 1,
                                      'did_bat' => 1, 'is_out' => 1]);
                break;
            case 4:  // duck
                $s = array_merge($s, ['runs' => 0, 'balls' => 3, 'did_bat' => 1, 'is_out' => 1]);
                break;
            case 5:  // five-wicket haul with a maiden
                $s = array_merge($s, ['wickets' => 5, 'overs' => 4.0, 'maidens' => 1,
                                      'runs_conceded' => 22, 'bowled_lbw' => 2]);
                break;
            case 6:  // three wickets
                $s = array_merge($s, ['wickets' => 3, 'overs' => 4.0, 'maidens' => 0,
                                      'runs_conceded' => 31, 'bowled_lbw' => 1]);
                break;
            case 7:  // keeper: catches and a stumping
                $s = array_merge($s, ['runs' => 12, 'balls' => 10, 'did_bat' => 1, 'is_out' => 1,
                                      'catches' => 2, 'stumpings' => 1]);
                break;
            case 8:  // run-outs
                $s = array_merge($s, ['runs' => 8, 'balls' => 7, 'did_bat' => 1, 'is_out' => 0,
                                      'runouts_direct' => 1, 'runouts_shared' => 1]);
                break;
            case 9:  // one wicket, below every haul tier
                $s = array_merge($s, ['wickets' => 1, 'overs' => 3.0, 'runs_conceded' => 28]);
                break;
            case 10: // neither batted nor bowled
                break;
            default: // a small contribution
                $s = array_merge($s, ['runs' => 19, 'balls' => 14, 'fours' => 2, 'did_bat' => 1,
                                      'is_out' => 1, 'catches' => 1]);
                break;
        }

        $players[$p['external_key']] = $s;
    }

    return ['ok' => true, 'error' => null, 'players' => $players,
            'state' => 'In Progress', 'status_text' => 'mock match in progress'];
}
