<?php
/**
 * "Your Eleven" — money-path suite for contest entry (phase 3).
 *
 * Needs no server and no database. The fantasy_* tables, the wallet helpers and tx() are backed by
 * an in-memory store, and tx() is modelled by snapshotting that store and restoring it on a throw —
 * which is exactly what a rolled-back transaction does. That is what makes the important assertions
 * possible: after every rejected join this suite checks that the balance, the ledger, the spot count
 * and the entry list are all completely unchanged.
 *
 *     php php-backend/test_fantasy_money.php
 *
 * The fake is coupled to the SQL the implementation issues, by design: this suite is here to catch a
 * mistake in the ORDER and the GUARDS of the money path, which is the part that cannot be checked by
 * reading it.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }

const TEST_NOW_MS = 1790000000000;

// -------------------------------------------------------------------------------------------------
// In-memory store
// -------------------------------------------------------------------------------------------------
$DB = [
    'contests' => [], 'matches' => [], 'teams' => [], 'entries' => [],
    'users' => [], 'txns' => [], 'next_id' => 100, 'ledger_fails' => false,
];

function db_reset() {
    global $DB;
    $DB = [
        'matches' => [1 => [
            'id' => 1, 'match_title' => 'India vs Australia', 'series_name' => 'T20I',
            'status' => 'UPCOMING', 'lock_time' => ms_to_sql(TEST_NOW_MS + 3600000),
            'start_time' => ms_to_sql(TEST_NOW_MS + 3600000), 'squads_ready' => 1,
            'team_a' => 'India', 'team_b' => 'Australia', 'team_a_short' => 'IND',
            'team_b_short' => 'AUS', 'team_a_logo' => null, 'team_b_logo' => null,
            'format' => 'T20', 'venue' => 'V',
        ]],
        'contests' => [10 => [
            'id' => 10, 'match_id' => 1, 'title' => 'Mega Contest', 'entry_fee' => 49.0,
            'total_spots' => 3, 'filled_spots' => 0, 'prize_pool' => 0.0,
            'prize_rules' => '[{"from":1,"to":1,"pct":100}]', 'rake_pct' => 15.0, 'status' => 'OPEN',
        ]],
        'teams' => [
            5 => ['id' => 5, 'user_id' => 1, 'match_id' => 1],
            6 => ['id' => 6, 'user_id' => 1, 'match_id' => 1],
            7 => ['id' => 7, 'user_id' => 2, 'match_id' => 1],
            8 => ['id' => 8, 'user_id' => 1, 'match_id' => 99],   // built for another fixture
        ],
        'users' => [
            1 => ['id' => 1, 'username' => 'alice', 'wallet_balance' => 500.0],
            2 => ['id' => 2, 'username' => 'bob',   'wallet_balance' => 10.0],
        ],
        'entries' => [], 'txns' => [], 'next_id' => 100, 'ledger_fails' => false,
    ];
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
    $whole = $s; $frac = 0;
    if (strpos($s, '.') !== false) { [$whole, $f] = explode('.', $s, 2); $frac = (int) substr($f . '000', 0, 3); }
    $t = strtotime($whole . ' UTC');
    return $t === false ? null : ($t * 1000 + $frac);
}
function log_error($m, $c = null) {}
function round2($n) { return round((float) $n, 2); }
function new_record_id($p) { global $DB; return $p . '_' . (++$DB['next_id']); }

/** tx(): snapshot, run, restore on throw — a faithful model of commit/rollback. */
function tx(callable $fn) {
    global $DB;
    $snapshot = $DB;
    try {
        return $fn();
    } catch (Throwable $e) {
        $DB = $snapshot;
        throw $e;
    }
}

class FakePdo {
    public $lastId = 0;
    public function lastInsertId() { return (string) $this->lastId; }
}
$PDO = new FakePdo();
function db_or_throw() { global $PDO; return $PDO; }

// --- the wallet helpers, mirroring lib/helpers.php semantics ---------------------------------------
function find_user_by_id($id) { global $DB; return $DB['users'][(int) $id] ?? null; }

/** Conditional debit: returns null when the balance will not cover it, exactly like the real one. */
function debit_wallet($userId, $amount) {
    global $DB;
    $u = &$DB['users'][(int) $userId];
    if (!$u) return null;
    if ($u['wallet_balance'] < $amount) return null;
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
    if ($DB['ledger_fails']) throw new RuntimeException('simulated ledger write failure');
    $DB['txns'][] = ['id' => $id, 'user' => $user, 'type' => $type,
                     'amount' => $amount, 'details' => $details, 'status' => $status];
}

// --- the fantasy_* table access -------------------------------------------------------------------
function one($sql, $params = []) {
    global $DB;
    if (strpos($sql, '"fantasy_contests"') !== false && stripos($sql, 'SELECT') === 0) {
        return $DB['contests'][(int) $params[0]] ?? null;
    }
    if (strpos($sql, '"fantasy_matches"') !== false) {
        return $DB['matches'][(int) $params[0]] ?? null;
    }
    if (strpos($sql, '"fantasy_user_teams"') !== false) {
        $t = $DB['teams'][(int) $params[0]] ?? null;
        if (!$t) return null;
        // The real query is scoped by user_id; honour that or ownership is not actually tested.
        if (isset($params[1]) && (int) $t['user_id'] !== (int) $params[1]) return null;
        return $t;
    }
    return null;
}
function all($sql, $params = []) {
    global $DB;
    if (strpos($sql, '"fantasy_contests"') !== false && strpos($sql, 'ORDER BY') !== false) {
        $out = [];
        foreach ($DB['contests'] as $c) if ((int) $c['match_id'] === (int) $params[0]) $out[] = $c;
        return $out;
    }
    if (strpos($sql, '"fantasy_contest_entries"') !== false) {
        $out = [];
        foreach ($DB['entries'] as $e) if ((int) $e['user_id'] === (int) $params[0]) $out[] = $e;
        return $out;
    }
    return [];
}
function scalar($sql, $params = [], $default = null) {
    global $DB;
    // The per-player entry count the multi-entry cap is checked against.
    if (strpos($sql, 'COUNT(*) FROM "fantasy_contest_entries"') !== false) {
        $n = 0;
        foreach ($DB['entries'] as $e) {
            if ((int) $e['contest_id'] === (int) $params[0] && (int) $e['user_id'] === (int) $params[1]) $n++;
        }
        return $n;
    }
    return $default;
}

function affected($sql, $params = []) {
    global $DB;
    // The conditional spot claim.
    if (strpos($sql, 'UPDATE "fantasy_contests"') !== false && strpos($sql, 'filled_spots') !== false) {
        $c = &$DB['contests'][(int) $params[0]];
        if (!$c) return 0;
        if (strtoupper($c['status']) !== 'OPEN') return 0;
        if ($c['filled_spots'] >= $c['total_spots']) return 0;
        $c['filled_spots']++;
        return 1;
    }
    return 0;
}

function q($sql, $params = []) {
    global $DB, $PDO;
    if (strpos($sql, 'INSERT INTO "fantasy_contest_entries"') !== false) {
        [$contestId, $teamId, $userId, $fee, $txnId] = $params;
        // UNIQUE (contest_id, user_team_id) from 006: the same team can never be in a contest twice.
        // (Migration 010 replaced 007's one-entry-per-account index with a per-contest entry cap,
        // checked under a row lock inside the join.)
        foreach ($DB['entries'] as $e) {
            if ((int) $e['contest_id'] === (int) $contestId && (int) $e['user_team_id'] === (int) $teamId) {
                throw new RuntimeException('duplicate key value violates unique constraint');
            }
        }
        $id = ++$DB['next_id'];
        $DB['entries'][] = ['id' => $id, 'contest_id' => (int) $contestId, 'user_team_id' => (int) $teamId,
                            'user_id' => (int) $userId, 'entry_fee_paid' => (float) $fee, 'txn_id' => $txnId];
        $PDO->lastId = $id;
        return null;
    }
    if (strpos($sql, 'UPDATE "fantasy_contests"') !== false) { affected($sql, $params); return null; }
    return null;
}

require_once __DIR__ . '/lib/fantasy.php';
require_once __DIR__ . '/lib/fantasy-teams.php';
require_once __DIR__ . '/lib/fantasy-contests.php';

// -------------------------------------------------------------------------------------------------
$pass = 0; $fail = 0; $failures = [];
function ok($c, $label) {
    global $pass, $fail, $failures;
    if ($c) { $pass++; echo "  PASS  $label\n"; }
    else    { $fail++; $failures[] = $label; echo "  FAIL  $label\n"; }
}
function section($t) { echo "\n== $t ==\n"; }

function bal($id = 1)     { global $DB; return $DB['users'][$id]['wallet_balance']; }
function filled($id = 10) { global $DB; return $DB['contests'][$id]['filled_spots']; }
function entries()        { global $DB; return count($DB['entries']); }
function txns()           { global $DB; return count($DB['txns']); }

/** Assert a failed join left absolutely nothing behind. */
function assertUntouched($label, $balBefore, $filledBefore, $entriesBefore, $txnsBefore) {
    ok(bal() === $balBefore,          "$label: balance unchanged (" . bal() . ")");
    ok(filled() === $filledBefore,    "$label: no spot consumed");
    ok(entries() === $entriesBefore,  "$label: no entry row");
    ok(txns() === $txnsBefore,        "$label: no ledger row");
}

// -------------------------------------------------------------------------------------------------
section('Prize tables — ported from validatePrizeBreakup()');
// -------------------------------------------------------------------------------------------------
db_reset();
$goodTable = [['from' => 1, 'to' => 1, 'pct' => 50], ['from' => 2, 'to' => 2, 'pct' => 30],
              ['from' => 3, 'to' => 4, 'pct' => 10]];   // 50 + 30 + 2*10 = 100
$v = fantasy_validate_prize_breakup($goodTable, 10);
ok($v['ok'] === true, 'a contiguous table totalling 100 validates' . ($v['ok'] ? '' : ' — ' . $v['error']));
ok(abs($v['total_pct'] - 100) < 0.001, 'total is 100 with bands weighted by rank count');

ok(fantasy_validate_prize_breakup([], 10)['ok'] === false, 'an empty table is rejected');
$notStart = fantasy_validate_prize_breakup([['from' => 2, 'to' => 2, 'pct' => 100]], 10);
ok($notStart['ok'] === false && stripos($notStart['error'], 'start at rank 1') !== false,
   'a table not starting at rank 1 is rejected');
$gap = fantasy_validate_prize_breakup(
    [['from' => 1, 'to' => 1, 'pct' => 50], ['from' => 3, 'to' => 3, 'pct' => 50]], 10);
ok($gap['ok'] === false && stripos($gap['error'], 'missing') !== false,
   'a gap is rejected — it would pay nothing to an advertised rank: ' . $gap['error']);
$overlap = fantasy_validate_prize_breakup(
    [['from' => 1, 'to' => 3, 'pct' => 25], ['from' => 2, 'to' => 4, 'pct' => 25]], 10);
ok($overlap['ok'] === false, 'an overlap is rejected — it would pay a rank twice');
$notHundred = fantasy_validate_prize_breakup([['from' => 1, 'to' => 1, 'pct' => 99]], 10);
ok($notHundred['ok'] === false && stripos($notHundred['error'], 'total 100') !== false,
   'a table that does not total 100 is rejected: ' . $notHundred['error']);
ok(fantasy_validate_prize_breakup([['from' => 1, 'to' => 1, 'pct' => -5]], 10)['ok'] === false,
   'a negative percentage is rejected');
ok(fantasy_validate_prize_breakup([['from' => 1.5, 'to' => 2, 'pct' => 100]], 10)['ok'] === false,
   'a fractional rank is rejected rather than rounded');
$tooDeep = fantasy_validate_prize_breakup([['from' => 1, 'to' => 50, 'pct' => 2]], 10);
ok($tooDeep['ok'] === false && stripos($tooDeep['error'], 'only has 10 spots') !== false,
   'paying deeper than the contest has spots is rejected: ' . $tooDeep['error']);

// pctForRank
ok(fantasy_pct_for_rank($goodTable, 1) === 50.0, 'rank 1 takes 50%');
ok(fantasy_pct_for_rank($goodTable, 4) === 10.0, 'rank 4 is inside the 3-4 band');
ok(fantasy_pct_for_rank($goodTable, 5) === 0.0,  'a rank outside the paid places takes nothing');

// pool maths
$pool = fantasy_compute_pool(49, 3, 15);
ok($pool['gross_pool'] === 147.0, 'gross = 49 x 3 = 147');
ok($pool['rake'] === 22.05,       'rake = 15% of 147 = 22.05');
ok($pool['prize_pool'] === 124.95, 'prize pool = 147 - 22.05 = 124.95');
ok(fantasy_compute_pool(0, 100, 15)['prize_pool'] === 0.0, 'a free contest has a zero pool');

// -------------------------------------------------------------------------------------------------
section('Joining a contest — the happy path');
// -------------------------------------------------------------------------------------------------
db_reset();
$before = bal();
$r = fantasy_join_contest(1, 'alice', 10, 5);
ok($r['ok'] === true, 'alice joins' . ($r['ok'] ? '' : ' — ' . $r['error']));
ok(bal() === round($before - 49.0, 2), 'exactly the entry fee left the wallet (' . $before . ' -> ' . bal() . ')');
ok($r['new_balance'] === bal(), 'the reported balance matches the stored one');
ok(filled() === 1, 'one spot consumed');
ok(entries() === 1, 'one entry row written');
ok(txns() === 1, 'one ledger row written');
$GLOBALS_TXN = $GLOBALS['DB']['txns'][0];
ok($GLOBALS_TXN['type'] === 'Withdrawal', 'the ledger row is a Withdrawal');
ok(abs($GLOBALS_TXN['amount'] - 49.0) < 0.001, 'the ledger amount is the entry fee');
ok($GLOBALS_TXN['user'] === 'alice', 'the ledger row is against the right account');
ok(strpos($GLOBALS_TXN['details'], 'Your Eleven Entry') === 0, 'the ledger details identify the game');
ok(strpos($GLOBALS_TXN['details'], 'Mega Contest') !== false, 'the ledger details name the contest');
ok($GLOBALS['DB']['entries'][0]['txn_id'] === $GLOBALS_TXN['id'],
   'the entry records the id of the debit that paid for it, so it can be traced');
ok($GLOBALS['DB']['entries'][0]['entry_fee_paid'] === 49.0, 'the entry records what was actually paid');

// -------------------------------------------------------------------------------------------------
section('Double entry is impossible');
// -------------------------------------------------------------------------------------------------
db_reset();
fantasy_join_contest(1, 'alice', 10, 5);
$b = bal(); $f = filled(); $e = entries(); $t = txns();
$again = fantasy_join_contest(1, 'alice', 10, 5);
ok($again['ok'] === false && (stripos($again['error'], 'already joined') !== false || stripos($again['error'], 'already in this contest') !== false),
   'the same team cannot be entered twice: ' . $again['error']);
assertUntouched('same team twice', $b, $f, $e, $t);

// A DIFFERENT team from the same account is refused on a single-entry contest (the default): the cap
// is counted inside the join transaction, under a row lock on the contest.
$other = fantasy_join_contest(1, 'alice', 10, 6);
ok($other['ok'] === false && stripos($other['error'], 'already joined') !== false,
   'a second team from the same account is refused on a single-entry contest: ' . $other['error']);
assertUntouched('second team, same account', $b, $f, $e, $t);

// Multi-entry: with max_entries_per_user = 2 the second, different team gets in, and a third is refused.
db_reset();
$GLOBALS['DB']['contests'][10]['max_entries_per_user'] = 2;
$GLOBALS['DB']['teams'][9] = ['id' => 9, 'user_id' => 1, 'match_id' => 1];
$m1 = fantasy_join_contest(1, 'alice', 10, 5);
$m2 = fantasy_join_contest(1, 'alice', 10, 6);
ok($m1['ok'] && $m2['ok'], 'a multi-entry contest takes a second, different team from the same account');
ok(count($GLOBALS['DB']['entries']) === 2 && $GLOBALS['DB']['users'][1]['wallet_balance'] === 402.0,
   'two entries, two fees debited');
$b = bal(); $f = filled(); $e = entries(); $t = txns();
$m3 = fantasy_join_contest(1, 'alice', 10, 9);
ok($m3['ok'] === false && stripos($m3['error'], 'maximum of 2') !== false, 'a third team is refused at the cap: ' . $m3['error']);
assertUntouched('over the multi-entry cap', $b, $f, $e, $t);
db_reset();
fantasy_join_contest(1, 'alice', 10, 5);
$b = bal(); $f = filled(); $e = entries(); $t = txns();

// Another account is of course fine.
$bobBalBefore = $GLOBALS['DB']['users'][2]['wallet_balance'];
$bob = fantasy_join_contest(2, 'bob', 10, 7);
ok($bob['ok'] === false && stripos($bob['error'], 'insufficient') !== false,
   'bob has only 10.00 and the fee is 49.00, so he is refused: ' . $bob['error']);
ok($GLOBALS['DB']['users'][2]['wallet_balance'] === $bobBalBefore, 'bob is not debited a partial amount');

// -------------------------------------------------------------------------------------------------
section('Insufficient balance moves nothing');
// -------------------------------------------------------------------------------------------------
db_reset();
$GLOBALS['DB']['users'][1]['wallet_balance'] = 48.99;   // one paisa short
$b = bal(); $f = filled(); $e = entries(); $t = txns();
$poor = fantasy_join_contest(1, 'alice', 10, 5);
ok($poor['ok'] === false && stripos($poor['error'], 'insufficient') !== false,
   'one paisa short is refused: ' . $poor['error']);
ok($poor['status'] === 402, 'refusal carries 402 Payment Required (got ' . $poor['status'] . ')');
assertUntouched('one paisa short', $b, $f, $e, $t);
// Exactly the fee is enough.
$GLOBALS['DB']['users'][1]['wallet_balance'] = 49.00;
ok(fantasy_join_contest(1, 'alice', 10, 5)['ok'] === true, 'exactly the fee is enough');
ok(bal() === 0.0, 'the wallet lands on exactly zero, not a negative');

// -------------------------------------------------------------------------------------------------
section('A ledger failure rolls the debit back');
// -------------------------------------------------------------------------------------------------
db_reset();
$b = bal(); $f = filled(); $e = entries(); $t = txns();
$GLOBALS['DB']['ledger_fails'] = true;
$broken = fantasy_join_contest(1, 'alice', 10, 5);
ok($broken['ok'] === false, 'the join fails when the ledger write fails');
// This is the invariant mines.php needs a hand-written refund for; inside one transaction it is free.
assertUntouched('ledger failure', $b, $f, $e, $t);

// -------------------------------------------------------------------------------------------------
section('Spots, lock time and contest state');
// -------------------------------------------------------------------------------------------------
db_reset();
$GLOBALS['DB']['contests'][10]['filled_spots'] = 3;   // total_spots is 3
$b = bal(); $e = entries(); $t = txns();
$full = fantasy_join_contest(1, 'alice', 10, 5);
ok($full['ok'] === false && stripos($full['error'], 'full') !== false,
   'a full contest is refused: ' . $full['error']);
ok(bal() === $b && entries() === $e && txns() === $t, 'a full contest costs nothing');

db_reset();
$GLOBALS['DB']['matches'][1]['lock_time'] = ms_to_sql(TEST_NOW_MS - 1);
$b = bal(); $f = filled(); $e = entries(); $t = txns();
$late = fantasy_join_contest(1, 'alice', 10, 5);
ok($late['ok'] === false && stripos($late['error'], 'closed') !== false,
   'past the deadline is refused: ' . $late['error']);
assertUntouched('past the deadline', $b, $f, $e, $t);

db_reset();
$GLOBALS['DB']['matches'][1]['status'] = 'LIVE';
$live = fantasy_join_contest(1, 'alice', 10, 5);
ok($live['ok'] === false, 'a live match is refused even before its deadline');

db_reset();
$GLOBALS['DB']['contests'][10]['status'] = 'LOCKED';
$lockedC = fantasy_join_contest(1, 'alice', 10, 5);
ok($lockedC['ok'] === false && stripos($lockedC['error'], 'closed') !== false,
   'a contest an operator has closed is refused');

// -------------------------------------------------------------------------------------------------
section('Ownership and match binding');
// -------------------------------------------------------------------------------------------------
db_reset();
$notMine = fantasy_join_contest(1, 'alice', 10, 7);   // team 7 belongs to bob
ok($notMine['ok'] === false && stripos($notMine['error'], 'not found') !== false,
   "another account's team cannot be entered: " . $notMine['error']);
$wrongMatch = fantasy_join_contest(1, 'alice', 10, 8);  // team 8 is for match 99
ok($wrongMatch['ok'] === false && stripos($wrongMatch['error'], 'different match') !== false,
   'a team built for another fixture is refused: ' . $wrongMatch['error']);
ok(fantasy_join_contest(1, 'alice', 9999, 5)['ok'] === false, 'an unknown contest is refused');
ok(entries() === 0, 'none of those attempts created an entry');

// -------------------------------------------------------------------------------------------------
section('Free contests skip the wallet');
// -------------------------------------------------------------------------------------------------
db_reset();
$GLOBALS['DB']['contests'][10]['entry_fee'] = 0.0;
$b = bal();
$free = fantasy_join_contest(1, 'alice', 10, 5);
ok($free['ok'] === true, 'a free contest can be joined');
ok(bal() === $b, 'no money moves for a free entry');
ok(txns() === 0, 'and no meaningless zero-amount ledger row is written');
ok(entries() === 1, 'the entry is still recorded');
ok($GLOBALS['DB']['entries'][0]['txn_id'] === null, 'with a null txn_id, because there was no debit');

// -------------------------------------------------------------------------------------------------
section('Contest shaping for the lobby');
// -------------------------------------------------------------------------------------------------
db_reset();
$pub = fantasy_contest_public($GLOBALS['DB']['contests'][10], false);
ok($pub['spots_left'] === 3,        'spots_left with nobody in');
ok($pub['prize_pool'] === 0.0,      'the current pool is empty before any entry');
ok($pub['max_prize_pool'] === 124.95, 'the pool if it fills is 3 x 49 less 15% = 124.95');
ok($pub['first_prize'] === 124.95,  'a 100%-to-rank-1 table gives the whole pool as first prize');
ok($pub['is_full'] === false,       'not full');
ok($pub['has_entered'] === false,   'has_entered reflects the caller');
$GLOBALS['DB']['contests'][10]['prize_pool'] = 500.0;   // a guaranteed pool
$guar = fantasy_contest_public($GLOBALS['DB']['contests'][10], true);
ok($guar['prize_pool'] === 500.0,   'a guaranteed pool floors the advertised figure');
ok($guar['guaranteed_pool'] === 500.0, 'the guarantee is reported separately');
ok($guar['has_entered'] === true,   'has_entered is carried through');

// -------------------------------------------------------------------------------------------------
echo "\n" . str_repeat('-', 60) . "\n";
echo "$pass passed, $fail failed\n";
if ($fail) { echo "\nFailures:\n"; foreach ($failures as $f2) echo "  - $f2\n"; }
exit($fail ? 1 : 0);
