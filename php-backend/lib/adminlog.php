<?php
/**
 * Operator action log — a record of what the admin (and super admin) actually did.
 *
 * Until this existed nothing recorded operator activity: a balance adjustment left a ledger row with
 * no operator name, a deposit or withdrawal decision only changed a status, and a settings change
 * (game RTP, bot takeover, cricket overrides) left no trace at all. The super admin console reads
 * this table to show who did what, when.
 *
 * Recording happens in ONE place, after every request has been handled (index.php), rather than in
 * each admin handler: many handlers check operator rights inline (is_admin) instead of through
 * require_admin, so a hook in any single gate would miss them. A request is recorded when it was
 * made with an admin, superadmin or sub-admin session, it changes something (any non-GET request, plus the
 * legacy GET-reachable actions on api/admin.php and api/wallet.php), and it succeeded (status < 400).
 * Secrets are never stored: any field whose name looks like a password, token, key, OTP or hash is
 * dropped from the summary.
 *
 * Schema: sql/migration-016-admin-log-postgres.sql, also applied automatically on first use.
 */

require_once __DIR__ . '/db.php';

function admin_log_ensure() {
    static $done = false;
    if ($done) return true;
    try {
        if ((int) state_get('admin_log_schema_version') < 16) {
            q('CREATE TABLE IF NOT EXISTS "AdminLog" (
                "id"         SERIAL PRIMARY KEY,
                "created_at" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
                "actor"      TEXT,
                "role"       TEXT,
                "method"     TEXT,
                "path"       TEXT,
                "action"     TEXT,
                "summary"    TEXT,
                "status"     INTEGER,
                "ip"         TEXT
            )');
            q('CREATE INDEX IF NOT EXISTS "AdminLog_created_at_idx" ON "AdminLog" ("created_at")');
            state_set('admin_log_schema_version', 16);
        }
        $done = true;
        return true;
    } catch (Throwable $e) {
        $done = true;   // no database (JSON fallback mode): nothing to log into, and do not retry
        return false;
    }
}

/** Legacy endpoints where an operator action can arrive as a plain GET. */
function admin_log_mutating_legacy_actions() {
    return ['adjust_balance', 'approve_deposit', 'reject_deposit', 'approve_withdrawal', 'reject_withdrawal', 'set_payment_config'];
}

/** A short, secret-free description of what the request asked for. */
function admin_log_summary(Req $req) {
    $fields = array_merge(is_array($req->query) ? $req->query : [], is_array($req->body) ? $req->body : []);
    $out = [];
    foreach ($fields as $k => $v) {
        $k = (string) $k;
        if ($k === 'action' || $k === '_' || preg_match('/pass|token|secret|otp|hash|key|pin|cvv|cookie|session/i', $k)) continue;
        if (is_array($v) || is_object($v)) $v = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $v = (string) $v;
        if (strlen($v) > 80) $v = substr($v, 0, 77) . '...';
        $out[$k] = $v;
        if (count($out) >= 12) break;
    }
    $s = $out ? json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';
    return strlen($s) > 600 ? substr($s, 0, 597) . '...' : $s;
}

/** Record this request if it was a successful operator action. Never throws. */
function admin_log_record(Req $req, Res $res) {
    try {
        $role = (string) ($req->auth['role'] ?? '');
        $actor = (string) ($req->auth['username'] ?? '');
        // Sub-admins are privileged operators too: their actions land in the same log.
        if ($role === '' && !empty($req->subadmin)) { $role = 'subadmin'; $actor = (string) $req->subadmin['username']; }
        if ($role !== 'admin' && $role !== 'superadmin' && $role !== 'subadmin') return;
        $status = method_exists($res, 'statusCode') ? $res->statusCode() : 200;
        if ($status >= 400) return;
        $path = (string) $req->path;
        $action = $req->q('action');
        if (!js_truthy($action)) $action = $req->b('action');
        $action = is_string($action) ? $action : '';
        if (in_array($action, ['login', 'superadmin_login', 'status', 'logout'], true)) return;

        $mutating = !in_array($req->method, ['GET', 'HEAD', 'OPTIONS'], true);
        if (!$mutating && $path === '/api/admin.php' && in_array($action, admin_log_mutating_legacy_actions(), true)) $mutating = true;
        if (!$mutating && $path === '/api/wallet.php' && ($req->q('delta') !== null || $req->b('delta') !== null)) $mutating = true;
        if (!$mutating) return;
        // A read made with POST is not an action (the super dashboard and stats are GETs already).
        if (preg_match('#^/api/admin\.php$#', $path) && in_array($action, ['stats', 'users', 'transactions', 'deposits', 'withdrawals', 'get_payment_config'], true)) return;

        if (!admin_log_ensure()) return;
        q('INSERT INTO "AdminLog" ("actor","role","method","path","action","summary","status","ip") VALUES (?,?,?,?,?,?,?,?)', [
            $actor, $role, $req->method, substr($path, 0, 200),
            substr($action, 0, 80), admin_log_summary($req), $status, substr((string) $req->ip, 0, 64),
        ]);
    } catch (Throwable $e) {
        // logging must never break the action it records
    }
}

/** The most recent operator actions, newest first. */
function admin_log_recent($limit = 40) {
    if (!admin_log_ensure()) return [];
    try {
        $rows = all('SELECT "id","created_at","actor","role","method","path","action","summary","status" FROM "AdminLog" ORDER BY "id" DESC LIMIT ' . max(1, min(200, (int) $limit)));
        return array_map(function ($r) {
            $ms = sql_to_ms($r['created_at']);
            return [
                'id' => (int) $r['id'], 'at' => $ms ? js_iso($ms) : null, 'actor' => $r['actor'], 'role' => $r['role'],
                'method' => $r['method'], 'path' => $r['path'], 'action' => $r['action'], 'summary' => $r['summary'],
            ];
        }, $rows);
    } catch (Throwable $e) {
        return [];
    }
}
