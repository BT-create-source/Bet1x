<?php
/**
 * Live Cricket Predictor - Core Engine
 * Fictional live T10 match jisme user virtual COINS laga kar predictions karta hai:
 * match winner, batsman milestones (50/100/six/fours), bowler milestones (wickets/maiden).
 * NOTE: Ye sirf VIRTUAL COINS hain - real money nahi. Koi deposit/withdraw/cash-out nahi hai.
 */

define('OVERS_PER_INNINGS', 10);
define('STARTING_COINS', 1000);
define('MIN_STAKE', 10);

// ---------- Fictional Rosters ----------
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

// Featured players jinpar prediction markets banenge (top-3 batsmen + top-2 bowlers har team se)
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

// ---------- Match Creation ----------
function createNewMatch(): array {
    $teams = getTeams();
    $tossWinner = (mt_rand(0, 1) === 0) ? 'A' : 'B';
    $battingFirst = $tossWinner; // toss winner bat pehle karta hai (simplified)
    $bowlingFirst = ($battingFirst === 'A') ? 'B' : 'A';

    return [
        'status' => 'betting', // betting -> live -> finished
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
        'cur' => null, // current innings live-state
        'commentary' => ["Toss: {$teams[$tossWinner]['name']} ne toss jeeta aur batting choose ki."],
        'winner' => null,
        'markets' => getFeaturedMarkets(),
        'bets' => [], // list of ['market_key','stake','odds','settled','result','payout']
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

// ---------- Ball-by-ball Simulation ----------
function simulateOneOver(array &$match): void {
    if ($match['status'] !== 'live' || $match['cur']['complete']) return;
    $cur = &$match['cur'];
    $bowlerName = $cur['bowlers_order'][$cur['bowler_cycle'] % count($cur['bowlers_order'])];
    $cur['bowler_stats'][$bowlerName]['runs_this_over'] = 0;
    $cur['bowler_stats'][$bowlerName]['balls_this_over'] = 0;

    $overNum = $cur['overs_completed'] + 1;
    addCommentary($match, "--- Over $overNum: $bowlerName bowl karega ---");

    for ($ball = 1; $ball <= 6; $ball++) {
        if ($cur['complete']) break;
        simulateBall($match, $bowlerName);
        if ($cur['complete']) break;
    }

    if (!$cur['complete']) {
        $cur['overs_completed']++;
        $bs = &$cur['bowler_stats'][$bowlerName];
        $bs['overs']++;
        if ($bs['runs_this_over'] === 0) {
            $bs['maidens']++;
            addCommentary($match, "$bowlerName ne maiden over dala! 🎯");
        }
        unset($bs);
        // over end par strike swap hoti hai
        $tmp = $cur['striker_idx'];
        $cur['striker_idx'] = $cur['non_striker_idx'];
        $cur['non_striker_idx'] = $tmp;
        $cur['bowler_cycle']++;

        if ($cur['overs_completed'] >= OVERS_PER_INNINGS) {
            $cur['complete'] = true;
            addCommentary($match, "Innings khatam - overs poore ho gaye. Score: {$cur['score']}/{$cur['wickets']}");
        }
    }

    if (!$cur['complete']) checkInningsEnd($match);
    if ($cur['complete']) advanceMatchState($match);
}

function simulateBall(array &$match, string $bowlerName): void {
    $cur = &$match['cur'];
    $strikerName = $cur['batting_order'][$cur['striker_idx']];
    $bs = &$cur['batsmen_stats'][$strikerName];
    $bw = &$cur['bowler_stats'][$bowlerName];
    $bs['batted'] = true;

    $roll = mt_rand(1, 100);
    if ($roll <= 35) $outcome = 0;
    elseif ($roll <= 60) $outcome = 1;
    elseif ($roll <= 70) $outcome = 2;
    elseif ($roll <= 72) $outcome = 3;
    elseif ($roll <= 87) $outcome = 4;
    elseif ($roll <= 95) $outcome = 6;
    else $outcome = -1; // wicket

    $bs['balls']++;
    $bw['balls_this_over']++;

    if ($outcome === -1) {
        $bs['out'] = true;
        $cur['wickets']++;
        $bw['wickets']++;
        addCommentary($match, "OUT! $strikerName out ho gaya. ({$bs['runs']} runs)");
        if ($cur['wickets'] >= 10 || $cur['next_batsman_idx'] >= count($cur['batting_order'])) {
            $cur['complete'] = true;
            addCommentary($match, "Sab out! Innings khatam. Score: {$cur['score']}/{$cur['wickets']}");
        } else {
            $cur['striker_idx'] = $cur['next_batsman_idx'];
            $cur['next_batsman_idx']++;
            addCommentary($match, "{$cur['batting_order'][$cur['striker_idx']]} batting ke liye aaya.");
        }
    } else {
        $bs['runs'] += $outcome;
        $cur['score'] += $outcome;
        $bw['runs'] += $outcome;
        $bw['runs_this_over'] += $outcome;
        if ($outcome === 4) { $bs['fours']++; addCommentary($match, "FOUR! $strikerName ne boundary lagayi. ({$bs['runs']})"); }
        elseif ($outcome === 6) { $bs['sixes']++; addCommentary($match, "SIX! $strikerName ne maidan ke bahar maara! ({$bs['runs']})"); }
        elseif ($outcome === 0) { /* dot ball, no commentary spam */ }
        else { addCommentary($match, "$strikerName ne $outcome run liya. ({$bs['runs']})"); }

        if ($bs['runs'] >= 100 && ($bs['runs'] - $outcome) < 100) addCommentary($match, "🏆 CENTURY! $strikerName ne 100 pura kiya!");
        elseif ($bs['runs'] >= 50 && ($bs['runs'] - $outcome) < 50) addCommentary($match, "🎉 FIFTY! $strikerName ne 50 pura kiya!");

        if ($outcome % 2 === 1) {
            $tmp = $cur['striker_idx']; $cur['striker_idx'] = $cur['non_striker_idx']; $cur['non_striker_idx'] = $tmp;
        }
    }
    unset($bs, $bw);

    // 2nd innings me target chase ho gaya to turant rok do
    if ($match['innings'] === 2 && $cur['score'] >= $match['target']) {
        $cur['complete'] = true;
        addCommentary($match, "Target chase ho gaya! {$match['teams'][$cur['batting_team']]['name']} jeet gayi!");
    }
}

function checkInningsEnd(array &$match): void {
    $cur = &$match['cur'];
    if ($cur['wickets'] >= 10) $cur['complete'] = true;
    if ($match['innings'] === 2 && $cur['score'] >= $match['target']) $cur['complete'] = true;
}

function advanceMatchState(array &$match): void {
    if ($match['innings'] === 1) {
        $match['innings1'] = $match['cur'];
        $match['target'] = $match['cur']['score'] + 1;
        $match['innings'] = 2;
        $nextBatting = $match['second_innings_key'];
        addCommentary($match, "--- Innings 2 shuru: {$match['teams'][$nextBatting]['name']} ko target hai {$match['target']} ---");
        $match['cur'] = initInningsState($match, $nextBatting);
        $match['batting_team'] = $nextBatting;
        $match['bowling_team'] = $match['first_innings_key'];
    } else {
        $match['innings2'] = $match['cur'];
        finalizeMatch($match);
    }
}

function finalizeMatch(array &$match): void {
    $score1 = $match['innings1']['score'];
    $score2 = $match['innings2']['score'];
    if ($score2 > $score1) $winner = $match['second_innings_key'];
    elseif ($score1 > $score2) $winner = $match['first_innings_key'];
    else $winner = 'tie';
    $match['winner'] = $winner;
    $match['status'] = 'finished';

    if ($winner === 'tie') {
        addCommentary($match, "Match TIE ho gaya! $score1 - $score2");
    } else {
        addCommentary($match, "MATCH KHATAM! {$match['teams'][$winner]['name']} jeet gayi! ($score1 vs $score2)");
    }
    settleAllBets($match);
}

function addCommentary(array &$match, string $line): void {
    $match['commentary'][] = $line;
    if (count($match['commentary']) > 60) array_shift($match['commentary']);
}

// ---------- Betting ----------
function findMarket(array $match, string $key): ?array {
    foreach ($match['markets'] as $m) if ($m['key'] === $key) return $m;
    return null;
}

function getPlayerFinalStats(array $match, string $team, string $player): ?array {
    $innings = ($match['innings1']['batting_team'] ?? null) === $team ? $match['innings1'] : (($match['innings2']['batting_team'] ?? null) === $team ? $match['innings2'] : null);
    if ($innings && isset($innings['batsmen_stats'][$player])) return ['type' => 'bat', 'stats' => $innings['batsmen_stats'][$player]];
    $bowlInnings = ($match['innings1']['batting_team'] ?? null) !== $team ? $match['innings1'] : $match['innings2'];
    if ($bowlInnings && isset($bowlInnings['bowler_stats'][$player])) return ['type' => 'bowl', 'stats' => $bowlInnings['bowler_stats'][$player]];
    return null;
}

function settleAllBets(array &$match): void {
    foreach ($match['bets'] as &$bet) {
        if ($bet['settled']) continue;
        $market = findMarket($match, $bet['market_key']);
        $won = false; $push = false;

        if ($market['type'] === 'winner') {
            if ($match['winner'] === 'tie') $push = true;
            else $won = ($market['team'] === $match['winner']);
        } else {
            $info = getPlayerFinalStats($match, $market['team'], $market['player']);
            $stats = $info['stats'] ?? null;
            if ($stats) {
                switch ($market['type']) {
                    case 'runs50': $won = $stats['runs'] >= 50; break;
                    case 'runs100': $won = $stats['runs'] >= 100; break;
                    case 'six': $won = $stats['sixes'] >= 1; break;
                    case 'four3': $won = $stats['fours'] >= 3; break;
                    case 'wkt2': $won = $stats['wickets'] >= 2; break;
                    case 'maiden': $won = $stats['maidens'] >= 1; break;
                }
            }
        }

        if ($push) {
            $bet['result'] = 'push'; $bet['payout'] = $bet['stake'];
        } elseif ($won) {
            $bet['result'] = 'won'; $bet['payout'] = round($bet['stake'] * $bet['odds'], 1);
        } else {
            $bet['result'] = 'lost'; $bet['payout'] = 0;
        }
        $bet['settled'] = true;
    }
    unset($bet);
}