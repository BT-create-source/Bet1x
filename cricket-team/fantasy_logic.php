<?php
/**
 * Fantasy Cricket - Core Logic
 * Player pool, team-validation rules, match-simulation aur Dream11-style scoring.
 * (Sab players/teams FICTIONAL hain, kisi real cricketer se koi lena dena nahi.)
 */

define('TOTAL_CREDITS', 100.0);
define('SQUAD_SIZE', 11);

// ---------- Fictional Player Pool (2 teams x 11 players) ----------
function getPlayerPool(): array {
    return [
        // ---- Coastal Sharks ----
        ['id' => 1,  'name' => 'Vivaan Kapoor',   'team' => 'Coastal Sharks',  'role' => 'WK',   'credits' => 9.0],
        ['id' => 2,  'name' => 'Aditya Rane',      'team' => 'Coastal Sharks',  'role' => 'BAT',  'credits' => 10.0],
        ['id' => 3,  'name' => 'Kunal Bhatt',      'team' => 'Coastal Sharks',  'role' => 'BAT',  'credits' => 9.5],
        ['id' => 4,  'name' => 'Ishaan Rao',       'team' => 'Coastal Sharks',  'role' => 'BAT',  'credits' => 8.5],
        ['id' => 5,  'name' => 'Rehan Qureshi',    'team' => 'Coastal Sharks',  'role' => 'AR',   'credits' => 9.5],
        ['id' => 6,  'name' => 'Naveen Pillai',    'team' => 'Coastal Sharks',  'role' => 'AR',   'credits' => 8.0],
        ['id' => 7,  'name' => 'Suresh Nair',      'team' => 'Coastal Sharks',  'role' => 'BOWL', 'credits' => 9.0],
        ['id' => 8,  'name' => 'Farhan Sheikh',    'team' => 'Coastal Sharks',  'role' => 'BOWL', 'credits' => 8.5],
        ['id' => 9,  'name' => 'Devansh Joshi',    'team' => 'Coastal Sharks',  'role' => 'BOWL', 'credits' => 7.5],
        ['id' => 10, 'name' => 'Aman Trivedi',     'team' => 'Coastal Sharks',  'role' => 'BOWL', 'credits' => 7.0],
        ['id' => 11, 'name' => 'Omkar Desai',      'team' => 'Coastal Sharks',  'role' => 'BAT',  'credits' => 8.0],

        // ---- Highland Eagles ----
        ['id' => 12, 'name' => 'Rajveer Sandhu',   'team' => 'Highland Eagles', 'role' => 'WK',   'credits' => 8.5],
        ['id' => 13, 'name' => 'Yuvaan Oberoi',    'team' => 'Highland Eagles', 'role' => 'BAT',  'credits' => 10.5],
        ['id' => 14, 'name' => 'Karan Bedi',       'team' => 'Highland Eagles', 'role' => 'BAT',  'credits' => 9.0],
        ['id' => 15, 'name' => 'Manav Chandra',    'team' => 'Highland Eagles', 'role' => 'BAT',  'credits' => 8.5],
        ['id' => 16, 'name' => 'Tarun Dutta',      'team' => 'Highland Eagles', 'role' => 'AR',   'credits' => 9.5],
        ['id' => 17, 'name' => 'Zubin Wadia',      'team' => 'Highland Eagles', 'role' => 'AR',   'credits' => 8.0],
        ['id' => 18, 'name' => 'Harshad Solanki',  'team' => 'Highland Eagles', 'role' => 'BOWL', 'credits' => 9.0],
        ['id' => 19, 'name' => 'Nikhil Bora',      'team' => 'Highland Eagles', 'role' => 'BOWL', 'credits' => 8.0],
        ['id' => 20, 'name' => 'Prithvi Ghosh',    'team' => 'Highland Eagles', 'role' => 'BOWL', 'credits' => 7.5],
        ['id' => 21, 'name' => 'Sameer Katyal',    'team' => 'Highland Eagles', 'role' => 'BOWL', 'credits' => 7.0],
        ['id' => 22, 'name' => 'Dhruv Asthana',    'team' => 'Highland Eagles', 'role' => 'BAT',  'credits' => 8.0],
    ];
}

function getPlayerById(int $id): ?array {
    foreach (getPlayerPool() as $p) {
        if ($p['id'] === $id) return $p;
    }
    return null;
}

// ---------- Team Validation ----------
// $ids = array of 11 selected player ids
function validateTeam(array $ids, int $captainId, int $viceId): array {
    $errors = [];
    $ids = array_values(array_unique($ids));

    if (count($ids) !== SQUAD_SIZE) {
        $errors[] = 'Aapko exactly ' . SQUAD_SIZE . ' players choose karne honge (abhi ' . count($ids) . ' select hain).';
    }

    $players = array_filter(array_map('getPlayerById', $ids));
    if (count($players) !== count($ids)) {
        $errors[] = 'Kuch invalid player IDs mile.';
    }

    $totalCredits = array_sum(array_column($players, 'credits'));
    if ($totalCredits > TOTAL_CREDITS) {
        $errors[] = 'Credits limit paar ho gayi: ' . $totalCredits . ' / ' . TOTAL_CREDITS;
    }

    $roleCounts = ['WK' => 0, 'BAT' => 0, 'AR' => 0, 'BOWL' => 0];
    $teamCounts = [];
    foreach ($players as $p) {
        $roleCounts[$p['role']]++;
        $teamCounts[$p['team']] = ($teamCounts[$p['team']] ?? 0) + 1;
    }

    if ($roleCounts['WK'] < 1 || $roleCounts['WK'] > 4) $errors[] = 'Wicket-Keeper: 1 se 4 ke beech hone chahiye.';
    if ($roleCounts['BAT'] < 3 || $roleCounts['BAT'] > 6) $errors[] = 'Batsman: 3 se 6 ke beech hone chahiye.';
    if ($roleCounts['AR'] < 1 || $roleCounts['AR'] > 4) $errors[] = 'All-Rounder: 1 se 4 ke beech hone chahiye.';
    if ($roleCounts['BOWL'] < 3 || $roleCounts['BOWL'] > 6) $errors[] = 'Bowler: 3 se 6 ke beech hone chahiye.';

    foreach ($teamCounts as $team => $count) {
        if ($count > 7) $errors[] = "Ek real team ($team) se max 7 players hi le sakte hain.";
    }

    if (!in_array($captainId, $ids)) $errors[] = 'Captain aapki selected team me hona chahiye.';
    if (!in_array($viceId, $ids)) $errors[] = 'Vice-Captain aapki selected team me hona chahiye.';
    if ($captainId === $viceId) $errors[] = 'Captain aur Vice-Captain alag-alag players hone chahiye.';

    return ['valid' => empty($errors), 'errors' => $errors, 'total_credits' => $totalCredits];
}

// ---------- Match Simulation ----------
function simulatePlayerStats(string $role): array {
    $runs = 0; $fours = 0; $sixes = 0; $wickets = 0; $maidens = 0;
    $catches = 0; $stumpings = 0; $runouts = 0;

    $battingRoles = ['BAT', 'WK', 'AR'];
    $bowlingRoles = ['BOWL', 'AR'];

    if (in_array($role, $battingRoles)) {
        $roll = mt_rand(1, 100);
        if ($roll <= 10) $runs = 0;
        elseif ($roll <= 40) $runs = mt_rand(1, 20);
        elseif ($roll <= 70) $runs = mt_rand(21, 45);
        elseif ($roll <= 90) $runs = mt_rand(46, 75);
        else $runs = mt_rand(76, 110);

        if ($runs > 0) {
            $fours = intdiv($runs, mt_rand(6, 10));
            $sixes = intdiv($runs, mt_rand(15, 25));
        }
    }

    if (in_array($role, $bowlingRoles)) {
        $roll = mt_rand(1, 100);
        if ($roll <= 20) $wickets = 0;
        elseif ($roll <= 55) $wickets = mt_rand(1, 2);
        elseif ($roll <= 85) $wickets = mt_rand(2, 3);
        else $wickets = mt_rand(3, 5);
        $maidens = (mt_rand(1, 100) <= 20) ? 1 : 0;
    }

    if (mt_rand(1, 100) <= 30) $catches = mt_rand(1, 2);
    if ($role === 'WK' && mt_rand(1, 100) <= 15) $stumpings = 1;
    if (mt_rand(1, 100) <= 10) $runouts = 1;

    return compact('runs', 'fours', 'sixes', 'wickets', 'maidens', 'catches', 'stumpings', 'runouts');
}

// Poore match ke liye (22 players) random performance generate karta hai
function simulateFullMatch(): array {
    $results = [];
    foreach (getPlayerPool() as $p) {
        $stats = simulatePlayerStats($p['role']);
        $stats['points'] = computeFantasyPoints($stats, $p['role']);
        $results[$p['id']] = $stats;
    }
    return $results;
}

// ---------- Scoring (Dream11-style approximate rules) ----------
function computeFantasyPoints(array $s, string $role): int {
    $pts = 0;
    $pts += $s['runs'] * 1;
    $pts += $s['fours'] * 1;
    $pts += $s['sixes'] * 2;

    if ($s['runs'] >= 100) $pts += 16;
    elseif ($s['runs'] >= 50) $pts += 8;
    elseif ($s['runs'] >= 30) $pts += 4;

    if ($s['runs'] === 0 && in_array($role, ['BAT', 'WK', 'AR'])) $pts -= 2; // duck

    $pts += $s['wickets'] * 25;
    if ($s['wickets'] >= 5) $pts += 8;
    elseif ($s['wickets'] >= 3) $pts += 4;

    $pts += $s['maidens'] * 4;
    $pts += $s['catches'] * 8;
    $pts += $s['stumpings'] * 12;
    $pts += $s['runouts'] * 6;

    return $pts;
}

function scoringRulesText(): array {
    return [
        '1 Run = 1 point', 'Boundary (4) = +1 bonus', 'Six = +2 bonus',
        '30+ runs = +4, Half-century = +8, Century = +16',
        'Duck (0 runs, batting role) = -2',
        'Wicket = 25 points (3-wkt haul = +4, 5-wkt haul = +8)',
        'Maiden over = +4', 'Catch = +8', 'Stumping = +12', 'Run-out = +6',
        'Captain = 2x points, Vice-Captain = 1.5x points',
    ];
}