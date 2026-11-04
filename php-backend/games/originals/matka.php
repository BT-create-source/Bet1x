<?php
/**
 * Matka, drawn instantly. Each draw is an OPEN panna and a CLOSE panna, three random digits each,
 * written in the traditional order (ascending, with 0 counted as 10, so 0 comes last: 1,2,0 -> "120").
 * The ank of a panna is the last digit of its sum; the jodi is the open ank followed by the close ank.
 *
 * Bets and their exact chances (digits independent and uniform):
 *   single  (open ank 0-9)        1/10
 *   jodi    (00-99)               1/100
 *   panna   (an exact open panna) 6/1000 all different, 3/1000 one pair, 1/1000 triple
 *   odd/even of the open ank      1/2
 * Every payout is RTP / chance (9.5x, 95x, 158.33x / 316.66x / 950x, 1.9x at 95%).
 */

function orig_matka_markets() { return ['Kalyan', 'Worli Mumbai', 'Milan Night', 'Rajdhani Night', 'Main Mumbai']; }

function orig_matka_sort(array $d) {
    usort($d, function ($a, $b) { return ($a === 0 ? 10 : $a) - ($b === 0 ? 10 : $b); });
    return $d;
}

function orig_matka_panna_prob($panna) {
    $u = count(array_unique(str_split($panna)));
    return $u === 1 ? 0.001 : ($u === 2 ? 0.003 : 0.006);
}

function orig_matka_pay($prob, $rtp) { return floor($rtp / $prob * 100 + 1e-9) / 100; }

function orig_matka_prepare(array $in, array $cfg) {
    [$chips, $sum] = orig_read_chips($in['bets'] ?? null, 40);
    foreach ($chips as &$c) {
        $v = trim($c['value']);
        switch ($c['type']) {
            case 'single':  if (!preg_match('/^\d$/', $v)) orig_fail('Single is one digit, 0-9.'); break;
            case 'jodi':    if (!preg_match('/^\d\d$/', $v)) orig_fail('Jodi is two digits, 00-99.'); break;
            case 'oddeven': $v = strtolower($v); if ($v !== 'odd' && $v !== 'even') orig_fail('Choose odd or even.'); break;
            case 'panna':
                if (!preg_match('/^\d{3}$/', $v)) orig_fail('Panna is three digits.');
                $canon = implode('', orig_matka_sort(array_map('intval', str_split($v))));
                if ($canon !== $v) orig_fail('Write the panna in order, e.g. ' . $canon . '.');
                break;
            default: orig_fail('Unknown bet.');
        }
        $c['value'] = $v;
    }
    unset($c);
    $market = in_array($in['market'] ?? '', orig_matka_markets(), true) ? $in['market'] : orig_matka_markets()[0];
    return ['stake' => $sum, 'params' => ['bets' => $chips, 'market' => $market]];
}

function orig_matka_play($stake, array $p, OrigRng $rng, array $cfg) {
    $open = [$rng->int(10), $rng->int(10), $rng->int(10)];
    $close = [$rng->int(10), $rng->int(10), $rng->int(10)];
    $openPanna = implode('', orig_matka_sort($open));
    $closePanna = implode('', orig_matka_sort($close));
    $openAnk = array_sum($open) % 10;
    $closeAnk = array_sum($close) % 10;
    $jodi = $openAnk . $closeAnk;
    $payout = 0.0; $winning = [];
    foreach ($p['bets'] as $i => $b) {
        $win = false; $prob = 1;
        switch ($b['type']) {
            case 'single':  $win = (int) $b['value'] === $openAnk; $prob = 0.1; break;
            case 'jodi':    $win = $b['value'] === $jodi; $prob = 0.01; break;
            case 'oddeven': $win = ($openAnk % 2 === 1) === ($b['value'] === 'odd'); $prob = 0.5; break;
            case 'panna':   $win = $b['value'] === $openPanna; $prob = orig_matka_panna_prob($b['value']); break;
        }
        if ($win) { $payout += $b['amount'] * orig_matka_pay($prob, $cfg['rtp']); $winning[] = $i; }
    }
    return ['status' => 'settled', 'payout' => $payout, 'result' => [
        'market' => $p['market'], 'open_panna' => $openPanna, 'close_panna' => $closePanna,
        'open_ank' => $openAnk, 'close_ank' => $closeAnk, 'jodi' => $jodi,
        'display' => $openPanna . '-' . $jodi . '-' . $closePanna, 'winning_bets' => $winning,
    ]];
}

function orig_matka_info(array $cfg) {
    $r = $cfg['rtp'];
    return ['rtp' => $r, 'markets' => orig_matka_markets(), 'pays' => [
        'single' => orig_matka_pay(0.1, $r), 'jodi' => orig_matka_pay(0.01, $r), 'oddeven' => orig_matka_pay(0.5, $r),
        'panna_single' => orig_matka_pay(0.006, $r), 'panna_double' => orig_matka_pay(0.003, $r), 'panna_triple' => orig_matka_pay(0.001, $r),
    ]];
}
