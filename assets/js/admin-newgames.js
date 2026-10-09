/**
 * Admin console: operator settings for Chicken Road and Astronaut.
 *
 * Kept in its own file so admin.html carries only a tab button, an empty panel and this include.
 * Both games are priced purely by their RTP setting (Chicken Road's ladders and Astronaut's crash
 * distribution are derived from it on the server); this panel edits that and the table limits.
 * Talks to GET/POST /api/admin/chickenroad/config and /api/admin/astronaut/config; the shared fetch
 * interceptor attaches the operator token because admin.html sets BET1X_ADMIN_CONSOLE.
 */
(function () {
  'use strict';

  var GAMES = [
    {
      key: 'chickenroad', title: 'Chicken Road', page: 'chickenroad.html',
      fields: [
        { k: 'rtp', label: 'Return to player (%)', pct: true, step: '0.1', hint: '90–99. 98% matches the original game.' },
        { k: 'min_bet', label: 'Minimum bet (₹)', step: '1' },
        { k: 'max_bet', label: 'Maximum bet (₹)', step: '1' },
        { k: 'max_win', label: 'Maximum win per round (₹)', step: '1', hint: 'A road reaching this is cashed out automatically.' },
      ],
    },
    {
      key: 'astronaut', title: 'Astronaut', page: 'astronaut.html',
      fields: [
        { k: 'rtp', label: 'Return to player (%)', pct: true, step: '0.1', hint: '90–99. Applies from the next round.' },
        { k: 'min_bet', label: 'Minimum bet (₹)', step: '1' },
        { k: 'max_bet', label: 'Maximum bet (₹)', step: '1' },
        { k: 'max_multiplier', label: 'Highest possible crash (x)', step: '1' },
        { k: 'max_win', label: 'Maximum win per bet (₹)', step: '1', hint: 'A bet reaching this is cashed out automatically.' },
      ],
    },
  ];

  function esc(s) { return String(s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }

  function cardHtml(g) {
    var rows = g.fields.map(function (f) {
      return '<label style="display:block; margin-bottom:10px; font-size:12px; color:var(--text-dim);">' + esc(f.label) +
        '<input type="number" step="' + f.step + '" data-k="' + f.k + '" class="cashier-input" style="margin-top:4px; background:#0d0f12; color:#fff;">' +
        (f.hint ? '<span style="display:block; font-size:11px; margin-top:3px;">' + esc(f.hint) + '</span>' : '') + '</label>';
    }).join('');
    return '<div class="card" data-game="' + g.key + '" style="padding:16px; border:1px solid var(--border); border-radius:12px; background:var(--surface);">' +
      '<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">' +
        '<h4 style="margin:0; font-size:15px;">' + esc(g.title) + ' <a href="' + g.page + '" target="_blank" rel="noopener" style="font-size:11px; color:var(--gold); margin-left:6px;">open ↗</a></h4>' +
        '<label style="display:flex; gap:6px; align-items:center; font-size:12px; cursor:pointer;"><input type="checkbox" data-k="enabled"> Open for play</label>' +
      '</div>' + rows +
      '<button type="button" class="btn btn-gold btn-block" data-save style="color:#000; font-weight:700;">Save ' + esc(g.title) + ' settings</button>' +
      '<div data-msg style="font-size:12px; margin-top:8px; min-height:16px;"></div>' +
    '</div>';
  }

  function setMsg(card, text, ok) {
    var el = card.querySelector('[data-msg]');
    el.textContent = text;
    el.style.color = ok ? 'var(--green)' : 'var(--red)';
  }

  function fill(card, g, cfg) {
    card.querySelector('[data-k="enabled"]').checked = !!cfg.enabled;
    g.fields.forEach(function (f) {
      var v = cfg[f.k];
      card.querySelector('[data-k="' + f.k + '"]').value = f.pct ? (Math.round(v * 1000) / 10) : v;
    });
  }

  function load(card, g) {
    fetch('api/admin/' + g.key + '/config').then(function (r) { return r.json(); }).then(function (d) {
      if (d && d.ok) { fill(card, g, d.config); setMsg(card, '', true); }
      else setMsg(card, (d && d.error) || 'Could not load settings.', false);
    }).catch(function () { setMsg(card, 'Cannot reach the server.', false); });
  }

  function save(card, g) {
    var body = { enabled: card.querySelector('[data-k="enabled"]').checked };
    g.fields.forEach(function (f) { body[f.k] = parseFloat(card.querySelector('[data-k="' + f.k + '"]').value); });
    fetch('api/admin/' + g.key + '/config', {
      method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body),
    }).then(function (r) { return r.json(); }).then(function (d) {
      if (d && d.ok) { fill(card, g, d.config); setMsg(card, 'Saved.', true); }
      else setMsg(card, (d && d.error) || 'Save failed.', false);
    }).catch(function () { setMsg(card, 'Cannot reach the server.', false); });
  }

  var rendered = false;
  function render() {
    var panel = document.getElementById('admin-newgames');
    if (!panel) return;
    if (!rendered) {
      panel.innerHTML =
        '<h3 style="margin-bottom:8px; font-size:16px;">Chicken Road &amp; Astronaut</h3>' +
        '<p style="font-size:12.5px; color:var(--text-dim); margin-bottom:16px;">Both games are priced entirely by their return-to-player ' +
        'setting, and every round is provably fair from a server seed revealed to the player afterwards. House profit for each ' +
        'appears in the platform stats alongside the other games.</p>' +
        '<div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(300px, 1fr)); gap:16px;">' +
        GAMES.map(cardHtml).join('') + '</div>';
      GAMES.forEach(function (g) {
        var card = panel.querySelector('[data-game="' + g.key + '"]');
        card.querySelector('[data-save]').addEventListener('click', function () { save(card, g); });
      });
      rendered = true;
    }
    GAMES.forEach(function (g) { load(panel.querySelector('[data-game="' + g.key + '"]'), g); });
  }

  // Load whenever the tab is opened (and once at start if it was the remembered tab).
  document.addEventListener('click', function (e) {
    var btn = e.target.closest && e.target.closest('.admin-tab-btn');
    if (btn && /newgames/.test(btn.getAttribute('onclick') || '')) render();
  });
  document.addEventListener('DOMContentLoaded', function () {
    if (localStorage.getItem('admin_active_tab') === 'newgames') render();
  });
})();
