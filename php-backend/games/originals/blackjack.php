<?php
/**
 * Blackjack against the dealer. Every card is drawn independently from a full 52-card pack (an
 * "infinite shoe"), in a fixed order from the round's seed, so the round can be re-derived afterwards.
 * Rules: dealer stands on all 17s; blackjack pays 3:2; double on any first two cards (also after a
 * split); one split of two equal ranks (split aces take one card each, and 21 there is not a
 * blackjack); insurance (2:1) is offered when the dealer shows an Ace; the dealer checks for blackjack
 * under an Ace or a ten before the player acts. About 99.5% return with good play. Fixed by the rules.
 *
 * The dealer's hole card stays in the server-side state and is shown only when the round ends.
 */

function orig_bj_draw(OrigRng $rng) { return orig_card($rng->int(52))['c']; }

function orig_bj_rank($code) { return substr($code, 0, -1); }

function orig_bj_value($code) {
    $r = orig_bj_rank($code);
    if ($r === 'A') return 1;
    if (in_array($r, ['10', 'J', 'Q', 'K'], true)) return 10;
    return (int) $r;
}

/** [total, soft] — soft when an ace is being counted as 11. */
function orig_bj_total(array $cards) {
    $t = 0; $aces = 0;
    foreach ($cards as $c) { $v = orig_bj_value($c); $t += $v; if ($v === 1) $aces++; }
    if ($aces && $t + 10 <= 21) return [$t + 10, true];
    return [$t, false];
}

function orig_bj_is_blackjack(array $cards, $fromSplit = false) {
    return !$fromSplit && count($cards) === 2 && orig_bj_total($cards)[0] === 21;
}

function orig_blackjack_prepare(array $in, array $cfg) {
    return ['stake' => orig_bet_amount($in), 'params' => []];
}

/** What the player may do now. */
function orig_bj_actions(array $s) {
    if ($s['phase'] === 'insurance') return ['insurance', 'no_insurance'];
    if ($s['phase'] !== 'player') return [];
    $h = $s['hands'][$s['active']];
    $a = ['hit', 'stand'];
    if (count($h['cards']) === 2 && empty($h['aces_split'])) $a[] = 'double';
    if (count($s['hands']) === 1 && count($h['cards']) === 2 && orig_bj_rank($h['cards'][0]) === orig_bj_rank($h['cards'][1])) $a[] = 'split';
    return $a;
}

/** The public view: the hole card stays hidden until the round is over. */
function orig_bj_public(array $s) {
    $done = $s['phase'] === 'done';
    $dealer = $done ? $s['dealer'] : [$s['dealer'][0], '??'];
    $hands = array_map(function ($h) {
        [$t, $soft] = orig_bj_total($h['cards']);
        return ['cards' => $h['cards'], 'total' => $t, 'soft' => $soft, 'bet' => $h['bet'], 'doubled' => !empty($h['doubled']),
                'done' => !empty($h['done']), 'outcome' => $h['outcome'] ?? null, 'payout' => $h['payout'] ?? null];
    }, $s['hands']);
    return [
        'phase' => $s['phase'], 'active' => $s['active'], 'hands' => $hands, 'dealer' => $dealer,
        'dealer_total' => $done ? orig_bj_total($s['dealer'])[0] : orig_bj_total([$s['dealer'][0]])[0],
        'insurance' => $s['insurance'], 'insurance_payout' => $s['insurance_payout'] ?? null,
        'actions' => orig_bj_actions($s),
    ];
}

/** Check the hole card for blackjack (dealer showing an Ace or a ten) and settle if needed. */
function orig_bj_after_peek(array $s, OrigRng $rng) {
    $dealerBj = orig_bj_is_blackjack($s['dealer']);
    $playerBj = orig_bj_is_blackjack($s['hands'][0]['cards']);
    $up = orig_bj_value($s['dealer'][0]);
    $peeks = $up === 1 || $up === 10;
    if ($s['insurance'] !== null) $s['insurance_payout'] = ($dealerBj ? $s['insurance'] * 3 : 0);
    if ($peeks && $dealerBj) return orig_bj_settle($s, $rng, false);
    if ($playerBj) return orig_bj_settle($s, $rng, false);
    $s['phase'] = 'player';
    return $s;
}

/** Move to the next unfinished hand, or let the dealer play and settle. */
function orig_bj_advance(array $s, OrigRng $rng) {
    foreach ($s['hands'] as $i => $h) {
        if (empty($h['done'])) { $s['active'] = $i; $s['phase'] = 'player'; return $s; }
    }
    return orig_bj_settle($s, $rng, true);
}

function orig_bj_settle(array $s, OrigRng $rng, $dealerPlays) {
    $anyLive = false;
    foreach ($s['hands'] as $h) if (orig_bj_total($h['cards'])[0] <= 21) $anyLive = true;
    $single = count($s['hands']) === 1;
    if ($dealerPlays && $anyLive && !($single && orig_bj_is_blackjack($s['hands'][0]['cards']))) {
        while (orig_bj_total($s['dealer'])[0] < 17) $s['dealer'][] = orig_bj_draw($rng);
    }
    $dTotal = orig_bj_total($s['dealer'])[0];
    $dBj = orig_bj_is_blackjack($s['dealer']);
    foreach ($s['hands'] as $i => $h) {
        $t = orig_bj_total($h['cards'])[0];
        $pBj = orig_bj_is_blackjack($h['cards'], !empty($h['from_split']));
        if ($t > 21) { $o = 'bust'; $m = 0; }
        elseif ($pBj && !$dBj) { $o = 'blackjack'; $m = 2.5; }
        elseif ($dBj && !$pBj) { $o = 'lose'; $m = 0; }
        elseif ($pBj && $dBj) { $o = 'push'; $m = 1; }
        elseif ($dTotal > 21 || $t > $dTotal) { $o = 'win'; $m = 2; }
        elseif ($t === $dTotal) { $o = 'push'; $m = 1; }
        else { $o = 'lose'; $m = 0; }
        $s['hands'][$i]['outcome'] = $o;
        $s['hands'][$i]['payout'] = orig_money($h['bet'] * $m);
        $s['hands'][$i]['done'] = true;
    }
    $s['phase'] = 'done';
    return $s;
}

function orig_bj_payout(array $s) {
    $p = (float) ($s['insurance_payout'] ?? 0);
    foreach ($s['hands'] as $h) $p += (float) ($h['payout'] ?? 0);
    return $p;
}

function orig_bj_wrap(array $s, $extra = 0) {
    $done = $s['phase'] === 'done';
    return ['status' => $done ? 'settled' : 'open', 'payout' => $done ? orig_bj_payout($s) : 0,
            'extra_stake' => $extra, 'state' => $s, 'result' => orig_bj_public($s)];
}

function orig_blackjack_play($stake, array $p, OrigRng $rng, array $cfg) {
    $p1 = orig_bj_draw($rng); $d1 = orig_bj_draw($rng); $p2 = orig_bj_draw($rng); $d2 = orig_bj_draw($rng);
    $s = ['hands' => [['cards' => [$p1, $p2], 'bet' => $stake, 'done' => false]], 'active' => 0,
          'dealer' => [$d1, $d2], 'phase' => 'player', 'insurance' => null];
    if (orig_bj_value($d1) === 1) {
        $s['phase'] = 'insurance';
        return orig_bj_wrap($s);
    }
    return orig_bj_wrap(orig_bj_after_peek($s, $rng));
}

function orig_blackjack_action(array $round, $action, array $in, OrigRng $rng, array $cfg) {
    $s = $round['state'];
    if (!in_array($action, orig_bj_actions($s), true)) orig_fail('You cannot ' . str_replace('_', ' ', $action) . ' now.', 409, 'invalid_action');
    $extra = 0.0;
    if ($action === 'insurance' || $action === 'no_insurance') {
        if ($action === 'insurance') { $extra = orig_money($s['hands'][0]['bet'] / 2); $s['insurance'] = $extra; }
        return orig_bj_wrap(orig_bj_after_peek($s, $rng), $extra);
    }
    $i = $s['active'];
    $h =& $s['hands'][$i];
    if ($action === 'hit') {
        $h['cards'][] = orig_bj_draw($rng);
        if (orig_bj_total($h['cards'])[0] >= 21) $h['done'] = true;
    } elseif ($action === 'stand') {
        $h['done'] = true;
    } elseif ($action === 'double') {
        $extra = $h['bet'];
        $h['bet'] = orig_money($h['bet'] * 2);
        $h['doubled'] = true;
        $h['cards'][] = orig_bj_draw($rng);
        $h['done'] = true;
    } elseif ($action === 'split') {
        $extra = $h['bet'];
        $aces = orig_bj_rank($h['cards'][0]) === 'A';
        $a = ['cards' => [$h['cards'][0], orig_bj_draw($rng)], 'bet' => $h['bet'], 'from_split' => true, 'aces_split' => $aces, 'done' => $aces];
        $b = ['cards' => [$h['cards'][1], orig_bj_draw($rng)], 'bet' => $h['bet'], 'from_split' => true, 'aces_split' => $aces, 'done' => $aces];
        if (!$aces && orig_bj_total($a['cards'])[0] === 21) $a['done'] = true;
        if (!$aces && orig_bj_total($b['cards'])[0] === 21) $b['done'] = true;
        unset($h);
        $s['hands'] = [$a, $b];
        $s['active'] = 0;
    }
    unset($h);
    return orig_bj_wrap(orig_bj_advance($s, $rng), $extra);
}

function orig_blackjack_info(array $cfg) {
    return ['rtp' => 0.995, 'rules' => ['dealer_stands' => 'all 17s', 'blackjack_pays' => '3:2', 'insurance' => '2:1', 'splits' => 1]];
}
