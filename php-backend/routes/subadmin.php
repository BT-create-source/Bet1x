<?php
/**
 * Sub-Admin endpoints.
 *
 *   /api/subadmin/*                 the sub-admin's own console (subadmin.html). Every query is scoped
 *                                   to the signed-in sub-admin's id taken from the session — never from
 *                                   the request — so one sub-admin can never read or change another's
 *                                   users, deposits, withdrawals or ledger.
 *   /api/admin/subadmins...         management for the Admin and the Super Admin (admin.html and the
 *   /api/admin/subadmin-...         super admin console). Gated by the existing require_admin on
 *                                   /api/admin, which admits exactly those two roles.
 *
 * Registered after register_admin_routes (so the /api/admin gate is already in place) and before the
 * /api fallback. Every rule about money lives in lib/subadmin.php; this file only maps HTTP to it.
 */

require_once __DIR__ . '/../lib/http.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/ratelimit.php';
require_once __DIR__ . '/../lib/subadmin.php';

/** Run a handler body, turning refusals into clean JSON errors. */
function sa_handle(Res $res, callable $fn) {
    try {
        $out = $fn();
        if (!$res->sent) $res->json(array_merge(['ok' => true], is_array($out) ? $out : []));
    } catch (SubAdminError $e) {
        $body = ['error' => $e->getMessage()];
        if ($e->errCode) $body['code'] = $e->errCode;
        foreach ((array) $e->extra as $k => $v) $body[$k] = $v;
        $res->status($e->httpStatus)->json($body);
    } catch (Throwable $e) {
        if (is_unique_violation($e)) {
            $res->status(409)->json(['error' => 'That has already been recorded.', 'code' => 'duplicate']);
            return;
        }
        fail500($res, $e, 'subadmin');
    }
}

/** A numeric path id, or a 404. */
function sa_path_int(Req $req, $name) {
    $v = (string) ($req->params[$name] ?? '');
    if (!ctype_digit($v) || strlen($v) > 9) sa_fail('Not found.', 404);
    return (int) $v;
}

/** A reference-style path id (ALC_..., SDP_..., CSH_..., WTH...), or a 404. */
function sa_path_ref(Req $req, $name) {
    $v = (string) ($req->params[$name] ?? '');
    if (!preg_match('/^[A-Za-z0-9_\-]{3,80}$/', $v)) sa_fail('Not found.', 404);
    return $v;
}

/** Guard for the admin side: schema present (503 otherwise). The role gate is /api/admin's own. */
function sa_admin_ready(Req $req, Res $res) {
    if (!subadmin_ensure()) {
        $res->status(503)->json(['error' => 'Sub-admin service is unavailable right now (database not ready).']);
    }
}

function sa_history_filters(Req $req) {
    return [
        'book' => (string) $req->q('book', 'all'), 'user' => (string) $req->q('user', ''),
        'from' => $req->q('from'), 'to' => $req->q('to'), 'type' => (string) $req->q('type', ''),
        'status' => (string) $req->q('status', ''), 'limit' => (int) js_parse_int($req->q('limit', 500)) ?: 500,
    ];
}

function sa_deposits_query($subadminId, array $f, $limit = 500) {
    $w = ['d."subadmin_id" = ?']; $p = [(int) $subadminId];
    $st = (string) ($f['status'] ?? '');
    if ($st !== '') { $w[] = 'd."status" = ?'; $p[] = $st; }
    $q = trim((string) ($f['q'] ?? ''));
    if ($q !== '') { $w[] = 'LOWER(d."username") LIKE LOWER(?)'; $p[] = '%' . $q . '%'; }
    [$lo, $hi] = sa_ist_range($f['from'] ?? null, $f['to'] ?? null);
    if ($lo !== null) { $w[] = 'd."created_at" >= ?'; $p[] = $lo; }
    if ($hi !== null) { $w[] = 'd."created_at" < ?'; $p[] = $hi; }
    return array_map('sa_deposit_view', all('SELECT d.* FROM "SubAdminDeposit" d WHERE ' . implode(' AND ', $w) . ' ORDER BY d."created_at" DESC LIMIT ' . (int) $limit, $p));
}

function sa_withdrawals_query($subadminId, array $f, $limit = 500) {
    $w = ['w."subadmin_id" = ?']; $p = [(int) $subadminId];
    $st = (string) ($f['status'] ?? '');
    if ($st === 'open') $w[] = 'w."status" IN (\'Pending\',\'Approved\')';
    elseif ($st !== '') { $w[] = 'w."status" = ?'; $p[] = $st; }
    $q = trim((string) ($f['q'] ?? ''));
    if ($q !== '') { $w[] = 'LOWER(w."username") LIKE LOWER(?)'; $p[] = '%' . $q . '%'; }
    [$lo, $hi] = sa_ist_range($f['from'] ?? null, $f['to'] ?? null);
    if ($lo !== null) { $w[] = 'w."created_at" >= ?'; $p[] = $lo; }
    if ($hi !== null) { $w[] = 'w."created_at" < ?'; $p[] = $hi; }
    return sa_withdrawals_list(implode(' AND ', $w), $p, $limit);
}

function sa_events_list($subadminId = null, $limit = 200) {
    $rows = $subadminId === null
        ? all('SELECT e.*, sa."username" AS subadmin FROM "SubAdminEvent" e JOIN "SubAdmin" sa ON sa."id" = e."subadmin_id" ORDER BY e."id" DESC LIMIT ' . (int) $limit)
        : all('SELECT e.*, sa."username" AS subadmin FROM "SubAdminEvent" e JOIN "SubAdmin" sa ON sa."id" = e."subadmin_id" WHERE e."subadmin_id" = ? ORDER BY e."id" DESC LIMIT ' . (int) $limit, [(int) $subadminId]);
    return array_map(function ($e) {
        return ['id' => (int) $e['id'], 'subadmin_id' => (int) $e['subadmin_id'], 'subadmin' => $e['subadmin'], 'event' => $e['event'],
                'user_id' => $e['user_id'] === null ? null : (int) $e['user_id'], 'username' => $e['username'],
                'actor' => $e['actor'], 'actor_role' => $e['actor_role'], 'details' => $e['details'], 'created_at' => sa_iso($e['created_at'])];
    }, $rows);
}

function sa_allocations_list($subadminId) {
    return array_map('sa_allocation_view', all('SELECT * FROM "SubAdminAllocation" WHERE "subadmin_id" = ? ORDER BY "created_at" DESC LIMIT 500', [(int) $subadminId]));
}

function sa_static_info() {
    return ['coin_rupee_rate' => sa_rate(), 'payment_methods' => sa_methods(), 'settings' => sa_settings(), 'admin_supply' => 'unlimited'];
}

function register_subadmin_routes(Router $app) {

    // =============================================================================================
    // The sub-admin's own console
    // =============================================================================================

    $app->post('/api/subadmin/login', limiter('auth'), function (Req $req, Res $res) {
        if (!subadmin_ensure()) { $res->status(503)->json(['error' => 'Sub-admin sign-in is unavailable right now.']); return; }
        sa_handle($res, function () use ($req) {
            return sa_login($req->b('username'), $req->b('password'));
        });
    });

    $app->post('/api/subadmin/logout', function (Req $req, Res $res) {
        $res->json(['ok' => true]);   // stateless tokens: the page drops its copy
    });

    $app->get('/api/subadmin/me', 'require_subadmin', function (Req $req, Res $res) {
        sa_handle($res, function () use ($req) {
            $r = sa_find($req->subadmin['id']);
            return array_merge(['subadmin' => sa_public($r)], sa_static_info());
        });
    });

    $app->post('/api/subadmin/password', limiter('auth'), 'require_subadmin', function (Req $req, Res $res) {
        sa_handle($res, function () use ($req) {
            return sa_change_password($req->subadmin['id'], $req->b('current_password'), $req->b('new_password'));
        });
    });

    $app->get('/api/subadmin/summary', 'require_subadmin_ready', function (Req $req, Res $res) {
        sa_handle($res, function () use ($req) {
            $id = (int) $req->subadmin['id'];
            $f = sa_figures($id)[$id] ?? null;
            $act = sa_game_activity($id);
            unset($act['per_user']);
            return array_merge(['subadmin' => sa_public(sa_find($id)), 'figures' => $f, 'game_activity' => $act], sa_static_info());
        });
    });

    $app->get('/api/subadmin/users', 'require_subadmin_ready', function (Req $req, Res $res) {
        sa_handle($res, function () use ($req) {
            return ['users' => sa_users_list($req->subadmin['id'], (string) $req->q('q', ''))];
        });
    });

    $app->post('/api/subadmin/users', limiter('wallet'), 'require_subadmin_ready', function (Req $req, Res $res) {
        sa_handle($res, function () use ($req) {
            return sa_user_create($req->subadmin['id'], (array) $req->body, sa_actor($req));
        });
    });

    $app->get('/api/subadmin/users/:id', 'require_subadmin_ready', function (Req $req, Res $res) {
        sa_handle($res, function () use ($req) {
            return sa_user_detail($req->subadmin['id'], sa_path_int($req, 'id'));
        });
    });

    $app->get('/api/subadmin/deposits', 'require_subadmin_ready', function (Req $req, Res $res) {
        sa_handle($res, function () use ($req) {
            return ['deposits' => sa_deposits_query($req->subadmin['id'], (array) $req->query)];
        });
    });

    $app->post('/api/subadmin/deposits', limiter('wallet'), 'require_subadmin_ready', function (Req $req, Res $res) {
        sa_handle($res, function () use ($req) {
            return sa_deposit_create($req->subadmin['id'], (array) $req->body, sa_actor($req));
        });
    });

    $app->post('/api/subadmin/deposits/:id/payment', limiter('wallet'), 'require_subadmin_ready', function (Req $req, Res $res) {
        sa_handle($res, function () use ($req) {
            return sa_deposit_record_payment(sa_path_ref($req, 'id'), $req->subadmin['id'], (array) $req->body, sa_actor($req));
        });
    });

    $app->post('/api/subadmin/deposits/:id/confirm', limiter('wallet'), 'require_subadmin_ready', function (Req $req, Res $res) {
        sa_handle($res, function () use ($req) {
            return sa_deposit_confirm(sa_path_ref($req, 'id'), $req->subadmin['id'], (array) $req->body, sa_actor($req));
        });
    });

    $app->post('/api/subadmin/deposits/:id/cancel', limiter('wallet'), 'require_subadmin_ready', function (Req $req, Res $res) {
        sa_handle($res, function () use ($req) {
            return sa_deposit_cancel(sa_path_ref($req, 'id'), $req->subadmin['id'], $req->b('reason'), sa_actor($req));
        });
    });

    $app->get('/api/subadmin/withdrawals', 'require_subadmin_ready', function (Req $req, Res $res) {
        sa_handle($res, function () use ($req) {
            return ['withdrawals' => sa_withdrawals_query($req->subadmin['id'], (array) $req->query), 'coin_rupee_rate' => sa_rate()];
        });
    });

    $app->post('/api/subadmin/withdrawals/:id/approve', limiter('wallet'), 'require_subadmin_ready', function (Req $req, Res $res) {
        sa_handle($res, function () use ($req) {
            return sa_withdrawal_approve(sa_path_ref($req, 'id'), $req->subadmin['id'], sa_actor($req));
        });
    });

    $app->post('/api/subadmin/withdrawals/:id/paid', limiter('wallet'), 'require_subadmin_ready', function (Req $req, Res $res) {
        sa_handle($res, function () use ($req) {
            return sa_withdrawal_paid(sa_path_ref($req, 'id'), $req->subadmin['id'], (array) $req->body, sa_actor($req));
        });
    });

    $app->post('/api/subadmin/withdrawals/:id/reject', limiter('wallet'), 'require_subadmin_ready', function (Req $req, Res $res) {
        sa_handle($res, function () use ($req) {
            return sa_withdrawal_reject(sa_path_ref($req, 'id'), $req->subadmin['id'], $req->b('reason'), sa_actor($req));
        });
    });

    $app->get('/api/subadmin/allocations', 'require_subadmin_ready', function (Req $req, Res $res) {
        sa_handle($res, function () use ($req) {
            return ['allocations' => sa_allocations_list($req->subadmin['id'])];
        });
    });

    // A sub-admin records that they have paid the Admin; only the Admin can confirm it arrived.
    $app->post('/api/subadmin/allocations/:id/payments', limiter('wallet'), 'require_subadmin_ready', function (Req $req, Res $res) {
        sa_handle($res, function () use ($req) {
            return sa_allocation_payment_record(sa_path_ref($req, 'id'), (array) $req->body, sa_actor($req), (int) $req->subadmin['id']);
        });
    });

    $app->get('/api/subadmin/history', 'require_subadmin_ready', function (Req $req, Res $res) {
        sa_handle($res, function () use ($req) {
            $f = sa_history_filters($req);
            $f['subadmin_id'] = (int) $req->subadmin['id'];   // never from the request
            return sa_history($f);
        });
    });

    $app->get('/api/subadmin/report', 'require_subadmin_ready', function (Req $req, Res $res) {
        sa_handle($res, function () use ($req) {
            return ['report' => sa_report($req->subadmin['id'], $req->q('from'), $req->q('to'))];
        });
    });

    // =============================================================================================
    // Admin and Super Admin: management and monitoring (behind the /api/admin gate)
    // =============================================================================================

    $app->get('/api/admin/subadmins', 'sa_admin_ready', function (Req $req, Res $res) {
        sa_handle($res, function () use ($req) {
            $figures = sa_figures();
            $q = strtolower(trim((string) $req->q('q', '')));
            $list = [];
            foreach (all('SELECT * FROM "SubAdmin" ORDER BY "created_at" DESC') as $r) {
                $row = sa_public($r);
                if ($q !== '' && strpos(strtolower($row['full_name'] . ' ' . $row['username'] . ' ' . $row['public_id']), $q) === false) continue;
                $row['figures'] = $figures[(int) $r['id']] ?? null;
                $list[] = $row;
            }
            return ['subadmins' => $list, 'totals' => sa_platform_totals($figures)] + sa_static_info();
        });
    });

    $app->post('/api/admin/subadmins', limiter('wallet'), 'sa_admin_ready', function (Req $req, Res $res) {
        sa_handle($res, function () use ($req) {
            return sa_create((array) $req->body, sa_actor($req));
        });
    });

    $app->get('/api/admin/subadmin-settings', 'sa_admin_ready', function (Req $req, Res $res) {
        sa_handle($res, function () { return sa_static_info(); });
    });

    $app->post('/api/admin/subadmin-settings', 'sa_admin_ready', function (Req $req, Res $res) {
        sa_handle($res, function () use ($req) {
            $patch = [];
            foreach (['allocation_credit'] as $k) if ($req->b($k) !== null) $patch[$k] = (string) $req->b($k);
            return ['settings' => sa_settings_save($patch)];
        });
    });

    $app->get('/api/admin/subadmin-history', 'sa_admin_ready', function (Req $req, Res $res) {
        sa_handle($res, function () use ($req) {
            $f = sa_history_filters($req);
            $sid = (string) $req->q('subadmin_id', '');
            if ($sid !== '') {
                if (!ctype_digit($sid)) sa_fail('Invalid sub-admin.');
                $f['subadmin_id'] = (int) $sid;
            }
            return sa_history($f);
        });
    });

    $app->get('/api/admin/subadmin-events', 'sa_admin_ready', function (Req $req, Res $res) {
        sa_handle($res, function () use ($req) {
            $sid = (string) $req->q('subadmin_id', '');
            return ['events' => sa_events_list($sid !== '' && ctype_digit($sid) ? (int) $sid : null, 300)];
        });
    });

    $app->get('/api/admin/subadmins/:id', 'sa_admin_ready', function (Req $req, Res $res) {
        sa_handle($res, function () use ($req) {
            $id = sa_path_int($req, 'id');
            $r = sa_find($id);
            if (!$r) sa_fail('Sub-admin not found.', 404);
            $act = sa_game_activity($id);
            unset($act['per_user']);
            return [
                'subadmin' => sa_public($r),
                'figures' => sa_figures($id)[$id] ?? null,
                'game_activity' => $act,
                'allocations' => sa_allocations_list($id),
                'users' => sa_users_list($id),
                'deposits' => sa_deposits_query($id, [], 300),
                'withdrawals' => sa_withdrawals_list('w."subadmin_id" = ?', [$id], 300),
                'events' => sa_events_list($id, 150),
            ] + sa_static_info();
        });
    });

    $app->get('/api/admin/subadmins/:id/report', 'sa_admin_ready', function (Req $req, Res $res) {
        sa_handle($res, function () use ($req) {
            $id = sa_path_int($req, 'id');
            if (!sa_find($id)) sa_fail('Sub-admin not found.', 404);
            return ['report' => sa_report($id, $req->q('from'), $req->q('to'))];
        });
    });

    $app->get('/api/admin/subadmins/:id/users/:uid', 'sa_admin_ready', function (Req $req, Res $res) {
        sa_handle($res, function () use ($req) {
            return sa_user_detail(sa_path_int($req, 'id'), sa_path_int($req, 'uid'));
        });
    });

    $app->post('/api/admin/subadmins/:id/status', 'sa_admin_ready', function (Req $req, Res $res) {
        sa_handle($res, function () use ($req) {
            return ['subadmin' => sa_set_status(sa_path_int($req, 'id'), (string) $req->b('status'), $req->b('reason'), sa_actor($req))];
        });
    });

    $app->post('/api/admin/subadmins/:id/reset-password', limiter('wallet'), 'sa_admin_ready', function (Req $req, Res $res) {
        sa_handle($res, function () use ($req) {
            return sa_reset_password(sa_path_int($req, 'id'), sa_actor($req));
        });
    });

    $app->post('/api/admin/subadmins/:id/allocations', limiter('wallet'), 'sa_admin_ready', function (Req $req, Res $res) {
        sa_handle($res, function () use ($req) {
            return sa_allocation_create(sa_path_int($req, 'id'), (array) $req->body, sa_actor($req));
        });
    });

    $app->post('/api/admin/subadmins/:id/assign', limiter('wallet'), 'sa_admin_ready', function (Req $req, Res $res) {
        sa_handle($res, function () use ($req) {
            return sa_user_assign(sa_path_int($req, 'id'), (string) $req->b('username'), sa_actor($req), sa_bool($req->b('move', false)));
        });
    });

    $app->post('/api/admin/subadmins/:id/unassign', limiter('wallet'), 'sa_admin_ready', function (Req $req, Res $res) {
        sa_handle($res, function () use ($req) {
            $uid = $req->b('user_id');
            if (!is_numeric($uid)) sa_fail('Choose a player.');
            return sa_user_unassign(sa_path_int($req, 'id'), (int) $uid, sa_actor($req));
        });
    });

    $app->post('/api/admin/subadmins/:id/adjust', limiter('wallet'), 'sa_admin_ready', function (Req $req, Res $res) {
        sa_handle($res, function () use ($req) {
            return sa_adjust(sa_path_int($req, 'id'), $req->b('coins'), $req->b('reason'), sa_actor($req));
        });
    });

    $app->post('/api/admin/subadmin-allocations/:aid/payments', limiter('wallet'), 'sa_admin_ready', function (Req $req, Res $res) {
        sa_handle($res, function () use ($req) {
            return sa_allocation_payment_record(sa_path_ref($req, 'aid'), (array) $req->body, sa_actor($req), null);
        });
    });

    $app->post('/api/admin/subadmin-allocations/:aid/cancel', limiter('wallet'), 'sa_admin_ready', function (Req $req, Res $res) {
        sa_handle($res, function () use ($req) {
            return sa_allocation_cancel(sa_path_ref($req, 'aid'), $req->b('reason'), sa_actor($req));
        });
    });

    $app->post('/api/admin/subadmin-allocations/:aid/reverse', limiter('wallet'), 'sa_admin_ready', function (Req $req, Res $res) {
        sa_handle($res, function () use ($req) {
            return sa_allocation_reverse(sa_path_ref($req, 'aid'), $req->b('reason'), sa_actor($req));
        });
    });

    $app->post('/api/admin/subadmin-payments/:pid/confirm', limiter('wallet'), 'sa_admin_ready', function (Req $req, Res $res) {
        sa_handle($res, function () use ($req) {
            return sa_payment_confirm(sa_path_ref($req, 'pid'), sa_actor($req));
        });
    });

    $app->post('/api/admin/subadmin-payments/:pid/fail', limiter('wallet'), 'sa_admin_ready', function (Req $req, Res $res) {
        sa_handle($res, function () use ($req) {
            return sa_payment_fail(sa_path_ref($req, 'pid'), $req->b('reason'), sa_actor($req));
        });
    });

    $app->post('/api/admin/subadmin-payments/:pid/reverse', limiter('wallet'), 'sa_admin_ready', function (Req $req, Res $res) {
        sa_handle($res, function () use ($req) {
            return sa_payment_reverse(sa_path_ref($req, 'pid'), $req->b('reason'), sa_actor($req));
        });
    });

    $app->post('/api/admin/subadmin-deposits/:did/reverse', limiter('wallet'), 'sa_admin_ready', function (Req $req, Res $res) {
        sa_handle($res, function () use ($req) {
            return sa_deposit_reverse(sa_path_ref($req, 'did'), $req->b('reason'), sa_actor($req));
        });
    });

    $app->post('/api/admin/subadmin-withdrawals/:wid/reverse', limiter('wallet'), 'sa_admin_ready', function (Req $req, Res $res) {
        sa_handle($res, function () use ($req) {
            return sa_withdrawal_reverse(sa_path_ref($req, 'wid'), $req->b('reason'), sa_actor($req));
        });
    });
}
