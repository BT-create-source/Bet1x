<?php
/**
 * Coinflip with a streak. The bet places the first flip; every correct call doubles the multiplier:
 *     multiplier after s correct calls = RTP x 2^s   (1.98x, 3.96x, 7.92x ... at 99%)
 * After any win the player cashes out or calls the next flip; a wrong call loses the stake. The edge
 * is taken once, so the expected return is exactly the RTP whenever the player stops. Ten in a row is
 * the most (about 1013x at 99%) and cashes out on its own.
 */

const ORIG_COINFLIP_MAX_STREAK = 10;

function orig_coinflip_mult($streak, $rtp) {
    return $streak <= 0 ? 0.0 : floor($rtp * pow(2, $streak) * 100 + 1e-9) / 100;
}

function orig_coinflip_side($v) {
    $v = strtolower((string) $v);
    if ($v !== 'heads' && $v !== 'tails') orig_fail('Choose heads or tails.');
    return $v;
}

function orig_coinflip_prepare(array $in, array $cfg) {
    return ['stake' => orig_bet_amount($in), 'params' => ['side' => orig_coinflip_side($in['side'] ?? null)]];
}

function orig_coinflip_flip(OrigRng $rng) { return $rng->float() < 0.5 ? 'heads' : 'tails'; }

/** The public view, and the round's outcome after this flip. */
function orig_coinflip_after($stake, array $flips, array $cfg) {
    $last = end($flips);
    $streak = 0;
    foreach ($flips as $f) if ($f['win']) $streak++;
    $mult = orig_coinflip_mult($streak, $cfg['rtp']);
    $result = ['flips' => $flips, 'streak' => $streak, 'multiplier' => $last['win'] ? $mult : 0,
               'next_multiplier' => orig_coinflip_mult($streak + 1, $cfg['rtp']), 'max_streak' => ORIG_COINFLIP_MAX_STREAK];
    if (!$last['win']) return ['status' => 'settled', 'payout' => 0, 'result' => $result + ['ended' => 'lost'], 'state' => ['flips' => $flips]];
    if ($streak >= ORIG_COINFLIP_MAX_STREAK) {
        return ['status' => 'settled', 'payout' => $stake * $mult, 'result' => $result + ['ended' => 'max_streak'], 'state' => ['flips' => $flips]];
    }
    return ['status' => 'open', 'result' => $result, 'state' => ['flips' => $flips]];
}

function orig_coinflip_play($stake, array $p, OrigRng $rng, array $cfg) {
    $side = orig_coinflip_flip($rng);
    return orig_coinflip_after($stake, [['call' => $p['side'], 'side' => $side, 'win' => $side === $p['side']]], $cfg);
}

function orig_coinflip_action(array $round, $action, array $in, OrigRng $rng, array $cfg) {
    $flips = $round['state']['flips'] ?? [];
    $streak = 0;
    foreach ($flips as $f) if ($f['win']) $streak++;
    if ($action === 'cashout') {
        if ($streak < 1) orig_fail('Nothing to cash out yet.');
        $mult = orig_coinflip_mult($streak, $cfg['rtp']);
        return ['status' => 'settled', 'payout' => $round['bet'] * $mult,
                'result' => ['flips' => $flips, 'streak' => $streak, 'multiplier' => $mult, 'ended' => 'cashout',
                             'next_multiplier' => null, 'max_streak' => ORIG_COINFLIP_MAX_STREAK],
                'state' => ['flips' => $flips]];
    }
    if ($action === 'flip') {
        $call = orig_coinflip_side($in['side'] ?? null);
        $side = orig_coinflip_flip($rng);
        $flips[] = ['call' => $call, 'side' => $side, 'win' => $side === $call];
        return orig_coinflip_after($round['bet'], $flips, $cfg);
    }
    orig_fail('Unknown action.');
}

function orig_coinflip_info(array $cfg) {
    $m = [];
    for ($s = 1; $s <= ORIG_COINFLIP_MAX_STREAK; $s++) $m[] = orig_coinflip_mult($s, $cfg['rtp']);
    return ['rtp' => $cfg['rtp'], 'ladder' => $m];
}
