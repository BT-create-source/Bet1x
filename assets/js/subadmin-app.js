/**
 * The sub-admin console (subadmin.html).
 *
 * Talks only to /api/subadmin/*, with its own session token (bet1x_subadmin_token). Every figure on
 * screen is read from the server — nothing is computed or stored here — and every list the server
 * returns is already limited to this sub-admin's own records.
 */
(function () {
  'use strict';
  var K = window.SAKit;
  var esc = K.esc, coins = K.coins, inr = K.inr, when = K.when;
  var API = String(window.BET1X_API_BASE || (location.protocol === 'file:' ? 'http://localhost:5000' : location.origin)).replace(/\/+$/, '');
  var TOKEN_KEY = 'bet1x_subadmin_token';
  var VIEWS = ['dashboard', 'users', 'inventory', 'deposits', 'withdrawals', 'history', 'reports', 'settings'];
  var S = { me: null, rate: 1, methods: ['UPI', 'Cash', 'Bank transfer', 'Other'], settings: {}, view: 'dashboard', users: null, cache: {} };

  function $(id) { return document.getElementById(id); }
  function getToken() { try { return localStorage.getItem(TOKEN_KEY); } catch (e) { return null; } }
  function setToken(t) { try { localStorage.setItem(TOKEN_KEY, t); } catch (e) {} }
  function clearToken() { try { localStorage.removeItem(TOKEN_KEY); } catch (e) {} }
  function remember(view) { try { localStorage.setItem('bet1x_subadmin_view', view); } catch (e) {} }
  function remembered() { try { return localStorage.getItem('bet1x_subadmin_view'); } catch (e) { return null; } }

  // ---------------------------------------------------------------------------------------- API
  function api(path, opts) {
    opts = opts || {};
    var headers = { Accept: 'application/json' };
    var t = getToken();
    if (t) headers.Authorization = 'Bearer ' + t;
    var init = { method: opts.method || 'GET', headers: headers, cache: 'no-store', credentials: 'same-origin' };
    if (opts.body !== undefined) { headers['Content-Type'] = 'application/json'; init.body = JSON.stringify(opts.body); }
    return fetch(API + path, init).catch(function () {
      throw new Error('Cannot reach the server. Check your connection and try again.');
    }).then(function (res) {
      return res.json().catch(function () { return null; }).then(function (data) {
        if (res.status === 401 && path !== '/api/subadmin/login') {
          sessionEnded((data && data.error) || null);
          throw new Error('Your session has ended. Please sign in again.');
        }
        if (res.status === 403 && data && data.code === 'password_change_required') {
          showPassword();
          throw new Error(data.error);
        }
        if (!res.ok || !data || data.ok === false) {
          var err = new Error((data && data.error) || ('Request failed (' + res.status + ').'));
          err.status = res.status; err.data = data;
          throw err;
        }
        return data;
      });
    });
  }
  function post(path, body) { return api(path, { method: 'POST', body: body || {} }); }

  // ---------------------------------------------------------------------------------------- gates
  function showOnly(which) {
    document.documentElement.classList.remove('sa-booting');
    $('sa-login').hidden = which !== 'login';
    $('sa-pw').hidden = which !== 'pw';
    document.documentElement.classList.toggle('sa-locked', which !== 'app');
  }
  function showLogin(message) {
    showOnly('login');
    var m = $('saLoginMsg');
    m.textContent = message || '';
    setTimeout(function () { $('saLoginUser').focus(); }, 30);
  }
  function showPassword() { showOnly('pw'); setTimeout(function () { $('saPwCurrent').focus(); }, 30); }
  function sessionEnded(reason) {
    clearToken();
    S.me = null;
    K.closeModal();
    showLogin(reason && /suspend/i.test(reason) ? reason : 'Your session has ended. Please sign in again.');
  }
  function applyMe(d) {
    S.me = d.subadmin;
    S.rate = Number(d.coin_rupee_rate) || 1;
    S.methods = d.payment_methods || S.methods;
    S.settings = d.settings || {};
    $('saName').textContent = S.me.full_name + ' (' + S.me.username + ')';
    $('saPublicId').textContent = S.me.public_id;
    setInventory(S.me.coin_balance);
  }
  function showApp() {
    showOnly('app');
    var v = remembered();
    switchView(VIEWS.indexOf(v) >= 0 ? v : 'dashboard');
    refreshBadge();
  }
  function setInventory(n) { if (n !== undefined && n !== null) { S.inv = Number(n) || 0; $('saInvChip').textContent = coins(n); } }

  function boot() {
    $('saLoginForm').addEventListener('submit', function (e) {
      e.preventDefault();
      var btn = $('saLoginBtn');
      if (btn.disabled) return;
      btn.disabled = true; $('saLoginMsg').textContent = '';
      post('/api/subadmin/login', { username: $('saLoginUser').value.trim(), password: $('saLoginPass').value }).then(function (d) {
        setToken(d.token);
        $('saLoginPass').value = '';
        applyMe({ subadmin: d.subadmin });
        return api('/api/subadmin/me').then(function (me) {
          applyMe(me);
          if (me.subadmin.must_change_password) showPassword(); else showApp();
        });
      }).catch(function (err) { $('saLoginMsg').textContent = err.message; }).then(function () { btn.disabled = false; });
    });
    $('saPwForm').addEventListener('submit', function (e) {
      e.preventDefault();
      var msg = $('saPwMsg'); msg.textContent = '';
      var cur = $('saPwCurrent').value, a = $('saPwNew').value, b = $('saPwNew2').value;
      if (a.length < 8) { msg.textContent = 'The new password must be at least 8 characters.'; return; }
      if (a !== b) { msg.textContent = 'The two new passwords do not match.'; return; }
      post('/api/subadmin/password', { current_password: cur, new_password: a }).then(function (d) {
        setToken(d.token);
        $('saPwForm').reset();
        applyMe({ subadmin: d.subadmin, coin_rupee_rate: S.rate, payment_methods: S.methods, settings: S.settings });
        K.toast('Password changed.');
        showApp();
      }).catch(function (err) { msg.textContent = err.message; });
    });
    document.addEventListener('click', onClick);
    document.querySelectorAll('.sa-tab').forEach(function (b) { b.addEventListener('click', function () { switchView(b.getAttribute('data-view')); }); });

    if (!getToken()) { showLogin(''); return; }
    api('/api/subadmin/me').then(function (me) {
      applyMe(me);
      if (me.subadmin.must_change_password) showPassword(); else showApp();
    }).catch(function () { if (!S.me && $('sa-login').hidden) showLogin(''); });
    setInterval(function () { if (S.me && !document.hidden) refreshBadge(); }, 30000);
  }

  function refreshBadge() {
    api('/api/subadmin/withdrawals?status=open').then(function (d) {
      var n = (d.withdrawals || []).length;
      var el = $('saWdCount');
      el.textContent = n; el.hidden = n === 0;
    }).catch(function () {});
  }

  // ---------------------------------------------------------------------------------------- views
  function view() { return $('saView'); }
  function loading() { view().innerHTML = '<div class="sa-loading">Loading…</div>'; }
  function failed(err) { view().innerHTML = '<div class="sa-error">' + esc(err.message) + ' <button type="button" class="sa-btn sm" data-act="reload">Try again</button></div>'; }
  function switchView(v) {
    S.view = v;
    remember(v);
    document.querySelectorAll('.sa-tab').forEach(function (b) { b.classList.toggle('active', b.getAttribute('data-view') === v); b.setAttribute('aria-selected', b.getAttribute('data-view') === v ? 'true' : 'false'); });
    (RENDER[v] || RENDER.dashboard)();
  }
  function reload() { (RENDER[S.view] || RENDER.dashboard)(); }

  var RENDER = {
    dashboard: function () {
      loading();
      api('/api/subadmin/summary').then(function (d) {
        var f = d.figures || {};
        setInventory(f.coin_balance);
        view().innerHTML =
          '<div class="sa-head"><div><h2>Dashboard</h2><div class="sa-sub">Every figure comes from your recorded transactions. 1 coin = ' + inr(S.rate) + '.</div></div>' +
          '<div class="sa-actions"><button class="sa-btn primary" type="button" data-act="new-deposit">Record a deposit</button>' +
          '<button class="sa-btn" type="button" data-act="goto" data-id="withdrawals">Review withdrawals</button></div></div>' +
          (f.open_items ? '<div class="sa-banner warn">' + esc(f.open_items) + ' item(s) are waiting on you or the Admin — unconfirmed deposits, unpaid withdrawals or payments the Admin has not confirmed yet.</div>' : '') +
          K.statsHtml(f) + K.positionHtml(f) + K.activityHtml(d.game_activity);
      }).catch(failed);
    },

    users: function () {
      loading();
      api('/api/subadmin/users').then(function (d) {
        S.users = d.users || [];
        view().innerHTML =
          '<div class="sa-head"><div><h2>My Users</h2><div class="sa-sub">Players who belong to you. Create a new player account here, or ask the Admin to assign an existing player to you.</div></div>' +
          '<div class="sa-actions"><button class="sa-btn primary" type="button" data-act="new-user">Create user</button></div></div>' +
          '<div class="sa-filters"><div class="sa-field grow"><label class="sa-lbl" for="saUserSearch">Search</label><input type="search" id="saUserSearch" placeholder="username or email" autocomplete="off"></div></div>' +
          K.usersHtml(S.users, { subadmin: true, emptyText: 'No users yet. Create one, or ask the Admin to assign existing players to you.' }) +
          '<div class="sa-note" style="margin-top:8px;" id="saUserCount">' + S.users.length + ' user(s)</div>';
        $('saUserSearch').addEventListener('input', function () {
          var q = this.value.trim().toLowerCase(), shown = 0;
          view().querySelectorAll('tr[data-user-row]').forEach(function (tr) {
            var hit = !q || tr.getAttribute('data-user-row').indexOf(q) >= 0;
            tr.hidden = !hit; if (hit) shown++;
          });
          $('saUserCount').textContent = shown + ' of ' + S.users.length + ' user(s)';
        });
      }).catch(failed);
    },

    inventory: function () {
      loading();
      Promise.all([api('/api/subadmin/summary'), api('/api/subadmin/allocations'), api('/api/subadmin/history?book=coin&limit=300')]).then(function (r) {
        var f = r[0].figures || {};
        setInventory(f.coin_balance);
        S.cache.allocations = r[1].allocations || [];
        var where = 'Coins from withdrawals you pay out go back to the Admin\'s supply, not to your inventory.';
        view().innerHTML =
          '<div class="sa-head"><div><h2>Coin Inventory</h2><div class="sa-sub">Coins reach you only through allocations from the Admin. Selling to a user takes coins out; ' + esc(where.charAt(0).toLowerCase() + where.slice(1)) +
          ' Game wins and losses never change your inventory.</div></div></div>' +
          '<div class="sa-stats">' +
          K.stat('accent', 'Available coins', coins(f.coin_balance), f.reconciled ? '<span class="sa-good">✓ matches the coin ledger</span>' : '<span class="sa-bad">does not match the ledger — tell the Admin</span>') +
          K.stat('', 'Allocated to you', coins(f.alloc_credited_coins), f.alloc_awaiting_count ? '<b>' + coins(f.alloc_awaiting_coins) + '</b> more awaiting payment' : '') +
          K.stat('', 'Sold to users', coins(f.dep_confirmed_coins), '') +
          K.stat(f.owed_admin > 0 ? 'warn' : '', 'Still owed to the Admin', inr(f.owed_admin), 'for coins already credited') +
          K.stat('', 'Paid to the Admin', inr(f.paid_admin_confirmed), 'awaiting the Admin\'s confirmation <b>' + inr(f.paid_admin_unconfirmed) + '</b>') +
          '</div>' +
          '<div class="sa-section-title">Allocations from the Admin</div>' +
          '<p class="sa-note" style="margin:0 0 8px;">Paid the Admin for an allocation? Use <b>I have paid</b> to record it — the Admin then confirms that the money arrived.</p>' +
          K.allocationsHtml(S.cache.allocations, { emptyText: 'No allocations yet. The Admin allocates coins to you.' }) +
          '<div class="sa-section-title">Coin movements</div>' + K.historyHtml(r[2].rows);
      }).catch(failed);
    },

    deposits: function () {
      loading();
      var f = S.cache.depFilters || '';
      api('/api/subadmin/deposits' + (f ? '?' + f : '')).then(function (d) {
        S.cache.deposits = d.deposits || [];
        view().innerHTML =
          '<div class="sa-head"><div><h2>Deposits</h2><div class="sa-sub">A user pays you (UPI, cash…) and you record it here. Coins move only when you <b>confirm</b> the payment arrived — they come out of your inventory into the user\'s balance.</div></div>' +
          '<div class="sa-actions"><button class="sa-btn primary" type="button" data-act="new-deposit">New deposit</button></div></div>' +
          '<form class="sa-filters" data-k="dep-filters">' +
          '<div class="sa-field"><label class="sa-lbl">Status</label><select name="status"><option value="">All</option><option>Requested</option><option value="Unconfirmed">Payment unconfirmed</option><option>Confirmed</option><option>Cancelled</option><option>Reversed</option></select></div>' +
          '<div class="sa-field"><label class="sa-lbl">From</label><input type="date" name="from"></div><div class="sa-field"><label class="sa-lbl">To</label><input type="date" name="to"></div>' +
          '<div class="sa-field grow"><label class="sa-lbl">User</label><input type="search" name="q" placeholder="username"></div>' +
          '<button type="submit" class="sa-btn primary">Apply</button></form>' +
          K.depositsHtml(S.cache.deposits, { subadmin: true, emptyText: 'No deposits match. Use “New deposit” when a user pays you.' });
        restoreFilters(view().querySelector('[data-k="dep-filters"]'), f);
        view().querySelector('[data-k="dep-filters"]').addEventListener('submit', function (e) {
          e.preventDefault(); S.cache.depFilters = K.formQuery(this); RENDER.deposits();
        });
      }).catch(failed);
    },

    withdrawals: function () {
      loading();
      var st = S.cache.wdStatus === undefined ? 'open' : S.cache.wdStatus;
      api('/api/subadmin/withdrawals' + (st ? '?status=' + encodeURIComponent(st) : '')).then(function (d) {
        S.cache.withdrawals = d.withdrawals || [];
        view().innerHTML =
          '<div class="sa-head"><div><h2>Withdrawals</h2><div class="sa-sub">Your users\' withdrawal requests. Their coins are held the moment they ask, so they cannot be spent twice. ' +
          'Approving does not pay anyone — mark a request <b>paid</b> only after you have actually sent the money. Rejecting returns the held coins.</div></div></div>' +
          '<div class="sa-filters"><div class="sa-field"><label class="sa-lbl" for="saWdStatus">Show</label><select id="saWdStatus">' +
          [['open', 'Open (pending + approved)'], ['Pending', 'Pending'], ['Approved', 'Approved'], ['Paid', 'Paid'], ['Rejected', 'Rejected'], ['Reversed', 'Reversed'], ['Returned', 'Back with Admin'], ['', 'All']]
            .map(function (o) { return '<option value="' + o[0] + '"' + (o[0] === st ? ' selected' : '') + '>' + o[1] + '</option>'; }).join('') +
          '</select></div></div>' +
          K.withdrawalsHtml(S.cache.withdrawals, { subadmin: true, emptyText: st === 'open' ? 'No open requests. 🎉' : 'No requests match.' });
        $('saWdStatus').addEventListener('change', function () { S.cache.wdStatus = this.value; RENDER.withdrawals(); });
        refreshBadge();
      }).catch(failed);
    },

    history: function () {
      loading();
      var f = S.cache.histFilters || '';
      api('/api/subadmin/history' + (f ? '?' + f : '')).then(function (d) {
        view().innerHTML =
          '<div class="sa-head"><div><h2>Transaction History</h2><div class="sa-sub">Every coin movement and cash record of yours, newest first. Records are permanent; a correction appears as its own entry pointing at the original.</div></div></div>' +
          K.historyFiltersHtml() + K.historyHtml(d.rows) +
          '<div class="sa-note" style="margin-top:8px;">' + esc(d.rows.length) + ' record(s)' + (d.capped ? ' — showing the newest; narrow the filters to see older ones' : '') + '</div>';
        var form = view().querySelector('[data-k="hist-filters"]');
        restoreFilters(form, f);
        form.addEventListener('submit', function (e) { e.preventDefault(); S.cache.histFilters = K.formQuery(form); RENDER.history(); });
      }).catch(failed);
    },

    reports: function () {
      var r = S.cache.report || { from: K.istDate(29), to: K.istDate(0) };
      view().innerHTML =
        '<div class="sa-head"><div><h2>Financial Reports</h2><div class="sa-sub">Cash-basis figures from confirmed records only. Choose a period (India time).</div></div></div>' +
        '<form class="sa-filters" data-k="rep">' +
        '<div class="sa-field"><label class="sa-lbl">From</label><input type="date" name="from" value="' + esc(r.from || '') + '"></div>' +
        '<div class="sa-field"><label class="sa-lbl">To</label><input type="date" name="to" value="' + esc(r.to || '') + '"></div>' +
        '<button type="submit" class="sa-btn primary">Show</button>' +
        '<button type="button" class="sa-btn" data-act="rep-preset" data-id="0">Today</button><button type="button" class="sa-btn" data-act="rep-preset" data-id="6">7 days</button>' +
        '<button type="button" class="sa-btn" data-act="rep-preset" data-id="29">30 days</button><button type="button" class="sa-btn" data-act="rep-preset" data-id="all">All time</button></form>' +
        '<div data-k="rep-out"><div class="sa-loading">Loading…</div></div>';
      var form = view().querySelector('[data-k="rep"]');
      form.addEventListener('submit', function (e) { e.preventDefault(); S.cache.report = { from: form.elements.from.value, to: form.elements.to.value }; RENDER.reports(); });
      var qs = new URLSearchParams();
      if (r.from) qs.set('from', r.from);
      if (r.to) qs.set('to', r.to);
      api('/api/subadmin/report?' + qs.toString()).then(function (d) {
        view().querySelector('[data-k="rep-out"]').innerHTML = K.reportHtml(d.report);
      }).catch(function (err) { view().querySelector('[data-k="rep-out"]').innerHTML = '<div class="sa-error">' + esc(err.message) + '</div>'; });
    },

    settings: function () {
      loading();
      api('/api/subadmin/me').then(function (d) {
        applyMe(d);
        var m = d.subadmin;
        view().innerHTML =
          '<div class="sa-head"><div><h2>Account Settings</h2></div></div>' +
          '<div class="sa-grid-2"><div class="sa-card"><div class="sa-section-title" style="margin-top:0;">Your account</div><dl class="sa-kv">' +
          '<dt>Name</dt><dd>' + esc(m.full_name) + '</dd><dt>Username</dt><dd>' + esc(m.username) + '</dd><dt>Sub-admin ID</dt><dd><code class="sa-code">' + esc(m.public_id) + '</code></dd>' +
          '<dt>Status</dt><dd>' + K.badge(m.status) + '</dd><dt>Created</dt><dd>' + esc(when(m.created_at)) + ' by ' + esc(m.created_by) + '</dd>' +
          '<dt>Last sign-in</dt><dd>' + esc(when(m.last_login_at)) + '</dd><dt>Password changed</dt><dd>' + esc(when(m.password_changed_at)) + '</dd>' +
          '<dt>Coin value</dt><dd>1 coin = ' + inr(d.coin_rupee_rate) + ' (platform setting)</dd>' +
          '<dt>Allocations</dt><dd>' + (d.settings.allocation_credit === 'immediate' ? 'credited at once; the agreed amount is owed until the Admin confirms payment' : 'credited once the Admin confirms your payment') + '</dd>' +
          '</dl></div>' +
          '<div class="sa-card"><div class="sa-section-title" style="margin-top:0;">Change password</div>' +
          '<form data-k="pw"><div class="sa-field" style="margin-bottom:10px;"><label class="sa-lbl">Current password</label><input type="password" name="current" autocomplete="current-password" required></div>' +
          '<div class="sa-field" style="margin-bottom:10px;"><label class="sa-lbl">New password</label><input type="password" name="a" autocomplete="new-password" minlength="8" required><span class="hint">At least 8 characters. Changing it signs you out on every other device.</span></div>' +
          '<div class="sa-field"><label class="sa-lbl">Repeat new password</label><input type="password" name="b" autocomplete="new-password" minlength="8" required></div>' +
          '<div class="sa-msg" data-k="pwmsg" role="alert"></div><button type="submit" class="sa-btn primary">Change password</button></form></div></div>';
        var form = view().querySelector('[data-k="pw"]'), msg = view().querySelector('[data-k="pwmsg"]');
        form.addEventListener('submit', function (e) {
          e.preventDefault();
          msg.textContent = ''; msg.className = 'sa-msg';
          if (form.elements.a.value.length < 8) { msg.textContent = 'At least 8 characters.'; msg.className = 'sa-msg err'; return; }
          if (form.elements.a.value !== form.elements.b.value) { msg.textContent = 'The two new passwords do not match.'; msg.className = 'sa-msg err'; return; }
          post('/api/subadmin/password', { current_password: form.elements.current.value, new_password: form.elements.a.value }).then(function (r) {
            setToken(r.token); form.reset(); msg.textContent = '✓ Password changed.'; msg.className = 'sa-msg ok';
          }).catch(function (err) { msg.textContent = err.message; msg.className = 'sa-msg err'; });
        });
      }).catch(failed);
    },
  };

  function restoreFilters(form, qs) {
    if (!form || !qs) return;
    new URLSearchParams(qs).forEach(function (v, k) { if (form.elements[k]) form.elements[k].value = v; });
  }

  // ---------------------------------------------------------------------------------------- actions
  function findUser(id) { return (S.users || []).filter(function (u) { return String(u.id) === String(id); })[0]; }
  function findIn(list, key, id) { return (list || []).filter(function (x) { return String(x[key]) === String(id); })[0]; }
  function done(msg) { K.closeModal(); K.toast(msg); reload(); }

  function ensureUsers() {
    if (S.users) return Promise.resolve(S.users);
    return api('/api/subadmin/users').then(function (d) { S.users = d.users || []; return S.users; });
  }

  function newUser() {
    K.formModal({
      title: 'Create a user', sub: 'A new player account that belongs to you. It starts at 0 coins; give the player their username and password.',
      submitLabel: 'Create user',
      html: '<div class="sa-form">' +
        K.field('username', 'Username', K.input('username', 'text', 'required autocomplete="off" maxlength="20" placeholder="3-20 letters, digits or _"')) +
        K.field('password', 'Password', K.input('password', 'text', 'required autocomplete="off" minlength="8" maxlength="128"'), 'At least 8 characters. The player can change it later.') +
        K.field('email', 'Email (optional)', K.input('email', 'text', 'autocomplete="off"'), '', true) + '</div>',
      onSubmit: function (form, body) {
        var u = form.elements.username.value.trim(), p = form.elements.password.value;
        return post('/api/subadmin/users', { username: u, password: p, email: form.elements.email.value.trim() }).then(function (d) {
          S.users = null;
          body.innerHTML = '<div class="sa-banner ok">Account <b>' + esc(d.user.username) + '</b> created and assigned to you.</div>' +
            '<p class="sa-note">Send the player these sign-in details. The password is not shown again.</p>' +
            '<div class="sa-secret"><code>' + esc(u) + ' / ' + esc(p) + '</code></div>' +
            '<div class="sa-modal-foot"><button type="button" class="sa-btn primary" data-modal-close>Done</button></div>';
          K.toast('User ' + d.user.username + ' created.');
          if (S.view === 'users') reload();
        });
      }
    });
  }

  function viewUser(id) {
    var body = K.openModal({ title: 'User details', wide: true, html: '<div class="sa-loading">Loading…</div>' });
    api('/api/subadmin/users/' + encodeURIComponent(id)).then(function (d) {
      body.innerHTML = '<div class="sa-actions" style="margin-bottom:12px;"><button type="button" class="sa-btn primary" data-act="user-deposit" data-id="' + esc(d.user.id) + '">Record deposit</button></div>' + K.userDetailHtml(d);
    }).catch(function (err) { body.innerHTML = '<div class="sa-error">' + esc(err.message) + '</div>'; });
  }

  function newDeposit(userId) {
    ensureUsers().then(function (users) {
      if (!users.length) { K.toast('You have no users yet. Create one first.', 'error'); return; }
      var key = K.newKey();
      var opts = users.map(function (u) { return '<option value="' + esc(u.id) + '"' + (String(u.id) === String(userId) ? ' selected' : '') + '>' + esc(u.username) + ' — ' + coins(u.wallet_balance) + ' coins</option>'; }).join('');
      K.formModal({
        title: 'Record a deposit', sub: 'You have ' + coins(S.inv) + ' coins available. Coins are credited only when the payment is confirmed.',
        submitLabel: 'Save deposit',
        html: '<div class="sa-form">' +
          K.field('user_id', 'User', '<select id="saf_user_id" name="user_id">' + opts + '</select>', '', true) +
          K.field('coins', 'Coins', K.input('coins', 'number', 'required min="1" step="1" inputmode="decimal"')) +
          K.field('amount_inr', 'Amount received (₹)', K.input('amount_inr', 'number', 'required min="0" step="0.01" inputmode="decimal"'), 'Filled in at ' + inr(S.rate) + ' per coin; change it if you agreed a different amount.') +
          K.field('method', 'Payment method', K.methodSelect('method', S.methods, 'UPI')) +
          K.field('reference', 'Payment reference (optional)', K.input('reference', 'text', 'maxlength="64" placeholder="UPI UTR / receipt no."')) +
          K.field('notes', 'Notes (optional)', '<textarea id="saf_notes" name="notes" maxlength="300"></textarea>', '', true) +
          '<div class="sa-field wide"><span class="sa-lbl">Payment</span><div class="sa-radio-group">' +
          '<label class="sa-radio"><input type="radio" name="stage" value="confirmed" checked><span><span class="t">Payment received — confirm and credit now</span><span class="d">The coins leave your inventory and reach the user straight away.</span></span></label>' +
          '<label class="sa-radio"><input type="radio" name="stage" value="unconfirmed"><span><span class="t">Payment recorded — not yet confirmed</span><span class="d">Saved as unconfirmed. Confirm it later once you see the money.</span></span></label>' +
          '<label class="sa-radio"><input type="radio" name="stage" value="requested"><span><span class="t">Requested — not paid yet</span><span class="d">Just a note that the user asked. Nothing moves.</span></span></label>' +
          '</div></div></div>',
        onOpen: function (form) {
          var touched = false;
          form.elements.amount_inr.addEventListener('input', function () { touched = true; });
          form.elements.coins.addEventListener('input', function () {
            if (!touched) { var c = parseFloat(this.value); form.elements.amount_inr.value = isFinite(c) ? Math.round(c * S.rate * 100) / 100 : ''; }
          });
        },
        onSubmit: function (form) {
          var stage = form.querySelector('input[name="stage"]:checked').value;
          var body = {
            user_id: form.elements.user_id.value, coins: form.elements.coins.value, amount_inr: form.elements.amount_inr.value || '0',
            method: form.elements.method.value, reference: form.elements.reference.value.trim(), notes: form.elements.notes.value.trim(),
            stage: stage, idempotency_key: key,
          };
          return post('/api/subadmin/deposits', body).then(function (d) {
            S.users = null;
            done(d.replayed ? 'That deposit was already saved.' : (stage === 'confirmed' ? 'Deposit confirmed — ' + coins(d.deposit.coins) + ' coins credited to ' + d.deposit.username + '.' : 'Deposit saved.'));
          });
        }
      });
    }).catch(function (err) { K.toast(err.message, 'error'); });
  }

  function depositPayment(id) {
    var d = findIn(S.cache.deposits, 'id', id);
    if (!d) return;
    K.formModal({
      title: 'Record the payment', sub: d.username + ' — ' + coins(d.coins) + ' coins. Recording does not credit coins yet.', submitLabel: 'Save payment',
      html: '<div class="sa-form">' + K.field('amount_inr', 'Amount received (₹)', K.input('amount_inr', 'number', 'required min="0" step="0.01" value="' + esc(d.amount_inr || Math.round(d.coins * S.rate * 100) / 100) + '"')) +
        K.field('method', 'Payment method', K.methodSelect('method', S.methods, d.method || 'UPI')) +
        K.field('reference', 'Payment reference (optional)', K.input('reference', 'text', 'maxlength="64"'), '', true) + '</div>',
      onSubmit: function (form) {
        return post('/api/subadmin/deposits/' + encodeURIComponent(id) + '/payment', { amount_inr: form.elements.amount_inr.value, method: form.elements.method.value, reference: form.elements.reference.value.trim() })
          .then(function () { done('Payment recorded. Confirm it once the money has arrived.'); });
      }
    });
  }

  function depositConfirm(id) {
    var d = findIn(S.cache.deposits, 'id', id);
    if (!d) return;
    var needsPayment = d.status === 'Requested';
    K.formModal({
      title: 'Confirm payment and credit coins', submitLabel: 'Confirm and credit',
      sub: 'Only confirm once you can see the money. This moves ' + coins(d.coins) + ' coins from your inventory to ' + d.username + ' and cannot be undone by you.',
      html: (needsPayment
        ? '<div class="sa-form">' + K.field('amount_inr', 'Amount received (₹)', K.input('amount_inr', 'number', 'required min="0" step="0.01" value="' + esc(d.amount_inr || Math.round(d.coins * S.rate * 100) / 100) + '"')) +
          K.field('method', 'Payment method', K.methodSelect('method', S.methods, d.method || 'UPI')) +
          K.field('reference', 'Payment reference (optional)', K.input('reference', 'text', 'maxlength="64"'), '', true) + '</div>'
        : '<dl class="sa-kv"><dt>User</dt><dd><b>' + esc(d.username) + '</b></dd><dt>Coins</dt><dd class="sa-num">' + coins(d.coins) + '</dd><dt>Received</dt><dd class="sa-num">' + inr(d.amount_inr) + ' via ' + esc(d.method || '') + '</dd>' +
          (d.reference ? '<dt>Reference</dt><dd><code class="sa-code">' + esc(d.reference) + '</code></dd>' : '') + '</dl>'),
      onSubmit: function (form) {
        var body = needsPayment ? { amount_inr: form.elements.amount_inr.value, method: form.elements.method.value, reference: form.elements.reference.value.trim() } : {};
        return post('/api/subadmin/deposits/' + encodeURIComponent(id) + '/confirm', body).then(function (r) {
          setInventory(r.coin_balance); S.users = null;
          done(coins(d.coins) + ' coins credited to ' + d.username + '.');
        });
      }
    });
  }

  function depositCancel(id) {
    var d = findIn(S.cache.deposits, 'id', id);
    K.reasonModal({
      title: 'Cancel this deposit?', sub: d ? d.username + ' — ' + coins(d.coins) + ' coins. No coins have moved, so none move now.' : '', submitLabel: 'Cancel deposit',
      onSubmit: function (reason) { return post('/api/subadmin/deposits/' + encodeURIComponent(id) + '/cancel', { reason: reason }).then(function () { done('Deposit cancelled.'); }); }
    });
  }

  function withdrawalApprove(id) {
    var w = findIn(S.cache.withdrawals, 'withdrawal_id', id);
    K.formModal({
      title: 'Approve this request?', submitLabel: 'Approve', sub: 'Approving only marks that you will pay it. The coins stay held; nothing is paid yet.',
      html: w ? '<dl class="sa-kv"><dt>User</dt><dd><b>' + esc(w.username) + '</b></dd><dt>Coins</dt><dd class="sa-num">' + coins(w.coins) + '</dd><dt>To pay</dt><dd class="sa-num">' + inr(w.rupee_equivalent) + '</dd><dt>Pay to</dt><dd>' + esc(w.destination || '') + '</dd></dl>' : '',
      onSubmit: function () { return post('/api/subadmin/withdrawals/' + encodeURIComponent(id) + '/approve').then(function () { done('Approved. Mark it paid once you have sent the money.'); }); }
    });
  }

  function withdrawalPaid(id) {
    var w = findIn(S.cache.withdrawals, 'withdrawal_id', id);
    if (!w) return;
    K.formModal({
      title: 'Mark as paid', submitLabel: 'Mark paid',
      sub: 'Record the payout you have already sent to ' + w.username + '. ' + coins(w.coins) + ' coins at ' + inr(w.rate) + ' each = ' + inr(w.rupee_equivalent) + '.',
      html: '<dl class="sa-kv" style="margin-bottom:12px;"><dt>Pay to</dt><dd>' + esc(w.destination || '') + '</dd></dl><div class="sa-form">' +
        K.field('amount_paid', 'Amount paid (₹)', K.input('amount_paid', 'number', 'required min="0.01" step="0.01" value="' + esc(w.rupee_equivalent) + '"')) +
        K.field('method', 'Paid by', K.methodSelect('method', S.methods, 'UPI')) +
        K.field('reference', 'Payment reference (optional)', K.input('reference', 'text', 'maxlength="64" placeholder="UPI UTR / receipt no."'), '', true) +
        '<div class="sa-field wide"><label class="sa-check"><input type="checkbox" name="confirm" value="1"> I confirm this payout has actually been sent to the user.</label></div></div>',
      onSubmit: function (form) {
        if (!form.elements.confirm.checked) throw new Error('Tick the box to confirm the payout has been sent.');
        return post('/api/subadmin/withdrawals/' + encodeURIComponent(id) + '/paid', {
          amount_paid: form.elements.amount_paid.value, method: form.elements.method.value, reference: form.elements.reference.value.trim(), confirm: true,
        }).then(function (r) { setInventory(r.coin_balance); done('Withdrawal marked paid.'); });
      }
    });
  }

  function withdrawalReject(id) {
    var w = findIn(S.cache.withdrawals, 'withdrawal_id', id);
    K.reasonModal({
      title: 'Reject this request?', submitLabel: 'Reject', sub: w ? 'The ' + coins(w.coins) + ' held coins go back to ' + w.username + '\'s balance.' : '',
      onSubmit: function (reason) { return post('/api/subadmin/withdrawals/' + encodeURIComponent(id) + '/reject', { reason: reason }).then(function () { done('Request rejected; the coins were returned.'); }); }
    });
  }

  function allocationPay(id) {
    var a = findIn(S.cache.allocations, 'id', id);
    if (!a) return;
    K.formModal({
      title: 'Record your payment to the Admin', submitLabel: 'Record payment',
      sub: 'Allocation ' + a.id + ': ' + coins(a.coins) + ' coins for ' + inr(a.agreed_amount) + '. The Admin confirms when the money arrives.',
      html: '<div class="sa-form">' + K.field('amount', 'Amount paid (₹)', K.input('amount', 'number', 'required min="0.01" step="0.01" value="' + esc(a.remaining_to_record) + '"')) +
        K.field('method', 'Paid by', K.methodSelect('method', S.methods, 'UPI')) +
        K.field('reference', 'Payment reference (optional)', K.input('reference', 'text', 'maxlength="64" placeholder="UPI UTR / receipt no."'), '', true) +
        K.field('notes', 'Notes (optional)', '<textarea id="saf_notes" name="notes" maxlength="300"></textarea>', '', true) + '</div>',
      onSubmit: function (form) {
        return post('/api/subadmin/allocations/' + encodeURIComponent(id) + '/payments', {
          amount: form.elements.amount.value, method: form.elements.method.value, reference: form.elements.reference.value.trim(), notes: form.elements.notes.value.trim(),
        }).then(function () { done('Payment recorded. It shows as unconfirmed until the Admin confirms it.'); });
      }
    });
  }

  function logout() {
    post('/api/subadmin/logout').catch(function () {});
    clearToken(); S.me = null; S.users = null; S.cache = {};
    K.closeModal();
    showLogin('Signed out.');
  }

  function onClick(e) {
    var t = e.target.closest ? e.target.closest('[data-act], [data-action]') : null;
    if (!t) return;
    var act = t.getAttribute('data-act') || t.getAttribute('data-action'), id = t.getAttribute('data-id');
    switch (act) {
      case 'logout': return logout();
      case 'reload': return reload();
      case 'goto': return switchView(id);
      case 'new-user': return newUser();
      case 'user-view': return viewUser(id);
      case 'user-deposit': K.closeModal(); return newDeposit(id);
      case 'new-deposit': return newDeposit(null);
      case 'dep-payment': return depositPayment(id);
      case 'dep-confirm': return depositConfirm(id);
      case 'dep-cancel': return depositCancel(id);
      case 'wd-approve': return withdrawalApprove(id);
      case 'wd-paid': return withdrawalPaid(id);
      case 'wd-reject': return withdrawalReject(id);
      case 'alloc-pay': return allocationPay(id);
      case 'rep-preset':
        S.cache.report = id === 'all' ? { from: '', to: '' } : { from: K.istDate(parseInt(id, 10)), to: K.istDate(0) };
        return RENDER.reports();
    }
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
})();
