<?php
/**
 * Ball by Ball — predict what the next delivery will be.
 *
 * =================================================================================================
 * THE GAME, AS THE ESTABLISHED SITES RUN IT
 * =================================================================================================
 * One market per delivery, eight outcomes: 0, 1, 2, 3, 4 or 6 runs, WICKET, or EXTRA (wide/no-ball).
 * The market opens the moment the previous delivery is recorded, takes bets for a short window, then
 * shows BALL RUNNING (suspended) until the delivery lands in the feed, and settles on it.
 *
 * =================================================================================================
 * A POOL, NOT A BOOK
 * =================================================================================================
 * Every stake on a delivery goes into one pool. The house takes rake_pct of a settled pool and has no
 * other interest in the result: winners split the rest in proportion to what they staked. There is
 * no house position on any outcome, so there is no ball the house would prefer and nothing to rig —
 * the live multipliers on screen are simply each outcome's share of the pool.
 *
 * Two edge cases are decided in the player's favour, as a fair tote does:
 *   - nobody backed the winning outcome: every stake is refunded in full, rake waived;
 *   - the pool is so one-sided that a winner's share would be below their stake: winners get their
 *     stake back, and the house keeps only what the losers staked.
 *
 * =================================================================================================
 * THE INTEGRITY RULE THAT MATTERS MOST
 * =================================================================================================
 * Betting must be closed before the ball is bowled. No affordable feed sends a "bowler running in"
 * signal, so the window is time-based — and the feed's own latency is the risk: a delivery reaches
 * us a second or few after it happened. So a market only SETTLES if its window closed at least
 * latency_guard_seconds before its delivery arrived. If the ball arrived sooner than that — a quick
 * over, a delayed push — nobody can prove every bet was placed before the ball, and the market is
 * VOIDED with every stake refunded. The same happens when the feed stalls, the match is abandoned,
 * or the delivery is something the market does not list (a five, a super over ball).
 *
 * Nothing here moves money outside tx(): a bet is the debit, the ledger row and the bet row together;
 * a settlement is every payout and the round's status together. A round is claimed by a conditional
 * status update inside its transaction, so two workers can never settle it twice.
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/cricket-feed.php';

// -------------------------------------------------------------------------------------------------
// Configuration
// -------------------------------------------------------------------------------------------------

function bbb_outcomes() {
    return [
        '0'  => '0 Runs',
        '1'  => '1 Run',
        '2'  => '2 Runs',
        '3'  => '3 Runs',
        '4'  => '4 Runs',
        '6'  => '6 Runs',
        'W'  => 'Wicket',
        'EX' => 'Wide / No Ball',
    ];
}

function bbb_config_defaults() {
    return [
        'rake_pct'               => 10.0,
        'min_stake'              => 10.0,
        'max_stake'              => 5000.0,
        'max_user_stake_per_ball' => 10000.0,
        // How long a market takes bets after the previous delivery is recorded. A T20 delivery comes
        // round every 35-45 seconds; this closes well before the next one is bowled.
        'window_seconds'         => 12,
        // A market only settles if it closed at least this long before its delivery arrived.
        'latency_guard_seconds'  => 4,
        // A delivery only settles once it has stood unchanged this long. Feeds correct themselves: on
        // the 9 Oct 2026 Sportmonks test about 1 ball in 7 was first posted wrong (FOUR -> 5 Wides) and
        // fixed within ~10s, and wrong entries were sometimes withdrawn altogether.
        'confirm_seconds'        => 20,
        // No push for this long during play = the feed is stalled: void and stop opening markets.
        'stall_seconds'          => 90,
    ];
}

function bbb_config() {
    $stored = state_get('bbb_config');
    $cfg = bbb_config_defaults();
    if (is_array($stored)) foreach ($stored as $k => $v) if (array_key_exists($k, $cfg) && is_numeric($v)) $cfg[$k] = $v + 0;
    // In mock mode balls come quicker than in real cricket, so the window scales with the mock pace.
    if (cricket_source_mode() === 'mock') {
        $ball = cricket_mock_conf()['ball_ms'] / 1000;
        $cfg['window_seconds'] = min($cfg['window_seconds'], max(4, (int) floor($ball * 0.5)));
        $cfg['latency_guard_seconds'] = min($cfg['latency_guard_seconds'], max(1, (int) floor($ball * 0.12)));
        $cfg['confirm_seconds'] = 0;   // the simulator never corrects a ball
    }
    // A polled feed reaches us ~5s after the ball (Sportmonks, measured 9 Oct 2026), which a 12s window
    // counted from the ball would mostly eat. 15s leaves ~10s to bet. Longer costs more voids: ~17% of
    // Sportmonks balls are posted under 8s after the previous one (the scorer catching up), and those
    // must void whatever the window; measured over 298 balls, 15s voids ~20%, 20s ~25%.
    if (cricket_source_mode() === 'sportmonks') $cfg['window_seconds'] = max($cfg['window_seconds'], 15);
    // Replaying the two 9 Oct matches: 16 of 495 balls were corrected and 10 deleted after first posting,
    // 13 of the 16 and all 10 deletions within 90s (SIX -> WICKET came 48s later). A 20s hold settled
    // 3 of 136 rounds on the wrong ball; 90s catches all but a rare very late review.
    if (cricket_source_mode() === 'sportmonks') $cfg['confirm_seconds'] = max($cfg['confirm_seconds'], 90);
    return $cfg;
}

function bbb_config_save(array $in) {
    $cfg = bbb_config_defaults();
    $stored = state_get('bbb_config');
    if (is_array($stored)) $cfg = array_merge($cfg, $stored);
    $limits = [
        'rake_pct' => [0, 30], 'min_stake' => [1, 100000], 'max_stake' => [1, 1000000],
        'max_user_stake_per_ball' => [1, 10000000], 'window_seconds' => [3, 60],
        'latency_guard_seconds' => [0, 30], 'stall_seconds' => [20, 600], 'confirm_seconds' => [0, 120],
    ];
    foreach ($limits as $k => [$lo, $hi]) {
        if (!array_key_exists($k, $in)) continue;
        if (!is_numeric($in[$k]) || $in[$k] < $lo || $in[$k] > $hi) {
            return ['ok' => false, 'error' => "$k must be between $lo and $hi."];
        }
        $cfg[$k] = $in[$k] + 0;
    }
    if ($cfg['min_stake'] > $cfg['max_stake']) return ['ok' => false, 'error' => 'min_stake cannot exceed max_stake.'];
    state_set('bbb_config', $cfg);
    return ['ok' => true, 'config' => bbb_config()];
}

// -------------------------------------------------------------------------------------------------
// Pure rules
// -------------------------------------------------------------------------------------------------

/**
 * Which outcome a delivery settles as, or null when the market does not list it (and so voids).
 *
 *   EXTRA   any wide or no-ball — the illegality is the headline, even if runs or a run-out came
 *           off it, and a no-ball hit for six is still EXTRA;
 *   WICKET  a dismissal on a legal delivery, run-outs included (retired hurt is not a dismissal);
 *   0..6    otherwise the total runs the delivery added — off the bat, or byes/leg-byes.
 */
function bbb_classify(array $delivery) {
    if (!empty($delivery['is_super_over'])) return null;
    if (in_array($delivery['extra_type'], ['wide', 'noball'], true)) return 'EX';
    if (!empty($delivery['is_wicket'])) return 'W';
    $total = (int) $delivery['batsman_runs'] + (int) $delivery['extra_runs'];
    return in_array((string) $total, ['0', '1', '2', '3', '4', '6'], true) ? (string) $total : null;
}

function bbb_floor2($n) { return floor(((float) $n) * 100 + 1e-7) / 100; }

/**
 * Split a settled pool. Pure, so the money arithmetic is tested without a database.
 *
 * $bets: [['id', 'outcome', 'stake', 'created_at'], ...]
 * Returns ['mode' => 'paid'|'refund_no_winner'|'empty', 'pool', 'rake', 'paid', 'payouts' => [id => amount]].
 */
function bbb_allocate(array $bets, $outcome, $rakePct) {
    $pool = 0.0;
    foreach ($bets as $b) $pool += (float) $b['stake'];
    $pool = round($pool, 2);
    if (!$bets) return ['mode' => 'empty', 'pool' => 0.0, 'rake' => 0.0, 'paid' => 0.0, 'payouts' => []];

    $winners = array_values(array_filter($bets, function ($b) use ($outcome) { return (string) $b['outcome'] === (string) $outcome; }));
    $winStake = 0.0;
    foreach ($winners as $w) $winStake += (float) $w['stake'];
    $winStake = round($winStake, 2);

    if (!$winners || $winStake <= 0) {
        $payouts = [];
        foreach ($bets as $b) $payouts[$b['id']] = round((float) $b['stake'], 2);
        return ['mode' => 'refund_no_winner', 'pool' => $pool, 'rake' => 0.0, 'paid' => $pool, 'payouts' => $payouts];
    }

    $distributable = round($pool * (1 - ((float) $rakePct) / 100), 2);
    // A winner never gets back less than they staked: the house's take is capped at what losers staked.
    if ($distributable < $winStake) $distributable = $winStake;

    usort($winners, function ($a, $b) {
        $d = (float) $b['stake'] - (float) $a['stake'];
        if (abs($d) > 1e-9) return $d > 0 ? 1 : -1;
        return strcmp((string) ($a['created_at'] ?? ''), (string) ($b['created_at'] ?? '')) ?: ((int) $a['id'] <=> (int) $b['id']);
    });
    $payouts = [];
    $sum = 0.0;
    foreach ($winners as $w) {
        $share = bbb_floor2($distributable * ((float) $w['stake']) / $winStake);
        $payouts[$w['id']] = $share;
        $sum += $share;
    }
    $remainder = round($distributable - $sum, 2);
    if ($remainder > 0) $payouts[$winners[0]['id']] = round($payouts[$winners[0]['id']] + $remainder, 2);

    return ['mode' => 'paid', 'pool' => $pool, 'rake' => round($pool - $distributable, 2),
            'paid' => $distributable, 'payouts' => $payouts];
}

/** Legal balls in a full innings for a format, or null for unlimited (Tests). */
function bbb_innings_balls($format) {
    switch (strtoupper((string) $format)) {
        case 'T10': return 60;
        case 'HUNDRED': return 100;
        case 'ODI': return 300;
        case 'TEST': return null;
        default: return 120;
    }
}

/**
 * Where the next delivery is: which innings, its index within the innings (deliveries already bowled,
 * legal or not), and its over.ball label. Returns null when there is no next delivery — the match is
 * over, or both innings of a limited-overs game are done.
 */
function bbb_next_delivery(array $feed, array $deliveries) {
    $format = $feed['format'] ?? 'T20';
    $byInnings = [];
    foreach ($deliveries as $d) {
        if (!empty($d['is_super_over'])) continue;
        $byInnings[$d['innings']][] = $d;
    }
    $maxBalls = bbb_innings_balls($format);
    $inningsCount = strtoupper((string) $format) === 'TEST' ? 4 : 2;

    if (!$byInnings) return ['innings' => 1, 'idx' => 0, 'over_no' => 0, 'ball_no' => 1];

    $cur = max(array_keys($byInnings));
    $balls = $byInnings[$cur];
    $legal = 0; $wkts = 0; $runs = 0;
    foreach ($balls as $b) { if ($b['is_legal']) $legal++; if ($b['is_wicket']) $wkts++; $runs += cricket_ball_total($b); }

    $target = null;
    if ($cur === 2 && isset($byInnings[1])) {
        $t = 0; foreach ($byInnings[1] as $b) $t += cricket_ball_total($b);
        $target = $t + 1;
    }
    $metaTarget = $feed['meta']['target'] ?? null;
    if ($metaTarget) $target = (int) $metaTarget;

    $inningsOver = $wkts >= 10 || ($maxBalls !== null && $legal >= $maxBalls) || ($cur === $inningsCount && $target !== null && $runs >= $target);
    if ($inningsOver) {
        if ($cur >= $inningsCount) return null;
        return ['innings' => $cur + 1, 'idx' => 0, 'over_no' => 0, 'ball_no' => 1];
    }

    // The label comes from the count of legal balls, not from the feed's over.ball numbering, which
    // providers disagree on for wides. A wide or no-ball is re-bowled, so the next delivery carries
    // the same label as the one it replaces — which is exactly what counting legal balls gives.
    return ['innings' => $cur, 'idx' => count($balls), 'over_no' => intdiv($legal, 6), 'ball_no' => ($legal % 6) + 1];
}

function bbb_label(array $next, array $feed) {
    $order = $feed['meta']['innings_order'] ?? [];
    $sideKey = $order[$next['innings'] - 1] ?? null;
    $side = $sideKey ? substr($sideKey, 0, 1) : null;
    $team = $side === 'a' ? $feed['team_a_short'] : ($side === 'b' ? $feed['team_b_short'] : 'Inns ' . $next['innings']);
    return $team . ' · Over ' . $next['over_no'] . '.' . $next['ball_no'];
}

// -------------------------------------------------------------------------------------------------
// Round lifecycle — driven by every feed update and by the tick
// -------------------------------------------------------------------------------------------------

/**
 * Bring a match's markets up to date with the feed:
 *   1. settle (or void) every open market whose delivery has arrived;
 *   2. void everything left if the match has ended, been abandoned, or the feed has stalled;
 *   3. open the market on the next delivery if play is on and it is not open yet.
 *
 * Safe to call as often as anyone likes; every step is idempotent.
 */
function bbb_process_match($matchKey, $nowMs = null) {
    $now = $nowMs ?? now_ms();
    $feed = cricket_match_feed_row($matchKey);
    if (!$feed) return ['ok' => false, 'error' => 'unknown match'];
    $cfg = bbb_config();
    $deliveries = cricket_match_deliveries($matchKey);

    // Index deliveries by (innings, position within innings).
    $pos = [];
    $count = [];
    foreach ($deliveries as $d) {
        $i = $d['innings'];
        if (!empty($d['is_super_over'])) continue;
        $idx = $count[$i] ?? 0;
        $pos[$i . ':' . $idx] = $d;
        $count[$i] = $idx + 1;
    }

    $summary = ['settled' => 0, 'voided' => 0, 'opened' => 0];

    // 1. Resolve every pending market whose ball is in.
    $pending = all('SELECT * FROM "bbb_rounds" WHERE "match_key" = ? AND "status" IN (?, ?) ORDER BY "id" ASC',
                   [$matchKey, 'OPEN', 'SUSPENDED']);
    foreach ($pending as $r) {
        $k = $r['innings'] . ':' . $r['delivery_idx'];
        if (!isset($pos[$k])) continue;
        $d = $pos[$k];
        // Not yet: the ball changed (or arrived) too recently to be trusted. A later pass settles it.
        if ($now - (int) $d['received_ms'] < ((int) $cfg['confirm_seconds']) * 1000) continue;
        $closesMs = sql_to_ms($r['closes_at']);
        $arrivedMs = $d['first_seen_ms'];
        if ($arrivedMs < $closesMs + ((int) $cfg['latency_guard_seconds']) * 1000) {
            if (bbb_void_round((int) $r['id'], 'ball_before_close')['ok']) $summary['voided']++;
            continue;
        }
        $outcome = bbb_classify($d);
        if ($outcome === null) {
            if (bbb_void_round((int) $r['id'], 'outcome_not_offered')['ok']) $summary['voided']++;
            continue;
        }
        $res = bbb_settle_round((int) $r['id'], $outcome, $d);
        if ($res['ok'] && empty($res['already'])) $summary['settled']++;
    }

    // 2. Nothing should be left open once play cannot continue.
    $status = $feed['status'];
    $stalled = (int) $feed['stalled'] === 1;
    $lastFeedMs = $feed['last_feed_at'] ? sql_to_ms($feed['last_feed_at']) : 0;
    if ($status === 'live' && $lastFeedMs && $now - $lastFeedMs > ((int) $cfg['stall_seconds']) * 1000) {
        q('UPDATE "cricket_match_feed" SET "stalled" = 1 WHERE "match_key" = ?', [$matchKey]);
        $stalled = true;
    }
    if (in_array($status, ['completed', 'abandoned'], true) || $stalled) {
        $reason = $stalled ? 'feed_stalled' : ($status === 'abandoned' ? 'match_abandoned' : 'match_ended');
        foreach (all('SELECT "id" FROM "bbb_rounds" WHERE "match_key" = ? AND "status" IN (?, ?)', [$matchKey, 'OPEN', 'SUSPENDED']) as $r) {
            if (bbb_void_round((int) $r['id'], $reason)['ok']) $summary['voided']++;
        }
        return ['ok' => true] + $summary;
    }

    // 3. Mark windows that have run out, then open the next market.
    q('UPDATE "bbb_rounds" SET "status" = ? WHERE "match_key" = ? AND "status" = ? AND "closes_at" <= ?',
      ['SUSPENDED', $matchKey, 'OPEN', ms_to_sql($now)]);

    if ($status !== 'live') return ['ok' => true] + $summary;

    $next = bbb_next_delivery($feed, $deliveries);
    if ($next === null) return ['ok' => true] + $summary;

    $exists = one('SELECT "id" FROM "bbb_rounds" WHERE "match_key" = ? AND "innings" = ? AND "delivery_idx" = ?',
                  [$matchKey, $next['innings'], $next['idx']]);
    // The window runs from when the previous ball was BOWLED, not from when we got round to opening the
    // market. If that leaves too little time (the update arrived late), the market is not opened at all
    // rather than opened only to be voided when the next ball lands inside its window.
    $prevMs = null;
    foreach ($deliveries as $d) if ((int) $d['innings'] === $next['innings'] && empty($d['is_super_over'])) $prevMs = (int) $d['first_seen_ms'];
    $closesMs = $prevMs !== null ? $prevMs + ((int) $cfg['window_seconds']) * 1000 : $now + ((int) $cfg['window_seconds']) * 1000;
    if (!$exists && $closesMs - $now < 3000) {
        return ['ok' => true, 'skipped' => 'too late to open this ball'] + $summary;
    }
    if (!$exists) {
        $opened = affected(
            'INSERT INTO "bbb_rounds" ("match_key","innings","delivery_idx","over_no","ball_no","label","status",'
            . '"rake_pct","opened_at","closes_at") VALUES (?,?,?,?,?,?,?,?,?,?) '
            . 'ON CONFLICT ("match_key","innings","delivery_idx") DO NOTHING',
            [$matchKey, $next['innings'], $next['idx'], $next['over_no'], $next['ball_no'], bbb_label($next, $feed),
             'OPEN', (float) $cfg['rake_pct'], ms_to_sql($now), ms_to_sql($closesMs)]
        );
        $summary['opened'] += $opened;
    }
    return ['ok' => true] + $summary;
}

/** Settle one market on its outcome. Idempotent: a round already settled or voided is left alone. */
function bbb_settle_round($roundId, $outcome, array $delivery) {
    return tx(function () use ($roundId, $outcome, $delivery) {
        $r = one('SELECT * FROM "bbb_rounds" WHERE "id" = ? FOR UPDATE', [(int) $roundId]);
        if (!$r) return ['ok' => false, 'error' => 'round not found'];
        if (!in_array($r['status'], ['OPEN', 'SUSPENDED'], true)) return ['ok' => true, 'already' => true];

        $bets = all('SELECT "id","user_id","username","outcome","stake","created_at" FROM "bbb_bets" WHERE "round_id" = ? AND "status" = ?',
                    [(int) $roundId, 'PENDING']);
        $alloc = bbb_allocate($bets, $outcome, (float) $r['rake_pct']);
        $labels = bbb_outcomes();
        $resultText = $labels[$outcome] ?? $outcome;
        if (!empty($delivery['commentary'])) $resultText .= ' — ' . $delivery['commentary'];
        $nowSql = ms_to_sql(now_ms());

        foreach ($bets as $b) {
            $pay = $alloc['payouts'][$b['id']] ?? 0.0;
            if ($alloc['mode'] === 'refund_no_winner') {
                credit_wallet((int) $b['user_id'], $pay);
                insert_transaction(new_record_id('BBBR'), (string) $b['username'], 'Deposit', $pay,
                    'Ball by Ball Refund — ' . $r['label'] . ' (no one picked ' . ($labels[$outcome] ?? $outcome) . ')', 'Completed');
                q('UPDATE "bbb_bets" SET "status" = ?, "payout" = ?, "settled_at" = ? WHERE "id" = ?', ['REFUNDED', $pay, $nowSql, $b['id']]);
            } elseif ($pay > 0) {
                credit_wallet((int) $b['user_id'], $pay);
                insert_transaction(new_record_id('BBBW'), (string) $b['username'], 'Deposit', $pay,
                    'Ball by Ball Win — ' . $r['label'] . ' · ' . ($labels[$outcome] ?? $outcome), 'Completed');
                q('UPDATE "bbb_bets" SET "status" = ?, "payout" = ?, "settled_at" = ? WHERE "id" = ?', ['WON', $pay, $nowSql, $b['id']]);
            } else {
                q('UPDATE "bbb_bets" SET "status" = ?, "payout" = 0, "settled_at" = ? WHERE "id" = ?', ['LOST', $nowSql, $b['id']]);
            }
        }

        q('UPDATE "bbb_rounds" SET "status" = ?, "outcome" = ?, "result_text" = ?, "result_ball_uid" = ?, '
          . '"pool_total" = ?, "paid_total" = ?, "rake_taken" = ?, "settled_at" = ? WHERE "id" = ?',
          ['SETTLED', $outcome, $resultText, $delivery['uid'] ?? null, $alloc['pool'], $alloc['paid'], $alloc['rake'], $nowSql, (int) $roundId]);
        return ['ok' => true, 'outcome' => $outcome, 'mode' => $alloc['mode'], 'paid' => $alloc['paid'], 'rake' => $alloc['rake']];
    });
}

/** Void a market: every stake back in full. Idempotent. */
function bbb_void_round($roundId, $reason) {
    return tx(function () use ($roundId, $reason) {
        $r = one('SELECT * FROM "bbb_rounds" WHERE "id" = ? FOR UPDATE', [(int) $roundId]);
        if (!$r) return ['ok' => false, 'error' => 'round not found'];
        if (!in_array($r['status'], ['OPEN', 'SUSPENDED'], true)) return ['ok' => false, 'already' => true];
        $nowSql = ms_to_sql(now_ms());
        $bets = all('SELECT * FROM "bbb_bets" WHERE "round_id" = ? AND "status" = ?', [(int) $roundId, 'PENDING']);
        $refunded = 0.0;
        foreach ($bets as $b) {
            $amt = round((float) $b['stake'], 2);
            credit_wallet((int) $b['user_id'], $amt);
            insert_transaction(new_record_id('BBBR'), (string) $b['username'], 'Deposit', $amt,
                'Ball by Ball Refund — ' . $r['label'] . ' (market void: ' . bbb_void_reason_text($reason) . ')', 'Completed');
            q('UPDATE "bbb_bets" SET "status" = ?, "payout" = ?, "settled_at" = ? WHERE "id" = ?', ['REFUNDED', $amt, $nowSql, $b['id']]);
            $refunded += $amt;
        }
        q('UPDATE "bbb_rounds" SET "status" = ?, "void_reason" = ?, "paid_total" = ?, "settled_at" = ? WHERE "id" = ?',
          ['VOID', (string) $reason, round($refunded, 2), $nowSql, (int) $roundId]);
        return ['ok' => true, 'refunded' => round($refunded, 2), 'bets' => count($bets)];
    });
}

function bbb_void_reason_text($reason) {
    $map = [
        'ball_before_close'   => 'the ball was bowled before betting could be confirmed closed',
        'outcome_not_offered' => 'the ball produced a result this market does not list',
        'feed_stalled'        => 'live data was interrupted',
        'match_abandoned'     => 'the match was abandoned',
        'match_ended'         => 'the match ended',
        'operator_void'       => 'voided by the operator',
    ];
    return $map[$reason] ?? str_replace('_', ' ', (string) $reason);
}

// -------------------------------------------------------------------------------------------------
// Placing a bet
// -------------------------------------------------------------------------------------------------

/**
 * Stake on one outcome of one market.
 *
 * The round row is locked for the whole transaction, so the window check, the per-ball cap, the
 * debit, the ledger row, the bet row and the pool total all see one consistent state — a bet cannot
 * slip in after the window by racing the settlement, and two quick taps cannot overrun the cap.
 */
function bbb_place_bet(array $user, $roundId, $outcome, $stake) {
    $cfg = bbb_config();
    $labels = bbb_outcomes();
    $outcome = (string) $outcome;
    if (!isset($labels[$outcome])) return ['ok' => false, 'status' => 422, 'error' => 'Pick one of the listed outcomes.'];
    if (!is_numeric($stake)) return ['ok' => false, 'status' => 422, 'error' => 'Enter a valid stake.'];
    $amount = round((float) $stake, 2);
    if ($amount < (float) $cfg['min_stake']) return ['ok' => false, 'status' => 422, 'error' => 'Minimum stake is ₹' . js_num_str($cfg['min_stake']) . '.'];
    if ($amount > (float) $cfg['max_stake']) return ['ok' => false, 'status' => 422, 'error' => 'Maximum stake is ₹' . js_num_str($cfg['max_stake']) . '.'];

    try {
        return tx(function () use ($user, $roundId, $outcome, $amount, $cfg, $labels) {
            $r = one('SELECT * FROM "bbb_rounds" WHERE "id" = ? FOR UPDATE', [(int) $roundId]);
            if (!$r) throw new RuntimeException('404:This market no longer exists.');
            if ($r['status'] !== 'OPEN' || now_ms() >= sql_to_ms($r['closes_at'])) {
                throw new RuntimeException('409:Betting on this ball has closed.');
            }
            $feed = one('SELECT "status","stalled" FROM "cricket_match_feed" WHERE "match_key" = ?', [$r['match_key']]);
            if (!$feed || $feed['status'] !== 'live' || (int) $feed['stalled'] === 1) {
                throw new RuntimeException('409:Betting is suspended right now.');
            }
            $mine = (float) scalar('SELECT COALESCE(SUM("stake"),0) FROM "bbb_bets" WHERE "round_id" = ? AND "user_id" = ?',
                                   [(int) $roundId, (int) $user['id']], 0);
            if ($mine + $amount > (float) $cfg['max_user_stake_per_ball'] + 1e-9) {
                throw new RuntimeException('422:You can stake at most ₹' . js_num_str($cfg['max_user_stake_per_ball']) . ' on one ball.');
            }
            $balance = debit_wallet((int) $user['id'], $amount);
            if ($balance === null) throw new RuntimeException('402:Insufficient balance.');
            $txn = new_record_id('BBB');
            insert_transaction($txn, (string) $user['username'], 'Withdrawal', $amount,
                'Ball by Ball Bet — ' . $r['label'] . ' · ' . $labels[$outcome], 'Completed');
            q('INSERT INTO "bbb_bets" ("round_id","user_id","username","outcome","stake","txn_id","status","created_at") '
              . 'VALUES (?,?,?,?,?,?,?,?)',
              [(int) $roundId, (int) $user['id'], (string) $user['username'], $outcome, $amount, $txn, 'PENDING', ms_to_sql(now_ms())]);
            $betId = (int) db_or_throw()->lastInsertId();
            q('UPDATE "bbb_rounds" SET "pool_total" = "pool_total" + ? WHERE "id" = ?', [$amount, (int) $roundId]);
            return ['ok' => true, 'bet_id' => $betId, 'new_balance' => $balance, 'stake' => $amount, 'outcome' => $outcome];
        });
    } catch (RuntimeException $e) {
        $msg = $e->getMessage();
        if (preg_match('/^(\d{3}):(.*)$/s', $msg, $m)) return ['ok' => false, 'status' => (int) $m[1], 'error' => $m[2]];
        throw $e;
    }
}

// -------------------------------------------------------------------------------------------------
// Reads for the page
// -------------------------------------------------------------------------------------------------

/** The live book for one market: stake and multiplier per outcome. */
function bbb_round_book(array $round) {
    $rows = all('SELECT "outcome", SUM("stake") AS "s", COUNT(*) AS "n" FROM "bbb_bets" WHERE "round_id" = ? GROUP BY "outcome"', [(int) $round['id']]);
    $by = [];
    foreach ($rows as $r) $by[(string) $r['outcome']] = ['stake' => round((float) $r['s'], 2), 'bets' => (int) $r['n']];
    $pool = 0.0; foreach ($by as $v) $pool += $v['stake'];
    $net = $pool * (1 - ((float) $round['rake_pct']) / 100);
    $out = [];
    foreach (bbb_outcomes() as $k => $label) {
        $stake = $by[$k]['stake'] ?? 0.0;
        $mult = $stake > 0 ? max(1.0, $net / $stake) : null;
        $out[] = ['key' => $k, 'label' => $label, 'stake' => $stake, 'bets' => $by[$k]['bets'] ?? 0,
                  'share_pct' => $pool > 0 ? round($stake * 100 / $pool, 1) : 0,
                  'multiplier' => $mult !== null ? round($mult, 2) : null];
    }
    return ['pool' => round($pool, 2), 'outcomes' => $out];
}

function bbb_round_public(array $r, $nowMs) {
    $closes = sql_to_ms($r['closes_at']);
    $status = $r['status'];
    if ($status === 'OPEN' && $nowMs >= $closes) $status = 'SUSPENDED';
    return [
        'id' => (int) $r['id'], 'innings' => (int) $r['innings'], 'delivery_idx' => (int) $r['delivery_idx'],
        'over_no' => (int) $r['over_no'], 'ball_no' => (int) $r['ball_no'], 'label' => $r['label'],
        'status' => $status, 'rake_pct' => (float) $r['rake_pct'],
        'opened_ms' => sql_to_ms($r['opened_at']), 'closes_ms' => $closes,
        'seconds_left' => $status === 'OPEN' ? max(0, (int) ceil(($closes - $nowMs) / 1000)) : 0,
        'outcome' => $r['outcome'], 'result_text' => $r['result_text'],
        'pool_total' => (float) $r['pool_total'], 'paid_total' => (float) $r['paid_total'],
        'void_reason' => $r['void_reason'] ? bbb_void_reason_text($r['void_reason']) : null,
    ];
}

/** Everything the Ball by Ball page needs in one poll. */
function bbb_match_state($matchKey, $userId = null, $nowMs = null) {
    $now = $nowMs ?? now_ms();
    $feed = cricket_match_feed_row($matchKey);
    if (!$feed) return null;
    $deliveries = cricket_match_deliveries($matchKey);
    $board = cricket_derive_scoreboard($feed, $deliveries);

    $current = one('SELECT * FROM "bbb_rounds" WHERE "match_key" = ? AND "status" IN (?, ?) ORDER BY "id" DESC LIMIT 1',
                   [$matchKey, 'OPEN', 'SUSPENDED']);
    $last = one('SELECT * FROM "bbb_rounds" WHERE "match_key" = ? AND "status" IN (?, ?) ORDER BY "id" DESC LIMIT 1',
                [$matchKey, 'SETTLED', 'VOID']);
    $recent = all('SELECT * FROM "bbb_rounds" WHERE "match_key" = ? AND "status" IN (?, ?) ORDER BY "id" DESC LIMIT 18',
                  [$matchKey, 'SETTLED', 'VOID']);

    $myCurrent = [];
    $myLast = [];
    if ($userId) {
        if ($current) $myCurrent = all('SELECT "id","outcome","stake","status","payout" FROM "bbb_bets" WHERE "round_id" = ? AND "user_id" = ? ORDER BY "id"', [(int) $current['id'], (int) $userId]);
        if ($last)    $myLast    = all('SELECT "id","outcome","stake","status","payout" FROM "bbb_bets" WHERE "round_id" = ? AND "user_id" = ? ORDER BY "id"', [(int) $last['id'], (int) $userId]);
    }
    $fmtBets = function ($rows) {
        return array_map(function ($b) {
            return ['id' => (int) $b['id'], 'outcome' => (string) $b['outcome'], 'stake' => (float) $b['stake'],
                    'status' => $b['status'], 'payout' => (float) $b['payout']];
        }, $rows);
    };

    $cfg = bbb_config();
    return [
        'match'      => cricket_match_summary($feed, $board),
        'scoreboard' => $board,
        'round'      => $current ? bbb_round_public($current, $now) + ['book' => bbb_round_book($current)] : null,
        'last_round' => $last ? bbb_round_public($last, $now) : null,
        'recent'     => array_map(function ($r) use ($now) { return bbb_round_public($r, $now); }, $recent),
        'my_bets'    => $fmtBets($myCurrent),
        'my_last_bets' => $fmtBets($myLast),
        'outcomes'   => array_map(function ($k, $v) { return ['key' => (string) $k, 'label' => $v]; }, array_keys(bbb_outcomes()), bbb_outcomes()),
        'limits'     => ['min_stake' => (float) $cfg['min_stake'], 'max_stake' => (float) $cfg['max_stake'],
                         'max_user_stake_per_ball' => (float) $cfg['max_user_stake_per_ball'],
                         'rake_pct' => (float) $cfg['rake_pct'], 'window_seconds' => (int) $cfg['window_seconds']],
        'server_time_ms' => $now,
    ];
}

/** Matches for the Ball by Ball lobby: live first, then upcoming, then just finished. */
function bbb_list_matches($nowMs = null) {
    $now = $nowMs ?? now_ms();
    $rows = all('SELECT * FROM "cricket_match_feed" WHERE "start_time" IS NULL OR "start_time" > ? ORDER BY "start_time" ASC',
                [ms_to_sql($now - 12 * 3600000)]);
    $live = []; $upcoming = []; $done = [];
    foreach ($rows as $row) {
        foreach (['toss', 'lineups', 'players', 'meta'] as $c) $row[$c] = json_decode((string) $row[$c], true) ?: [];
        $board = in_array($row['status'], ['live', 'innings_break', 'completed', 'abandoned'], true)
            ? cricket_derive_scoreboard($row, cricket_match_deliveries($row['match_key'])) : null;
        $s = cricket_match_summary($row, $board);
        if (in_array($row['status'], ['live', 'innings_break'], true)) $live[] = $s;
        elseif ($row['status'] === 'not_started') $upcoming[] = $s;
        else $done[] = $s;
    }
    // Healthy live matches first; a match whose feed has stalled stays listed (with its stalled flag)
    // but never above one that is actually moving.
    usort($live, function ($a, $b) { return (int) !empty($a['stalled']) <=> (int) !empty($b['stalled']); });
    // Upcoming fixtures the feed has not pushed yet still belong in the lobby.
    if (function_exists('fantasy_list_matches')) {
        $known = [];
        foreach (array_merge($live, $upcoming, $done) as $m) $known[$m['match_key']] = true;
        try {
            foreach (all('SELECT * FROM "fantasy_matches" WHERE "status" = ? AND "start_time" > ? AND "feed_key" IS NOT NULL ORDER BY "start_time" ASC LIMIT 8',
                         ['UPCOMING', ms_to_sql($now)]) as $fm) {
                $fk = $fm['feed_key'] ?: preg_replace('/^(rz|mock):/', '', (string) $fm['external_key']);
                if (isset($known[$fk])) continue;
                $upcoming[] = ['match_key' => $fk, 'title' => $fm['match_title'], 'format' => $fm['format'],
                               'team_a' => $fm['team_a'], 'team_a_short' => $fm['team_a_short'],
                               'team_b' => $fm['team_b'], 'team_b_short' => $fm['team_b_short'],
                               'start_time_ms' => sql_to_ms($fm['start_time']), 'status' => 'not_started',
                               'toss_text' => null, 'lineups_out' => false, 'result_text' => null, 'score' => [],
                               'stalled' => false, 'last_feed_ms' => null];
            }
        } catch (Throwable $e) { /* the lobby still works from the feed alone */ }
    }
    usort($upcoming, function ($a, $b) { return ($a['start_time_ms'] ?? 0) <=> ($b['start_time_ms'] ?? 0); });
    return ['live' => $live, 'upcoming' => array_slice($upcoming, 0, 8), 'completed' => array_slice(array_reverse($done), 0, 6)];
}

/** The caller's recent bets across every match. */
function bbb_my_bets($userId, $limit = 50) {
    $rows = all('SELECT b.*, r."label", r."match_key", r."outcome" AS "result", r."status" AS "round_status", r."void_reason", '
              . 'f."title" FROM "bbb_bets" b JOIN "bbb_rounds" r ON r."id" = b."round_id" '
              . 'LEFT JOIN "cricket_match_feed" f ON f."match_key" = r."match_key" '
              . 'WHERE b."user_id" = ? ORDER BY b."id" DESC LIMIT ' . max(1, min(200, (int) $limit)), [(int) $userId]);
    $labels = bbb_outcomes();
    return array_map(function ($b) use ($labels) {
        return [
            'id' => (int) $b['id'], 'match_key' => $b['match_key'], 'match_title' => $b['title'], 'label' => $b['label'],
            'outcome' => (string) $b['outcome'], 'outcome_label' => $labels[$b['outcome']] ?? $b['outcome'],
            'result' => $b['result'], 'result_label' => $b['result'] ? ($labels[$b['result']] ?? $b['result']) : null,
            'stake' => (float) $b['stake'], 'payout' => (float) $b['payout'], 'status' => $b['status'],
            'void_reason' => $b['void_reason'] ? bbb_void_reason_text($b['void_reason']) : null,
            'created_ms' => sql_to_ms($b['created_at']),
        ];
    }, $rows);
}
