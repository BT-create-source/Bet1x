<?php
/**
 * Live Cricket Predictor - Core Engine (Unified Backend)
 */

define('OVERS_PER_INNINGS', 10);
define('STARTING_COINS', 1000);
define('MIN_STAKE', 10);

function getTeams(): array {
    return [
        'A' => [
            'key' => 'A', 'name' => 'Coastal Sharks',
            'batsmen' => ['Aditya Rane', 'Kunal Bhatt', 'Ishaan Rao', 'Vivaan Kapoor', 'Rehan Qureshi', 'Omkar Desai'],
            'bowlers' => ['Suresh Nair', 'Farhan Sheikh', 'Devansh Joshi', 'Aman Trivedi'],
        ],
        'B' => [
            'key' => 'B', 'name' => 'Highland Eagles',
            'batsmen' => ['Yuvaan Oberoi', 'Karan Bedi', 'Manav Chandra', 'Rajveer Sandhu', 'Tarun Dutta', 'Dhruv Asthana'],
            'bowlers' => ['Harshad Solanki', 'Nikhil Bora', 'Prithvi Ghosh', 'Sameer Katyal'],
        ],
    ];
}

function getFeaturedMarkets(): array {
    $teams = getTeams();
    $markets = [
        ['key' => 'winner_A', 'type' => 'winner', 'team' => 'A', 'label' => $teams['A']['name'] . ' jeetegi', 'odds' => 1.9],
        ['key' => 'winner_B', 'type' => 'winner', 'team' => 'B', 'label' => $teams['B']['name'] . ' jeetegi', 'odds' => 1.9],
    ];
    foreach ($teams as $tk => $team) {
        foreach (array_slice($team['batsmen'], 0, 3) as $bat) {
            $markets[] = ['key' => "bat50_{$tk}_" . slug($bat), 'type' => 'runs50', 'player' => $bat, 'team' => $tk, 'label' => "$bat: 50+ runs banayega", 'odds' => 4.5];
            $markets[] = ['key' => "bat100_{$tk}_" . slug($bat), 'type' => 'runs100', 'player' => $bat, 'team' => $tk, 'label' => "$bat: 100+ runs banayega (century)", 'odds' => 12.0];
            $markets[] = ['key' => "six_{$tk}_" . slug($bat), 'type' => 'six', 'player' => $bat, 'team' => $tk, 'label' => "$bat: kam se kam 1 six lagayega", 'odds' => 1.7];
            $markets[] = ['key' => "four3_{$tk}_" . slug($bat), 'type' => 'four3', 'player' => $bat, 'team' => $tk, 'label' => "$bat: 3+ fours lagayega", 'odds' => 2.2];
        }
        foreach (array_slice($team['bowlers'], 0, 2) as $bowl) {
            $markets[] = ['key' => "wkt2_{$tk}_" . slug($bowl), 'type' => 'wkt2', 'player' => $bowl, 'team' => $tk, 'label' => "$bowl: 2+ wickets lega", 'odds' => 2.6];
            $markets[] = ['key' => "maiden_{$tk}_" . slug($bowl), 'type' => 'maiden', 'player' => $bowl, 'team' => $tk, 'label' => "$bowl: ek maiden over dalega", 'odds' => 3.2];
        }
    }
    return $markets;
}

function slug(string $s): string { return preg_replace('/[^a-z0-9]/', '', strtolower($s)); }

function createNewMatch(): array {
    $teams = getTeams();
    $tossWinner = (mt_rand(0, 1) === 0) ? 'A' : 'B';
    $battingFirst = $tossWinner;
    $bowlingFirst = ($battingFirst === 'A') ? 'B' : 'A';

    return [
        'status' => 'betting',
        'teams' => $teams,
        'toss_winner' => $tossWinner,
        'innings' => 1,
        'batting_team' => $battingFirst,
        'bowling_team' => $bowlingFirst,
        'first_innings_key' => $battingFirst,
        'second_innings_key' => $bowlingFirst,
        'target' => null,
        'innings1' => null,
        'innings2' => null,
        'cur' => null,
        'commentary' => ["Toss: {$teams[$tossWinner]['name']} ne toss jeeta aur batting choose ki."],
        'winner' => null,
        'markets' => getFeaturedMarkets(),
        'bets' => [],
    ];
}

function initInningsState(array $match, string $battingKey): array {
    $teams = $match['teams'];
    $bat = $teams[$battingKey]['batsmen'];
    $bowlKey = ($battingKey === 'A') ? 'B' : 'A';
    $bowlers = $teams[$bowlKey]['bowlers'];

    $batsmenStats = [];
    foreach ($bat as $name) $batsmenStats[$name] = ['runs' => 0, 'balls' => 0, 'fours' => 0, 'sixes' => 0, 'out' => false, 'batted' => false];
    $bowlerStats = [];
    foreach ($bowlers as $name) $bowlerStats[$name] = ['overs' => 0, 'balls_this_over' => 0, 'runs' => 0, 'runs_this_over' => 0, 'wickets' => 0, 'maidens' => 0];

    return [
        'batting_team' => $battingKey,
        'batting_order' => $bat,
        'bowlers_order' => $bowlers,
        'striker_idx' => 0, 'non_striker_idx' => 1, 'next_batsman_idx' => 2,
        'batsmen_stats' => $batsmenStats,
        'bowler_stats' => $bowlerStats,
        'bowler_cycle' => 0,
        'score' => 0, 'wickets' => 0, 'overs_completed' => 0,
        'complete' => false,
    ];
}

function findMarket(array $match, string $key): ?array {
    foreach ($match['markets'] as $m) {
        if ($m['key'] === $key) return $m;
    }
    return null;
}

function addCommentary(array &$match, string $line): void {
    $match['commentary'][] = $line;
    if (count($match['commentary']) > 30) array_shift($match['commentary']);
}

function simulateOneOver(array &$match): void {
    $cur = &$match['cur'];
    if (!$cur || $cur['complete']) return;

    $bowlerName = $cur['bowlers_order'][$cur['bowler_cycle'] % count($cur['bowlers_order'])];
    $cur['bowler_stats'][$bowlerName]['runs_this_over'] = 0;
    $cur['bowler_stats'][$bowlerName]['balls_this_over'] = 0;

    $overRuns = 0;
    $overWkts = 0;
    $outcomes = [];

    for ($b = 1; $b <= 6; $b++) {
        if ($cur['wickets'] >= 10) break;
        if ($match['target'] !== null && $cur['score'] >= $match['target']) break;

        $strikerName = $cur['batting_order'][$cur['striker_idx']] ?? null;
        if (!$strikerName) break;

        $cur['batsmen_stats'][$strikerName]['batted'] = true;
        $cur['batsmen_stats'][$strikerName]['balls']++;

        $roll = mt_rand(1, 100);
        if ($roll <= 12) {
            $cur['wickets']++;
            $overWkts++;
            $cur['batsmen_stats'][$strikerName]['out'] = true;
            $cur['bowler_stats'][$bowlerName]['wickets']++;
            $outcomes[] = 'W';

            addCommentary($match, "WICKET! $strikerName ($bowlerName b $strikerName) - {$cur['batsmen_stats'][$strikerName]['runs']} runs pe out!");

            if ($cur['next_batsman_idx'] < count($cur['batting_order'])) {
                $cur['striker_idx'] = $cur['next_batsman_idx'];
                $cur['next_batsman_idx']++;
            } else {
                $cur['striker_idx'] = -1;
            }
        } else {
            $runsMap = [13=>0, 14=>0, 15=>0, 16=>0, 17=>1, 18=>1, 19=>1, 20=>1, 21=>2, 22=>2, 23=>3, 24=>4, 25=>4, 26=>6];
            $r = 0;
            if ($roll <= 35) $r = 0;
            elseif ($roll <= 65) $r = 1;
            elseif ($roll <= 80) $r = 2;
            elseif ($roll <= 92) $r = 4;
            else $r = 6;

            $cur['score'] += $r;
            $overRuns += $r;
            $cur['batsmen_stats'][$strikerName]['runs'] += $r;
            if ($r === 4) $cur['batsmen_stats'][$strikerName]['fours']++;
            if ($r === 6) $cur['batsmen_stats'][$strikerName]['sixes']++;
            $outcomes[] = (string)$r;

            if ($r % 2 === 1) {
                $tmp = $cur['striker_idx'];
                $cur['striker_idx'] = $cur['non_striker_idx'];
                $cur['non_striker_idx'] = $tmp;
            }
        }
    }

    $cur['overs_completed']++;
    $cur['bowler_stats'][$bowlerName]['overs']++;
    $cur['bowler_stats'][$bowlerName]['runs'] += $overRuns;
    if ($overRuns === 0 && count($outcomes) === 6) {
        $cur['bowler_stats'][$bowlerName]['maidens']++;
    }
    $cur['bowler_cycle']++;

    $tmp = $cur['striker_idx'];
    $cur['striker_idx'] = $cur['non_striker_idx'];
    $cur['non_striker_idx'] = $tmp;

    $outStr = implode(' ', $outcomes);
    addCommentary($match, "Over {$cur['overs_completed']}: $outStr | Runs: $overRuns, Wkts: $overWkts | Total: {$cur['score']}/{$cur['wickets']}");

    if ($cur['wickets'] >= 10 || $cur['overs_completed'] >= OVERS_PER_INNINGS || ($match['target'] !== null && $cur['score'] >= $match['target'])) {
        $cur['complete'] = true;
        finishInnings($match);
    }

    settleBets($match);
}

function finishInnings(array &$match): void {
    if ($match['innings'] === 1) {
        $match['innings1'] = $match['cur'];
        $match['target'] = $match['cur']['score'] + 1;
        addCommentary($match, "Innings 1 Khatam! Target: {$match['target']} runs.");
        $match['innings'] = 2;
        $match['batting_team'] = $match['second_innings_key'];
        $match['bowling_team'] = $match['first_innings_key'];
        $match['cur'] = initInningsState($match, $match['batting_team']);
    } else {
        $match['innings2'] = $match['cur'];
        $match['status'] = 'finished';
        $s1 = $match['innings1']['score'];
        $s2 = $match['innings2']['score'];
        $t1 = $match['teams'][$match['first_innings_key']]['name'];
        $t2 = $match['teams'][$match['second_innings_key']]['name'];

        if ($s2 >= $match['target']) {
            $match['winner'] = $match['second_innings_key'];
            $wktsLeft = 10 - $match['innings2']['wickets'];
            addCommentary($match, "MATCH KHATAM! $t2 ne $wktsLeft wickets se jeet hasil ki!");
        } elseif ($s1 > $s2) {
            $match['winner'] = $match['first_innings_key'];
            $margin = $s1 - $s2;
            addCommentary($match, "MATCH KHATAM! $t1 ne $margin runs se match jeet liya!");
        } else {
            $match['winner'] = 'tie';
            addCommentary($match, "MATCH TIE HO GAYA!");
        }
        settleBets($match);
    }
}

function settleBets(array &$match): void {
    $finished = ($match['status'] === 'finished');

    foreach ($match['bets'] as &$bet) {
        if ($bet['settled']) continue;
        $mKey = $bet['market_key'];
        $m = findMarket($match, $mKey);
        if (!$m) continue;

        $type = $m['type'];

        if ($type === 'winner' && $finished) {
            $bet['settled'] = true;
            $bet['result'] = ($match['winner'] === $m['team']);
            $bet['payout'] = $bet['result'] ? round($bet['stake'] * $bet['odds'], 2) : 0;
            continue;
        }

        $allStats = getAllPlayerStats($match, $m['player'] ?? '');
        if (!$allStats) continue;

        if ($type === 'runs50') {
            if ($allStats['runs'] >= 50) {
                $bet['settled'] = true; $bet['result'] = true; $bet['payout'] = round($bet['stake'] * $bet['odds'], 2);
            } elseif ($allStats['out'] || $finished) {
                $bet['settled'] = true; $bet['result'] = false; $bet['payout'] = 0;
            }
        } elseif ($type === 'runs100') {
            if ($allStats['runs'] >= 100) {
                $bet['settled'] = true; $bet['result'] = true; $bet['payout'] = round($bet['stake'] * $bet['odds'], 2);
            } elseif ($allStats['out'] || $finished) {
                $bet['settled'] = true; $bet['result'] = false; $bet['payout'] = 0;
            }
        } elseif ($type === 'six') {
            if ($allStats['sixes'] >= 1) {
                $bet['settled'] = true; $bet['result'] = true; $bet['payout'] = round($bet['stake'] * $bet['odds'], 2);
            } elseif ($allStats['out'] || $finished) {
                $bet['settled'] = true; $bet['result'] = false; $bet['payout'] = 0;
            }
        } elseif ($type === 'four3') {
            if ($allStats['fours'] >= 3) {
                $bet['settled'] = true; $bet['result'] = true; $bet['payout'] = round($bet['stake'] * $bet['odds'], 2);
            } elseif ($allStats['out'] || $finished) {
                $bet['settled'] = true; $bet['result'] = false; $bet['payout'] = 0;
            }
        } elseif ($type === 'wkt2') {
            if (($allStats['wickets'] ?? 0) >= 2) {
                $bet['settled'] = true; $bet['result'] = true; $bet['payout'] = round($bet['stake'] * $bet['odds'], 2);
            } elseif ($finished) {
                $bet['settled'] = true; $bet['result'] = false; $bet['payout'] = 0;
            }
        } elseif ($type === 'maiden') {
            if (($allStats['maidens'] ?? 0) >= 1) {
                $bet['settled'] = true; $bet['result'] = true; $bet['payout'] = round($bet['stake'] * $bet['odds'], 2);
            } elseif ($finished) {
                $bet['settled'] = true; $bet['result'] = false; $bet['payout'] = 0;
            }
        }
    }
}

function getAllPlayerStats(array $match, string $pname): ?array {
    $innList = [$match['cur'], $match['innings1'], $match['innings2']];
    foreach ($innList as $inn) {
        if (!$inn) continue;
        if (isset($inn['batsmen_stats'][$pname])) return $inn['batsmen_stats'][$pname];
        if (isset($inn['bowler_stats'][$pname])) return $inn['bowler_stats'][$pname];
    }
    return null;
}
