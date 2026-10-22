/**
 * Sub-Admin management, for the Admin (admin.html → "Sub-Admins" tab) and the Super Admin (the super
 * admin console → "Sub-Admins"). One implementation for both, so they read the same records the same
 * way; the host page passes its own authenticated fetch and its theme:
 *
 *     SubAdminConsole.mount(element, { api: fetchFunction, theme: 'admin' | 'super' })
 *
 * Talks only to /api/admin/subadmins… and /api/admin/subadmin-…, which the server gates to the Admin
 * and Super Admin roles. Nothing on this screen is computed locally: every figure is the server's.
 */
(function (global) {
  'use strict';
  var K = global.SAKit;
  var esc = K.esc, coins = K.coins, inr = K.inr, when = K.when;

  function Console(el, opts) {
    this.el = el;
    this.fetchFn = opts.api || global.fetch.bind(global);
    this.theme = opts.theme || 'admin';
    this.s = { view: 'list', id: null, tab: 'allocations', list: null, detail: null, q: '', depStatus: '', wdStatus: '', hist: '', rep: null, platHist: '' };
    el.classList.add('sa-root');
    if (this.theme === 'super') el.classList.add('sa-theme-super');
    var self = this;
    el.addEventListener('click', function (e) { self.onClick(e); });
    document.addEventListener('click', function (e) {
      // Buttons inside the shared modal (rendered outside this element) route here too.
      var m = document.getElementById('saKitModal');
      if (m && m.contains(e.target) && self.active) self.onClick(e);
    });
  }

  Console.prototype.api = function (path, opts) {
    opts = opts || {};
    var init = { method: opts.method || 'GET', headers: { Accept: 'application/json' }, cache: 'no-store' };
    if (opts.body !== undefined) { init.headers['Content-Type'] = 'application/json'; init.body = JSON.stringify(opts.body); }
    return Promise.resolve(this.fetchFn(path, init)).catch(function () {
      throw new Error('Cannot reach the server. Check the connection and try again.');
    }).then(function (res) {
      return res.json().catch(function () { return null; }).then(function (d) {
        if (res.status === 401 || res.status === 403) throw new Error('Your operator session has ended or lacks permission. Sign in again.');
        if (!res.ok || !d || d.ok === false) {
          var err = new Error((d && d.error) || ('Request failed (' + res.status + ').'));
          err.status = res.status; err.data = d;
          throw err;
        }
        return d;
      });
    });
  };
  Console.prototype.post = function (path, body) { return this.api(path, { method: 'POST', body: body || {} }); };

  Console.prototype.show = function () { this.active = true; K.setTheme(this.theme); this.render(); };
  Console.prototype.hide = function () { this.active = false; };
  Console.prototype.render = function () {
    if (this.s.view === 'profile') return this.renderProfile();
    if (this.s.view === 'history') return this.renderPlatformHistory();
    if (this.s.view === 'events') return this.renderEvents();
    return this.renderList();
  };
  Console.prototype.loading = function () { this.el.innerHTML = '<div class="sa-loading">Loading…</div>'; };
  Console.prototype.failed = function (err) {
    this.el.innerHTML = '<div class="sa-error">' + esc(err.message) + ' <button type="button" class="sa-btn sm" data-act="reload">Try again</button></div>';
  };
  Console.prototype.done = function (msg) { K.closeModal(); K.toast(msg); this.render(); };

  // ------------------------------------------------------------------------------------------ list
  Console.prototype.renderList = function () {
    var self = this;
    self.loading();
    self.api('/api/admin/subadmins').then(function (d) {
      self.s.list = d.subadmins || [];
      self.s.info = { rate: d.coin_rupee_rate, methods: d.payment_methods, settings: d.settings };
      var T = d.totals || {};
      var active = self.s.list.filter(function (x) { return x.status === 'active'; }).length;
      var pend = (T.wd_pending_count || 0) + (T.wd_approved_count || 0);
      var rows = self.s.list.map(function (x) {
        var f = x.figures || {};
        var p = (f.wd_pending_count || 0) + (f.wd_approved_count || 0);
        return '<tr class="click" data-act="open" data-id="' + esc(x.id) + '" data-search="' + esc((x.full_name + ' ' + x.username + ' ' + x.public_id).toLowerCase()) + '">' +
          '<td><b>' + esc(x.full_name) + '</b><div class="sa-who">' + esc(x.username) + ' · <code>' + esc(x.public_id) + '</code></div></td>' +
          '<td>' + K.badge(x.status) + '</td>' +
          '<td class="num sa-num">' + coins(f.coin_balance) + '</td><td class="num sa-num">' + coins(f.alloc_credited_coins) + '</td>' +
          '<td class="num sa-num">' + esc(f.users || 0) + '</td>' +
          '<td class="num sa-num">' + (p ? '<span class="sa-warn">' + esc(p) + '</span><div class="sa-who">' + inr(f.pending_payouts_inr) + '</div>' : '0') + '</td>' +
          '<td class="num sa-num">' + inr(f.paid_admin_confirmed) + (f.paid_admin_unconfirmed > 0 ? '<div class="sa-who sa-warn">+ ' + inr(f.paid_admin_unconfirmed) + ' to confirm</div>' : '') + '</td>' +
          '<td class="num sa-num">' + (f.owed_admin > 0 ? '<span class="sa-warn">' + inr(f.owed_admin) + '</span>' : inr(0)) + '</td>' +
          '<td class="num sa-num">' + inr(f.estimated_net_position) + '</td>' +
          '<td>' + (f.reconciled ? '<span class="sa-good" title="Inventory matches the coin ledger">✓</span>' : '<span class="sa-bad" title="Inventory does not match the coin ledger">✗</span>') + '</td>' +
          '<td><button type="button" class="sa-btn sm" data-act="open" data-id="' + esc(x.id) + '">Open</button></td></tr>';
      }).join('');
      self.el.innerHTML =
        '<div class="sa-head"><div><h2>Sub-Admins</h2><div class="sa-sub">Sub-admins receive coins from the Admin\'s <b>unlimited</b> supply — nothing is ever deducted from the Admin — sell them to their own users, and pay out those users\' withdrawals. Cash is recorded here; the site does not verify UPI or cash payments itself, so every confirmation names who made it.</div></div>' +
        '<div class="sa-actions"><button type="button" class="sa-btn primary" data-act="create">+ Create Sub-Admin</button>' +
        '<button type="button" class="sa-btn" data-act="platform-history">All transactions</button><button type="button" class="sa-btn" data-act="events">Activity log</button>' +
        '<button type="button" class="sa-btn" data-act="settings">Workflow settings</button><button type="button" class="sa-btn" data-act="reload">↻</button></div></div>' +
        '<div class="sa-stats">' +
        K.stat('accent', 'Sub-admins', esc(T.subadmins || 0), '<b>' + active + '</b> active · <b>' + ((T.subadmins || 0) - active) + '</b> suspended') +
        K.stat('', 'Admin coin supply', '∞', 'unlimited · net issued to date <b>' + coins(T.net_coins_issued_from_supply) + '</b>') +
        K.stat('', 'Coins held by sub-admins', coins(T.coin_balance), 'allocated in total <b>' + coins(T.alloc_credited_coins) + '</b>') +
        K.stat('good', 'Cash received from sub-admins', inr(T.paid_admin_confirmed), 'awaiting confirmation <b>' + inr(T.paid_admin_unconfirmed) + '</b>') +
        K.stat(T.owed_admin > 0 ? 'warn' : '', 'Still owed by sub-admins', inr(T.owed_admin), 'for coins already credited') +
        K.stat('', 'Users\' deposits to sub-admins', inr(T.cash_from_users_confirmed), 'awaiting confirmation <b>' + inr(T.cash_from_users_unconfirmed) + '</b>') +
        K.stat('', 'Payouts made by sub-admins', inr(T.payout_confirmed_inr), '<b>' + esc(T.wd_paid_count || 0) + '</b> completed') +
        K.stat(pend ? 'warn' : '', 'Pending withdrawal obligations', esc(pend), 'worth <b>' + inr((T.wd_pending_inr || 0) + (T.wd_approved_inr || 0)) + '</b>') +
        K.stat(T.all_reconciled ? 'good' : 'bad', 'Books reconciled', T.all_reconciled ? '✓' : '✗', T.all_reconciled ? 'every inventory matches its ledger' : 'a sub-admin\'s inventory does not match its ledger') +
        '</div>' +
        '<div class="sa-filters"><div class="sa-field grow"><label class="sa-lbl" for="saConsoleSearch">Search</label><input type="search" id="saConsoleSearch" placeholder="name, username or SA- id" autocomplete="off" value="' + esc(self.s.q) + '"></div></div>' +
        K.table('<th>Sub-admin</th><th>Status</th><th class="num">Inventory</th><th class="num">Allocated</th><th class="num">Users</th><th class="num">Pending withdrawals</th><th class="num">Paid to Admin</th><th class="num">Owed</th><th class="num">Est. position</th><th>Books</th><th></th>',
          rows, 11, 'No sub-admins yet. Use “Create Sub-Admin” to add one.') +
        '<div class="sa-note" id="saConsoleCount" style="margin-top:8px;"></div>';
      var inp = self.el.querySelector('#saConsoleSearch');
      var filter = function () {
        var q = inp.value.trim().toLowerCase(), shown = 0;
        self.s.q = inp.value;
        self.el.querySelectorAll('tr[data-search]').forEach(function (tr) { var hit = !q || tr.getAttribute('data-search').indexOf(q) >= 0; tr.hidden = !hit; if (hit) shown++; });
        self.el.querySelector('#saConsoleCount').textContent = shown + ' of ' + self.s.list.length + ' sub-admin(s)';
      };
      inp.addEventListener('input', filter);
      filter();
    }).catch(function (e) { self.failed(e); });
  };

  // ------------------------------------------------------------------------------------------ profile
  Console.prototype.renderProfile = function () {
    var self = this, id = self.s.id;
    self.loading();
    self.api('/api/admin/subadmins/' + encodeURIComponent(id)).then(function (d) {
      self.s.detail = d;
      self.s.info = { rate: d.coin_rupee_rate, methods: d.payment_methods, settings: d.settings };
      var a = d.subadmin, f = d.figures || {};
      var tabs = [['allocations', 'Allocations & payments'], ['users', 'Users (' + (d.users || []).length + ')'], ['deposits', 'Deposits'], ['withdrawals', 'Withdrawals'],
                  ['history', 'Coin & cash history'], ['report', 'Financial report'], ['activity', 'Account activity']];
      self.el.innerHTML =
        '<button type="button" class="sa-btn sm" data-act="back" style="margin-bottom:12px;">← All sub-admins</button>' +
        '<div class="sa-head"><div><h2>' + esc(a.full_name) + ' ' + K.badge(a.status) + '</h2>' +
        '<div class="sa-sub">' + esc(a.username) + ' · <code class="sa-code">' + esc(a.public_id) + '</code> · created ' + esc(when(a.created_at)) + ' by ' + esc(a.created_by) + ' (' + esc(a.created_by_role) + ')' +
        ' · last sign-in ' + esc(when(a.last_login_at)) + (a.must_change_password ? ' · <span class="sa-warn">must change the temporary password</span>' : '') + '</div></div>' +
        '<div class="sa-actions">' +
        (a.status === 'active' ? '<button type="button" class="sa-btn primary" data-act="allocate">Allocate coins</button><button type="button" class="sa-btn" data-act="assign">Assign user</button>' : '') +
        '<button type="button" class="sa-btn" data-act="adjust">Adjust inventory</button><button type="button" class="sa-btn" data-act="reset-pw">Reset password</button>' +
        (a.status === 'active' ? '<button type="button" class="sa-btn bad" data-act="suspend">Suspend</button>' : '<button type="button" class="sa-btn good" data-act="reactivate">Reactivate</button>') +
        '</div></div>' +
        (a.status === 'suspended' ? '<div class="sa-banner bad">Suspended ' + esc(when(a.status_changed_at)) + ' by ' + esc(a.status_changed_by || '') + (a.status_reason ? ': ' + esc(a.status_reason) : '') +
          '. They cannot sign in; their history, balance and obligations are kept. New withdrawals from their users go to the Admin\'s queue.' +
          ((f.wd_pending_count || 0) + (f.wd_approved_count || 0) ? ' <b>' + ((f.wd_pending_count || 0) + (f.wd_approved_count || 0)) + ' request(s) are still waiting on them</b> — move or remove those users to hand them on.' : '') + '</div>' : '') +
        K.statsHtml(f) + K.positionHtml(f) +
        '<div class="sa-tabs" style="margin-top:6px;">' + tabs.map(function (t) {
          return '<button type="button" class="sa-tab' + (self.s.tab === t[0] ? ' active' : '') + '" data-act="tab" data-id="' + t[0] + '">' + esc(t[1]) + '</button>';
        }).join('') + '</div><div data-k="tab"></div>';
      self.renderTab();
    }).catch(function (e) { self.failed(e); });
  };

  Console.prototype.renderTab = function () {
    var self = this, d = self.s.detail, box = self.el.querySelector('[data-k="tab"]');
    if (!d || !box) return;
    var t = self.s.tab;
    if (t === 'allocations') {
      box.innerHTML = '<p class="sa-note" style="margin:0 0 8px;">Each allocation is linked to its payment records. <b>Confirm received</b> only once the money is actually in your account.</p>' +
        K.allocationsHtml(d.allocations, { admin: true });
    } else if (t === 'users') {
      box.innerHTML = '<div class="sa-actions" style="margin-bottom:10px;">' + (d.subadmin.status === 'active' ? '<button type="button" class="sa-btn" data-act="assign">Assign user</button>' : '') + '</div>' +
        K.usersHtml(d.users, { admin: true, emptyText: 'No users assigned. The sub-admin can create users, or you can assign existing players.' }) + K.activityHtml(d.game_activity);
    } else if (t === 'deposits' || t === 'withdrawals') {
      var isDep = t === 'deposits';
      var st = isDep ? self.s.depStatus : self.s.wdStatus;
      var opts = isDep ? ['', 'Requested', 'Unconfirmed', 'Confirmed', 'Cancelled', 'Reversed'] : ['', 'Pending', 'Approved', 'Paid', 'Rejected', 'Reversed', 'Returned'];
      var list = (isDep ? d.deposits : d.withdrawals).filter(function (x) { return !st || x.status === st; });
      box.innerHTML = '<div class="sa-filters"><div class="sa-field"><label class="sa-lbl">Status</label><select data-k="st">' +
        opts.map(function (o) { return '<option value="' + o + '"' + (o === st ? ' selected' : '') + '>' + (o || 'All') + '</option>'; }).join('') + '</select></div></div>' +
        (isDep ? K.depositsHtml(list, { admin: true }) : K.withdrawalsHtml(list, { admin: true })) +
        '<p class="sa-note" style="margin-top:8px;">' + (isDep
          ? 'Deposits are recorded and confirmed by the sub-admin. You can reverse a confirmed one as a correction (the user must still have the coins).'
          : 'Withdrawals are paid by the sub-admin. If a payout marked paid never reached the user, reverse it: the coins go back to the user.') + '</p>';
      box.querySelector('[data-k="st"]').addEventListener('change', function () { if (isDep) self.s.depStatus = this.value; else self.s.wdStatus = this.value; self.renderTab(); });
    } else if (t === 'history') {
      box.innerHTML = K.historyFiltersHtml() + '<div data-k="hist-out"><div class="sa-loading">Loading…</div></div>';
      var form = box.querySelector('[data-k="hist-filters"]');
      restore(form, self.s.hist);
      form.addEventListener('submit', function (e) { e.preventDefault(); self.s.hist = K.formQuery(form); self.renderTab(); });
      self.api('/api/admin/subadmin-history?subadmin_id=' + encodeURIComponent(d.subadmin.id) + (self.s.hist ? '&' + self.s.hist : '')).then(function (h) {
        box.querySelector('[data-k="hist-out"]').innerHTML = K.historyHtml(h.rows) + totalsLine(h);
      }).catch(function (e) { box.querySelector('[data-k="hist-out"]').innerHTML = '<div class="sa-error">' + esc(e.message) + '</div>'; });
    } else if (t === 'report') {
      var r = self.s.rep || { from: K.istDate(29), to: K.istDate(0) };
      box.innerHTML = '<form class="sa-filters" data-k="rep"><div class="sa-field"><label class="sa-lbl">From</label><input type="date" name="from" value="' + esc(r.from) + '"></div>' +
        '<div class="sa-field"><label class="sa-lbl">To</label><input type="date" name="to" value="' + esc(r.to) + '"></div><button type="submit" class="sa-btn primary">Show</button>' +
        '<button type="button" class="sa-btn" data-act="rep-preset" data-id="0">Today</button><button type="button" class="sa-btn" data-act="rep-preset" data-id="6">7 days</button>' +
        '<button type="button" class="sa-btn" data-act="rep-preset" data-id="29">30 days</button><button type="button" class="sa-btn" data-act="rep-preset" data-id="all">All time</button></form>' +
        '<div data-k="rep-out"><div class="sa-loading">Loading…</div></div>';
      var rf = box.querySelector('[data-k="rep"]');
      rf.addEventListener('submit', function (e) { e.preventDefault(); self.s.rep = { from: rf.elements.from.value, to: rf.elements.to.value }; self.renderTab(); });
      var qs = new URLSearchParams(); if (r.from) qs.set('from', r.from); if (r.to) qs.set('to', r.to);
      self.api('/api/admin/subadmins/' + encodeURIComponent(d.subadmin.id) + '/report?' + qs.toString()).then(function (x) {
        box.querySelector('[data-k="rep-out"]').innerHTML = K.reportHtml(x.report);
      }).catch(function (e) { box.querySelector('[data-k="rep-out"]').innerHTML = '<div class="sa-error">' + esc(e.message) + '</div>'; });
    } else if (t === 'activity') {
      box.innerHTML = K.eventsHtml(d.events) + '<p class="sa-note" style="margin-top:8px;">Money actions are in the coin and cash history; this list covers the account itself: creation, password changes and resets, suspension, users added or moved.</p>';
    }
  };

  function totalsLine(h) {
    var T = h.totals || {}, parts = [];
    if (T.coin_in || T.coin_out) parts.push('coins into inventory <b>' + coins(T.coin_in) + '</b>, out <b>' + coins(T.coin_out) + '</b>');
    Object.keys(T.cash || {}).forEach(function (k) { var p = k.split('|'); parts.push(esc(K.typeLabel('cash', p[0])) + ' ' + esc(p[1]).toLowerCase() + ' <b>' + inr(T.cash[k]) + '</b>'); });
    return '<div class="sa-note" style="margin-top:8px;">' + esc(h.rows.length) + ' record(s)' + (h.capped ? ' (newest shown — narrow the filters for older)' : '') + (parts.length ? ' · Totals for these rows: ' + parts.join(' · ') : '') + '</div>';
  }
  function restore(form, qs) {
    if (!form || !qs) return;
    new URLSearchParams(qs).forEach(function (v, k) { if (form.elements[k]) form.elements[k].value = v; });
  }

  // ------------------------------------------------------------------------------------------ platform-wide
  Console.prototype.renderPlatformHistory = function () {
    var self = this;
    self.loading();
    var listP = self.s.list ? Promise.resolve({ subadmins: self.s.list }) : self.api('/api/admin/subadmins');
    listP.then(function (l) {
      self.s.list = l.subadmins || [];
      var sel = '<div class="sa-field"><label class="sa-lbl">Sub-admin</label><select name="subadmin_id"><option value="">All sub-admins</option>' +
        self.s.list.map(function (x) { return '<option value="' + esc(x.id) + '">' + esc(x.username) + ' — ' + esc(x.full_name) + '</option>'; }).join('') + '</select></div>';
      self.el.innerHTML = '<button type="button" class="sa-btn sm" data-act="back" style="margin-bottom:12px;">← All sub-admins</button>' +
        '<div class="sa-head"><div><h2>All sub-admin transactions</h2><div class="sa-sub">Every allocation, payment confirmation, coin credit, withdrawal, payout and correction across all sub-admins — the same records each sub-admin sees, with who did each and when.</div></div></div>' +
        K.historyFiltersHtml(sel) + '<div data-k="out"><div class="sa-loading">Loading…</div></div>';
      var form = self.el.querySelector('[data-k="hist-filters"]');
      restore(form, self.s.platHist);
      form.addEventListener('submit', function (e) { e.preventDefault(); self.s.platHist = K.formQuery(form); self.renderPlatformHistory(); });
      return self.api('/api/admin/subadmin-history' + (self.s.platHist ? '?' + self.s.platHist : '')).then(function (h) {
        self.el.querySelector('[data-k="out"]').innerHTML = K.historyHtml(h.rows, { showSubadmin: true }) + totalsLine(h);
      });
    }).catch(function (e) { self.failed(e); });
  };

  Console.prototype.renderEvents = function () {
    var self = this;
    self.loading();
    self.api('/api/admin/subadmin-events').then(function (d) {
      self.el.innerHTML = '<button type="button" class="sa-btn sm" data-act="back" style="margin-bottom:12px;">← All sub-admins</button>' +
        '<div class="sa-head"><div><h2>Sub-admin account activity</h2><div class="sa-sub">Accounts created, suspended or reactivated, password resets and changes, users created, assigned, moved or removed — newest first.</div></div></div>' +
        K.eventsHtml(d.events, { showSubadmin: true });
    }).catch(function (e) { self.failed(e); });
  };

  // ------------------------------------------------------------------------------------------ actions
  Console.prototype.findAlloc = function (id) { return ((this.s.detail || {}).allocations || []).filter(function (a) { return a.id === id; })[0]; };
  Console.prototype.findPayment = function (id) {
    var out = null;
    ((this.s.detail || {}).allocations || []).forEach(function (a) { (a.payments || []).forEach(function (p) { if (p.id === id) out = { p: p, a: a }; }); });
    return out;
  };
  Console.prototype.sid = function () { return this.s.detail ? this.s.detail.subadmin.id : null; };
  Console.prototype.saName = function () { return this.s.detail ? this.s.detail.subadmin.username : ''; };
  Console.prototype.methods = function () { return (this.s.info && this.s.info.methods) || ['UPI', 'Cash', 'Bank transfer', 'Other']; };

  Console.prototype.create = function () {
    var self = this;
    K.formModal({
      title: 'Create a Sub-Admin', submitLabel: 'Create Sub-Admin',
      sub: 'The password is stored only as a secure hash. Nobody — including you — can read it later. The sub-admin must choose their own at first sign-in.',
      html: '<div class="sa-form">' + K.field('full_name', 'Full name', K.input('full_name', 'text', 'required maxlength="60" autocomplete="off"')) +
        K.field('username', 'Username', K.input('username', 'text', 'required maxlength="20" autocomplete="off" placeholder="3-20 letters, digits or _"'), 'Used to sign in at /subadmin.html. Must be unique.') +
        '<div class="sa-field wide"><span class="sa-lbl">Password</span><div class="sa-radio-group">' +
        '<label class="sa-radio"><input type="radio" name="pwmode" value="generate" checked><span><span class="t">Generate a temporary password</span><span class="d">Shown to you once, to pass on to the sub-admin.</span></span></label>' +
        '<label class="sa-radio"><input type="radio" name="pwmode" value="set"><span><span class="t">Set an initial password</span><span class="d">They will still have to change it at first sign-in.</span></span></label></div></div>' +
        '<div class="sa-field wide" data-k="pwfield" hidden><label for="saf_password">Initial password</label><input id="saf_password" name="password" type="text" minlength="8" maxlength="128" autocomplete="off"><span class="hint">At least 8 characters.</span></div></div>',
      onOpen: function (form) {
        form.querySelectorAll('input[name="pwmode"]').forEach(function (r) {
          r.addEventListener('change', function () { form.querySelector('[data-k="pwfield"]').hidden = form.querySelector('input[name="pwmode"]:checked').value !== 'set'; });
        });
      },
      onSubmit: function (form, body) {
        var gen = form.querySelector('input[name="pwmode"]:checked').value === 'generate';
        if (!gen && form.elements.password.value.length < 8) throw new Error('The initial password must be at least 8 characters.');
        return self.post('/api/admin/subadmins', {
          full_name: form.elements.full_name.value.trim(), username: form.elements.username.value.trim(),
          generate_password: gen, password: gen ? '' : form.elements.password.value,
        }).then(function (d) {
          self.s.list = null;
          body.innerHTML = '<div class="sa-banner ok"><b>' + esc(d.subadmin.full_name) + '</b> (' + esc(d.subadmin.username) + ', <code class="sa-code">' + esc(d.subadmin.public_id) + '</code>) can now sign in at <b>subadmin.html</b>.</div>' +
            (d.temporary_password ? secretHtml(d.temporary_password) : '<p class="sa-note">The password you set is not shown again. They will be asked to change it at first sign-in.</p>') +
            '<div class="sa-modal-foot"><button type="button" class="sa-btn" data-modal-close>Close</button><button type="button" class="sa-btn primary" data-act="open-after-create" data-id="' + esc(d.subadmin.id) + '">Open profile</button></div>';
          K.toast('Sub-admin ' + d.subadmin.username + ' created.');
          if (self.s.view === 'list') self.renderList();
        });
      }
    });
  };

  function secretHtml(pw) {
    return '<p class="sa-note" style="margin-bottom:4px;"><b>Temporary password — shown only this once.</b> Pass it on securely; it is not stored anywhere readable.</p>' +
      '<div class="sa-secret"><code>' + esc(pw) + '</code><button type="button" class="sa-btn sm" data-act="copy" data-id="' + esc(pw) + '">Copy</button></div>';
  }

  Console.prototype.allocate = function () {
    var self = this, mode = (self.s.info && self.s.info.settings && self.s.info.settings.allocation_credit) || 'after_payment';
    var key = K.newKey();
    K.formModal({
      title: 'Allocate coins to ' + self.saName(), submitLabel: 'Allocate',
      sub: mode === 'immediate'
        ? 'Workflow: coins are credited at once; the agreed amount is owed until you confirm the payment.'
        : 'Workflow: coins are credited once the agreed amount is paid and you confirm it. Nothing is deducted from the Admin — the supply is unlimited.',
      html: '<div class="sa-form">' + K.field('coins', 'Coins', K.input('coins', 'number', 'required min="1" step="1" inputmode="numeric"')) +
        K.field('agreed_amount', 'Agreed payment (₹)', K.input('agreed_amount', 'number', 'required min="0" step="0.01" inputmode="decimal"'), '<span data-k="ppc">What the sub-admin pays you for these coins.</span>') +
        K.field('payment_method', 'Payment method', K.methodSelect('payment_method', self.methods(), '', true)) +
        K.field('payment_reference', 'Payment reference (optional)', K.input('payment_reference', 'text', 'maxlength="64" placeholder="UPI UTR / receipt no."')) +
        K.field('notes', 'Notes (optional)', '<textarea id="saf_notes" name="notes" maxlength="300"></textarea>', '', true) +
        '<div class="sa-field wide"><label class="sa-check"><input type="checkbox" name="payment_received" value="1"> The payment has been received — confirm it now.</label>' +
        '<span class="hint">Leave unticked if the money has not arrived yet; you can record and confirm it later from the allocation.</span></div></div>',
      onOpen: function (form) {
        var upd = function () {
          var c = parseFloat(form.elements.coins.value), a = parseFloat(form.elements.agreed_amount.value);
          form.querySelector('[data-k="ppc"]').textContent = c > 0 && a >= 0 ? 'That is ' + inr(a / c) + ' per coin.' : 'What the sub-admin pays you for these coins.';
        };
        form.elements.coins.addEventListener('input', upd); form.elements.agreed_amount.addEventListener('input', upd);
      },
      onSubmit: function (form) {
        var received = form.elements.payment_received.checked;
        if (received && !form.elements.payment_method.value && parseFloat(form.elements.agreed_amount.value) > 0) throw new Error('Choose how the payment was received.');
        return self.post('/api/admin/subadmins/' + encodeURIComponent(self.sid()) + '/allocations', {
          coins: form.elements.coins.value, agreed_amount: form.elements.agreed_amount.value || '0',
          payment_method: form.elements.payment_method.value, payment_reference: form.elements.payment_reference.value.trim(),
          notes: form.elements.notes.value.trim(), payment_received: received, idempotency_key: key,
        }).then(function (d) {
          var a = d.allocation;
          self.done(d.replayed ? 'That allocation was already created.' : (a.status === 'credited' ? coins(a.coins) + ' coins credited to ' + self.saName() + '.' : 'Allocation created — coins will be credited when the payment is confirmed.'));
        });
      }
    });
  };

  Console.prototype.recordPayment = function (allocId) {
    var self = this, a = self.findAlloc(allocId);
    if (!a) return;
    K.formModal({
      title: 'Record a payment', submitLabel: 'Record payment',
      sub: 'Allocation ' + a.id + ': ' + coins(a.coins) + ' coins for ' + inr(a.agreed_amount) + '. Still to record: ' + inr(a.remaining_to_record) + '.',
      html: '<div class="sa-form">' + K.field('amount', 'Amount (₹)', K.input('amount', 'number', 'required min="0.01" step="0.01" value="' + esc(a.remaining_to_record) + '"')) +
        K.field('method', 'Method', K.methodSelect('method', self.methods(), 'UPI')) +
        K.field('reference', 'Reference (optional)', K.input('reference', 'text', 'maxlength="64"'), '', true) +
        K.field('notes', 'Notes (optional)', '<textarea id="saf_notes" name="notes" maxlength="300"></textarea>', '', true) +
        '<div class="sa-field wide"><label class="sa-check"><input type="checkbox" name="confirm" value="1" checked> The money has arrived — confirm it now.</label></div></div>',
      onSubmit: function (form) {
        return self.post('/api/admin/subadmin-allocations/' + encodeURIComponent(allocId) + '/payments', {
          amount: form.elements.amount.value, method: form.elements.method.value, reference: form.elements.reference.value.trim(),
          notes: form.elements.notes.value.trim(), confirm: form.elements.confirm.checked,
        }).then(function () { self.done('Payment recorded.'); });
      }
    });
  };

  Console.prototype.confirmPayment = function (payId) {
    var self = this, x = self.findPayment(payId);
    if (!x) return;
    K.formModal({
      title: 'Confirm payment received?', submitLabel: 'Confirm received',
      sub: 'Only confirm once the money is actually in your account. This is recorded with your name and the time' + (x.a.status === 'awaiting_payment' ? ', and may credit the allocation\'s coins.' : '.'),
      html: '<dl class="sa-kv"><dt>From</dt><dd>' + esc(self.saName()) + '</dd><dt>Amount</dt><dd class="sa-num"><b>' + inr(x.p.amount) + '</b></dd><dt>Method</dt><dd>' + esc(x.p.method || '—') + '</dd>' +
        (x.p.reference ? '<dt>Reference</dt><dd><code class="sa-code">' + esc(x.p.reference) + '</code></dd>' : '') + '<dt>Recorded by</dt><dd>' + esc(x.p.created_by) + ' · ' + esc(when(x.p.created_at)) + '</dd></dl>',
      onSubmit: function () { return self.post('/api/admin/subadmin-payments/' + encodeURIComponent(payId) + '/confirm').then(function () { self.done('Payment confirmed.'); }); }
    });
  };

  Console.prototype.reason = function (o) {
    var self = this;
    K.reasonModal({
      title: o.title, sub: o.sub, submitLabel: o.submit, intro: o.intro, optional: !!o.optional,
      onSubmit: function (reason) { return self.post(o.path, Object.assign({ reason: reason }, o.body || {})).then(function () { self.done(o.done); }); }
    });
  };

  Console.prototype.assign = function () {
    var self = this;
    K.formModal({
      title: 'Assign a player to ' + self.saName(), submitLabel: 'Assign',
      sub: 'The player will appear in this sub-admin\'s users, and their future withdrawal requests will go to this sub-admin.',
      html: '<div class="sa-form">' + K.field('username', 'Player username', K.input('username', 'text', 'required autocomplete="off"'), '', true) + '</div><div data-k="move"></div>',
      onSubmit: function (form) {
        var move = !!form.dataset.move;
        return self.post('/api/admin/subadmins/' + encodeURIComponent(self.sid()) + '/assign', { username: form.elements.username.value.trim(), move: move }).then(function (d) {
          self.done(d.changed ? d.user.username + ' now belongs to ' + self.saName() + '.' : d.user.username + ' already belongs to ' + self.saName() + '.');
        }).catch(function (err) {
          if (err.data && err.data.code === 'already_assigned') {
            form.dataset.move = '1';
            form.querySelector('[data-k="move"]').innerHTML = '<div class="sa-banner warn" style="margin-top:12px;">' + esc(err.message) +
              ' Their not-yet-paid withdrawal requests move with them. Press <b>Assign</b> again to move them.</div>';
            return;
          }
          throw err;
        });
      }
    });
  };

  Console.prototype.unassign = function (userId, name) {
    this.reason({
      title: 'Remove ' + name + ' from ' + this.saName() + '?', submit: 'Remove', path: '/api/admin/subadmins/' + encodeURIComponent(this.sid()) + '/unassign',
      body: { user_id: userId }, done: name + ' removed. Any unpaid withdrawal requests went back to the Admin\'s queue.',
      sub: 'Their account and history are untouched. Unpaid withdrawal requests return to the Admin\'s Pending Cashouts.',
      intro: '<p class="sa-note" style="margin:0 0 8px;">Optional note for the record:</p>', optional: true
    });
  };

  Console.prototype.adjust = function () {
    var self = this;
    K.formModal({
      title: 'Adjust ' + self.saName() + '\'s inventory', submitLabel: 'Record adjustment',
      sub: 'An authorised correction. A positive number adds coins from the Admin\'s unlimited supply; a negative one takes coins back (never below zero). The reason is kept in the ledger.',
      html: '<div class="sa-form">' + K.field('coins', 'Coins (+ or −)', K.input('coins', 'number', 'required step="0.01" placeholder="e.g. 250 or -250"')) +
        K.field('reason', 'Reason', '<textarea id="saf_reason" name="reason" maxlength="300" required></textarea>', '', true) + '</div>',
      onSubmit: function (form) {
        if ((form.elements.reason.value || '').trim().length < 3) throw new Error('Please give a reason.');
        return self.post('/api/admin/subadmins/' + encodeURIComponent(self.sid()) + '/adjust', { coins: form.elements.coins.value, reason: form.elements.reason.value.trim() })
          .then(function (d) { self.done('Adjustment recorded. Inventory is now ' + coins(d.coin_balance) + ' coins.'); });
      }
    });
  };

  Console.prototype.resetPassword = function () {
    var self = this;
    K.formModal({
      title: 'Reset ' + self.saName() + '\'s password?', submitLabel: 'Reset password', danger: true,
      sub: 'A new temporary password is generated and shown to you once. Every open session of this sub-admin is signed out, and they must choose a new password at their next sign-in. The old password is never shown.',
      html: '',
      onSubmit: function (form, body) {
        return self.post('/api/admin/subadmins/' + encodeURIComponent(self.sid()) + '/reset-password').then(function (d) {
          body.innerHTML = secretHtml(d.temporary_password) + '<div class="sa-modal-foot"><button type="button" class="sa-btn primary" data-modal-close>Done</button></div>';
          K.toast('Password reset.');
          self.renderProfile();
        });
      }
    });
  };

  Console.prototype.settings = function () {
    var self = this;
    var load = self.s.info && self.s.info.settings ? Promise.resolve(self.s.info) : self.api('/api/admin/subadmin-settings').then(function (d) { return { settings: d.settings, rate: d.coin_rupee_rate }; });
    load.then(function (info) {
      var st = info.settings || {};
      var r = function (name, val, title, desc) {
        return '<label class="sa-radio"><input type="radio" name="' + name + '" value="' + val + '"' + (st[name] === val ? ' checked' : '') + '><span><span class="t">' + esc(title) + '</span><span class="d">' + esc(desc) + '</span></span></label>';
      };
      K.formModal({
        title: 'Workflow settings', submitLabel: 'Save settings',
        sub: 'These rules apply to every sub-admin from now on. Past records keep the rule they were made under.',
        html: '<div class="sa-field" style="margin-bottom:14px;"><span class="sa-lbl">When do allocated coins reach the sub-admin?</span><div class="sa-radio-group">' +
          r('allocation_credit', 'after_payment', 'After the payment is confirmed (recommended)', 'Coins are credited once the agreed amount has been paid and you have confirmed it.') +
          r('allocation_credit', 'immediate', 'Immediately, on credit', 'Coins are credited when you allocate; the agreed amount stays an outstanding obligation until confirmed.') +
          '</div></div><p class="sa-note"><b>Fixed rule:</b> when a sub-admin pays out a withdrawal, the user\'s coins go back to the Admin\'s supply — the sub-admin does not get them back, so to sell more they buy more from you.</p><p class="sa-note">Coin value: 1 coin = ' + inr(info.rate || (self.s.info && self.s.info.rate) || 1) + ' (COIN_RUPEE_RATE in the server configuration). Game wins and losses never touch a sub-admin\'s inventory.</p>',
        onSubmit: function (form) {
          return self.post('/api/admin/subadmin-settings', {
            allocation_credit: form.querySelector('input[name="allocation_credit"]:checked').value,
          }).then(function (d) { if (self.s.info) self.s.info.settings = d.settings; self.done('Settings saved.'); });
        }
      });
    }).catch(function (e) { K.toast(e.message, 'error'); });
  };

  Console.prototype.viewUser = function (userId) {
    var self = this;
    var body = K.openModal({ title: 'User details', wide: true, html: '<div class="sa-loading">Loading…</div>' });
    self.api('/api/admin/subadmins/' + encodeURIComponent(self.sid()) + '/users/' + encodeURIComponent(userId)).then(function (d) {
      body.innerHTML = K.userDetailHtml(d);
    }).catch(function (e) { body.innerHTML = '<div class="sa-error">' + esc(e.message) + '</div>'; });
  };

  Console.prototype.onClick = function (e) {
    var t = e.target.closest ? e.target.closest('[data-act]') : null;
    if (!t) return;
    var act = t.getAttribute('data-act'), id = t.getAttribute('data-id'), self = this, sid = self.sid();
    switch (act) {
      case 'reload': return self.render();
      case 'open': e.stopPropagation(); self.s.view = 'profile'; self.s.id = id; self.s.tab = 'allocations'; self.s.depStatus = ''; self.s.wdStatus = ''; self.s.hist = ''; return self.render();
      case 'open-after-create': K.closeModal(); self.s.view = 'profile'; self.s.id = id; self.s.tab = 'allocations'; return self.render();
      case 'back': self.s.view = 'list'; return self.render();
      case 'tab': self.s.tab = id; self.el.querySelectorAll('.sa-tab').forEach(function (b) { b.classList.toggle('active', b.getAttribute('data-id') === id); }); return self.renderTab();
      case 'create': return self.create();
      case 'platform-history': self.s.view = 'history'; return self.render();
      case 'events': self.s.view = 'events'; return self.render();
      case 'settings': return self.settings();
      case 'allocate': return self.allocate();
      case 'assign': return self.assign();
      case 'adjust': return self.adjust();
      case 'reset-pw': return self.resetPassword();
      case 'suspend':
        return self.reason({ title: 'Suspend ' + self.saName() + '?', submit: 'Suspend', path: '/api/admin/subadmins/' + encodeURIComponent(sid) + '/status', body: { status: 'suspended' },
          done: self.saName() + ' suspended.', sub: 'They are signed out at once and cannot sign in. Nothing is deleted: their balance, history and obligations stay.' });
      case 'reactivate':
        K.formModal({ title: 'Reactivate ' + self.saName() + '?', submitLabel: 'Reactivate', html: '', sub: 'They can sign in again with their current password.',
          onSubmit: function () { return self.post('/api/admin/subadmins/' + encodeURIComponent(sid) + '/status', { status: 'active' }).then(function () { self.done(self.saName() + ' reactivated.'); }); } });
        return;
      case 'alloc-pay': return self.recordPayment(id);
      case 'alloc-cancel':
        return self.reason({ title: 'Cancel allocation ' + id + '?', submit: 'Cancel allocation', path: '/api/admin/subadmin-allocations/' + encodeURIComponent(id) + '/cancel',
          done: 'Allocation cancelled.', sub: 'No coins were credited, so none move. Payments awaiting confirmation are marked failed.' });
      case 'alloc-reverse':
        return self.reason({ title: 'Reverse allocation ' + id + '?', submit: 'Reverse allocation', path: '/api/admin/subadmin-allocations/' + encodeURIComponent(id) + '/reverse',
          done: 'Allocation reversed; its coins were taken back.', sub: 'The coins are taken back from the sub-admin\'s inventory (they must still have them). Confirmed payments are not changed — reverse those separately if you refund the money.' });
      case 'pay-confirm': return self.confirmPayment(id);
      case 'pay-fail':
        return self.reason({ title: 'Mark payment ' + id + ' as failed?', submit: 'Mark failed', path: '/api/admin/subadmin-payments/' + encodeURIComponent(id) + '/fail',
          done: 'Payment marked failed.', sub: 'Use this when a claimed payment never arrived. The record is kept.' });
      case 'pay-reverse':
        return self.reason({ title: 'Reverse payment ' + id + '?', submit: 'Reverse payment', path: '/api/admin/subadmin-payments/' + encodeURIComponent(id) + '/reverse',
          done: 'Payment reversed.', sub: 'For a refund or a confirmation made by mistake. The original stays in the records, marked reversed, with a reversal entry pointing at it. Coins are not touched.' });
      case 'dep-reverse':
        return self.reason({ title: 'Reverse deposit ' + id + '?', submit: 'Reverse deposit', path: '/api/admin/subadmin-deposits/' + encodeURIComponent(id) + '/reverse',
          done: 'Deposit reversed.', sub: 'The coins are taken back from the user (they must still have them) and returned to the sub-admin\'s inventory. The cash record is marked reversed.' });
      case 'wd-reverse':
        return self.reason({ title: 'Reverse payout ' + id + '?', submit: 'Reverse payout', path: '/api/admin/subadmin-withdrawals/' + encodeURIComponent(id) + '/reverse',
          done: 'Payout reversed; the coins are back with the user.', sub: 'Use this when a payout marked paid never reached the user. The coins go back to the user\'s balance.' });
      case 'user-view': return self.viewUser(id);
      case 'user-unassign': return self.unassign(id, t.getAttribute('data-name') || 'this player');
      case 'rep-preset':
        self.s.rep = id === 'all' ? { from: '', to: '' } : { from: K.istDate(parseInt(id, 10)), to: K.istDate(0) };
        return self.renderTab();
      case 'copy':
        try { navigator.clipboard.writeText(id).then(function () { K.toast('Copied.'); }, function () { K.toast('Copy it by hand.', 'error'); }); } catch (x) { K.toast('Copy it by hand.', 'error'); }
        return;
    }
  };

  global.SubAdminConsole = {
    mount: function (el, opts) {
      if (!el) return null;
      if (!el._saConsole) el._saConsole = new Console(el, opts || {});
      el._saConsole.show();
      return el._saConsole;
    }
  };
})(window);
