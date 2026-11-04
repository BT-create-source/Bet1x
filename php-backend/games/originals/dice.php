<?php
/**
 * Dice. A roll from 0.00 to 99.99 (10,000 equally likely values). The player picks a win chance
 * (2% to 98%) and Roll Under or Roll Over:
 *   under: wins when roll <  chance          (exactly chance% of the values)
 *   over:  wins when roll >= 100 - chance    (exactly chance% of the values)
 * Multiplier = RTP x 100 / chance, floored to 4 decimals, so the expected return is the RTP for
 * every choice of chance.
 */

function orig_dice_multiplier($chance, $rtp) {
    return floor($rtp * 100 / $chance * 10000 + 1e-9) / 10000;
}

function orig_dice_prepare(array $in, array $cfg) {
    $bet = orig_bet_amount($in);
    $chance = js_parse_float($in['chance'] ?? null);
    if (!js_is_finite($chance)) orig_fail('Choose a win chance.');
    $chance = round($chance, 2);
    if ($chance < 2 || $chance > 98) orig_fail('Win chance must be between 2% and 98%.');
    $dir = strtolower((string) ($in['direction'] ?? 'under'));
    if ($dir !== 'under' && $dir !== 'over') orig_fail('Choose Roll Under or Roll Over.');
    return ['stake' => $bet, 'params' => ['chance' => $chance, 'direction' => $dir]];
}

function orig_dice_play($stake, array $p, OrigRng $rng, array $cfg) {
    $roll = floor($rng->float() * 10000) / 100;                 // 0.00 .. 99.99
    $chance = (float) $p['chance'];
    $win = $p['direction'] === 'under' ? ($roll < $chance - 1e-9) : ($roll >= 100 - $chance - 1e-9);
    $mult = orig_dice_multiplier($chance, $cfg['rtp']);
    return [
        'status' => 'settled',
        'payout' => $win ? $stake * $mult : 0,
        'result' => ['roll' => $roll, 'win' => $win, 'multiplier' => $mult,
                     'target' => $p['direction'] === 'under' ? $chance : round(100 - $chance, 2)],
    ];
}

function orig_dice_info(array $cfg) {
    return ['rtp' => $cfg['rtp'], 'min_chance' => 2, 'max_chance' => 98];
}
