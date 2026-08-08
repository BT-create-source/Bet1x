<?php
/**
 * Teen Patti - Core Game Logic
 * Deck banana, cards deal karna, hand evaluate karna, aur game-state manage karna.
 * Ye pure functions hain jo $_SESSION['game'] array par kaam karte hain.
 */

// ---------- Constants ----------
define('BOOT_AMOUNT', 10);      // fixed boot/ante amount
define('STARTING_BALANCE', 1000);
define('BOT_NAMES', ['Raju', 'Vikram', 'Sana']);

// ---------- Deck ----------
function createShuffledDeck(): array {
    $suits = ['S', 'H', 'C', 'D']; // Spade, Heart, Club, Diamond
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
    // tie-break ke liye suit ki priority: Spade > Heart > Club > Diamond
    $order = ['S' => 4, 'H' => 3, 'C' => 2, 'D' => 1];
    return $order[$s] ?? 0;
}

// ---------- Hand Evaluation ----------
// Returns [categoryNumber, tiebreakRanksArray, bestSuitRank]
// category: 6=Trail, 5=Pure Sequence, 4=Sequence, 3=Color, 2=Pair, 1=High Card
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
        // Ace-2-3 special low sequence
        $isSeq = true;
        $seqTiebreak = [3, 2, 1];
    }

    $bestSuit = max(array_map('suitRank', $suits));

    if ($ranks[0] === $ranks[1] && $ranks[1] === $ranks[2]) {
        return [6, [$ranks[0]], $bestSuit]; // Trail
    }
    if ($isSeq && $isColor) {
        return [5, $seqTiebreak, $bestSuit]; // Pure Sequence
    }
    if ($isSeq) {
        return [4, $seqTiebreak, $bestSuit]; // Sequence
    }
    if ($isColor) {
        return [3, $ranks, $bestSuit]; // Color
    }
    if ($ranks[0] === $ranks[1]) {
        return [2, [$ranks[0], $ranks[2]], $bestSuit]; // Pair + kicker
    }
    if ($ranks[1] === $ranks[2]) {
        return [2, [$ranks[1], $ranks[0]], $bestSuit];
    }
    return [1, $ranks, $bestSuit]; // High Card
}

function handLabel(int $category): string {
    $labels = [
        6 => 'Trail (Set)', 5 => 'Pure Sequence', 4 => 'Sequence',
        3 => 'Color', 2 => 'Pair', 1 => 'High Card',
    ];
    return $labels[$category] ?? 'Unknown';
}

// Returns true if handA beats handB
function handWins(array $handA, array $handB): bool {
    if ($handA[0] !== $handB[0]) return $handA[0] > $handB[0];
    $tA = $handA[1]; $tB = $handB[1];
    for ($i = 0; $i < count($tA); $i++) {
        if (($tA[$i] ?? 0) !== ($tB[$i] ?? 0)) return $tA[$i] > $tB[$i];
    }
    return $handA[2] > $handB[2]; // suit tiebreak
}

// ---------- Game State ----------
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

    // Sab players boot amount pot me dalte hain
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
        'current_stake' => BOOT_AMOUNT, // seen bet unit
        'status' => 'playing', // playing | finished
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

// Ek player chaal (bet) karta hai / seen bolta hai
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

// Simple bot AI: apne hand ki strength dekh kar chaal ya fold decide karta hai
function botDecide(array $game, string $key): string {
    $hand = evaluateHand($game['players'][$key]['cards']);
    $category = $hand[0];
    $rand = mt_rand(1, 100);

    if ($category >= 5) return 'chaal';           // Pure Seq / Trail -> almost always continue
    if ($category === 4 && $rand <= 90) return 'chaal'; // Sequence
    if ($category === 3 && $rand <= 70) return 'chaal'; // Color
    if ($category === 2 && $rand <= 55) return 'chaal'; // Pair
    if ($category === 1 && $rand <= 25) return 'chaal'; // High card, kabhi kabhi bluff
    return 'fold';
}

// Bots ki turn process karo jab tak human ki turn na aaye ya game khatam na ho
function processBotTurns(array &$game): void {
    while ($game['status'] === 'playing') {
        $key = currentPlayerKey($game);
        $player = $game['players'][$key];
        if (!$player['is_bot']) return; // human ki baari, ruk jao

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