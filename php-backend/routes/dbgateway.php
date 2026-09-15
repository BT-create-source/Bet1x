<?php
/**
 * The /api/db namespace — a direct read/write door into the users, transactions, deposits and
 * withdrawals tables, plus the generic game-state and recent-results stores.
 *
 * It exists for the operator console and the legacy PHP gateway, never for players, so the whole
 * namespace is gated ONCE at the top rather than route by route. The
 * /api/db/users/{login,signup,status,adjust-balance,reset-balance} and GET /api/db/transactions
 * aliases are registered EARLIER (see routes/auth.php and routes/wallet.php) and keep their own,
 * narrower rules — that ordering is load-bearing and must not be rearranged.
 */

require_once __DIR__ . '/../lib/http.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/helpers.php';

function uuid_v4() {
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

function register_db_gateway_routes(Router $app) {

    // Everything below this line is operator-only and needs a live database.
    $app->useMw('/api/db', 'require_admin', 'require_database');

    // --- Game bets ---
    $app->get('/api/db/game-bets', function (Req $req, Res $res) {
        $username = $req->q('username');
        $game = $req->q('game');
        try {
            $bets = [];
            try {
                $sql = 'SELECT * FROM "GameBet" WHERE 1=1';
                $params = [];
                if (js_truthy($username)) { $sql .= ' AND LOWER("username") = LOWER(?)'; $params[] = $username; }
                if (js_truthy($game))     { $sql .= ' AND "game" = ?'; $params[] = $game; }
                $sql .= ' ORDER BY "created_at" DESC LIMIT 50';
                foreach (all($sql, $params) as $r) $bets[] = map_game_bet($r);
            } catch (Throwable $e) {
                $bets = readJsonTable('game_bets');
                if (js_truthy($username)) {
                    $bets = array_values(array_filter($bets, function ($b) use ($username) {
                        return isset($b['username']) && strtolower($b['username']) === strtolower((string)$username);
                    }));
                }
                if (js_truthy($game)) {
                    $bets = array_values(array_filter($bets, function ($b) use ($game) { return ($b['game'] ?? null) === $game; }));
                }
            }
            $res->json($bets);
        } catch (Throwable $err) {
            fail500($res, $err, 'dbgateway');
        }
    });

    $app->post('/api/db/game-bets', function (Req $req, Res $res) {
        $betAmountParsed = js_parse_float($req->b('bet_amount'));
        $payoutParsed    = js_parse_float($req->b('payout'));
        $betRecord = [
            'username'   => js_truthy($req->b('username')) ? $req->b('username') : 'DemoUser',
            'game'       => js_truthy($req->b('game')) ? $req->b('game') : 'unknown',
            'bet_amount' => js_truthy($betAmountParsed) ? (float)$betAmountParsed : 0.0,
            'payout'     => js_truthy($payoutParsed) ? (float)$payoutParsed : 0.0,
            'status'     => js_truthy($req->b('status')) ? $req->b('status') : 'active',
            'metadata'   => js_truthy($req->b('metadata')) ? $req->b('metadata') : null,
        ];

        try {
            $saved = null;
            try {
                $id = uuid_v4();
                $createdAt = ms_to_sql();
                q('INSERT INTO "GameBet" ("id","username","game","bet_amount","payout","status","metadata","created_at")
                   VALUES (?,?,?,?,?,?,?,?)', [
                    $id, $betRecord['username'], $betRecord['game'], $betRecord['bet_amount'],
                    $betRecord['payout'], $betRecord['status'],
                    $betRecord['metadata'] === null ? null : js_json_encode($betRecord['metadata']),
                    $createdAt,
                ]);
                $saved = map_game_bet(one('SELECT * FROM "GameBet" WHERE "id" = ?', [$id]));
            } catch (Throwable $e) {
                $bets = readJsonTable('game_bets');
                $betRecord['id'] = 'BET_' . (int) floor(100000 + js_random() * 900000);
                $betRecord['created_at'] = js_iso();
                array_unshift($bets, $betRecord);
                writeJsonTable('game_bets', array_slice($bets, 0, 100));
                $saved = $betRecord;
            }
            $res->json(['success' => true, 'bet' => $saved]);
        } catch (Throwable $err) {
            fail500($res, $err, 'dbgateway');
        }
    });

    // --- Users (note: this one returns password hashes; admin.php?action=users strips them) ---
    $app->get('/api/db/users', function (Req $req, Res $res) {
        try {
            $users = [];
            try {
                foreach (all('SELECT * FROM "User"') as $r) $users[] = map_user($r);
            } catch (Throwable $e) {
                $users = readJsonTable('users');
            }
            $res->json($users);
        } catch (Throwable $err) {
            fail500($res, $err, 'dbgateway');
        }
    });

    // --- Direct transaction insert ---
    $app->post('/api/db/transactions', function (Req $req, Res $res) {
        $id = $req->b('id'); $user = $req->b('user'); $type = $req->b('type');
        $amount = $req->b('amount'); $details = $req->b('details'); $status = $req->b('status');
        try {
            $txnId = js_truthy($id) ? $id
                   : strtoupper(substr((string)$type, 0, 3)) . '_' . (int) floor(100000 + js_random() * 900000);
            $ts = ms_to_sql();
            insert_transaction($txnId, $user, $type, (float)js_parse_float($amount), $details, $status, now_ms());
            $res->json(map_transaction(one('SELECT * FROM "Transaction" WHERE "id" = ?', [$txnId])));
        } catch (Throwable $err) {
            fail500($res, $err, 'dbgateway');
        }
    });

    // --- Deposits ---
    $app->get('/api/db/deposits', function (Req $req, Res $res) {
        try {
            $out = [];
            foreach (all('SELECT * FROM "Deposit"') as $r) $out[] = map_deposit($r);
            $res->json($out);
        } catch (Throwable $err) {
            fail500($res, $err, 'dbgateway');
        }
    });

    $app->post('/api/db/deposits', function (Req $req, Res $res) {
        try {
            $createdAt = js_truthy($req->b('created_at')) ? strtotime((string)$req->b('created_at')) * 1000 : now_ms();
            $updatedAt = js_truthy($req->b('updated_at')) ? strtotime((string)$req->b('updated_at')) * 1000 : now_ms();
            $depositId = $req->b('deposit_id');
            q('INSERT INTO "Deposit"
               ("deposit_id","order_id","username","amount","utr","qr_type","custom_qr_data","status","gateway","gateway_id","created_at","updated_at")
               VALUES (?,?,?,?,?,?,?,?,?,?,?,?)', [
                $depositId,
                js_truthy($req->b('order_id')) ? $req->b('order_id') : null,
                $req->b('username'),
                (float) js_parse_float($req->b('amount')),
                js_truthy($req->b('utr')) ? $req->b('utr') : null,
                js_truthy($req->b('qr_type')) ? $req->b('qr_type') : null,
                js_truthy($req->b('custom_qr_data')) ? $req->b('custom_qr_data') : null,
                $req->b('status'),
                js_truthy($req->b('gateway')) ? $req->b('gateway') : null,
                js_truthy($req->b('gateway_id')) ? $req->b('gateway_id') : null,
                ms_to_sql($createdAt), ms_to_sql($updatedAt),
            ]);
            $res->json(map_deposit(one('SELECT * FROM "Deposit" WHERE "deposit_id" = ?', [$depositId])));
        } catch (Throwable $err) {
            fail500($res, $err, 'dbgateway');
        }
    });

    // Complete a deposit and its transaction atomically (gateway webhook flow).
    // Note: a missing order answers 200 with an `error` field, not a 404. Reproduced as-is.
    $app->post('/api/db/deposits/complete', function (Req $req, Res $res) {
        $orderId = $req->b('orderId');
        $paymentId = $req->b('paymentId');
        try {
            $result = tx(function () use ($orderId, $paymentId) {
                $deposit = one('SELECT * FROM "Deposit" WHERE "order_id" = ? LIMIT 1', [$orderId]);
                if (!$deposit) return ['error' => 'Deposit order not found.'];
                if ($deposit['status'] !== 'Pending') return ['success' => true, 'message' => 'Deposit already processed.'];

                q('UPDATE "Deposit" SET "status" = ?, "gateway_id" = ?, "updated_at" = ? WHERE "deposit_id" = ?',
                  ['Completed', $paymentId, ms_to_sql(), $deposit['deposit_id']]);

                $user = find_user_ci($deposit['username']);
                if ($user) {
                    q('UPDATE "User" SET "wallet_balance" = ? WHERE "id" = ?',
                      [(float)$user['wallet_balance'] + (float)$deposit['amount'], (int)$user['id']]);
                }

                $txn = one('SELECT * FROM "Transaction" WHERE "user" = ? AND "details" LIKE ? LIMIT 1',
                           [$deposit['username'], '%' . $orderId . '%']);
                if ($txn) {
                    q('UPDATE "Transaction" SET "status" = ? WHERE "id" = ?', ['Completed', $txn['id']]);
                } else {
                    insert_transaction(new_record_id('DEP'), $deposit['username'], 'Deposit',
                                       (float)$deposit['amount'], 'Razorpay Deposit: ' . $paymentId, 'Completed');
                }

                return ['success' => true, 'amount' => (float)$deposit['amount'], 'user' => $deposit['username']];
            });
            $res->json($result);
        } catch (Throwable $err) {
            fail500($res, $err, 'dbgateway');
        }
    });

    // --- Withdrawals ---
    $app->get('/api/db/withdrawals', function (Req $req, Res $res) {
        try {
            $out = [];
            foreach (all('SELECT * FROM "Withdrawal"') as $r) $out[] = map_withdrawal($r);
            $res->json($out);
        } catch (Throwable $err) {
            fail500($res, $err, 'dbgateway');
        }
    });

    $app->post('/api/db/withdrawals', function (Req $req, Res $res) {
        try {
            $createdAt = js_truthy($req->b('created_at')) ? strtotime((string)$req->b('created_at')) * 1000 : now_ms();
            $updatedAt = js_truthy($req->b('updated_at')) ? strtotime((string)$req->b('updated_at')) * 1000 : now_ms();
            $wid = $req->b('withdrawal_id');
            q('INSERT INTO "Withdrawal" ("withdrawal_id","username","amount","method","details","status","created_at","updated_at")
               VALUES (?,?,?,?,?,?,?,?)', [
                $wid, $req->b('username'), (float) js_parse_float($req->b('amount')),
                $req->b('method'), $req->b('details'), $req->b('status'),
                ms_to_sql($createdAt), ms_to_sql($updatedAt),
            ]);
            $res->json(map_withdrawal(one('SELECT * FROM "Withdrawal" WHERE "withdrawal_id" = ?', [$wid])));
        } catch (Throwable $err) {
            fail500($res, $err, 'dbgateway');
        }
    });

    // --- Payment logs ---
    $app->get('/api/db/payment-logs', function (Req $req, Res $res) {
        try {
            $out = [];
            foreach (all('SELECT * FROM "PaymentLog"') as $r) $out[] = map_payment_log($r);
            $res->json($out);
        } catch (Throwable $err) {
            fail500($res, $err, 'dbgateway');
        }
    });

    $app->post('/api/db/payment-logs', function (Req $req, Res $res) {
        try {
            $id = js_truthy($req->b('id')) ? $req->b('id') : 'LOG_' . (int) floor(100000 + js_random() * 900000);
            $ts = js_truthy($req->b('timestamp')) ? strtotime((string)$req->b('timestamp')) * 1000 : now_ms();
            q('INSERT INTO "PaymentLog" ("id","payload","signature","timestamp") VALUES (?,?,?,?)',
              [$id, js_json_encode($req->b('payload')), $req->b('signature'), ms_to_sql($ts)]);
            $res->json(map_payment_log(one('SELECT * FROM "PaymentLog" WHERE "id" = ?', [$id])));
        } catch (Throwable $err) {
            fail500($res, $err, 'dbgateway');
        }
    });

    // --- Generic game state ---
    $app->get('/api/db/state/:key', function (Req $req, Res $res) {
        try {
            $data = state_get($req->p('key'));
            $res->json($data === null ? null : $data);
        } catch (Throwable $err) {
            fail500($res, $err, 'dbgateway');
        }
    });

    $app->post('/api/db/state/:key', function (Req $req, Res $res) {
        try {
            $key = $req->p('key');
            $data = $req->b('data');
            state_set($key, $data);
            $row = one('SELECT * FROM "GameState" WHERE "key" = ?', [$key]);
            $res->json(['success' => true, 'state' => [
                'key'       => $key,
                'data'      => $data,
                'updatedAt' => js_iso($row['updatedAt'] ?? null),
            ]]);
        } catch (Throwable $err) {
            fail500($res, $err, 'dbgateway');
        }
    });

    // --- Recent results ---
    $app->get('/api/db/recent-results', function (Req $req, Res $res) {
        $room = $req->q('room');
        try {
            $sql = 'SELECT * FROM "RecentResult"' . (js_truthy($room) ? ' WHERE "room" = ?' : '')
                 . ' ORDER BY "id" DESC LIMIT 20';
            $rows = all($sql, js_truthy($room) ? [$room] : []);
            $formatted = [];
            foreach ($rows as $r) {
                $formatted[] = [
                    'roundNumber' => $r['roundNumber'],
                    'number'      => (int)$r['number'],
                    'color'       => $r['color'],
                    'dotClass'    => $r['dotClass'],
                    'size'        => $r['size'],
                    // Bare local HH:MM:SS, not ISO — the frontend renders this verbatim.
                    'timestamp'   => js_locale_time(sql_to_ms($r['timestamp'])),
                ];
            }
            $res->json($formatted);
        } catch (Throwable $err) {
            fail500($res, $err, 'dbgateway');
        }
    });

    $app->post('/api/db/recent-results', function (Req $req, Res $res) {
        try {
            $room = $req->b('room');
            $roundNumber = (string)$req->b('roundNumber');
            $number = (int) js_parse_int($req->b('number'));
            $color = $req->b('color'); $dotClass = $req->b('dotClass'); $size = $req->b('size');
            q('INSERT INTO "RecentResult" ("room","roundNumber","number","color","dotClass","size")
               VALUES (?,?,?,?,?,?)
               ON CONFLICT ("room","roundNumber") DO UPDATE SET "number"=EXCLUDED."number", "color"=EXCLUDED."color",
                                       "dotClass"=EXCLUDED."dotClass", "size"=EXCLUDED."size"',
              [$room, $roundNumber, $number, $color, $dotClass, $size]);
            $row = one('SELECT * FROM "RecentResult" WHERE "room" = ? AND "roundNumber" = ?', [$room, $roundNumber]);
            $res->json(['success' => true, 'result' => [
                'id'          => (int)$row['id'],
                'room'        => $row['room'],
                'roundNumber' => $row['roundNumber'],
                'number'      => (int)$row['number'],
                'color'       => $row['color'],
                'dotClass'    => $row['dotClass'],
                'size'        => $row['size'],
                'timestamp'   => js_iso($row['timestamp']),
            ]]);
        } catch (Throwable $err) {
            fail500($res, $err, 'dbgateway');
        }
    });
}

/**
 * Bulk overwrite of an entire table.
 *
 * Already covered by the /api/db namespace guard; the explicit repeat documents that this is the
 * single most destructive endpoint in the service. Registered AFTER /api/db/state/:key so that
 * ordering matches Express — a request for /api/db/state/sync reaches the state handler first.
 *
 * The table name comes from the URL, so it is resolved through a fixed allowlist and never
 * interpolated into SQL.
 */
function register_db_sync_route(Router $app) {
    $app->post('/api/db/:table/sync', 'require_admin', function (Req $req, Res $res) {
        $table = $req->p('table');
        $data = $req->body;
        try {
            $isList = is_array($data) && (count($data) === 0 || array_keys($data) === range(0, count($data) - 1));
            if (!$isList) {
                $res->status(400)->json(['error' => 'Body must be an array']);
                return;
            }

            tx(function () use ($table, $data) {
                if ($table === 'users') {
                    foreach ($data as $item) {
                        $existing = one('SELECT * FROM "User" WHERE "username" = ? LIMIT 1', [$item['username'] ?? null]);
                        $createdAt = js_truthy($item['created_at'] ?? null) ? strtotime((string)$item['created_at']) * 1000 : now_ms();
                        if ($existing) {
                            q('UPDATE "User" SET "email" = ?, "password" = ?, "wallet_balance" = ?, "created_at" = ? WHERE "id" = ?',
                              [$item['email'] ?? null, $item['password'] ?? null,
                               (float) js_parse_float($item['wallet_balance'] ?? 0), ms_to_sql($createdAt), (int)$existing['id']]);
                        } else {
                            q('INSERT INTO "User" ("username","email","password","wallet_balance","created_at") VALUES (?,?,?,?,?)',
                              [$item['username'] ?? null, $item['email'] ?? null, $item['password'] ?? null,
                               (float) js_parse_float($item['wallet_balance'] ?? 0), ms_to_sql($createdAt)]);
                        }
                    }
                } elseif ($table === 'transactions') {
                    foreach ($data as $item) {
                        $ts = js_truthy($item['timestamp'] ?? null) ? strtotime((string)$item['timestamp']) * 1000 : now_ms();
                        q('INSERT INTO "Transaction" ("id","user","type","amount","details","status","timestamp")
                           VALUES (?,?,?,?,?,?,?)
                           ON CONFLICT ("id") DO UPDATE SET "user"=EXCLUDED."user", "type"=EXCLUDED."type", "amount"=EXCLUDED."amount",
                                                   "details"=EXCLUDED."details", "status"=EXCLUDED."status", "timestamp"=EXCLUDED."timestamp"',
                          [$item['id'] ?? null, $item['user'] ?? null, $item['type'] ?? null,
                           (float) js_parse_float($item['amount'] ?? 0), $item['details'] ?? null,
                           $item['status'] ?? null, ms_to_sql($ts)]);
                    }
                } elseif ($table === 'deposits') {
                    foreach ($data as $item) {
                        $createdAt = js_truthy($item['created_at'] ?? null) ? strtotime((string)$item['created_at']) * 1000 : now_ms();
                        $updatedAt = js_truthy($item['updated_at'] ?? null) ? strtotime((string)$item['updated_at']) * 1000 : now_ms();
                        q('INSERT INTO "Deposit"
                           ("deposit_id","order_id","username","amount","utr","qr_type","custom_qr_data","status","gateway","gateway_id","created_at","updated_at")
                           VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
                           ON CONFLICT ("deposit_id") DO UPDATE SET "order_id"=EXCLUDED."order_id", "username"=EXCLUDED."username",
                             "amount"=EXCLUDED."amount", "utr"=EXCLUDED."utr", "qr_type"=EXCLUDED."qr_type",
                             "custom_qr_data"=EXCLUDED."custom_qr_data", "status"=EXCLUDED."status",
                             "gateway"=EXCLUDED."gateway", "gateway_id"=EXCLUDED."gateway_id", "updated_at"=EXCLUDED."updated_at"',
                          [$item['deposit_id'] ?? null,
                           js_truthy($item['order_id'] ?? null) ? $item['order_id'] : null,
                           $item['username'] ?? null, (float) js_parse_float($item['amount'] ?? 0),
                           js_truthy($item['utr'] ?? null) ? $item['utr'] : null,
                           js_truthy($item['qr_type'] ?? null) ? $item['qr_type'] : null,
                           js_truthy($item['custom_qr_data'] ?? null) ? $item['custom_qr_data'] : null,
                           $item['status'] ?? null,
                           js_truthy($item['gateway'] ?? null) ? $item['gateway'] : null,
                           js_truthy($item['gateway_id'] ?? null) ? $item['gateway_id'] : null,
                           ms_to_sql($createdAt), ms_to_sql($updatedAt)]);
                    }
                } elseif ($table === 'withdrawals') {
                    foreach ($data as $item) {
                        $createdAt = js_truthy($item['created_at'] ?? null) ? strtotime((string)$item['created_at']) * 1000 : now_ms();
                        $updatedAt = js_truthy($item['updated_at'] ?? null) ? strtotime((string)$item['updated_at']) * 1000 : now_ms();
                        q('INSERT INTO "Withdrawal" ("withdrawal_id","username","amount","method","details","status","created_at","updated_at")
                           VALUES (?,?,?,?,?,?,?,?)
                           ON CONFLICT ("withdrawal_id") DO UPDATE SET "username"=EXCLUDED."username", "amount"=EXCLUDED."amount",
                             "method"=EXCLUDED."method", "details"=EXCLUDED."details", "status"=EXCLUDED."status",
                             "updated_at"=EXCLUDED."updated_at"',
                          [$item['withdrawal_id'] ?? null, $item['username'] ?? null,
                           (float) js_parse_float($item['amount'] ?? 0), $item['method'] ?? null,
                           $item['details'] ?? null, $item['status'] ?? null,
                           ms_to_sql($createdAt), ms_to_sql($updatedAt)]);
                    }
                } elseif ($table === 'payment_logs') {
                    foreach ($data as $item) {
                        $logId = js_truthy($item['id'] ?? null) ? $item['id'] : 'LOG_' . (int) floor(100000 + js_random() * 900000);
                        $ts = js_truthy($item['timestamp'] ?? null) ? strtotime((string)$item['timestamp']) * 1000 : now_ms();
                        q('INSERT INTO "PaymentLog" ("id","payload","signature","timestamp") VALUES (?,?,?,?)
                           ON CONFLICT ("id") DO UPDATE SET "payload"=EXCLUDED."payload", "signature"=EXCLUDED."signature",
                                                   "timestamp"=EXCLUDED."timestamp"',
                          [$logId, js_json_encode($item['payload'] ?? null),
                           js_truthy($item['signature'] ?? null) ? $item['signature'] : null, ms_to_sql($ts)]);
                    }
                }
            });

            $res->json(['success' => true]);
        } catch (Throwable $err) {
            log_error("Sync error on table {$table}: " . $err->getMessage());
            fail500($res, $err, 'dbgateway');
        }
    });
}
