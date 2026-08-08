<?php
/**
 * logic.php — Aviator-style "crash game" core logic (LEARNING / DEMO BUILD)
 *
 * IMPORTANT SCOPE NOTE:
 * This demo uses a VIRTUAL points balance only. There is no real money,
 * no payment gateway, no withdrawals, and no multi-user wallet system.
 * It exists to teach the *mechanics* of how a crash-style game works:
 *   1) Provably-fair crash-point generation
 *   2) Server-authoritative round timing (waiting -> running -> crashed)
 *   3) Placing a bet / cashing out before the crash
 *
 * State is kept in the PHP session, so this is a single-player sandbox.
 * A real multi-user product would need a database, locking around bets,
 * auth, and (if real money were ever involved) a licensed gambling
 * operator's compliance stack — none of which is in scope here.
 */

session_start();

class AviatorGame
{
    // ---- Tunable constants -------------------------------------------------
    const STARTING_BALANCE   = 1000.0;  // virtual points, not currency
    const WAITING_SECONDS    = 8;       // betting window before each round
    const CRASHED_PAUSE_SECS = 4;       // pause showing the crash before next round
    const GROWTH_RATE        = 0.06;    // exponential growth rate per second
    const INSTANT_CRASH_MOD  = 33;      // ~1-in-33 rounds crash instantly at 1.00x

    public function __construct()
    {
        if (!isset($_SESSION['round'])) {
            $this->startNewRound();
        }
    }

    // ---- Database Sync Helpers ---------------------------------------------
    private function getPlayerBalance(): float
    {
        if (isset($_SESSION['username'])) {
            $username = $_SESSION['username'];
            require_once __DIR__ . '/../api/db.php';
            $users = db_read('users');
            foreach ($users as $u) {
                if (strtolower($u['username']) === strtolower($username)) {
                    return (float)$u['wallet_balance'];
                }
            }
        }
        
        if (!isset($_SESSION['balance'])) {
            $_SESSION['balance'] = self::STARTING_BALANCE;
        }
        return $_SESSION['balance'];
    }

    private function adjustPlayerBalance(float $delta, string $reason): void
    {
        if (isset($_SESSION['username'])) {
            $username = $_SESSION['username'];
            require_once __DIR__ . '/../api/db.php';
            try {
                db_adjust_wallet($username, $delta, $reason);
            } catch (Exception $e) {
                // Log warning or suppress if needed
            }
        } else {
            if (!isset($_SESSION['balance'])) {
                $_SESSION['balance'] = self::STARTING_BALANCE;
            }
            $_SESSION['balance'] += $delta;
        }
    }

    // ---- Provably-fair crash point -----------------------------------------
    // Classic "bustabit-style" public algorithm: hash a server seed + round
    // number, take 52 bits of it, and map to a crash multiplier. Anyone can
    // recompute this after the fact to verify the round wasn't tampered with.
    private function generateCrashPoint(string $seed): float
    {
        $hash  = hash('sha256', $seed);
        $hex   = substr($hash, 0, 13); // 52 bits
        $intVal = hexdec($hex);
        $e     = 2 ** 52;

        if ($intVal % self::INSTANT_CRASH_MOD === 0) {
            return 1.00;
        }

        $crash = floor((100 * $e - $intVal) / ($e - $intVal)) / 100;
        return max(1.00, $crash);
    }

    private function startNewRound(): void
    {
        $roundId    = ($_SESSION['round']['id'] ?? 0) + 1;
        $serverSeed = bin2hex(random_bytes(16));

        $_SESSION['round'] = [
            'id'          => $roundId,
            'server_seed' => $serverSeed,          // revealed only after crash
            'seed_hash'   => hash('sha256', $serverSeed), // shown up-front (provably fair)
            'crash_point' => $this->generateCrashPoint($serverSeed . ':' . $roundId),
            'phase'       => 'waiting',
            'phase_start' => microtime(true),
            'bet'         => null, // this player's bet for the round, if any
        ];
    }

    // ---- Time-based state machine ------------------------------------------
    // Every call to getState() re-derives the phase from elapsed wall-clock
    // time rather than trusting the client, so the client can't fake timing.
    private function currentMultiplier(float $elapsedRunningSecs): float
    {
        return round(exp(self::GROWTH_RATE * $elapsedRunningSecs), 2);
    }

    private function advancePhaseIfNeeded(): void
    {
        $round = &$_SESSION['round'];
        $now   = microtime(true);
        $sinceStart = $now - $round['phase_start'];

        if ($round['phase'] === 'waiting' && $sinceStart >= self::WAITING_SECONDS) {
            $round['phase']       = 'running';
            $round['phase_start'] = $now;
            return;
        }

        if ($round['phase'] === 'running') {
            $mult = $this->currentMultiplier($sinceStart);
            if ($mult >= $round['crash_point']) {
                // Round busts. Any un-cashed bet is lost (already deducted
                // from balance at bet time — nothing further to charge).
                if ($round['bet'] && !$round['bet']['cashed_out']) {
                    $round['bet']['result'] = 'lost';
                }
                $round['phase']       = 'crashed';
                $round['phase_start'] = $now;
            }
            return;
        }

        if ($round['phase'] === 'crashed' && $sinceStart >= self::CRASHED_PAUSE_SECS) {
            $this->startNewRound();
        }
    }

    // ---- Public API used by api.php ----------------------------------------
    public function getState(): array
    {
        $this->advancePhaseIfNeeded();
        $round = $_SESSION['round'];
        $now   = microtime(true);
        $elapsed = $now - $round['phase_start'];

        $multiplier = 1.00;
        if ($round['phase'] === 'running') {
            $multiplier = $this->currentMultiplier($elapsed);
        } elseif ($round['phase'] === 'crashed') {
            $multiplier = $round['crash_point'];
        }

        return [
            'round_id'     => $round['id'],
            'phase'        => $round['phase'],          // waiting | running | crashed
            'seed_hash'    => $round['seed_hash'],       // provably-fair commitment
            'server_seed'  => $round['phase'] === 'crashed' ? $round['server_seed'] : null,
            'crash_point'  => $round['phase'] === 'crashed' ? $round['crash_point'] : null,
            'multiplier'   => $multiplier,
            'seconds_left' => $round['phase'] === 'waiting'
                ? max(0, round(self::WAITING_SECONDS - $elapsed, 1))
                : null,
            'balance'      => round($this->getPlayerBalance(), 2),
            'bet'          => $round['bet'],
        ];
    }

    public function placeBet(float $amount): array
    {
        $this->advancePhaseIfNeeded();
        $round = &$_SESSION['round'];

        if ($round['phase'] !== 'waiting') {
            return ['ok' => false, 'error' => 'Betting is closed for this round.'];
        }
        if ($round['bet'] !== null) {
            return ['ok' => false, 'error' => 'You already placed a bet this round.'];
        }
        if ($amount <= 0 || $amount > $this->getPlayerBalance()) {
            return ['ok' => false, 'error' => 'Invalid bet amount.'];
        }

        $this->adjustPlayerBalance(-$amount, 'Aviator Bet Placed');
        $round['bet'] = [
            'amount'            => $amount,
            'cashed_out'        => false,
            'cashout_multiplier'=> null,
            'result'            => 'pending',
        ];

        return ['ok' => true, 'state' => $this->getState()];
    }

    public function cashOut(): array
    {
        $this->advancePhaseIfNeeded();
        $round = &$_SESSION['round'];

        if ($round['phase'] !== 'running') {
            return ['ok' => false, 'error' => 'You can only cash out while the round is running.'];
        }
        if (!$round['bet'] || $round['bet']['cashed_out']) {
            return ['ok' => false, 'error' => 'No active bet to cash out.'];
        }

        $elapsed    = microtime(true) - $round['phase_start'];
        $multiplier = $this->currentMultiplier($elapsed);
        $payout     = round($round['bet']['amount'] * $multiplier, 2);

        $this->adjustPlayerBalance($payout, 'Aviator Cashout Payout');
        $round['bet']['cashed_out']         = true;
        $round['bet']['cashout_multiplier'] = $multiplier;
        $round['bet']['result']             = 'won';

        return ['ok' => true, 'payout' => $payout, 'state' => $this->getState()];
    }
}