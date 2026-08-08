<?php
/**
 * Mines Game Logic - Core Engine (Unified Backend)
 */

session_start();

class MinesGame
{
    const STARTING_BALANCE = 1000.0;
    const GRID_SIZE         = 25;
    const MIN_MINES         = 1;
    const MAX_MINES         = 24;
    const RTP               = 0.97;

    public function __construct()
    {
        if (!isset($_SESSION['balance'])) {
            $_SESSION['balance'] = self::STARTING_BALANCE;
        }
        if (!isset($_SESSION['mines_game'])) {
            $_SESSION['mines_game'] = ['status' => 'idle'];
        }
    }

    private function calcMultiplier(int $gridSize, int $minesCount, int $revealedCount): float
    {
        if ($revealedCount <= 0) return 1.0;

        $prob = 1.0;
        for ($i = 0; $i < $revealedCount; $i++) {
            $safeLeft  = $gridSize - $minesCount - $i;
            $totalLeft = $gridSize - $i;
            if ($safeLeft <= 0) return 0.0;
            $prob *= $safeLeft / $totalLeft;
        }
        return round((1 / $prob) * self::RTP, 4);
    }

    private function generateMinePositions(string $serverSeed, int $gridSize, int $minesCount): array
    {
        $ranked = [];
        for ($i = 0; $i < $gridSize; $i++) {
            $ranked[$i] = hash('sha256', $serverSeed . ':' . $i);
        }
        asort($ranked);
        return array_slice(array_keys($ranked), 0, $minesCount);
    }

    private function getCurrentBalance(): float
    {
        $username = $_SESSION['username'] ?? 'DemoUser';
        if ($username !== 'DemoUser') {
            require_once __DIR__ . '/db.php';
            $users = db_read('users');
            foreach ($users as $u) {
                if (strtolower($u['username']) === strtolower($username)) {
                    return (float)$u['wallet_balance'];
                }
            }
        }
        return $_SESSION['balance'];
    }

    public function startGame(float $betAmount, int $minesCount): array
    {
        $game = $_SESSION['mines_game'];
        if (($game['status'] ?? 'idle') === 'active') {
            return ['ok' => false, 'error' => 'Finish or cash out your current game first.'];
        }
        $balance = $this->getCurrentBalance();
        if ($betAmount <= 0 || $betAmount > $balance) {
            return ['ok' => false, 'error' => 'Invalid bet amount.'];
        }
        if ($minesCount < self::MIN_MINES || $minesCount > self::MAX_MINES) {
            return ['ok' => false, 'error' => 'Mines must be between ' . self::MIN_MINES . ' and ' . self::MAX_MINES . '.'];
        }

        $serverSeed = bin2hex(random_bytes(16));
        $mines      = $this->generateMinePositions($serverSeed, self::GRID_SIZE, $minesCount);

        $username = $_SESSION['username'] ?? 'DemoUser';
        if ($username !== 'DemoUser') {
            require_once __DIR__ . '/db.php';
            $res = db_adjust_wallet($username, -$betAmount, "Mines Bet");
            if (isset($res['error'])) {
                return ['ok' => false, 'error' => $res['error']];
            }
        } else {
            $_SESSION['balance'] -= $betAmount;
        }

        $_SESSION['mines_game'] = [
            'status'       => 'active',
            'bet_amount'   => $betAmount,
            'mines_count'  => $minesCount,
            'server_seed'  => $serverSeed,
            'seed_hash'    => hash('sha256', $serverSeed),
            'mine_positions' => $mines,
            'revealed'     => [],
        ];

        return ['ok' => true, 'state' => $this->getState()];
    }

    public function revealTile(int $index): array
    {
        $game = &$_SESSION['mines_game'];
        if (($game['status'] ?? 'idle') !== 'active') {
            return ['ok' => false, 'error' => 'No active game.'];
        }
        if ($index < 0 || $index >= self::GRID_SIZE) {
            return ['ok' => false, 'error' => 'Tile out of range.'];
        }
        if (in_array($index, $game['revealed'], true)) {
            return ['ok' => false, 'error' => 'Tile already revealed.'];
        }

        if (in_array($index, $game['mine_positions'], true)) {
            $game['status'] = 'busted';
            return ['ok' => true, 'hit_mine' => true, 'state' => $this->getState()];
        }

        $game['revealed'][] = $index;
        return ['ok' => true, 'hit_mine' => false, 'state' => $this->getState()];
    }

    public function cashOut(): array
    {
        $game = &$_SESSION['mines_game'];
        if (($game['status'] ?? 'idle') !== 'active') {
            return ['ok' => false, 'error' => 'No active game to cash out.'];
        }
        if (count($game['revealed']) === 0) {
            return ['ok' => false, 'error' => 'Reveal at least one safe tile before cashing out.'];
        }

        $multiplier = $this->calcMultiplier(self::GRID_SIZE, $game['mines_count'], count($game['revealed']));
        $payout     = round($game['bet_amount'] * $multiplier, 2);

        $username = $_SESSION['username'] ?? 'DemoUser';
        if ($username !== 'DemoUser') {
            require_once __DIR__ . '/db.php';
            db_adjust_wallet($username, $payout, "Mines Cash Out");
        } else {
            $_SESSION['balance'] += $payout;
        }
        $game['status'] = 'cashed';

        return ['ok' => true, 'payout' => $payout, 'state' => $this->getState()];
    }

    public function getState(): array
    {
        $game = $_SESSION['mines_game'];
        $status = $game['status'] ?? 'idle';
        $revealedCount = $status === 'idle' ? 0 : count($game['revealed'] ?? []);

        $multiplier = 0;
        $potentialPayout = 0;
        if ($status === 'active' || $status === 'cashed') {
            $multiplier = $this->calcMultiplier(self::GRID_SIZE, $game['mines_count'], $revealedCount);
            $potentialPayout = round(($game['bet_amount'] ?? 0) * $multiplier, 2);
        }

        $gameOver = in_array($status, ['busted', 'cashed'], true);
        $balance = $this->getCurrentBalance();

        return [
            'status'         => $status,
            'grid_size'      => self::GRID_SIZE,
            'mines_count'    => $game['mines_count'] ?? null,
            'bet_amount'     => $game['bet_amount'] ?? null,
            'revealed'       => $game['revealed'] ?? [],
            'multiplier'     => $multiplier,
            'potential_payout' => $potentialPayout,
            'seed_hash'      => $game['seed_hash'] ?? null,
            'server_seed'    => $gameOver ? ($game['server_seed'] ?? null) : null,
            'mine_positions' => $gameOver ? ($game['mine_positions'] ?? null) : null,
            'balance'        => round($balance, 2),
        ];
    }
}
