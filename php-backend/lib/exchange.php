<?php
/**
 * Cricket match betting — Match Odds, Bookmaker, Fancy (session) and Cash Out.
 *
 * =================================================================================================
 * THE HOUSE IS THE COUNTERPARTY, SO RISK CONTROL IS PART OF THE PRODUCT
 * =================================================================================================
 * Prices come from lib/odds-engine.php. Every bet is struck against the house, so this module is as
 * much about limiting what the house can lose as about taking bets:
 *
 *   - markets are OPEN only between deliveries (for window_seconds after a ball is recorded) and are
 *     SUSPENDED while a ball is in play, for wicket_suspend_seconds after a wicket, when the feed
 *     stalls, or when an operator suspends the match;
 *   - a bet is struck at the CURRENT price, and refused ("odds changed") if that is worse for the
 *     player than the price they saw;
 *   - per-bet stake limits, a per-player liability cap per market, and a cap on the house's worst-case
 *     loss per market, all checked inside one transaction under a per-match lock;
 *   - a bet struck within ball_guard_seconds before a delivery, or after a delivery but before the feed
 *     reported it, is VOIDED and refunded — nobody can bet on a ball that has already been bowled;
 *   - no liquidity is invented: the amounts shown under prices are the stakes the house will actually
 *     accept, never fabricated "matched" money.
 *
 * Settlement is idempotent: a market is claimed by inserting its result row (PK match+market) inside
 * the transaction that pays it, so it can be settled once, by whoever gets there first.
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/cricket-feed.php';
require_once __DIR__ . '/odds-engine.php';
require_once __DIR__ . '/cricket-roanuz.php';

// -------------------------------------------------------------------------------------------------
// Configuration
// -------------------------------------------------------------------------------------------------

function mx_config_defaults() {
    return [
        'mo_margin' => 0.02, 'bm_margin' => 0.05,
        'tail_temper' => 0.5,             // market-style compression of near-certain prices (1 = off)
        'min_stake' => 100, 'max_stake_mo' => 25000, 'max_stake_bm' => 50000, 'max_stake_fancy' => 25000,
        'user_max_liability_market' => 100000,
        'house_max_loss_market' => 200000,
        'window_seconds' => 20,           // betting stays open this long after each delivery
        'ball_guard_seconds' => 4,        // bets this close before a ball are void
        'wicket_suspend_seconds' => 25,   // extra pause after a wicket, while the market reprices
    ];
}

function mx_config() {
    $cfg = mx_config_defaults();
    $stored = state_get('mx_config');
    if (is_array($stored)) foreach ($stored as $k => $v) if (array_key_exists($k, $cfg) && is_numeric($v)) $cfg[$k] = $v + 0;
    if (cricket_source_mode() === 'mock') {
        // The simulated league bowls faster than real cricket; keep the same proportions.
        $ball = cricket_mock_conf()['ball_ms'] / 1000;
        $cfg['window_seconds'] = min($cfg['window_seconds'], max(4, (int) floor($ball * 0.55)));
        $cfg['ball_guard_seconds'] = min($cfg['ball_guard_seconds'], max(1, (int) floor($ball * 0.12)));
        $cfg['wicket_suspend_seconds'] = min($cfg['wicket_suspend_seconds'], max(2, (int) floor($ball * 0.25)));
    }
    return $cfg;
}

function mx_config_save(array $in) {
    $cfg = mx_config_defaults();
    $stored = state_get('mx_config');
    if (is_array($stored)) $cfg = array_merge($cfg, $stored);
    $limits = [
        'mo_margin' => [0, 0.2], 'bm_margin' => [0, 0.3], 'tail_temper' => [0.2, 1], 'min_stake' => [1, 100000],
        'max_stake_mo' => [1, 10000000], 'max_stake_bm' => [1, 10000000], 'max_stake_fancy' => [1, 10000000],
        'user_max_liability_market' => [1, 100000000], 'house_max_loss_market' => [1, 1000000000],
        'window_seconds' => [3, 120], 'ball_guard_seconds' => [0, 30], 'wicket_suspend_seconds' => [0, 300],
    ];
    foreach ($limits as $k => [$lo, $hi]) {
        if (!array_key_exists($k, $in)) continue;
        if (!is_numeric($in[$k]) || $in[$k] < $lo || $in[$k] > $hi) return ['ok' => false, 'error' => "$k must be between $lo and $hi."];
        $cfg[$k] = $in[$k] + 0;
    }
    state_set('mx_config', $cfg);
    return ['ok' => true, 'config' => mx_config()];
}

// -------------------------------------------------------------------------------------------------
// Per-match settings (the operator's strength price)
// -------------------------------------------------------------------------------------------------

function mx_settings($matchKey) {
    $row = one('SELECT * FROM "mx_match_settings" WHERE "match_key" = ?', [(string) $matchKey]);
    if (!$row && cricket_source_mode() === 'mock' && cricket_mock_slot_from_key($matchKey) !== null) {
        // Simulated matches get the price an operator would set, from the squads' skill ratings.
        $meta = cricket_mock_match_meta(cricket_mock_slot_from_key($matchKey));
        $avg = function ($teamKey) { $xi = cricket_mock_xi($teamKey); return array_sum(array_column($xi, 'skill')) / count($xi); };
        $delta = round(6.0 * ($avg($meta['team_a']['key']) - $avg($meta['team_b']['key'])), 5);
        $p = odds_sigmoid(abs($delta));
        mx_settings_save($matchKey, $delta >= 0 ? 'a' : 'b', round(1 / $p, 2), 'mock');
        $row = one('SELECT * FROM "mx_match_settings" WHERE "match_key" = ?', [(string) $matchKey]);
    }
    return $row ?: ['match_key' => $matchKey, 'fav_side' => null, 'fav_price' => null, 'delta' => 0, 'suspended' => 0, 'source' => 'none'];
}

function mx_settings_save($matchKey, $favSide, $favPrice, $source = 'operator', $suspended = null) {
    $delta = ($favSide && $favPrice) ? odds_delta_from_price($favSide, $favPrice) : 0.0;
    q('INSERT INTO "mx_match_settings" ("match_key","fav_side","fav_price","delta","source","updated_at") VALUES (?,?,?,?,?,?) '
      . 'ON CONFLICT ("match_key") DO UPDATE SET "fav_side" = EXCLUDED."fav_side", "fav_price" = EXCLUDED."fav_price", '
      . '"delta" = EXCLUDED."delta", "source" = EXCLUDED."source", "updated_at" = EXCLUDED."updated_at"',
      [(string) $matchKey, $favSide, $favPrice, $delta, $source, ms_to_sql(now_ms())]);
    if ($suspended !== null) q('UPDATE "mx_match_settings" SET "suspended" = ? WHERE "match_key" = ?', [$suspended ? 1 : 0, (string) $matchKey]);
}

// -------------------------------------------------------------------------------------------------
// Odds provider slot (API-ready, OFF by default)
// -------------------------------------------------------------------------------------------------

/**
 * Where the match-winner probability comes from. CRICKET_ODDS_SOURCE=engine (default) uses the
 * bet1x engine only. CRICKET_ODDS_SOURCE=roanuz blends in Roanuz's Live Match Odds (₹200/match, if ever
 * bought): the provider's implied probability, refreshed at most every 5 s, weighted by
 * CRICKET_ODDS_BLEND (default 0.7). Any failure falls back to the engine silently — a broken odds feed
 * must never stop the markets from pricing. The Roanuz field names are read through aliases and are
 * unverified until a real response is seen, like the rest of the feed adapter.
 */
function mx_provider_prob($matchKey, $feed, $enginePa) {
    $src = strtolower(trim((string) env_get('CRICKET_ODDS_SOURCE', 'engine')));
    if ($src !== 'roanuz' || $enginePa === null || cricket_source_mode() !== 'roanuz') return $enginePa;
    try {
        $cacheKey = 'mx_odds_' . $matchKey;
        $c = state_get($cacheKey);
        if (!is_array($c) || now_ms() - (int) ($c['ms'] ?? 0) > 5000) {
            $res = roanuz_call('GET', '/match/' . rawurlencode($matchKey) . '/live-match-odds/');
            $pa = null;
            if ($res['ok']) {
                $d = $res['data']['data'] ?? $res['data'];
                $oa = cricket_pick($d, ['match.result_prediction.automatic.decimal.a', 'odds.decimal.a', 'decimal.a', 'teams.a.decimal'], null);
                $ob = cricket_pick($d, ['match.result_prediction.automatic.decimal.b', 'odds.decimal.b', 'decimal.b', 'teams.b.decimal'], null);
                if (is_numeric($oa) && is_numeric($ob) && $oa > 1 && $ob > 1) $pa = (1 / $oa) / ((1 / $oa) + (1 / $ob));
            }
            $c = ['ms' => now_ms(), 'pa' => $pa];
            state_set($cacheKey, $c);
        }
        if ($c['pa'] === null) return $enginePa;
        $w = max(0.0, min(1.0, (float) env_get('CRICKET_ODDS_BLEND', 0.7)));
        return $w * (float) $c['pa'] + (1 - $w) * $enginePa;
    } catch (Throwable $e) {
        return $enginePa;
    }
}

// -------------------------------------------------------------------------------------------------
// Market state
// -------------------------------------------------------------------------------------------------

/**
 * Is betting open right now, and if not, why. Returns ['open' => bool, 'reason' => string].
 * Applies to every market on the match; individual markets add their own closing rules.
 */
function mx_gate(array $feed, array $st, array $settings, array $deliveries, $now, array $cfg) {
    if ((int) ($settings['suspended'] ?? 0) === 1) return ['open' => false, 'reason' => 'Suspended'];
    if ((int) $feed['stalled'] === 1) return ['open' => false, 'reason' => 'Suspended — live data interrupted'];
    if (in_array($feed['status'], ['completed', 'abandoned'], true)) return ['open' => false, 'reason' => 'Closed'];
    // Play is over but the source's result fields disagree (lib/cricket-sportmonks.php): no betting, no settling.
    if (($feed['status_text'] ?? '') === 'awaiting confirmed result') return ['open' => false, 'reason' => 'Awaiting result'];
    if (!odds_model($feed['format'])) return ['open' => false, 'reason' => 'Not offered for this format'];
    if ($feed['status'] === 'not_started') {
        return ['open' => true, 'reason' => 'Pre-match'];
    }
    if ($feed['status'] === 'innings_break') return ['open' => true, 'reason' => 'Innings break'];
    $last = $deliveries ? end($deliveries) : null;
    if (!$last) return ['open' => true, 'reason' => 'Before the first ball'];
    $since = $now - (int) $last['first_seen_ms'];
    if (!empty($last['is_wicket']) && $since < ((int) $cfg['wicket_suspend_seconds']) * 1000) {
        return ['open' => false, 'reason' => 'Wicket — reopening shortly'];
    }
    if ($since <= ((int) $cfg['window_seconds']) * 1000) return ['open' => true, 'reason' => 'Open'];
    return ['open' => false, 'reason' => 'Ball running'];
}

function mx_team_name(array $feed, $side) { return $side === 'a' ? $feed['team_a'] : $feed['team_b']; }
function mx_team_short(array $feed, $side) { return $side === 'a' ? $feed['team_a_short'] : $feed['team_b_short']; }

/**
 * Every market on the match with its live prices and status — the single source both the page and the
 * bet-placement path read, so what a player sees and what they are struck at come from one function.
 */
function mx_markets($matchKey, $nowMs = null) {
    $now = $nowMs ?? now_ms();
    $feed = cricket_match_feed_row($matchKey);
    if (!$feed) return null;
    $deliveries = cricket_match_deliveries($matchKey);
    $st = odds_state($feed, $deliveries);
    $settings = mx_settings($matchKey);
    $cfg = mx_config();
    $gate = mx_gate($feed, $st, $settings, $deliveries, $now, $cfg);
    $settled = [];
    foreach (all('SELECT "market_key","status","result","void_reason" FROM "mx_markets" WHERE "match_key" = ?', [$matchKey]) as $r) $settled[$r['market_key']] = $r;

    $pa = odds_temper(odds_win_prob($st, (float) $settings['delta']), (float) $cfg['tail_temper']);
    $pa = mx_provider_prob($matchKey, $feed, $pa);
    $preMatchNoPrice = $feed['status'] === 'not_started' && empty($settings['fav_price']);

    $out = ['feed' => $feed, 'state' => $st, 'settings' => $settings, 'gate' => $gate, 'config' => $cfg, 'p_a' => $pa, 'markets' => []];

    // --- Match Odds and Bookmaker ---
    foreach (['MO' => 'Match Odds', 'BM' => 'Bookmaker'] as $mk => $name) {
        $m = ['key' => $mk, 'type' => $mk === 'MO' ? 'MATCH_ODDS' : 'BOOKMAKER', 'name' => $name, 'runners' => []];
        $open = $gate['open'] && !isset($settled[$mk]) && $pa !== null && !$preMatchNoPrice;
        $reason = isset($settled[$mk]) ? 'Settled' : ($preMatchNoPrice ? 'Opens when play starts' : $gate['reason']);
        foreach (['a', 'b'] as $side) {
            $p = $pa === null ? null : ($side === 'a' ? $pa : 1 - $pa);
            $r = ['side' => $side, 'name' => mx_team_name($feed, $side), 'short' => mx_team_short($feed, $side), 'p' => $p === null ? null : round($p, 4)];
            if ($mk === 'MO') {
                $lad = $p === null ? null : odds_ladder($p, (float) $cfg['mo_margin']);
                $r['back'] = $lad ? $lad['back'] : null; $r['lay'] = $lad ? $lad['lay'] : null;
                if (!$lad) $open = false;
            } else {
                $bm = $p === null ? null : odds_bookmaker($p, (float) $cfg['bm_margin']);
                $r['back'] = $bm ? $bm['back'] : null; $r['lay'] = $bm ? $bm['lay'] : null;
                if (!$bm) $open = false;
            }
            $m['runners'][] = $r;
        }
        $m['open'] = $open;
        $m['status'] = $open ? 'OPEN' : (isset($settled[$mk]) ? 'SETTLED' : 'SUSPENDED');
        $m['reason'] = $open ? '' : $reason;
        $m['min'] = (float) $cfg['min_stake'];
        $m['max'] = (float) ($mk === 'MO' ? $cfg['max_stake_mo'] : $cfg['max_stake_bm']);
        if (isset($settled[$mk])) $m['result'] = $settled[$mk];
        $out['markets'][$mk] = $m;
    }

    // --- Fancy ---
    if ($st['batting'] && !in_array($feed['status'], ['completed', 'abandoned'], true)) {
        foreach (odds_fancy($st, mx_team_short($feed, $st['batting'])) as $f) {
            if (isset($settled[$f['key']])) continue;
            // Pre-match, a fancy line needs to know who bats first (the toss); in play it follows the gate.
            $open = $gate['open'] && ($feed['status'] !== 'not_started' || $st['first']);
            $f['type'] = 'FANCY';
            $f['open'] = (bool) $open;
            $f['status'] = $open ? 'OPEN' : 'SUSPENDED';
            $f['reason'] = $open ? '' : $gate['reason'];
            $f['min'] = (float) $cfg['min_stake'];
            $f['max'] = (float) $cfg['max_stake_fancy'];
            $out['markets'][$f['key']] = $f;
        }
    }
    return $out;
}

// -------------------------------------------------------------------------------------------------
// Placing a bet
// -------------------------------------------------------------------------------------------------

function mx_err($status, $msg) { return ['ok' => false, 'status' => $status, 'error' => $msg]; }

/** House P&L on a set of bets for each possible outcome of the market (the worst case is what is capped). */
function mx_house_worst(array $bets, $type) {
    if (!$bets) return 0.0;
    if ($type !== 'FANCY') $outcomes = ['a', 'b'];
    else {
        $outcomes = [0, 2000];
        foreach ($bets as $b) { $outcomes[] = (int) $b['line'] - 1; $outcomes[] = (int) $b['line']; }
    }
    $worst = INF;
    foreach ($outcomes as $o) {
        $h = 0.0;
        foreach ($bets as $b) $h += odds_bet_wins($b, $o) ? -(float) $b['profit'] : (float) $b['liability'];
        $worst = min($worst, $h);
    }
    return $worst;
}

/**
 * Strike a bet. $in: market_key, side (BACK|LAY|YES|NO), selection ('a'|'b', ignored for fancy),
 * price (decimal odds for Match Odds, rate for Bookmaker) or line+rate for fancy, stake.
 */
function mx_place_bet(array $user, $matchKey, array $in) {
    $cfg = mx_config();
    $marketKey = (string) ($in['market_key'] ?? '');
    $side = strtoupper((string) ($in['side'] ?? ''));
    $stake = round((float) ($in['stake'] ?? 0), 2);
    if (!is_numeric($in['stake'] ?? null) || $stake <= 0) return mx_err(422, 'Enter a valid stake.');

    try {
        return tx(function () use ($user, $matchKey, $in, $marketKey, $side, $stake, $cfg) {
            // One bet at a time per match, so the exposure checks see every bet already struck.
            q('SELECT pg_advisory_xact_lock(?)', [crc32('mx:' . $matchKey) & 0x7FFFFFFF]);
            $book = mx_markets($matchKey);
            if (!$book) throw new RuntimeException('404:Match not found.');
            $m = $book['markets'][$marketKey] ?? null;
            if (!$m) throw new RuntimeException('409:This market is not available any more.');
            if (!$m['open']) throw new RuntimeException('409:' . ($m['reason'] ?: 'Market suspended') . '.');
            if ($stake < $m['min']) throw new RuntimeException('422:Minimum stake is ₹' . js_num_str($m['min']) . '.');
            if ($stake > $m['max']) throw new RuntimeException('422:Maximum stake is ₹' . js_num_str($m['max']) . '.');

            $sel = 'RUNS'; $selName = $m['name']; $odds = 0.0; $rate = 0.0; $line = null;
            if ($m['type'] === 'FANCY') {
                if (!in_array($side, ['YES', 'NO'], true)) throw new RuntimeException('422:Choose Yes or No.');
                $curLine = $side === 'YES' ? (int) $m['yes_line'] : (int) $m['no_line'];
                $curRate = $side === 'YES' ? (int) $m['yes_rate'] : (int) $m['no_rate'];
                $wantLine = isset($in['line']) ? (int) $in['line'] : $curLine;
                $wantRate = isset($in['rate']) ? (int) $in['rate'] : $curRate;
                // Accept only if the current line is as good or better for the player than the one they saw.
                $better = $side === 'YES' ? ($curLine <= $wantLine) : ($curLine >= $wantLine);
                if (!$better || ($side === 'YES' ? $curRate < $wantRate : $curRate > $wantRate)) {
                    throw new RuntimeException('409:The line has moved to ' . $curLine . ' @' . $curRate . '. Please check and bet again.');
                }
                $line = $curLine; $rate = $curRate;
                $selName = $m['name'] . ' — ' . $side . ' ' . $curLine;
            } else {
                if (!in_array($side, ['BACK', 'LAY'], true)) throw new RuntimeException('422:Choose Back or Lay.');
                $sel = (string) ($in['selection'] ?? '');
                $runner = null;
                foreach ($m['runners'] as $r) if ($r['side'] === $sel) $runner = $r;
                if (!$runner) throw new RuntimeException('422:Pick a team.');
                $selName = $runner['name'];
                if ($m['type'] === 'MATCH_ODDS') {
                    $cur = $side === 'BACK' ? (float) $runner['back'][0] : (float) $runner['lay'][0];
                    $want = isset($in['price']) ? (float) $in['price'] : $cur;
                    if ($side === 'BACK' ? $cur + 1e-9 < $want : $cur - 1e-9 > $want) {
                        throw new RuntimeException('409:Odds changed to ' . $cur . '. Please check and bet again.');
                    }
                    $odds = $cur;
                } else {
                    $cur = $side === 'BACK' ? (int) $runner['back'] : (int) $runner['lay'];
                    $want = isset($in['price']) ? (int) $in['price'] : $cur;
                    if ($side === 'BACK' ? $cur < $want : $cur > $want) {
                        throw new RuntimeException('409:Rate changed to ' . $cur . '. Please check and bet again.');
                    }
                    $rate = $cur; $odds = 1 + $cur / 100;
                }
            }

            $terms = odds_bet_terms($side, $stake, $odds, $rate);
            $newBet = ['side' => $side, 'selection' => $sel, 'line' => $line, 'liability' => $terms['liability'], 'profit' => $terms['profit']];

            $existing = all('SELECT "side","selection","line","liability","profit","user_id" FROM "mx_bets" WHERE "match_key" = ? AND "market_key" = ? AND "status" = ?',
                            [$matchKey, $marketKey, 'PENDING']);
            $mine = 0.0;
            foreach ($existing as $e) if ((int) $e['user_id'] === (int) $user['id']) $mine += (float) $e['liability'];
            if ($mine + $terms['liability'] > (float) $cfg['user_max_liability_market'] + 1e-9) {
                throw new RuntimeException('422:That would take your exposure on this market over ₹' . js_num_str($cfg['user_max_liability_market']) . '.');
            }
            $existing[] = $newBet;
            if (mx_house_worst($existing, $m['type']) < -(float) $cfg['house_max_loss_market']) {
                throw new RuntimeException('422:This market has reached its limit for that selection. Try a smaller stake.');
            }

            $balance = debit_wallet((int) $user['id'], $terms['liability']);
            if ($balance === null) throw new RuntimeException('402:Insufficient balance (needs ₹' . number_format($terms['liability'], 2) . ').');
            $txn = new_record_id('MXB');
            $desc = 'Cricket Bet — ' . $m['name'] . ' · ' . $side . ' ' . ($m['type'] === 'FANCY' ? $line . ' @' . $rate : $selName . ' @' . ($m['type'] === 'BOOKMAKER' ? $rate : $odds));
            insert_transaction($txn, (string) $user['username'], 'Withdrawal', $terms['liability'], $desc . ' (' . $book['feed']['title'] . ')', 'Completed');
            q('INSERT INTO "mx_bets" ("match_key","market_key","market_type","market_name","selection","selection_name","side","odds","rate","line",'
              . '"stake","liability","profit","user_id","username","txn_id","status","created_at") VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
              [$matchKey, $marketKey, $m['type'], $m['name'], $sel, $selName, $side, $odds, $rate, $line, $stake,
               $terms['liability'], $terms['profit'], (int) $user['id'], (string) $user['username'], $txn, 'PENDING', ms_to_sql(now_ms())]);
            return ['ok' => true, 'bet_id' => (int) db_or_throw()->lastInsertId(), 'new_balance' => $balance,
                    'odds' => $odds, 'rate' => $rate, 'line' => $line, 'liability' => $terms['liability'], 'profit' => $terms['profit']];
        });
    } catch (RuntimeException $e) {
        if (preg_match('/^(\d{3}):(.*)$/s', $e->getMessage(), $mm)) return mx_err((int) $mm[1], $mm[2]);
        throw $e;
    }
}

// -------------------------------------------------------------------------------------------------
// Settlement
// -------------------------------------------------------------------------------------------------

/** Pay or void every pending bet on one market, and record its result. Idempotent. */
function mx_settle_market($matchKey, $marketKey, $type, $name, $outcome, $voidReason = null) {
    return tx(function () use ($matchKey, $marketKey, $type, $name, $outcome, $voidReason) {
        $claimed = affected('INSERT INTO "mx_markets" ("match_key","market_key","market_type","market_name","status","result","void_reason","settled_at") '
                          . 'VALUES (?,?,?,?,?,?,?,?) ON CONFLICT ("match_key","market_key") DO NOTHING',
                            [$matchKey, $marketKey, $type, $name, $voidReason ? 'VOID' : 'SETTLED', $voidReason ? null : (string) $outcome,
                             $voidReason, ms_to_sql(now_ms())]);
        if ($claimed !== 1) return ['ok' => true, 'already' => true];
        $bets = all('SELECT * FROM "mx_bets" WHERE "match_key" = ? AND "market_key" = ? AND "status" = ? FOR UPDATE', [$matchKey, $marketKey, 'PENDING']);
        $nowSql = ms_to_sql(now_ms());
        $paid = 0.0;
        foreach ($bets as $b) {
            if ($voidReason) {
                $amt = (float) $b['liability'];
                credit_wallet((int) $b['user_id'], $amt);
                insert_transaction(new_record_id('MXR'), (string) $b['username'], 'Deposit', $amt, 'Cricket Refund — ' . $name . ' (void: ' . $voidReason . ')', 'Completed');
                q('UPDATE "mx_bets" SET "status" = ?, "payout" = ?, "void_reason" = ?, "settled_at" = ? WHERE "id" = ?', ['VOID', $amt, $voidReason, $nowSql, $b['id']]);
                $paid += $amt;
            } elseif (odds_bet_wins($b, $outcome)) {
                $amt = round((float) $b['liability'] + (float) $b['profit'], 2);
                credit_wallet((int) $b['user_id'], $amt);
                insert_transaction(new_record_id('MXW'), (string) $b['username'], 'Deposit', $amt, 'Cricket Win — ' . $name . ' · ' . $b['side'] . ' ' . $b['selection_name'], 'Completed');
                q('UPDATE "mx_bets" SET "status" = ?, "payout" = ?, "settled_at" = ? WHERE "id" = ?', ['WON', $amt, $nowSql, $b['id']]);
                $paid += $amt;
            } else {
                q('UPDATE "mx_bets" SET "status" = ?, "payout" = 0, "settled_at" = ? WHERE "id" = ?', ['LOST', $nowSql, $b['id']]);
            }
        }
        return ['ok' => true, 'bets' => count($bets), 'paid' => round($paid, 2)];
    });
}

/** Void one bet (struck too close to a ball). */
function mx_void_bet($betId, $reason) {
    return tx(function () use ($betId, $reason) {
        $b = one('SELECT * FROM "mx_bets" WHERE "id" = ? FOR UPDATE', [(int) $betId]);
        if (!$b || $b['status'] !== 'PENDING') return false;
        $amt = (float) $b['liability'];
        credit_wallet((int) $b['user_id'], $amt);
        insert_transaction(new_record_id('MXR'), (string) $b['username'], 'Deposit', $amt, 'Cricket Refund — ' . $b['market_name'] . ' (void: ' . $reason . ')', 'Completed');
        q('UPDATE "mx_bets" SET "status" = ?, "payout" = ?, "void_reason" = ?, "settled_at" = ? WHERE "id" = ?', ['VOID', $amt, $reason, ms_to_sql(now_ms()), $b['id']]);
        return true;
    });
}

/**
 * Bring a match's betting up to date with the feed: void late bets, settle every fancy line whose over
 * is complete, and settle (or void) the match markets when the match is over. Safe to call any time.
 */
function mx_process_match($matchKey, $nowMs = null) {
    $now = $nowMs ?? now_ms();
    $feed = cricket_match_feed_row($matchKey);
    if (!$feed) return ['ok' => false];
    $cfg = mx_config();
    $deliveries = cricket_match_deliveries($matchKey);
    $sum = ['voided_late' => 0, 'settled' => 0, 'voided' => 0];

    // 1. Late bets: struck between (ball - guard) and the moment the feed reported that ball.
    $pending = all('SELECT "id","created_at" FROM "mx_bets" WHERE "match_key" = ? AND "status" = ? AND "created_at" > ?',
                   [$matchKey, 'PENDING', ms_to_sql($now - 30 * 60000)]);
    if ($pending && $deliveries) {
        $guard = ((int) $cfg['ball_guard_seconds']) * 1000;
        foreach ($pending as $b) {
            $t = sql_to_ms($b['created_at']);
            foreach ($deliveries as $d) {
                $lo = (int) $d['first_seen_ms'] - $guard;
                $hi = max((int) $d['first_seen_ms'], (int) ($d['received_ms'] ?? $d['first_seen_ms']));
                if ($t >= $lo && $t <= $hi) {
                    if (mx_void_bet((int) $b['id'], 'struck while a ball was being bowled')) $sum['voided_late']++;
                    break;
                }
            }
        }
    }

    // 2. Fancy lines that have resolved.
    $inn = [];
    foreach ($deliveries as $d) { if (empty($d['is_super_over'])) $inn[(int) $d['innings']][] = $d; }
    $model = odds_model($feed['format']);
    $maxBalls = $model ? (int) $model['max_balls'] : 120;
    $openKeys = array_column(all('SELECT DISTINCT "market_key", "market_name" FROM "mx_bets" WHERE "match_key" = ? AND "status" = ? AND "market_type" = ?',
                                 [$matchKey, 'PENDING', 'FANCY']), 'market_name', 'market_key');
    $matchOver = in_array($feed['status'], ['completed', 'abandoned'], true);
    foreach ($openKeys as $key => $name) {
        if (!preg_match('/^(ms|ov)_(\d)_(\d+)$/', $key, $mm)) continue;
        [$all_, $kind, $i, $n] = $mm; $i = (int) $i; $n = (int) $n;
        $balls = $inn[$i] ?? [];
        $legal = 0; $runs = 0; $wk = 0; $atEnd = null; $overRuns = 0; $overDone = false;
        foreach ($balls as $d) {
            $r = (int) $d['batsman_runs'] + (int) $d['extra_runs'];
            if ($kind === 'ov' && $legal >= $n * 6 && $legal < ($n + 1) * 6) $overRuns += $r;
            $runs += $r;
            if (!empty($d['is_wicket'])) $wk++;
            if (!empty($d['is_legal'])) {
                $legal++;
                if ($kind === 'ms' && $legal === $n * 6) $atEnd = $runs;
                if ($kind === 'ov' && $legal === ($n + 1) * 6) $overDone = true;
            }
        }
        $inningsEnded = $wk >= 10 || $legal >= $maxBalls || isset($inn[$i + 1])
                        || ($matchOver && $feed['status'] === 'completed')
                        || ($i === 2 && !empty($inn[1]) && $runs > array_sum(array_map(function ($d) { return (int) $d['batsman_runs'] + (int) $d['extra_runs']; }, $inn[1])));
        if ($feed['status'] === 'abandoned') {
            if (mx_settle_market($matchKey, $key, 'FANCY', $name, null, 'match abandoned')['ok']) $sum['voided']++;
            continue;
        }
        if ($kind === 'ms') {
            if ($atEnd !== null) $res = mx_settle_market($matchKey, $key, 'FANCY', $name, $atEnd);
            elseif ($inningsEnded) $res = mx_settle_market($matchKey, $key, 'FANCY', $name, $runs);   // innings ended early: settles at the total
            else continue;
        } else {
            if ($overDone) $res = mx_settle_market($matchKey, $key, 'FANCY', $name, $overRuns);
            elseif ($inningsEnded) $res = mx_settle_market($matchKey, $key, 'FANCY', $name, null, 'over not completed');
            else continue;
        }
        if ($res['ok'] && empty($res['already'])) $sum['settled']++;
    }

    // 3. The match markets.
    if ($matchOver) {
        $winner = $feed['meta']['winner'] ?? null;
        if ($winner !== null && $winner !== 'a' && $winner !== 'b') {
            $winner = $winner === $feed['team_a_key'] ? 'a' : ($winner === $feed['team_b_key'] ? 'b' : null);
        }
        foreach (['MO' => ['MATCH_ODDS', 'Match Odds'], 'BM' => ['BOOKMAKER', 'Bookmaker']] as $mk => [$type, $name]) {
            if ($feed['status'] === 'abandoned') $res = mx_settle_market($matchKey, $mk, $type, $name, null, 'match abandoned / no result');
            elseif ($winner === null) $res = mx_settle_market($matchKey, $mk, $type, $name, null, 'match tied');
            else $res = mx_settle_market($matchKey, $mk, $type, $name, $winner);
            if ($res['ok'] && empty($res['already'])) $sum['settled']++;
        }
    }
    return ['ok' => true] + $sum;
}

// -------------------------------------------------------------------------------------------------
// Cash out
// -------------------------------------------------------------------------------------------------

/** The cash-out offer for the caller on a two-way market, at the current prices, or null. */
function mx_cashout_offer(array $book, array $bets, $marketKey) {
    $m = $book['markets'][$marketKey] ?? null;
    if (!$m || !in_array($m['type'], ['MATCH_ODDS', 'BOOKMAKER'], true) || !$bets) return null;
    $a = $m['runners'][0];
    if (!$a['back'] || !$a['lay']) return null;
    $back = $m['type'] === 'MATCH_ODDS' ? (float) $a['back'][0] : 1 + $a['back'] / 100;
    $lay = $m['type'] === 'MATCH_ODDS' ? (float) $a['lay'][0] : 1 + $a['lay'] / 100;
    $pos = odds_position($bets);
    $value = odds_cashout_value($pos, $back, $lay);
    return ['value' => max(0, $value), 'profit' => round($value - $pos['locked'], 2), 'locked' => $pos['locked'], 'open' => $m['open']];
}

function mx_cashout(array $user, $matchKey, $marketKey, $expected = null) {
    try {
        return tx(function () use ($user, $matchKey, $marketKey, $expected) {
            q('SELECT pg_advisory_xact_lock(?)', [crc32('mx:' . $matchKey) & 0x7FFFFFFF]);
            $book = mx_markets($matchKey);
            if (!$book) throw new RuntimeException('404:Match not found.');
            $bets = all('SELECT * FROM "mx_bets" WHERE "match_key" = ? AND "market_key" = ? AND "user_id" = ? AND "status" = ? FOR UPDATE',
                        [$matchKey, $marketKey, (int) $user['id'], 'PENDING']);
            if (!$bets) throw new RuntimeException('404:You have no open bets on this market.');
            $offer = mx_cashout_offer($book, $bets, $marketKey);
            if (!$offer) throw new RuntimeException('409:Cash out is not available on this market.');
            if (!$offer['open']) throw new RuntimeException('409:Cash out is suspended while the market is suspended.');
            if ($expected !== null && $offer['value'] + 0.005 < (float) $expected) {
                throw new RuntimeException('409:The cash-out value has changed to ₹' . number_format($offer['value'], 2) . '.');
            }
            $value = $offer['value'];
            $nowSql = ms_to_sql(now_ms());
            $totalLocked = array_sum(array_map(function ($b) { return (float) $b['liability']; }, $bets));
            // Split the credited amount across the bets it closes, the rounding remainder to the last one,
            // so the per-bet payouts always add up to exactly what the wallet received.
            $given = 0.0; $n = count($bets);
            foreach (array_values($bets) as $i => $b) {
                $share = $i === $n - 1 ? round($value - $given, 2)
                                       : ($totalLocked > 0 ? round($value * (float) $b['liability'] / $totalLocked, 2) : 0);
                $given += $share;
                q('UPDATE "mx_bets" SET "status" = ?, "payout" = ?, "settled_at" = ? WHERE "id" = ?', ['CASHED_OUT', $share, $nowSql, $b['id']]);
            }
            if ($value > 0) {
                credit_wallet((int) $user['id'], $value);
                insert_transaction(new_record_id('MXC'), (string) $user['username'], 'Deposit', $value,
                    'Cricket Cash Out — ' . $book['markets'][$marketKey]['name'] . ' (' . $book['feed']['title'] . ')', 'Completed');
            }
            $u = find_user_by_id($user['id']);
            return ['ok' => true, 'value' => $value, 'profit' => $offer['profit'], 'new_balance' => $u ? (float) $u['wallet_balance'] : null];
        });
    } catch (RuntimeException $e) {
        if (preg_match('/^(\d{3}):(.*)$/s', $e->getMessage(), $mm)) return mx_err((int) $mm[1], $mm[2]);
        throw $e;
    }
}

// -------------------------------------------------------------------------------------------------
// The page
// -------------------------------------------------------------------------------------------------

/**
 * Everything the match page shows in one poll: score, every market with prices and status, the
 * caller's position on each (P&L per team, the run-ladder "book" for fancy), cash-out offers, and
 * their open bets.
 */
function mx_page($matchKey, $userId = null, $nowMs = null) {
    $now = $nowMs ?? now_ms();
    $book = mx_markets($matchKey, $now);
    if (!$book) return null;
    $feed = $book['feed'];
    $board = cricket_derive_scoreboard($feed, cricket_match_deliveries($matchKey));

    $myBets = $userId ? all('SELECT * FROM "mx_bets" WHERE "match_key" = ? AND "user_id" = ? ORDER BY "id" DESC LIMIT 200', [$matchKey, (int) $userId]) : [];
    $pendingBy = [];
    foreach ($myBets as $b) if ($b['status'] === 'PENDING') $pendingBy[$b['market_key']][] = $b;

    $markets = [];
    foreach ($book['markets'] as $k => $m) {
        $mine = $pendingBy[$k] ?? [];
        if ($m['type'] !== 'FANCY') {
            $pos = $mine ? odds_position($mine) : null;
            $m['position'] = $pos ? $pos['pnl'] : null;
            $m['cashout'] = $mine ? mx_cashout_offer($book, $mine, $k) : null;
        } else {
            // Run-ladder book: the caller's P&L for each band of possible runs, as the "Book" button shows.
            $m['position'] = null;
            if ($mine) {
                $pts = [];
                foreach ($mine as $b) { $pts[] = (int) $b['line'] - 1; $pts[] = (int) $b['line']; }
                sort($pts); $pts = array_values(array_unique($pts));
                $ladder = [];
                $locked = array_sum(array_map(function ($b) { return (float) $b['liability']; }, $mine));
                foreach (array_merge([max(0, $pts[0] - 1)], $pts) as $v) {
                    $c = 0.0; foreach ($mine as $b) if (odds_bet_wins($b, $v)) $c += (float) $b['liability'] + (float) $b['profit'];
                    $ladder[] = ['runs' => $v, 'pnl' => round($c - $locked, 2)];
                }
                $m['position'] = $ladder;
            }
        }
        $markets[] = $m;
    }

    $fmt = function ($b) {
        return ['id' => (int) $b['id'], 'market_key' => $b['market_key'], 'market' => $b['market_name'], 'type' => $b['market_type'],
                'selection' => $b['selection_name'], 'side' => $b['side'], 'odds' => (float) $b['odds'], 'rate' => (float) $b['rate'],
                'line' => $b['line'] === null ? null : (int) $b['line'], 'stake' => (float) $b['stake'], 'liability' => (float) $b['liability'],
                'profit' => (float) $b['profit'], 'status' => $b['status'], 'payout' => (float) $b['payout'], 'void_reason' => $b['void_reason'],
                'created_ms' => sql_to_ms($b['created_at'])];
    };

    return [
        'match' => cricket_match_summary($feed, $board),
        'scoreboard' => ['current' => $board['current'], 'innings' => array_map(function ($i) {
            return ['team_short' => $i['team_short'], 'team' => $i['team'], 'runs' => $i['runs'], 'wickets' => $i['wickets'], 'overs' => $i['overs'], 'run_rate' => $i['run_rate']];
        }, $board['innings']), 'chase' => $board['chase'], 'this_over' => $board['this_over'], 'last_balls' => $board['last_balls']],
        'gate' => $book['gate'],
        'markets' => $markets,
        'open_bets' => array_values(array_map($fmt, array_filter($myBets, function ($b) { return $b['status'] === 'PENDING'; }))),
        'settled_bets' => array_values(array_map($fmt, array_slice(array_filter($myBets, function ($b) { return $b['status'] !== 'PENDING'; }), 0, 30))),
        'pricing' => ['fav_side' => $book['settings']['fav_side'], 'fav_price' => $book['settings']['fav_price'] === null ? null : (float) $book['settings']['fav_price'],
                      'source' => $book['settings']['source']],
        'server_time_ms' => $now,
    ];
}

function mx_my_bets($userId, $limit = 100) {
    return all('SELECT b.*, f."title" FROM "mx_bets" b LEFT JOIN "cricket_match_feed" f ON f."match_key" = b."match_key" '
             . 'WHERE b."user_id" = ? ORDER BY b."id" DESC LIMIT ' . max(1, min(300, (int) $limit)), [(int) $userId]);
}
