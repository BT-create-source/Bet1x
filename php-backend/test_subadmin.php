<?php
/**
 * Sub-Admin system — the real HTTP API against the REAL database.
 *
 *     php -d extension=pdo_pgsql -d extension=curl php-backend/test_subadmin.php
 *
 * Starts the site on four ports (php -S serves one request at a time) and drives every flow through
 * the endpoints the pages call: account creation, sign-in, password change and reset, suspension,
 * allocations from the Admin's unlimited supply (both credit workflows), payments to the Admin,
 * user creation and assignment, user deposits (requested -> unconfirmed -> confirmed), real game
 * play, withdrawals routed from the existing cashier (approve, pay, reject, reverse, hand back),
 * the reports, and the isolation between sub-admins and every other role. Parallel bursts prove a
 * double click can never credit or pay twice. After every money step the books are reconciled:
 * stored inventory == inventory re-derived from the coin ledger, wallets == their ledgers.
 *
 * Writes to whatever php-backend/.env points at — use a development database. Touches only its own
 * satest_* accounts and their rows, and removes them at the end.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }
ini_set('display_errors', '1');
error_reporting(E_ALL & ~E_DEPRECATED);
require_once __DIR__ . '/config.php';
foreach (['json', 'logger', 'db', 'http', 'auth', 'helpers', 'subadmin'] as $f) require_once __DIR__ . "/lib/$f.php";

$pass = 0; $fail = 0; $failures = [];
function check($cond, $label) {
    global $pass, $fail, $failures;
    if ($cond) { $pass++; echo "  PASS  $label\n"; } else { $fail++; $failures[] = $label; echo "  FAIL  $label\n"; }
}
function near($a, $b, $eps = 0.005) { return abs((float) $a - (float) $b) < $eps; }
function section($t) { echo "\n== $t ==\n"; }

if (!subadmin_ensure()) { echo "Database not reachable or schema could not be applied.\n"; exit(1); }

// =================================================================================================
section('0. Servers');
// =================================================================================================
$PORTS = [5141, 5142, 5143, 5144];
$root = dirname(__DIR__);
$servers = [];
$env = array_merge(getenv(), ['DISABLE_RATE_LIMITS' => 'true']);
foreach ($PORTS as $p) {
    $log = sys_get_temp_dir() . DIRECTORY_SEPARATOR . "subadmin_$p.log";
    $cmd = escapeshellarg(PHP_BINARY) . ' -d extension=pdo_pgsql -S 127.0.0.1:' . $p . ' ' . escapeshellarg($root . '/router.php');
    $servers[] = proc_open($cmd, [1 => ['file', $log, 'w'], 2 => ['file', $log, 'a']], $pipes, $root, $env);
}
register_shutdown_function(function () use (&$servers) {
    foreach ($servers as $s) {
        if (!is_resource($s)) continue;
        $st = proc_get_status($s);
        if (stripos(PHP_OS, 'WIN') === 0) exec('taskkill /F /T /PID ' . (int) $st['pid'] . ' 2>NUL');
        else proc_terminate($s);
        proc_close($s);
    }
});
function http($method, $path, $token = null, $body = null, $port = 5141) {
    $ch = curl_init("http://127.0.0.1:$port$path");
    $h = ['Accept: application/json'];
    if ($token) $h[] = 'Authorization: Bearer ' . $token;
    if ($body !== null) { $h[] = 'Content-Type: application/json'; curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body)); }
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $h, CURLOPT_TIMEOUT => 60]);
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, json_decode((string) $raw, true), (string) $raw];
}
function burst($path, $token, $body, $copies = 8) {
    global $PORTS;
    $mh = curl_multi_init(); $hs = [];
    for ($i = 0; $i < $copies; $i++) {
        $ch = curl_init('http://127.0.0.1:' . $PORTS[$i % count($PORTS)] . $path);
        curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => 'POST', CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60,
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/json', 'Authorization: Bearer ' . $token],
            CURLOPT_POSTFIELDS => json_encode($body)]);
        curl_multi_add_handle($mh, $ch); $hs[] = $ch;
    }
    do { curl_multi_exec($mh, $running); curl_multi_select($mh, 0.05); } while ($running > 0);
    $out = [];
    foreach ($hs as $ch) {
        $out[] = [(int) curl_getinfo($ch, CURLINFO_HTTP_CODE), json_decode((string) curl_multi_getcontent($ch), true)];
        curl_multi_remove_handle($mh, $ch); curl_close($ch);
    }
    curl_multi_close($mh);
    return $out;
}
function oks(array $results) { return count(array_filter($results, function ($r) { return $r[0] === 200; })); }
function key8() { return 'k' . bin2hex(random_bytes(10)); }

$up = 0;
foreach ($PORTS as $p) { for ($i = 0; $i < 60; $i++) { [$c] = http('GET', '/api/health', null, null, $p); if ($c === 200) { $up++; break; } usleep(250000); } }
check($up === count($PORTS), "all four servers answer ($up)");
if ($up !== count($PORTS)) exit(1);

function wipe() {
    $ids = array_map(function ($r) { return (int) $r['id']; }, all('SELECT "id" FROM "SubAdmin" WHERE "username" LIKE ?', ['satest%']));
    $uids = array_map(function ($r) { return (int) $r['id']; }, all('SELECT "id" FROM "User" WHERE "username" LIKE ?', ['satest%']));
    tx(function () use ($ids, $uids) {
        q("SET LOCAL bet1x.allow_ledger_delete = 'on'");
        if ($ids) {
            $in = implode(',', $ids);
            foreach (['SubAdminLedger', 'SubAdminCash', 'SubAdminEvent', 'SubAdminWithdrawal', 'SubAdminDeposit', 'SubAdminAllocation', 'SubAdminUser'] as $t) {
                q('DELETE FROM "' . $t . '" WHERE "subadmin_id" IN (' . $in . ')');
            }
            q('DELETE FROM "SubAdmin" WHERE "id" IN (' . $in . ')');
        }
        if ($uids) q('DELETE FROM "SubAdminUser" WHERE "user_id" IN (' . implode(',', $uids) . ')');
    });
    foreach (['Withdrawal' => 'username', 'Deposit' => 'username', 'Transaction' => 'user', 'MinesSession' => 'username', 'User' => 'username'] as $t => $col) {
        try { q('DELETE FROM "' . $t . '" WHERE "' . $col . '" LIKE ?', ['satest%']); } catch (Throwable $e) {}
    }
    try { q('DELETE FROM "AdminLog" WHERE "actor" LIKE ?', ['satest%']); } catch (Throwable $e) {}
}
wipe();
$origSettings = state_get('subadmin_settings');
$houseBefore = (float) scalar('SELECT "wallet_balance" FROM "User" WHERE LOWER("username") = \'admin\'', [], 0);

$ADMIN = issue_token(['id' => 0, 'username' => 'admin', 'role' => 'admin']);
$SUPER = issue_token(['id' => 0, 'username' => 'superadmin', 'role' => 'superadmin']);
function player($name, $balance = 0) {
    $row = get_or_create_user($name, true);
    q('UPDATE "User" SET "wallet_balance" = ? WHERE "id" = ?', [$balance, (int) $row['id']]);
    return ['id' => (int) $row['id'], 'name' => $name, 'tok' => issue_token(['id' => (int) $row['id'], 'username' => $name, 'role' => 'user'])];
}
function wallet($userId) { return (float) scalar('SELECT "wallet_balance" FROM "User" WHERE "id" = ?', [(int) $userId], 0); }
function inv($saId) { return (float) scalar('SELECT "coin_balance" FROM "SubAdmin" WHERE "id" = ?', [(int) $saId], 0); }
function figures($saId) { return sa_figures($saId)[$saId]; }
function reconciled($saId, $label) {
    $f = figures($saId);
    check($f['reconciled'], "$label: stored inventory " . $f['coin_balance'] . ' == inventory re-derived from the coin ledger ' . $f['ledger_inventory']);
}
$P_OTHER = player('satest_p3', 3000);          // an ordinary player who belongs to no sub-admin

// =================================================================================================
section('1. Creating sub-admin accounts');
// =================================================================================================
[$c] = http('POST', '/api/admin/subadmins', null, ['full_name' => 'X Y', 'username' => 'satest_x']);
check($c === 401, "no session cannot create a sub-admin ($c)");
[$c] = http('POST', '/api/admin/subadmins', $P_OTHER['tok'], ['full_name' => 'X Y', 'username' => 'satest_x']);
check($c === 403, "a player cannot create a sub-admin ($c)");
[$c, $b] = http('POST', '/api/admin/subadmins', $ADMIN, ['full_name' => 'Bad', 'username' => 'no spaces allowed']);
check($c === 400, "an invalid username is refused ($c: " . ($b['error'] ?? '') . ')');
[$c, $b] = http('POST', '/api/admin/subadmins', $ADMIN, ['full_name' => 'Asha Verma', 'username' => 'satest_sa1', 'generate_password' => true]);
check($c === 200 && !empty($b['temporary_password']), 'the Admin creates sub-admin 1 with a generated temporary password');
$SA1 = $b['subadmin'] ?? []; $tmp1 = $b['temporary_password'] ?? '';
check(($SA1['must_change_password'] ?? false) === true && ($SA1['status'] ?? '') === 'active' && preg_match('/^SA-[A-Z0-9]{6}$/', $SA1['public_id'] ?? ''),
      'new account is active, has a unique SA- id and must change its password: ' . ($SA1['public_id'] ?? '?'));
$row = one('SELECT * FROM "SubAdmin" WHERE "id" = ?', [(int) ($SA1['id'] ?? 0)]);
check($row && strpos($row['password_hash'], '$2y$') === 0 && $row['password_hash'] !== $tmp1 && password_verify($tmp1, $row['password_hash']),
      'the password is stored only as a bcrypt hash');
check($row && $row['created_by'] === 'admin' && $row['created_at'] !== null, 'creation date and creator are recorded');
[$c] = http('POST', '/api/admin/subadmins', $SUPER, ['full_name' => 'Dup', 'username' => 'SATEST_SA1', 'password' => 'abcdefgh1']);
check($c === 409, "a duplicate username (any letter case) is refused ($c)");
[$c, $b] = http('POST', '/api/admin/subadmins', $SUPER, ['full_name' => 'Bilal Khan', 'username' => 'satest_sa2', 'password' => 'Initial#Pass2']);
check($c === 200 && ($b['temporary_password'] ?? null) === null, 'the Super Admin creates sub-admin 2 with a chosen initial password (not echoed back)');
$SA2 = $b['subadmin'] ?? [];
[$c, $b, $raw] = http('GET', '/api/admin/subadmins', $ADMIN);
check($c === 200 && count($b['subadmins'] ?? []) >= 2 && strpos($raw, 'password_hash') === false && strpos($raw, '$2y$') === false,
      'the list shows both accounts and never a password or hash');
check(($b['totals']['admin_supply'] ?? '') === 'unlimited', 'the Admin\'s supply is reported as unlimited, not as a balance');

// =================================================================================================
section('2. Sign-in, password change, reset');
// =================================================================================================
[$c] = http('POST', '/api/subadmin/login', null, ['username' => 'satest_sa1', 'password' => 'wrong-password']);
check($c === 401, "a wrong password is refused ($c)");
[$c, $b] = http('POST', '/api/subadmin/login', null, ['username' => 'Satest_SA1', 'password' => $tmp1]);
check($c === 200 && !empty($b['token']), 'sub-admin 1 signs in with the temporary password (username in any case)');
$T1 = $b['token'] ?? '';
[$c, $b] = http('GET', '/api/subadmin/me', $T1);
check($c === 200 && ($b['subadmin']['username'] ?? '') === 'satest_sa1' && ($b['subadmin']['must_change_password'] ?? false) === true, 'GET /me works and says the password must change');
[$c, $b] = http('GET', '/api/subadmin/summary', $T1);
check($c === 403 && ($b['code'] ?? '') === 'password_change_required', "everything else waits until the temporary password is changed ($c)");
[$c] = http('POST', '/api/subadmin/password', $T1, ['current_password' => 'nope', 'new_password' => 'Fresh#Pass111']);
check($c === 400, "changing needs the correct current password ($c)");
[$c, $b] = http('POST', '/api/subadmin/password', $T1, ['current_password' => $tmp1, 'new_password' => 'Fresh#Pass111']);
check($c === 200 && !empty($b['token']), 'sub-admin 1 changes the password and gets a fresh session');
$OLD1 = $T1; $T1 = $b['token'] ?? '';
[$c] = http('GET', '/api/subadmin/me', $OLD1);
check($c === 401, "the session from before the change no longer works ($c)");
[$c] = http('GET', '/api/subadmin/summary', $T1);
check($c === 200, "the new session reaches the dashboard ($c)");
[$c, $b] = http('POST', '/api/admin/subadmins/' . (int) $SA1['id'] . '/reset-password', $ADMIN);
check($c === 200 && !empty($b['temporary_password']), 'the Admin resets the password: a new temporary one is shown once');
$tmp1b = $b['temporary_password'] ?? '';
[$c] = http('GET', '/api/subadmin/me', $T1);
check($c === 401, "a reset signs the sub-admin out everywhere ($c)");
[$c, $b] = http('POST', '/api/subadmin/login', null, ['username' => 'satest_sa1', 'password' => $tmp1b]);
[$c2, $b2] = http('POST', '/api/subadmin/password', $b['token'] ?? '', ['current_password' => $tmp1b, 'new_password' => 'Second#Pass222']);
check($c === 200 && $c2 === 200, 'signs in with the reset password and sets a new one');
$T1 = $b2['token'] ?? '';
[$c, $b] = http('POST', '/api/subadmin/login', null, ['username' => 'satest_sa2', 'password' => 'Initial#Pass2']);
[$c2, $b2] = http('POST', '/api/subadmin/password', $b['token'] ?? '', ['current_password' => 'Initial#Pass2', 'new_password' => 'Bilal#Pass333']);
$T2 = $b2['token'] ?? '';
check($c === 200 && $c2 === 200 && $T2 !== '', 'sub-admin 2 signs in and changes the initial password');
$ev = all('SELECT "event" FROM "SubAdminEvent" WHERE "subadmin_id" = ? ORDER BY "id"', [(int) $SA1['id']]);
$evs = array_column($ev, 'event');
check(in_array('created', $evs, true) && in_array('password_changed', $evs, true) && in_array('password_reset', $evs, true), 'the account history records creation, password change and reset: ' . implode(', ', $evs));

// =================================================================================================
section('3. A sub-admin session has no other authority');
// =================================================================================================
$TWIN = player('satest_sa1', 777);            // a PLAYER who happens to share sub-admin 1's username
foreach (['/api/admin/subadmins' => 'GET', '/api/admin.php?action=users' => 'GET', '/api/wallet/balance' => 'GET', '/api/admin/super-dashboard' => 'GET'] as $path => $m) {
    [$c] = http($m, $path, $T1);
    check($c === 401 || $c === 403 || $c === 404, "sub-admin token is refused at $path ($c)");
}
[$c, $b] = http('GET', '/api/auth/status', $T1);
check($c === 200 && ($b['logged_in'] ?? null) === false, 'to the player session check a sub-admin token is no session at all (never the same-named player)');
[$c] = http('POST', '/api/mines/start', $T1, ['bet_amount' => 10, 'mines_count' => 1]);
check($c === 401, "a sub-admin token cannot bet with the same-named player's coins ($c)");
check(near(wallet($TWIN['id']), 777), 'the same-named player\'s wallet is untouched');
[$c] = http('GET', '/api/subadmin/summary', $TWIN['tok']);
check($c === 401, "a player token cannot open the sub-admin console ($c)");
[$c] = http('GET', '/api/subadmin/summary', $ADMIN);
check($c === 401, "an Admin token is not a sub-admin session either ($c)");

// =================================================================================================
section('4. Allocations from the unlimited supply — credit after payment');
// =================================================================================================
http('POST', '/api/admin/subadmin-settings', $ADMIN, ['allocation_credit' => 'after_payment']);
$sa1 = (int) $SA1['id']; $sa2 = (int) $SA2['id'];
$k = key8();
[$c, $b] = http('POST', "/api/admin/subadmins/$sa1/allocations", $ADMIN,
    ['coins' => 5000, 'agreed_amount' => 500, 'payment_method' => 'UPI', 'payment_reference' => 'SATESTUTR0001', 'idempotency_key' => $k]);
$A1 = $b['allocation'] ?? [];
check($c === 200 && ($A1['status'] ?? '') === 'awaiting_payment' && near(inv($sa1), 0), 'allocating 5,000 coins for ₹500 waits for the payment: no coins yet');
check(($A1['payment_status'] ?? '') === 'Awaiting confirmation' && count($A1['payments'] ?? []) === 1, 'the payment is recorded separately, linked to the allocation, awaiting confirmation');
[$c, $b] = http('POST', "/api/admin/subadmins/$sa1/allocations", $ADMIN,
    ['coins' => 5000, 'agreed_amount' => 500, 'payment_method' => 'UPI', 'payment_reference' => 'SATESTUTR0001', 'idempotency_key' => $k]);
check($c === 200 && !empty($b['replayed']) && (int) scalar('SELECT COUNT(*) FROM "SubAdminAllocation" WHERE "subadmin_id" = ?', [$sa1]) === 1,
      'submitting the same form twice creates one allocation');
$pay1 = $A1['payments'][0]['id'] ?? '';
[$c] = http('POST', "/api/admin/subadmin-payments/$pay1/confirm", $T1);
check($c === 401, "a sub-admin cannot confirm their own payment to the Admin ($c)");
$res = burst("/api/admin/subadmin-payments/$pay1/confirm", $ADMIN, (object) []);
check(oks($res) === 1, 'eight simultaneous confirmations: exactly one succeeds (' . oks($res) . ')');
check(near(inv($sa1), 5000), 'after the payment is confirmed the 5,000 coins reach the inventory exactly once (' . inv($sa1) . ')');
$a = one('SELECT * FROM "SubAdminAllocation" WHERE "id" = ?', [$A1['id']]);
$cash = one('SELECT * FROM "SubAdminCash" WHERE "id" = ?', [$pay1]);
check($a['status'] === 'credited' && $a['credited_at'] !== null && $cash['status'] === 'Confirmed' && $cash['confirmed_by'] === 'admin' && $cash['confirmed_at'] !== null,
      'allocation credited; payment confirmed with who and when');
[$c] = http('POST', "/api/admin/subadmin-payments/$pay1/confirm", $ADMIN);
check($c === 409, "confirming again is refused ($c)");
[$c, $b] = http('POST', "/api/admin/subadmins/$sa2/allocations", $ADMIN,
    ['coins' => 100, 'agreed_amount' => 10, 'payment_method' => 'UPI', 'payment_reference' => 'satestutr0001', 'idempotency_key' => key8()]);
check($c === 409 && ($b['code'] ?? '') === 'duplicate_reference', "the same payment reference cannot back a second payment, in any letter case ($c)");
check(near((float) scalar('SELECT "wallet_balance" FROM "User" WHERE LOWER("username") = \'admin\'', [], 0), $houseBefore), 'nothing was taken from any Admin balance (the house account is untouched)');
reconciled($sa1, 'sub-admin 1');

// A second allocation paid in two parts, the second recorded by the sub-admin themself.
[$c, $b] = http('POST', "/api/admin/subadmins/$sa1/allocations", $ADMIN, ['coins' => 2000, 'agreed_amount' => 200, 'idempotency_key' => key8()]);
$A2 = $b['allocation'] ?? [];
check($c === 200 && ($A2['payment_status'] ?? '') === 'Unpaid' && near(inv($sa1), 5000), 'an allocation with no payment yet: Unpaid, nothing credited');
[$c, $b] = http('POST', '/api/admin/subadmin-allocations/' . $A2['id'] . '/payments', $ADMIN, ['amount' => 120, 'method' => 'Cash', 'confirm' => true]);
check($c === 200 && near(inv($sa1), 5000) && ($b['allocation']['payment_status'] ?? '') === 'Part paid', 'a part payment confirmed: still not credited (₹120 of ₹200)');
[$c] = http('POST', '/api/subadmin/allocations/' . $A2['id'] . '/payments', $T1, ['amount' => 90, 'method' => 'UPI']);
check($c === 409, "recording more than is still owed is refused ($c)");
[$c, $b] = http('POST', '/api/subadmin/allocations/' . $A2['id'] . '/payments', $T1, ['amount' => 80, 'method' => 'UPI', 'reference' => 'SATESTUTR0002', 'confirm' => true]);
$pay2 = $b['payment']['id'] ?? '';
check($c === 200 && ($b['payment']['status'] ?? '') === 'Unconfirmed', 'the sub-admin records the rest as paid; it stays Unconfirmed (they cannot confirm it)');
[$c] = http('POST', '/api/subadmin/allocations/' . $A1['id'] . '/payments', $T2, ['amount' => 1, 'method' => 'UPI']);
check($c === 404, "another sub-admin cannot even see this allocation ($c)");
http('POST', "/api/admin/subadmin-payments/$pay2/confirm", $ADMIN);
check(near(inv($sa1), 7000), 'once fully paid and confirmed, the 2,000 coins are credited (' . inv($sa1) . ')');

// Cancel, fail, reverse.
[$c, $b] = http('POST', "/api/admin/subadmins/$sa1/allocations", $ADMIN, ['coins' => 300, 'agreed_amount' => 30, 'payment_method' => 'UPI', 'idempotency_key' => key8()]);
$A3 = $b['allocation'] ?? [];
[$c] = http('POST', '/api/admin/subadmin-payments/' . ($A3['payments'][0]['id'] ?? 'x') . '/fail', $ADMIN, ['reason' => 'never arrived']);
[$c2] = http('POST', '/api/admin/subadmin-allocations/' . $A3['id'] . '/cancel', $ADMIN, ['reason' => 'deal called off']);
check($c === 200 && $c2 === 200 && one('SELECT "status" FROM "SubAdminAllocation" WHERE "id" = ?', [$A3['id']])['status'] === 'cancelled' && near(inv($sa1), 7000),
      'a payment marked failed and the allocation cancelled: no coins moved');
[$c] = http('POST', '/api/admin/subadmin-allocations/' . $A3['id'] . '/cancel', $ADMIN, ['reason' => 'again']);
check($c === 409, "cancelling twice is refused ($c)");

// =================================================================================================
section('5. Allocations — credit immediately (on credit)');
// =================================================================================================
http('POST', '/api/admin/subadmin-settings', $SUPER, ['allocation_credit' => 'immediate']);
[$c, $b] = http('POST', "/api/admin/subadmins/$sa2/allocations", $ADMIN, ['coins' => 3000, 'agreed_amount' => 300, 'idempotency_key' => key8()]);
$B1 = $b['allocation'] ?? [];
check($c === 200 && ($B1['status'] ?? '') === 'credited' && near(inv($sa2), 3000), 'in "immediate" mode the coins arrive at once');
$f2 = figures($sa2);
check(near($f2['owed_admin'], 300) && $f2['finalized_profit_loss'] === null, 'the unpaid ₹300 is an outstanding obligation, and there is no final profit figure while it is');
[$c, $b] = http('POST', '/api/admin/subadmin-allocations/' . $B1['id'] . '/payments', $ADMIN, ['amount' => 300, 'method' => 'Bank transfer', 'reference' => 'SATESTBANK01', 'confirm' => true]);
check($c === 200 && near(figures($sa2)['owed_admin'], 0), 'once the Admin confirms the ₹300, nothing is owed');
http('POST', '/api/admin/subadmin-settings', $ADMIN, ['allocation_credit' => 'after_payment']);
reconciled($sa2, 'sub-admin 2');

// =================================================================================================
section('6. Users');
// =================================================================================================
[$c, $b] = http('POST', '/api/subadmin/users', $T1, ['username' => 'satest_u1', 'password' => 'UserPass#123']);
$U1 = ['id' => (int) ($b['user']['id'] ?? 0), 'name' => 'satest_u1'];
check($c === 200 && $U1['id'] > 0 && near(wallet($U1['id']), 0), 'sub-admin 1 opens a player account: it starts at 0 coins (no signup bonus)');
[$c, $b] = http('POST', '/api/auth/login', null, ['username' => 'satest_u1', 'password' => 'UserPass#123']);
check($c === 200 && !empty($b['token']), 'the new player signs in through the normal login');
$U1['tok'] = $b['token'] ?? '';
[$c] = http('POST', '/api/subadmin/users', $T2, ['username' => 'satest_U1', 'password' => 'UserPass#123']);
check($c === 409, "the username is unique across the platform ($c)");
$P2 = player('satest_p2', 1500);
[$c] = http('POST', "/api/admin/subadmins/$sa1/assign", $ADMIN, ['username' => 'satest_p2']);
check($c === 200 && (int) scalar('SELECT "subadmin_id" FROM "SubAdminUser" WHERE "user_id" = ?', [$P2['id']]) === $sa1, 'the Admin assigns an existing player to sub-admin 1');
[$c, $b] = http('POST', "/api/admin/subadmins/$sa2/assign", $ADMIN, ['username' => 'satest_p2']);
check($c === 409 && ($b['code'] ?? '') === 'already_assigned', 'moving a player who already has a sub-admin needs an explicit confirmation');
[$c] = http('POST', "/api/admin/subadmins/$sa1/assign", $ADMIN, ['username' => 'admin']);
check($c === 400, "the house account can never be assigned ($c)");
check(scalar('SELECT COUNT(*) FROM "SubAdminUser" WHERE "user_id" = ?', [$P_OTHER['id']]) == 0, 'an existing player nobody assigned stays unassigned');
[$c, $b] = http('GET', '/api/subadmin/users', $T1);
$names = array_column($b['users'] ?? [], 'username');
check($c === 200 && in_array('satest_u1', $names, true) && in_array('satest_p2', $names, true) && !in_array('satest_p3', $names, true), 'My Users lists exactly the assigned players');
[$c] = http('GET', '/api/subadmin/users/' . $U1['id'], $T2);
check($c === 404, "another sub-admin cannot open that player ($c)");

// =================================================================================================
section('7. User deposits');
// =================================================================================================
$k = key8();
$dep = ['user_id' => $U1['id'], 'coins' => 500, 'amount_inr' => 500, 'method' => 'UPI', 'reference' => 'SATESTDEP0001', 'stage' => 'confirmed', 'idempotency_key' => $k];
[$c, $b] = http('POST', '/api/subadmin/deposits', $T1, $dep);
$D1 = $b['deposit'] ?? [];
check($c === 200 && ($D1['status'] ?? '') === 'Confirmed', 'a paid deposit is recorded and confirmed in one step');
check(near(wallet($U1['id']), 500) && near(inv($sa1), 6500), 'the player gets 500 coins; the inventory drops by 500 (' . inv($sa1) . ')');
[$c, $b] = http('POST', '/api/subadmin/deposits', $T1, $dep);
check($c === 200 && !empty($b['replayed']) && near(wallet($U1['id']), 500), 'the same submission again credits nothing more');
$t = one('SELECT * FROM "Transaction" WHERE "user" = ? AND "details" LIKE ?', ['satest_u1', 'Sub-Admin Deposit ' . $D1['id'] . '%']);
check($t && $t['type'] === 'Deposit' && near($t['amount'], 500) && $t['status'] === 'Completed', 'the player\'s own transaction history shows the deposit');
$l = one('SELECT * FROM "SubAdminLedger" WHERE "ref" = ? AND "type" = \'user_credit\'', [$D1['id']]);
check($l && $l['from_account'] === 'subadmin:' . $sa1 && $l['to_account'] === 'user:' . $U1['id'] && near($l['rupees'], 500) && $l['actor'] === 'satest_sa1',
      'the coin ledger names the user, the sub-admin, the coins, the rupees and who did it');
// Requested -> payment recorded -> confirmed, with a double-click burst on the confirm.
[$c, $b] = http('POST', '/api/subadmin/deposits', $T1, ['user_id' => $P2['id'], 'coins' => 250, 'amount_inr' => 250, 'stage' => 'requested', 'idempotency_key' => key8()]);
$D2 = $b['deposit'] ?? [];
check($c === 200 && ($D2['status'] ?? '') === 'Requested' && near(wallet($P2['id']), 1500), 'a requested deposit moves nothing');
[$c, $b] = http('POST', '/api/subadmin/deposits/' . $D2['id'] . '/payment', $T1, ['amount_inr' => 250, 'method' => 'Cash']);
check($c === 200 && ($b['deposit']['status'] ?? '') === 'Unconfirmed' && near(wallet($P2['id']), 1500), 'a recorded but unconfirmed payment moves nothing');
$res = burst('/api/subadmin/deposits/' . $D2['id'] . '/confirm', $T1, (object) []);
check(oks($res) === 1 && near(wallet($P2['id']), 1750) && near(inv($sa1), 6250), 'eight simultaneous confirms credit the player exactly once (' . oks($res) . ' ok, wallet ' . wallet($P2['id']) . ')');
// Not enough inventory: nothing at all is recorded.
$before = (int) scalar('SELECT COUNT(*) FROM "SubAdminDeposit" WHERE "subadmin_id" = ?', [$sa1]);
[$c, $b] = http('POST', '/api/subadmin/deposits', $T1, ['user_id' => $U1['id'], 'coins' => 999999, 'amount_inr' => 999999, 'method' => 'UPI', 'stage' => 'confirmed', 'idempotency_key' => key8()]);
check($c === 409 && ($b['code'] ?? '') === 'insufficient_inventory' && (int) scalar('SELECT COUNT(*) FROM "SubAdminDeposit" WHERE "subadmin_id" = ?', [$sa1]) === $before && near(inv($sa1), 6250),
      'a deposit larger than the inventory is refused and nothing is recorded (never a negative inventory)');
[$c] = http('POST', '/api/subadmin/deposits', $T2, ['user_id' => $U1['id'], 'coins' => 10, 'amount_inr' => 10, 'method' => 'UPI', 'stage' => 'confirmed', 'idempotency_key' => key8()]);
check($c === 404, "sub-admin 2 cannot credit sub-admin 1's player ($c)");
[$c] = http('POST', '/api/subadmin/deposits/' . $D1['id'] . '/cancel', $T2, ['reason' => 'not mine']);
check($c === 404, "nor touch sub-admin 1's deposits ($c)");
[$c, $b] = http('POST', '/api/subadmin/deposits', $T1, ['user_id' => $U1['id'], 'coins' => 40, 'amount_inr' => 40, 'method' => 'UPI', 'reference' => 'SATESTDEP0002', 'stage' => 'unconfirmed', 'idempotency_key' => key8()]);
$D3 = $b['deposit'] ?? [];
[$c] = http('POST', '/api/subadmin/deposits/' . $D3['id'] . '/cancel', $T1, ['reason' => 'payment bounced']);
check($c === 200 && one('SELECT "status" FROM "SubAdminCash" WHERE "id" = ?', [$D3['cash_id']])['status'] === 'Failed' && near(wallet($U1['id']), 500),
      'cancelling an unconfirmed deposit marks its payment failed and moves no coins');
[$c] = http('POST', '/api/subadmin/deposits', $T1, ['user_id' => $U1['id'], 'coins' => 5, 'amount_inr' => 5, 'method' => 'UPI', 'reference' => 'SATESTDEP0001', 'stage' => 'unconfirmed', 'idempotency_key' => key8()]);
check($c === 409, "a payment reference already used for a confirmed deposit is refused ($c)");
reconciled($sa1, 'sub-admin 1 after deposits');

// =================================================================================================
section('8. Game play does not touch the inventory');
// =================================================================================================
$invBefore = inv($sa1); $wBefore = wallet($U1['id']);
for ($i = 0; $i < 4; $i++) {
    [$c] = http('POST', '/api/mines/start', $U1['tok'], ['bet_amount' => 10, 'mines_count' => 1]);
    if ($c !== 200) break;
    [$c2, $b2] = http('POST', '/api/mines/reveal', $U1['tok'], ['index' => $i]);
    http('POST', '/api/mines/cashout', $U1['tok']);
}
$bets = (float) scalar('SELECT COALESCE(SUM("amount"),0) FROM "Transaction" WHERE "user" = ? AND "details" LIKE \'Mines Bet%\'', ['satest_u1'], 0);
$wins = (float) scalar('SELECT COALESCE(SUM("amount"),0) FROM "Transaction" WHERE "user" = ? AND "details" LIKE \'Mines Cash Out%\'', ['satest_u1'], 0);
check($bets > 0, 'the player played real Mines rounds (₹' . $bets . ' staked, ₹' . $wins . ' paid)');
check(near(wallet($U1['id']), $wBefore - $bets + $wins), 'their wallet moved by exactly stakes and payouts, through the existing game code');
check(near(inv($sa1), $invBefore), 'the sub-admin\'s inventory did not move: losses go to the platform, wins come from it');
[$c, $b] = http('GET', '/api/subadmin/summary', $T1);
$act = $b['game_activity'] ?? ['wagered' => -1, 'won' => -1];
check($c === 200 && near($act['wagered'], $bets) && near($act['won'], $wins), 'the play is attributed to sub-admin 1 for reporting (wagered ' . $act['wagered'] . ', won ' . $act['won'] . ')');
[$c, $b] = http('GET', "/api/admin/subadmins/$sa1", $ADMIN);
check($c === 200 && near($b['game_activity']['wagered'] ?? -1, $bets), 'and the Admin sees the same activity by operator');

// =================================================================================================
section('9. Withdrawals through the existing cashier');
// =================================================================================================
$wd = function ($tok, $amount) { return http('POST', '/api/withdraw.php', $tok, ['amount' => $amount, 'method' => 'upi', 'upi_id' => 'satest@upi']); };
$w0 = wallet($U1['id']);
[$c, $b] = $wd($U1['tok'], 200);
$W1 = $b['withdrawal_id'] ?? '';
check($c === 200 && ($b['handled_by_subadmin'] ?? '') === 'satest_sa1' && near(wallet($U1['id']), $w0 - 200), 'the player\'s normal withdrawal holds 200 coins and goes to sub-admin 1');
[$c, $b] = http('POST', '/api/admin.php?action=approve_withdrawal', $ADMIN, ['withdrawal_id' => $W1]);
check($c === 409, "the Admin's own Approve refuses it, so it can never be paid twice ($c)");
[$c, $b] = http('GET', '/api/admin.php?action=withdrawals', $ADMIN);
$hit = array_values(array_filter((array) $b, function ($w) use ($W1) { return ($w['withdrawal_id'] ?? '') === $W1; }));
check($hit && ($hit[0]['handled_by_subadmin'] ?? '') === 'satest_sa1', 'the Admin\'s cashout list shows who is handling it');
[$c, $b] = http('GET', '/api/subadmin/withdrawals?status=open', $T1);
$mine = array_values(array_filter($b['withdrawals'] ?? [], function ($w) use ($W1) { return $w['withdrawal_id'] === $W1; }));
check($c === 200 && $mine && near($mine[0]['coins'], 200) && near($mine[0]['rupee_equivalent'], 200 * (float) cfg('COIN_RUPEE_RATE')) && $mine[0]['status'] === 'Pending'
      && strpos((string) $mine[0]['destination'], 'satest@upi') !== false, 'sub-admin 1 sees the user, coins, rupee value (configured rate), payout destination and status');
[$c] = http('POST', "/api/subadmin/withdrawals/$W1/paid", $T2, ['amount_paid' => 200, 'method' => 'UPI', 'confirm' => true]);
check($c === 404, "sub-admin 2 cannot act on it ($c)");
[$c] = http('POST', "/api/subadmin/withdrawals/$W1/paid", $T1, ['amount_paid' => 200, 'method' => 'UPI']);
check($c === 400, "marking paid needs an explicit payout confirmation ($c)");
[$c] = http('POST', "/api/subadmin/withdrawals/$W1/approve", $T1);
check($c === 200 && one('SELECT "status" FROM "Withdrawal" WHERE "withdrawal_id" = ?', [$W1])['status'] === 'Pending', 'approved: the coins stay held, the request is not treated as paid');
$invB = inv($sa1);
$res = burst("/api/subadmin/withdrawals/$W1/paid", $T1, ['amount_paid' => 200, 'method' => 'UPI', 'reference' => 'SATESTPAY0001', 'confirm' => true]);
check(oks($res) === 1, 'eight simultaneous "paid" clicks: exactly one succeeds (' . oks($res) . ')');
$wrow = one('SELECT * FROM "Withdrawal" WHERE "withdrawal_id" = ?', [$W1]);
$srow = one('SELECT * FROM "SubAdminWithdrawal" WHERE "withdrawal_id" = ?', [$W1]);
check($wrow['status'] === 'Completed' && $srow['status'] === 'Paid' && near($srow['paid_amount'], 200) && $srow['paid_by'] === 'satest_sa1' && $srow['paid_at'] !== null,
      'paid: amount, method, reference, who and when are recorded; the player\'s request is Completed');
check(one('SELECT "status" FROM "Transaction" WHERE "user" = ? AND "details" LIKE ?', ['satest_u1', 'Withdrawal Request ' . $W1 . '%'])['status'] === 'Completed', 'the player\'s history shows the withdrawal Completed');
check(near(inv($sa1), $invB) && one('SELECT "to_account" FROM "SubAdminLedger" WHERE "ref" = ? AND "type" = \'withdrawal_paid\'', [$W1])['to_account'] === 'admin_supply',
      'the paid-out coins go back to the Admin supply, not to the sub-admin inventory (fixed rule)');
// Reject: coins back to the player.
$w0 = wallet($U1['id']);
[$c, $b] = $wd($U1['tok'], 100);
$W2 = $b['withdrawal_id'] ?? '';
[$c] = http('POST', "/api/subadmin/withdrawals/$W2/reject", $T1, ['reason' => 'wrong UPI id']);
check($c === 200 && near(wallet($U1['id']), $w0) && one('SELECT "status" FROM "Withdrawal" WHERE "withdrawal_id" = ?', [$W2])['status'] === 'Rejected', 'a rejected request gives the held coins back');
[$c] = http('POST', "/api/subadmin/withdrawals/$W2/paid", $T1, ['amount_paid' => 100, 'method' => 'UPI', 'confirm' => true]);
check($c === 409, "a rejected request cannot then be paid ($c)");
// Reverse a paid one (admin correction).
[$c, $b] = $wd($U1['tok'], 150);
$W3 = $b['withdrawal_id'] ?? '';
http('POST', "/api/subadmin/withdrawals/$W3/paid", $T1, ['amount_paid' => 150, 'method' => 'Cash', 'confirm' => true]);
$w0 = wallet($U1['id']); $i0 = inv($sa1);
[$c] = http('POST', "/api/subadmin/withdrawals/$W3/approve", $T1);
check($c === 409, "a paid request cannot be approved again ($c)");
[$c] = http('POST', "/api/admin/subadmin-withdrawals/$W3/reverse", $T1, ['reason' => 'x']);
check($c === 401, "a sub-admin cannot reverse a payout; that is an Admin correction ($c)");
[$c] = http('POST', "/api/admin/subadmin-withdrawals/$W3/reverse", $ADMIN, ['reason' => 'payout bounced back']);
check($c === 200 && near(wallet($U1['id']), $w0 + 150) && near(inv($sa1), $i0) && one('SELECT "status" FROM "Withdrawal" WHERE "withdrawal_id" = ?', [$W3])['status'] === 'Reversed',
      'the Admin reverses a bounced payout: coins back to the player (from the Admin supply, inventory untouched), request marked Reversed');
$pc = one('SELECT * FROM "SubAdminCash" WHERE "withdrawal_id" = ? AND "kind" = \'user_payout\'', [$W3]);
check($pc['status'] === 'Reversed' && (int) scalar('SELECT COUNT(*) FROM "SubAdminCash" WHERE "reverses" = ?', [$pc['id']]) === 1, 'the payout record is kept, marked Reversed, with a reversal entry pointing at it');
// The rule is fixed: an attempt to switch it is ignored and the next payout still retires the coins.
http('POST', '/api/admin/subadmin-settings', $ADMIN, ['withdrawal_coins_to' => 'subadmin']);
[$c, $b] = $wd($U1['tok'], 100);
$W4 = $b['withdrawal_id'] ?? ''; $i0 = inv($sa1);
http('POST', "/api/subadmin/withdrawals/$W4/paid", $T1, ['amount_paid' => 100, 'method' => 'UPI', 'confirm' => true]);
check(sa_settings()['withdrawal_coins_to'] === 'admin_supply' && near(inv($sa1), $i0) && one('SELECT "to_account" FROM "SubAdminLedger" WHERE "ref" = ? AND "type" = \'withdrawal_paid\'', [$W4])['to_account'] === 'admin_supply',
      'the rule cannot be switched: payouts always return the coins to the Admin supply');
// Hand a pending request back to the Admin by unassigning the player.
[$c, $b] = $wd($P2['tok'], 120);
$W5 = $b['withdrawal_id'] ?? '';
[$c] = http('POST', "/api/admin/subadmins/$sa1/unassign", $ADMIN, ['user_id' => $P2['id']]);
check($c === 200 && one('SELECT "status" FROM "SubAdminWithdrawal" WHERE "withdrawal_id" = ?', [$W5])['status'] === 'Returned', 'unassigning a player returns their pending request to the Admin');
[$c] = http('POST', '/api/admin.php?action=approve_withdrawal', $ADMIN, ['withdrawal_id' => $W5]);
check($c === 200 && one('SELECT "status" FROM "Withdrawal" WHERE "withdrawal_id" = ?', [$W5])['status'] === 'Completed', 'and the Admin\'s existing Approve now handles it as before');
[$c, $b] = $wd($P_OTHER['tok'], 100);
check($c === 200 && ($b['handled_by_subadmin'] ?? null) === null && !one('SELECT 1 FROM "SubAdminWithdrawal" WHERE "withdrawal_id" = ?', [$b['withdrawal_id'] ?? '']),
      'a player with no sub-admin withdraws exactly as before (Admin queue)');
[$c] = http('POST', '/api/admin.php?action=reject_withdrawal', $ADMIN, ['withdrawal_id' => $b['withdrawal_id'] ?? '']);
check($c === 200 && near(wallet($P_OTHER['id']), 3000), 'and the Admin\'s existing Reject refunds it as before');
reconciled($sa1, 'sub-admin 1 after withdrawals');

// =================================================================================================
section('10. Admin corrections');
// =================================================================================================
$w0 = wallet($U1['id']); $i0 = inv($sa1);
[$c, $b] = http('POST', '/api/subadmin/deposits', $T1, ['user_id' => $U1['id'], 'coins' => 60, 'amount_inr' => 60, 'method' => 'UPI', 'stage' => 'confirmed', 'idempotency_key' => key8()]);
$D4 = $b['deposit'] ?? [];
[$c] = http('POST', '/api/admin/subadmin-deposits/' . $D4['id'] . '/reverse', $T1, ['reason' => 'x']);
check($c === 401, "a sub-admin cannot reverse a confirmed deposit ($c)");
[$c] = http('POST', '/api/admin/subadmin-deposits/' . $D4['id'] . '/reverse', $ADMIN, ['reason' => 'credited the wrong player']);
check($c === 200 && near(wallet($U1['id']), $w0) && near(inv($sa1), $i0), 'the Admin reverses a deposit: coins back from the player into the inventory');
[$c] = http('POST', '/api/admin/subadmin-deposits/' . $D4['id'] . '/reverse', $ADMIN, ['reason' => 'again']);
check($c === 409, "a reversal cannot be repeated ($c)");
[$c, $b] = http('POST', "/api/admin/subadmins/$sa1/adjust", $ADMIN, ['coins' => -99999999, 'reason' => 'too much']);
check($c === 409 && ($b['code'] ?? '') === 'insufficient_inventory', 'an adjustment can never push the inventory below zero');
[$c] = http('POST', "/api/admin/subadmins/$sa1/adjust", $ADMIN, ['coins' => 25, 'reason' => 'goodwill top-up']);
check($c === 200 && near(inv($sa1), $i0 + 25), 'an authorised adjustment is recorded with its reason');
http('POST', "/api/admin/subadmins/$sa1/adjust", $ADMIN, ['coins' => -25, 'reason' => 'undo the top-up']);
$i0 = inv($sa1);
[$c] = http('POST', '/api/admin/subadmin-allocations/' . $A2['id'] . '/reverse', $ADMIN, ['reason' => 'allocation made in error']);
check($c === 200 && near(inv($sa1), $i0 - 2000), 'the Admin reverses a credited allocation; its coins leave the inventory');
try {
    q('DELETE FROM "SubAdminLedger" WHERE "subadmin_id" = ?', [$sa1]);
    $deleted = true;
} catch (Throwable $e) { $deleted = false; }
check(!$deleted, 'the database itself refuses to delete coin-ledger rows');
try { q('UPDATE "SubAdminLedger" SET "coins" = 1 WHERE "subadmin_id" = ?', [$sa1]); $edited = true; } catch (Throwable $e) { $edited = false; }
check(!$edited, 'and refuses to edit them');
reconciled($sa1, 'sub-admin 1 after corrections');

// =================================================================================================
section('11. Suspension');
// =================================================================================================
[$c] = http('POST', "/api/admin/subadmins/$sa2/status", $ADMIN, ['status' => 'suspended']);
check($c === 400, "suspending needs a reason ($c)");
$invS = inv($sa2);
[$c] = http('POST', "/api/admin/subadmins/$sa2/status", $ADMIN, ['status' => 'suspended', 'reason' => 'audit in progress']);
check($c === 200, 'the Admin suspends sub-admin 2');
[$c] = http('GET', '/api/subadmin/me', $T2);
check($c === 401, "the suspended sub-admin's open session stops working at once ($c)");
[$c, $b] = http('POST', '/api/subadmin/login', null, ['username' => 'satest_sa2', 'password' => 'Bilal#Pass333']);
check($c === 403 && ($b['code'] ?? '') === 'suspended', "and they cannot sign in ($c)");
check(near(inv($sa2), $invS) && (int) scalar('SELECT COUNT(*) FROM "SubAdminAllocation" WHERE "subadmin_id" = ?', [$sa2]) > 0, 'nothing is deleted: balance and history are kept');
[$c] = http('POST', "/api/admin/subadmins/$sa2/allocations", $ADMIN, ['coins' => 10, 'agreed_amount' => 1, 'idempotency_key' => key8()]);
check($c === 409, "no allocations to a suspended account ($c)");
[$c] = http('POST', "/api/admin/subadmins/$sa2/status", $SUPER, ['status' => 'active']);
[$c2] = http('POST', '/api/subadmin/login', null, ['username' => 'satest_sa2', 'password' => 'Bilal#Pass333']);
check($c === 200 && $c2 === 200, 'the Super Admin reactivates the account; sign-in works again');

// =================================================================================================
section('12. Reports, history and reconciliation');
// =================================================================================================
$f1 = figures($sa1);
$cashIn = (float) scalar('SELECT COALESCE(SUM("amount_inr"),0) FROM "SubAdminDeposit" WHERE "subadmin_id" = ? AND "status" = \'Confirmed\'', [$sa1], 0);
check(near($f1['cash_from_users_confirmed'], $cashIn), 'cash received from users equals the confirmed deposits (₹' . $cashIn . ')');
$paidOut = (float) scalar('SELECT COALESCE(SUM("paid_amount"),0) FROM "SubAdminWithdrawal" WHERE "subadmin_id" = ? AND "status" = \'Paid\'', [$sa1], 0);
check(near($f1['payout_confirmed_inr'], $paidOut), 'cash paid to users equals the paid (not reversed) withdrawals (₹' . $paidOut . ')');
check(near($f1['paid_admin_confirmed'], 500 + 120 + 80), 'cash paid to the Admin = the confirmed allocation payments (₹700)');
check(near($f1['gross_cash_surplus'], $cashIn - $paidOut) && near($f1['cash_balance'], $cashIn - $paidOut - 700),
      'gross cash surplus and cash balance follow the stated rules (' . $f1['gross_cash_surplus'] . ' / ' . $f1['cash_balance'] . ')');
check(near($f1['estimated_net_position'], $f1['cash_balance'] - $f1['outstanding_liabilities']), 'the estimated position = cash balance − outstanding liabilities');
[$c, $b] = http('GET', '/api/subadmin/report', $T1);
check($c === 200 && near($b['report']['period']['cash_from_users'] ?? -1, $cashIn) && !empty($b['report']['rules']), 'the sub-admin\'s report matches the same records and states its rules');
[$c, $b2] = http('GET', "/api/admin/subadmins/$sa1/report", $SUPER);
check($c === 200 && json_encode($b2['report']['period']) === json_encode($b['report']['period']), 'the Super Admin sees exactly the same report figures');
[$c, $b] = http('GET', '/api/subadmin/history?book=coin&type=user_credit', $T1);
$types = array_unique(array_column($b['rows'] ?? [], 'type'));
check($c === 200 && $types === ['user_credit'], 'history filters by transaction type');
[$c, $b] = http('GET', '/api/subadmin/history?book=cash&status=Failed', $T1);
check($c === 200 && $b['rows'] && count(array_filter($b['rows'], function ($r) { return $r['status'] !== 'Failed'; })) === 0, 'and by status');
$today = gmdate('Y-m-d', (int) ((now_ms() + 330 * 60000) / 1000));
[$c, $b] = http('GET', "/api/subadmin/history?from=$today&to=$today", $T1);
[$c2, $b2] = http('GET', '/api/subadmin/history?from=2001-01-01&to=2001-01-02', $T1);
check($c === 200 && count($b['rows']) > 0 && count($b2['rows']) === 0, 'and by date range');
check(count(array_filter($b['rows'], function ($r) use ($sa1) { return (int) $r['subadmin_id'] !== $sa1; })) === 0, 'a sub-admin\'s history only ever contains their own records');
[$c, $b] = http('GET', '/api/admin/subadmin-history?user=satest_u1', $SUPER);
check($c === 200 && count($b['rows']) > 0 && count(array_filter($b['rows'], function ($r) { return stripos((string) $r['username'], 'satest_u1') === false; })) === 0,
      'the Super Admin\'s platform history filters by user');
$who = one('SELECT "created_by","confirmed_by" FROM "SubAdminCash" WHERE "id" = ?', [$pay2]);
check($who['created_by'] === 'satest_sa1' && $who['confirmed_by'] === 'admin', 'every record says who created it and who confirmed it');
[$c, $b] = http('GET', '/api/admin/subadmins', $SUPER);
check($c === 200 && !empty($b['totals']['all_reconciled']), 'platform-wide: every sub-admin\'s inventory agrees with its ledger');
[$c] = http('GET', '/api/admin/subadmins', $P_OTHER['tok']);
check($c === 403, "a player cannot read any of it ($c)");
check((int) scalar('SELECT COUNT(*) FROM "AdminLog" WHERE "actor" = \'satest_sa1\' AND "role" = \'subadmin\'', [], 0) > 0, 'sub-admin actions are in the operator action log');
check(near((float) scalar('SELECT "wallet_balance" FROM "User" WHERE LOWER("username") = \'admin\'', [], 0), $houseBefore), 'from start to finish nothing touched an Admin balance');

// =================================================================================================
section('13. Existing features keep working');
// =================================================================================================
[$c, $b] = http('POST', '/api/deposit.php', $P_OTHER['tok'], ['amount' => 500, 'action' => 'submit_upi_deposit', 'utr' => 'SATESTUTR' . random_int(100000, 999999)]);
$depId = $b['deposit_id'] ?? '';
[$c2] = http('POST', '/api/admin.php?action=approve_deposit', $ADMIN, ['deposit_id' => $depId]);
check($c === 200 && $c2 === 200 && near(wallet($P_OTHER['id']), 3500), 'the platform\'s own UPI deposit + Admin approval still credit the player');
[$c, $b] = http('GET', '/api/wallet/balance', $U1['tok']);
check($c === 200 && near($b['balance'], wallet($U1['id'])), 'a sub-admin\'s player uses the normal wallet endpoint');
[$c] = http('GET', '/api/admin.php?action=stats', $ADMIN);
check($c === 200, 'the Admin console stats still load');
[$c] = http('GET', '/api/admin/super-dashboard', $SUPER);
check($c === 200, 'the Super Admin dashboard still loads');

// =================================================================================================
if ($origSettings === null) q('DELETE FROM "GameState" WHERE "key" = ?', ['subadmin_settings']); else state_set('subadmin_settings', $origSettings);
wipe();
echo "\n$pass passed, $fail failed\n";
if ($fail) { echo "Failures:\n  - " . implode("\n  - ", $failures) . "\n"; }
exit($fail ? 1 : 0);
