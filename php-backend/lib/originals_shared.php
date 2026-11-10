<?php
/** Public, clock-driven rounds for the single-step Originals. All callers lock game before user. */
function originals_is_shared($game) { return !originals_catalogue()[$game]['multi']; }

function originals_shared_root() {
    $root = state_get('originals_public_root');
    if (is_string($root) && strlen($root) === 64) return $root;
    return tx_locked('originals-public-root', 'root', function () {
        $root = state_get('originals_public_root');
        if (!is_string($root) || strlen($root) !== 64) $root = state_set('originals_public_root', bin2hex(random_bytes(32)));
        return $root;
    });
}

function originals_shared_seed($game, $id) {
    return hash_hmac('sha256', $game . ':' . (int) $id, originals_shared_root());
}

/** Reference selections only determine presentation/payout; raw draws use the shared stream. */
function originals_shared_params($game) {
    switch ($game) {
        case 'dice': return ['chance' => 50, 'direction' => 'under'];
        case 'wheel': return ['risk' => 'medium', 'segments' => 10];
        case 'plinko': return ['risk' => 'medium', 'rows' => 16];
        case 'keno': return ['picks' => [1]];
        case 'matka': return ['bets' => [], 'market' => orig_matka_markets()[0]];
        case 'slots': return [];
        default: return ['bets' => []];
    }
}

/** Settle due accepted bets, even when their players have disconnected. Must hold the game lock. */
function originals_shared_settle_locked($game, $now) {
    // Consistent account order also prevents cross-game settlement from locking wallets inversely.
    $rows = all('SELECT * FROM "OriginalsRound" WHERE "game" = ? AND "status" = \'queued\' ORDER BY "username", "id" FOR UPDATE', [$game]);
    foreach ($rows as $r) {
        $state = json_decode((string) $r['state'], true);
        if (!is_array($state) || $now < (int) ($state['closes_at'] ?? PHP_INT_MAX)) continue;
        $cfg = $state['config'];
        $rng = new OrigRng($r['server_seed'], $r['client_seed'], (int) $r['nonce']);
        $out = call_user_func('orig_' . $game . '_play', (float) $r['bet_amount'], json_decode($r['params'], true), $rng, $cfg);
        $payout = orig_cap($out['payout'] ?? 0, $cfg);
        q('UPDATE "OriginalsRound" SET "payout" = ?, "multiplier" = ?, "status" = \'settled\', "result" = ?, "settled_at" = ? WHERE "id" = ?',
            [$payout, round($payout / (float) $r['bet_amount'], 6), orig_json($out['result']), ms_to_sql($state['closes_at']), $r['id']]);
        if ($payout > 0) {
            $user = find_user_ci($r['username']);
            if (!$user) throw new RuntimeException('Shared round account missing');
            credit_wallet($user['id'], $payout);
            orig_ledger_win($r['username'], originals_catalogue()[$game]['label'], $payout, $payout / $r['bet_amount'], $r['round_ref']);
        }
    }
}

/** Freeze round terms at first observation/bet, not when someone later requests the result. */
function originals_shared_tick_locked($game) {
    originals_game($game);
    $clock = originals_clock();
    $key = 'originals_public_' . $game;
    $state = state_get($key);
    if (!is_array($state)) $state = ['first_round' => $clock['round_id'], 'terms' => []];
    $id = $clock['round_id'];
    if (!isset($state['terms'][$id])) {
        $state['terms'][$id] = originals_config($game);
        foreach (array_keys($state['terms']) as $old) if ((int) $old < $id - 25) unset($state['terms'][$old]);
        state_set($key, $state);
    }
    originals_shared_settle_locked($game, $clock['server_time']);
    return [$clock, $state];
}

function originals_shared_public($game) {
    originals_game($game);
    if (!originals_is_shared($game)) return null;
    return tx_locked('originals-public', $game, function () use ($game) {
        [$clock, $state] = originals_shared_tick_locked($game);
        $id = $clock['round_id'];
        $currentSeed = originals_shared_seed($game, $id);
        $last = $clock['phase'] === 'closed' ? $id : $id - 1;
        $history = [];
        $materialized = false;
        for ($r = $last; $r >= max((int) $state['first_round'], $last - 19); $r--) {
            $seed = originals_shared_seed($game, $r);
            if (!isset($state['terms'][$r])) {
                $state['terms'][$r] = $state['terms'][$id];
                $materialized = true;
            }
            $cfg = $state['terms'][$r];
            $params = originals_shared_params($game);
            $rng = new OrigRng($seed, 'public:' . $game, $r);
            $out = call_user_func('orig_' . $game . '_play', 1.0, $params, $rng, $cfg);
            $result = $out['result'];
            // Public facts have no personal win/loss, selection, stake or account information.
            unset($result['win'], $result['target'], $result['hits'], $result['winning_bets'], $result['market']);
            if ($game === 'dice') unset($result['multiplier']);
            // Preserve precision when a viewer maps this stop to 20/30 sectors, not just the reference 10.
            if ($game === 'wheel') $result['position_degrees'] = orig_float_at($seed, 'public:' . $game, $r, 0) * 360;
            $history[] = ['round_id' => $r, 'game' => $game, 'params' => $params, 'result' => $result,
                'server_seed' => $seed, 'server_seed_hash' => hash('sha256', $seed),
                'client_seed' => 'public:' . $game, 'nonce' => $r, 'published_at' => $r * 30000 + 15000];
        }
        if ($materialized) state_set('originals_public_' . $game, $state);
        return ['clock' => $clock, 'current' => ['round_id' => $id, 'server_seed_hash' => hash('sha256', $currentSeed)], 'history' => $history];
    });
}

function originals_shared_queue(array $user, $game, $clientRef, array $in, array $prep) {
    return tx_locked('originals-public', $game, function () use ($user, $game, $clientRef, $in, $prep) {
        [$clock, $publicState] = originals_shared_tick_locked($game);
        $personalSeed = orig_seed_lock($user['username']);
        $existing = one('SELECT * FROM "OriginalsRound" WHERE "username" = ? AND "client_ref" = ?', [$user['username'], $clientRef]);
        if ($existing) {
            if ($existing['game'] !== $game) orig_fail('That reference belongs to another game.', 409, 'ref_conflict');
            return ['round' => orig_round_view($existing, $personalSeed['server_seed_hash']), 'balance' => orig_balance($user['id']), 'replayed' => true];
        }
        $clock = originals_require_betting($in);
        // Recheck after the user lock, which may have waited across a phase boundary.
        if (!isset($publicState['terms'][$clock['round_id']])) orig_fail('Round changed. Refresh the countdown.', 409, 'stale_round');
        $cfg = $publicState['terms'][$clock['round_id']];
        if (!$cfg['enabled']) orig_fail('This game is currently unavailable.', 403, 'disabled');
        $stake = orig_money($prep['stake']);
        if ($stake < $cfg['min_bet'] || $stake > $cfg['max_bet']) orig_fail('Bet amount is outside this round\'s limits.');
        if (debit_wallet($user['id'], $stake) === null) orig_fail('Insufficient balance.', 400, 'insufficient_balance');
        $id = $clock['round_id'];
        $seed = originals_shared_seed($game, $id);
        $ref = 'OR' . strtoupper(bin2hex(random_bytes(7)));
        $state = ['shared_round_id' => $id, 'closes_at' => $clock['betting_closes_at'], 'config' => $cfg];
        q('INSERT INTO "OriginalsRound" ("round_ref","username","game","client_ref","bet_amount","payout","multiplier","status","params","state","result","server_seed","server_seed_hash","client_seed","nonce","created_at","settled_at") VALUES (?,?,?,?,?,0,0,\'queued\',?,?,NULL,?,?,?,?,?,NULL)',
            [$ref, $user['username'], $game, $clientRef, $stake, orig_json($prep['params']), orig_json($state), $seed, hash('sha256', $seed), 'public:' . $game, $id, ms_to_sql()]);
        orig_ledger_bet($user['username'], originals_catalogue()[$game]['label'], $stake, 'Round ' . $ref);
        $row = one('SELECT * FROM "OriginalsRound" WHERE "round_ref" = ?', [$ref]);
        return ['round' => orig_round_view($row), 'balance' => orig_balance($user['id']), 'replayed' => false];
    });
}
