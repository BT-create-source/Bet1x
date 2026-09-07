<?php
/**
 * Referral dashboard + claim. See lib/referral.php for the commission model — codes, signup
 * attribution, and how a deposit turns into a commission row — this file only reads/spends it.
 */

require_once __DIR__ . '/../lib/http.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/referral.php';
require_once __DIR__ . '/../lib/ratelimit.php';

function register_referral_routes(Router $app) {

    // --- GET /api/referral ---------------------------------------------------------------------
    $app->get('/api/referral', 'require_auth', function (Req $req, Res $res) {
        try {
            $username = $req->auth['username'];
            $user = find_user_ci($username);
            if (!$user) { $res->status(404)->json(['error' => 'Account not found.']); return; }

            $code = referral_get_or_create_code($user['id'], $user['referral_code'] ?? null);

            $referred = all(
                'SELECT "username","created_at" FROM "User" WHERE LOWER("referred_by") = LOWER(?) ORDER BY "created_at" DESC',
                [$username]
            );

            $earnedRows = all(
                'SELECT "referred_username", SUM("commission_amount") AS total FROM "ReferralCommission" '
                . 'WHERE LOWER("inviter_username") = LOWER(?) GROUP BY "referred_username"',
                [$username]
            );
            $earnedByUser = [];
            foreach ($earnedRows as $r) {
                $earnedByUser[strtolower($r['referred_username'])] = (float) $r['total'];
            }

            $players = array_map(function ($r) use ($earnedByUser) {
                return [
                    'username'    => $r['username'],
                    'joined'      => $r['created_at'],
                    'earned_from' => round($earnedByUser[strtolower($r['username'])] ?? 0, 2),
                ];
            }, $referred);

            $totalEarned = (float) scalar(
                'SELECT COALESCE(SUM("commission_amount"),0) FROM "ReferralCommission" WHERE LOWER("inviter_username") = LOWER(?)',
                [$username], 0
            );

            $res->json([
                'success'          => true,
                'referral_code'    => $code,
                'referral_balance' => (float) $user['referral_balance'],
                'total_referred'   => count($players),
                'total_earned'     => round($totalEarned, 2),
                'players'          => $players,
            ]);
        } catch (Throwable $err) {
            fail500($res, $err, 'referral');
        }
    });

    // --- POST /api/referral/claim ---------------------------------------------------------------
    // Merges the whole accrued referral_balance into wallet_balance in one shot.
    $app->post('/api/referral/claim', limiter('wallet'), 'require_auth', function (Req $req, Res $res) {
        try {
            $username = $req->auth['username'];
            // Snapshot the moment BEFORE reading the balance: any commission credited after this
            // instant must not get swept up by the "mark as claimed" step below, even though its
            // value is (correctly) left untouched in referral_balance either way — see the
            // conditional UPDATE's own comment for why that part is already race-safe.
            $claimCutoff = ms_to_sql();

            $user = find_user_ci($username);
            if (!$user) { $res->status(404)->json(['error' => 'Account not found.']); return; }

            $amount = round((float) $user['referral_balance'], 2);
            if ($amount <= 0) {
                $res->status(400)->json(['error' => 'No referral bonus available to claim.']);
                return;
            }

            // Conditional update: claims exactly the amount just read, and only once. A
            // double-clicked Claim cannot merge twice; a fresh commission credited between the
            // read above and this write simply waits for the next claim instead of being lost —
            // the WHERE clause only matches if the balance still covers what we are about to move.
            $claimed = affected(
                'UPDATE "User" SET "wallet_balance" = "wallet_balance" + ?, "referral_balance" = "referral_balance" - ? '
                . 'WHERE "id" = ? AND "referral_balance" >= ?',
                [$amount, $amount, (int) $user['id'], $amount]
            );
            if ($claimed === 0) {
                $res->status(409)->json(['error' => 'Referral balance changed — please try again.']);
                return;
            }

            insert_transaction(new_record_id('REFCLAIM'), $username, 'Deposit', $amount,
                'Referral Bonus Claimed', 'Completed');

            // Mark what was actually just claimed, not everything ever earned — a row credited
            // after $claimCutoff (even a split second before this UPDATE runs) is deliberately
            // left unclaimed, since its value was not part of $amount above.
            q('UPDATE "ReferralCommission" SET "claimed_at" = ? '
              . 'WHERE LOWER("inviter_username") = LOWER(?) AND "claimed_at" IS NULL AND "created_at" <= ?',
              [ms_to_sql(), $username, $claimCutoff]);

            $fresh = find_user_by_id((int) $user['id']);
            $res->json([
                'success'          => true,
                'claimed'          => $amount,
                'wallet_balance'   => (float) $fresh['wallet_balance'],
                'referral_balance' => (float) $fresh['referral_balance'],
            ]);
        } catch (Throwable $err) {
            fail500($res, $err, 'referral');
        }
    });
}
