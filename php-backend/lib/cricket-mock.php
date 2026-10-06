<?php
/**
 * Mock cricket world — a deterministic, clock-driven stand-in for the Roanuz feed.
 *
 * =================================================================================================
 * WHY THIS EXISTS AND WHY IT IS SHAPED LIKE IT IS
 * =================================================================================================
 * Both cricket games are built before there is a paid Roanuz key. So that the whole product —
 * lobby, team builder, contests, live points, settlement, every Ball by Ball market — can be used
 * and tested end to end, this file simulates a never-ending cricket calendar:
 *
 *   - a new fictional T20 starts every CRICKET_MOCK_PERIOD_MIN minutes, anchored to the clock, so
 *     there is always one live, one about to start, and a few upcoming;
 *   - the toss and both playing XIs are announced CRICKET_MOCK_LINEUP_MIN minutes before the start
 *     (Dream11's "Lineups out" moment);
 *   - a delivery is bowled every CRICKET_MOCK_BALL_SECONDS seconds, with a short pause between overs
 *     and an innings break, and the chase stops the moment the target is reached.
 *
 * It is a PURE FUNCTION OF TIME. The match number and the clock decide everything; there is no
 * stored state. Ask for match 42's snapshot at 19:05:30 twice and the bytes are identical, which is
 * what makes the mock safe to drive from a cron, from a web request and from a test at once.
 *
 * Everything leaves this file in the SAME SHAPE the Roanuz adapter in cricket-feed.php reads
 * (snapshots, fixtures, squads), so the mock exercises the real parsing path rather than a shortcut
 * around it. Flipping CRICKET_SOURCE to roanuz changes where the payloads come from and nothing
 * downstream.
 *
 * The teams and players are fictional on purpose: simulated figures must never be presented as a
 * real person's performance.
 */

require_once __DIR__ . '/json.php';

function cricket_mock_conf() {
    return [
        'period_ms'   => max(30, (int) env_get('CRICKET_MOCK_PERIOD_MIN', 150)) * 60000,
        'lineup_ms'   => max(1,  (int) env_get('CRICKET_MOCK_LINEUP_MIN', 30)) * 60000,
        'ball_ms'     => max(3,  (int) env_get('CRICKET_MOCK_BALL_SECONDS', 25)) * 1000,
        'over_gap_ms' => max(0,  (int) env_get('CRICKET_MOCK_OVER_GAP_SECONDS', 15)) * 1000,
        'break_ms'    => max(1,  (int) env_get('CRICKET_MOCK_BREAK_MIN', 6)) * 60000,
        // Every Nth match is rained off after a few overs, to exercise the refund paths. 0 = never.
        'abandon_every' => max(0, (int) env_get('CRICKET_MOCK_ABANDON_EVERY', 0)),
        // A fixed epoch the calendar is counted from. Any value works; it only has to be stable.
        'anchor_ms'   => 1767225600000, // 2026-01-01T00:00:00Z
    ];
}

// -------------------------------------------------------------------------------------------------
// Deterministic randomness
// -------------------------------------------------------------------------------------------------

/** A tiny seeded PRNG (mulberry32), so the simulation never touches PHP's global mt_rand state. */
final class CricketMockRng {
    private $s;
    public function __construct($seed) { $this->s = ((int) $seed) & 0xFFFFFFFF; }
    public function next() {
        $this->s = ($this->s + 0x6D2B79F5) & 0xFFFFFFFF;
        $t = $this->s;
        $t = $this->imul($t ^ ($t >> 15), $t | 1) & 0xFFFFFFFF;
        $t ^= ($t + ($this->imul($t ^ ($t >> 7), $t | 61) & 0xFFFFFFFF)) & 0xFFFFFFFF;
        $t &= 0xFFFFFFFF;
        return (($t ^ ($t >> 14)) & 0xFFFFFFFF) / 4294967296.0;
    }
    private function imul($a, $b) {
        $a &= 0xFFFFFFFF; $b &= 0xFFFFFFFF;
        $ah = ($a >> 16) & 0xFFFF; $al = $a & 0xFFFF;
        $bh = ($b >> 16) & 0xFFFF; $bl = $b & 0xFFFF;
        return (($al * $bl) + ((((($ah * $bl) + ($al * $bh)) & 0xFFFF) << 16))) & 0xFFFFFFFF;
    }
    public function pick(array $weights) {
        $total = array_sum($weights);
        $r = $this->next() * $total;
        foreach ($weights as $k => $w) {
            if ($r < $w) return $k;
            $r -= $w;
        }
        return array_key_last($weights);
    }
    public function int($min, $max) { return $min + (int) floor($this->next() * ($max - $min + 1)); }
}

// -------------------------------------------------------------------------------------------------
// The fictional league
// -------------------------------------------------------------------------------------------------

function cricket_mock_teams() {
    return [
        ['key' => 'mk_mum', 'name' => 'Mumbai Mariners',      'code' => 'MUM'],
        ['key' => 'mk_che', 'name' => 'Chennai Cheetahs',     'code' => 'CHE'],
        ['key' => 'mk_del', 'name' => 'Delhi Dragons',        'code' => 'DEL'],
        ['key' => 'mk_kol', 'name' => 'Kolkata Comets',       'code' => 'KOL'],
        ['key' => 'mk_ben', 'name' => 'Bengaluru Blasters',   'code' => 'BEN'],
        ['key' => 'mk_hyd', 'name' => 'Hyderabad Hawks',      'code' => 'HYD'],
        ['key' => 'mk_jai', 'name' => 'Jaipur Jaguars',       'code' => 'JAI'],
        ['key' => 'mk_luc', 'name' => 'Lucknow Lions',        'code' => 'LUC'],
    ];
}

/**
 * A 16-man squad for a team: deterministic names, roles and skill ratings.
 *
 * Squad order is batting order for the first eleven: 2 openers, 3 middle order (one a keeper),
 * 2 all-rounders, 4 bowlers, then 5 reserves. Skill (0..1) drives both the simulation and the
 * credit model, so a strong player both scores more and costs more — which is what makes the
 * 100-credit cap a real decision.
 */
function cricket_mock_squad($teamKey) {
    static $cache = [];
    if (isset($cache[$teamKey])) return $cache[$teamKey];

    $first = ['Aarav', 'Vihaan', 'Ishaan', 'Kabir', 'Reyansh', 'Arjun', 'Dhruv', 'Kian', 'Rohan',
              'Yash', 'Aditya', 'Neel', 'Pranav', 'Siddharth', 'Tanmay', 'Varun', 'Kunal', 'Manav',
              'Nikhil', 'Om', 'Parth', 'Rudra', 'Samar', 'Tejas', 'Uday', 'Veer', 'Zayan', 'Harsh',
              'Jai', 'Laksh', 'Mihir', 'Ojas'];
    $last  = ['Rathore', 'Bhandari', 'Kulkarni', 'Menon', 'Saxena', 'Thakur', 'Iyer', 'Chauhan',
              'Deshpande', 'Gill', 'Hegde', 'Joshi', 'Kapoor', 'Malhotra', 'Nair', 'Pandey', 'Qureshi',
              'Rana', 'Sethi', 'Tiwari', 'Upadhyay', 'Verma', 'Wadhwa', 'Yadav', 'Zaveri', 'Bose',
              'Chopra', 'Dutta', 'Grewal', 'Khanna', 'Lamba', 'Mehra'];

    $layout = [
        ['BAT', 0.80], ['BAT', 0.72], ['BAT', 0.76], ['WK', 0.68], ['BAT', 0.62],
        ['ALL', 0.70], ['ALL', 0.58], ['BOWL', 0.78], ['BOWL', 0.70], ['BOWL', 0.64], ['BOWL', 0.60],
        // reserves
        ['BAT', 0.50], ['WK', 0.45], ['ALL', 0.48], ['BOWL', 0.55], ['BOWL', 0.42],
    ];

    $rng = new CricketMockRng(crc32('squad:' . $teamKey));
    $players = [];
    $used = [];
    foreach ($layout as $i => $spec) {
        do {
            $name = $first[$rng->int(0, count($first) - 1)] . ' ' . $last[$rng->int(0, count($last) - 1)];
        } while (isset($used[$name]));
        $used[$name] = true;
        $skill = max(0.3, min(0.95, $spec[1] + ($rng->next() - 0.5) * 0.12));
        $players[] = [
            'key'   => $teamKey . '_p' . str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT),
            'name'  => $name,
            'role'  => $spec[0],
            'skill' => round($skill, 3),
            'order' => $i + 1,
        ];
    }
    return $cache[$teamKey] = $players;
}

/** Roanuz's own vocabulary for a role, so the adapter's role mapping is exercised. */
function cricket_mock_roanuz_role($role) {
    return ['WK' => 'keeper', 'BAT' => 'batsman', 'ALL' => 'all_rounder', 'BOWL' => 'bowler'][$role] ?? 'batsman';
}

// -------------------------------------------------------------------------------------------------
// The calendar
// -------------------------------------------------------------------------------------------------

/** The match number whose slot contains $ms. */
function cricket_mock_slot_at($ms) {
    $c = cricket_mock_conf();
    return (int) floor(($ms - $c['anchor_ms']) / $c['period_ms']);
}

function cricket_mock_match_key($slot) { return 'mock_m' . (int) $slot; }

function cricket_mock_slot_from_key($key) {
    return preg_match('/^mock_m(-?\d+)$/', (string) $key, $m) ? (int) $m[1] : null;
}

/** Static facts about match $slot: teams, start time, venue. */
function cricket_mock_match_meta($slot) {
    $c = cricket_mock_conf();
    $teams = cricket_mock_teams();
    $n = count($teams);
    // Every pairing comes round, and a team never plays itself.
    $a = (($slot % $n) + $n) % $n;
    $b = ($a + 1 + ((int) floor($slot / $n) % ($n - 1)) + $n) % $n;
    if ($b === $a) $b = ($a + 1) % $n;
    $venues = ['Marine Drive Oval, Mumbai', 'Coastal Park, Chennai', 'Ring Road Ground, Delhi',
               'Riverside Arena, Kolkata', 'Garden City Stadium, Bengaluru', 'Pearl Ground, Hyderabad',
               'Pink City Oval, Jaipur', 'Nawab Park, Lucknow'];
    return [
        'slot'      => $slot,
        'key'       => cricket_mock_match_key($slot),
        'team_a'    => $teams[$a],
        'team_b'    => $teams[$b],
        'start_ms'  => $c['anchor_ms'] + $slot * $c['period_ms'] + 20 * 60000,
        'venue'     => $venues[$a],
        'title'     => $teams[$a]['name'] . ' vs ' . $teams[$b]['name'] . ' — Match ' . ($slot % 1000 + 1),
        'abandoned' => $c['abandon_every'] > 0 && ((($slot % $c['abandon_every']) + $c['abandon_every']) % $c['abandon_every']) === $c['abandon_every'] - 1,
    ];
}

/**
 * Roanuz-shaped fixtures: the live match (if any) and the next few.
 * Matches that finished more than an hour ago drop off, as they would from a fixtures endpoint.
 */
function cricket_mock_fixtures($nowMs = null) {
    $now = $nowMs ?? now_ms();
    $slot = cricket_mock_slot_at($now);
    $out = [];
    for ($s = $slot - 1; $s <= $slot + 4; $s++) {
        $meta = cricket_mock_match_meta($s);
        $sim = cricket_mock_simulate($s);
        if ($sim['ended_ms'] !== null && $sim['ended_ms'] < $now - 3600000) continue;
        $out[] = [
            'key'        => $meta['key'],
            'name'       => $meta['title'],
            'short_name' => $meta['team_a']['code'] . ' vs ' . $meta['team_b']['code'],
            'format'     => 't20',
            'start_at'   => (int) floor($meta['start_ms'] / 1000),
            'status'     => cricket_mock_status_at($s, $now)['status'],
            'teams'      => [
                'a' => ['key' => $meta['team_a']['key'], 'name' => $meta['team_a']['name'], 'code' => $meta['team_a']['code']],
                'b' => ['key' => $meta['team_b']['key'], 'name' => $meta['team_b']['name'], 'code' => $meta['team_b']['code']],
            ],
            'venue'      => ['name' => $meta['venue']],
            'tournament' => ['key' => 'mock_league_2026', 'name' => 'Bet1x Premier League 2026 (simulated)'],
        ];
    }
    return $out;
}

/** Roanuz-shaped tournament team (squad) for one team key. */
function cricket_mock_tournament_team($teamKey) {
    $players = [];
    foreach (cricket_mock_squad($teamKey) as $p) {
        $players[$p['key']] = [
            'key' => $p['key'], 'name' => $p['name'],
            'seasonal_role' => cricket_mock_roanuz_role($p['role']),
            'skill' => $p['skill'],
        ];
    }
    return ['players' => $players];
}

// -------------------------------------------------------------------------------------------------
// The simulation
// -------------------------------------------------------------------------------------------------

/** The eleven who play: the first eleven of the squad order. */
function cricket_mock_xi($teamKey) {
    return array_slice(cricket_mock_squad($teamKey), 0, 11);
}

/**
 * Simulate match $slot completely, once, with a timestamp on every delivery.
 *
 * Returns ['balls' => [...], 'toss' => [...], 'innings_order' => [...], 'ended_ms' => ?int,
 *          'result' => [...], 'innings_end_ms' => [...]].
 */
function cricket_mock_simulate($slot) {
    static $cache = [];
    if (isset($cache[$slot])) return $cache[$slot];

    $c = cricket_mock_conf();
    $meta = cricket_mock_match_meta($slot);
    $rng = new CricketMockRng(crc32('match:' . $slot));

    $tossWinner = $rng->next() < 0.5 ? 'a' : 'b';
    $elected = $rng->next() < 0.6 ? 'bowl' : 'bat';
    $firstBat = ($elected === 'bat') ? $tossWinner : ($tossWinner === 'a' ? 'b' : 'a');
    $secondBat = $firstBat === 'a' ? 'b' : 'a';
    $teamOf = ['a' => $meta['team_a'], 'b' => $meta['team_b']];

    $balls = [];
    $t = $meta['start_ms'];
    $index = 0;
    $target = null;
    $inningsTotals = [];
    $inningsEnd = [];
    $abandonAfter = $meta['abandoned'] ? 34 : null;   // deliveries bowled before the rain arrives
    $endedMs = null;

    foreach ([1 => $firstBat, 2 => $secondBat] as $inn => $side) {
        $bowlSide = $side === 'a' ? 'b' : 'a';
        $batters = cricket_mock_xi($teamOf[$side]['key']);
        $bowlersXi = cricket_mock_xi($teamOf[$bowlSide]['key']);
        // The five or six who bowl: bowlers and all-rounders, strongest first.
        $attack = array_values(array_filter($bowlersXi, function ($p) { return $p['role'] === 'BOWL' || $p['role'] === 'ALL'; }));
        $fielders = $bowlersXi;
        $keeper = null;
        foreach ($bowlersXi as $p) if ($p['role'] === 'WK') { $keeper = $p; break; }
        if (!$keeper) $keeper = $bowlersXi[3];

        $striker = 0; $nonStriker = 1; $nextBat = 2;
        $runs = 0; $wkts = 0; $legal = 0;
        $oversBy = [];
        $prevBowler = null;
        $over = 0;

        while ($over < 20 && $wkts < 10) {
            // Pick the over's bowler: not the previous over's, nobody past four overs.
            $choices = [];
            foreach ($attack as $i => $p) {
                if ($prevBowler === $i) continue;
                if (($oversBy[$i] ?? 0) >= 4) continue;
                $choices[$i] = 1 + $p['skill'] * 2;
            }
            if (!$choices) foreach ($attack as $i => $p) if ($prevBowler !== $i) $choices[$i] = 1;
            $bi = $rng->pick($choices);
            $bowler = $attack[$bi];
            $oversBy[$bi] = ($oversBy[$bi] ?? 0) + 1;
            $prevBowler = $bi;

            $legalInOver = 0;
            $overRuns = 0;
            while ($legalInOver < 6 && $wkts < 10) {
                if ($abandonAfter !== null && $index >= $abandonAfter) break 3;

                $bat = $batters[$striker];
                $phase = $over < 6 ? 'pp' : ($over < 15 ? 'mid' : 'death');
                $edge = $bat['skill'] - $bowler['skill'];
                $w = [
                    'dot' => 34 - $edge * 10 + ($phase === 'mid' ? 4 : 0),
                    '1'   => 33 + ($phase === 'mid' ? 4 : 0),
                    '2'   => 7,
                    '3'   => 1,
                    '4'   => 11 + $edge * 6 + ($phase === 'pp' ? 3 : 0) + ($phase === 'death' ? 2 : 0),
                    '6'   => 4.5 + $edge * 5 + ($phase === 'death' ? 4 : 0),
                    'W'   => 4.6 - $edge * 4 + ($phase === 'death' ? 1.5 : 0),
                    'wd'  => 3.2,
                    'nb'  => 0.6,
                    'lb'  => 1.8,
                    'b'   => 0.4,
                ];
                foreach ($w as $k => $v) $w[$k] = max(0.2, $v);
                $kind = $rng->pick($w);

                $legalBall = !in_array($kind, ['wd', 'nb'], true);
                $index++;
                $ball = [
                    'key' => $meta['key'] . '_' . $inn . '_' . $index,
                    'index' => $index,
                    'innings' => $side . '_1',
                    'batting_team' => $side,
                    'overs' => [$over, $legalBall ? $legalInOver + 1 : $legalInOver],
                    'ball_type' => 'normal',
                    'batsman' => ['player_key' => $bat['key'], 'runs' => 0, 'is_four' => false, 'is_six' => false, 'is_dot_ball' => false],
                    'non_striker' => ['player_key' => $batters[$nonStriker]['key']],
                    'bowler' => ['player_key' => $bowler['key'], 'runs' => 0, 'extras' => 0, 'is_wicket' => false],
                    'team_score' => ['runs' => 0, 'extras' => 0, 'is_wicket' => false],
                    'fielders' => [],
                    'wicket' => null,
                    'comment' => '',
                    '_ts' => $t,
                ];
                $batRuns = 0; $extras = 0; $wicket = null; $rotate = false;

                switch ($kind) {
                    case 'dot':
                        $ball['batsman']['is_dot_ball'] = true;
                        $ball['comment'] = $bowler['name'] . ' to ' . $bat['name'] . ', no run';
                        break;
                    case '1': case '2': case '3':
                        $batRuns = (int) $kind; $rotate = ($batRuns % 2) === 1;
                        $ball['comment'] = $bowler['name'] . ' to ' . $bat['name'] . ', ' . $batRuns . ' run' . ($batRuns > 1 ? 's' : '');
                        break;
                    case '4':
                        $batRuns = 4; $ball['batsman']['is_four'] = true;
                        $ball['comment'] = $bowler['name'] . ' to ' . $bat['name'] . ', FOUR!';
                        break;
                    case '6':
                        $batRuns = 6; $ball['batsman']['is_six'] = true;
                        $ball['comment'] = $bowler['name'] . ' to ' . $bat['name'] . ', SIX!';
                        break;
                    case 'wd':
                        $ball['ball_type'] = 'wide'; $extras = 1;
                        if ($rng->next() < 0.08) { $extras = 5; } // wide that runs away for four
                        $ball['comment'] = $bowler['name'] . ' to ' . $bat['name'] . ', wide' . ($extras > 1 ? ', and it runs away for four' : '');
                        break;
                    case 'nb':
                        $ball['ball_type'] = 'no_ball'; $extras = 1;
                        $batRuns = [0, 1, 4, 6][$rng->int(0, 3)];
                        if ($batRuns === 4) $ball['batsman']['is_four'] = true;
                        if ($batRuns === 6) $ball['batsman']['is_six'] = true;
                        $rotate = ($batRuns % 2) === 1;
                        $ball['comment'] = $bowler['name'] . ' to ' . $bat['name'] . ', no ball' . ($batRuns ? ', ' . $batRuns . ' off the bat' : '');
                        break;
                    case 'lb': case 'b':
                        $ball['ball_type'] = $kind === 'lb' ? 'leg_bye' : 'bye';
                        $extras = $rng->next() < 0.8 ? 1 : 4;
                        $rotate = ($extras % 2) === 1;
                        $ball['comment'] = $bowler['name'] . ' to ' . $bat['name'] . ', ' . $extras . ' ' . ($kind === 'lb' ? 'leg bye' : 'bye') . ($extras > 1 ? 's' : '');
                        break;
                    case 'W':
                        $how = $rng->pick(['caught' => 58, 'bowled' => 17, 'lbw' => 12, 'run_out' => 8, 'stumped' => 5]);
                        $outKey = $bat['key'];
                        $outName = $bat['name'];
                        if ($how === 'run_out') {
                            // A run out can fall at either end and usually comes with a completed run.
                            $batRuns = $rng->next() < 0.5 ? 1 : 0;
                            if ($rng->next() < 0.35) { $outKey = $batters[$nonStriker]['key']; $outName = $batters[$nonStriker]['name']; }
                            $thrower = $fielders[$rng->int(0, 10)];
                            if ($rng->next() < 0.5) {
                                $ball['fielders'][] = ['player_key' => $thrower['key'], 'is_run_out' => true, 'is_direct_run_out' => true];
                            } else {
                                $ball['fielders'][] = ['player_key' => $thrower['key'], 'is_run_out' => true, 'is_direct_run_out' => false];
                                $ball['fielders'][] = ['player_key' => $keeper['key'], 'is_run_out' => true, 'is_direct_run_out' => false];
                            }
                        } elseif ($how === 'caught') {
                            $catcher = $rng->next() < 0.18 ? $keeper : ($rng->next() < 0.06 ? $bowler : $fielders[$rng->int(0, 10)]);
                            $ball['fielders'][] = ['player_key' => $catcher['key'], 'is_catch' => true];
                        } elseif ($how === 'stumped') {
                            $ball['fielders'][] = ['player_key' => $keeper['key'], 'is_stumps' => true];
                        } else {
                            $ball['batsman']['is_dot_ball'] = true;
                        }
                        $wicket = ['player_key' => $outKey, 'kind' => $how];
                        $ball['bowler']['is_wicket'] = $how !== 'run_out';
                        $ball['team_score']['is_wicket'] = true;
                        $ball['comment'] = $bowler['name'] . ' to ' . $bat['name'] . ', OUT! ' . $outName . ' ' . str_replace('_', ' ', $how);
                        break;
                }

                $ball['batsman']['runs'] = $batRuns;
                $ball['team_score']['runs'] = $batRuns + $extras;
                $ball['team_score']['extras'] = $extras;
                $chargedExtras = in_array($ball['ball_type'], ['wide', 'no_ball'], true) ? $extras : 0;
                $ball['bowler']['runs'] = $batRuns + $chargedExtras;
                $ball['bowler']['extras'] = $chargedExtras;
                $ball['wicket'] = $wicket;
                $balls[] = $ball;

                $runs += $batRuns + $extras;
                $overRuns += $batRuns + $extras;
                if ($legalBall) { $legal++; $legalInOver++; }
                if ($rotate) { $tmp = $striker; $striker = $nonStriker; $nonStriker = $tmp; }

                if ($wicket) {
                    $wkts++;
                    if ($wkts < 10 && $nextBat < 11) {
                        if ($wicket['player_key'] === $batters[$striker]['key']) $striker = $nextBat;
                        else $nonStriker = $nextBat;
                        $nextBat++;
                    }
                }

                $t += $c['ball_ms'];
                if ($target !== null && $runs >= $target) break 2;
            }
            // End of over: batters change ends.
            $tmp = $striker; $striker = $nonStriker; $nonStriker = $tmp;
            $over++;
            $t += $c['over_gap_ms'];
        }

        $inningsTotals[$inn] = ['side' => $side, 'runs' => $runs, 'wickets' => $wkts, 'legal' => $legal];
        $inningsEnd[$inn] = $t;
        if ($inn === 1) {
            $target = $runs + 1;
            $t += $c['break_ms'];
        }
    }

    if ($abandonAfter !== null) {
        $endedMs = $t + 20 * 60000;
        $result = ['status' => 'abandoned', 'winner' => null, 'msg' => 'Match abandoned due to rain — no result'];
    } else {
        $endedMs = $t;
        $i1 = $inningsTotals[1]; $i2 = $inningsTotals[2] ?? ['runs' => 0, 'wickets' => 0, 'side' => $secondBat];
        if ($i2['runs'] > $i1['runs']) {
            $result = ['status' => 'completed', 'winner' => $i2['side'],
                       'msg' => $teamOf[$i2['side']]['name'] . ' won by ' . (10 - $i2['wickets']) . ' wickets'];
        } elseif ($i2['runs'] < $i1['runs']) {
            $result = ['status' => 'completed', 'winner' => $i1['side'],
                       'msg' => $teamOf[$i1['side']]['name'] . ' won by ' . ($i1['runs'] - $i2['runs']) . ' runs'];
        } else {
            $result = ['status' => 'completed', 'winner' => null, 'msg' => 'Match tied'];
        }
    }

    return $cache[$slot] = [
        'meta' => $meta,
        'balls' => $balls,
        'toss' => ['winner' => $tossWinner, 'elected' => $elected],
        'innings_order' => [$firstBat . '_1', $secondBat . '_1'],
        'innings_end_ms' => $inningsEnd,
        'ended_ms' => $endedMs,
        'result' => $result,
    ];
}

/** Where match $slot stands at $ms, in Roanuz's status vocabulary. */
function cricket_mock_status_at($slot, $ms) {
    $c = cricket_mock_conf();
    $sim = cricket_mock_simulate($slot);
    $start = $sim['meta']['start_ms'];
    if ($ms < $start - $c['lineup_ms']) return ['status' => 'not_started', 'play_status' => 'scheduled'];
    if ($ms < $start) return ['status' => 'not_started', 'play_status' => 'pre_match'];
    if ($ms >= $sim['ended_ms']) {
        return $sim['result']['status'] === 'abandoned'
            ? ['status' => 'completed', 'play_status' => 'abandoned']
            : ['status' => 'completed', 'play_status' => 'result'];
    }
    if (isset($sim['innings_end_ms'][1]) && $ms >= $sim['innings_end_ms'][1]
        && $ms < $sim['innings_end_ms'][1] + $c['break_ms'] && $sim['result']['status'] !== 'abandoned') {
        return ['status' => 'started', 'play_status' => 'innings_break'];
    }
    if ($sim['result']['status'] === 'abandoned') {
        $last = end($sim['balls']);
        if ($last && $ms > $last['_ts'] + 60000) return ['status' => 'started', 'play_status' => 'rain_delay'];
    }
    return ['status' => 'started', 'play_status' => 'in_play'];
}

/**
 * The Roanuz-shaped match snapshot for match $key as it stood at $ms — exactly what a push
 * delivery would carry at that moment. Returns null for a key that is not a mock match.
 */
function cricket_mock_snapshot($key, $ms = null) {
    $slot = cricket_mock_slot_from_key($key);
    if ($slot === null) return null;
    $now = $ms ?? now_ms();
    $c = cricket_mock_conf();
    $sim = cricket_mock_simulate($slot);
    $meta = $sim['meta'];
    $status = cricket_mock_status_at($slot, $now);

    $lineupsOut = $now >= $meta['start_ms'] - $c['lineup_ms'];
    $players = [];
    $squad = [];
    foreach (['a' => $meta['team_a'], 'b' => $meta['team_b']] as $side => $team) {
        $keys = [];
        foreach (cricket_mock_squad($team['key']) as $p) {
            $players[$p['key']] = ['player' => ['key' => $p['key'], 'name' => $p['name'],
                                   'seasonal_role' => cricket_mock_roanuz_role($p['role'])]];
            $keys[] = $p['key'];
        }
        $squad[$side] = ['player_keys' => $keys];
        if ($lineupsOut) {
            $squad[$side]['playing_xi'] = array_map(function ($p) { return $p['key']; }, cricket_mock_xi($team['key']));
        }
    }

    $balls = [];
    foreach ($sim['balls'] as $b) {
        if ($b['_ts'] > $now) break;
        // The simulated moment the ball was bowled, in the field a provider would stamp it with.
        $b['timestamp'] = (int) floor($b['_ts'] / 1000);
        unset($b['_ts']);
        $balls[] = $b;
    }

    $innings = [];
    foreach ($balls as $b) {
        $k = $b['innings'];
        if (!isset($innings[$k])) $innings[$k] = ['runs' => 0, 'wickets' => 0, 'balls' => 0];
        $innings[$k]['runs'] += $b['team_score']['runs'];
        if ($b['team_score']['is_wicket']) $innings[$k]['wickets']++;
        if (!in_array($b['ball_type'], ['wide', 'no_ball'], true)) $innings[$k]['balls']++;
    }
    $inningsOut = [];
    foreach ($innings as $k => $s) {
        $inningsOut[$k] = ['index' => $k, 'score' => ['runs' => $s['runs'], 'wickets' => $s['wickets'], 'balls' => $s['balls']],
                           'overs' => [intdiv($s['balls'], 6), $s['balls'] % 6]];
    }

    $done = $status['status'] === 'completed';
    return [
        'key'        => $meta['key'],
        'name'       => $meta['title'],
        'short_name' => $meta['team_a']['code'] . ' vs ' . $meta['team_b']['code'],
        'format'     => 't20',
        'start_at'   => (int) floor($meta['start_ms'] / 1000),
        'status'     => $status['status'],
        'play_status' => $status['play_status'],
        'teams'      => [
            'a' => ['key' => $meta['team_a']['key'], 'name' => $meta['team_a']['name'], 'code' => $meta['team_a']['code']],
            'b' => ['key' => $meta['team_b']['key'], 'name' => $meta['team_b']['name'], 'code' => $meta['team_b']['code']],
        ],
        'venue'      => ['name' => $meta['venue']],
        'toss'       => $lineupsOut ? $sim['toss'] : null,
        'squad'      => $squad,
        'players'    => $players,
        'play'       => [
            'innings_order' => $sim['innings_order'],
            'innings'       => $inningsOut,
            'target'        => isset($sim['innings_end_ms'][1]) && $now >= $sim['innings_end_ms'][1] && isset($inningsOut[$sim['innings_order'][0]])
                                 ? $inningsOut[$sim['innings_order'][0]]['score']['runs'] + 1 : null,
            'result'        => $done ? $sim['result'] : null,
        ],
        'related_balls' => $balls,
        'generated_at' => (int) floor($now / 1000),
    ];
}

/** Every mock match that is worth ingesting at $ms: lineups out and not yet long finished. */
function cricket_mock_active_keys($ms = null) {
    $now = $ms ?? now_ms();
    $c = cricket_mock_conf();
    $slot = cricket_mock_slot_at($now);
    $keys = [];
    for ($s = $slot - 1; $s <= $slot + 1; $s++) {
        $sim = cricket_mock_simulate($s);
        $start = $sim['meta']['start_ms'];
        if ($now < $start - $c['lineup_ms']) continue;
        if ($now > $sim['ended_ms'] + 10 * 60000) continue;
        $keys[] = cricket_mock_match_key($s);
    }
    return $keys;
}
