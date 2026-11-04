<?php
/**
 * Andar Bahar with one 52-card pack, shuffled for every round. The first card is the Joker; the rest
 * are dealt one at a time, Andar first, then Bahar, alternately, until a card of the Joker's rank
 * appears — that side wins. Three of the remaining 51 cards match, so the chance that the first match
 * is the i-th card dealt is exact:
 *     P(i) = [C(48, i-1) / C(51, i-1)] x 3 / (52 - i)
 * Andar wins on odd i, Bahar on even i. Every payout is RTP / probability, floored to the paisa: the
 * main bets (about 1.88x Andar / 2.06x Bahar at 97%) and the "how many cards are dealt" side bets.
 */

function orig_ab_position_probs() {
    static $p = null;
    if ($p !== null) return $p;
    $p = [];
    for ($i = 1; $i <= 49; $i++) $p[$i] = orig_choose(48, $i - 1) / orig_choose(51, $i - 1) * 3 / (52 - $i);
    return $p;
}

function orig_ab_buckets() {
    return ['1-5' => [1, 5], '6-10' => [6, 10], '11-15' => [11, 15], '16-25' => [16, 25],
            '26-30' => [26, 30], '31-35' => [31, 35], '36-40' => [36, 40], '41-49' => [41, 49]];
}

/** Every bet's probability and payout at this RTP. */
function orig_ab_pays($rtp) {
    $p = orig_ab_position_probs();
    $andar = 0.0; $bahar = 0.0;
    foreach ($p as $i => $pi) { if ($i % 2 === 1) $andar += $pi; else $bahar += $pi; }
    $pay = function ($prob) use ($rtp) { return floor($rtp / $prob * 100 + 1e-9) / 100; };
    $out = ['andar' => ['prob' => $andar, 'pays' => $pay($andar)], 'bahar' => ['prob' => $bahar, 'pays' => $pay($bahar)], 'cards' => []];
    foreach (orig_ab_buckets() as $k => [$lo, $hi]) {
        $s = 0.0;
        for ($i = $lo; $i <= $hi; $i++) $s += $p[$i];
        $out['cards'][$k] = ['prob' => $s, 'pays' => $pay($s)];
    }
    return $out;
}

function orig_andarbahar_prepare(array $in, array $cfg) {
    [$chips, $sum] = orig_read_chips($in['bets'] ?? null, 12);
    $buckets = orig_ab_buckets();
    foreach ($chips as &$c) {
        if ($c['type'] === 'andar' || $c['type'] === 'bahar') { $c['value'] = ''; continue; }
        if ($c['type'] === 'cards' && isset($buckets[$c['value']])) continue;
        orig_fail('Unknown bet.');
    }
    unset($c);
    return ['stake' => $sum, 'params' => ['bets' => $chips]];
}

function orig_andarbahar_play($stake, array $p, OrigRng $rng, array $cfg) {
    $deck = $rng->shuffle(range(0, 51));
    $joker = orig_card($deck[0]);
    $andar = []; $bahar = []; $pos = 0; $winner = null;
    for ($k = 1; $k < 52; $k++) {
        $c = orig_card($deck[$k]);
        $pos++;
        if ($pos % 2 === 1) $andar[] = $c['c']; else $bahar[] = $c['c'];
        if ($c['r'] === $joker['r']) { $winner = $pos % 2 === 1 ? 'andar' : 'bahar'; break; }
    }
    $pays = orig_ab_pays($cfg['rtp']);
    $payout = 0.0;
    foreach ($p['bets'] as $b) {
        if ($b['type'] === $winner) $payout += $b['amount'] * $pays[$winner]['pays'];
        if ($b['type'] === 'cards') {
            [$lo, $hi] = orig_ab_buckets()[$b['value']];
            if ($pos >= $lo && $pos <= $hi) $payout += $b['amount'] * $pays['cards'][$b['value']]['pays'];
        }
    }
    return ['status' => 'settled', 'payout' => $payout, 'result' => [
        'joker' => $joker['c'], 'andar' => $andar, 'bahar' => $bahar, 'winner' => $winner, 'cards_dealt' => $pos,
    ]];
}

function orig_andarbahar_info(array $cfg) {
    return ['rtp' => $cfg['rtp'], 'pays' => orig_ab_pays($cfg['rtp'])];
}
