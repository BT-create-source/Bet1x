/**
 * Shared pieces for every Sub-Admin screen: formatting, labels, the modal, toasts, and the renderers
 * for figures, reports and record tables. Used by subadmin.html (the sub-admin's console) and by
 * subadmin-console.js (the Sub-Admins section in admin.html and in the super admin console), so the
 * same record always reads the same way to the sub-admin, the Admin and the Super Admin.
 *
 * Every value from the server is escaped before it reaches innerHTML. Action buttons carry
 * data-act / data-id attributes; each host page wires those to its own handlers.
 */
(function (global) {
  'use strict';

  function esc(v) {
    if (v === null || v === undefined) return '';
    return String(v).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; });
  }
  function num(n) { var v = Number(n); return isFinite(v) ? v : 0; }
  function coins(n) { return num(n).toLocaleString('en-IN', { minimumFractionDigits: 0, maximumFractionDigits: 2 }); }
  function inr(n) {
    var v = num(n);
    return (v < 0 ? '−' : '') + '₹' + Math.abs(v).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }
  function when(iso) {
    if (!iso) return '—';
    var d = new Date(iso);
    if (isNaN(d.getTime())) return '—';
    return d.toLocaleString('en-IN', { timeZone: 'Asia/Kolkata', day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit', hour12: false });
  }
  /** An India-time calendar date, `offsetDays` before today, as YYYY-MM-DD. */
  function istDate(offsetDays) { return new Date(Date.now() + 330 * 60000 - (offsetDays || 0) * 86400000).toISOString().slice(0, 10); }
  /** A fresh request key: makes a create request safe to submit twice. */
  function newKey() {
    if (global.crypto && global.crypto.randomUUID) return global.crypto.randomUUID();
    var a = new Uint8Array(16);
    (global.crypto || global.msCrypto).getRandomValues(a);
    return Array.prototype.map.call(a, function (b) { return ('0' + b.toString(16)).slice(-2); }).join('');
  }

  var STATUS_CLASS = {
    active: 'ok', suspended: 'bad', credited: 'ok', awaiting_payment: 'warn', cancelled: '', reversed: 'bad',
    Requested: 'info', Unconfirmed: 'warn', Confirmed: 'ok', Cancelled: '', Reversed: 'bad', Failed: 'bad',
    Pending: 'warn', Approved: 'info', Paid: 'ok', Rejected: 'bad', Returned: '', Completed: 'ok',
    'Part paid': 'warn', Unpaid: 'bad', 'Awaiting confirmation': 'warn', 'No payment due': ''
  };
  var STATUS_LABEL = {
    awaiting_payment: 'Awaiting payment', credited: 'Credited', cancelled: 'Cancelled', reversed: 'Reversed',
    active: 'Active', suspended: 'Suspended', Unconfirmed: 'Unconfirmed', Returned: 'Back with Admin'
  };
  function badge(s) { return '<span class="sa-badge ' + (STATUS_CLASS[s] || '') + '">' + esc(STATUS_LABEL[s] || s) + '</span>'; }

  var COIN_TYPES = {
    allocation_credit: 'Allocation received', allocation_reversal: 'Allocation reversed',
    adjustment_credit: 'Adjustment (+)', adjustment_debit: 'Adjustment (−)',
    user_credit: 'Coins sold to user', user_credit_reversal: 'Sale reversed',
    withdrawal_reserved: 'Withdrawal requested (coins held)', withdrawal_paid: 'Withdrawal paid',
    withdrawal_refund: 'Withdrawal rejected (coins returned)', withdrawal_reversal: 'Payout reversed'
  };
  var CASH_KINDS = { allocation_payment: 'Payment to Admin', user_deposit: 'Cash from user', user_payout: 'Payout to user', reversal: 'Reversal entry' };
  var EVENTS = {
    created: 'Account created', suspended: 'Suspended', reactivated: 'Reactivated', password_reset: 'Password reset',
    password_changed: 'Password changed', user_created: 'Created a user', user_assigned: 'User assigned',
    user_unassigned: 'User removed', user_moved_in: 'User moved in', user_moved_out: 'User moved out'
  };
  function typeLabel(book, t) { return book === 'cash' ? (CASH_KINDS[t] || t) : (COIN_TYPES[t] || t); }
  /** +1 when a coin-ledger row adds to a sub-admin's inventory, −1 when it takes from it, 0 otherwise. */
  function coinSign(r) {
    var to = String(r.to_account || ''), fr = String(r.from_account || '');
    if (to.indexOf('subadmin:') === 0) return 1;
    if (fr.indexOf('subadmin:') === 0) return -1;
    return 0;
  }
  function account(a) {
    a = String(a || '');
    if (a === 'admin_supply') return 'Admin supply (unlimited)';
    if (a.indexOf('subadmin:') === 0) return 'Sub-admin inventory';
    if (a.indexOf('user:') === 0) return 'Player balance';
    if (a.indexOf('reserve:') === 0) return 'Held for withdrawal';
    return a;
  }
  function by(name, role, at) {
    if (!name) return '';
    return esc(name) + (role ? ' <span class="sa-dimtxt">(' + esc(role) + ')</span>' : '') + (at ? ' · ' + esc(when(at)) : '');
  }
  function empty(cols, text) { return '<tr><td colspan="' + cols + '"><div class="sa-empty">' + esc(text) + '</div></td></tr>'; }
  function table(head, rows, cols, emptyText) {
    return '<div class="sa-table-wrap"><table class="sa-table"><thead><tr>' + head + '</tr></thead><tbody>' +
      (rows || empty(cols, emptyText || 'Nothing to show.')) + '</tbody></table></div>';
  }
  function btn(act, id, label, cls, extra) {
    return '<button type="button" class="sa-btn sm ' + (cls || '') + '" data-act="' + esc(act) + '" data-id="' + esc(id) + '"' + (extra || '') + '>' + esc(label) + '</button>';
  }

  // ------------------------------------------------------------------------------------------ toasts
  function toast(msg, kind) {
    var box = document.getElementById('saKitToasts');
    if (!box) {
      box = document.createElement('div');
      box.id = 'saKitToasts';
      box.className = 'sa-root sa-toasts';
      box.setAttribute('aria-live', 'polite');
      document.body.appendChild(box);
    }
    var t = document.createElement('div');
    t.className = 'sa-toast' + (kind === 'error' ? ' err' : '');
    t.textContent = msg;
    box.appendChild(t);
    setTimeout(function () { t.remove(); }, kind === 'error' ? 7000 : 4200);
  }

  // ------------------------------------------------------------------------------------------ modal
  var modalTheme = '';
  function modalEl() {
    var m = document.getElementById('saKitModal');
    if (!m) {
      m = document.createElement('div');
      m.id = 'saKitModal';
      m.className = 'sa-root sa-modal';
      m.setAttribute('role', 'dialog');
      m.setAttribute('aria-modal', 'true');
      m.setAttribute('aria-labelledby', 'saKitModalTitle');
      m.innerHTML = '<div class="sa-modal-card"><div class="sa-modal-top"><div><h3 id="saKitModalTitle"></h3><div class="sa-sub" data-k="sub"></div></div>' +
        '<button class="sa-x" type="button" data-modal-close aria-label="Close">&times;</button></div><div data-k="body"></div></div>';
      document.body.appendChild(m);
      m.addEventListener('click', function (e) {
        if (e.target === m || (e.target.closest && e.target.closest('[data-modal-close]'))) closeModal();
      });
      document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && m.classList.contains('show')) closeModal(); });
    }
    m.classList.toggle('sa-theme-super', modalTheme === 'super');
    return m;
  }
  function setTheme(t) { modalTheme = t || ''; }
  function openModal(o) {
    var m = modalEl();
    m.querySelector('.sa-modal-card').classList.toggle('wide', !!o.wide);
    document.getElementById('saKitModalTitle').textContent = o.title || '';
    m.querySelector('[data-k="sub"]').textContent = o.sub || '';
    var body = m.querySelector('[data-k="body"]');
    body.innerHTML = o.html || '';
    m.classList.add('show');
    m.scrollTop = 0;
    document.body.style.overflow = 'hidden';
    var first = body.querySelector('input:not([type=hidden]):not([readonly]), select, textarea');
    if (first) setTimeout(function () { try { first.focus(); } catch (e) {} }, 30);
    return body;
  }
  function closeModal() {
    var m = document.getElementById('saKitModal');
    if (m) m.classList.remove('show');
    document.body.style.overflow = '';
  }
  /**
   * A form in the modal. onSubmit(form, body) returns a promise; a thrown error is shown under the
   * form and the submit button is disabled while a request is in flight, so a double click sends one.
   */
  function formModal(o) {
    var body = openModal({
      title: o.title, sub: o.sub, wide: o.wide,
      html: '<form novalidate>' + o.html + '<div class="sa-msg" data-k="msg" role="alert"></div><div class="sa-modal-foot">' +
        '<button type="button" class="sa-btn" data-modal-close>Cancel</button>' +
        '<button type="submit" class="sa-btn ' + (o.danger ? 'bad' : 'primary') + '" data-k="submit">' + esc(o.submitLabel || 'Save') + '</button></div></form>'
    });
    var form = body.querySelector('form');
    var msg = body.querySelector('[data-k="msg"]');
    var submit = body.querySelector('[data-k="submit"]');
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      if (submit.disabled) return;
      submit.disabled = true;
      var label = submit.textContent;
      submit.textContent = 'Working…';
      msg.textContent = ''; msg.className = 'sa-msg';
      Promise.resolve().then(function () { return o.onSubmit(form, body); }).catch(function (err) {
        msg.textContent = (err && err.message) || 'Something went wrong.';
        msg.className = 'sa-msg err';
      }).then(function () { submit.disabled = false; submit.textContent = label; });
    });
    if (o.onOpen) o.onOpen(form, body);
    return { form: form, body: body };
  }
  function reasonModal(o) {
    return formModal({
      title: o.title, sub: o.sub, submitLabel: o.submitLabel || 'Confirm', danger: o.danger !== false,
      html: (o.intro || '') + '<div class="sa-field"><label for="saKitReason">' + esc(o.label || 'Reason') + '</label>' +
        '<textarea id="saKitReason" name="reason" maxlength="300" ' + (o.optional ? '' : 'required') + ' placeholder="' + esc(o.placeholder || 'Write a short reason — it is kept in the record') + '"></textarea></div>',
      onSubmit: function (form) {
        var r = form.elements.reason.value.trim();
        if (!o.optional && r.length < 3) throw new Error('Please give a reason (at least 3 characters).');
        return o.onSubmit(r);
      }
    });
  }
  function field(name, label, input, hint, wide) {
    return '<div class="sa-field' + (wide ? ' wide' : '') + '"><label for="saf_' + name + '">' + esc(label) + '</label>' + input +
      (hint ? '<span class="hint">' + hint + '</span>' : '') + '</div>';
  }
  function input(name, type, attrs) { return '<input id="saf_' + name + '" name="' + name + '" type="' + (type || 'text') + '" ' + (attrs || '') + '>'; }
  function methodSelect(name, methods, selected, allowBlank) {
    return '<select id="saf_' + name + '" name="' + name + '">' + (allowBlank ? '<option value="">— not yet —</option>' : '') +
      (methods || ['UPI', 'Cash', 'Bank transfer', 'Other']).map(function (m) {
        return '<option' + (m === selected ? ' selected' : '') + '>' + esc(m) + '</option>';
      }).join('') + '</select>';
  }

  // ------------------------------------------------------------------------------------------ figures
  function stat(cls, lbl, val, sub) {
    return '<div class="sa-stat ' + (cls || '') + '"><div class="lbl">' + esc(lbl) + '</div><div class="val">' + val + '</div>' +
      (sub ? '<div class="sub">' + sub + '</div>' : '') + '</div>';
  }
  function statsHtml(f) {
    if (!f) return '<div class="sa-empty">No figures yet.</div>';
    var pending = (f.wd_pending_count || 0) + (f.wd_approved_count || 0);
    return '<div class="sa-stats">' +
      stat('accent', 'Available coins', coins(f.coin_balance), 'in inventory, ready to sell') +
      stat('', 'Total coins allocated', coins(f.alloc_credited_coins), f.alloc_awaiting_count ? '<b>' + coins(f.alloc_awaiting_coins) + '</b> more awaiting payment' : 'credited from the Admin') +
      stat('', 'Coins distributed', coins(f.dep_confirmed_coins), '<b>' + esc(f.dep_confirmed_count) + '</b> confirmed user deposits') +
      stat('', 'Assigned users', esc(f.users), '') +
      stat('good', 'User deposits received', inr(f.cash_from_users_confirmed), 'awaiting confirmation <b>' + inr(f.cash_from_users_unconfirmed) + '</b>' + (f.dep_requested_count ? ' · <b>' + esc(f.dep_requested_count) + '</b> requested' : '')) +
      stat('', 'Withdrawals paid', inr(f.payout_confirmed_inr), '<b>' + esc(f.wd_paid_count) + '</b> completed payouts') +
      stat(pending ? 'warn' : '', 'Pending withdrawals', esc(pending), 'still to pay <b>' + inr(f.pending_payouts_inr) + '</b>') +
      stat('', 'Paid to Admin', inr(f.paid_admin_confirmed), 'awaiting confirmation <b>' + inr(f.paid_admin_unconfirmed) + '</b> · still owed <b>' + inr(f.owed_admin) + '</b>') +
      stat(num(f.estimated_net_position) >= 0 ? 'good' : 'bad', 'Estimated financial position', inr(f.estimated_net_position), 'an estimate — not final profit') +
      '</div>';
  }
  function positionHtml(f) {
    if (!f) return '';
    var fin = f.finalized_profit_loss === null || f.finalized_profit_loss === undefined
      ? '<span class="sa-warn">Not final</span> <span class="sa-dimtxt">— ' + esc(f.open_items) + ' open item(s) still to settle</span>'
      : '<b class="sa-num">' + inr(f.finalized_profit_loss) + '</b> <span class="sa-badge ok">final</span>';
    return '<div class="sa-card"><div class="sa-section-title" style="margin-top:0;">Financial position (from confirmed records only)</div>' +
      '<dl class="sa-kv">' +
      '<dt>Gross cash surplus</dt><dd class="sa-num">' + inr(f.gross_cash_surplus) + ' <span class="sa-dimtxt">= cash from users ' + inr(f.cash_from_users_confirmed) + ' − payouts ' + inr(f.payout_confirmed_inr) + '</span></dd>' +
      '<dt>Cash balance</dt><dd class="sa-num">' + inr(f.cash_balance) + ' <span class="sa-dimtxt">= surplus − paid to Admin ' + inr(f.paid_admin_confirmed) + '</span></dd>' +
      '<dt>Outstanding liabilities</dt><dd class="sa-num">' + inr(f.outstanding_liabilities) + ' <span class="sa-dimtxt">= owed to Admin ' + inr(f.owed_admin) + ' + unpaid withdrawals ' + inr(f.pending_payouts_inr) + '</span></dd>' +
      '<dt>Estimated net position</dt><dd class="sa-num">' + inr(f.estimated_net_position) + ' <span class="sa-badge warn">estimate</span></dd>' +
      '<dt>Finalized profit / loss</dt><dd>' + fin + '</dd>' +
      '<dt>Inventory check</dt><dd>' + (f.reconciled
        ? '<span class="sa-good">✓ ' + coins(f.coin_balance) + ' coins — matches the coin ledger</span>'
        : '<span class="sa-bad">✗ stored ' + coins(f.coin_balance) + ' vs ledger ' + coins(f.ledger_inventory) + ' — report this to the Admin</span>') + '</dd>' +
      '</dl><p class="sa-note" style="margin:12px 0 0;">Unconfirmed amounts are never added in. Coins still in the inventory are not counted as income — what was paid for them is already in “paid to Admin”. The Admin\'s coin supply is unlimited and is never a cost.</p></div>';
  }
  function activityHtml(a) {
    if (!a) return '';
    var rows = (a.per_game || []).map(function (g) {
      return '<tr><td>' + esc(g.label) + '</td><td class="num sa-num">' + esc(g.bets) + '</td><td class="num sa-num">' + coins(g.wagered) + '</td><td class="num sa-num">' + coins(g.won) + '</td>' +
        '<td class="num sa-num">' + coins(num(g.wagered) - num(g.won)) + '</td></tr>';
    }).join('');
    return '<div class="sa-card"><div class="sa-section-title" style="margin-top:0;">Users\' game activity since assignment</div>' +
      '<p class="sa-note" style="margin:0 0 10px;">Wins and losses happen between the users and the platform. They change the users\' balances only — never the sub-admin\'s coin inventory.</p>' +
      '<div class="sa-stats" style="margin-bottom:10px;">' + stat('', 'Coins staked', coins(a.wagered), esc(a.bets) + ' bets') + stat('', 'Coins won', coins(a.won), '') +
      stat(num(a.house_result) >= 0 ? 'good' : 'bad', 'Platform result', coins(a.house_result), 'staked − won') + '</div>' +
      table('<th>Game</th><th class="num">Bets</th><th class="num">Staked</th><th class="num">Won</th><th class="num">Platform result</th>', rows, 5, 'No game play yet.') + '</div>';
  }

  // ------------------------------------------------------------------------------------------ report
  function reportHtml(r) {
    if (!r) return '';
    var P = r.period || {}, F = r.position_now || {};
    var fin = P.finalized_profit_loss === null || P.finalized_profit_loss === undefined
      ? '<span class="sa-warn">Not final</span> <span class="sa-dimtxt">— ' + esc(P.open_items_in_period) + ' item(s) from this period are still open' + (num(F.owed_admin) > 0 ? ', and money is still owed to the Admin' : '') + '</span>'
      : '<b class="sa-num">' + inr(P.finalized_profit_loss) + '</b> <span class="sa-badge ok">final</span>';
    var coinRows = Object.keys(P.coins || {}).map(function (t) {
      var c = P.coins[t];
      return '<tr><td>' + esc(typeLabel('coin', t)) + '</td><td class="num sa-num">' + esc(c.count) + '</td><td class="num sa-num">' + coins(c.coins) + '</td></tr>';
    }).join('');
    return '<div class="sa-grid-2">' +
      '<div class="sa-card"><div class="sa-section-title" style="margin-top:0;">Cash in this period <span class="sa-dimtxt" style="font-weight:500;">(' + esc(r.from || 'start') + ' → ' + esc(r.to || 'today') + ', confirmed only)</span></div>' +
        '<dl class="sa-kv">' +
        '<dt>Cash received from users</dt><dd class="sa-num sa-good">' + inr(P.cash_from_users) + '</dd>' +
        '<dt>Cash paid out to users</dt><dd class="sa-num sa-bad">' + inr(P.cash_paid_to_users) + '</dd>' +
        '<dt>Gross cash surplus</dt><dd class="sa-num">' + inr(P.gross_cash_surplus) + '</dd>' +
        '<dt>Cash paid to the Admin</dt><dd class="sa-num">' + inr(P.cash_paid_to_admin) + '</dd>' +
        '<dt>Cash result</dt><dd class="sa-num"><b>' + inr(P.cash_result) + '</b></dd>' +
        '<dt>Finalized profit / loss</dt><dd>' + fin + '</dd>' +
        '</dl></div>' +
      '<div class="sa-card"><div class="sa-section-title" style="margin-top:0;">Position right now</div>' +
        '<dl class="sa-kv">' +
        '<dt>Cash balance</dt><dd class="sa-num">' + inr(F.cash_balance) + '</dd>' +
        '<dt>Owed to the Admin</dt><dd class="sa-num">' + inr(F.owed_admin) + '</dd>' +
        '<dt>Unpaid withdrawals</dt><dd class="sa-num">' + inr(F.pending_payouts_inr) + '</dd>' +
        '<dt>Outstanding liabilities</dt><dd class="sa-num">' + inr(F.outstanding_liabilities) + '</dd>' +
        '<dt>Estimated net position</dt><dd class="sa-num">' + inr(F.estimated_net_position) + ' <span class="sa-badge warn">estimate</span></dd>' +
        '<dt>Awaiting confirmation</dt><dd class="sa-num">from users ' + inr(F.cash_from_users_unconfirmed) + ' · to Admin ' + inr(F.paid_admin_unconfirmed) + '</dd>' +
        '<dt>Coins in inventory</dt><dd class="sa-num">' + coins(F.coin_balance) + '</dd>' +
        '</dl></div></div>' +
      '<div class="sa-section-title">Coin movements in this period</div>' +
      table('<th>Movement</th><th class="num">Count</th><th class="num">Coins</th>', coinRows, 3, 'No coin movements in this period.') +
      '<div class="sa-card" style="margin-top:14px;"><div class="sa-section-title" style="margin-top:0;">How these figures are calculated</div><ul class="sa-rules">' +
      (r.rules || []).map(function (x) { return '<li>' + esc(x) + '</li>'; }).join('') + '</ul></div>';
  }

  // ------------------------------------------------------------------------------------------ record tables
  function paymentLine(p, opts) {
    var acts = '';
    if (opts.admin && p.kind === 'allocation_payment') {
      if (p.status === 'Unconfirmed') acts = btn('pay-confirm', p.id, 'Confirm received', 'good') + btn('pay-fail', p.id, 'Mark failed', 'bad');
      else if (p.status === 'Confirmed') acts = btn('pay-reverse', p.id, 'Reverse', 'bad');
    }
    return '<tr class="sub"><td><code>' + esc(p.id) + '</code></td><td colspan="2">' + esc(typeLabel('cash', p.kind)) + (p.reverses ? ' of <code>' + esc(p.reverses) + '</code>' : '') +
      '<div class="sa-who">' + esc(p.method || '—') + (p.reference ? ' · ref <code>' + esc(p.reference) + '</code>' : '') + (p.notes ? ' · ' + esc(p.notes) : '') + '</div></td>' +
      '<td class="num sa-num">' + inr(p.amount) + '</td><td>' + badge(p.status) + '</td>' +
      '<td colspan="2"><div class="sa-who">recorded by ' + by(p.created_by, p.created_by_role, p.created_at) + '</div>' +
      (p.confirmed_by ? '<div class="sa-who">confirmed by ' + by(p.confirmed_by, p.confirmed_by_role, p.confirmed_at) + '</div>' : '') +
      (p.failed_by ? '<div class="sa-who">failed: ' + esc(p.fail_reason || '') + ' — ' + by(p.failed_by, '', p.failed_at) + '</div>' : '') +
      (p.reversed_by ? '<div class="sa-who">reversed: ' + esc(p.reverse_reason || '') + ' — ' + by(p.reversed_by, '', p.reversed_at) + '</div>' : '') +
      '</td><td><div class="sa-btn-row">' + acts + '</div></td></tr>';
  }
  function allocationsHtml(list, opts) {
    opts = opts || {};
    var rows = (list || []).map(function (a) {
      var open = a.status === 'awaiting_payment' || a.status === 'credited';
      var acts = '';
      if (open && a.remaining_to_record > 0) acts += btn('alloc-pay', a.id, opts.admin ? 'Record payment' : 'I have paid', '');
      if (opts.admin && a.status === 'awaiting_payment') acts += btn('alloc-cancel', a.id, 'Cancel', 'bad');
      if (opts.admin && a.status === 'credited') acts += btn('alloc-reverse', a.id, 'Reverse', 'bad');
      var main = '<tr><td><code>' + esc(a.id) + '</code><div class="sa-who">' + esc(when(a.created_at)) + '</div></td>' +
        '<td class="num sa-num">' + coins(a.coins) + '</td><td class="num sa-num">' + inr(a.agreed_amount) + '</td>' +
        '<td class="num sa-num">' + inr(a.paid_confirmed) + (a.paid_unconfirmed > 0 ? '<div class="sa-who">+ ' + inr(a.paid_unconfirmed) + ' unconfirmed</div>' : '') + '</td>' +
        '<td>' + badge(a.status) + '<div class="sa-who">' + (a.credit_mode === 'immediate' ? 'credit at once' : 'credit after payment') + '</div></td>' +
        '<td>' + badge(a.payment_status) + (a.outstanding > 0 && a.status === 'credited' ? '<div class="sa-who sa-warn">owed ' + inr(a.outstanding) + '</div>' : '') + '</td>' +
        '<td><div class="sa-who">by ' + by(a.created_by, a.created_by_role, null) + '</div>' +
        (a.credited_at ? '<div class="sa-who">credited ' + esc(when(a.credited_at)) + '</div>' : '') +
        (a.cancelled_at ? '<div class="sa-who">cancelled: ' + esc(a.cancel_reason || '') + ' — ' + by(a.cancelled_by, '', a.cancelled_at) + '</div>' : '') +
        (a.reversed_at ? '<div class="sa-who">reversed: ' + esc(a.reverse_reason || '') + ' — ' + by(a.reversed_by, '', a.reversed_at) + '</div>' : '') +
        (a.notes ? '<div class="sa-who">' + esc(a.notes) + '</div>' : '') + '</td>' +
        '<td><div class="sa-btn-row">' + acts + '</div></td></tr>';
      return main + (a.payments || []).map(function (p) { return paymentLine(p, opts); }).join('');
    }).join('');
    return table('<th>Allocation</th><th class="num">Coins</th><th class="num">Agreed</th><th class="num">Paid</th><th>Status</th><th>Payment</th><th>Who / notes</th><th></th>',
      rows, 8, opts.emptyText || 'No allocations yet.');
  }
  function depositsHtml(list, opts) {
    opts = opts || {};
    var rows = (list || []).map(function (d) {
      var acts = '';
      if (opts.subadmin) {
        if (d.status === 'Requested') acts = btn('dep-payment', d.id, 'Record payment') + btn('dep-confirm', d.id, 'Confirm & credit', 'good') + btn('dep-cancel', d.id, 'Cancel', 'bad');
        if (d.status === 'Unconfirmed') acts = btn('dep-confirm', d.id, 'Confirm & credit', 'good') + btn('dep-cancel', d.id, 'Cancel', 'bad');
      }
      if (opts.admin && d.status === 'Confirmed') acts = btn('dep-reverse', d.id, 'Reverse', 'bad');
      return '<tr><td><code>' + esc(d.id) + '</code><div class="sa-who">' + esc(when(d.created_at)) + '</div></td>' +
        '<td><b>' + esc(d.username) + '</b></td><td class="num sa-num">' + coins(d.coins) + '</td><td class="num sa-num">' + inr(d.amount_inr) + '</td>' +
        '<td>' + esc(d.method || '—') + (d.reference ? '<div class="sa-who">ref <code>' + esc(d.reference) + '</code></div>' : '') + '</td>' +
        '<td>' + badge(d.status) + '</td>' +
        '<td><div class="sa-who">recorded by ' + by(d.created_by, '', null) + '</div>' +
        (d.confirmed_at ? '<div class="sa-who">confirmed by ' + by(d.confirmed_by, '', d.confirmed_at) + '</div>' : '') +
        (d.cancelled_at ? '<div class="sa-who">cancelled: ' + esc(d.cancel_reason || '') + ' — ' + by(d.cancelled_by, '', d.cancelled_at) + '</div>' : '') +
        (d.reversed_at ? '<div class="sa-who">reversed: ' + esc(d.reverse_reason || '') + ' — ' + by(d.reversed_by, '', d.reversed_at) + '</div>' : '') +
        (d.notes ? '<div class="sa-who">' + esc(d.notes) + '</div>' : '') + '</td>' +
        '<td><div class="sa-btn-row">' + acts + '</div></td></tr>';
    }).join('');
    return table('<th>Deposit</th><th>User</th><th class="num">Coins</th><th class="num">Rupees</th><th>Method</th><th>Status</th><th>Who</th><th></th>',
      rows, 8, opts.emptyText || 'No deposits recorded yet.');
  }
  function withdrawalsHtml(list, opts) {
    opts = opts || {};
    var rows = (list || []).map(function (w) {
      var acts = '';
      if (opts.subadmin) {
        if (w.status === 'Pending') acts = btn('wd-approve', w.withdrawal_id, 'Approve') + btn('wd-paid', w.withdrawal_id, 'Mark paid', 'good') + btn('wd-reject', w.withdrawal_id, 'Reject', 'bad');
        if (w.status === 'Approved') acts = btn('wd-paid', w.withdrawal_id, 'Mark paid', 'good') + btn('wd-reject', w.withdrawal_id, 'Reject', 'bad');
      }
      if (opts.admin && w.status === 'Paid') acts = btn('wd-reverse', w.withdrawal_id, 'Reverse payout', 'bad');
      return '<tr><td><code>' + esc(w.withdrawal_id) + '</code><div class="sa-who">' + esc(when(w.created_at)) + '</div></td>' +
        '<td><b>' + esc(w.username) + '</b></td><td class="num sa-num">' + coins(w.coins) + '</td><td class="num sa-num">' + inr(w.rupee_equivalent) + '</td>' +
        '<td style="max-width:240px;">' + esc(w.destination || '—') + '</td><td>' + badge(w.status) + '</td>' +
        '<td>' + (w.approved_at ? '<div class="sa-who">approved by ' + by(w.approved_by, '', w.approved_at) + '</div>' : '') +
        (w.paid_at ? '<div class="sa-who">paid <b class="sa-num">' + inr(w.paid_amount) + '</b> via ' + esc(w.payout_method || '') + (w.payout_reference ? ' (ref <code>' + esc(w.payout_reference) + '</code>)' : '') + ' — ' + by(w.paid_by, '', w.paid_at) + '</div>' : '') +
        (w.coins_to ? '<div class="sa-who">coins ' + (w.coins_to === 'subadmin' ? 'returned to the sub-admin\'s inventory' : 'retired to the Admin\'s supply') + '</div>' : '') +
        (w.rejected_at ? '<div class="sa-who">rejected: ' + esc(w.reject_reason || '') + ' — ' + by(w.rejected_by, '', w.rejected_at) + '</div>' : '') +
        (w.reversed_at ? '<div class="sa-who">reversed: ' + esc(w.reverse_reason || '') + ' — ' + by(w.reversed_by, '', w.reversed_at) + '</div>' : '') +
        (w.returned_at ? '<div class="sa-who">handed back to the Admin\'s queue — ' + by(w.returned_by, '', w.returned_at) + '</div>' : '') + '</td>' +
        '<td><div class="sa-btn-row">' + acts + '</div></td></tr>';
    }).join('');
    return table('<th>Request</th><th>User</th><th class="num">Coins</th><th class="num">Rupee value</th><th>Pay to</th><th>Status</th><th>Processing</th><th></th>',
      rows, 8, opts.emptyText || 'No withdrawal requests.');
  }
  function historyHtml(rows, opts) {
    opts = opts || {};
    var html = (rows || []).map(function (r) {
      var amt, who;
      if (r.book === 'coin') {
        var s = coinSign(r);
        amt = '<span class="sa-num ' + (s > 0 ? 'sa-good' : s < 0 ? 'sa-bad' : 'sa-dimtxt') + '">' + (s > 0 ? '+' : s < 0 ? '−' : '') + coins(r.coins) + ' coins</span>' +
          (r.rupees !== null && r.rupees !== undefined ? '<div class="sa-who">' + inr(r.rupees) + '</div>' : '') +
          (r.inventory_after !== null && r.inventory_after !== undefined && s !== 0 ? '<div class="sa-who">inventory after ' + coins(r.inventory_after) + '</div>' : '');
        who = by(r.actor, r.actor_role, null);
      } else {
        amt = '<span class="sa-num">' + inr(r.amount) + '</span><div class="sa-who">' + esc(r.method || '') + (r.reference ? ' · ref <code>' + esc(r.reference) + '</code>' : '') + '</div>';
        who = 'by ' + by(r.created_by, r.created_by_role, null) + (r.confirmed_by ? '<div class="sa-who">confirmed by ' + by(r.confirmed_by, r.confirmed_by_role, r.confirmed_at) + '</div>' : '');
      }
      var ref = r.ref || r.allocation_id || r.deposit_id || r.withdrawal_id || '';
      return '<tr><td><div class="sa-num" style="font-size:12px;">' + esc(when(r.created_at)) + '</div><code>' + esc(r.id) + '</code></td>' +
        (opts.showSubadmin ? '<td>' + esc(r.subadmin || '') + '</td>' : '') +
        '<td>' + esc(typeLabel(r.book, r.type || r.kind)) + '<div class="sa-who">' + (r.book === 'coin' ? esc(account(r.from_account)) + ' → ' + esc(account(r.to_account)) : (r.book === 'cash' ? 'cash record' : '')) + '</div></td>' +
        '<td>' + amt + '</td><td>' + esc(r.username || '—') + '</td><td>' + badge(r.status) + '</td>' +
        '<td>' + (ref ? '<code>' + esc(ref) + '</code>' : '—') + (r.reverses ? '<div class="sa-who">reverses <code>' + esc(r.reverses) + '</code></div>' : '') + '</td>' +
        '<td><div class="sa-who" style="margin:0;">' + who + '</div>' + (r.notes ? '<div class="sa-who">' + esc(r.notes) + '</div>' : '') + '</td></tr>';
    }).join('');
    return table('<th>When / ID</th>' + (opts.showSubadmin ? '<th>Sub-admin</th>' : '') + '<th>Type</th><th>Amount</th><th>User</th><th>Status</th><th>Linked to</th><th>Who</th>',
      html, opts.showSubadmin ? 8 : 7, 'No records match these filters.');
  }
  function usersHtml(list, opts) {
    opts = opts || {};
    var rows = (list || []).map(function (u) {
      var acts = btn('user-view', u.id, 'View');
      if (opts.subadmin) acts = btn('user-deposit', u.id, 'Record deposit', 'good') + acts;
      if (opts.admin) acts += btn('user-unassign', u.id, 'Remove', 'bad', ' data-name="' + esc(u.username) + '"');
      return '<tr data-user-row="' + esc(String(u.username).toLowerCase() + ' ' + String(u.email || '').toLowerCase()) + '"><td><b>' + esc(u.username) + '</b><div class="sa-who">' + esc(u.email || '') + '</div></td>' +
        '<td class="num sa-num">' + coins(u.wallet_balance) + '</td><td class="num sa-num">' + coins(u.coins_bought) + '<div class="sa-who">' + esc(u.deposits) + ' deposits</div></td>' +
        '<td class="num sa-num">' + coins(u.coins_withdrawn) + (u.open_withdrawals ? '<div class="sa-who sa-warn">' + esc(u.open_withdrawals) + ' open</div>' : '') + '</td>' +
        '<td class="num sa-num">' + coins(u.wagered) + ' / ' + coins(u.won) + '<div class="sa-who">' + esc(u.bets) + ' bets</div></td>' +
        '<td><div class="sa-who" style="margin:0;">joined ' + esc(when(u.joined_at)) + '</div><div class="sa-who">' +
        (u.how === 'created_by_subadmin' ? 'created by the sub-admin' : u.how === 'moved_by_admin' ? 'moved here by ' + esc(u.assigned_by) : 'assigned by ' + esc(u.assigned_by)) + ' · ' + esc(when(u.assigned_at)) + '</div></td>' +
        '<td><div class="sa-btn-row">' + acts + '</div></td></tr>';
    }).join('');
    return table('<th>User</th><th class="num">Balance</th><th class="num">Bought</th><th class="num">Withdrawn</th><th class="num">Staked / won</th><th>Since</th><th></th>',
      rows, 7, opts.emptyText || 'No users yet.');
  }
  function userDetailHtml(d, opts) {
    var u = d.user || {};
    var tx = (d.transactions || []).map(function (t) {
      return '<tr><td class="sa-num" style="font-size:12px;">' + esc(when(t.timestamp)) + '</td><td>' + esc(t.type) + '</td><td class="num sa-num">' + coins(t.amount) + '</td>' +
        '<td>' + esc(t.details) + '</td><td>' + badge(t.status) + '</td></tr>';
    }).join('');
    return '<dl class="sa-kv" style="margin-bottom:14px;"><dt>Username</dt><dd><b>' + esc(u.username) + '</b></dd><dt>Email</dt><dd>' + esc(u.email || '—') + '</dd>' +
      '<dt>Balance</dt><dd class="sa-num"><b>' + coins(u.wallet_balance) + '</b> coins</dd><dt>Joined</dt><dd>' + esc(when(u.joined_at)) + '</dd>' +
      '<dt>With this sub-admin since</dt><dd>' + esc(when(u.assigned_at)) + '</dd></dl>' +
      '<div class="sa-section-title">Deposits through this sub-admin</div>' + depositsHtml(d.deposits, { emptyText: 'None.' }) +
      '<div class="sa-section-title">Withdrawal requests</div>' + withdrawalsHtml(d.withdrawals, { emptyText: 'None.' }) +
      '<div class="sa-section-title">Account history (every balance change on the platform)</div>' +
      table('<th>When</th><th>Type</th><th class="num">Coins</th><th>Details</th><th>Status</th>', tx, 5, 'No transactions yet.');
  }
  function eventsHtml(list, opts) {
    opts = opts || {};
    var rows = (list || []).map(function (e) {
      var det = e.details || '';
      try { var o = JSON.parse(det); if (o && typeof o === 'object') det = Object.keys(o).map(function (k) { return k.replace(/_/g, ' ') + ': ' + o[k]; }).join(' · '); } catch (x) {}
      return '<tr><td class="sa-num" style="font-size:12px;">' + esc(when(e.created_at)) + '</td>' + (opts.showSubadmin ? '<td>' + esc(e.subadmin) + '</td>' : '') +
        '<td>' + esc(EVENTS[e.event] || e.event) + '</td><td>' + esc(e.username || '') + '</td><td>' + by(e.actor, e.actor_role, null) + '</td><td>' + esc(det) + '</td></tr>';
    }).join('');
    return table('<th>When</th>' + (opts.showSubadmin ? '<th>Sub-admin</th>' : '') + '<th>Event</th><th>User</th><th>By</th><th>Details</th>', rows, opts.showSubadmin ? 6 : 5, 'No account activity yet.');
  }
  /** Filter bar for the history view. Values are read back by name. */
  function historyFiltersHtml(extra) {
    var coinOpts = Object.keys(COIN_TYPES).map(function (k) { return '<option value="' + k + '">' + esc(COIN_TYPES[k]) + '</option>'; }).join('');
    var cashOpts = Object.keys(CASH_KINDS).map(function (k) { return '<option value="' + k + '">' + esc(CASH_KINDS[k]) + '</option>'; }).join('');
    return '<form class="sa-filters" data-k="hist-filters">' + (extra || '') +
      '<div class="sa-field"><label class="sa-lbl">Book</label><select name="book"><option value="all">Coins and cash</option><option value="coin">Coin movements</option><option value="cash">Cash records</option></select></div>' +
      '<div class="sa-field"><label class="sa-lbl">Type</label><select name="type"><option value="">Any type</option><optgroup label="Coins">' + coinOpts + '</optgroup><optgroup label="Cash">' + cashOpts + '</optgroup></select></div>' +
      '<div class="sa-field"><label class="sa-lbl">Status</label><select name="status"><option value="">Any status</option><option>Completed</option><option>Unconfirmed</option><option>Confirmed</option><option>Failed</option><option>Reversed</option></select></div>' +
      '<div class="sa-field"><label class="sa-lbl">From</label><input type="date" name="from"></div>' +
      '<div class="sa-field"><label class="sa-lbl">To</label><input type="date" name="to"></div>' +
      '<div class="sa-field grow"><label class="sa-lbl">User</label><input type="search" name="user" placeholder="username"></div>' +
      '<button type="submit" class="sa-btn primary">Apply</button></form>';
  }
  function formQuery(form) {
    var p = new URLSearchParams();
    Array.prototype.forEach.call(form.elements, function (el) { if (el.name && el.value !== '') p.set(el.name, el.value); });
    return p.toString();
  }

  global.SAKit = {
    esc: esc, num: num, coins: coins, inr: inr, when: when, istDate: istDate, newKey: newKey, badge: badge, typeLabel: typeLabel,
    toast: toast, openModal: openModal, closeModal: closeModal, formModal: formModal, reasonModal: reasonModal, setTheme: setTheme,
    field: field, input: input, methodSelect: methodSelect, table: table, stat: stat,
    statsHtml: statsHtml, positionHtml: positionHtml, activityHtml: activityHtml, reportHtml: reportHtml,
    allocationsHtml: allocationsHtml, depositsHtml: depositsHtml, withdrawalsHtml: withdrawalsHtml, historyHtml: historyHtml,
    usersHtml: usersHtml, userDetailHtml: userDetailHtml, eventsHtml: eventsHtml, historyFiltersHtml: historyFiltersHtml, formQuery: formQuery,
  };
})(window);
