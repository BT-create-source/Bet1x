<?php
/**
 * Teen Patti - Core Game Logic (Unified Backend)
 */

define('BOOT_AMOUNT', 10);
define('STARTING_BALANCE', 1000);
define('BOT_NAMES', ['Raju', 'Vikram', 'Sana']);

function createShuffledDeck(): array {
    $suits = ['S', 'H', 'C', 'D'];
    $deck = [];
    for ($r = 2; $r <= 14; $r++) {
        foreach ($suits as $s) {
            $deck[] = ['r' => $r, 's' => $s];
        }
    }
    shuffle($deck);
    return $deck;
}

function rankLabel(int $r): string {
    $labels = [11 => 'J', 12 => 'Q', 13 => 'K', 14 => 'A'];
    return $labels[$r] ?? (string)$r;
}

function suitSymbol(string $s): string {
    $map = ['S' => '&spades;', 'H' => '&hearts;', 'C' => '&clubs;', 'D' => '&diams;'];
    return $map[$s] ?? $s;
}

function suitRank(string $s): int {
    $order = ['S' => 4, 'H' => 3, 'C' => 2, 'D' => 1];
    return $order[$s] ?? 0;
}

function evaluateHand(array $cards): array {
    $ranks = array_map(fn($c) => $c['r'], $cards);
    $suits = array_map(fn($c) => $c['s'], $cards);
    rsort($ranks);

    $isColor = (count(array_unique($suits)) === 1);

    $isSeq = false;
    $seqTiebreak = $ranks;
    if ($ranks[0] - $ranks[1] === 1 && $ranks[1] - $ranks[2] === 1) {
        $isSeq = true;
    } elseif ($ranks === [14, 3, 2]) {
        $isSeq = true;
        $seqTiebreak = [3, 2, 1];
    }

    $bestSuit = max(array_map('suitRank', $suits));

    if ($ranks[0] === $ranks[1] && $ranks[1] === $ranks[2]) {
        return [6, [$ranks[0]], $bestSuit];
    }
    if ($isSeq && $isColor) {
        return [5, $seqTiebreak, $bestSuit];
    }
    if ($isSeq) {
        return [4, $seqTiebreak, $bestSuit];
    }
    if ($isColor) {
        return [3, $ranks, $bestSuit];
    }
    if ($ranks[0] === $ranks[1]) {
        return [2, [$ranks[0], $ranks[2]], $bestSuit];
    }
    if ($ranks[1] === $ranks[2]) {
        return [2, [$ranks[1], $ranks[0]], $bestSuit];
    }
    return [1, $ranks, $bestSuit];
}

function handLabel(int $category): string {
    $labels = [
        6 => 'Trail (Set)', 5 => 'Pure Sequence', 4 => 'Sequence',
        3 => 'Color', 2 => 'Pair', 1 => 'High Card',
    ];
    return $labels[$category] ?? 'Unknown';
}

function handWins(array $handA, array $handB): bool {
    if ($handA[0] !== $handB[0]) return $handA[0] > $handB[0];
    $tA = $handA[1]; $tB = $handB[1];
    for ($i = 0; $i < count($tA); $i++) {
        if (($tA[$i] ?? 0) !== ($tB[$i] ?? 0)) return $tA[$i] > $tB[$i];
    }
    return $handA[2] > $handB[2];
}

function newGameState(int $balance): array {
    $deck = createShuffledDeck();
    $players = [];

    $players['human'] = [
        'name' => 'Aap', 'is_bot' => false, 'cards' => [array_shift($deck), array_shift($deck), array_shift($deck)],
        'folded' => false, 'balance' => $balance, 'seen' => false,
    ];
    foreach (BOT_NAMES as $i => $bname) {
        $key = 'bot' . $i;
        $players[$key] = [
            'name' => $bname, 'is_bot' => true,
            'cards' => [array_shift($deck), array_shift($deck), array_shift($deck)],
            'folded' => false, 'balance' => STARTING_BALANCE, 'seen' => true,
        ];
    }

    $pot = 0;
    foreach ($players as $k => $p) {
        $players[$k]['balance'] -= BOOT_AMOUNT;
        $pot += BOOT_AMOUNT;
    }

    return [
        'players' => $players,
        'order' => ['human', 'bot0', 'bot1', 'bot2'],
        'turn_index' => 0,
        'pot' => $pot,
        'current_stake' => BOOT_AMOUNT,
        'status' => 'playing',
        'winner' => null,
        'log' => ['Naya game shuru! Sabne boot ' . BOOT_AMOUNT . ' laga diya. Pot: ' . $pot],
        'round' => 1,
    ];
}

function activePlayers(array $game): array {
    return array_filter($game['players'], fn($p) => !$p['folded']);
}

function addLog(array &$game, string $msg): void {
    $game['log'][] = $msg;
    if (count($game['log']) > 25) array_shift($game['log']);
}

function nextTurn(array &$game): void {
    $n = count($game['order']);
    for ($i = 1; $i <= $n; $i++) {
        $idx = ($game['turn_index'] + $i) % $n;
        $key = $game['order'][$idx];
        if (!$game['players'][$key]['folded']) {
            $game['turn_index'] = $idx;
            return;
        }
    }
}

function currentPlayerKey(array $game): string {
    return $game['order'][$game['turn_index']];
}

function checkGameEnd(array &$game): bool {
    $active = activePlayers($game);
    if (count($active) === 1) {
        $winnerKey = array_key_first($active);
        endGame($game, $winnerKey, false);
        return true;
    }
    return false;
}

function endGame(array &$game, string $winnerKey, bool $wasShow): void {
    $game['status'] = 'finished';
    $game['winner'] = $winnerKey;
    $game['players'][$winnerKey]['balance'] += $game['pot'];
    $name = $game['players'][$winnerKey]['name'];
    if ($wasShow) {
        addLog($game, "$name ne SHOW jeeta! Pot ({$game['pot']}) mila.");
    } else {
        addLog($game, "Baaki sab pack ho gaye. $name jeet gaya! Pot ({$game['pot']}) mila.");
    }
}

function playerChaal(array &$game, string $key): void {
    $stake = $game['current_stake'];
    $game['players'][$key]['balance'] -= $stake;
    $game['pot'] += $stake;
    addLog($game, "{$game['players'][$key]['name']} ne Chaal khela ($stake).");
}

function playerFold(array &$game, string $key): void {
    $game['players'][$key]['folded'] = true;
    addLog($game, "{$game['players'][$key]['name']} pack ho gaya.");
}

function botDecide(array $game, string $key): string {
    $hand = evaluateHand($game['players'][$key]['cards']);
    $category = $hand[0];
    $rand = mt_rand(1, 100);

    if ($category >= 5) return 'chaal';
    if ($category === 4 && $rand <= 90) return 'chaal';
    if ($category === 3 && $rand <= 70) return 'chaal';
    if ($category === 2 && $rand <= 55) return 'chaal';
    if ($category === 1 && $rand <= 25) return 'chaal';
    return 'fold';
}

function processBotTurns(array &$game): void {
    while ($game['status'] === 'playing') {
        $key = currentPlayerKey($game);
        $player = $game['players'][$key];
        if (!$player['is_bot']) return;

        if ($player['balance'] < $game['current_stake']) {
            playerFold($game, $key);
        } else {
            $decision = botDecide($game, $key);
            if ($decision === 'chaal') {
                playerChaal($game, $key);
            } else {
                playerFold($game, $key);
            }
        }
        if (checkGameEnd($game)) return;
        nextTurn($game);
    }
}

/**
 * Generate specific preset cards
 */
function getPresetCards(string $preset): array {
    switch ($preset) {
        case 'trail_aces':
            return [['r' => 14, 's' => 'S'], ['r' => 14, 's' => 'H'], ['r' => 14, 's' => 'C']];
        case 'trail_kings':
            return [['r' => 13, 's' => 'S'], ['r' => 13, 's' => 'H'], ['r' => 13, 's' => 'D']];
        case 'pure_sequence':
        case 'pure_seq_akq':
            return [['r' => 14, 's' => 'S'], ['r' => 13, 's' => 'S'], ['r' => 12, 's' => 'S']];
        case 'sequence':
        case 'seq_j109':
            return [['r' => 11, 's' => 'S'], ['r' => 10, 's' => 'H'], ['r' => 9, 's' => 'D']];
        case 'color':
        case 'color_flush':
            return [['r' => 14, 's' => 'H'], ['r' => 9, 's' => 'H'], ['r' => 4, 's' => 'H']];
        case 'pair':
        case 'pair_kings':
            return [['r' => 13, 's' => 'S'], ['r' => 13, 's' => 'H'], ['r' => 5, 's' => 'D']];
        case 'high_card':
            return [['r' => 7, 's' => 'S'], ['r' => 4, 's' => 'H'], ['r' => 2, 's' => 'D']];
        default:
            return [];
    }
}

/**
 * Creates a dealt set of 4 hands with full rigging applied cleanly
 */
function createRiggedHands(?string $winnerKey = null, ?string $preset = null, ?string $rigType = null): array {
    $keys = ['human', 'bot0', 'bot1', 'bot2'];
    
    // Check if preset specified
    $forcedCards = !empty($preset) ? getPresetCards($preset) : [];
    
    // Try deterministic/simulated generation first
    for ($attempt = 0; $attempt < 1000; $attempt++) {
        $deck = createShuffledDeck();
        $hands = [
            'human' => [array_shift($deck), array_shift($deck), array_shift($deck)],
            'bot0'  => [array_shift($deck), array_shift($deck), array_shift($deck)],
            'bot1'  => [array_shift($deck), array_shift($deck), array_shift($deck)],
            'bot2'  => [array_shift($deck), array_shift($deck), array_shift($deck)]
        ];

        // If forced preset cards are requested for a target seat
        if (!empty($forcedCards)) {
            $targetSeat = (!empty($winnerKey) && in_array($winnerKey, $keys)) ? $winnerKey : 'human';
            
            // Rebuild deck excluding forced cards
            $allCards = [];
            for ($r = 2; $r <= 14; $r++) {
                foreach (['S', 'H', 'C', 'D'] as $s) {
                    $isUsed = false;
                    foreach ($forcedCards as $fc) {
                        if ($fc['r'] === $r && $fc['s'] === $s) { $isUsed = true; break; }
                    }
                    if (!$isUsed) $allCards[] = ['r' => $r, 's' => $s];
                }
            }
            shuffle($allCards);
            
            $hands[$targetSeat] = $forcedCards;
            foreach ($keys as $k) {
                if ($k !== $targetSeat) {
                    $hands[$k] = [array_shift($allCards), array_shift($allCards), array_shift($allCards)];
                }
            }
        }

        // Evaluate all hands
        $evals = [];
        foreach ($keys as $k) {
            $evals[$k] = evaluateHand($hands[$k]);
        }

        // Find current best and worst
        $bestKey = $keys[0];
        $worstKey = $keys[0];
        for ($i = 1; $i < count($keys); $i++) {
            if (handWins($evals[$keys[$i]], $evals[$bestKey])) $bestKey = $keys[$i];
            if (!handWins($evals[$keys[$i]], $evals[$worstKey])) $worstKey = $keys[$i];
        }

        // Apply Platform Auto-Rig Mode
        if ($rigType === 'platform_profit') {
            // Human MUST lose, a bot must have the winning hand
            if ($bestKey === 'human') {
                $targetBot = 'bot' . mt_rand(0, 2);
                $temp = $hands['human'];
                $hands['human'] = $hands[$targetBot];
                $hands[$targetBot] = $temp;
            }
            return $hands;
        } elseif ($rigType === 'user_win') {
            // Human MUST win
            if ($bestKey !== 'human') {
                $temp = $hands['human'];
                $hands['human'] = $hands[$bestKey];
                $hands[$bestKey] = $temp;
            }
            return $hands;
        }

        // Apply specific winner seat
        if (!empty($winnerKey) && in_array($winnerKey, $keys)) {
            if ($bestKey !== $winnerKey) {
                $temp = $hands[$winnerKey];
                $hands[$winnerKey] = $hands[$bestKey];
                $hands[$bestKey] = $temp;
            }
            return $hands;
        }

        // If no rig requested, return natural deal
        return $hands;
    }

    // Fallback natural deal
    $deck = createShuffledDeck();
    return [
        'human' => [array_shift($deck), array_shift($deck), array_shift($deck)],
        'bot0'  => [array_shift($deck), array_shift($deck), array_shift($deck)],
        'bot1'  => [array_shift($deck), array_shift($deck), array_shift($deck)],
        'bot2'  => [array_shift($deck), array_shift($deck), array_shift($deck)]
    ];
}

