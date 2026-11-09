<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../routes/account_management.php';
if (cfg('IS_PRODUCTION')) throw new RuntimeException('Development database only.');
$checks = 0;
function check_account($ok) { global $checks; if (!$ok) throw new RuntimeException('Account management check failed: ' . ($checks + 1)); $checks++; }
$pdo = db_or_throw(); $pdo->beginTransaction();
try {
    $name = 'delete_test_' . bin2hex(random_bytes(5));
    q('INSERT INTO "User" ("username","email","password","wallet_balance","phone","phone_verified") VALUES (?,?,?,100,?,1)', [$name, $name . '@example.invalid', hash_password('Temporary-Test-123'), '999' . random_int(1000000,9999999)]);
    $u = find_user_ci($name); $id = (int) $u['id'];
    q('INSERT INTO "Transaction" ("id","user","type","amount","details","status") VALUES (?,?,?,10,?,?)', [$name, $name, 'deposit', 'test', 'Completed']);
    state_set($name, ['bets'=>[['username'=>$name,'amount'=>5], ['username'=>'other','amount'=>7]]]);
    $token = issue_token(['id'=>$id,'username'=>$name,'role'=>'user']);
    try { account_delete_player($id, 'DELETE wrong'); throw new RuntimeException('Confirmation accepted'); } catch (DomainException $e) {}
    check_account(find_user_ci($name) !== null);
    check_account(account_delete_player($id, 'DELETE ' . $name)['success']);
    check_account(find_user_ci($name) === null);
    check_account(!one('SELECT 1 FROM "Transaction" WHERE "user" = ?', [$name]));
    check_account(state_get($name)['bets'] == [['username'=>'other','amount'=>7]]);
    check_account(!session_still_valid(verify_token($token)));
    // The former contacts are reusable, with a new id (old tokens stay invalid).
    q('INSERT INTO "User" ("username","email","password","phone") VALUES (?,?,?,?)', [$name, $u['email'], $u['password'], $u['phone']]);
    check_account((int) find_user_ci($name)['id'] !== $id);
    check_account(!session_still_valid(verify_token($token)));
    check_account(account_scrub_state(['other'=>['user'=>'other'], $name=>['x'=>1]], $name) === ['other'=>['user'=>'other']]);
    $router = new Router(); register_account_management_routes($router);
    foreach ([null, 'user', 'admin', 'subadmin'] as $role) {
        foreach (['search', 'delete'] as $action) {
            $req = new Req(); $req->method = $action === 'search' ? 'GET' : 'POST';
            $req->path = '/api/admin/accounts/' . $action;
            $req->auth = $role === null || $role === 'subadmin' ? null : ['role'=>$role,'username'=>'test'];
            $res = new Res(); ob_start(); $router->dispatch($req, $res); ob_end_clean();
            check_account($res->statusCode() >= 400);
        }
    }
    $req = new Req(); $req->method = 'GET'; $req->path = '/api/admin/accounts/search';
    $req->auth = ['role'=>'superadmin','username'=>'test']; $req->query = ['q'=>$u['email']];
    $res = new Res(); ob_start(); $router->dispatch($req, $res); $body = json_decode(ob_get_clean(), true);
    check_account($res->statusCode() === 200 && count($body['users']) === 1);
    echo "PASS $checks database deletion, confirmation, contact reuse, runtime cleanup and session checks (rolled back)\n";
} finally { $pdo->rollBack(); }
