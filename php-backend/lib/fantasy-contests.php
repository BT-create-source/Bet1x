<?php
/**
 * "Your Eleven" — contests and the entry-fee money path.
 *
 * =================================================================================================
 * WHAT MOVES MONEY HERE, AND HOW
 * =================================================================================================
 * fantasy_join_contest() is the only function in the whole module that touches a wallet. It uses
 * the platform's existing helpers and nothing else:
 *
 *   debit_wallet($userId, $fee)       the existing CONDITIONAL debit: the balance check and the
 *                                    deduction are one SQL statement, so two simultaneous joins
 *                                    cannot both pass a check against the same balance
 *   insert_transaction(...)           the existing ledger row, so the cashier, the admin revenue
 *                                    figures and any audit of this account stay complete
 *   tx(fn)                            the existing transaction wrapper
 *
 * No new balance column, no fantasy-specific wallet, no direct UPDATE of "User"."wallet_balance".
 *
 * =================================================================================================
 * WHY ONE TRANSACTION RATHER THAN THE COMPENSATION PATTERN USED IN MINES
 * =================================================================================================
 * routes/mines.php debits, then refunds by hand if the ledger insert fails, because a Mines round
 * also owns non-database state (the session slot) that a rollback would not undo.
 *
 * A contest entry has no such state — the spot claim, the debit, the ledger row and the entry row
 * are all rows in this same database — so the whole join runs inside one tx(). If any step throws,
 * every one of them rolls back together: no money leaves the wallet, no spot is consumed, no ledger
 * row is orphaned. That is strictly safer than compensating after the fact, because there is no
 * window in which the compensation itself can fail.
 *
 * Both concurrency guards are therefore statements the database evaluates, not checks PHP makes:
 *
 *   - the spot claim is a conditional UPDATE (`WHERE filled_spots < total_spots`), so the row lock
 *     serialises two joins racing for the last spot and the second re-tests the new value;
 *   - double entry is the UNIQUE index on (contest_id, user_id) from migration 007. A PHP "already
 *     joined?" check cannot be atomic; the constraint can, and a violation rolls the join back with
 *     no money moved.
 */

require_once __DIR__ . '/fantasy.php';
require_once __DIR__ . '/fantasy-teams.php';

// -------------------------------------------------------------------------------------------------
// Prize tables — ported from validatePrizeBreakup()/pctForRank()/computePool() in
// backend/lib/cricket/contests.js. See the note in fantasy-teams.php about keeping the two in step.
// -------------------------------------------------------------------------------------------------

/**
 * Validate a rank-to-percentage prize table.
 *
 * `pct` is the share won by EACH rank in the band, not by the band as a whole — the standard
 * fantasy model, where every place in a band wins the same amount. The total therefore weights each
 * band by how many ranks it covers.
 *
 * Contiguity is checked because a gap silently pays nothing to a rank the lobby advertises as
 * winning, and an overlap pays it twice.
 */
function fantasy_validate_prize_breakup($breakup, $totalSpots = null) {
    if (!is_array($breakup) || !$breakup) {
        return ['ok' => false, 'error' => 'Prize breakup must be a non-empty list.'];
    }

    $rows = [];
    foreach ($breakup as $row) {
        if (!is_array($row)) return ['ok' => false, 'error' => 'Each prize row must be an object.'];
        $from = isset($row['from']) ? $row['from'] : null;
        $to   = isset($row['to'])   ? $row['to']   : null;
        $pct  = isset($row['pct'])  ? $row['pct']  : null;

        // Integer ranks, checked strictly: "1.5" as a rank is a config mistake, not something to
        // round into place.
        if (!is_numeric($from) || !is_numeric($to) || (int) $from != $from || (int) $to != $to
            || (int) $from < 1 || (int) $to < (int) $from) {
            return ['ok' => false,
                    'error' => 'Each prize row needs integer from/to with from >= 1 and to >= from.'];
        }
        if (!is_numeric($pct) || (float) $pct <= 0) {
            return ['ok' => false, 'error' => 'Each prize row needs a positive pct.'];
        }
        $rows[] = ['from' => (int) $from, 'to' => (int) $to, 'pct' => (float) $pct];
    }

    usort($rows, function ($a, $b) { return $a['from'] - $b['from']; });

    if ($rows[0]['from'] !== 1) {
        return ['ok' => false, 'error' => 'Prize breakup must start at rank 1.'];
    }

    for ($i = 1; $i < count($rows); $i++) {
        $expected = $rows[$i - 1]['to'] + 1;
        if ($rows[$i]['from'] !== $expected) {
            $what = $rows[$i]['from'] > $expected ? 'missing' : 'covered twice';
            return ['ok' => false,
                    'error' => 'Prize breakup must be contiguous: rank ' . $expected . ' is ' . $what . '.'];
        }
    }

    $total = 0.0;
    foreach ($rows as $r) $total += ($r['to'] - $r['from'] + 1) * $r['pct'];
    if (abs($total - 100) > 0.01) {
        return ['ok' => false,
                'error' => 'Prize percentages must total 100 (got ' . number_format($total, 2) . ').'];
    }

    // A table paying ranks 1-50 in a ten-spot contest advertises prizes that can never be awarded,
    // and the unpaid share would silently stay with the house.
    if ($totalSpots !== null) {
        $deepest = $rows[count($rows) - 1]['to'];
        if ($deepest > (int) $totalSpots) {
            return ['ok' => false,
                    'error' => 'Prize breakup pays down to rank ' . $deepest . ' but the contest only has '
                             . (int) $totalSpots . ' spots.'];
        }
    }

    return ['ok' => true, 'breakup' => $rows, 'total_pct' => round($total, 2)];
}

/** The percentage won by one rank, or 0 for a rank outside the paid places. */
function fantasy_pct_for_rank($breakup, $rank) {
    foreach ((array) $breakup as $row) {
        if ($rank >= (int) $row['from'] && $rank <= (int) $row['to']) return (float) $row['pct'];
    }
    return 0.0;
}

/**
 * gross -> rake -> prize pool.
 *
 * rake_pct is operator configuration and the platform's only revenue on this game, so it is read
 * off the contest row rather than being a constant.
 */
function fantasy_compute_pool($entryFee, $entrants, $rakePct) {
    $gross = round(((float) $entryFee) * ((int) $entrants), 2);
    $rake  = round($gross * (((float) $rakePct) / 100), 2);
    return ['gross_pool' => $gross, 'rake' => $rake, 'prize_pool' => round($gross - $rake, 2)];
}

// -------------------------------------------------------------------------------------------------
// Creation (operator)
// -------------------------------------------------------------------------------------------------

/**
 * Create one contest on a match. Operator action — see the admin route.
 *
 * Deliberately not automatic. Contests carry real entry fees and a rake, so which ones exist on a
 * fixture is an operator decision rather than something a scraper's cron invents, in the same spirit
 * as the rest of this platform keeping tunable money numbers behind an explicit operator edit.
 */
function fantasy_create_contest($matchId, array $spec) {
    $match = fantasy_find_match((int) $matchId);
    if (!$match) return ['ok' => false, 'error' => 'Match not found.', 'status' => 404];

    $title = trim((string) ($spec['title'] ?? ''));
    if ($title === '' || strlen($title) > 60) {
        return ['ok' => false, 'error' => 'A contest needs a title of 1-60 characters.', 'status' => 422];
    }

    $entryFee = (float) ($spec['entry_fee'] ?? 0);
    if (!is_finite($entryFee) || $entryFee < 0 || $entryFee > 1000000) {
        return ['ok' => false, 'error' => 'entry_fee must be between 0 and 1,000,000.', 'status' => 422];
    }

    $totalSpots = (int) ($spec['total_spots'] ?? 0);
    if ($totalSpots < 2 || $totalSpots > 1000000) {
        return ['ok' => false, 'error' => 'total_spots must be between 2 and 1,000,000.', 'status' => 422];
    }

    $rakePct = (float) ($spec['rake_pct'] ?? 0);
    if (!is_finite($rakePct) || $rakePct < 0 || $rakePct > 50) {
        return ['ok' => false, 'error' => 'rake_pct must be between 0 and 50.', 'status' => 422];
    }

    // A guaranteed pool is money the house underwrites: if entries do not cover it, the shortfall
    // comes out of the operator's pocket. Allowed, but it must be an explicit number, never a
    // default.
    $guaranteed = (float) ($spec['prize_pool'] ?? 0);
    if (!is_finite($guaranteed) || $guaranteed < 0) {
        return ['ok' => false, 'error' => 'prize_pool (guaranteed) cannot be negative.', 'status' => 422];
    }

    $type = strtolower(trim((string) ($spec['contest_type'] ?? 'custom')));
    if (!in_array($type, ['mega', 'h2h', 'small', 'winner_takes_all', 'practice', 'custom'], true)) $type = 'custom';
    $maxEntries = (int) ($spec['max_entries_per_user'] ?? 1);
    if ($maxEntries < 1 || $maxEntries > 20) {
        return ['ok' => false, 'error' => 'max_entries_per_user must be between 1 and 20.', 'status' => 422];
    }
    $isGuaranteed = !empty($spec['is_guaranteed']) || $guaranteed > 0;

    // A practice contest is free and pays nothing - the established apps offer one on every match so a
    // newcomer can learn the game without risking money. It is the only contest allowed no prize table.
    if ($type === 'practice') {
        if ($entryFee > 0) return ['ok' => false, 'error' => 'A practice contest must be free.', 'status' => 422];
        $prize = ['ok' => true, 'breakup' => []];
    } else {
        $prize = fantasy_validate_prize_breakup($spec['prize_rules'] ?? null, $totalSpots);
        if (!$prize['ok']) return ['ok' => false, 'error' => $prize['error'], 'status' => 422];
    }

    // How many entries this contest needs before it may pay out. Below it, settlement refunds
    // everyone instead (see fantasy_settle_contest).
    //
    // Left unset, it defaults to the deepest rank the prize table pays — the exact number of entries
    // at which every advertised prize can actually be awarded. Quoting prizes down to rank 60 and then
    // paying out on eight entries means the other ranks' share is never awarded and quietly stays
    // with the house, which is what this default prevents. An operator can pass any other number,
    // including 0 to let a contest pay out however few enter.
    $deepestPaidRank = 0;
    foreach ($prize['breakup'] as $row) {
        if ((int) $row['to'] > $deepestPaidRank) $deepestPaidRank = (int) $row['to'];
    }
    $minEntries = array_key_exists('min_entries', $spec) && $spec['min_entries'] !== null
        ? (int) $spec['min_entries']
        : $deepestPaidRank;
    if ($minEntries < 0 || $minEntries > $totalSpots) {
        return ['ok' => false, 'status' => 422,
                'error' => 'min_entries must be between 0 and total_spots (' . $totalSpots . ').'];
    }

    if ($type === 'practice') $minEntries = 0;
    $templateKey = isset($spec['template_key']) && $spec['template_key'] !== '' ? (string) $spec['template_key'] : null;

    q('INSERT INTO "fantasy_contests" ("match_id","title","entry_fee","total_spots","filled_spots",'
      . '"prize_pool","prize_rules","rake_pct","status","min_entries","max_entries_per_user","contest_type",'
      . '"is_guaranteed","template_key","created_at") '
      . 'VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
      [(int) $matchId, $title, $entryFee, $totalSpots, 0, $guaranteed,
       json_encode($prize['breakup']), $rakePct, 'OPEN', $minEntries, $maxEntries, $type,
       $isGuaranteed ? 1 : 0, $templateKey, ms_to_sql(now_ms())]);

    $id = (int) db_or_throw()->lastInsertId();
    return ['ok' => true, 'contest_id' => $id];
}

// -------------------------------------------------------------------------------------------------
// Reads
// -------------------------------------------------------------------------------------------------

/** Decode a stored prize table, tolerating a row that predates validation. */
function fantasy_contest_breakup(array $row) {
    $decoded = json_decode((string) ($row['prize_rules'] ?? '[]'), true);
    return is_array($decoded) ? $decoded : [];
}

/**
 * Shape a contest row for the client, with the pool maths done.
 *
 * Two pool figures are reported because they answer different questions: `prize_pool` is what is
 * currently on the table (entries collected, less rake, floored at any guarantee), and
 * `max_prize_pool` is what it becomes if the contest fills. Showing only the first makes an empty
 * contest look worthless; showing only the second advertises money that may never exist.
 */
function fantasy_contest_public(array $row, $entered = false, $myEntries = null) {
    $entryFee   = (float) $row['entry_fee'];
    $filled     = (int) $row['filled_spots'];
    $totalSpots = (int) $row['total_spots'];
    $rakePct    = (float) $row['rake_pct'];
    $guaranteed = (float) $row['prize_pool'];
    $breakup    = fantasy_contest_breakup($row);

    $now  = fantasy_compute_pool($entryFee, $filled, $rakePct);
    $full = fantasy_compute_pool($entryFee, $totalSpots, $rakePct);

    $current = max($now['prize_pool'], $guaranteed);
    $atFull  = max($full['prize_pool'], $guaranteed);

    return [
        'id'             => (int) $row['id'],
        'match_id'       => (int) $row['match_id'],
        'title'          => (string) $row['title'],
        'entry_fee'      => round($entryFee, 2),
        'total_spots'    => $totalSpots,
        'filled_spots'   => $filled,
        'spots_left'     => max(0, $totalSpots - $filled),
        // Below this many entries the contest refunds instead of paying out, so the lobby can say so
        // before someone joins rather than after.
        'min_entries'    => (int) ($row['min_entries'] ?? 0),
        'meets_minimum'  => $filled >= (int) ($row['min_entries'] ?? 0),
        'rake_pct'       => round($rakePct, 2),
        'guaranteed_pool' => round($guaranteed, 2),
        'prize_pool'     => round($current, 2),
        'max_prize_pool' => round($atFull, 2),
        // What rank 1 takes if the contest fills — the headline number a lobby card shows.
        'first_prize'    => round($atFull * (fantasy_pct_for_rank($breakup, 1) / 100), 2),
        'prize_rules'    => $breakup,
        'status'         => (string) $row['status'],
        'is_full'        => $filled >= $totalSpots,
        'has_entered'    => (bool) $entered,
        'my_entries'     => $myEntries === null ? ($entered ? 1 : 0) : (int) $myEntries,
        'max_entries_per_user' => (int) ($row['max_entries_per_user'] ?? 1),
        'contest_type'   => (string) ($row['contest_type'] ?? 'custom'),
        // Guaranteed: runs and pays whatever the turnout. Flexible: refunded in full if fewer than
        // min_entries join - the wording the established apps use on every contest card.
        'is_guaranteed'  => ((int) ($row['is_guaranteed'] ?? 0)) === 1 || $guaranteed > 0,
        'winners'        => fantasy_breakup_winners($breakup),
        'is_practice'    => (string) ($row['contest_type'] ?? '') === 'practice',
    ];
}

/** How many ranks a prize table pays. */
function fantasy_breakup_winners($breakup) {
    $deepest = 0;
    foreach ((array) $breakup as $row) if ((int) ($row['to'] ?? 0) > $deepest) $deepest = (int) $row['to'];
    return $deepest;
}

/** Contests on a match, biggest prize first and practice last, flagged with the caller's entries. */
function fantasy_list_contests($matchId, $userId = null) {
    $rows = all('SELECT * FROM "fantasy_contests" WHERE "match_id" = ? ORDER BY "entry_fee" ASC, "id" ASC',
                [(int) $matchId]);

    $mine = [];
    if ($userId) {
        $entered = all(
            'SELECT e."contest_id", COUNT(*) AS "n" FROM "fantasy_contest_entries" e '
            . 'JOIN "fantasy_contests" c ON c."id" = e."contest_id" '
            . 'WHERE e."user_id" = ? AND c."match_id" = ? GROUP BY e."contest_id"',
            [(int) $userId, (int) $matchId]
        );
        foreach ($entered as $e) $mine[(int) $e['contest_id']] = (int) $e['n'];
    }

    $out = [];
    foreach ($rows as $r) {
        $n = $mine[(int) $r['id']] ?? 0;
        $out[] = fantasy_contest_public($r, $n > 0, $n);
    }
    usort($out, function ($a, $b) {
        if ($a['is_practice'] !== $b['is_practice']) return $a['is_practice'] ? 1 : -1;
        return ($b['max_prize_pool'] <=> $a['max_prize_pool']) ?: ($a['id'] <=> $b['id']);
    });
    return $out;
}

/** The caller's entries, newest first, optionally for one match. */
function fantasy_my_entries($userId, $matchId = null) {
    $sql = 'SELECT e.*, c."title", c."match_id", c."entry_fee" AS "fee", m."match_title", m."series_name" '
         . 'FROM "fantasy_contest_entries" e '
         . 'JOIN "fantasy_contests" c ON c."id" = e."contest_id" '
         . 'JOIN "fantasy_matches" m ON m."id" = c."match_id" '
         . 'WHERE e."user_id" = ?';
    $args = [(int) $userId];
    if ($matchId !== null) { $sql .= ' AND c."match_id" = ?'; $args[] = (int) $matchId; }
    $sql .= ' ORDER BY e."id" DESC LIMIT 200';

    $rows = all($sql, $args);
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'id'             => (int) $r['id'],
            'contest_id'     => (int) $r['contest_id'],
            'contest_title'  => (string) $r['title'],
            'match_id'       => (int) $r['match_id'],
            'match_title'    => (string) $r['match_title'],
            'series_name'    => (string) $r['series_name'],
            'user_team_id'   => (int) $r['user_team_id'],
            'entry_fee_paid' => (float) $r['entry_fee_paid'],
            'txn_id'         => $r['txn_id'],
            'points'         => (float) $r['points'],
            'rank'           => $r['rank'] === null ? null : (int) $r['rank'],
            'prize_won'      => (float) $r['prize_won'],
            'is_settled'     => ((int) $r['is_settled']) === 1,
            'created_at'     => $r['created_at'],
        ];
    }
    return $out;
}

// -------------------------------------------------------------------------------------------------
// Joining — the money path
// -------------------------------------------------------------------------------------------------

/** Is this PDO error a unique-constraint violation? */
function fantasy_is_unique_violation(Throwable $e) {
    if ($e instanceof PDOException) {
        // 23505 is PostgreSQL's unique_violation; 23000/1062 is MySQL's.
        $sqlState = isset($e->errorInfo[0]) ? (string) $e->errorInfo[0] : (string) $e->getCode();
        if ($sqlState === '23505' || $sqlState === '23000') return true;
    }
    $m = strtolower($e->getMessage());
    return strpos($m, 'duplicate key') !== false || strpos($m, 'unique constraint') !== false
        || strpos($m, 'duplicate entry') !== false;
}

/**
 * Enter one of the caller's teams into a contest, paying the entry fee.
 *
 * Returns ['ok' => true, 'entry_id' => int, 'new_balance' => float, ...] or
 * ['ok' => false, 'error' => 'sentence', 'status' => int].
 *
 * Order inside the transaction is chosen so the cheapest rejections happen before any money is
 * touched, and so the two things that can only be decided atomically are decided by the database:
 *
 *   1. re-read the contest and match and re-check every precondition (the deadline can pass between
 *      a player tapping Join and this running)
 *   2. claim a spot with a conditional UPDATE      <- serialises the race for the last spot
 *   3. debit the wallet with the conditional debit  <- serialises the race against the balance
 *   4. write the ledger row
 *   5. insert the entry row                          <- UNIQUE (contest_id, user_id) stops double entry
 *
 * Any throw rolls all five back together.
 */
function fantasy_join_contest($userId, $username, $contestId, $teamId) {
    $userId    = (int) $userId;
    $contestId = (int) $contestId;
    $teamId    = (int) $teamId;

    $contest = one('SELECT * FROM "fantasy_contests" WHERE "id" = ?', [$contestId]);
    if (!$contest) return ['ok' => false, 'error' => 'Contest not found.', 'status' => 404];

    $match = fantasy_find_match((int) $contest['match_id']);
    if (!$match) return ['ok' => false, 'error' => 'Match not found.', 'status' => 404];

    // The team must be the caller's own AND be built for this contest's match — entering a team
    // assembled from another fixture's squad would be a team that cannot score.
    $team = one('SELECT "id","match_id" FROM "fantasy_user_teams" WHERE "id" = ? AND "user_id" = ?',
                [$teamId, $userId]);
    if (!$team) return ['ok' => false, 'error' => 'Team not found.', 'status' => 404];
    if ((int) $team['match_id'] !== (int) $contest['match_id']) {
        return ['ok' => false, 'error' => 'That team was built for a different match.', 'status' => 422];
    }

    if (strtoupper((string) $contest['status']) !== 'OPEN') {
        return ['ok' => false, 'error' => 'This contest is closed.', 'status' => 409];
    }
    $open = fantasy_match_open_for_entry($match);
    if (!$open['ok']) return ['ok' => false, 'error' => $open['error'], 'status' => 409];

    $fee = round((float) $contest['entry_fee'], 2);
    $title = (string) $contest['title'];

    try {
        $result = tx(function () use ($userId, $username, $contestId, $teamId, $fee, $title, $match) {
            // 1. Re-read under the transaction. Checking only before it leaves a window in which the
            //    deadline passes, the contest fills, or an operator closes it.
            // The contest row is LOCKED: that serialises every join on this contest, which is what
            // makes the per-player entry cap below exact - two quick taps cannot both see "one entry
            // left" and both get in.
            $c = one('SELECT * FROM "fantasy_contests" WHERE "id" = ? FOR UPDATE', [$contestId]);
            if (!$c) throw new RuntimeException('Contest not found.');
            if (strtoupper((string) $c['status']) !== 'OPEN') throw new RuntimeException('This contest is closed.');

            $m = one('SELECT "status","lock_time" FROM "fantasy_matches" WHERE "id" = ?',
                     [(int) $c['match_id']]);
            if (!$m) throw new RuntimeException('Match not found.');
            $reopen = fantasy_match_open_for_entry($m);
            if (!$reopen['ok']) throw new RuntimeException($reopen['error']);

            // 1b. Multi-entry: a player may enter up to max_entries_per_user DIFFERENT teams. The same
            //     team twice is stopped by UNIQUE (contest_id, user_team_id).
            $maxPer = max(1, (int) ($c['max_entries_per_user'] ?? 1));
            $already = (int) scalar('SELECT COUNT(*) FROM "fantasy_contest_entries" WHERE "contest_id" = ? AND "user_id" = ?',
                                    [$contestId, $userId], 0);
            if ($already >= $maxPer) {
                throw new RuntimeException($maxPer === 1 ? 'You have already joined this contest.'
                    : 'You have already joined this contest with the maximum of ' . $maxPer . ' teams.');
            }

            // 2. Claim a spot. Conditional UPDATE, so two joins racing for the last spot are
            //    serialised by the row lock and the loser sees the contest full.
            $claimed = affected(
                'UPDATE "fantasy_contests" SET "filled_spots" = "filled_spots" + 1 '
                . 'WHERE "id" = ? AND "status" = ? AND "filled_spots" < "total_spots"',
                [$contestId, 'OPEN']
            );
            if ($claimed !== 1) throw new RuntimeException('This contest is full.');

            // 3. Take the money, using the platform's conditional debit so the balance check and the
            //    deduction are one statement. A free contest skips the wallet entirely rather than
            //    debiting zero and writing a meaningless ledger row.
            $newBalance = null;
            $txnId = null;
            if ($fee > 0) {
                $newBalance = debit_wallet($userId, $fee);
                if ($newBalance === null) throw new RuntimeException('Insufficient balance for this entry fee.');

                // 4. Ledger. Inside the transaction, so a failure here rolls the debit back too —
                //    there is deliberately no hand-written refund, because there is no window in
                //    which one would be needed.
                $txnId = new_record_id('Y11');
                insert_transaction($txnId, $username, 'Withdrawal', $fee,
                    'Your Eleven Entry — ' . $title . ' (' . (string) $match['match_title'] . ')',
                    'Completed');
            } else {
                $u = find_user_by_id($userId);
                $newBalance = $u ? (float) $u['wallet_balance'] : null;
            }

            // 5. The entry itself. UNIQUE (contest_id, user_id) from migration 007 is what makes a
            //    second entry by the same account impossible, including from two simultaneous
            //    requests that both saw "not entered yet".
            q('INSERT INTO "fantasy_contest_entries" ("contest_id","user_team_id","user_id",'
              . '"entry_fee_paid","txn_id","created_at") VALUES (?,?,?,?,?,?)',
              [$contestId, $teamId, $userId, $fee, $txnId, ms_to_sql(now_ms())]);

            return ['entry_id' => (int) db_or_throw()->lastInsertId(), 'new_balance' => $newBalance];
        });
    } catch (Throwable $e) {
        if (fantasy_is_unique_violation($e)) {
            return ['ok' => false, 'error' => 'That team is already in this contest - pick a different team.', 'status' => 409];
        }
        $msg = $e->getMessage();
        $status = (stripos($msg, 'insufficient') !== false) ? 402 : 409;
        return ['ok' => false, 'error' => $msg, 'status' => $status];
    }

    return [
        'ok'          => true,
        'entry_id'    => $result['entry_id'],
        'new_balance' => $result['new_balance'],
        'entry_fee'   => $fee,
    ];
}

/**
 * Swap the team on one of the caller's entries, before the deadline - the "switch team" action the
 * established apps offer. No money moves: the entry, its fee and its spot stay exactly as they were;
 * only which of the caller's own XIs it points at changes.
 */
function fantasy_switch_entry_team($userId, $entryId, $teamId) {
    $userId = (int) $userId; $entryId = (int) $entryId; $teamId = (int) $teamId;
    $entry = one('SELECT e.*, c."match_id", c."status" AS "contest_status" FROM "fantasy_contest_entries" e '
               . 'JOIN "fantasy_contests" c ON c."id" = e."contest_id" WHERE e."id" = ? AND e."user_id" = ?',
               [$entryId, $userId]);
    if (!$entry) return ['ok' => false, 'error' => 'Entry not found.', 'status' => 404];
    $match = fantasy_find_match((int) $entry['match_id']);
    $open = $match ? fantasy_match_open_for_entry($match) : ['ok' => false, 'error' => 'Match not found.'];
    if (!$open['ok']) return ['ok' => false, 'error' => $open['error'], 'status' => 409];
    if (strtoupper((string) $entry['contest_status']) !== 'OPEN') return ['ok' => false, 'error' => 'This contest is closed.', 'status' => 409];
    $team = one('SELECT "id","match_id" FROM "fantasy_user_teams" WHERE "id" = ? AND "user_id" = ?', [$teamId, $userId]);
    if (!$team) return ['ok' => false, 'error' => 'Team not found.', 'status' => 404];
    if ((int) $team['match_id'] !== (int) $entry['match_id']) return ['ok' => false, 'error' => 'That team was built for a different match.', 'status' => 422];
    if ((int) $entry['user_team_id'] === $teamId) return ['ok' => true, 'unchanged' => true];
    try {
        q('UPDATE "fantasy_contest_entries" SET "user_team_id" = ? WHERE "id" = ?', [$teamId, $entryId]);
    } catch (Throwable $e) {
        if (fantasy_is_unique_violation($e)) return ['ok' => false, 'error' => 'That team is already in this contest.', 'status' => 409];
        throw $e;
    }
    return ['ok' => true];
}
