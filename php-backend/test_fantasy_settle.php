<?php
/**
 * "Your Eleven" — settlement money suite (phase 5).
 *
 * No server, no database. Same approach as test_fantasy_money.php: the tables and wallet helpers are
 * an in-memory store, and tx() is modelled by snapshotting it and restoring on a throw, so "nothing
 * moved" can be asserted after every refusal.
 *
 *     php php-backend/test_fantasy_settle.php
 *
 * The assertions that matter most:
 *   - the sum credited to wallets equals the sum allocated, to the paisa
 *   - settling twice pays once
 *   - settlement refuses until the SOURCE says the match is over
 *   - a void refunds exactly what was paid in, and cannot undo a settled contest
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }

const TEST_NOW_MS = 1790000000000;

$DB = [];

function db_reset() {
    global $DB;
    $DB = [
        'matches' => [1 => [
            'id' => 1, 'match_title' => 'India vs Australia', 'series_name' => 'T20I',
            'status' => 'LIVE', 'lock_time' => ms_to_sql(TEST_NOW_MS - 7200000),
            'start_time' => ms_to_sql(TEST_NOW_MS - 7200000), 'squads_ready' => 1,
            'team_a' => 'India', 'team_b' => 'Australia', 'team_a_short' => 'IND',
            'team_b_short' => 'AUS', 'team_a_logo' => null, 'team_b_logo' => null,
            'format' => 'T20', 'venue' => 'V', 'external_key' => 'cb:1',
            'source_state' => 'Complete', 'source_status_text' => 'India won by 8 runs',
        ]],
        'contests' => [10 => [
            'id' => 10, 'match_id' => 1, 'title' => 'Mega Contest', 'entry_fee' => 100.0,
            'total_spots' => 10, 'filled_spots' => 5, 'prize_pool' => 0.0, 'rake_pct' => 0.0,
            'prize_rules' => '[{"from":1,"to":1,"pct":50},{"from":2,"to":2,"pct":30},{"from":3,"to":4,"pct":10}]',
            'status' => 'OPEN',
        ]],
        // Five entries, distinct points, so every paid rank exists and the pool must clear exactly.
        'entries' => [
            1 => ['id' => 1, 'contest_id' => 10, 'user_id' => 1, 'user_team_id' => 101,
                  'entry_fee_paid' => 100.0, 'points' => 300.0, 'rank' => null, 'prize_won' => 0.0,
                  'is_settled' => 0, 'created_at' => '2026-01-01 00:00:01.000'],
            2 => ['id' => 2, 'contest_id' => 10, 'user_id' => 2, 'user_team_id' => 102,
                  'entry_fee_paid' => 100.0, 'points' => 250.0, 'rank' => null, 'prize_won' => 0.0,
                  'is_settled' => 0, 'created_at' => '2026-01-01 00:00:02.000'],
            3 => ['id' => 3, 'contest_id' => 10, 'user_id' => 3, 'user_team_id' => 103,
                  'entry_fee_paid' => 100.0, 'points' => 200.0, 'rank' => null, 'prize_won' => 0.0,
                  'is_settled' => 0, 'created_at' => '2026-01-01 00:00:03.000'],
            4 => ['id' => 4, 'contest_id' => 10, 'user_id' => 4, 'user_team_id' => 104,
                  'entry_fee_paid' => 100.0, 'points' => 150.0, 'rank' => null, 'prize_won' => 0.0,
                  'is_settled' => 0, 'created_at' => '2026-01-01 00:00:04.000'],
            5 => ['id' => 5, 'contest_id' => 10, 'user_id' => 5, 'user_team_id' => 105,
                  'entry_fee_paid' => 100.0, 'points' => 100.0, 'rank' => null, 'prize_won' => 0.0,
                  'is_settled' => 0, 'created_at' => '2026-01-01 00:00:05.000'],
        ],
        'teams' => [],
        'users' => [
            1 => ['id' => 1, 'username' => 'alice', 'wallet_balance' => 0.0],
            2 => ['id' => 2, 'username' => 'bob',   'wallet_balance' => 0.0],
            3 => ['id' => 3, 'username' => 'carol', 'wallet_balance' => 0.0],
            4 => ['id' => 4, 'username' => 'dave',  'wallet_balance' => 0.0],
            5 => ['id' => 5, 'username' => 'erin',  'wallet_balance' => 0.0],
        ],
        'txns' => [], 'next_id' => 500,
    ];
    foreach ([101, 102, 103, 104, 105] as $i) {
        $DB['teams'][$i] = ['id' => $i, 'user_id' => $i - 100, 'match_id' => 1, 'rank' => null];
    }
}

// --- platform stubs -------------------------------------------------------------------------------
function env_get($n, $d = null) { return $d; }
function env_bool($n, $d)       { return $d; }
function now_ms()               { return TEST_NOW_MS; }
function ms_to_sql($ms) {
    $ms = (int) $ms;
    return gmdate('Y-m-d H:i:s', (int) floor($ms / 1000)) . '.' . sprintf('%03d', $ms % 1000);
}
function sql_to_ms($s) {
    if ($s === null || $s === '') return null;
    $whole = $s;
    if (strpos($s, '.') !== false) [$whole, ] = explode('.', $s, 2);
    $t = strtotime($whole . ' UTC');
    return $t === false ? null : $t * 1000;
}
function log_error($m, $c = null) {}
function round2($n) { return round((float) $n, 2); }
function new_record_id($p) { global $DB; return $p . '_' . (++$DB['next_id']); }

function tx(callable $fn) {
    global $DB;
    $snap = $DB;
    try { return $fn(); }
    catch (Throwable $e) { $DB = $snap; throw $e; }
}

class FakePdo { public $lastId = 0; public function lastInsertId() { return (string) $this->lastId; } }
$PDO = new FakePdo();
function db_or_throw() { global $PDO; return $PDO; }

function find_user_by_id($id) { global $DB; return $DB['users'][(int) $id] ?? null; }
function debit_wallet($userId, $amount) {
    global $DB;
    $u = &$DB['users'][(int) $userId];
    if (!$u || $u['wallet_balance'] < $amount) return null;
    $u['wallet_balance'] = round($u['wallet_balance'] - $amount, 2);
    return $u['wallet_balance'];
}
function credit_wallet($userId, $amount) {
    global $DB;
    $u = &$DB['users'][(int) $userId];
    if (!$u) return null;
    $u['wallet_balance'] = round($u['wallet_balance'] + $amount, 2);
    return $u['wallet_balance'];
}
function insert_transaction($id, $user, $type, $amount, $details, $status, $ts = null) {
    global $DB;
    $DB['txns'][] = ['id' => $id, 'user' => $user, 'type' => $type, 'amount' => round((float) $amount, 2),
                     'details' => $details, 'status' => $status];
}

// --- table access ---------------------------------------------------------------------------------
function one($sql, $params = []) {
    global $DB;
    if (strpos($sql, '"fantasy_contests"') !== false) return $DB['contests'][(int) $params[0]] ?? null;
    if (strpos($sql, '"fantasy_matches"') !== false)  return $DB['matches'][(int) $params[0]] ?? null;
    return null;
}
function all($sql, $params = []) {
    global $DB;
    // entries joined to User, for settlement and for the leaderboard
    if (strpos($sql, '"fantasy_contest_entries"') !== false && strpos($sql, '"User"') !== false) {
        $out = [];
        foreach ($DB['entries'] as $e) {
            if ((int) $e['contest_id'] !== (int) $params[0]) continue;
            if (strpos($sql, '"is_settled" = 0') !== false && (int) $e['is_settled'] !== 0) continue;
            $row = $e;
            $row['username'] = $DB['users'][(int) $e['user_id']]['username'];
            $row['team_name'] = 'Team ' . $e['user_team_id'];
            $out[] = $row;
        }
        return $out;
    }
    if (strpos($sql, '"fantasy_contests"') !== false) {
        $out = [];
        foreach ($DB['contests'] as $c) {
            if ((int) $c['match_id'] !== (int) $params[0]) continue;
            if (strpos($sql, '"status" <> ?') !== false
                && in_array(strtoupper($c['status']), ['SETTLED', 'CANCELLED'], true)) continue;
            $out[] = $c;
        }
        return $out;
    }
    return [];
}
function scalar($sql, $params = [], $default = null) {
    global $DB;
    if (strpos($sql, 'SUM("entry_fee_paid")') !== false) {
        $sum = 0.0;
        foreach ($DB['entries'] as $e) if ((int) $e['contest_id'] === (int) $params[0]) $sum += $e['entry_fee_paid'];
        return $sum;
    }
    if (strpos($sql, 'COUNT(*) FROM "fantasy_contests"') !== false) {
        $n = 0;
        foreach ($DB['contests'] as $c) {
            if ((int) $c['match_id'] !== (int) $params[0]) continue;
            if (in_array(strtoupper($c['status']), ['SETTLED', 'CANCELLED'], true)) continue;
            $n++;
        }
        return $n;
    }
    if (strpos($sql, '"external_key"') !== false) {
        return $DB['matches'][(int) $params[0]]['external_key'] ?? '';
    }
    return $default;
}
function affected($sql, $params = []) {
    global $DB;
    // The conditional settle/void claim — the idempotency guard.
    if (strpos($sql, 'UPDATE "fantasy_contests" SET "status"') !== false) {
        $newStatus = (string) $params[0];
        $id = (int) $params[1];
        $c = &$DB['contests'][$id];
        if (!$c) return 0;
        $cur = strtoupper($c['status']);
        if ($cur === 'SETTLED' || $cur === 'CANCELLED') return 0;
        $c['status'] = $newStatus;
        return 1;
    }
    return 0;
}
function q($sql, $params = []) {
    global $DB;
    if (strpos($sql, 'UPDATE "fantasy_contest_entries"') !== false) {
        if (strpos($sql, '"rank" = ?') !== false) {
            [$rank, $prize, $when, $id] = $params;
            $e = &$DB['entries'][(int) $id];
            if ($e) { $e['rank'] = (int) $rank; $e['prize_won'] = round((float) $prize, 2);
                      $e['is_settled'] = 1; $e['settled_at'] = $when; }
        } else {
            [$when, $id] = $params;
            $e = &$DB['entries'][(int) $id];
            if ($e) { $e['prize_won'] = 0.0; $e['is_settled'] = 1; $e['settled_at'] = $when; }
        }
        return null;
    }
    if (strpos($sql, 'UPDATE "fantasy_user_teams"') !== false) {
        [$rank, $entryId] = $params;
        $e = $DB['entries'][(int) $entryId] ?? null;
        if ($e && isset($DB['teams'][(int) $e['user_team_id']])) {
            $DB['teams'][(int) $e['user_team_id']]['rank'] = (int) $rank;
        }
        return null;
    }
    if (strpos($sql, 'UPDATE "fantasy_contests"') !== false) { affected($sql, $params); return null; }
    if (strpos($sql, 'UPDATE "fantasy_matches"') !== false) {
        $DB['matches'][(int) end($params)]['status'] = (string) $params[0];
        return null;
    }
    return null;
}

require_once __DIR__ . '/lib/fantasy.php';
require_once __DIR__ . '/lib/fantasy-source.php';
require_once __DIR__ . '/lib/fantasy-contests.php';
require_once __DIR__ . '/lib/fantasy-settle.php';

// -------------------------------------------------------------------------------------------------
$pass = 0; $fail = 0; $failures = [];
function ok($c, $label) {
    global $pass, $fail, $failures;
    if ($c) { $pass++; echo "  PASS  $label\n"; }
    else    { $fail++; $failures[] = $label; echo "  FAIL  $label\n"; }
}
function section($t) { echo "\n== $t ==\n"; }
function bal($id) { global $DB; return $DB['users'][$id]['wallet_balance']; }
function walletTotal() { global $DB; $t = 0.0; foreach ($DB['users'] as $u) $t += $u['wallet_balance']; return round($t, 2); }
function txnTotal($type = 'Deposit') {
    global $DB; $t = 0.0;
    foreach ($DB['txns'] as $x) if ($x['type'] === $type) $t += $x['amount'];
    return round($t, 2);
}

// -------------------------------------------------------------------------------------------------
section('Settling a contest — money in equals money out');
// -------------------------------------------------------------------------------------------------
db_reset();
$r = fantasy_settle_contest(10);
ok($r['ok'] === true, 'the contest settles' . ($r['ok'] ? '' : ' — ' . $r['error']));
// 5 x 100 collected, 0% rake -> pool 500. Breakup 50/30/10/10 with 5 distinct scores pays every rank.
ok($r['prize_pool'] === 500.0, 'pool is 5 x 100 with no rake = 500 (got ' . $r['prize_pool'] . ')');
ok($r['paid'] === 500.0, 'the whole pool is paid out (got ' . $r['paid'] . ')');
ok($r['undistributed'] === 0.0, 'nothing is left undistributed');
ok($r['winners'] === 4, 'four ranks are paid, the fifth wins nothing (got ' . $r['winners'] . ')');

ok(bal(1) === 250.0, 'rank 1 takes 50% of 500 = 250');
ok(bal(2) === 150.0, 'rank 2 takes 30% = 150');
ok(bal(3) === 50.0,  'rank 3 takes 10% = 50');
ok(bal(4) === 50.0,  'rank 4 takes 10% = 50');
ok(bal(5) === 0.0,   'rank 5 is outside the paid places');
ok(walletTotal() === 500.0, 'the wallets gained exactly the pool');
ok(txnTotal('Deposit') === 500.0, 'the ledger records exactly the pool as Deposits');
ok(count($GLOBALS['DB']['txns']) === 4, 'one ledger row per winner, none for the non-winner');
ok(strpos($GLOBALS['DB']['txns'][0]['details'], 'Your Eleven Prize') === 0,
   'the ledger details identify the game');
ok(strpos($GLOBALS['DB']['txns'][0]['details'], 'rank #1') !== false,
   'and record which rank was paid');

ok($GLOBALS['DB']['entries'][1]['rank'] === 1, 'the entry stores its rank');
ok($GLOBALS['DB']['entries'][1]['prize_won'] === 250.0, 'and its prize');
$allSettled = true;
foreach ($GLOBALS['DB']['entries'] as $e) if ((int) $e['is_settled'] !== 1) $allSettled = false;
ok($allSettled, 'EVERY entry is marked settled, losers included, so a retry does not look unfinished');
ok($GLOBALS['DB']['contests'][10]['status'] === 'SETTLED', 'the contest is closed');
ok($GLOBALS['DB']['teams'][101]['rank'] === 1, "the team's rank is recorded too");

// -------------------------------------------------------------------------------------------------
section('Settling twice pays once');
// -------------------------------------------------------------------------------------------------
$walletAfterFirst = walletTotal();
$txnsAfterFirst = count($GLOBALS['DB']['txns']);
$again = fantasy_settle_contest(10);
ok($again['ok'] === true, 'a second settle is reported ok, not an error — a retried worker is normal');
ok(!empty($again['already_settled']), 'it says already_settled');
ok($again['paid'] === 0.0, 'and pays nothing');
ok(walletTotal() === $walletAfterFirst, 'no wallet changed on the second attempt');
ok(count($GLOBALS['DB']['txns']) === $txnsAfterFirst, 'no extra ledger row was written');

// -------------------------------------------------------------------------------------------------
section('Ties split the pooled slots, and still clear the pool');
// -------------------------------------------------------------------------------------------------
db_reset();
// Three-way tie for 2nd: they pool ranks 2, 3 and 4 = 30 + 10 + 10 = 50% of 500 = 250, split three
// ways as 83.34 / 83.33 / 83.33.
$GLOBALS['DB']['entries'][2]['points'] = 250.0;
$GLOBALS['DB']['entries'][3]['points'] = 250.0;
$GLOBALS['DB']['entries'][4]['points'] = 250.0;
$r = fantasy_settle_contest(10);
ok($r['ok'] === true, 'a contest with a three-way tie settles');
ok(bal(1) === 250.0, 'rank 1 is unaffected by the tie below it');
ok(abs((bal(2) + bal(3) + bal(4)) - 250.0) < 0.005,
   'the three tied entries share 250 between them (got ' . (bal(2) + bal(3) + bal(4)) . ')');
ok(bal(2) === 83.34, 'the undividable remainder goes to the first of the tied group');
ok(bal(3) === 83.33 && bal(4) === 83.33, 'the other two take the floored share');
ok(walletTotal() === 500.0, 'the pool still clears exactly, to the paisa');
ok($r['paid'] === 500.0, 'and the reported total matches');
$ranks = [];
foreach ($GLOBALS['DB']['entries'] as $e) $ranks[] = $e['rank'];
ok($ranks === [1, 2, 2, 2, 5], 'ranks skip the way sport does: 1,2,2,2,5 — nobody is 3rd or 4th');

// -------------------------------------------------------------------------------------------------
section('Settlement waits for evidence, not a clock');
// -------------------------------------------------------------------------------------------------
db_reset();
$GLOBALS['DB']['matches'][1]['source_state'] = 'In Progress';
$before = walletTotal();
$early = fantasy_settle_contest(10);
ok($early['ok'] === false, 'a match still in progress is not settled');
ok(stripos($early['error'], 'not reported finished') !== false, 'and says why: ' . $early['error']);
ok(walletTotal() === $before, 'no money moved');
ok($GLOBALS['DB']['contests'][10]['status'] === 'OPEN', 'the contest is left open');

$GLOBALS['DB']['matches'][1]['source_state'] = null;
ok(fantasy_settle_contest(10)['ok'] === false, 'an unknown source state also refuses');

$GLOBALS['DB']['matches'][1]['source_state'] = 'Stumps';
ok(fantasy_settle_contest(10)['ok'] === false,
   'stumps in a multi-day match is NOT the end and must not settle');

// force overrides, and says so in the ledger
$forced = fantasy_settle_contest(10, true);
ok($forced['ok'] === true, 'an operator can force a settle');
ok($forced['forced'] === true, 'the result records that it was forced');
$sawNote = false;
foreach ($GLOBALS['DB']['txns'] as $x) if (strpos($x['details'], '[settled manually]') !== false) $sawNote = true;
ok($sawNote, 'the ledger detail marks it, so a forced settle can be told apart afterwards');

// -------------------------------------------------------------------------------------------------
section('An unfilled contest leaves money undistributed — and reports it');
// -------------------------------------------------------------------------------------------------
db_reset();
// Only two entries, so ranks 3 and 4 never exist and their 20% is never paid.
unset($GLOBALS['DB']['entries'][3], $GLOBALS['DB']['entries'][4], $GLOBALS['DB']['entries'][5]);
$r = fantasy_settle_contest(10);
ok($r['ok'] === true, 'it settles');
ok($r['prize_pool'] === 200.0, 'pool is 2 x 100 = 200');
ok($r['paid'] === 160.0, 'only ranks 1 and 2 exist, so 80% is paid = 160');
ok($r['undistributed'] === 40.0,
   'the unreached 20% is reported as undistributed, not buried (got ' . $r['undistributed'] . ')');
ok(walletTotal() === 160.0, 'the wallets gained only what was actually allocated');

// -------------------------------------------------------------------------------------------------
section('Voiding refunds exactly what was paid in');
// -------------------------------------------------------------------------------------------------
db_reset();
$v = fantasy_void_contest(10, 'contest did not fill');
ok($v['ok'] === true, 'the contest voids');
ok($v['entries'] === 5, 'all five entries are refunded');
ok($v['refunded'] === 500.0, 'the refund equals everything collected (got ' . $v['refunded'] . ')');
ok(walletTotal() === 500.0, 'the wallets are made whole');
foreach ([1, 2, 3, 4, 5] as $u) {
    if (bal($u) !== 100.0) { ok(false, "user $u refunded exactly their own 100"); break; }
}
ok(bal(1) === 100.0 && bal(5) === 100.0, 'each player gets back exactly their own entry fee');
ok($GLOBALS['DB']['contests'][10]['status'] === 'CANCELLED', 'the contest is cancelled');
$sawReason = false;
foreach ($GLOBALS['DB']['txns'] as $x) if (strpos($x['details'], 'did not fill') !== false) $sawReason = true;
ok($sawReason, 'the reason is recorded in the ledger so a player can see why');

$v2 = fantasy_void_contest(10, 'again');
ok($v2['ok'] === true && !empty($v2['already_cancelled']), 'voiding twice is a no-op');
ok(walletTotal() === 500.0, 'and refunds nothing a second time');

// A settled contest cannot be unpaid.
db_reset();
fantasy_settle_contest(10);
$afterSettle = walletTotal();
$bad = fantasy_void_contest(10, 'oops');
ok($bad['ok'] === false && stripos($bad['error'], 'already been settled') !== false,
   'a settled contest cannot be voided: ' . $bad['error']);
ok(walletTotal() === $afterSettle, 'and nothing moved when that was refused');

// -------------------------------------------------------------------------------------------------
section('Settling a whole match');
// -------------------------------------------------------------------------------------------------
db_reset();
$m = fantasy_settle_match(1);
ok($m['ok'] === true, 'the match settles');
ok($m['contests'] === 1, 'one contest was settled');
ok($m['paid'] === 500.0, 'the payout is reported at match level');
ok(!empty($m['match_settled']), 'and the fixture is marked settled once nothing is left open');
ok($GLOBALS['DB']['matches'][1]['status'] === 'SETTLED', 'the fixture status is SETTLED');

// A fixture with an unsettleable contest must stay open for a retry.
db_reset();
$GLOBALS['DB']['contests'][11] = array_merge($GLOBALS['DB']['contests'][10],
    ['id' => 11, 'title' => 'Second', 'status' => 'CANCELLED']);
$m2 = fantasy_settle_match(1);
ok($m2['ok'] === true, 'a cancelled sibling contest does not block the match');
ok(!empty($m2['match_settled']), 'and the fixture still settles, since nothing is left open');

// -------------------------------------------------------------------------------------------------
section('Leaderboard: provisional before, authoritative after');
// -------------------------------------------------------------------------------------------------
db_reset();
$board = fantasy_contest_leaderboard(10);
ok($board !== null, 'the leaderboard loads');
ok($board['provisional'] === true, 'before settlement it is flagged provisional');
ok($board['is_settled'] === false, 'and not settled');
ok(count($board['entries']) === 5, 'all five entries are listed');
ok($board['entries'][0]['rank'] === 1 && $board['entries'][0]['points'] === 300.0,
   'the highest score leads');
ok($board['entries'][0]['prize'] === 250.0, 'with its projected prize');

fantasy_settle_contest(10);
$after = fantasy_contest_leaderboard(10);
ok($after['provisional'] === false, 'after settlement it is no longer provisional');
ok($after['is_settled'] === true, 'and reports settled');
ok($after['entries'][0]['prize'] === 250.0, 'the stored prize is returned, matching what was paid');
ok($after['entries'][0]['rank'] === 1, 'with the stored rank');

// -------------------------------------------------------------------------------------------------
echo "\n" . str_repeat('-', 60) . "\n";
echo "$pass passed, $fail failed\n";
if ($fail) { echo "\nFailures:\n"; foreach ($failures as $f2) echo "  - $f2\n"; }
exit($fail ? 1 : 0);
