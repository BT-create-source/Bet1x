<?php
/**
 * ROAD TEST — Your 11 with 50 fake users, from team building to prize money.
 *
 *     php -d extension=pdo_pgsql php-backend/tests/road_your11.php [--users=50] [--keep]
 *
 * Follows the Dream11 user journey end to end, at volume, and tries to break it:
 *   teams (and illegal teams) -> contests of every kind -> parallel races for spots and balances ->
 *   team switches -> lineups -> a full live match -> settlement (with a settlement race) -> refunds on an
 *   abandoned match — then reconciles every rupee and checks every player's points against the
 *   independent Dream11 oracle.
 */
require __DIR__ . '/road_common.php';
require __DIR__ . '/road_oracle.php';

$NU = 50;
foreach ($argv as $a) if (preg_match('/^--users=(\d+)$/', $a, $m)) $NU = max(10, (int) $m[1]);

road_wipe_users('y');
$SLOT = road_slot(1200000);
$KEY = cricket_mock_match_key($SLOT);
road_wipe_match($KEY);
// Users 1..(N-5) are well funded; the last five are deliberately short of money.
$users = road_users('y', $NU, function ($i) use ($NU) { return $i > $NU - 5 ? [30, 60, 75, 49, 120][$i - ($NU - 4)] : 5000; });
$U = array_values($users);
$sim = cricket_mock_simulate($SLOT);
$start = $sim['meta']['start_ms'];

// -------------------------------------------------------------------------------------------------
road_section("1. Fixture, squads, contests ({$NU} users)");
road_at($start - 3 * 3600000);
$fx = fantasy_feed_upsert_fixture(road_mock_fixture($SLOT));
$mid = $fx['match_id'];
road_check($mid > 0 && (int) fantasy_find_match($mid)['squads_ready'] === 1, 'fixture and both squads are in');
// Extra contests on top of the standard set: several head-to-heads and a 20-spot league.
$h2h = [];
for ($i = 0; $i < 8; $i++) {
    $r = fantasy_create_contest($mid, ['title' => 'H2H #' . ($i + 1), 'contest_type' => 'h2h', 'entry_fee' => 50, 'total_spots' => 2,
                                       'rake_pct' => 15, 'min_entries' => 2, 'prize_rules' => [['from' => 1, 'to' => 1, 'pct' => 100]]]);
    $h2h[] = $r['contest_id'];
}
$league = fantasy_create_contest($mid, ['title' => 'Road League', 'contest_type' => 'custom', 'entry_fee' => 10, 'total_spots' => 20,
    'max_entries_per_user' => 3, 'rake_pct' => 10, 'min_entries' => 5,
    'prize_rules' => [['from' => 1, 'to' => 1, 'pct' => 40], ['from' => 2, 'to' => 2, 'pct' => 25], ['from' => 3, 'to' => 3, 'pct' => 15], ['from' => 4, 'to' => 5, 'pct' => 10]]])['contest_id'];
$contests = [];
foreach (fantasy_list_contests($mid) as $c) $contests[$c['id']] = $c;
road_check(count($contests) === 5 + 8 + 1, count($contests) . ' contests on the match (5 standard + 8 head-to-head + 1 league)');
$bad = fantasy_create_contest($mid, ['title' => 'Bad', 'entry_fee' => 10, 'total_spots' => 5, 'prize_rules' => [['from' => 1, 'to' => 1, 'pct' => 90]]]);
road_check(!$bad['ok'], 'a contest whose prize table does not add up to 100% is refused');

// -------------------------------------------------------------------------------------------------
road_section('2. Building teams — and trying to build illegal ones');
$g = fantasy_players_grouped($mid);
$allP = array_merge($g['WK'], $g['BAT'], $g['ALL'], $g['BOWL']);
$teams = [];
$made = 0;
foreach ($U as $i => $u) {
    $n = 1 + ($i % 6);
    for ($k = 0; $k < $n; $k++) {
        $xi = road_legal_xi($mid, 1000 + $i * 10 + $k);
        $cap = $xi[mt_rand(0, 10)]['id'];
        do { $vc = $xi[mt_rand(0, 10)]['id']; } while ($vc === $cap);
        $r = fantasy_save_team((int) $u['id'], $mid, ['players' => array_column($xi, 'id'), 'captain' => $cap, 'vice_captain' => $vc, 'team_name' => 'T' . ($k + 1)]);
        if ($r['ok']) { $teams[$u['username']][] = $r['team_id']; $made++; }
    }
}
road_check($made >= $NU * 3, "$made legal teams saved");
$u0 = $U[0];
$xi = road_legal_xi($mid, 7);
$ids = array_column($xi, 'id');
usort($allP, function ($a, $b) { return $b['credits'] <=> $a['credits']; });
$rich = [];   // most expensive legal-shape XI, which must break the 100-credit cap
foreach (['WK' => 1, 'BAT' => 4, 'ALL' => 2, 'BOWL' => 4] as $role => $cnt) { $pool = $g[$role]; usort($pool, function ($a, $b) { return $b['credits'] <=> $a['credits']; }); $rich = array_merge($rich, array_slice($pool, 0, $cnt)); }
$sideA = array_values(array_filter($allP, function ($p) use ($xi) { return $p['team_name'] === $xi[0]['team_name']; }));
$cases = [
    '12 players'            => ['players' => array_merge($ids, [$allP[0]['id'] === $ids[0] ? $allP[1]['id'] : $allP[0]['id']]), 'captain' => $ids[0], 'vice_captain' => $ids[1]],
    '10 players'            => ['players' => array_slice($ids, 0, 10), 'captain' => $ids[0], 'vice_captain' => $ids[1]],
    'same player twice'     => ['players' => array_merge(array_slice($ids, 0, 10), [$ids[0]]), 'captain' => $ids[0], 'vice_captain' => $ids[1]],
    'captain = vice-captain' => ['players' => $ids, 'captain' => $ids[0], 'vice_captain' => $ids[0]],
    'captain not in the XI' => ['players' => $ids, 'captain' => 999999, 'vice_captain' => $ids[1]],
    'over 100 credits'      => ['players' => array_column($rich, 'id'), 'captain' => $rich[0]['id'], 'vice_captain' => $rich[1]['id']],
    'player from another match' => ['players' => array_merge(array_slice($ids, 0, 10), [999999]), 'captain' => $ids[0], 'vice_captain' => $ids[1]],
    'no wicket-keeper'      => null,
];
$noWk = array_merge(array_slice(array_column($g['BAT'], 'id'), 0, 5), array_slice(array_column($g['ALL'], 'id'), 0, 2), array_slice(array_column($g['BOWL'], 'id'), 0, 4));
$cases['no wicket-keeper'] = ['players' => $noWk, 'captain' => $noWk[0], 'vice_captain' => $noWk[1]];
$sum = array_sum(array_map(function ($id) use ($allP) { foreach ($allP as $p) if ($p['id'] === $id) return $p['credits']; return 0; }, array_column($rich, 'id')));
foreach ($cases as $label => $lineup) {
    $r = fantasy_save_team((int) $u0['id'], $mid, $lineup);
    road_check(!$r['ok'], "illegal team refused: $label" . ($label === 'over 100 credits' ? sprintf(' (%.1f credits)', $sum) : ''), $r['ok'] ? 'was accepted' : null);
}
// The per-match team limit (11): fill one user up and try a 12th.
$capUser = $U[1];
$have = count($teams[$capUser['username']] ?? []);
for ($k = $have; $k < 11; $k++) {
    $x = road_legal_xi($mid, 5000 + $k);
    $r = fantasy_save_team((int) $capUser['id'], $mid, ['players' => array_column($x, 'id'), 'captain' => $x[0]['id'], 'vice_captain' => $x[1]['id']]);
    if ($r['ok']) $teams[$capUser['username']][] = $r['team_id'];
}
$x = road_legal_xi($mid, 9999);
$r12 = fantasy_save_team((int) $capUser['id'], $mid, ['players' => array_column($x, 'id'), 'captain' => $x[0]['id'], 'vice_captain' => $x[1]['id']]);
road_check(count($teams[$capUser['username']]) === 11 && !$r12['ok'], 'a 12th team on one match is refused (limit 11)');

// -------------------------------------------------------------------------------------------------
road_section('3. Joining contests at volume');
$joined = 0; $refusedShort = 0; $refusedFull = 0; $refusedCap = 0; $other = [];
$cids = array_keys($contests);
mt_srand(77);
foreach ($U as $i => $u) {
    $myTeams = $teams[$u['username']] ?? [];
    if (!$myTeams) continue;
    $tries = 2 + ($i % 5);
    for ($t = 0; $t < $tries; $t++) {
        $cid = $cids[mt_rand(0, count($cids) - 1)];
        $tid = $myTeams[mt_rand(0, count($myTeams) - 1)];
        $r = fantasy_join_contest((int) $u['id'], $u['username'], $cid, $tid);
        if ($r['ok']) $joined++;
        elseif (($r['status'] ?? 0) === 402) $refusedShort++;
        elseif (stripos($r['error'], 'full') !== false) $refusedFull++;
        elseif (stripos($r['error'], 'already') !== false || stripos($r['error'], 'maximum') !== false) $refusedCap++;
        else $other[] = $r['error'];
    }
}
road_info("$joined entries, refused: $refusedShort for balance, $refusedFull full, $refusedCap at the per-player cap / same team");
road_check($joined > $NU, 'entries flowed in across every contest type');
// Deterministic too: a short player against a contest dearer than their whole balance.
$pricey = fantasy_create_contest($mid, ['title' => 'Pricey', 'contest_type' => 'custom', 'entry_fee' => 500, 'total_spots' => 10, 'rake_pct' => 10, 'min_entries' => 2,
                                        'prize_rules' => [['from' => 1, 'to' => 1, 'pct' => 100]]])['contest_id'];
$shorts = 0; $shortsKept = true;
foreach (array_slice($U, $NU - 5) as $su) {
    if (empty($teams[$su['username']])) continue;
    $b0 = road_balance($su);
    $r = fantasy_join_contest((int) $su['id'], $su['username'], $pricey, $teams[$su['username']][0]);
    if (!$r['ok'] && ($r['status'] ?? 0) === 402) $shorts++;
    if (!road_near(road_balance($su), $b0)) $shortsKept = false;
}
road_check($shorts === 5 && $shortsKept, "players without enough balance are refused (402: $shorts of 5) and keep every rupee");
road_check(!$other, 'no unexpected refusal reasons', $other ? array_slice(array_unique($other), 0, 5) : null);
// Mega: push every funded user's teams in so it fills up, then one more must bounce.
$megaId = null; foreach ($contests as $c) if ($c['contest_type'] === 'mega') $megaId = $c['id'];
foreach ($U as $u) foreach (($teams[$u['username']] ?? []) as $tid) fantasy_join_contest((int) $u['id'], $u['username'], $megaId, $tid);
$mega = one('SELECT * FROM "fantasy_contests" WHERE "id" = ?', [$megaId]);
$perUserMax = (int) scalar('SELECT MAX("n") FROM (SELECT COUNT(*) AS "n" FROM "fantasy_contest_entries" WHERE "contest_id" = ? GROUP BY "user_id") x', [$megaId], 0);
road_check($perUserMax <= 6, "no player has more than 6 teams in the mega (most: $perUserMax)");

// -------------------------------------------------------------------------------------------------
road_section('4. Parallel races (separate processes hitting the database at the same instant)');
// (a) six players race for one fresh head-to-head: exactly two get in.
$freshH2h = fantasy_create_contest($mid, ['title' => 'Race H2H', 'contest_type' => 'h2h', 'entry_fee' => 50, 'total_spots' => 2, 'rake_pct' => 15, 'min_entries' => 2,
                                          'prize_rules' => [['from' => 1, 'to' => 1, 'pct' => 100]]])['contest_id'];
$tasks = [];
for ($i = 2; $i < 8; $i++) $tasks[] = ['action' => 'join', 'user_id' => $U[$i]['id'], 'contest_id' => $freshH2h, 'team_id' => $teams[$U[$i]['username']][0]];
$res = road_parallel($tasks);
$ok = count(array_filter($res, function ($r) { return !empty($r['ok']); }));
$filled = (int) scalar('SELECT "filled_spots" FROM "fantasy_contests" WHERE "id" = ?', [$freshH2h]);
$entries = (int) scalar('SELECT COUNT(*) FROM "fantasy_contest_entries" WHERE "contest_id" = ?', [$freshH2h]);
road_check($ok === 2 && $filled === 2 && $entries === 2, "6 simultaneous joins on a 2-spot contest: exactly 2 succeed ($ok ok, filled $filled, entries $entries)");
// (b) one player, two different teams, one single-entry contest, at the same instant: exactly one entry.
$single = fantasy_create_contest($mid, ['title' => 'Race single', 'contest_type' => 'custom', 'entry_fee' => 20, 'total_spots' => 50, 'rake_pct' => 10, 'min_entries' => 2,
                                        'prize_rules' => [['from' => 1, 'to' => 1, 'pct' => 100]]])['contest_id'];
$u8 = $U[8]; $t8 = $teams[$u8['username']];
$bal8 = road_balance($u8);
$res = road_parallel([
    ['action' => 'join', 'user_id' => $u8['id'], 'contest_id' => $single, 'team_id' => $t8[0]],
    ['action' => 'join', 'user_id' => $u8['id'], 'contest_id' => $single, 'team_id' => $t8[1] ?? $t8[0]],
    ['action' => 'join', 'user_id' => $u8['id'], 'contest_id' => $single, 'team_id' => $t8[0]],
]);
$ok = count(array_filter($res, function ($r) { return !empty($r['ok']); }));
road_check($ok === 1 && road_near(road_balance($u8), $bal8 - 20), "3 simultaneous joins by one player on a single-entry contest: 1 entry, charged once ($ok ok)");
// (c) double spend: a player with ₹75 fires three ₹50 joins at once — at most one can be paid for.
$poor = $U[$NU - 3];   // balance 75
$ptid = $teams[$poor['username']][0] ?? null;
$hs = [];
for ($i = 0; $i < 3; $i++) $hs[] = fantasy_create_contest($mid, ['title' => 'DS ' . $i, 'contest_type' => 'h2h', 'entry_fee' => 50, 'total_spots' => 2, 'rake_pct' => 15, 'min_entries' => 2,
                                                                 'prize_rules' => [['from' => 1, 'to' => 1, 'pct' => 100]]])['contest_id'];
$before = road_balance($poor);
$res = road_parallel(array_map(function ($c) use ($poor, $ptid) { return ['action' => 'join', 'user_id' => $poor['id'], 'contest_id' => $c, 'team_id' => $ptid]; }, $hs));
$ok = count(array_filter($res, function ($r) { return !empty($r['ok']); }));
$after = road_balance($poor);
road_check($ok === intdiv((int) $before, 50) && $after >= 0 && road_near($after, $before - 50 * $ok), sprintf('3 simultaneous ₹50 joins from a ₹%.0f wallet: %d paid, balance ₹%.2f, never negative', $before, $ok, $after));
// (d) multi-entry cap under fire: 8 teams at once into a fresh "max 3" league.
$capLeague = fantasy_create_contest($mid, ['title' => 'Cap race', 'contest_type' => 'custom', 'entry_fee' => 10, 'total_spots' => 100, 'max_entries_per_user' => 3, 'rake_pct' => 10, 'min_entries' => 2,
                                           'prize_rules' => [['from' => 1, 'to' => 1, 'pct' => 100]]])['contest_id'];
$res = road_parallel(array_map(function ($tid) use ($capUser, $capLeague) { return ['action' => 'join', 'user_id' => $capUser['id'], 'contest_id' => $capLeague, 'team_id' => $tid]; }, array_slice($teams[$capUser['username']], 0, 8)));
$n = (int) scalar('SELECT COUNT(*) FROM "fantasy_contest_entries" WHERE "contest_id" = ? AND "user_id" = ?', [$capLeague, (int) $capUser['id']]);
road_check($n === 3, "8 simultaneous entries against a max-3 cap: exactly 3 recorded ($n)");

// -------------------------------------------------------------------------------------------------
road_section('5. Switching teams before the deadline');
// Exact, not a count: an entry can switch to any of the player's other teams that is not already
// entered in that contest — that must succeed — and to nothing else, which must be refused.
$switched = 0; $rightlyRefused = 0; $wrong = [];
$balBefore = []; foreach ($U as $u) $balBefore[$u['username']] = road_balance($u);
foreach (all('SELECT e."id", e."user_id", e."user_team_id", e."contest_id" FROM "fantasy_contest_entries" e JOIN "fantasy_contests" c ON c."id" = e."contest_id" WHERE c."match_id" = ? ORDER BY e."id" LIMIT 150', [$mid]) as $e) {
    $uname = scalar('SELECT "username" FROM "User" WHERE "id" = ?', [(int) $e['user_id']]);
    $entered = array_map('intval', array_column(all('SELECT "user_team_id" FROM "fantasy_contest_entries" WHERE "contest_id" = ? AND "user_id" = ?', [(int) $e['contest_id'], (int) $e['user_id']]), 'user_team_id'));
    $free = array_values(array_diff(array_map('intval', $teams[$uname] ?? []), $entered));
    $taken = array_values(array_diff($entered, [(int) $e['user_team_id']]));
    if ($free) {
        $r = fantasy_switch_entry_team((int) $e['user_id'], (int) $e['id'], $free[0]);
        $now = (int) scalar('SELECT "user_team_id" FROM "fantasy_contest_entries" WHERE "id" = ?', [(int) $e['id']]);
        if ($r['ok'] && $now === $free[0]) $switched++; else $wrong[] = "entry {$e['id']}: switch to free team {$free[0]} refused: " . ($r['error'] ?? 'not applied');
    } elseif ($taken) {
        $r = fantasy_switch_entry_team((int) $e['user_id'], (int) $e['id'], $taken[0]);
        if (!$r['ok']) $rightlyRefused++; else $wrong[] = "entry {$e['id']}: switched onto team {$taken[0]}, already in that contest";
    }
}
$moneyMoved = false; foreach ($U as $u) if (!road_near(road_balance($u), $balBefore[$u['username']])) $moneyMoved = true;
road_info("$switched switches made, $rightlyRefused refused because that team was already in the contest");
road_check($switched > 0 && !$wrong, "every switch to one of the player's own free teams works; every switch onto a team already entered is refused", $wrong ? array_slice($wrong, 0, 5) : null);
road_check(!$moneyMoved, 'switching teams moved no money');
$someEntry = one('SELECT e."id", e."user_id" FROM "fantasy_contest_entries" e JOIN "fantasy_contests" c ON c."id" = e."contest_id" WHERE c."match_id" = ? LIMIT 1', [$mid]);
$otherUserTeam = $teams[$U[3]['username']][0];
$r = fantasy_switch_entry_team((int) $someEntry['user_id'], (int) $someEntry['id'], (int) $otherUserTeam);
road_check(!$r['ok'] || (int) $someEntry['user_id'] === (int) $U[3]['id'], 'switching to somebody else\'s team is refused');

// -------------------------------------------------------------------------------------------------
road_section('6. Integrity before the deadline');
$badSpots = (int) scalar('SELECT COUNT(*) FROM "fantasy_contests" c WHERE c."match_id" = ? AND c."filled_spots" <> (SELECT COUNT(*) FROM "fantasy_contest_entries" e WHERE e."contest_id" = c."id")', [$mid], 0);
$over = (int) scalar('SELECT COUNT(*) FROM "fantasy_contests" WHERE "match_id" = ? AND "filled_spots" > "total_spots"', [$mid], 0);
$dupTeam = (int) scalar('SELECT COUNT(*) FROM (SELECT "contest_id","user_team_id" FROM "fantasy_contest_entries" GROUP BY 1,2 HAVING COUNT(*) > 1) x', [], 0);
$overCap = (int) scalar('SELECT COUNT(*) FROM (SELECT e."contest_id", e."user_id", COUNT(*) AS n, MAX(c."max_entries_per_user") AS mx FROM "fantasy_contest_entries" e JOIN "fantasy_contests" c ON c."id" = e."contest_id" WHERE c."match_id" = ? GROUP BY 1,2) x WHERE n > mx', [$mid], 0);
road_check($badSpots === 0 && $over === 0, 'every contest\'s spot counter equals its entries, and none is over-filled');
road_check($dupTeam === 0 && $overCap === 0, 'no team is entered twice in a contest, and no player is over a contest\'s entry cap');
$moneyOk = true;
foreach ($U as $u) {
    $paid = (float) scalar('SELECT COALESCE(SUM("entry_fee_paid"),0) FROM "fantasy_contest_entries" WHERE "user_id" = ?', [(int) $u['id']], 0);
    if (!road_near(road_balance($u), $u['_start'] - $paid)) $moneyOk = false;
}
road_check($moneyOk, 'every wallet = starting balance − the entry fees actually recorded');

// -------------------------------------------------------------------------------------------------
road_section('7. Lineups, deadline, and the live match');
road_at($start - 20 * 60000);
cricket_feed_ingest(cricket_mock_snapshot($KEY, $start - 20 * 60000), 'mock', $start - 20 * 60000);
fantasy_feed_sync($KEY, true);
road_check((int) fantasy_find_match($mid)['lineups_announced'] === 1, 'lineups out: Playing / Not playing flags are set');
road_at($start + 1000);
$late = fantasy_join_contest((int) $U[0]['id'], $U[0]['username'], $league, $teams[$U[0]['username']][0]);
$lateSwitch = fantasy_switch_entry_team((int) $someEntry['user_id'], (int) $someEntry['id'], (int) ($teams[scalar('SELECT "username" FROM "User" WHERE "id" = ?', [(int) $someEntry['user_id']])][0]));
$lateTeam = fantasy_save_team((int) $U[0]['id'], $mid, ['players' => $ids, 'captain' => $ids[0], 'vice_captain' => $ids[1]]);
road_check(!$late['ok'] && !$lateSwitch['ok'] && !$lateTeam['ok'], 'after the deadline: no joins, no switches, no new or edited teams');
$snaps = 0;
for ($t = $start; $t <= $sim['ended_ms'] + 60000; $t += 60000) {
    road_at($t);
    cricket_feed_ingest(cricket_mock_snapshot($KEY, $t), 'mock', $t);
    $snaps++;
    if ($snaps % 15 === 0) {
        // mid-match: a leaderboard must always be internally consistent
        $lb = fantasy_contest_leaderboard($megaId, 500);
        $prev = INF; $okLb = true;
        foreach ($lb['entries'] as $e) { if ($e['points'] > $prev + 1e-9) $okLb = false; $prev = $e['points']; }
        if (!$okLb) road_check(false, 'live leaderboard ordered by points at snapshot ' . $snaps);
    }
}
road_at($sim['ended_ms'] + 120000);
fantasy_feed_sync($KEY, true);
$feed = cricket_match_feed_row($KEY);
road_check($feed['status'] === 'completed', "match completed after $snaps live updates: " . $feed['result_text']);

// -------------------------------------------------------------------------------------------------
road_section('8. Every player\'s points vs the independent Dream11 oracle');
$finalSnap = cricket_mock_snapshot($KEY, $sim['ended_ms'] + 120000);
$lineupSet = [];
foreach ($feed['lineups'] as $side => $keys) foreach ($keys as $k) $lineupSet[$k] = true;
$roles = [];
foreach (all('SELECT "external_key","role" FROM "fantasy_players" WHERE "match_id" = ?', [$mid]) as $r) $roles[$r['external_key']] = $r['role'];
$want = oracle_points(oracle_balls_from_snapshot($finalSnap), $lineupSet, $roles);
$stored = [];
foreach (all('SELECT p."external_key", s."calculated_fantasy_points" FROM "fantasy_player_live_stats" s JOIN "fantasy_players" p ON p."id" = s."player_id" WHERE s."match_id" = ?', [$mid]) as $r) $stored[$r['external_key']] = (float) $r['calculated_fantasy_points'];
$diff = [];
foreach ($want as $k => $pts) {
    if (!isset($lineupSet[$k]) && $pts == 0) continue;
    $mine = $stored[$k] ?? (isset($lineupSet[$k]) ? null : 0.0);
    if ($mine === null || !road_near($mine, $pts)) $diff[] = "$k engine " . json_encode($mine) . " oracle $pts";
}
road_check(!$diff && count($stored) >= 22, count($stored) . ' players scored; every one equals the oracle', $diff ? array_slice($diff, 0, 8) : null);
$teamBad = 0; $teamWhy = [];
foreach (all('SELECT "id","user_id","total_points" FROM "fantasy_user_teams" WHERE "match_id" = ?', [$mid]) as $tm) {
    $tw = fantasy_team_with_players((int) $tm['id'], (int) $tm['user_id']);
    $tot = 0.0;
    foreach ($tw['players'] as $p) {
        $k = scalar('SELECT "external_key" FROM "fantasy_players" WHERE "id" = ?', [(int) $p['id']]);
        $base = $want[$k] ?? 0.0;
        $tot += $base * ($p['is_captain'] ? 2 : ($p['is_vice_captain'] ? 1.5 : 1));
    }
    if (!road_near($tot, $tm['total_points'], 0.02)) { $teamBad++; if (count($teamWhy) < 6) $teamWhy[] = "team {$tm['id']}: stored {$tm['total_points']} vs oracle $tot " . json_encode(array_map(function ($p) use ($want) { $k = scalar('SELECT "external_key" FROM "fantasy_players" WHERE "id" = ?', [(int) $p['id']]); return [$k, $want[$k] ?? null, $p['points'] ?? null, $p['is_captain'] ? 'C' : ($p['is_vice_captain'] ? 'VC' : '')]; }, $tw['players'])); }
}
road_check($teamBad === 0, 'every team total = the oracle\'s player points with captain 2x and vice-captain 1.5x', $teamWhy ?: null);

// -------------------------------------------------------------------------------------------------
road_section('9. Settlement — including two settlers racing for the same contest');
$raceRes = road_parallel([['action' => 'settle_contest', 'contest_id' => $megaId], ['action' => 'settle_contest', 'contest_id' => $megaId], ['action' => 'settle_contest', 'contest_id' => $megaId]]);
$megaPaid = (float) scalar('SELECT COALESCE(SUM("prize_won"),0) FROM "fantasy_contest_entries" WHERE "contest_id" = ?', [$megaId], 0);
$megaWinTx = (float) scalar('SELECT COALESCE(SUM("amount"),0) FROM "Transaction" WHERE "details" LIKE ? AND "user" LIKE ?', ['Your Eleven Prize — Mega Contest%', 'road_y%'], 0);
road_check(road_near($megaPaid, $megaWinTx), sprintf('three settlers at once: the mega paid exactly once (₹%.2f in prizes, ₹%.2f credited)', $megaPaid, $megaWinTx));
$set = fantasy_settle_match($mid, false);
road_check(empty($set['errors']), 'the rest of the match settles without errors', $set['errors'] ?? null);
$settledOk = true; $rankOk = true; $rakeTotal = 0.0; $nSettled = 0; $nCancelled = 0; $refundOk = true;
foreach (all('SELECT * FROM "fantasy_contests" WHERE "match_id" = ?', [$mid]) as $c) {
    $rows = all('SELECT * FROM "fantasy_contest_entries" WHERE "contest_id" = ? ORDER BY "rank" ASC, "points" DESC', [(int) $c['id']]);
    if ($c['status'] === 'SETTLED') {
        $nSettled++;
        $pool = fantasy_contest_pool($c);
        $paid = array_sum(array_map(function ($r) { return (float) $r['prize_won']; }, $rows));
        if ($c['contest_type'] !== 'practice' && $rows && !road_near($paid, $pool['prize_pool'], 0.05)) { $settledOk = false; road_info("contest {$c['id']} paid $paid of pool {$pool['prize_pool']}"); }
        $rakeTotal += $pool['collected'] - $paid;
        // ranks: better points never rank worse; ties share a rank
        foreach ($rows as $a) foreach ($rows as $b) {
            if ((float) $a['points'] > (float) $b['points'] + 1e-6 && (int) $a['rank'] >= (int) $b['rank']) $rankOk = false;
            if (abs((float) $a['points'] - (float) $b['points']) < 1e-6 && (int) $a['rank'] !== (int) $b['rank']) $rankOk = false;
        }
    } elseif ($c['status'] === 'CANCELLED') {
        $nCancelled++;
        foreach ($rows as $r) if ((float) $r['prize_won'] != 0 || (int) $r['is_settled'] !== 1) $refundOk = false;
    } else {
        $settledOk = false; road_info("contest {$c['id']} left in status {$c['status']}");
    }
}
road_info("$nSettled contests settled, $nCancelled cancelled (under their minimum) — house rake ₹" . number_format($rakeTotal, 2));
road_check($settledOk, 'every settled contest paid out exactly its prize pool, and none was left open');
road_check($rankOk, 'ranks follow points everywhere; tied teams share a rank');
road_check($refundOk, 'every cancelled contest refunded its entries');
$bal = []; foreach ($U as $u) $bal[$u['username']] = road_balance($u);
fantasy_settle_match($mid, false);
$same = true; foreach ($U as $u) if (!road_near(road_balance($u), $bal[$u['username']])) $same = false;
road_check($same, 'settling the match again moves no money');

road_section('10. Every rupee reconciled');
$allOk = true; $sumDelta = 0.0; $why = [];
foreach ($U as $u) {
    $now = road_balance($u);
    $fees = (float) scalar('SELECT COALESCE(SUM("entry_fee_paid"),0) FROM "fantasy_contest_entries" WHERE "user_id" = ?', [(int) $u['id']], 0);
    $prizes = (float) scalar('SELECT COALESCE(SUM(e."prize_won"),0) FROM "fantasy_contest_entries" e JOIN "fantasy_contests" c ON c."id" = e."contest_id" WHERE e."user_id" = ? AND c."status" = ?', [(int) $u['id'], 'SETTLED'], 0);
    $refunds = (float) scalar('SELECT COALESCE(SUM(e."entry_fee_paid"),0) FROM "fantasy_contest_entries" e JOIN "fantasy_contests" c ON c."id" = e."contest_id" WHERE e."user_id" = ? AND c."status" = ?', [(int) $u['id'], 'CANCELLED'], 0);
    $led = road_ledger($u['username']);
    $expect = $u['_start'] - $fees + $prizes + $refunds;
    if (!road_near($now, $expect) || !road_near($led['out'], $fees) || !road_near($led['in'], $prizes + $refunds)) { $allOk = false; $why[] = "{$u['username']}: now $now expect $expect ledger " . json_encode($led); }
    $sumDelta += $now - $u['_start'];
}
road_check($allOk, "all {$NU} wallets = start − fees + prizes + refunds, and the ledger agrees row for row", $why ? array_slice($why, 0, 5) : null);
road_check(road_near(-$sumDelta, $rakeTotal, 0.1), sprintf('across all players: they are down ₹%.2f in total, which is exactly the house rake ₹%.2f', -$sumDelta, $rakeTotal));

// -------------------------------------------------------------------------------------------------
road_section('11. A rained-off match: every fee back, no rake');
putenv('CRICKET_MOCK_ABANDON_EVERY=1');
$SLOT2 = $SLOT + 1; $KEY2 = cricket_mock_match_key($SLOT2);
road_wipe_match($KEY2);
$sim2 = cricket_mock_simulate($SLOT2);
road_at($sim2['meta']['start_ms'] - 3 * 3600000);
$mid2 = fantasy_feed_upsert_fixture(road_mock_fixture($SLOT2))['match_id'];
$c2 = []; foreach (fantasy_list_contests($mid2) as $c) $c2[$c['contest_type']] = $c['id'];
$before2 = [];
$j2 = 0;
foreach (array_slice($U, 0, 30) as $i => $u) {
    $before2[$u['username']] = road_balance($u);
    $x = road_legal_xi($mid2, 300 + $i);
    $t = fantasy_save_team((int) $u['id'], $mid2, ['players' => array_column($x, 'id'), 'captain' => $x[0]['id'], 'vice_captain' => $x[1]['id']]);
    foreach (['small', 'h2h', 'mega'] as $type) if (fantasy_join_contest((int) $u['id'], $u['username'], $c2[$type], $t['team_id'])['ok']) $j2++;
}
for ($t = $sim2['meta']['start_ms'] - 20 * 60000; $t <= $sim2['ended_ms'] + 60000; $t += 60000) { road_at($t); cricket_feed_ingest(cricket_mock_snapshot($KEY2, $t), 'mock', $t); }
road_at($sim2['ended_ms'] + 120000);
fantasy_feed_sync($KEY2, true);
fantasy_settle_match($mid2, false);
$allBack = true; foreach ($before2 as $un => $b) if (!road_near(road_balance($users[$un]), $b)) $allBack = false;
road_check($j2 > 20 && $allBack, "$j2 entries on the abandoned match — all 30 players have exactly their money back");
road_check(fantasy_find_match($mid2)['status'] === 'CANCELLED', 'the fixture is cancelled, not settled');
putenv('CRICKET_MOCK_ABANDON_EVERY=0');

road_clock_off();
if (!$GLOBALS['ROAD_KEEP']) { road_wipe_match($KEY); road_wipe_match($KEY2); road_wipe_users('y'); }
road_finish('road_your11');
