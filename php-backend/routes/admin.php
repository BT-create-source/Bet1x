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
    return null;
}

function game_labels() {
    return [
        'color_guess' => 'Color Prediction',
        'aviator'     => 'Aviator',
        'teenpatti'   => 'Teen Patti',
        'mines'       => 'Mines',
    ];
}

function register_admin_routes(Router $app) {

    // Operator-only from here on: house statistics, the super dashboard and the rig consoles.
    $app->useMw('/api/admin', 'require_admin');

    // --- Platform stats ---
    $app->get('/api/admin/stats', function (Req $req, Res $res) {
        try {
            $totalUsers = 0; $deposits = []; $withdrawals = []; $users = [];
            try {
                $totalUsers  = (int) scalar('SELECT COUNT(*) FROM `User`', [], 0);
                $deposits    = all("SELECT * FROM `Deposit` WHERE `status` = 'Completed'");
                $withdrawals = all('SELECT * FROM `Withdrawal`');
                $users       = all('SELECT * FROM `User`');
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
            $totalWithdrawn = $sumAmount($withdrawals, function ($w) { return ($w['status'] ?? null) === 'Completed'; });
            $pendingWithdrawals = count(array_filter($withdrawals, function ($w) { return ($w['status'] ?? null) === 'Pending'; }));
            $walletPool = 0.0;
            foreach ($users as $u) {
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
            $res->status(500)->json(['error' => $err->getMessage()]);
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
    $app->get('/api/admin/super-dashboard', function (Req $req, Res $res) {
        try {
            $users = []; $transactions = [];
            try {
                $users = all('SELECT `username`, `wallet_balance`, `created_at` FROM `User`');
                $transactions = all('SELECT `id`,`user`,`type`,`amount`,`details`,`timestamp` FROM `Transaction`');
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
            $todayKey = substr($nowIso, 0, 10);
            $monthKey = substr($nowIso, 0, 7);
            $startOfToday = strtotime($todayKey . 'T00:00:00.000Z') * 1000;
            $startOfMonth = strtotime($monthKey . '-01T00:00:00.000Z') * 1000;

            // --- Registered users ---
            $newToday = 0; $newThisMonth = 0;
            foreach ($users as $u) {
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

            foreach ($transactions as $t) {
                $cls = classify_gameplay_transaction($t['details'] ?? null);
                $tsMs = !empty($t['timestamp'])
                    ? (is_numeric($t['timestamp']) ? (int)$t['timestamp'] : sql_to_ms($t['timestamp']))
                    : $nowMs;
                if ($tsMs === null) $tsMs = $nowMs;
                $tsIso = js_iso($tsMs);
                $dayKey = substr($tsIso, 0, 10);
                $mKey   = substr($tsIso, 0, 7);
                $amtParsed = js_parse_float($t['amount'] ?? 0);
                $amt = js_truthy($amtParsed) ? (float)$amtParsed : 0.0;

                // "Admin" is the house's own seat — the account the house plays through, not a
                // customer. Its wins are house profit landing in its own wallet, not a payout cost,
                // and its wagers are not a real customer's stake, so it is excluded from the
                // wagered/won ledger to keep the sign of "house profit" correct. It still appears in
                // the recent-activity feed below for transparency.
                $isHouseAccount = strtolower((string)($t['user'] ?? '')) === 'admin';

                if ($cls && !$isHouseAccount && ($cls['kind'] === 'wager' || $cls['kind'] === 'win')) {
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
                }

                if ($cls) {
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

            foreach ($perGame as $g => $pg) {
                $perGame[$g]['wagered']  = to_fixed_num($pg['wagered'], 2);
                $perGame[$g]['paid_out'] = to_fixed_num($pg['paid_out'], 2);
                $perGame[$g]['profit']   = to_fixed_num($pg['wagered'] - $pg['paid_out'], 2);
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
            ]);
        } catch (Throwable $err) {
            $res->status(500)->json(['error' => $err->getMessage()]);
        }
    });

    // --- Per-game betting aggregates (GameBet rows — currently Mines only) ---
    $app->get('/api/admin/game-stats', function (Req $req, Res $res) {
        try {
            $games = ['mines'];
            $stats = [];
            foreach ($games as $game) {
                $total  = (int) scalar('SELECT COUNT(*) FROM `GameBet` WHERE `game` = ?', [$game], 0);
                $active = (int) scalar("SELECT COUNT(*) FROM `GameBet` WHERE `game` = ? AND `status` = 'active'", [$game], 0);
                $won    = (int) scalar("SELECT COUNT(*) FROM `GameBet` WHERE `game` = ? AND `status` = 'won'", [$game], 0);
                $lost   = (int) scalar("SELECT COUNT(*) FROM `GameBet` WHERE `game` = ? AND `status` = 'lost'", [$game], 0);

                $allBets = all('SELECT `bet_amount`,`payout` FROM `GameBet` WHERE `game` = ?', [$game]);
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
            $res->status(500)->json(['error' => $err->getMessage()]);
        }
    });
}
