<?php
/**
 * Two independently drawn cards, as in the reference game's rank/suit model. A=1, K=13.
 * Total returns: Dragon/Tiger 2x, Tie 12x; a tied main bet returns half its stake.
 * Fixed RTP before monetary flooring/caps: main bets 25/26, Tie 12/13.
 * Own implementation; no upstream Python code or artwork is distributed.
 */
const ORIG_DT_PAYS = ['dragon' => 2.0, 'tiger' => 2.0, 'tie' => 12.0];

function orig_dragontiger_prepare(array $in, array $cfg) {
    [$chips, $sum] = orig_read_chips($in['bets'] ?? null, 60);
    $merged = [];
    foreach ($chips as $chip) {
        if (!isset(ORIG_DT_PAYS[$chip['type']])) orig_fail('Choose Dragon, Tiger or Tie.');
        $type = $chip['type'];
        $merged[$type] = orig_money(($merged[$type] ?? 0) + $chip['amount']);
    }
    $bets = [];
    foreach ($merged as $type => $amount) $bets[] = ['type' => $type, 'value' => '', 'amount' => $amount];
    return ['stake' => $sum, 'params' => ['bets' => $bets]];
}

/** Pure settlement, shared with exhaustive rank tests. Cards use the common orig_card format. */
function orig_dragontiger_settle(array $bets, array $dragon, array $tiger) {
    $winner = $dragon['r'] > $tiger['r'] ? 'dragon' : ($dragon['r'] < $tiger['r'] ? 'tiger' : 'tie');
    $payout = 0.0;
    foreach ($bets as $bet) {
        if ($bet['type'] === $winner) $payout += $bet['amount'] * ORIG_DT_PAYS[$winner];
        elseif ($winner === 'tie' && $bet['type'] !== 'tie') $payout += $bet['amount'] * 0.5;
    }
    return ['status' => 'settled', 'payout' => $payout, 'result' => [
        'dragon' => $dragon['c'], 'tiger' => $tiger['c'],
        'dragon_rank' => $dragon['r'], 'tiger_rank' => $tiger['r'], 'winner' => $winner,
    ]];
}

function orig_dragontiger_play($stake, array $params, OrigRng $rng, array $cfg) {
    return orig_dragontiger_settle($params['bets'], orig_card($rng->int(52)), orig_card($rng->int(52)));
}

function orig_dragontiger_info(array $cfg) {
    return ['pays' => ORIG_DT_PAYS, 'tie_main_return' => 0.5,
            'rtp_by_bet' => ['dragon' => 25 / 26, 'tiger' => 25 / 26, 'tie' => 12 / 13]];
}
