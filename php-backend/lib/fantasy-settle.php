<?php
/**
 * "Your Eleven" — leaderboards, ranking, prize allocation and settlement.
 *
 * =================================================================================================
 * THE TIE RULE, AND WHY IT IS THE WAY IT IS
 * =================================================================================================
 * Ported from rankEntries()/allocatePrizes() in backend/lib/cricket/contests.js.
 *
 * Ranks skip on a tie the way sport does: two entries level on the second-highest score are both
 * 2nd, and nobody is 3rd. Tied entries then POOL the prize money for every slot they occupy and split
 * it evenly — three entries tied on rank 2 pool the prizes for ranks 2, 3 and 4.
 *
 * Pooling the slots is not a stylistic choice. Paying each tied entry the prize for the shared rank
 * would pay out MORE than the pool (three entries each taking the 2nd-place prize), and paying only
 * one of them would be arbitrary. Pooling is the only rule under which total payout still equals what
 * was collected, which is the invariant the whole settlement rests on and which the test suite
 * asserts directly.
 *
 * The split is floored to the paisa and the undividable remainder goes to the lowest-numbered rank in
 * the group, so the total is exact rather than a rounding drift.
 *
 * =================================================================================================
 * SETTLEMENT SAFETY
 * =================================================================================================
 *  - It runs only when the SOURCE says the match is over. Not when a start time has passed, not when
 *    a clock says the innings should be done: settlement needs evidence, and "the scraper reported
 *    Complete" is that evidence. An operator can force past this, and that is recorded as a forced
 *    settle rather than looking like a normal one.
 *  - Each contest is claimed with a conditional UPDATE on its status, so two workers racing cannot
 *    both settle it. That claim IS the idempotency guard: the second attempt finds nothing to claim.
 *  - The whole settlement of one contest — the claim, every credit, every ledger row, every entry
 *    update — happens inside one transaction. A partially paid contest is not a state this can reach.
 *  - Winnings use the platform's existing credit_wallet() and insert_transaction(), so the ledger,
 *    the cashier and the admin revenue figures stay complete.
 */

require_once __DIR__ . '/fantasy.php';
require_once __DIR__ . '/fantasy-contests.php';
require_once __DIR__ . '/fantasy-source.php';

/** Floor to two decimals. Used for prize splits so a tie can never pay out more than the pool. */
function fantasy_floor2($n) {
    return floor(((float) $n) * 100) / 100;
}

// -------------------------------------------------------------------------------------------------
// Ranking
// -------------------------------------------------------------------------------------------------

/**
 * Sort entries and assign ranks, ties sharing a rank and the next rank skipping accordingly.
 *
 * Points are compared with a tolerance because they are sums of one-decimal values in floating point;
 * two entries that should be level can otherwise differ in the fifteenth decimal place and be ranked
 * apart, which would hand one of them a prize the other deserved equally.
 *
 * The earlier entry sorts first within a tie. That does not change anyone's RANK, only the order they
 * are listed and which of them collects the undividable remainder — and rewarding the entry that
 * committed first is the least arbitrary way to break that.
 */
function fantasy_rank_entries(array $entries) {
    $sorted = $entries;
    usort($sorted, function ($a, $b) {
        $pa = (float) ($a['points'] ?? 0);
        $pb = (float) ($b['points'] ?? 0);
        if (abs($pa - $pb) > 0.0001) return ($pb > $pa) ? 1 : -1;
        $ca = (string) ($a['created_at'] ?? '');
        $cb = (string) ($b['created_at'] ?? '');
        if ($ca !== $cb) return strcmp($ca, $cb);
        // Final tiebreak on id, so the order is stable and a re-run ranks identically.
        return ((int) ($a['id'] ?? 0)) - ((int) ($b['id'] ?? 0));
    });

    $out = [];
    $lastPoints = null;
    $lastRank = 0;
    foreach ($sorted as $i => $entry) {
        $points = (float) ($entry['points'] ?? 0);
        $rank = ($lastPoints !== null && abs($points - $lastPoints) <= 0.0001) ? $lastRank : ($i + 1);
        $lastPoints = $points;
        $lastRank = $rank;
        $entry['points'] = $points;
        $entry['rank'] = $rank;
        $out[] = $entry;
    }
    return $out;
}

/**
 * Split a prize pool across ranked entries.
 *
 * Returns the entries with 'rank' and 'prize' set, ordered by rank.
 */
function fantasy_allocate_prizes(array $entries, array $breakup, $prizePool) {
    $ranked = fantasy_rank_entries($entries);
    $pool = (float) $prizePool;
    if ($pool <= 0) {
        foreach ($ranked as &$e) $e['prize'] = 0.0;
        return $ranked;
    }

    // Group by rank, preserving the order within each group.
    $groups = [];
    foreach ($ranked as $e) {
        $groups[$e['rank']][] = $e;
    }

    // Every band is FLOORED to the paisa — never rounded up — and the few paise that leaves over go to
    // the top-ranked winner at the end. Rounding each band to the nearest paisa looked harmless, but
    // across a 50-rank prize table the half-paisa gains add up: the road test caught a ₹4,067.25 pool
    // paying out ₹4,067.33. With flooring the total paid equals the pool exactly and can never exceed it.
    $out = [];
    $exact = 0.0; $paid = 0.0;
    foreach ($groups as $rank => $group) {
        $size = count($group);

        // The slots this tie consumes: a three-way tie on rank 2 occupies ranks 2, 3 and 4.
        $slotTotal = 0.0;
        for ($slot = $rank; $slot < $rank + $size; $slot++) {
            $slotTotal += $pool * (fantasy_pct_for_rank($breakup, $slot) / 100);
        }

        if ($slotTotal <= 0) {
            foreach ($group as $e) { $e['prize'] = 0.0; $out[] = $e; }
            continue;
        }

        $exact += $slotTotal;
        $groupTotal = fantasy_floor2($slotTotal);
        $each = fantasy_floor2($groupTotal / $size);
        $remainder = round($groupTotal - ($each * $size), 2);
        foreach ($group as $i => $e) {
            $e['prize'] = round($i === 0 ? $each + $remainder : $each, 2);
            $paid += $e['prize'];
            $out[] = $e;
        }
    }
    $leftover = round(fantasy_floor2($exact + 1e-7) - $paid, 2);
    if ($leftover > 0 && $out) {
        $best = null;
        foreach ($out as $i => $e) if ($e['prize'] > 0 && ($best === null || $e['rank'] < $out[$best]['rank'])) $best = $i;
        if ($best !== null) $out[$best]['prize'] = round($out[$best]['prize'] + $leftover, 2);
    }

    usort($out, function ($a, $b) {
        if ($a['rank'] !== $b['rank']) return $a['rank'] - $b['rank'];
        return strcmp((string) ($a['created_at'] ?? ''), (string) ($b['created_at'] ?? ''));
    });
    return $out;
}

// -------------------------------------------------------------------------------------------------
// Pool
// -------------------------------------------------------------------------------------------------

/**
 * The money actually available to a contest.
 *
 * Gross is the sum of what was ACTUALLY paid in, read from the entries, not entry_fee x filled_spots.
 * The two should agree, but if they ever diverge — a fee edited after some entries were taken, a spot
 * counter that drifted — the entries are the record of real money and the fee column is not.
 *
 * A guaranteed pool floors the result: the operator underwrites the difference.
 */
function fantasy_contest_pool(array $contest) {
    $collected = (float) scalar(
        'SELECT COALESCE(SUM("entry_fee_paid"), 0) FROM "fantasy_contest_entries" WHERE "contest_id" = ?',
        [(int) $contest['id']], 0
    );
    $rakePct = (float) $contest['rake_pct'];
    $rake = round($collected * ($rakePct / 100), 2);
    $prize = round($collected - $rake, 2);
    $guaranteed = (float) $contest['prize_pool'];

    return [
        'collected'  => round($collected, 2),
        'rake'       => $rake,
        'prize_pool' => max($prize, $guaranteed),
        'guaranteed' => $guaranteed,
        // True when the house is topping the pool up out of its own pocket.
        'underwritten' => $guaranteed > $prize,
    ];
}

// -------------------------------------------------------------------------------------------------
// Leaderboard
// -------------------------------------------------------------------------------------------------

/**
 * A contest's standings.
 *
 * Before settlement this is provisional and computed on the fly from each entry's stored points;
 * afterwards the stored rank and prize are authoritative and are returned as-is, so a leaderboard
 * never disagrees with what was actually paid.
 */
function fantasy_contest_leaderboard($contestId, $limit = 100) {
    $contestId = (int) $contestId;
    $contest = one('SELECT * FROM "fantasy_contests" WHERE "id" = ?', [$contestId]);
    if (!$contest) return null;

    $rows = all(
        'SELECT e."id", e."user_id", e."user_team_id", e."points", e."rank", e."prize_won", '
        . 'e."is_settled", e."created_at", t."team_name", u."username" '
        . 'FROM "fantasy_contest_entries" e '
        . 'JOIN "fantasy_user_teams" t ON t."id" = e."user_team_id" '
        . 'JOIN "User" u ON u."id" = e."user_id" '
        . 'WHERE e."contest_id" = ?',
        [$contestId]
    );

    $settled = strtoupper((string) $contest['status']) === 'SETTLED';
    $pool = fantasy_contest_pool($contest);
    $breakup = fantasy_contest_breakup($contest);

    $entries = [];
    foreach ($rows as $r) {
        $entries[] = [
            'id' => (int) $r['id'], 'user_id' => (int) $r['user_id'],
            'username' => (string) $r['username'], 'team_id' => (int) $r['user_team_id'],
            'team_name' => (string) $r['team_name'], 'points' => (float) $r['points'],
            'created_at' => (string) $r['created_at'],
            'stored_rank' => $r['rank'] === null ? null : (int) $r['rank'],
            'stored_prize' => (float) $r['prize_won'],
            'is_settled' => ((int) $r['is_settled']) === 1,
        ];
    }

    if ($settled) {
        // Trust what was paid.
        usort($entries, function ($a, $b) {
            $ra = $a['stored_rank'] === null ? PHP_INT_MAX : $a['stored_rank'];
            $rb = $b['stored_rank'] === null ? PHP_INT_MAX : $b['stored_rank'];
            if ($ra !== $rb) return $ra - $rb;
            return strcmp($a['created_at'], $b['created_at']);
        });
        $ranked = [];
        foreach ($entries as $e) {
            $e['rank'] = $e['stored_rank'];
            $e['prize'] = $e['stored_prize'];
            $ranked[] = $e;
        }
    } else {
        $ranked = fantasy_allocate_prizes($entries, $breakup, $pool['prize_pool']);
    }

    return [
        'contest'  => fantasy_contest_public($contest),
        'pool'     => $pool,
        'is_settled' => $settled,
        'provisional' => !$settled,
        'entries'  => array_slice($ranked, 0, max(1, min(500, (int) $limit))),
        'total_entries' => count($ranked),
    ];
}

// -------------------------------------------------------------------------------------------------
// Settlement
// -------------------------------------------------------------------------------------------------

/**
 * Settle one contest: rank the entries, allocate the pool, credit the winners, close the contest.
 *
 * $force lets an operator settle without the source having reported the match finished. It is a
 * deliberate, recorded act: the ledger detail says so, so a forced settle can be told apart from a
 * normal one afterwards.
 *
 * Returns ['ok' => true, 'paid' => float, 'winners' => int, 'undistributed' => float, ...].
 */
function fantasy_settle_contest($contestId, $force = false) {
    $contestId = (int) $contestId;

    $contest = one('SELECT * FROM "fantasy_contests" WHERE "id" = ?', [$contestId]);
    if (!$contest) return ['ok' => false, 'error' => 'Contest not found.', 'status' => 404];

    if (strtoupper((string) $contest['status']) === 'SETTLED') {
        // Not an error. Settling twice is exactly what a retried worker does, and the right answer is
        // to report that it is already done rather than to pay again.
        return ['ok' => true, 'already_settled' => true, 'paid' => 0.0, 'winners' => 0,
                'undistributed' => 0.0];
    }
    if (strtoupper((string) $contest['status']) === 'CANCELLED') {
        return ['ok' => false, 'error' => 'This contest was cancelled.', 'status' => 409];
    }

    $match = fantasy_find_match((int) $contest['match_id']);
    if (!$match) return ['ok' => false, 'error' => 'Match not found.', 'status' => 404];

    // The gate: evidence, not a clock.
    $sourceFinal = fantasy_state_is_final($match['source_state'] ?? null);
    if (!$sourceFinal && !$force) {
        return ['ok' => false, 'status' => 409,
                'error' => 'The match is not reported finished yet (source state: '
                         . (($match['source_state'] ?? '') === '' ? 'unknown' : $match['source_state'])
                         . '). Settle with force=1 to override.'];
    }

    // A match that ended without a result is never ranked: every contest on it is cancelled and every
    // entry fee refunded in full. Checked before anything else, and regardless of $force, because no
    // operator override can turn a washout into a result.
    if (fantasy_state_is_abandoned($match['source_state'] ?? null)) {
        $void = fantasy_void_contest($contestId, 'match abandoned / no result');
        if (!$void['ok']) return $void;
        return [
            'ok' => true, 'voided' => true, 'abandoned' => true,
            'entries' => (int) ($void['entries'] ?? 0), 'refunded' => (float) ($void['refunded'] ?? 0),
            'paid' => 0.0, 'winners' => 0, 'undistributed' => 0.0,
            'reason' => 'refunded: the match ended without a result',
        ];
    }

    $pool = fantasy_contest_pool($contest);
    $breakup = fantasy_contest_breakup($contest);

    $rows = all(
        'SELECT e."id", e."user_id", e."points", e."created_at", u."username" '
        . 'FROM "fantasy_contest_entries" e JOIN "User" u ON u."id" = e."user_id" '
        . 'WHERE e."contest_id" = ?',
        [$contestId]
    );
    if (!$rows) {
        // Nothing was collected and nothing is owed; close it so it stops being polled.
        q('UPDATE "fantasy_contests" SET "status" = ? WHERE "id" = ? AND "status" <> ?',
          ['SETTLED', $contestId, 'SETTLED']);
        return ['ok' => true, 'paid' => 0.0, 'winners' => 0, 'undistributed' => 0.0, 'entries' => 0];
    }

    // ---------------------------------------------------------------------------------------------
    // Minimum participation. Below it the prize table cannot be honoured — the deeper ranks never
    // existed, so their share of the pool would never be awarded and would quietly stay with the
    // house. The operator's policy is to give the money back instead.
    //
    // Delegated to fantasy_void_contest() rather than reimplemented, so every refund in this module
    // goes through exactly one code path and the conditional status claim keeps it idempotent.
    // ---------------------------------------------------------------------------------------------
    $minEntries = (int) ($contest['min_entries'] ?? 0);
    if ($minEntries > 0 && count($rows) < $minEntries) {
        $void = fantasy_void_contest($contestId,
            'only ' . count($rows) . ' of the ' . $minEntries . ' entries needed');
        if (!$void['ok']) return $void;
        return [
            'ok' => true, 'voided' => true,
            'entries' => count($rows), 'refunded' => $void['refunded'],
            'paid' => 0.0, 'winners' => 0, 'undistributed' => 0.0,
            'reason' => 'refunded: ' . count($rows) . ' entries, minimum is ' . $minEntries,
        ];
    }

    $entries = [];
    foreach ($rows as $r) {
        $entries[] = ['id' => (int) $r['id'], 'user_id' => (int) $r['user_id'],
                      'username' => (string) $r['username'], 'points' => (float) $r['points'],
                      'created_at' => (string) $r['created_at']];
    }

    $allocated = fantasy_allocate_prizes($entries, $breakup, $pool['prize_pool']);
    $title = (string) $contest['title'];
    $matchTitle = (string) $match['match_title'];

    try {
        $result = tx(function () use ($contestId, $allocated, $pool, $title, $matchTitle, $force) {
            // Claim the settle with a conditional UPDATE. This is the idempotency guard: a second
            // worker finds nothing to claim and stops, so nobody is paid twice.
            $claimed = affected(
                'UPDATE "fantasy_contests" SET "status" = ? WHERE "id" = ? AND "status" <> ? AND "status" <> ?',
                ['SETTLED', $contestId, 'SETTLED', 'CANCELLED']
            );
            if ($claimed !== 1) throw new RuntimeException('This contest is already settled.');

            $paid = 0.0;
            $winners = 0;
            $nowSql = ms_to_sql(now_ms());

            foreach ($allocated as $e) {
                $prize = round((float) $e['prize'], 2);
                $txnId = null;

                if ($prize > 0) {
                    credit_wallet((int) $e['user_id'], $prize);
                    $txnId = new_record_id('Y11W');
                    insert_transaction($txnId, (string) $e['username'], 'Deposit', $prize,
                        'Your Eleven Prize — ' . $title . ' (' . $matchTitle . ') rank #' . $e['rank']
                        . ($force ? ' [settled manually]' : ''),
                        'Completed');
                    $paid += $prize;
                    $winners++;
                }

                // Every entry is marked settled, winner or not: is_settled means "this entry has been
                // dealt with", and leaving the losers unmarked would make a retry look unfinished.
                q('UPDATE "fantasy_contest_entries" SET "rank" = ?, "prize_won" = ?, "is_settled" = 1, '
                  . '"settled_at" = ? WHERE "id" = ?',
                  [(int) $e['rank'], $prize, $nowSql, (int) $e['id']]);

                q('UPDATE "fantasy_user_teams" SET "rank" = ? WHERE "id" = (SELECT "user_team_id" '
                  . 'FROM "fantasy_contest_entries" WHERE "id" = ?)',
                  [(int) $e['rank'], (int) $e['id']]);
            }

            return ['paid' => round($paid, 2), 'winners' => $winners];
        });
    } catch (Throwable $e) {
        if (stripos($e->getMessage(), 'already settled') !== false) {
            return ['ok' => true, 'already_settled' => true, 'paid' => 0.0, 'winners' => 0,
                    'undistributed' => 0.0];
        }
        return ['ok' => false, 'error' => $e->getMessage(), 'status' => 409];
    }

    // Money that the prize table did not reach — because the contest never filled far enough for
    // every paid rank to exist. Reported rather than buried: it is the difference between what the
    // players put in and what they got back, and an operator should see it.
    $undistributed = round($pool['prize_pool'] - $result['paid'], 2);

    return [
        'ok' => true,
        'entries' => count($allocated),
        'paid' => $result['paid'],
        'winners' => $result['winners'],
        'prize_pool' => $pool['prize_pool'],
        'rake' => $pool['rake'],
        'undistributed' => $undistributed < 0.005 ? 0.0 : $undistributed,
        'forced' => (bool) $force,
    ];
}

/**
 * Refund every entry and cancel the contest.
 *
 * The right answer for a contest that did not fill, or a match that was abandoned: give the money
 * back rather than pay a prize table that was quoted against a full house. Idempotent by the same
 * conditional claim as settlement.
 */
function fantasy_void_contest($contestId, $reason = '') {
    $contestId = (int) $contestId;
    $contest = one('SELECT * FROM "fantasy_contests" WHERE "id" = ?', [$contestId]);
    if (!$contest) return ['ok' => false, 'error' => 'Contest not found.', 'status' => 404];

    $status = strtoupper((string) $contest['status']);
    if ($status === 'CANCELLED') {
        return ['ok' => true, 'already_cancelled' => true, 'refunded' => 0.0, 'entries' => 0];
    }
    if ($status === 'SETTLED') {
        return ['ok' => false, 'status' => 409,
                'error' => 'This contest has already been settled; prizes cannot be unpaid.'];
    }

    $match = fantasy_find_match((int) $contest['match_id']);
    $matchTitle = $match ? (string) $match['match_title'] : '';
    $title = (string) $contest['title'];
    $why = trim((string) $reason);

    $rows = all(
        'SELECT e."id", e."user_id", e."entry_fee_paid", u."username" '
        . 'FROM "fantasy_contest_entries" e JOIN "User" u ON u."id" = e."user_id" '
        . 'WHERE e."contest_id" = ? AND e."is_settled" = 0',
        [$contestId]
    );

    try {
        $result = tx(function () use ($contestId, $rows, $title, $matchTitle, $why) {
            $claimed = affected(
                'UPDATE "fantasy_contests" SET "status" = ? WHERE "id" = ? AND "status" <> ? AND "status" <> ?',
                ['CANCELLED', $contestId, 'SETTLED', 'CANCELLED']
            );
            if ($claimed !== 1) throw new RuntimeException('This contest is already closed.');

            $refunded = 0.0;
            $count = 0;
            $nowSql = ms_to_sql(now_ms());

            foreach ($rows as $r) {
                $fee = round((float) $r['entry_fee_paid'], 2);
                if ($fee > 0) {
                    credit_wallet((int) $r['user_id'], $fee);
                    insert_transaction(new_record_id('Y11R'), (string) $r['username'], 'Deposit', $fee,
                        'Your Eleven Refund — ' . $title . ' (' . $matchTitle . ')'
                        . ($why !== '' ? ': ' . $why : ''),
                        'Completed');
                    $refunded += $fee;
                }
                q('UPDATE "fantasy_contest_entries" SET "prize_won" = 0, "is_settled" = 1, '
                  . '"settled_at" = ? WHERE "id" = ?', [$nowSql, (int) $r['id']]);
                $count++;
            }
            return ['refunded' => round($refunded, 2), 'entries' => $count];
        });
    } catch (Throwable $e) {
        if (stripos($e->getMessage(), 'already closed') !== false) {
            return ['ok' => true, 'already_cancelled' => true, 'refunded' => 0.0, 'entries' => 0];
        }
        return ['ok' => false, 'error' => $e->getMessage(), 'status' => 409];
    }

    return ['ok' => true, 'refunded' => $result['refunded'], 'entries' => $result['entries']];
}

/**
 * Settle every open contest on a match, then mark the match SETTLED.
 *
 * The match is only marked settled if every one of its contests came out settled — otherwise the
 * fixture stays open so a retry picks up what was missed, rather than a failure being papered over by
 * a fixture that claims to be finished.
 */
function fantasy_settle_match($matchId, $force = false) {
    $matchId = (int) $matchId;
    $match = fantasy_find_match($matchId);
    if (!$match) return ['ok' => false, 'error' => 'Match not found.', 'status' => 404];

    $contests = all('SELECT "id" FROM "fantasy_contests" WHERE "match_id" = ? AND "status" <> ? AND "status" <> ?',
                    [$matchId, 'SETTLED', 'CANCELLED']);

    $out = ['ok' => true, 'contests' => 0, 'paid' => 0.0, 'winners' => 0,
            'undistributed' => 0.0, 'voided' => 0, 'refunded' => 0.0, 'errors' => []];

    foreach ($contests as $c) {
        $r = fantasy_settle_contest((int) $c['id'], $force);
        if (!$r['ok']) {
            $out['errors'][] = 'contest ' . (int) $c['id'] . ': ' . $r['error'];
            continue;
        }
        $out['contests']++;
        $out['paid'] += (float) ($r['paid'] ?? 0);
        $out['winners'] += (int) ($r['winners'] ?? 0);
        $out['undistributed'] += (float) ($r['undistributed'] ?? 0);
        // A contest below its minimum was refunded rather than paid; counted separately so the two
        // outcomes are never conflated in a worker's summary.
        if (!empty($r['voided'])) {
            $out['voided']++;
            $out['refunded'] += (float) ($r['refunded'] ?? 0);
        }
    }

    $out['paid'] = round($out['paid'], 2);
    $out['refunded'] = round($out['refunded'], 2);
    $out['undistributed'] = round($out['undistributed'], 2);

    if (!$out['errors']) {
        $remaining = (int) scalar(
            'SELECT COUNT(*) FROM "fantasy_contests" WHERE "match_id" = ? AND "status" <> ? AND "status" <> ?',
            [$matchId, 'SETTLED', 'CANCELLED'], 0
        );
        if ($remaining === 0) {
            $abandoned = fantasy_state_is_abandoned($match['source_state'] ?? null);
            q('UPDATE "fantasy_matches" SET "status" = ?, "updated_at" = ? WHERE "id" = ?',
              [$abandoned ? 'CANCELLED' : 'SETTLED', ms_to_sql(now_ms()), $matchId]);
            $out['match_settled'] = true;
            if ($abandoned) $out['match_cancelled'] = true;
        }
    }

    return $out;
}
