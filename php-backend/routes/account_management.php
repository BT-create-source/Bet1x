<?php
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/http.php';
require_once __DIR__ . '/../lib/sms.php';

/** Discover optional installed tables without swallowing SQL errors inside a transaction. */
function account_delete_tables() {
    $rows = db_is_mysql()
        ? all('SELECT table_name AS name FROM information_schema.tables WHERE table_schema = DATABASE()')
        : all("SELECT tablename AS name FROM pg_tables WHERE schemaname = current_schema()");
    return array_column($rows, 'name');
}

/** Remove player-owned objects/keys from shared runtime JSON, keeping other players intact. */
function account_scrub_state($value, $username) {
    if (!is_array($value)) return $value;
    $list = array_is_list($value);
    foreach (['username', 'user', 'inviter_username', 'referred_username'] as $field) {
        if (isset($value[$field]) && $value[$field] === $username) return null;
    }
    $out = [];
    foreach ($value as $key=>$child) {
        if ((string) $key === $username || ($list && $child === $username)) continue;
        $clean = account_scrub_state($child, $username);
        if (is_array($child) && $clean === null) continue;
        if ($list) $out[] = $clean; else $out[$key] = $clean;
    }
    return $out;
}

function account_payload_has_identity($value, $identities) {
    if (!is_array($value)) return is_string($value) && in_array($value, $identities, true);
    foreach ($value as $key=>$child) {
        if (in_array((string) $key, $identities, true) || account_payload_has_identity($child, $identities)) return true;
    }
    return false;
}

function account_delete_player($id, $confirmation) {
    $tables = account_delete_tables();
    return tx(function () use ($id, $confirmation, $tables) {
        $u = one('SELECT * FROM "User" WHERE "id" = ? FOR UPDATE', [$id]);
        if (!$u) throw new DomainException('Account not found.');
        if (!hash_equals('DELETE ' . $u['username'], $confirmation)) throw new DomainException('Confirmation does not match this account.');
        foreach (['ADMIN_USERNAME', 'SUPERADMIN_USERNAME'] as $key) {
            if (strcasecmp($u['username'], (string) cfg($key)) === 0) throw new DomainException('Operator accounts cannot be deleted here.');
        }
        // Child records first; optional game tables may not be installed on older deployments.
        $map = [
            'fantasy_contest_entries'=>['user_id'], 'fantasy_user_teams'=>['user_id'],
            'bbb_bets'=>['user_id'], 'mx_bets'=>['user_id'],
            'SubAdminLedger'=>['user_id'], 'SubAdminEvent'=>['user_id'], 'SubAdminCash'=>['user_id'],
            'SubAdminDeposit'=>['user_id'], 'SubAdminWithdrawal'=>['user_id'], 'SubAdminUser'=>['user_id'],
            'Transaction'=>['user'], 'Deposit'=>['username'], 'Withdrawal'=>['username'],
            'ChatMessage'=>['username'], 'TeenPattiSeat'=>['username'], 'GameBet'=>['username'],
            'MinesSession'=>['username'], 'ChickenRoadSession'=>['username'], 'AstronautBet'=>['username'],
            'TowerRushSession'=>['username'], 'OriginalsRound'=>['username'], 'OriginalsSeed'=>['username'],
            'SessionPresence'=>['username'], 'LivePresence'=>['subject'],
            'ReferralCommission'=>['inviter_username','referred_username']
        ];
        // Do not remove a seated player mid-hand: that would change other players' game state.
        if (in_array('TeenPattiSeat', $tables, true) && one('SELECT 1 FROM "TeenPattiSeat" WHERE "username" = ? LIMIT 1', [$u['username']])) {
            throw new DomainException('This player must leave their Teen Patti table before deletion.');
        }
        if (in_array('PaymentLog', $tables, true)) {
            $identities = array_values(array_filter([$u['username'], $u['email'], $u['phone'] ?? null], function ($v) { return is_string($v) && $v !== ''; }));
            foreach (all('SELECT "deposit_id", "order_id" FROM "Deposit" WHERE "username" = ?', [$u['username']]) as $deposit) {
                foreach ($deposit as $value) if (is_string($value) && $value !== '') $identities[] = $value;
            }
            foreach (all('SELECT "id", "payload" FROM "PaymentLog" FOR UPDATE') as $payment) {
                $payload = is_string($payment['payload']) ? json_decode($payment['payload'], true) : $payment['payload'];
                if (account_payload_has_identity($payload, $identities)) q('DELETE FROM "PaymentLog" WHERE "id" = ?', [$payment['id']]);
            }
        }
        foreach ($map as $table=>$columns) {
            if (!in_array($table, $tables, true)) continue;
            $where = []; $args = [];
            foreach ($columns as $column) { $where[] = '"' . $column . '" = ?'; $args[] = $column === 'user_id' ? $id : $u['username']; }
            q('DELETE FROM "' . $table . '" WHERE ' . implode(' OR ', $where), $args);
        }
        if (in_array('PhoneOtp', $tables, true) && !empty($u['phone'])) q('DELETE FROM "PhoneOtp" WHERE "phone" IN (?, ?)', [$u['phone'], 'reset:' . $u['phone']]);
        if (in_array('EmailOtp', $tables, true)) q('DELETE FROM "EmailOtp" WHERE "email" = ?', [$u['email']]);
        foreach (all('SELECT "key", "data" FROM "GameState" FOR UPDATE') as $row) {
            $data = is_string($row['data']) ? json_decode($row['data'], true) : $row['data'];
            $clean = account_scrub_state($data, $u['username']);
            if ($clean !== $data) state_set($row['key'], $clean);
        }
        q('UPDATE "User" SET "referred_by" = NULL WHERE "referred_by" = ?', [$u['username']]);
        q('DELETE FROM "User" WHERE "id" = ?', [$id]);
        return ['success'=>true];
    });
}

function register_account_management_routes(Router $app) {
    $app->get('/api/admin/accounts/search', 'require_superadmin', function (Req $req, Res $res) {
        $query = trim((string) $req->q('q'));
        if (strlen($query) < 3 || strlen($query) > 255) { $res->status(400)->json(['error'=>'Enter at least 3 characters of an email or phone number.']); return; }
        try {
            // Literal substring search: SQL wildcard characters are not interpreted.
            $sql = db_is_mysql() ? 'LOCATE(LOWER(?), LOWER("email")) > 0 OR LOCATE(?, "phone") > 0' : 'POSITION(LOWER(?) IN LOWER("email")) > 0 OR POSITION(? IN "phone") > 0';
            $phoneQuery = preg_match('/^[+\d\s()-]+$/', $query) ? (sms_normalise_indian_mobile($query) ?: preg_replace('/\D/', '', $query)) : $query;
            $res->json(['users'=>all('SELECT "id", "username", "email", "phone", "phone_verified", "wallet_balance", "created_at" FROM "User" WHERE ' . $sql . ' ORDER BY "id" DESC LIMIT 50', [$query, $phoneQuery])]);
        } catch (Throwable $e) { fail500($res, $e, 'account_search'); }
    });
    $app->post('/api/admin/accounts/delete', 'require_superadmin', function (Req $req, Res $res) {
        $id = (int) $req->b('id');
        if ($id <= 0) { $res->status(400)->json(['error'=>'Select an account.']); return; }
        try { $res->json(account_delete_player($id, (string) $req->b('confirmation'))); }
        catch (DomainException $e) { $res->status(400)->json(['error'=>$e->getMessage()]); }
        catch (Throwable $e) { fail500($res, $e, 'account_delete'); }
    });
}
