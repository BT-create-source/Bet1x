<?php
/**
 * Football Betting Game Logic - Core Engine (Unified Backend)
 */

session_start();

class FootballBettingGame
{
    const STARTING_BALANCE = 1000.0;
    const MARGIN            = 1.06;
    const MAX_GOALS_GRID    = 8;
    const NUM_MATCHES       = 4;

    const TEAM_POOL = [
        'Ember City FC', 'Northgate United', 'Rivertown Rovers', 'Ironclad Athletic',
        'Solaris FC', 'Blackwood Town', 'Harborlight FC', 'Grayfield Wanderers',
        'Cinder Park FC', 'Meridian United',
    ];

    public function __construct()
    {
        if (!isset($_SESSION['fb_balance'])) {
            $_SESSION['fb_balance'] = self::STARTING_BALANCE;
        }
        if (!isset($_SESSION['fb_matches'])) {
            $this->generateFixtures();
        }
        if (!isset($_SESSION['fb_bets'])) {
            $_SESSION['fb_bets'] = [];
        }
    }

    private function factorial(int $n): float
    {
        $f = 1.0;
        for ($i = 2; $i <= $n; $i++) $f *= $i;
        return $f;
    }

    private function poissonPmf(int $k, float $lambda): float
    {
        return exp(-$lambda) * pow($lambda, $k) / $this->factorial($k);
    }

    private function uniformFromSeed(string $seed, string $label): float
    {
        $hex = substr(hash('sha256', $seed . ':' . $label), 0, 13);
        return hexdec($hex) / (2 ** 52);
    }

    private function samplePoisson(float $lambda, float $u): int
    {
        $cumulative = 0.0;
        for ($k = 0; $k <= self::MAX_GOALS_GRID; $k++) {
            $cumulative += $this->poissonPmf($k, $lambda);
            if ($u < $cumulative) return $k;
        }
        return self::MAX_GOALS_GRID;
    }

    private function scoreGrid(float $lambdaHome, float $lambdaAway): array
    {
        $grid = [];
        for ($h = 0; $h <= self::MAX_GOALS_GRID; $h++) {
            for ($a = 0; $a <= self::MAX_GOALS_GRID; $a++) {
                $grid["$h-$a"] = $this->poissonPmf($h, $lambdaHome) * $this->poissonPmf($a, $lambdaAway);
            }
        }
        return $grid;
    }

    private function oddsFromProb(float $prob): float
    {
        if ($prob <= 0) return 999.0;
        return round(1 / ($prob * self::MARGIN), 2);
    }

    private function generateFixtures(): void
    {
        $pool = self::TEAM_POOL;
        shuffle($pool);
        $matches = [];

        for ($i = 0; $i < self::NUM_MATCHES; $i++) {
            $home = $pool[$i * 2];
            $away = $pool[$i * 2 + 1];

            $lambdaHome = round(mt_rand(70, 220) / 100, 2) + 0.15;
            $lambdaAway = round(mt_rand(50, 200) / 100, 2);

            $grid = $this->scoreGrid($lambdaHome, $lambdaAway);

            $homeWin = $draw = $awayWin = $over25 = $btts = 0.0;
            foreach ($grid as $score => $p) {
                [$h, $a] = array_map('intval', explode('-', $score));
                if ($h > $a) $homeWin += $p;
                elseif ($h === $a) $draw += $p;
                else $awayWin += $p;
                if ($h + $a >= 3) $over25 += $p;
                if ($h >= 1 && $a >= 1) $btts += $p;
            }

            $commonScores = ['0-0','1-0','0-1','1-1','2-0','0-2','2-1','1-2','2-2'];
            $correctScoreOdds = [];
            $coveredProb = 0.0;
            foreach ($commonScores as $s) {
                $p = $grid[$s] ?? 0.0;
                $coveredProb += $p;
                $correctScoreOdds[$s] = $this->oddsFromProb($p);
            }
            $correctScoreOdds['other'] = $this->oddsFromProb(max(0.0001, 1 - $coveredProb));

            $serverSeed = bin2hex(random_bytes(16));

            $matches[] = [
                'id'          => $i + 1,
                'home'        => $home,
                'away'        => $away,
                'lambda_home' => $lambdaHome,
                'lambda_away' => $lambdaAway,
                'server_seed' => $serverSeed,
                'seed_hash'   => hash('sha256', $serverSeed),
                'status'      => 'scheduled',
                'score'       => null,
                'odds'        => [
                    'home'  => $this->oddsFromProb($homeWin),
                    'draw'  => $this->oddsFromProb($draw),
                    'away'  => $this->oddsFromProb($awayWin),
                    'over25'  => $this->oddsFromProb($over25),
                    'under25' => $this->oddsFromProb(1 - $over25),
                    'btts_yes' => $this->oddsFromProb($btts),
                    'btts_no'  => $this->oddsFromProb(1 - $btts),
                    'correct_score' => $correctScoreOdds,
                ],
            ];
        }

        $_SESSION['fb_matches'] = $matches;
    }

    private function findMatchIndex(int $matchId): ?int
    {
        foreach ($_SESSION['fb_matches'] as $idx => $m) {
            if ($m['id'] === $matchId) return $idx;
        }
        return null;
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
        return $_SESSION['fb_balance'];
    }

    public function placeBet(array $legs, float $stake): array
    {
        $balance = $this->getCurrentBalance();
        if ($stake <= 0 || $stake > $balance) {
            return ['ok' => false, 'error' => 'Invalid stake amount.'];
        }
        if (empty($legs)) {
            return ['ok' => false, 'error' => 'Select at least one pick.'];
        }

        $totalOdds = 1.0;
        $resolvedLegs = [];

        foreach ($legs as $leg) {
            $idx = $this->findMatchIndex((int) $leg['match_id']);
            if ($idx === null) return ['ok' => false, 'error' => 'Unknown match.'];
            $match = $_SESSION['fb_matches'][$idx];
            if ($match['status'] !== 'scheduled') {
                return ['ok' => false, 'error' => 'Match already finished — pick removed.'];
            }

            $odds = $this->oddsForSelection($match, $leg['bet_type'], $leg['selection']);
            if ($odds === null) {
                return ['ok' => false, 'error' => 'Invalid bet type/selection.'];
            }

            $totalOdds *= $odds;
            $resolvedLegs[] = [
                'match_id'  => $match['id'],
                'match_label' => $match['home'] . ' vs ' . $match['away'],
                'bet_type'  => $leg['bet_type'],
                'selection' => $leg['selection'],
                'odds'      => $odds,
                'result'    => 'pending',
            ];
        }

        $bet = [
            'id'          => count($_SESSION['fb_bets']) + 1,
            'stake'       => $stake,
            'total_odds'  => round($totalOdds, 3),
            'potential_payout' => round($stake * $totalOdds, 2),
            'status'      => 'pending',
            'legs'        => $resolvedLegs,
        ];

        $username = $_SESSION['username'] ?? 'DemoUser';
        if ($username !== 'DemoUser') {
            require_once __DIR__ . '/db.php';
            $res = db_adjust_wallet($username, -$stake, "Football Wager: Bet #" . $bet['id']);
            if (isset($res['error'])) {
                return ['ok' => false, 'error' => $res['error']];
            }
        } else {
            $_SESSION['fb_balance'] -= $stake;
        }

        $_SESSION['fb_bets'][] = $bet;

        return ['ok' => true, 'state' => $this->getState()];
    }

    private function oddsForSelection(array $match, string $betType, string $selection): ?float
    {
        switch ($betType) {
            case 'match_winner':
                return $match['odds'][$selection] ?? null;
            case 'over_under':
                return $match['odds'][$selection] ?? null;
            case 'btts':
                return $match['odds'][$selection] ?? null;
            case 'correct_score':
                return $match['odds']['correct_score'][$selection] ?? null;
            default:
                return null;
        }
    }

    public function simulateMatch(int $matchId): array
    {
        $idx = $this->findMatchIndex($matchId);
        if ($idx === null) return ['ok' => false, 'error' => 'Unknown match.'];

        $match = &$_SESSION['fb_matches'][$idx];
        if ($match['status'] !== 'scheduled') {
            return ['ok' => false, 'error' => 'Match already simulated.'];
        }

        $uHome = $this->uniformFromSeed($match['server_seed'], 'home_goals');
        $uAway = $this->uniformFromSeed($match['server_seed'], 'away_goals');
        $h = $this->samplePoisson($match['lambda_home'], $uHome);
        $a = $this->samplePoisson($match['lambda_away'], $uAway);

        $match['status'] = 'finished';
        $match['score']  = [$h, $a];

        $this->settleBetsForMatch($matchId, $h, $a);

        return ['ok' => true, 'state' => $this->getState()];
    }

    private function legWins(string $betType, string $selection, int $h, int $a): bool
    {
        switch ($betType) {
            case 'match_winner':
                $actual = $h > $a ? 'home' : ($h === $a ? 'draw' : 'away');
                return $selection === $actual;
            case 'over_under':
                $isOver = ($h + $a) >= 3;
                return $selection === ($isOver ? 'over25' : 'under25');
            case 'btts':
                $isBtts = $h >= 1 && $a >= 1;
                return $selection === ($isBtts ? 'btts_yes' : 'btts_no');
            case 'correct_score':
                $commonScores = ['0-0','1-0','0-1','1-1','2-0','0-2','2-1','1-2','2-2'];
                $actualScore = "$h-$a";
                if (in_array($actualScore, $commonScores, true)) {
                    return $selection === $actualScore;
                }
                return $selection === 'other';
            default:
                return false;
        }
    }

    private function settleBetsForMatch(int $matchId, int $h, int $a): void
    {
        foreach ($_SESSION['fb_bets'] as &$bet) {
            foreach ($bet['legs'] as &$leg) {
                if ($leg['match_id'] !== $matchId || $leg['result'] !== 'pending') continue;
                $leg['result'] = $this->legWins($leg['bet_type'], $leg['selection'], $h, $a) ? 'won' : 'lost';
            }
            unset($leg);

            if ($bet['status'] === 'lost' || $bet['status'] === 'won') continue;

            $allDecided = true;
            $anyLost = false;
            foreach ($bet['legs'] as $leg) {
                if ($leg['result'] === 'pending') $allDecided = false;
                if ($leg['result'] === 'lost') $anyLost = true;
            }

            if ($anyLost) {
                $bet['status'] = 'lost';
            } elseif ($allDecided) {
                $bet['status'] = 'won';
                $username = $_SESSION['username'] ?? 'DemoUser';
                if ($username !== 'DemoUser') {
                    require_once __DIR__ . '/db.php';
                    db_adjust_wallet($username, $bet['potential_payout'], "Football Win: Bet #" . $bet['id']);
                } else {
                    $_SESSION['fb_balance'] += $bet['potential_payout'];
                }
            }
        }
        unset($bet);
    }

    public function getState(): array
    {
        $publicMatches = array_map(function ($m) {
            return [
                'id'        => $m['id'],
                'home'      => $m['home'],
                'away'      => $m['away'],
                'status'    => $m['status'],
                'score'     => $m['score'],
                'seed_hash' => $m['seed_hash'],
                'server_seed' => $m['status'] === 'finished' ? $m['server_seed'] : null,
                'odds'      => $m['odds'],
            ];
        }, $_SESSION['fb_matches']);

        $balance = $this->getCurrentBalance();

        return [
            'balance' => round($balance, 2),
            'matches' => $publicMatches,
            'bets'    => array_reverse($_SESSION['fb_bets']),
        ];
    }

    public function resetAll(): void
    {
        $username = $_SESSION['username'] ?? 'DemoUser';
        if ($username !== 'DemoUser') {
            require_once __DIR__ . '/db.php';
            db_transaction('users', function(&$users) use ($username) {
                foreach ($users as &$u) {
                    if (strtolower($u['username']) === strtolower($username)) {
                        $u['wallet_balance'] = 1000.00;
                    }
                }
            });
        } else {
            $_SESSION['fb_balance'] = self::STARTING_BALANCE;
        }
        $_SESSION['fb_bets']    = [];
        $this->generateFixtures();
    }
}
