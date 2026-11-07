/**
 * bet1x Originals — the shared page framework for Dice, Coinflip, Wheel, Plinko, Keno, Roulette,
 * Baccarat, Andar Bahar, Slots, Blackjack and Matka.
 *
 * A game page includes this after ui-common.js and calls Orig.mount({...}) with its own controls,
 * stage, how-to text, and an onResult(round) animation. The framework does everything shared:
 *   - loads the game's settings, the player's open round, seeds and recent rounds
 *   - the bet amount (or the chip rack for table games), the Play button, messages
 *   - places the bet with a fresh client_ref per click (the server turns a repeat into a replay,
 *     so a double-click can never bet twice), updates the header balance, win/lose banner + sound
 *   - multi-step actions (Orig.action) carrying the round's step, so a double-click is refused
 *   - the history table and the provably-fair card: server seed hash, editable client seed, nonce,
 *     rotate & reveal, and a per-round "Verify" that recomputes the round's random numbers in the
 *     browser (WebCrypto HMAC-SHA256) from the revealed seed.
 * Every result comes from the server; nothing here decides an outcome.
 */
(function () {
  'use strict';

  const $ = (id) => document.getElementById(id);
  const esc = (v) => String(v == null ? '' : v).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const money = (n) => '₹' + Number(n || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  const fmtMult = (m) => { const v = Math.floor(Number(m || 0) * 100 + 1e-6) / 100; return (v >= 100 && v === Math.floor(v) ? String(v) : v.toFixed(2)) + 'x'; };   // never shown higher than it is
  const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
  const sound = (name) => { try { if (window.SoundFX) window.SoundFX.play(name); } catch (e) {} };
  const newRef = () => {
    const b = new Uint8Array(10);
    (window.crypto || window.msCrypto).getRandomValues(b);
    return 'c' + Array.from(b, (x) => x.toString(16).padStart(2, '0')).join('');
  };

  const S = { game: null, opt: null, config: null, info: null, signedIn: false, balance: null, busy: false,
              token: null, balanceRevision: 0, open: null, step: 0, recent: [], seed: null, chip: 10, total: 0, session: { staked: 0, won: 0 } };
  const sessionToken = () => localStorage.getItem('bet1x_auth_token');
  let clock = null, clockReceived = 0, clockServerNow = 0, clockPolling = false;

  function bettingOpen() {
    if (!clock || performance.now() - clockReceived > 7000) return false;
    const now = clockServerNow + performance.now() - clockReceived;
    // Never locally open a new round: wait for confirmation from the server.
    return clock.phase === 'betting' && now < clock.betting_closes_at;
  }

  function renderClock() {
    const el = $('ogClock'), btn = $('ogPlay');
    const fresh = clock && performance.now() - clockReceived <= 7000;
    const now = clockServerNow + performance.now() - clockReceived;
    const open = bettingOpen();
    if (el) {
      el.textContent = !fresh ? 'Synchronizing betting timer...' :
        'Round ' + clock.round_id + ' · ' + (open ? 'Betting closes in ' : 'Betting closed · next window in ') +
        Math.max(0, Math.ceil(((open ? clock.betting_closes_at : clock.next_round_at) - now) / 1000)) + 's';
      el.style.color = open ? 'var(--accent, #39d98a)' : 'var(--text-dim)';
    }
    if (btn) btn.disabled = S.busy || !!S.open || !open || !!(S.config && !S.config.enabled);
  }

  async function syncClock() {
    if (clockPolling) return;
    clockPolling = true;
    const started = performance.now();
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 5000);
    try {
      const res = await fetch('api/originals/clock', { cache: 'no-store', signal: controller.signal });
      const d = await res.json();
      if (res.ok && d.ok && d.clock && Number.isFinite(d.clock.server_time)) {
        clock = d.clock;
        clockReceived = performance.now();
        // Include the full request latency so a slow connection closes conservatively.
        clockServerNow = clock.server_time + clockReceived - started;
      }
    } catch (e) { /* Expired clock data disables betting until synchronization recovers. */ }
    finally { clearTimeout(timeout); clockPolling = false; renderClock(); }
  }

  async function api(path, body) {
    const token = sessionToken();
    const opts = body === undefined ? {} : { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) };
    let res, data = null;
    try { res = await fetch('api/originals/' + path, opts); data = await res.json(); } catch (e) {}
    if (token !== sessionToken()) return { ok: false, _status: 409, code: 'session_changed', error: 'Your account changed. Refreshing the game.' };
    if (!data) data = { ok: false, error: 'Could not reach the server. Check your connection.' };
    data._status = res ? res.status : 0;
    return data;
  }

  function msg(text, err) { const m = $('ogMsg'); if (m) { m.textContent = text || ''; m.classList.toggle('err', !!err); } }

  function setBalance(b) {
    if (b == null) return;
    S.balance = b;
    S.balanceRevision++;
    if (window.bet1xSetWallet) window.bet1xSetWallet(b);
  }

  function renderAccountBalance() {
    const el = $('ogWallet');
    if (el) el.hidden = !S.signedIn;
  }

  let bannerTimer = null;
  function banner(win, text, sub) {
    const el = $('ogBanner');
    if (!el) return;
    el.className = 'og-banner show ' + (win ? 'win' : 'lose');
    el.innerHTML = esc(text) + (sub ? '<small>' + esc(sub) + '</small>' : '');
    clearTimeout(bannerTimer);
    bannerTimer = setTimeout(() => el.classList.remove('show'), win ? 3200 : 2200);
  }

  // ------------------------------------------------------------------------------ amount / chips
  function amount() { const v = parseFloat(($('ogAmount') || {}).value); return isFinite(v) ? Math.round(v * 100) / 100 : 0; }
  function clampAmount(v) {
    const c = S.config || { min_bet: 1, max_bet: 10000 };
    v = Math.max(c.min_bet, Math.min(c.max_bet, v));
    return Math.round(v * 100) / 100;
  }
  function setAmount(v) { const el = $('ogAmount'); if (el) el.value = clampAmount(v); updateStats(); if (S.opt.onAmount) S.opt.onAmount(amount()); }
  function chip() { return S.chip; }
  function setTotal(v) { S.total = Math.round(v * 100) / 100; const el = $('ogTotal'); if (el) el.textContent = money(S.total); }

  function setBusy(b) {
    S.busy = b;
    const btn = $('ogPlay');
    if (btn) btn.disabled = b;
    document.querySelectorAll('[data-og-lock]').forEach((el) => { el.disabled = b; });
    renderClock();
  }

  function updateStats() {
    const p = $('ogProfit');
    if (p) {
      const v = S.session.won - S.session.staked;
      p.textContent = (v >= 0 ? '+' : '−') + money(Math.abs(v)).replace('₹', '₹');
      p.classList.toggle('good', v > 0);
    }
    const w = $('ogWagered');
    if (w) w.textContent = money(S.session.staked);
    if (S.opt && S.opt.onStats) S.opt.onStats();
  }

  // ------------------------------------------------------------------------------ cards
  const SUIT = { S: '♠', H: '♥', D: '♦', C: '♣' };
  function cardHTML(code, opts) {
    opts = opts || {};
    if (!code || code === '??') return '<div class="og-card-face back' + (opts.sm ? ' sm' : '') + '"><span></span></div>';
    const r = code.slice(0, -1), s = code.slice(-1);
    const red = s === 'H' || s === 'D';
    return '<div class="og-card-face' + (red ? ' red' : '') + (opts.sm ? ' sm' : '') + (opts.hit ? ' hit' : '') + '"' +
      (opts.delay ? ' style="animation-delay:' + opts.delay + 'ms"' : '') + '><span>' + esc(r) + '</span><span class="s">' + SUIT[s] + '</span><span class="r2">' + esc(r) + '</span></div>';
  }

  // ------------------------------------------------------------------------------ provably fair
  const enc = (s) => new TextEncoder().encode(s);
  async function hmacBytes(key, message) {
    const k = await crypto.subtle.importKey('raw', enc(key), { name: 'HMAC', hash: 'SHA-256' }, false, ['sign']);
    return new Uint8Array(await crypto.subtle.sign('HMAC', k, enc(message)));
  }
  async function sha256Hex(s) {
    const d = new Uint8Array(await crypto.subtle.digest('SHA-256', enc(s)));
    return Array.from(d, (x) => x.toString(16).padStart(2, '0')).join('');
  }
  /** The round's first n random numbers, exactly as the server computes them. */
  async function floats(serverSeed, clientSeed, nonce, n) {
    const out = [];
    for (let cursor = 0; out.length < n; cursor++) {
      const h = await hmacBytes(serverSeed, clientSeed + ':' + nonce + ':' + cursor);
      for (let i = 0; i < 32 && out.length < n; i += 4) out.push(h[i] / 256 + h[i + 1] / 65536 + h[i + 2] / 16777216 + h[i + 3] / 4294967296);
    }
    return out;
  }

  function modal(title, html) {
    const m = document.createElement('div');
    m.className = 'og-modal';
    m.innerHTML = '<div class="og-modal-card"><h3>' + esc(title) + '<button type="button" aria-label="Close">&times;</button></h3>' + html + '</div>';
    m.addEventListener('click', (e) => { if (e.target === m || e.target.closest('h3 button')) m.remove(); });
    document.body.appendChild(m);
    return m;
  }

  async function verify(round) {
    const revealed = !!round.server_seed;
    let html = '<dl class="og-kv"><dt>Round</dt><dd>' + esc(round.round_ref) + '</dd><dt>Server seed hash</dt><dd>' + esc(round.server_seed_hash) + '</dd>' +
      '<dt>Client seed</dt><dd>' + esc(round.client_seed) + '</dd><dt>Nonce</dt><dd>' + esc(round.nonce) + '</dd>' +
      '<dt>Server seed</dt><dd>' + (revealed ? esc(round.server_seed) : '<span style="color:var(--text-dim);font-family:var(--font-body)">hidden until you change your seed (Fairness panel)</span>') + '</dd></dl>';
    const m = modal('Verify round', html + '<div id="ogVerifyOut" class="og-howto" style="margin-top:12px;">' + (revealed ? 'Checking…' : '') + '</div>');
    if (!revealed) return;
    const out = m.querySelector('#ogVerifyOut');
    try {
      const h = await sha256Hex(round.server_seed);
      const f = await floats(round.server_seed, round.client_seed, round.nonce, S.opt.verifyFloats || 8);
      let txt = (h === round.server_seed_hash ? '✅ The server seed matches the hash shown before you played.' : '❌ The server seed does NOT match its hash.') +
        '<br>First random numbers (HMAC-SHA256): <code style="font-size:11px">' + f.slice(0, 4).map((x) => x.toFixed(6)).join(', ') + '</code>';
      if (S.opt.verify) txt += '<br>' + S.opt.verify(f, round);
      out.innerHTML = txt;
    } catch (e) {
      out.textContent = 'Your browser could not run the check (it needs a secure https page).';
    }
  }

  function renderFair() {
    const el = $('ogFair');
    if (!el) return;
    if (!S.signedIn) { el.innerHTML = '<div class="og-howto">Sign in to see your seeds. Every result comes from your seed pair and can be checked afterwards.</div>'; return; }
    const s = S.seed || {};
    let html = '<dl class="og-kv"><dt>Server seed (hash)</dt><dd>' + esc(s.server_seed_hash || 'created with your first bet') + '</dd>' +
      '<dt>Next nonce</dt><dd>' + esc(s.nonce == null ? 0 : s.nonce) + '</dd></dl>' +
      '<div class="og-field" style="margin-top:10px;"><label class="og-lbl" for="ogClientSeed"><span>Client seed</span><span>yours to choose</span></label>' +
      '<div style="display:flex;gap:6px;"><input class="og-input" id="ogClientSeed" maxlength="64" value="' + esc(s.client_seed || '') + '">' +
      '<button type="button" class="og-chipbtn" id="ogRotate" data-og-lock>Change</button></div></div>' +
      '<div class="og-howto" style="font-size:12px;margin-top:8px;">Changing your seed reveals the old server seed, so you can verify every round played with it.</div>';
    if (s.previous) {
      html += '<dl class="og-kv" style="margin-top:10px;"><dt>Previous server seed</dt><dd>' + esc(s.previous.server_seed) + '</dd><dt>Its hash</dt><dd>' +
        esc(s.previous.server_seed_hash) + '</dd><dt>Client seed</dt><dd>' + esc(s.previous.client_seed) + '</dd><dt>Rounds</dt><dd>' + esc(s.previous.last_nonce) + '</dd></dl>';
    }
    if (S.recent.length) {
      html += '<details class="og-round-verification"><summary>Verify a recent round</summary><div class="og-paytable">' +
        S.recent.map((r, i) => '<button type="button" class="og-link" data-verify="' + i + '">' + esc(r.round_ref) + '</button>').join('') + '</div></details>';
    }
    el.innerHTML = html;
    el.querySelectorAll('[data-verify]').forEach((b) => { b.onclick = () => verify(S.recent[+b.dataset.verify]); });
    $('ogRotate').onclick = async () => {
      const cs = $('ogClientSeed').value.trim();
      setBusy(true);
      const d = await api('seed/rotate', { client_seed: cs });
      setBusy(false);
      if (!d.ok) { msg(d.error || 'Could not change the seed.', true); return; }
      S.seed = d.seed;
      renderFair();
      await reload();
      msg('Seed changed. Your previous server seed is revealed below.');
    };
  }

  function renderHistory() {
    const el = $('ogHistory');
    if (!el) return;
    if (!S.signedIn) { el.innerHTML = '<div class="og-empty">Sign in to play and see your rounds here.</div>'; return; }
    if (!S.recent.length) { el.innerHTML = '<div class="og-empty">No rounds yet — place your first bet.</div>'; return; }
    el.innerHTML = '<table class="og-tbl"><thead><tr><th>Result</th><th class="num">Bet</th><th class="num">Multiplier</th><th class="num">Payout</th><th>Outcome</th></tr></thead><tbody>' +
      S.recent.map((r) => {
        const paid = Math.round(Number(r.payout || 0) * 100), stake = Math.round(Number(r.bet || 0) * 100);
        const outcome = paid > stake ? 'win' : paid < stake ? 'loss' : 'push';
        const won = outcome === 'win';
        const txt = S.opt.resultText ? S.opt.resultText(r) : '';
        return '<tr><td>' + txt + '</td><td class="num">' + money(r.bet) + '</td><td class="num ' + (won ? 'w' : 'l') + '">' + fmtMult(r.multiplier) + '</td>' +
          '<td class="num ' + (won ? 'w' : 'l') + '">' + (paid > 0 ? '+' + money(r.payout) : money(0)) + '</td><td><span class="og-outcome ' + outcome + '">' + ({win: 'Win', loss: 'Loss', push: 'Push'})[outcome] + '</span></td></tr>';
      }).join('') + '</tbody></table>';
  }

  // ------------------------------------------------------------------------------ playing
  async function play() {
    if (S.busy || S.open) return;
    if (S.token !== sessionToken()) { await reload(); if (S.busy || S.open) return; }
    if (!S.signedIn) { if (window.openAuthModal) window.openAuthModal('login'); return; }
    if (!bettingOpen()) { msg('Betting is closed. Wait for the next countdown.', true); syncClock(); return; }
    if (S.config && !S.config.enabled) { msg('This game is currently unavailable.', true); return; }
    let body;
    try { body = S.opt.collect ? S.opt.collect() : {}; } catch (e) { msg(e.message || String(e), true); return; }
    if (!S.opt.chipMode) {
      const a = amount();
      if (!(a > 0)) { msg('Enter a bet amount.', true); return; }
      body.bet_amount = a;
    } else if (!(S.total > 0)) { msg('Place a chip first.', true); return; }
    body.client_ref = newRef();
    body.round_id = clock.round_id;
    setBusy(true); msg('');
    sound('betPlace');
    let d = await api(S.game + '/play', body);
    if (!d.ok && d._status === 0) d = await api(S.game + '/play', body);   // one retry with the SAME ref: never a second bet
    if (!d.ok) { setBusy(false); msg(d.error || 'Something went wrong.', true); syncClock(); if (d.code === 'round_open' || d.code === 'session_changed') reload(); return; }
    const round = d.round;
    try { if (S.opt.onResult) await S.opt.onResult(round, d); } catch (e) { console.error(e); }
    afterRound(round, d.balance);
  }

  function afterRound(round, balance) {
    if (S.token !== sessionToken()) { setBusy(false); reload(); return; }
    setBalance(balance);
    if (S.seed && S.seed.server_seed_hash === round.server_seed_hash) S.seed.nonce = Math.max(S.seed.nonce || 0, round.nonce + 1);
    renderFair();
    if (round.status === 'open') {
      S.open = round; S.step = round.step != null ? round.step : S.step;
      setBusy(false);
      return;
    }
    S.open = null;
    S.session.staked += round.bet; S.session.won += round.payout;
    S.recent.unshift(round); S.recent = S.recent.slice(0, 20);
    if (!S.seed) api('seed').then((d) => { if (d.ok) { S.seed = d.seed; renderFair(); } });
    renderHistory(); renderFair(); updateStats();
    const won = round.payout > round.bet;
    if (!S.opt.noBanner) {
      if (won) banner(true, 'You won ' + money(round.payout), fmtMult(round.multiplier) + ' on ' + money(round.bet));
      else if (round.payout === round.bet) banner(true, 'Stake returned', money(round.payout));
      else banner(false, round.payout > 0 ? 'Returned ' + money(round.payout) : 'No win this time', 'Bet ' + money(round.bet));
    }
    sound(won ? (round.multiplier >= 10 ? 'bigWin' : round.multiplier >= 2 ? 'win' : 'cashout') : 'lose');
    setBusy(false);
  }

  /** The next step of the open round (Blackjack, Coinflip). Resolves with the new round, or null. */
  async function action(name, extra) {
    if (S.busy || !S.open) return null;
    if (S.token !== sessionToken()) { await reload(); return null; }
    setBusy(true); msg('');
    const d = await api(S.game + '/action', Object.assign({ round_ref: S.open.round_ref, step: S.step, action: name, round_id: clock ? clock.round_id : null }, extra || {}));
    if (!d.ok) {
      setBusy(false); msg(d.error || 'Something went wrong.', true);
      if (d._status === 0 || d.code === 'stale' || d.code === 'round_closed' || d.code === 'session_changed') await reload();
      return null;
    }
    S.step += 1;
    const round = d.round;
    try { if (S.opt.onAction) await S.opt.onAction(round, name, d); } catch (e) { console.error(e); }
    afterRound(round, d.balance);
    return round;
  }

  async function reload() {
    const token = sessionToken(), revision = S.balanceRevision;
    if (S.token !== token) {
      S.open = null; S.seed = null; S.recent = []; S.signedIn = false; S.balance = null;
      S.session = { staked: 0, won: 0 };
      renderHistory(); renderFair(); updateStats(); renderAccountBalance();
    }
    const d = await api(S.game + '/state');
    if (token !== sessionToken() || S.busy || revision !== S.balanceRevision) return;
    if (!d.ok) { msg(d.error || 'Could not load the game.', true); return; }
    S.token = token;
    S.config = d.config; S.info = d.info || {}; S.signedIn = !!d.signed_in; S.seed = d.seed; S.recent = d.recent || [];
    renderAccountBalance();
    if (d.balance != null) setBalance(d.balance);
    S.open = d.open_round || null;
    S.step = S.open ? (S.open.step || 0) : 0;
    const rtp = $('ogRtp');
    if (rtp) rtp.textContent = (S.config.rtp * 100).toFixed(2).replace(/\.?0+$/, '') + '%' + (S.game === 'blackjack' ? ' with good play' : S.game === 'baccarat' ? ' Banker' : '');
    const lim = $('ogLimits');
    if (lim) lim.textContent = money(S.config.min_bet) + ' – ' + money(S.config.max_bet);
    if (!S.config.enabled) msg('This game is currently unavailable.', true);
    if (!S.opt.chipMode && $('ogAmount') && !$('ogAmount').dataset.touched) setAmount(Math.max(S.config.min_bet, Math.min(10, S.config.max_bet)));
    renderHistory(); renderFair();
    const how = $('ogHow');
    if (how && S.opt.howto) how.innerHTML = (typeof S.opt.howto === 'function' ? S.opt.howto(S.info, S.config) : S.opt.howto) + '<p>Maximum total return per round: <b>' + money(S.config.max_win) + '</b>. This cap can reduce the effective return on larger bets.</p>';
    if (S.opt.onLoad) S.opt.onLoad(d);
    renderClock();
  }

  let refreshing = false;
  async function refreshAccount() {
    if (refreshing || S.busy || document.hidden) return;
    refreshing = true;
    try {
      if (S.token !== sessionToken() || !S.config) { await reload(); return; }
      if (!S.signedIn) return;
      const token = sessionToken(), revision = S.balanceRevision;
      const res = await fetch('api/wallet/balance');
      const d = await res.json();
      if (token !== sessionToken() || S.busy || revision !== S.balanceRevision) return;
      if (res.status === 401) { await reload(); return; }
      if (res.ok && Number.isFinite(Number(d.balance))) setBalance(Number(d.balance));
    } catch (e) { /* A background refresh never fabricates a balance. Betting still uses the server. */ }
    finally { refreshing = false; }
  }

  // ------------------------------------------------------------------------------ mount
  function mount(opt) {
    S.opt = opt; S.game = opt.game;
    const root = $('og');
    const amountHTML = opt.chipMode
      ? '<div class="og-field"><div class="og-lbl"><span>Chip</span><span>tap the table to place</span></div><div class="og-chips">' +
          [10, 50, 100, 500, 1000, 5000].map((v) => '<button type="button" class="og-chip' + (v === 10 ? ' on' : '') + '" data-v="' + v + '">' + (v >= 1000 ? v / 1000 + 'K' : v) + '</button>').join('') +
        '</div></div><div class="og-stats"><div class="og-stat"><div class="k">Total bet</div><div class="v" id="ogTotal">₹0.00</div></div>' +
        '<div class="og-stat" style="display:flex;gap:6px;align-items:center;justify-content:center;"><button type="button" class="og-chipbtn" id="ogUndo" data-og-lock>Undo</button><button type="button" class="og-chipbtn" id="ogClear" data-og-lock>Clear</button></div></div>'
      : '<div class="og-field"><div class="og-lbl"><span>Bet amount</span><span id="ogLimits"></span></div>' +
          '<div class="og-amount"><span class="cur">₹</span><input id="ogAmount" type="number" inputmode="decimal" min="0" step="1" value="10" data-og-lock>' +
          '<button type="button" data-q="half" data-og-lock>½</button><button type="button" data-q="double" data-og-lock>2×</button></div>' +
          '<div class="og-quick"><button type="button" class="og-chipbtn" data-q="min" data-og-lock>Min</button><button type="button" class="og-chipbtn" data-q="10" data-og-lock>₹10</button>' +
          '<button type="button" class="og-chipbtn" data-q="100" data-og-lock>₹100</button><button type="button" class="og-chipbtn" data-q="max" data-og-lock>Max</button></div></div>';
    root.innerHTML = '<a href="cashier.html" class="og-wallet" id="ogWallet" data-wallet-chip aria-label="Account balance" hidden></a>' +
      '<div class="og-title"><h1>' + esc(opt.title) + '<small>' + esc(opt.sub || '') + '</small></h1>' +
      '<span class="og-pill">RTP <b id="ogRtp">—</b> · Provably fair</span></div>' +
      '<div class="og-main"><aside class="og-panel">' + amountHTML + (opt.controls || '') +
      '<div id="ogClock" class="og-howto" role="timer" aria-label="Shared betting timer" style="margin:8px 0;font-variant-numeric:tabular-nums">Synchronizing betting timer...</div>' +
      '<button type="button" class="og-play" id="ogPlay" disabled>' + esc(opt.playLabel || 'Bet') + '</button>' + (opt.afterPlay || '') +
      '<div class="og-msg" id="ogMsg" role="status"></div>' +
      '<div class="og-stats"><div class="og-stat"><div class="k">Session profit</div><div class="v" id="ogProfit">₹0.00</div></div>' +
      '<div class="og-stat"><div class="k">Wagered</div><div class="v" id="ogWagered">₹0.00</div></div></div></aside>' +
      '<section class="og-stage" id="ogStage"><div class="og-banner" id="ogBanner"></div>' + (opt.stage || '') + '</section></div>';
    const below = document.createElement('div');
    below.className = 'og-below';
    below.innerHTML = '<div style="display:flex;flex-direction:column;gap:14px;min-width:0;"><div class="og-card"><h3>Your recent rounds</h3><div id="ogHistory"></div></div>' +
      '<div class="og-card"><h3>How to play</h3><div class="og-howto" id="ogHow"></div></div></div>' +
      '<div class="og-card"><h3>Fairness</h3><div id="ogFair"></div></div>';
    root.after(below);

    $('ogPlay').onclick = play;
    if (opt.chipMode) {
      root.querySelectorAll('.og-chip').forEach((b) => {
        b.onclick = () => { root.querySelectorAll('.og-chip').forEach((x) => x.classList.remove('on')); b.classList.add('on'); S.chip = +b.dataset.v; sound('click'); };
      });
      $('ogClear').onclick = () => { if (opt.onClear) opt.onClear(); };
      $('ogUndo').onclick = () => { if (opt.onUndo) opt.onUndo(); };
    } else {
      const inp = $('ogAmount');
      inp.addEventListener('input', () => { inp.dataset.touched = '1'; updateStats(); if (opt.onAmount) opt.onAmount(amount()); });
      inp.addEventListener('blur', () => setAmount(amount()));
      root.querySelectorAll('[data-q]').forEach((b) => {
        b.onclick = () => {
          inp.dataset.touched = '1';
          const q = b.dataset.q, a = amount(), c = S.config || { min_bet: 1, max_bet: 10000 };
          if (q === 'half') setAmount(a / 2); else if (q === 'double') setAmount(a * 2);
          else if (q === 'min') setAmount(c.min_bet); else if (q === 'max') setAmount(Math.min(c.max_bet, S.balance != null ? Math.floor(S.balance * 100) / 100 : c.max_bet));
          else setAmount(+q);
        };
      });
    }
    document.addEventListener('keydown', (e) => {
      if (e.code === 'Space' && !/INPUT|TEXTAREA|SELECT|BUTTON/.test((e.target.tagName || '')) && !S.open) { e.preventDefault(); play(); }
    });
    if (opt.onMount) opt.onMount();
    reload();
    syncClock();
    setInterval(syncClock, 2500);
    setInterval(renderClock, 100);
    window.addEventListener('focus', syncClock);
    document.addEventListener('visibilitychange', () => { if (!document.hidden) syncClock(); });
    window.addEventListener('focus', refreshAccount);
    window.addEventListener('storage', (e) => { if (e.key === 'bet1x_auth_token' || e.key === 'bet1x_demo_wallet' || e.key === null) refreshAccount(); });
    document.addEventListener('visibilitychange', refreshAccount);
    setInterval(refreshAccount, 10000);
    // ui-common restores the session asynchronously; refresh once it has.
    setTimeout(() => { if (!S.signedIn && typeof window.getCurrentUser === 'function' && window.getCurrentUser()) reload(); }, 1200);
  }

  window.Orig = { mount, api, play, action, reload, amount, setAmount, chip, setTotal, setBusy, banner, msg, money, fmtMult, sleep, sound,
                  cardHTML, esc, floats, modal, state: S };
})();
