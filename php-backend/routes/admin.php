<?php
/**
 * Operator analytics: platform stats, the rig audit report, the super dashboard and per-game
 * betting aggregates.
 *
 * The whole /api/admin namespace is gated once at the top of this file.
 */

require_once __DIR__ . '/../lib/http.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/botengine.php';
require_once __DIR__ . '/../lib/rigaudit.php';
require_once __DIR__ . '/../lib/adminlog.php';

/**
 * Which game a Transaction's free-text `details` belongs to, and whether it was a wager (stake
 * taken from a player) or a win (payout given to a player).
 *
 * Cashier deposits/withdrawals and the signup welcome bonus are deliberately excluded — they are
 * the player moving their own funds, not a bet outcome, so they do not belong in house-profit or
 * win/loss figures.
 */
function classify_gameplay_transaction($details) {
    if (!is_string($details) || $details === '') return null;
    if (strpos($details, 'UPI Deposit') === 0) return null;
    if (strpos($details, 'Withdrawal Request') === 0) return null;
    if ($details === 'Welcome Bonus Credits') return null;

    if (strpos($details, 'Color Guess Wager') !== false)      return ['game' => 'color_guess', 'kind' => 'wager'];
    if (strpos($details, 'Color Guess Win Payout') !== false) return ['game' => 'color_guess', 'kind' => 'win'];
    if (strpos($details, 'Aviator Wager') !== false)          return ['game' => 'aviator', 'kind' => 'wager'];
    if (strpos($details, 'Aviator Payout') !== false)         return ['game' => 'aviator', 'kind' => 'win'];
    if (strpos($details, 'Teen Patti Boot') !== false || strpos($details, 'Teen Patti Chaal') !== false) {
        return ['game' => 'teenpatti', 'kind' => 'wager'];
    }
    if (strpos($details, 'Teen Patti Won Pot') !== false)     return ['game' => 'teenpatti', 'kind' => 'win'];
    if (strpos($details, 'Mines Bet') !== false)              return ['game' => 'mines', 'kind' => 'wager'];
    if (strpos($details, 'Mines Cash Out') !== false)         return ['game' => 'mines', 'kind' => 'win'];
    if (strpos($details, 'Chicken Road Bet') === 0)           return ['game' => 'chickenroad', 'kind' => 'wager'];
    if (strpos($details, 'Chicken Road Cash Out') === 0)      return ['game' => 'chickenroad', 'kind' => 'win'];
    if (strpos($details, 'Astronaut Bet Round') === 0)        return ['game' => 'astronaut', 'kind' => 'wager'];
    // A cancelled bet is the stake handed back, so it nets against its wager like a win would.
    if (strpos($details, 'Astronaut Bet Cancelled') === 0)    return ['game' => 'astronaut', 'kind' => 'win'];
    if (strpos($details, 'Astronaut Cash Out') === 0)         return ['game' => 'astronaut', 'kind' => 'win'];
    if (strpos($details, 'Tower Rush Bet') === 0)             return ['game' => 'towerrush', 'kind' => 'wager'];
    if (strpos($details, 'Tower Rush Cash Out') === 0)        return ['game' => 'towerrush', 'kind' => 'win'];
    // Teen Patti's Show stake (it had no ledger row at all before).
    if (strpos($details, 'Teen Patti Show') === 0)            return ['game' => 'teenpatti', 'kind' => 'wager'];
    // The cricket games were not counted anywhere on this dashboard.
    if (strpos($details, 'Ball by Ball Bet') === 0)           return ['game' => 'bbb', 'kind' => 'wager'];
    if (strpos($details, 'Ball by Ball Win') === 0)           return ['game' => 'bbb', 'kind' => 'win'];
    if (strpos($details, 'Ball by Ball Refund') === 0)        return ['game' => 'bbb', 'kind' => 'win'];
    if (strpos($details, 'Cricket Bet') === 0)                return ['game' => 'cricket', 'kind' => 'wager'];
    if (strpos($details, 'Cricket Win') === 0)                return ['game' => 'cricket', 'kind' => 'win'];
    if (strpos($details, 'Cricket Refund') === 0)             return ['game' => 'cricket', 'kind' => 'win'];
    if (strpos($details, 'Cricket Cash Out') === 0)           return ['game' => 'cricket', 'kind' => 'win'];
    if (strpos($details, 'Your Eleven Entry') === 0)          return ['game' => 'youreleven', 'kind' => 'wager'];
    if (strpos($details, 'Your Eleven Prize') === 0)          return ['game' => 'youreleven', 'kind' => 'win'];
    if (strpos($details, 'Your Eleven Refund') === 0)         return ['game' => 'youreleven', 'kind' => 'win'];
    // Referral commission is money the house pays out, so it is a cost against profit. It has no
    // wager side: its row shows only what was paid.
    if ($details === 'Referral Bonus Claimed')               return ['game' => 'referral', 'kind' => 'win'];
    return null;
}

function game_labels() {
    return [
        'color_guess' => 'Color Prediction',
        'aviator'     => 'Aviator',
        'teenpatti'   => 'Teen Patti',
        'mines'       => 'Mines',
        'chickenroad' => 'Chicken Road',
        'astronaut'   => 'Astronaut',
        'towerrush'   => 'Tower Rush',
        'bbb'         => 'Ball by Ball',
        'youreleven'  => 'Your 11',
        'cricket'     => 'Cricket (match betting)',
        'referral'    => 'Referral commission paid',
    ];
}

/**
 * What each game is BUILT to pay back, from its rules and the operator's current settings — shown
 * beside what it actually paid back, so the operator can see both the design and the reality.
 * Over many bets "actual" drifts toward "built"; over a few days it can be well above or below.
 */
function game_design_payback() {
    $pct = function ($x) { return rtrim(rtrim(number_format((float) $x * 100, 1, '.', ''), '0'), '.') . '%'; };
    $out = [
        'color_guess' => '39–98% depending on bet',
        'aviator'     => '96%',
        // Only claim a commission when the running Teen Patti code actually takes one (tp_commission).
        'teenpatti'   => function_exists('tp_commission')
            ? 'house keeps ' . js_num_str((float) cfg('TEENPATTI_COMMISSION_PCT', 10)) . '% of pots players win'
            : 'no house commission on pots',
        'mines'       => '96% at most',
        'youreleven'  => '85% (15% rake per contest)',
        'referral'    => '—',
    ];
    try { if (function_exists('cr_config_get'))    $out['chickenroad'] = $pct(cr_config_get()['rtp']) . ' at most'; } catch (Throwable $e) {}
    try { if (function_exists('astro_config_get')) $out['astronaut']   = $pct(astro_config_get()['rtp']); } catch (Throwable $e) {}
    try { if (function_exists('tr_config_get'))    $out['towerrush']   = $pct(tr_config_get()['rtp']) . ' per floor'; } catch (Throwable $e) {}
    try { if (function_exists('bbb_config'))       $out['bbb']         = $pct(1 - ((float) bbb_config()['rake_pct']) / 100) . ' of each pool'; } catch (Throwable $e) {}
    try {
        if (function_exists('mx_config')) {
            $c = mx_config();
            $out['cricket'] = 'margin ' . $pct($c['mo_margin']) . ' / ' . $pct($c['bm_margin']);
        }
    } catch (Throwable $e) {}
    return $out;
}

/**
 * What each game normally pays back per rupee staked (as a fraction), for the player win monitor.
 * Where a game's return depends on the bet type, the HIGHEST figure is used, so an ordinary player
 * is never flagged just for picking the better bets. Teen Patti has no fixed return (players play
 * each other and the bots), so its figure is filled in from the platform's own average.
 */
function game_expected_payback() {
    $out = [
        'color_guess' => 0.98, 'aviator' => 0.96, 'mines' => 0.96, 'youreleven' => 0.85, 'cricket' => 0.97,
        'teenpatti' => null, 'chickenroad' => 0.97, 'astronaut' => 0.97, 'towerrush' => 0.97, 'bbb' => 0.90,
    ];
    try { if (function_exists('cr_config_get'))    $out['chickenroad'] = (float) cr_config_get()['rtp']; } catch (Throwable $e) {}
    try { if (function_exists('astro_config_get')) $out['astronaut']   = (float) astro_config_get()['rtp']; } catch (Throwable $e) {}
    try { if (function_exists('tr_config_get'))    $out['towerrush']   = (float) tr_config_get()['rtp']; } catch (Throwable $e) {}
    try { if (function_exists('bbb_config'))       $out['bbb']         = 1 - ((float) bbb_config()['rake_pct']) / 100; } catch (Throwable $e) {}
    try { if (function_exists('mx_config'))        $out['cricket']     = 1 - (float) mx_config()['mo_margin']; } catch (Throwable $e) {}
    return $out;
}

/**
 * How widely one bet's payout swings, as a multiple of its stake (a standard deviation). Used to
 * judge how much of a player's winnings luck can explain. Deliberately generous (wider than most
 * real bets), so a flag means "very hard to explain by luck", not "a good week".
 */
function game_payout_spread() {
    return ['color_guess' => 1.5, 'aviator' => 2.5, 'mines' => 1.8, 'youreleven' => 3.0, 'cricket' => 1.2,
            'teenpatti' => 1.8, 'chickenroad' => 1.8, 'astronaut' => 2.5, 'towerrush' => 1.8, 'bbb' => 2.0];
}

/** Upper tail of the normal distribution: the chance of doing at least this well by luck. */
function normal_upper_tail($z) {
    // Abramowitz & Stegun 7.1.26 (error under 1.5e-7), enough for "about 1 in N".
    $x = abs($z) / M_SQRT2;
    $t = 1 / (1 + 0.3275911 * $x);
    $erfc = $t * (0.254829592 + $t * (-0.284496736 + $t * (1.421413741 + $t * (-1.453152027 + $t * 1.061405429)))) * exp(-$x * $x);
    return $z >= 0 ? 0.5 * $erfc : 1 - 0.5 * $erfc;
}

/**
 * Test / internal accounts the super admin has marked to leave OUT of the money analytics.
 *
 * These are accounts the operator funds with virtual coins to test the games; counting their bets,
 * wins, deposits, withdrawals or wallet balance would distort the real cash-flow picture. The list
 * is a set of lowercased usernames in GameState, and every super-admin analytics endpoint skips
 * anyone in it. The accounts still exist and still play — they are only removed from the figures.
 */
function super_excluded_set() {
    try { $list = state_get('superadmin_excluded_users'); } catch (Throwable $e) { $list = null; }
    $out = [];
    if (is_array($list)) foreach ($list as $u) { $u = strtolower(trim((string) $u)); if ($u !== '') $out[$u] = true; }
    return $out;
}
function super_excluded_save(array $usernames) {
    $seen = [];
    foreach ($usernames as $u) { $u = strtolower(trim((string) $u)); if ($u !== '' && $u !== 'admin' && !isset($seen[$u])) $seen[$u] = true; }
    $list = array_keys($seen);
    sort($list);
    state_set('superadmin_excluded_users', $list);
    return $list;
}

function register_admin_routes(Router $app) {

    // Operator-only from here on: house statistics, the super dashboard and the rig consoles.
    $app->useMw('/api/admin', 'require_admin');

    // --- Platform stats ---
    $app->get('/api/admin/stats', function (Req $req, Res $res) {
        try {
            $totalUsers = 0; $deposits = []; $withdrawals = []; $users = [];
            try {
                $totalUsers  = (int) scalar('SELECT COUNT(*) FROM "User"', [], 0);
                $deposits    = all("SELECT * FROM \"Deposit\" WHERE \"status\" = 'Completed'");
                $withdrawals = all('SELECT * FROM "Withdrawal"');
                $users       = all('SELECT * FROM "User"');
            } catch (Throwable $e) {
                $users = readJsonTable('users');
                $totalUsers = count($users);
                $deposits = array_values(array_filter(readJsonTable('deposits'),
                    function ($d) { return ($d['status'] ?? null) === 'Completed'; }));
                $withdrawals = readJsonTable('withdrawals');
            }

            $sumAmount = function ($rows, $pred = null) {
                $t = 0.0;
                foreach ($rows as $r) {
                    if ($pred && !$pred($r)) continue;
                    $v = js_parse_float($r['amount'] ?? 0);
                    $t += js_truthy($v) ? (float)$v : 0.0;
                }
                return $t;
            };

            $totalDeposited = $sumAmount($deposits);
            // Withdrawals a sub-admin pays are that sub-admin's cash and queue (lib/subadmin.php).
            $routed = function_exists('subadmin_routed_withdrawals') ? subadmin_routed_withdrawals() : [];
            $totalWithdrawn = $sumAmount($withdrawals, function ($w) use ($routed) { return ($w['status'] ?? null) === 'Completed' && !isset($routed[$w['withdrawal_id'] ?? '']); });
            $pendingWithdrawals = count(array_filter($withdrawals, function ($w) use ($routed) { return ($w['status'] ?? null) === 'Pending' && !isset($routed[$w['withdrawal_id'] ?? '']); }));
            // Wallet liability is money the platform owes its CUSTOMERS. "Admin" is the house's own
            // seat — the account the house plays through, seeded with a float by sql/schema*.sql —
            // so its balance is the house's own money, not a debt. Including it made a brand-new
            // deployment with no players at all report a standing liability of the seed amount.
            // The superadmin dashboard already excludes this account by name for the same reason.
            $walletPool = 0.0;
            foreach ($users as $u) {
                if (strtolower((string)($u['username'] ?? '')) === 'admin') continue;
                $v = js_parse_float($u['wallet_balance'] ?? 0);
                $walletPool += js_truthy($v) ? (float)$v : 0.0;
            }

            $res->json([
                'total_users'         => $totalUsers,
                'total_deposited'     => $totalDeposited,
                'total_withdrawn'     => $totalWithdrawn,
                'wallet_pool'         => $walletPool,
                'pending_withdrawals' => $pendingWithdrawals,
            ]);
        } catch (Throwable $err) {
            fail500($res, $err, 'admin');
        }
    });

    /**
     * Rig audit — observed vs configured percentages, per game and per room.
     *   GET /api/admin/rig-audit?window_ms=600000&game=teenpatti&recent=50
     */
    $app->get('/api/admin/rig-audit', 'require_admin', function (Req $req, Res $res) {
        $windowMs = js_parse_int($req->q('window_ms'));
        $report = rig_report([
            'sinceMs' => (js_is_finite($windowMs) && $windowMs > 0) ? (int)$windowMs : null,
            'game'    => js_truthy($req->q('game')) ? $req->q('game') : null,
        ]);

        // A disabled game legitimately rigs 0% of its rounds, but the ledger still carries whatever
        // percentage is stored against it, so raw drift read as an alarming "-90" when nothing was
        // wrong. Annotate each game with whether its bot was actually on, and suppress the drift
        // figure when it was not — a percentage the engine was never trying to hit is not a
        // deviation.
        $state = bot_takeover_state();
        $games = (array) $report['games'];
        foreach ($games as $game => $summary) {
            $conf = $state[$game] ?? null;
            $enabled = (bool)($conf && !empty($conf['enabled']));
            $summary['bot_enabled'] = $enabled;
            if (!$enabled) {
                $summary['configured_pct'] = 0;
                $summary['drift_pct'] = null;
                $perInstance = (array)($summary['per_instance'] ?? []);
                foreach ($perInstance as $k => $inst) {
                    $inst['configured_pct'] = 0;
                    $inst['drift_pct'] = null;
                    $perInstance[$k] = $inst;
                }
                $summary['per_instance'] = js_object($perInstance);
            }
            $games[$game] = $summary;
        }
        $report['games'] = js_object($games);

        $liveUsersCount = [];
        foreach (live_user_games() as $g) $liveUsersCount[$g] = count(get_live_usernames($g));
        $liveInstancesCount = [];
        foreach (live_instance_games() as $g) $liveInstancesCount[$g] = count(get_live_instances($g));

        $wantRecent = js_parse_int($req->q('recent'));

        $res->json(array_merge($report, [
            'configured'           => $state,
            'live_users_count'     => $liveUsersCount,
            'targeted_users'       => bot_targeted_users_all(),
            // Games measured in concurrent tables rather than players report both counts, so an
            // operator can see "3 of 6 tables" directly instead of inferring it.
            'live_instances_count' => $liveInstancesCount,
            'recent' => (js_is_finite($wantRecent) && $wantRecent > 0)
                ? rig_recent($wantRecent, js_truthy($req->q('game')) ? $req->q('game') : null)
                : UNDEF(),
        ]));
    });

    /**
     * Super dashboard — real-money-shaped analytics derived entirely from the Transaction ledger,
     * the live User table and the in-memory live-targeting engine. No figure is estimated: every
     * number is a direct aggregation of rows that already exist for other reasons.
     */
    // Superadmin ONLY, not merely admin. The path prefix already carries require_admin from the
    // useMw above; this adds the narrower gate on top so an ordinary operator token cannot read
    // whole-business financials — all-time house profit, every player's net position, the top
    // winners and losers. superadmin.html is the only caller, so nothing else is affected.
    $app->get('/api/admin/super-dashboard', 'require_superadmin', function (Req $req, Res $res) {
        try {
            $users = []; $transactions = [];
            try {
                $users = all('SELECT "username", "wallet_balance", "created_at" FROM "User"');
                $transactions = all('SELECT "id","user","type","amount","details","timestamp" FROM "Transaction"');
            } catch (Throwable $dbErr) {
                foreach (readJsonTable('users') as $u) {
                    $users[] = [
                        'username'       => $u['username'] ?? null,
                        'wallet_balance' => $u['wallet_balance'] ?? 0,
                        'created_at'     => $u['created_at'] ?? js_iso(),
                    ];
                }
                foreach (readJsonTable('transactions') as $t) {
                    $transactions[] = [
                        'id'        => $t['id'] ?? null,
                        'user'      => $t['user'] ?? null,
                        'type'      => $t['type'] ?? null,
                        'amount'    => $t['amount'] ?? 0,
                        'details'   => $t['details'] ?? null,
                        'timestamp' => $t['timestamp'] ?? js_iso(),
                    ];
                }
            }

            $nowMs = now_ms();
            $nowIso = js_iso($nowMs);
            // "Today" and "this month" are India days (IST, UTC+5:30), the operator's calendar — they
            // used to roll over at 05:30 IST (UTC midnight), splitting an Indian evening across two days.
            $istOff = 330 * 60 * 1000;
            $istDay = function ($ms) use ($istOff) { return gmdate('Y-m-d', (int) floor(($ms + $istOff) / 1000)); };
            $istMonth = function ($ms) use ($istOff) { return gmdate('Y-m', (int) floor(($ms + $istOff) / 1000)); };
            $todayKey = $istDay($nowMs);
            $monthKey = $istMonth($nowMs);
            $startOfToday = (int) (floor(($nowMs + $istOff) / 86400000) * 86400000 - $istOff);
            $startOfYesterday = $startOfToday - 86400000;
            $startOfMonth = (int) (strtotime($monthKey . '-01T00:00:00Z') * 1000 - $istOff);
            $excluded = super_excluded_set();   // test / internal accounts to leave out of the figures

            // --- Registered users ---
            $newToday = 0; $newThisMonth = 0;
            foreach ($users as $u) {
                if (isset($excluded[strtolower((string)($u['username'] ?? ''))])) continue;
                if (empty($u['created_at'])) continue;
                $ms = is_numeric($u['created_at']) ? (int)$u['created_at'] : sql_to_ms($u['created_at']);
                if ($ms === null) continue;
                if ($ms >= $startOfToday) $newToday++;
                if ($ms >= $startOfMonth) $newThisMonth++;
            }

            // --- Live users (from the same continuous engine every game already uses) ---
            $liveByGame = []; $liveUnion = [];
            foreach (live_user_games() as $gameKey) {
                $list = get_live_usernames($gameKey);
                $liveByGame[$gameKey] = count($list);
                foreach ($list as $u) $liveUnion[strtolower((string)$u)] = true;
            }

            // --- Gameplay aggregation ---
            $labels = game_labels();
            $perGame = [];
            foreach ($labels as $g => $label) {
                $perGame[$g] = ['label' => $label, 'wagered' => 0.0, 'paid_out' => 0.0, 'bet_count' => 0, 'win_count' => 0];
            }

            $dailyMap = []; $monthlyMap = []; $perUserNet = [];
            $houseProfitAllTime = 0.0; $houseProfitToday = 0.0; $houseProfitThisMonth = 0.0;
            $totalWagered = 0.0; $totalPaidOut = 0.0; $totalBets = 0; $totalWins = 0;
            $recentTx = [];
            // Single payouts over the last 24h, for the "biggest recent wins" review panel: the
            // honest replacement for secretly clawing a lucky streak back. Nothing is changed here —
            // it just surfaces the large wins so an operator can look at who, and how often.
            $bigWins = [];
            $bigWinSince = $nowMs - 24 * 3600 * 1000;

            // Today (IST): the "today" box — betting volume, payouts, profit, who played, hour by hour.
            $today = ['wagered' => 0.0, 'paid_out' => 0.0, 'bets' => 0, 'wins' => 0, 'biggest_win' => null];
            $todayBettors = [];
            $todayPerGame = [];
            $hourly = array_fill(0, 24, ['wagered' => 0.0, 'profit' => 0.0]);
            $yesterdayProfit = 0.0; $yesterdayWagered = 0.0;
            // Players who placed a bet in the last 15 minutes, per game — covers every game, including
            // the ones the in-memory live tracker does not follow (Chicken Road, Astronaut, cricket ...).
            $activeSince = $nowMs - 15 * 60 * 1000;
            $activeByGame = [];

            // Balance changes an operator made by hand ("Admin Adjustment" from the admin panel,
            // "Manual Adjustment" from the legacy wallet endpoint): money the house created or took.
            $adjustments = [];
            $adj = ['credited' => 0.0, 'debited' => 0.0, 'credited_today' => 0.0, 'debited_today' => 0.0, 'count' => 0];

            foreach ($transactions as $t) {
                $det = (string) ($t['details'] ?? '');
                if (strpos($det, 'Admin Adjustment') === 0 || strpos($det, 'Manual Adjustment') === 0) {
                    $aMs = !empty($t['timestamp']) ? (is_numeric($t['timestamp']) ? (int) $t['timestamp'] : (sql_to_ms($t['timestamp']) ?? 0)) : 0;
                    $aAmt = abs((float) js_parse_float($t['amount'] ?? 0));
                    $credit = ($t['type'] ?? '') === 'Deposit';
                    $adj[$credit ? 'credited' : 'debited'] += $aAmt;
                    if ($aMs >= $startOfToday) $adj[$credit ? 'credited_today' : 'debited_today'] += $aAmt;
                    $adj['count']++;
                    $adjustments[] = ['username' => $t['user'] ?? null, 'amount' => to_fixed_num($aAmt, 2), 'credit' => $credit, 'details' => $det, 'at' => js_iso($aMs ?: $nowMs), '_ms' => $aMs];
                }
                $cls = classify_gameplay_transaction($t['details'] ?? null);
                $tsMs = !empty($t['timestamp'])
                    ? (is_numeric($t['timestamp']) ? (int)$t['timestamp'] : sql_to_ms($t['timestamp']))
                    : $nowMs;
                if ($tsMs === null) $tsMs = $nowMs;
                $tsIso = js_iso($tsMs);
                $dayKey = $istDay($tsMs);
                $mKey   = $istMonth($tsMs);
                $amtParsed = js_parse_float($t['amount'] ?? 0);
                $amt = js_truthy($amtParsed) ? (float)$amtParsed : 0.0;

                // "Admin" is the house's own seat — the account the house plays through, not a
                // customer. Its wins are house profit landing in its own wallet, not a payout cost,
                // and its wagers are not a real customer's stake, so it is excluded from the
                // wagered/won ledger to keep the sign of "house profit" correct. It still appears in
                // the recent-activity feed below for transparency.
                $uLower = strtolower((string)($t['user'] ?? ''));
                $isHouseAccount = $uLower === 'admin';
                $isExcluded = isset($excluded[$uLower]);

                if ($cls && !$isHouseAccount && !$isExcluded && ($cls['kind'] === 'wager' || $cls['kind'] === 'win')) {
                    $signedProfit = $cls['kind'] === 'wager' ? $amt : -$amt;
                    $houseProfitAllTime += $signedProfit;
                    if ($tsMs >= $startOfToday) $houseProfitToday += $signedProfit;
                    if ($tsMs >= $startOfMonth) $houseProfitThisMonth += $signedProfit;
                    $dailyMap[$dayKey] = ($dailyMap[$dayKey] ?? 0) + $signedProfit;
                    $monthlyMap[$mKey] = ($monthlyMap[$mKey] ?? 0) + $signedProfit;

                    if (isset($perGame[$cls['game']])) {
                        if ($cls['kind'] === 'wager') {
                            $perGame[$cls['game']]['wagered'] += $amt;
                            $perGame[$cls['game']]['bet_count']++;
                            $totalWagered += $amt; $totalBets++;
                        } else {
                            $perGame[$cls['game']]['paid_out'] += $amt;
                            $perGame[$cls['game']]['win_count']++;
                            $totalPaidOut += $amt; $totalWins++;
                        }
                    }

                    $uKey = strtolower((string)($t['user'] ?? 'Unknown'));
                    if (!isset($perUserNet[$uKey])) {
                        $perUserNet[$uKey] = ['username' => $t['user'] ?? null, 'wagered' => 0.0, 'won' => 0.0];
                    }
                    if ($cls['kind'] === 'wager') $perUserNet[$uKey]['wagered'] += $amt;
                    else                          $perUserNet[$uKey]['won'] += $amt;

                    if ($tsMs >= $startOfToday) {
                        $g = $cls['game'];
                        if (!isset($todayPerGame[$g])) $todayPerGame[$g] = ['label' => $labels[$g] ?? $g, 'wagered' => 0.0, 'paid_out' => 0.0, 'bets' => 0];
                        $hr = (int) gmdate('G', (int) floor(($tsMs + $istOff) / 1000));
                        $hourly[$hr]['profit'] += $signedProfit;
                        if ($cls['kind'] === 'wager') {
                            $today['wagered'] += $amt; $today['bets']++;
                            $todayPerGame[$g]['wagered'] += $amt; $todayPerGame[$g]['bets']++;
                            $hourly[$hr]['wagered'] += $amt;
                            $todayBettors[strtolower((string) ($t['user'] ?? ''))] = true;
                        } else {
                            $today['paid_out'] += $amt; $today['wins']++;
                            $todayPerGame[$g]['paid_out'] += $amt;
                            if ($g !== 'referral' && (!$today['biggest_win'] || $amt > $today['biggest_win']['amount'])) {
                                $today['biggest_win'] = ['username' => $t['user'] ?? null, 'game' => $labels[$g] ?? $g, 'amount' => to_fixed_num($amt, 2)];
                            }
                        }
                    } elseif ($tsMs >= $startOfYesterday) {
                        $yesterdayProfit += $signedProfit;
                        if ($cls['kind'] === 'wager') $yesterdayWagered += $amt;
                    }
                    if ($cls['kind'] === 'wager' && $tsMs >= $activeSince) {
                        $activeByGame[$cls['game']][strtolower((string) ($t['user'] ?? ''))] = true;
                    }

                    if ($cls['kind'] === 'win' && $tsMs >= $bigWinSince && $amt > 0) {
                        $bigWins[] = [
                            'username'  => $t['user'] ?? null,
                            'game'      => $labels[$cls['game']] ?? $cls['game'],
                            'amount'    => to_fixed_num($amt, 2),
                            'details'   => $t['details'] ?? null,
                            'timestamp' => $tsIso,
                        ];
                    }
                }

                if ($cls && !$isExcluded) {
                    $recentTx[] = [
                        'id'        => $t['id'] ?? null,
                        'user'      => $t['user'] ?? null,
                        'type'      => $t['type'] ?? null,
                        'amount'    => $amt,
                        'details'   => $t['details'] ?? null,
                        'game'      => $labels[$cls['game']] ?? $cls['game'],
                        'kind'      => $cls['kind'],
                        'is_house'  => $isHouseAccount,
                        'timestamp' => $tsIso,
                    ];
                }
            }

            usort($recentTx, function ($a, $b) {
                return strcmp((string)$b['timestamp'], (string)$a['timestamp']);
            });

            // Biggest single payouts in the last 24h, largest first.
            usort($bigWins, function ($a, $b) { return ($b['amount'] <=> $a['amount']); });
            $bigWins = array_slice($bigWins, 0, 12);

            // --- Winners / losers: net = wagered - won. Positive net = the house is up on them. ---
            $netEntries = [];
            foreach ($perUserNet as $e) {
                $netEntries[] = [
                    'username' => $e['username'],
                    'wagered'  => to_fixed_num($e['wagered'], 2),
                    'won'      => to_fixed_num($e['won'], 2),
                    'net'      => to_fixed_num($e['wagered'] - $e['won'], 2),
                ];
            }
            $losingUsers   = array_values(array_filter($netEntries, function ($e) { return $e['net'] > 0; }));
            $winningUsers  = array_values(array_filter($netEntries, function ($e) { return $e['net'] < 0; }));
            $breakEvenUsers = array_values(array_filter($netEntries, function ($e) { return $e['net'] == 0; }));

            $topLosers = $losingUsers;
            usort($topLosers, function ($a, $b) { return ($b['net'] <=> $a['net']); });
            $topLosers = array_slice($topLosers, 0, 8);

            $topWinners = $winningUsers;
            usort($topWinners, function ($a, $b) { return ($a['net'] <=> $b['net']); });
            $topWinners = array_slice($topWinners, 0, 8);

            ksort($dailyMap);
            $dailyTrend = [];
            foreach (array_slice(array_keys($dailyMap), -14) as $d) {
                $dailyTrend[] = ['date' => $d, 'profit' => to_fixed_num($dailyMap[$d], 2)];
            }
            ksort($monthlyMap);
            $monthlyTrend = [];
            foreach (array_slice(array_keys($monthlyMap), -12) as $m) {
                $monthlyTrend[] = ['month' => $m, 'profit' => to_fixed_num($monthlyMap[$m], 2)];
            }

            // --- Money in / out: the cashier side, which this console did not show at all ---
            $finance = [
                'deposits_total' => 0.0, 'deposits_count' => 0, 'deposits_today' => 0.0, 'deposits_today_count' => 0,
                'deposits_pending' => 0.0, 'deposits_pending_count' => 0,
                'withdrawals_total' => 0.0, 'withdrawals_count' => 0, 'withdrawals_today' => 0.0, 'withdrawals_today_count' => 0,
                'withdrawals_pending' => 0.0, 'withdrawals_pending_count' => 0,
                'wallet_liability' => 0.0, 'players_with_balance' => 0,
            ];
            $pendingWithdrawals = [];
            $decisions = [];
            try {
                foreach (all('SELECT "username","amount","status","created_at","updated_at" FROM "Deposit"') as $d) {
                    if (isset($excluded[strtolower((string) ($d['username'] ?? ''))])) continue;
                    $a = (float) $d['amount']; $ms = sql_to_ms($d['created_at']) ?? 0; $st = (string) $d['status'];
                    if ($st === 'Completed' || $st === 'Rejected') {
                        $dec = sql_to_ms($d['updated_at']) ?? $ms;
                        $decisions[] = ['kind' => 'Deposit', 'username' => $d['username'], 'amount' => to_fixed_num($a, 2), 'status' => $st, 'at' => js_iso($dec), '_ms' => $dec];
                    }
                    if ($st === 'Completed') {
                        $finance['deposits_total'] += $a; $finance['deposits_count']++;
                        if ($ms >= $startOfToday) { $finance['deposits_today'] += $a; $finance['deposits_today_count']++; }
                    } elseif ($st === 'Pending') {
                        $finance['deposits_pending'] += $a; $finance['deposits_pending_count']++;
                    }
                }
                // Withdrawals a sub-admin pays from their own cash are not the platform's cash-out;
                // they are reported in the Sub-Admins section instead.
                $routedWd = function_exists('subadmin_routed_withdrawals') ? subadmin_routed_withdrawals() : [];
                foreach (all('SELECT "withdrawal_id","username","amount","method","status","created_at","updated_at" FROM "Withdrawal"') as $w) {
                    if (isset($excluded[strtolower((string) ($w['username'] ?? ''))])) continue;
                    if (isset($routedWd[$w['withdrawal_id']])) continue;
                    $a = (float) $w['amount']; $ms = sql_to_ms($w['created_at']) ?? 0; $st = (string) $w['status'];
                    if ($st === 'Completed' || $st === 'Rejected') {
                        $dec = sql_to_ms($w['updated_at']) ?? $ms;
                        $decisions[] = ['kind' => 'Withdrawal', 'username' => $w['username'], 'amount' => to_fixed_num($a, 2), 'status' => $st, 'at' => js_iso($dec), '_ms' => $dec];
                    }
                    if ($st === 'Completed') {
                        $finance['withdrawals_total'] += $a; $finance['withdrawals_count']++;
                        if ($ms >= $startOfToday) { $finance['withdrawals_today'] += $a; $finance['withdrawals_today_count']++; }
                    } elseif ($st === 'Pending') {
                        $finance['withdrawals_pending'] += $a; $finance['withdrawals_pending_count']++;
                        $pendingWithdrawals[] = ['username' => $w['username'], 'amount' => to_fixed_num($a, 2), 'method' => $w['method'], 'requested_at' => js_iso($ms)];
                    }
                }
            } catch (Throwable $e) { /* the JSON fallback has no cashier tables worth summing */ }
            // What the platform owes its customers: every player's wallet ("admin" is the house's own seat).
            $recentSignups = [];
            foreach ($users as $u) {
                $uL = strtolower((string) ($u['username'] ?? ''));
                if ($uL === 'admin' || isset($excluded[$uL])) continue;
                $bal = (float) js_parse_float($u['wallet_balance'] ?? 0);
                if (is_finite($bal) && $bal > 0) { $finance['wallet_liability'] += $bal; $finance['players_with_balance']++; }
                $ms = !empty($u['created_at']) ? (is_numeric($u['created_at']) ? (int) $u['created_at'] : sql_to_ms($u['created_at'])) : null;
                $recentSignups[] = ['username' => $u['username'] ?? null, 'balance' => to_fixed_num(is_finite($bal) ? $bal : 0, 2), 'joined_at' => $ms ? js_iso($ms) : null, '_ms' => $ms ?: 0];
            }
            usort($recentSignups, function ($a, $b) { return $b['_ms'] <=> $a['_ms']; });
            $recentSignups = array_map(function ($r) { unset($r['_ms']); return $r; }, array_slice($recentSignups, 0, 8));
            usort($pendingWithdrawals, function ($a, $b) { return strcmp($a['requested_at'], $b['requested_at']); });   // oldest first: waiting longest
            foreach ($finance as $k => $v) if (is_float($v)) $finance[$k] = to_fixed_num($v, 2);
            $finance['net_cash_in'] = to_fixed_num($finance['deposits_total'] - $finance['withdrawals_total'], 2);
            $finance['net_cash_in_today'] = to_fixed_num($finance['deposits_today'] - $finance['withdrawals_today'], 2);

            // Players betting in the last 15 minutes, per game (union with the live tracker's counts).
            $activeCounts = [];
            $activeUnion = $liveUnion;
            foreach ($activeByGame as $set) foreach ($set as $u => $_) $activeUnion[$u] = true;
            foreach ($labels as $g => $label) {
                if ($g === 'referral') continue;
                $activeCounts[$g] = ['label' => $label, 'count' => max(count($activeByGame[$g] ?? []), (int) ($liveByGame[$g] ?? 0))];
            }

            foreach ($todayPerGame as $g => $pg) {
                $todayPerGame[$g]['profit'] = to_fixed_num($pg['wagered'] - $pg['paid_out'], 2);
                $todayPerGame[$g]['wagered'] = to_fixed_num($pg['wagered'], 2);
                $todayPerGame[$g]['paid_out'] = to_fixed_num($pg['paid_out'], 2);
            }
            uasort($todayPerGame, function ($a, $b) { return $b['wagered'] <=> $a['wagered']; });
            $hourlyOut = [];
            foreach ($hourly as $h => $v) $hourlyOut[] = ['hour' => $h, 'wagered' => to_fixed_num($v['wagered'], 2), 'profit' => to_fixed_num($v['profit'], 2)];

            $design = game_design_payback();
            foreach ($perGame as $g => $pg) {
                $perGame[$g]['wagered']  = to_fixed_num($pg['wagered'], 2);
                $perGame[$g]['paid_out'] = to_fixed_num($pg['paid_out'], 2);
                $perGame[$g]['profit']   = to_fixed_num($pg['wagered'] - $pg['paid_out'], 2);
                // Share of every rupee staked that went back to players, as it actually happened.
                $perGame[$g]['paid_back_pct'] = $pg['wagered'] > 0 ? to_fixed_num($pg['paid_out'] / $pg['wagered'] * 100, 1) : null;
                $perGame[$g]['built_to_pay_back'] = $design[$g] ?? null;
            }

            $res->json([
                'generated_at' => $nowIso,
                'users' => [
                    'total_registered' => count($users),
                    'new_today'        => $newToday,
                    'new_this_month'   => $newThisMonth,
                ],
                'live' => [
                    'total_unique' => count($liveUnion),
                    'per_game'     => $liveByGame,
                ],
                'gameplay' => [
                    'total_wagered'          => to_fixed_num($totalWagered, 2),
                    'total_paid_out'         => to_fixed_num($totalPaidOut, 2),
                    'total_bets'             => $totalBets,
                    'total_wins'             => $totalWins,
                    'house_profit_all_time'  => to_fixed_num($houseProfitAllTime, 2),
                    'house_profit_today'     => to_fixed_num($houseProfitToday, 2),
                    'house_profit_this_month'=> to_fixed_num($houseProfitThisMonth, 2),
                    'daily_trend'            => $dailyTrend,
                    'monthly_trend'          => $monthlyTrend,
                    'per_game'               => $perGame,
                ],
                'players' => [
                    'net_losing_count'  => count($losingUsers),
                    'net_winning_count' => count($winningUsers),
                    'break_even_count'  => count($breakEvenUsers),
                    'top_losers'        => $topLosers,
                    'top_winners'       => $topWinners,
                ],
                'bot_takeover'         => bot_takeover_state(),
                'recent_transactions'  => array_slice($recentTx, 0, 60),
                'big_wins_24h'         => $bigWins,
                // India-time day the "today" figures cover, and the "today" box itself
                'today_key'            => $todayKey,
                'today' => [
                    'wagered'          => to_fixed_num($today['wagered'], 2),
                    'paid_out'         => to_fixed_num($today['paid_out'], 2),
                    'profit'           => to_fixed_num($houseProfitToday, 2),
                    'bets'             => $today['bets'],
                    'wins'             => $today['wins'],
                    'players'          => count($todayBettors),
                    'new_users'        => $newToday,
                    'avg_bet'          => $today['bets'] ? to_fixed_num($today['wagered'] / $today['bets'], 2) : 0,
                    'margin_pct'       => $today['wagered'] > 0 ? to_fixed_num($houseProfitToday / $today['wagered'] * 100, 1) : null,
                    'biggest_win'      => $today['biggest_win'],
                    'yesterday_profit' => to_fixed_num($yesterdayProfit, 2),
                    'yesterday_wagered'=> to_fixed_num($yesterdayWagered, 2),
                    'per_game'         => array_values($todayPerGame),
                    'hourly'           => $hourlyOut,
                ],
                'finance'              => $finance,
                'pending_withdrawals'  => array_slice($pendingWithdrawals, 0, 10),
                'recent_signups'       => $recentSignups,
                'active_15m'           => $activeCounts,
                'active_unique_15m'    => count($activeUnion),
                'excluded_accounts'    => array_keys($excluded),
                // What the operators did: their action log, hand-made balance changes, cashier decisions
                'admin' => (function () use ($adjustments, $adj, $decisions) {
                    $byTime = function ($a, $b) { return $b['_ms'] <=> $a['_ms']; };
                    $strip = function ($r) { unset($r['_ms']); return $r; };
                    usort($adjustments, $byTime);
                    usort($decisions, $byTime);
                    foreach ($adj as $k => $v) if (is_float($v)) $adj[$k] = to_fixed_num($v, 2);
                    return [
                        'actions'           => admin_log_recent(40),
                        'adjustments'       => array_map($strip, array_slice($adjustments, 0, 15)),
                        'adjustment_totals' => $adj,
                        'decisions'         => array_map($strip, array_slice($decisions, 0, 15)),
                    ];
                })(),
            ]);
        } catch (Throwable $err) {
            fail500($res, $err, 'admin');
        }
    });

    /**
     * Player win monitor (super admin only) — which players are winning more than the games
     * normally pay, so an operator favouring someone stands out.
     *
     *   GET /api/admin/super-player-monitor?window=24h|7d|30d|all
     *
     * For each player: what they staked and won, what the games they played would normally have
     * paid back on those stakes, and how unlikely their result is by luck alone (a z-score turned
     * into "about 1 in N"). Alongside: money an admin added to their balance by hand and operator
     * actions that name them. Nothing is changed; this only reports.
     */
    $app->get('/api/admin/super-player-monitor', 'require_superadmin', function (Req $req, Res $res) {
        try {
            $windows = ['24h' => 1, '7d' => 7, '30d' => 30, 'all' => 0];
            $win = (string) $req->q('window', '7d');
            if (!isset($windows[$win])) $win = '7d';
            $nowMs = now_ms();
            $sinceMs = $windows[$win] ? $nowMs - $windows[$win] * 86400000 : 0;

            $rows = $sinceMs
                ? all('SELECT "user","type","amount","details","timestamp" FROM "Transaction" WHERE "timestamp" >= ?', [gmdate('Y-m-d H:i:s', (int) ($sinceMs / 1000))])
                : all('SELECT "user","type","amount","details","timestamp" FROM "Transaction"');
            $labels = game_labels();
            $expected = game_expected_payback();
            $spread = game_payout_spread();

            $excluded = super_excluded_set();
            $players = []; $platform = [];
            foreach ($rows as $t) {
                $user = (string) ($t['user'] ?? '');
                if ($user === '' || strtolower($user) === 'admin' || isset($excluded[strtolower($user)])) continue;
                $cls = classify_gameplay_transaction($t['details'] ?? null);
                if (!$cls || $cls['game'] === 'referral') continue;
                $g = $cls['game'];
                $amt = abs((float) js_parse_float($t['amount'] ?? 0));
                if (!is_finite($amt) || $amt <= 0) continue;
                $ms = sql_to_ms($t['timestamp']) ?? 0;
                $k = strtolower($user);
                if (!isset($players[$k])) $players[$k] = ['username' => $user, 'games' => [], 'bets' => 0, 'wins' => 0, 'wagered' => 0.0, 'won' => 0.0, 'last_ms' => 0, 'top_wins' => []];
                $P = &$players[$k];
                if (!isset($P['games'][$g])) $P['games'][$g] = ['bets' => 0, 'wagered' => 0.0, 'won' => 0.0, 'stake_sq' => 0.0];
                if (!isset($platform[$g])) $platform[$g] = ['wagered' => 0.0, 'won' => 0.0];
                if ($cls['kind'] === 'wager') {
                    $P['bets']++; $P['wagered'] += $amt;
                    $P['games'][$g]['bets']++; $P['games'][$g]['wagered'] += $amt; $P['games'][$g]['stake_sq'] += $amt * $amt;
                    $platform[$g]['wagered'] += $amt;
                } else {
                    $P['wins']++; $P['won'] += $amt; $P['games'][$g]['won'] += $amt;
                    $platform[$g]['won'] += $amt;
                    $P['top_wins'][] = ['game' => $labels[$g] ?? $g, 'amount' => to_fixed_num($amt, 2), 'at' => js_iso($ms)];
                }
                if ($ms > $P['last_ms']) $P['last_ms'] = $ms;
                unset($P);
            }
            // Teen Patti (and any game without a fixed return): the platform's own average, kept between
            // 90% and 100% — a low average (bots winning most pots, old test data) must not make an
            // ordinary player look lucky.
            foreach ($expected as $g => $v) {
                if ($v !== null) continue;
                $pf = $platform[$g] ?? null;
                $expected[$g] = ($pf && $pf['wagered'] > 0) ? max(0.9, min(1.0, $pf['won'] / $pf['wagered'])) : 0.95;
            }

            // Money an admin added / removed by hand, per player (all time — a favour is a favour whenever).
            $credits = [];
            try {
                foreach (all("SELECT \"user\",\"type\",\"amount\",\"timestamp\" FROM \"Transaction\" WHERE \"details\" LIKE 'Admin Adjustment%' OR \"details\" LIKE 'Manual Adjustment%'") as $a) {
                    $k = strtolower((string) $a['user']);
                    if (!isset($credits[$k])) $credits[$k] = ['added' => 0.0, 'removed' => 0.0, 'count' => 0, 'last' => null];
                    $v = abs((float) $a['amount']);
                    if (($a['type'] ?? '') === 'Deposit') $credits[$k]['added'] += $v; else $credits[$k]['removed'] += $v;
                    $credits[$k]['count']++;
                    $ms = sql_to_ms($a['timestamp']);
                    if ($ms && (!$credits[$k]['last'] || $ms > sql_to_ms($credits[$k]['last']))) $credits[$k]['last'] = js_iso($ms);
                }
            } catch (Throwable $e) {}
            // Operator actions that name a player (balance changes, approvals ... from the action log).
            $mentions = [];
            foreach (admin_log_recent(200) as $act) {
                $sum = json_decode((string) $act['summary'], true);
                if (!is_array($sum)) continue;
                foreach (['username', 'user', 'target', 'player'] as $f) {
                    if (!empty($sum[$f]) && is_string($sum[$f])) {
                        $k = strtolower($sum[$f]);
                        $mentions[$k][] = ['at' => $act['at'], 'actor' => $act['actor'], 'action' => $act['action'] ?: $act['path']];
                    }
                }
            }

            $out = []; $flagged = ['red' => 0, 'amber' => 0];
            foreach ($players as $k => $P) {
                $exp = 0.0; $var = 0.0; $perGame = [];
                foreach ($P['games'] as $g => $G) {
                    $e = (float) ($expected[$g] ?? 0.95);
                    $exp += $G['wagered'] * $e;
                    $var += pow((float) ($spread[$g] ?? 2.0), 2) * $G['stake_sq'];
                    $perGame[] = ['label' => $labels[$g] ?? $g, 'bets' => $G['bets'], 'wagered' => to_fixed_num($G['wagered'], 2), 'won' => to_fixed_num($G['won'], 2),
                                  'payback_pct' => $G['wagered'] > 0 ? to_fixed_num($G['won'] / $G['wagered'] * 100, 1) : null, 'expected_pct' => to_fixed_num($e * 100, 1)];
                }
                usort($perGame, function ($a, $b) { return $b['wagered'] <=> $a['wagered']; });
                $excess = $P['won'] - $exp;
                $z = $var > 0 ? $excess / sqrt($var) : 0.0;
                $pLuck = normal_upper_tail($z);
                $net = $P['won'] - $P['wagered'];
                $cr = $credits[$k] ?? ['added' => 0.0, 'removed' => 0.0, 'count' => 0, 'last' => null];
                $men = $mentions[$k] ?? [];

                $reasons = []; $flag = 'none';
                if ($P['bets'] >= 20 && $z >= 3 && $net >= 500) { $flag = 'red'; $reasons[] = 'wins far more than these games normally pay (about 1 in ' . number_format(1 / max($pLuck, 1e-12)) . ' by luck)'; }
                elseif ($P['bets'] >= 20 && $z >= 2 && $net > 0) { $flag = 'amber'; $reasons[] = 'winning more than usual (about 1 in ' . number_format(1 / max($pLuck, 1e-12)) . ' by luck)'; }
                if ($cr['added'] > 0) {
                    $reasons[] = 'received ' . number_format($cr['added'], 2) . ' from an admin by hand';
                    // a hand-given credit matters when the player is also up overall, or when it is large
                    if ($flag === 'none' && ($net > 0 || $cr['added'] >= 1000)) $flag = 'amber';
                    if ($cr['added'] >= 1000 && $z >= 2) $flag = 'red';
                }
                if ($men && $net > 0) { $reasons[] = count($men) . ' admin action(s) name this player'; if ($flag === 'none') $flag = 'amber'; }
                if ($flag !== 'none') $flagged[$flag]++;

                usort($P['top_wins'], function ($a, $b) { return $b['amount'] <=> $a['amount']; });
                $out[] = [
                    'username' => $P['username'], 'bets' => $P['bets'], 'wins' => $P['wins'],
                    'wagered' => to_fixed_num($P['wagered'], 2), 'won' => to_fixed_num($P['won'], 2), 'net' => to_fixed_num($net, 2),
                    'payback_pct' => $P['wagered'] > 0 ? to_fixed_num($P['won'] / $P['wagered'] * 100, 1) : null,
                    'expected_pct' => $P['wagered'] > 0 ? to_fixed_num($exp / $P['wagered'] * 100, 1) : null,
                    'excess' => to_fixed_num($excess, 2), 'z' => to_fixed_num($z, 2),
                    'luck_one_in' => $z > 0 ? (int) min(1e12, round(1 / max($pLuck, 1e-12))) : null,
                    'win_rate_pct' => $P['bets'] ? to_fixed_num($P['wins'] / $P['bets'] * 100, 1) : null,
                    'admin_added' => to_fixed_num($cr['added'], 2), 'admin_removed' => to_fixed_num($cr['removed'], 2), 'admin_adjustments' => $cr['count'], 'admin_last' => $cr['last'],
                    'admin_mentions' => array_slice($men, 0, 8),
                    'flag' => $flag, 'reasons' => $reasons,
                    'per_game' => $perGame, 'top_wins' => array_slice($P['top_wins'], 0, 5),
                    'last_bet_at' => $P['last_ms'] ? js_iso($P['last_ms']) : null,
                ];
            }
            $rank = ['red' => 2, 'amber' => 1, 'none' => 0];
            usort($out, function ($a, $b) use ($rank) { return [$rank[$b['flag']], $b['net']] <=> [$rank[$a['flag']], $a['net']]; });
            // ?q= narrows to matching usernames before the list is cut, so any player can be found.
            $q = strtolower(trim((string) $req->q('q', '')));
            if ($q !== '') $out = array_values(array_filter($out, function ($p) use ($q) { return strpos(strtolower((string) $p['username']), $q) !== false; }));

            $res->json([
                'generated_at' => js_iso($nowMs), 'window' => $win,
                'players_total' => count($players), 'matching' => count($out), 'flagged' => $flagged,
                'expected_payback' => array_map(function ($v) { return to_fixed_num($v * 100, 1); }, $expected),
                'players' => array_slice($out, 0, 400),
            ]);
        } catch (Throwable $err) {
            fail500($res, $err, 'admin');
        }
    });

    /**
     * Period helpers — an India-time (IST, UTC+5:30) date range from ?from=&to= (YYYY-MM-DD),
     * both inclusive. Defaults to today. Shared by the period summary and the drill-down.
     */
    $super_ist_range = function (Req $req) {
        $istOff = 330 * 60 * 1000;
        $nowMs = now_ms();
        $today = gmdate('Y-m-d', (int) floor(($nowMs + $istOff) / 1000));
        $valid = function ($d) { return is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d . 'T00:00:00Z') !== false; };
        $from = $req->q('from'); $to = $req->q('to');
        if (!$valid($from)) $from = $today;
        if (!$valid($to)) $to = $from;
        if (strcmp($from, $to) > 0) { $t = $from; $from = $to; $to = $t; }           // swap if reversed
        $startMs = (int) (strtotime($from . 'T00:00:00Z') * 1000 - $istOff);
        $endMs   = (int) (strtotime($to . 'T00:00:00Z') * 1000 - $istOff) + 86400000; // end of the 'to' day
        if ($endMs - $startMs > 400 * 86400000) $startMs = $endMs - 400 * 86400000;   // cap the span
        return ['from' => $from, 'to' => $to, 'startMs' => $startMs, 'endMs' => $endMs, 'istOff' => $istOff, 'nowMs' => $nowMs];
    };

    /**
     * Super period summary (super admin only) — the "at a glance" figures for ANY India-time date
     * range, so the operator can look at a day, a week, a month or a custom span.
     *   GET /api/admin/super-period?from=YYYY-MM-DD&to=YYYY-MM-DD
     */
    $app->get('/api/admin/super-period', 'require_superadmin', function (Req $req, Res $res) use ($super_ist_range) {
        try {
            $r = $super_ist_range($req);
            $lo = gmdate('Y-m-d H:i:s', (int) ($r['startMs'] / 1000));
            $hi = gmdate('Y-m-d H:i:s', (int) ($r['endMs'] / 1000));
            $istOff = $r['istOff'];
            $labels = game_labels();
            $excluded = super_excluded_set();

            $rows = [];
            try { $rows = all('SELECT "user","type","amount","details","timestamp" FROM "Transaction" WHERE "timestamp" >= ? AND "timestamp" < ?', [$lo, $hi]); }
            catch (Throwable $e) {
                foreach (readJsonTable('transactions') as $t) {
                    $ms = is_numeric($t['timestamp'] ?? null) ? (int) $t['timestamp'] : (sql_to_ms($t['timestamp'] ?? null) ?? 0);
                    if ($ms >= $r['startMs'] && $ms < $r['endMs']) $rows[] = $t;
                }
            }

            $wagered = 0.0; $paidOut = 0.0; $bets = 0; $wins = 0; $profit = 0.0;
            $players = []; $perGame = []; $byDay = []; $byHour = array_fill(0, 24, ['wagered' => 0.0, 'profit' => 0.0]);
            $biggest = null;
            $spanDays = max(1, (int) round(($r['endMs'] - $r['startMs']) / 86400000));
            foreach ($rows as $t) {
                $uL = strtolower((string) ($t['user'] ?? ''));
                if ($uL === 'admin' || isset($excluded[$uL])) continue;
                $cls = classify_gameplay_transaction($t['details'] ?? null);
                if (!$cls) continue;
                $amt = abs((float) js_parse_float($t['amount'] ?? 0));
                if (!is_finite($amt) || $amt <= 0) continue;
                $ms = is_numeric($t['timestamp'] ?? null) ? (int) $t['timestamp'] : (sql_to_ms($t['timestamp'] ?? null) ?? 0);
                $g = $cls['game'];
                if (!isset($perGame[$g])) $perGame[$g] = ['key' => $g, 'label' => $labels[$g] ?? $g, 'wagered' => 0.0, 'paid_out' => 0.0, 'bets' => 0];
                $dayK = gmdate('Y-m-d', (int) floor(($ms + $istOff) / 1000));
                if (!isset($byDay[$dayK])) $byDay[$dayK] = ['wagered' => 0.0, 'profit' => 0.0];
                $hr = (int) gmdate('G', (int) floor(($ms + $istOff) / 1000));
                if ($cls['kind'] === 'wager') {
                    $wagered += $amt; $bets++; $profit += $amt;
                    $perGame[$g]['wagered'] += $amt; $perGame[$g]['bets']++;
                    $byDay[$dayK]['wagered'] += $amt; $byDay[$dayK]['profit'] += $amt;
                    $byHour[$hr]['wagered'] += $amt; $byHour[$hr]['profit'] += $amt;
                    $players[strtolower((string) ($t['user'] ?? ''))] = true;
                } else {
                    $paidOut += $amt; $wins++; $profit -= $amt;
                    $perGame[$g]['paid_out'] += $amt;
                    $byDay[$dayK]['profit'] -= $amt; $byHour[$hr]['profit'] -= $amt;
                    if ($g !== 'referral' && (!$biggest || $amt > $biggest['amount'])) {
                        $biggest = ['username' => $t['user'] ?? null, 'game' => $labels[$g] ?? $g, 'amount' => to_fixed_num($amt, 2)];
                    }
                }
            }

            // Cashier movement in the same range (by created date).
            $fin = ['deposits' => 0.0, 'deposits_count' => 0, 'withdrawals' => 0.0, 'withdrawals_count' => 0];
            try {
                foreach (all('SELECT "username","amount","status","created_at" FROM "Deposit" WHERE "created_at" >= ? AND "created_at" < ? AND "status" = \'Completed\'', [$lo, $hi]) as $d) {
                    if (isset($excluded[strtolower((string) ($d['username'] ?? ''))])) continue;
                    $fin['deposits'] += (float) $d['amount']; $fin['deposits_count']++;
                }
                $routedWd = function_exists('subadmin_routed_withdrawals') ? subadmin_routed_withdrawals() : [];
                foreach (all('SELECT "withdrawal_id","username","amount","status","created_at" FROM "Withdrawal" WHERE "created_at" >= ? AND "created_at" < ? AND "status" = \'Completed\'', [$lo, $hi]) as $w) {
                    if (isset($excluded[strtolower((string) ($w['username'] ?? ''))])) continue;
                    if (isset($routedWd[$w['withdrawal_id']])) continue;   // paid by a sub-admin, not the platform
                    $fin['withdrawals'] += (float) $w['amount']; $fin['withdrawals_count']++;
                }
            } catch (Throwable $e) {}
            $newUsers = 0;
            try {
                foreach (all('SELECT "username" FROM "User" WHERE "created_at" >= ? AND "created_at" < ?', [$lo, $hi]) as $u) {
                    if (!isset($excluded[strtolower((string) ($u['username'] ?? ''))])) $newUsers++;
                }
            } catch (Throwable $e) {}

            foreach ($perGame as $g => $pg) {
                $perGame[$g]['profit'] = to_fixed_num($pg['wagered'] - $pg['paid_out'], 2);
                $perGame[$g]['wagered'] = to_fixed_num($pg['wagered'], 2);
                $perGame[$g]['paid_out'] = to_fixed_num($pg['paid_out'], 2);
            }
            uasort($perGame, function ($a, $b) { return $b['wagered'] <=> $a['wagered']; });
            ksort($byDay);
            $daily = [];
            foreach ($byDay as $k => $v) $daily[] = ['date' => $k, 'wagered' => to_fixed_num($v['wagered'], 2), 'profit' => to_fixed_num($v['profit'], 2)];
            $hourly = [];
            foreach ($byHour as $h => $v) $hourly[] = ['hour' => $h, 'wagered' => to_fixed_num($v['wagered'], 2), 'profit' => to_fixed_num($v['profit'], 2)];

            $res->json([
                'generated_at' => js_iso($r['nowMs']), 'from' => $r['from'], 'to' => $r['to'], 'span_days' => $spanDays,
                'wagered' => to_fixed_num($wagered, 2), 'paid_out' => to_fixed_num($paidOut, 2), 'profit' => to_fixed_num($profit, 2),
                'bets' => $bets, 'wins' => $wins, 'players' => count($players), 'new_users' => $newUsers,
                'avg_bet' => $bets ? to_fixed_num($wagered / $bets, 2) : 0,
                'margin_pct' => $wagered > 0 ? to_fixed_num($profit / $wagered * 100, 1) : null,
                'biggest_win' => $biggest,
                'deposits' => to_fixed_num($fin['deposits'], 2), 'deposits_count' => $fin['deposits_count'],
                'withdrawals' => to_fixed_num($fin['withdrawals'], 2), 'withdrawals_count' => $fin['withdrawals_count'],
                'net_cash_in' => to_fixed_num($fin['deposits'] - $fin['withdrawals'], 2),
                'per_game' => array_values($perGame), 'daily' => $daily, 'hourly' => $hourly,
                'multi_day' => $spanDays > 1,
            ]);
        } catch (Throwable $err) { fail500($res, $err, 'admin'); }
    });

    /**
     * Super drill-down (super admin only) — the individual rows behind a figure, so a box opens into
     * "who did what, when, which game". Scoped to the same India-time range.
     *   GET /api/admin/super-drill?from=&to=&kind=wager|win|deposit|withdrawal|newusers|game:<key>
     */
    $app->get('/api/admin/super-drill', 'require_superadmin', function (Req $req, Res $res) use ($super_ist_range) {
        try {
            $r = $super_ist_range($req);
            $lo = gmdate('Y-m-d H:i:s', (int) ($r['startMs'] / 1000));
            $hi = gmdate('Y-m-d H:i:s', (int) ($r['endMs'] / 1000));
            $excluded = super_excluded_set();
            $kind = (string) $req->q('kind', 'wager');
            $gameFilter = null;
            if (strpos($kind, 'game:') === 0) { $gameFilter = substr($kind, 5); $kind = 'game'; }
            $labels = game_labels();
            $out = []; $total = 0.0; $count = 0; $CAP = 1000;

            if ($kind === 'deposit' || $kind === 'withdrawal') {
                $tbl = $kind === 'deposit' ? 'Deposit' : 'Withdrawal';
                $idCol = $kind === 'deposit' ? 'deposit_id' : 'withdrawal_id';
                $routedWd = ($kind === 'withdrawal' && function_exists('subadmin_routed_withdrawals')) ? subadmin_routed_withdrawals() : [];
                try {
                    foreach (all("SELECT \"$idCol\" AS rid,\"username\",\"amount\",\"status\",\"created_at\" FROM \"$tbl\" WHERE \"created_at\" >= ? AND \"created_at\" < ? AND \"status\" = 'Completed' ORDER BY \"created_at\" DESC", [$lo, $hi]) as $x) {
                        if (isset($excluded[strtolower((string) ($x['username'] ?? ''))])) continue;
                        if (isset($routedWd[$x['rid']])) continue;   // paid by a sub-admin, not the platform
                        $a = (float) $x['amount']; $total += $a; $count++;
                        if (count($out) < $CAP) $out[] = ['user' => $x['username'], 'label' => ucfirst($kind), 'amount' => to_fixed_num($a, 2), 'kind' => $kind, 'at' => js_iso(sql_to_ms($x['created_at']) ?? 0)];
                    }
                } catch (Throwable $e) {}
            } elseif ($kind === 'newusers') {
                try {
                    foreach (all('SELECT "username","wallet_balance","created_at" FROM "User" WHERE "created_at" >= ? AND "created_at" < ? ORDER BY "created_at" DESC', [$lo, $hi]) as $u) {
                        if (isset($excluded[strtolower((string) ($u['username'] ?? ''))])) continue;
                        $count++;
                        if (count($out) < $CAP) $out[] = ['user' => $u['username'], 'label' => 'Signed up', 'amount' => to_fixed_num((float) $u['wallet_balance'], 2), 'kind' => 'newuser', 'at' => js_iso(sql_to_ms($u['created_at']) ?? 0)];
                    }
                } catch (Throwable $e) {}
            } else {
                // gameplay rows: wager, win, or a single game's bets+wins
                $rows = [];
                try { $rows = all('SELECT "user","amount","details","timestamp" FROM "Transaction" WHERE "timestamp" >= ? AND "timestamp" < ? ORDER BY "timestamp" DESC', [$lo, $hi]); }
                catch (Throwable $e) {
                    foreach (readJsonTable('transactions') as $t) {
                        $ms = is_numeric($t['timestamp'] ?? null) ? (int) $t['timestamp'] : (sql_to_ms($t['timestamp'] ?? null) ?? 0);
                        if ($ms >= $r['startMs'] && $ms < $r['endMs']) $rows[] = $t;
                    }
                    usort($rows, function ($a, $b) { return strcmp((string) ($b['timestamp'] ?? ''), (string) ($a['timestamp'] ?? '')); });
                }
                foreach ($rows as $t) {
                    $uL = strtolower((string) ($t['user'] ?? ''));
                    if ($uL === 'admin' || isset($excluded[$uL])) continue;
                    $cls = classify_gameplay_transaction($t['details'] ?? null);
                    if (!$cls) continue;
                    if ($kind === 'wager' && $cls['kind'] !== 'wager') continue;
                    if ($kind === 'win' && $cls['kind'] !== 'win') continue;
                    if ($kind === 'game' && $cls['game'] !== $gameFilter) continue;
                    $amt = abs((float) js_parse_float($t['amount'] ?? 0));
                    if (!is_finite($amt) || $amt <= 0) continue;
                    $total += $cls['kind'] === 'wager' ? $amt : -$amt; $count++;
                    if (count($out) < $CAP) {
                        $ms = is_numeric($t['timestamp'] ?? null) ? (int) $t['timestamp'] : (sql_to_ms($t['timestamp'] ?? null) ?? 0);
                        $out[] = ['user' => $t['user'] ?? null, 'label' => $labels[$cls['game']] ?? $cls['game'], 'game' => $cls['game'],
                                  'amount' => to_fixed_num($amt, 2), 'kind' => $cls['kind'], 'details' => $t['details'] ?? null, 'at' => js_iso($ms)];
                    }
                }
            }

            $res->json(['from' => $r['from'], 'to' => $r['to'], 'kind' => $req->q('kind', 'wager'),
                'count' => $count, 'total' => to_fixed_num($total, 2), 'capped' => $count > $CAP, 'rows' => $out]);
        } catch (Throwable $err) { fail500($res, $err, 'admin'); }
    });

    /**
     * Candidate accounts for the test/internal exclusion manager (super admin only).
     *   GET /api/admin/super-candidates?from=&to=&q=
     *
     * Returns, as one list a super admin can tick: accounts active right now (playing), accounts
     * that did anything in the last 24h, accounts that bet in the chosen period, and — when ?q= is
     * given — any account whose name matches (so an idle, signed-in test account is still findable).
     * Each carries a short reason and whether it is already excluded. Stateless tokens mean a truly
     * idle session cannot be enumerated, so name search is how those are added.
     */
    $app->get('/api/admin/super-candidates', 'require_superadmin', function (Req $req, Res $res) use ($super_ist_range) {
        try {
            $r = $super_ist_range($req);
            $lo = gmdate('Y-m-d H:i:s', (int) ($r['startMs'] / 1000));
            $hi = gmdate('Y-m-d H:i:s', (int) ($r['endMs'] / 1000));
            $excluded = super_excluded_set();
            $cand = [];   // lower => ['user','reasons'=>[], 'bet'=>0, 'active'=>bool, 'recent'=>bool, 'excluded'=>bool]
            $add = function ($name, $reason) use (&$cand, $excluded) {
                $k = strtolower(trim((string) $name));
                if ($k === '' || $k === 'admin') return;
                if (!isset($cand[$k])) $cand[$k] = ['user' => $name, 'bet' => 0.0, 'active' => false, 'recent' => false, 'excluded' => isset($excluded[$k]), 'reasons' => []];
                if ($reason && !in_array($reason, $cand[$k]['reasons'], true)) $cand[$k]['reasons'][] = $reason;
            };

            // signed in now — every authenticated player seen in the last 15 minutes (lib/presence.php)
            if (function_exists('online_usernames')) {
                foreach (online_usernames() as $u) { $add($u, 'signed in now'); if (isset($cand[strtolower($u)])) $cand[strtolower($u)]['active'] = true; }
            }
            // also actively playing — the live presence engine (the 4 live games)
            if (function_exists('get_live_usernames')) {
                foreach (live_user_games() as $g) {
                    foreach (get_live_usernames($g) as $u) { $add($u, 'playing now'); if (isset($cand[strtolower($u)])) $cand[strtolower($u)]['active'] = true; }
                }
            }
            // anything in the last 24h, and bets inside the chosen period
            $since24 = gmdate('Y-m-d H:i:s', (int) ((now_ms() - 24 * 3600 * 1000) / 1000));
            try {
                foreach (all('SELECT DISTINCT "user" FROM "Transaction" WHERE "timestamp" >= ?', [$since24]) as $t) {
                    $add($t['user'], 'active in the last 24h'); if (isset($cand[strtolower((string) $t['user'])])) $cand[strtolower((string) $t['user'])]['recent'] = true;
                }
            } catch (Throwable $e) {}
            try {
                foreach (all('SELECT "user","amount","details" FROM "Transaction" WHERE "timestamp" >= ? AND "timestamp" < ?', [$lo, $hi]) as $t) {
                    $cls = classify_gameplay_transaction($t['details'] ?? null);
                    if (!$cls || $cls['kind'] !== 'wager') continue;
                    $add($t['user'], 'bet in this period');
                    $k = strtolower((string) $t['user']);
                    if (isset($cand[$k])) $cand[$k]['bet'] += abs((float) js_parse_float($t['amount'] ?? 0));
                }
            } catch (Throwable $e) {}
            // already excluded always shown
            foreach (array_keys($excluded) as $k) $add($k, 'already excluded');
            // name search — find any account (idle or not)
            $q = trim((string) $req->q('q', ''));
            if ($q !== '') {
                try {
                    foreach (all('SELECT "username" FROM "User" WHERE LOWER("username") LIKE LOWER(?) ORDER BY "username" LIMIT 60', ['%' . $q . '%']) as $u) {
                        $add($u['username'], 'registered account');
                    }
                } catch (Throwable $e) {}
            }
            // ?all=1 — every registered account that still exists (the operator wants the whole list)
            $allUsers = 0;
            if (js_truthy($req->q('all'))) {
                try {
                    foreach (all('SELECT "username" FROM "User" ORDER BY LOWER("username") LIMIT 3000') as $u) { $add($u['username'], 'registered account'); $allUsers++; }
                } catch (Throwable $e) {}
            }

            $out = array_values(array_map(function ($c) {
                $c['bet'] = to_fixed_num($c['bet'], 2);
                return $c;
            }, $cand));
            // active first, then biggest bettors, then name
            usort($out, function ($a, $b) {
                return [$b['active'] ? 1 : 0, $b['recent'] ? 1 : 0, $b['bet']] <=> [$a['active'] ? 1 : 0, $a['recent'] ? 1 : 0, $a['bet']]
                    ?: strcmp(strtolower($a['user']), strtolower($b['user']));
            });
            $res->json(['from' => $r['from'], 'to' => $r['to'], 'count' => count($out), 'all_mode' => $allUsers > 0,
                'candidates' => array_slice($out, 0, 800), 'excluded' => array_keys($excluded)]);
        } catch (Throwable $err) { fail500($res, $err, 'admin'); }
    });

    /**
     * The test / internal account exclusion list (super admin only).
     *   GET  /api/admin/super-excluded                      -> current list
     *   POST /api/admin/super-excluded {usernames:[...]}     -> replace the whole list
     *   POST /api/admin/super-excluded {add:"name"} / {remove:"name"}  -> one at a time
     */
    $app->get('/api/admin/super-excluded', 'require_superadmin', function (Req $req, Res $res) {
        $res->json(['excluded' => array_keys(super_excluded_set())]);
    });
    $app->post('/api/admin/super-excluded', 'require_superadmin', function (Req $req, Res $res) {
        try {
            $cur = array_keys(super_excluded_set());
            $list = $req->b('usernames');
            if (is_array($list)) {
                $cur = $list;                                   // full replace
            } else {
                $add = $req->b('add'); $remove = $req->b('remove');
                if (is_string($add) && trim($add) !== '') $cur[] = $add;
                if (is_string($remove) && trim($remove) !== '') {
                    $rm = strtolower(trim($remove));
                    $cur = array_values(array_filter($cur, function ($u) use ($rm) { return strtolower(trim((string) $u)) !== $rm; }));
                }
            }
            $saved = super_excluded_save($cur);
            log_info('superadmin updated the excluded-account list', ['count' => count($saved)]);
            $res->json(['ok' => true, 'excluded' => $saved]);
        } catch (Throwable $err) { fail500($res, $err, 'admin'); }
    });

    // --- Per-game betting aggregates (GameBet rows — currently Mines only) ---
    $app->get('/api/admin/game-stats', function (Req $req, Res $res) {
        try {
            $games = ['mines'];
            $stats = [];
            foreach ($games as $game) {
                $total  = (int) scalar('SELECT COUNT(*) FROM "GameBet" WHERE "game" = ?', [$game], 0);
                $active = (int) scalar("SELECT COUNT(*) FROM \"GameBet\" WHERE \"game\" = ? AND \"status\" = 'active'", [$game], 0);
                $won    = (int) scalar("SELECT COUNT(*) FROM \"GameBet\" WHERE \"game\" = ? AND \"status\" = 'won'", [$game], 0);
                $lost   = (int) scalar("SELECT COUNT(*) FROM \"GameBet\" WHERE \"game\" = ? AND \"status\" = 'lost'", [$game], 0);

                $allBets = all('SELECT "bet_amount","payout" FROM "GameBet" WHERE "game" = ?', [$game]);
                $totalWagered = 0.0; $totalPayout = 0.0;
                foreach ($allBets as $b) {
                    $totalWagered += (float)$b['bet_amount'];
                    $totalPayout  += (float)$b['payout'];
                }

                $stats[$game] = [
                    'total'         => $total,
                    'active'        => $active,
                    'won'           => $won,
                    'lost'          => $lost,
                    'total_wagered' => $totalWagered,
                    'total_payout'  => $totalPayout,
                    'house_profit'  => $totalWagered - $totalPayout,
                ];
            }
            $res->json(['success' => true, 'stats' => $stats]);
        } catch (Throwable $err) {
            fail500($res, $err, 'admin');
        }
    });
}
