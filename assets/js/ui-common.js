/* ---------------------------------------------------------------------------------------------
 * Backend base URL.
 *
 * Every API call used to be rewritten to the literal string "http://localhost:5000", which works on
 * the developer's laptop and nowhere else: once the site is hosted, that request goes to the
 * *visitor's* own machine and fails. The backend serves this static site itself, so the default is
 * now simply the page's own origin and the build runs unchanged on any domain.
 *
 * To point the pages at a separate API host, set either of these before loading this file:
 *     <meta name="bet1x-api-base" content="https://api.example.com">
 *     <script>window.BET1X_API_BASE = 'https://api.example.com';</script>
 * That host must also list this site's origin in the backend's CORS_ORIGINS.
 * ------------------------------------------------------------------------------------------- */
window.BET1X_API_BASE = (function () {
  var explicit = window.BET1X_API_BASE;
  if (!explicit) {
    var meta = document.querySelector('meta[name="bet1x-api-base"]');
    if (meta && meta.content) explicit = meta.content;
  }
  if (explicit) return String(explicit).replace(/\/+$/, '');
  // Opened straight off the filesystem there is no origin to talk to, so fall back to a local dev
  // server. Anywhere else, same-origin.
  if (window.location.protocol === 'file:') return 'http://localhost:5000';
  return window.location.origin;
})();

/* Which localStorage key holds this page's session token. Player pages use the player session; the
 * operator consoles set window.BET1X_ADMIN_CONSOLE = true before loading this file so that an admin
 * signed in on the same browser neither clobbers nor borrows a player's session. */
window.BET1X_TOKEN_KEY = window.BET1X_ADMIN_CONSOLE ? 'bet1x_admin_token' : 'bet1x_auth_token';

/* ---------------------------------------------------------------------------------------------
 * Cricket feature gate (Your 11 / Boundary Baazi).
 *
 * Both games are built and their code stays in the repo, but they are not part of the v1 launch.
 * The single switch is the [data-feature="cricket"] rule at the bottom of assets/css/style.css --
 * that rule hides every entry point, and this block reads the very same rule (by probing a
 * throwaway element) rather than carrying a second flag that could drift out of sync with it.
 *
 * Anyone who reaches youreleven.html or boundarybaazi.html directly -- a bookmark, a shared link, a
 * search result -- is sent back to the lobby, because with the backend flag off every API call on
 * those pages returns 404 and the page would otherwise sit there looking broken.
 * ------------------------------------------------------------------------------------------- */
window.BET1X_CRICKET_ENABLED = (function () {
  try {
    var probe = document.createElement('div');
    probe.setAttribute('data-feature', 'cricket');
    document.documentElement.appendChild(probe);
    var hidden = window.getComputedStyle(probe).display === 'none';
    probe.parentNode.removeChild(probe);
    return !hidden;
  } catch (e) {
    return false; // if the gate cannot be read, stay closed rather than exposing a dead page
  }
})();

if (!window.BET1X_CRICKET_ENABLED &&
    /(youreleven|boundarybaazi)\.html$/i.test(window.location.pathname)) {
  window.location.replace('index.html');
}

// Global fetch interceptor: rewrites the legacy PHP-shaped API paths onto the real backend and
// attaches the session token.
const originalFetch = window.fetch;
window.fetch = function (input, init) {
  let url = typeof input === 'string' ? input : (input instanceof URL ? input.href : (input && input.url));
  let isApiCall = false;

  if (url && (url.includes('api/') || url.includes('.php'))) {
    if (!url.startsWith('http://') && !url.startsWith('https://')) {
      let cleanPath = url;
      if (cleanPath.startsWith('./')) cleanPath = cleanPath.substring(2);
      while (cleanPath.startsWith('../')) cleanPath = cleanPath.substring(3);
      if (cleanPath.startsWith('/')) cleanPath = cleanPath.substring(1);
      if (cleanPath.startsWith('backend/')) cleanPath = cleanPath.substring(8);
      if (!cleanPath.startsWith('api/')) cleanPath = 'api/' + cleanPath;
      url = window.BET1X_API_BASE + '/' + cleanPath;
      isApiCall = true;
    } else if (url.indexOf(window.BET1X_API_BASE) === 0) {
      isApiCall = true;
    }
    // The username is deliberately NOT appended any more. The backend derives the acting account
    // from the signed token, and on an operator session a stray ?username= would silently redirect
    // an admin action onto whichever player happened to be logged in on this browser.
  }

  const token = localStorage.getItem(window.BET1X_TOKEN_KEY);
  if (token) {
    if (!init) init = {};
    if (!init.headers) init.headers = {};
    if (init.headers instanceof Headers) {
      if (!init.headers.has('Authorization')) {
        init.headers.append('Authorization', 'Bearer ' + token);
      }
    } else if (Array.isArray(init.headers)) {
      init.headers.push(['Authorization', 'Bearer ' + token]);
    } else if (typeof init.headers === 'object') {
      if (!init.headers['Authorization']) {
        init.headers['Authorization'] = 'Bearer ' + token;
      }
    }
  }

  // Same-origin by default. 'include' attaches cookies to third-party hosts, which paired with a
  // permissive CORS policy is what makes cross-site request forgery possible.
  if (init && !init.credentials) {
    init.credentials = (isApiCall && url.indexOf(window.location.origin) !== 0) ? 'omit' : 'same-origin';
  }

  return originalFetch(url, init);
};

function getApiPrefix() {
  const path = window.location.pathname;
  if (path.includes('/teenpati/') || path.includes('/aviator/') || path.includes('/mining/')) {
    return '../';
  }
  return '';
}

const WALLET_KEY = 'bet1x_demo_wallet';
const HISTORY_KEY = 'bet1x_demo_history';
const CURRENT_USER_KEY = 'bet1x_current_user';
const AUTH_TOKEN_KEY = window.BET1X_TOKEN_KEY;
const USERS_KEY = 'bet1x_users';
const STARTING_BALANCE = 2000;

// --- CENTRALIZED BACKEND SYNCHRONIZED CLOCK ENGINE ---
window.ServerClock = {
  clockSkew: 0,
  isSynced: false,
  lastSyncTime: 0,
  rooms: {},
  aviator: null,
  async sync() {
    const t0 = Date.now();
    try {
      const res = await originalFetch(window.BET1X_API_BASE + '/api/server_time');
      if (res.ok) {
        const data = await res.json();
        const t1 = Date.now();
        const latency = (t1 - t0) / 2;
        const serverNow = (data.server_time || Date.now()) + latency;
        this.clockSkew = Date.now() - serverNow;
        this.isSynced = true;
        this.lastSyncTime = Date.now();
        if (data.rooms) this.rooms = data.rooms;
        if (data.aviator) this.aviator = data.aviator;
      }
    } catch (e) {
      this.isSynced = true;
    }
  },
  now() {
    return Date.now() - this.clockSkew;
  },
  nowSec() {
    return Math.floor((Date.now() - this.clockSkew) / 1000);
  },
  getRoomState(room, duration) {
    const dur = duration || (room === 'sapre' ? 30 : (room === 'becone' ? 60 : (room === 'emred' ? 180 : (room === 'vip' ? 300 : 30))));
    const nowSec = this.nowSec();
    const timeLeft = Math.max(0, dur - (nowSec % dur));
    const roundStart = Math.floor(nowSec / dur) * dur;
    const d = new Date(roundStart * 1000);
    const yyyy = d.getUTCFullYear();
    const mm = String(d.getUTCMonth() + 1).padStart(2, '0');
    const dd = String(d.getUTCDate()).padStart(2, '0');
    const hh = String(d.getUTCHours()).padStart(2, '0');
    const bucket = String(Math.floor((roundStart % 3600) / dur)).padStart(3, '0');
    const roundId = `${yyyy}${mm}${dd}${hh}${bucket}`;
    return {
      time_left: timeLeft,
      round_id: roundId,
      duration: dur,
      progress_pct: timeLeft / dur
    };
  }
};

// Immediately synchronize server clock on script evaluation
if (typeof originalFetch === 'function') {
  window.ServerClock.sync();
  setInterval(() => {
    window.ServerClock.sync();
  }, 10000);
}

/* ---------------------------------------------------------------------------------------------
 * Offline demo mode.
 *
 * When the backend is unreachable the pages can fall back to a pure-localStorage simulation with
 * its own balance, its own round results and its own payouts. That is exactly right for a laptop
 * pitch demo and exactly wrong for a live deployment: a player whose connection blips would be
 * shown winnings that do not exist on the server and cannot be withdrawn.
 *
 * So the fallback is now opt-in. Set window.BET1X_ALLOW_OFFLINE = true (or open the pages over
 * file://) to get the old demo behaviour; otherwise a backend outage surfaces as an outage.
 * ------------------------------------------------------------------------------------------- */
(function () {
  var allowOffline = window.BET1X_ALLOW_OFFLINE === true || window.location.protocol === 'file:';
  var offline = false;
  var warned = false;
  Object.defineProperty(window, 'isOfflineMode', {
    configurable: true,
    get: function () { return offline; },
    set: function (value) {
      if (value && !allowOffline) {
        offline = false;
        if (!warned) {
          warned = true;
          console.warn('[bet1x] Backend unreachable, and offline demo mode is disabled on this deployment.');
          if (typeof window.showToast === 'function') {
            window.showToast('Lost connection to the game server. Please refresh in a moment.', 'error');
          }
        }
        return;
      }
      offline = !!value;
    }
  });
  window.BET1X_ALLOW_OFFLINE = allowOffline;
})();

function getUsers() {
  const stored = localStorage.getItem(USERS_KEY);
  return stored ? JSON.parse(stored) : [];
}

function saveUsers(users) {
  localStorage.setItem(USERS_KEY, JSON.stringify(users));
}

function getCurrentUser() {
  const stored = localStorage.getItem(CURRENT_USER_KEY);
  return stored ? JSON.parse(stored) : null;
}

/**
 * Turn a server-supplied wallet balance into a number.
 *
 * Deliberately NOT `parseFloat(x) || 2000`. A brand-new account has a balance of exactly 0, and 0 is
 * falsy in JavaScript, so that expression threw the real balance away and substituted the old demo
 * float of 2000 — the account showed 2000 coins for the second or two until the first server sync
 * replaced it with the true 0. Only a genuinely absent or unparseable value falls back now, and it
 * falls back to 0, because on a real-money deployment inventing a balance is never the safe guess.
 */
function walletFromServer(value) {
  const n = parseFloat(value);
  return Number.isFinite(n) ? n : 0;
}

function getWallet() {
  const balance = localStorage.getItem(WALLET_KEY);
  if (balance === null) {
    // Nothing cached yet — show 0 until the server says otherwise. This used to seed 2000, the old
    // demo float, which is what put a non-existent balance on screen for a brand-new account before
    // the first sync corrected it. STARTING_BALANCE still exists for the offline demo paths below,
    // which are switched off on any real deployment.
    localStorage.setItem(WALLET_KEY, '0.00');
    return 0;
  }
  const n = parseFloat(balance);
  return Number.isFinite(n) ? n : 0;
}

function setWallet(amount) {
  localStorage.setItem(WALLET_KEY, parseFloat(amount).toFixed(2));
  renderWalletChips();
}

function adjustWallet(delta, reason = 'Color Room Wager/Payout') {
  const current = getWallet();
  const newBal = current + delta;
  localStorage.setItem(WALLET_KEY, newBal.toFixed(2));
  renderWalletChips();
  
  if (window.isOfflineMode) {
    // Local storage fallback for transactions list
    const txns = JSON.parse(localStorage.getItem('bet1x_transactions') || '[]');
    txns.unshift({
      id: 'TX_' + Math.floor(100000 + Math.random() * 900000),
      user: getCurrentUser() ? getCurrentUser().username : 'DemoUser',
      type: delta >= 0 ? 'Deposit' : 'Withdrawal',
      amount: Math.abs(delta),
      details: reason,
      status: 'Completed',
      timestamp: new Date().toLocaleString()
    });
    localStorage.setItem('bet1x_transactions', JSON.stringify(txns));
    return newBal;
  }
  
  const prefix = getApiPrefix();
  const params = new URLSearchParams();
  params.append('delta', delta);
  params.append('reason', reason);
  
  fetch(prefix + 'api/wallet.php?action=adjust', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: params
  })
  .then(res => {
    if (!res.ok) throw new Error("HTTP error " + res.status);
    const ct = res.headers.get("content-type");
    if (!ct || !ct.includes("application/json")) throw new Error("Not JSON");
    return res.json();
  })
  .then(data => {
    if (data.new_balance !== undefined) {
      localStorage.setItem(WALLET_KEY, parseFloat(data.new_balance).toFixed(2));
      renderWalletChips();
    }
  })
  .catch(err => {
    console.warn("API wallet adjust error (switching to offline mode):", err);
    window.isOfflineMode = true;
    // Retroactively add this to local history since we fell back to offline mode
    const txns = JSON.parse(localStorage.getItem('bet1x_transactions') || '[]');
    txns.unshift({
      id: 'TX_' + Math.floor(100000 + Math.random() * 900000),
      user: getCurrentUser() ? getCurrentUser().username : 'DemoUser',
      type: delta >= 0 ? 'Deposit' : 'Withdrawal',
      amount: Math.abs(delta),
      details: reason,
      status: 'Completed',
      timestamp: new Date().toLocaleString()
    });
    localStorage.setItem('bet1x_transactions', JSON.stringify(txns));
  });
  return newBal;
}

function getCurrentGameRoom() {
  const p = window.location.pathname.toLowerCase();
  if (p.includes('aviator')) return 'Vimaan';
  if (p.includes('win.html') || p.endsWith('/win')) return 'SAPRE';
  if (p.includes('win1')) return 'BECONE';
  if (p.includes('win2')) return 'EMRED';
  if (p.includes('win3')) return 'VIP';
  if (p.includes('teenpatti') || p.includes('teenpati')) return 'Teen Patti';
  if (p.includes('mining')) return 'Mines';
  return null;
}

function forfeitAllPendingBets(targetRoom = null) {
  try {
    const stored = localStorage.getItem(HISTORY_KEY);
    if (!stored) return;
    const hist = JSON.parse(stored);
    let changed = false;
    hist.forEach(bet => {
      if (bet.status === 'pending') {
        if (!targetRoom || (bet.room && bet.room.toUpperCase() === targetRoom.toUpperCase())) {
          bet.status = 'lost';
          bet.payout = 0;
          changed = true;
        }
      }
    });
    if (changed) {
      localStorage.setItem(HISTORY_KEY, JSON.stringify(hist));
    }
  } catch (e) {
    console.error("Error forfeiting pending bets:", e);
  }
}

function getHistory() {
  const stored = localStorage.getItem(HISTORY_KEY);
  if (!stored) return [];
  try {
    const hist = JSON.parse(stored);
    const currentRoom = getCurrentGameRoom();
    let changed = false;
    hist.forEach(bet => {
      // If user is no longer in this game room, any pending bets are automatically lost
      if (bet.status === 'pending') {
        if (!currentRoom || !bet.room || bet.room.toUpperCase() !== currentRoom.toUpperCase()) {
          bet.status = 'lost';
          bet.payout = 0;
          changed = true;
        }
      }
    });
    if (changed) {
      localStorage.setItem(HISTORY_KEY, JSON.stringify(hist));
    }
    return hist;
  } catch (e) {
    return [];
  }
}

function pushHistory(entry) {
  const hist = getHistory();
  hist.unshift(entry);
  localStorage.setItem(HISTORY_KEY, JSON.stringify(hist.slice(0, 50)));
}

function renderWalletChips() {
  const prefix = getApiPrefix();
  document.querySelectorAll('[data-wallet-chip]').forEach(el => {
    el.textContent = '₹ ' + getWallet().toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    el.style.cursor = 'pointer';
    el.title = 'Click to Deposit / Manage Wallet';
    el.onclick = () => {
      window.location.href = prefix + 'cashier.html';
    };
  });
}

function updateAuthHeaderUI() {
  const authArea = document.getElementById('headerAuthArea');
  if (!authArea) return;
  const user = getCurrentUser();
  const prefix = getApiPrefix();
  
  // Colors below are CSS custom properties, not literals, specifically so this one shared
  // template renders correctly on both the original dark exchange-header (e.g. parity.html,
  // which has no body.exchange-theme class) and the 2026-08-25 reskinned pages (docs/NEW-DESIGN)
  // without any per-page branching here.
  if (user && user.username) {
    authArea.innerHTML = `
      <div style="display:flex; align-items:center; gap:10px; color:var(--text); font-size:13.5px; flex-wrap:wrap; justify-content:flex-end;">
        <span>Welcome, <strong style="color:var(--gold);">${escapeHtml(user.username)}</strong></span>
        <span class="wallet-chip" data-wallet-chip style="margin:0;">₹ ${getWallet().toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</span>
        <a href="${prefix}cashier.html" style="background:var(--gold, #c9a054); color:#000; font-weight:800; font-size:12px; padding:6px 12px; border-radius:4px; text-decoration:none; display:inline-flex; align-items:center; gap:4px; box-shadow:0 0 12px rgba(201,160,84,0.4);">💰 Deposit</a>
        <a href="#" onclick="handleHeaderLogout(event)" style="color:var(--red); font-weight:700; text-decoration:none; font-size:12.5px; border-left:1px solid var(--border); padding-left:10px;">Logout ⎋</a>
      </div>
    `;
  } else {
    authArea.innerHTML = `
      <div class="header-guest-wrap" style="display:flex; align-items:center; gap:8px;">
        <button type="button" class="btn btn-ghost header-login-btn" onclick="openAuthModal('login')" style="padding:6px 14px; font-size:13px; font-weight:700; border:1px solid var(--border); border-radius:4px; color:var(--text); background:var(--surface-2); cursor:pointer; transition:all 0.2s;">Log In</button>
        <button type="button" class="btn btn-primary header-signup-btn" onclick="openAuthModal('signup')" style="padding:6px 14px; font-size:13px; font-weight:800; background:var(--red); color:#ffffff; border:none; border-radius:4px; cursor:pointer; box-shadow:0 0 12px var(--red-soft); transition:all 0.2s;">Sign Up</button>
      </div>
    `;
  }
  renderWalletChips();
}

function handleHeaderLogout(e) {
  if (e) e.preventDefault();
  const prefix = getApiPrefix();
  fetch(prefix + 'api/auth.php?action=logout').catch(() => {});
  localStorage.removeItem(CURRENT_USER_KEY);
  localStorage.removeItem(AUTH_TOKEN_KEY);
  localStorage.removeItem(WALLET_KEY);
  localStorage.removeItem(HISTORY_KEY);
  location.reload();
}

window.handleHeaderLogout = handleHeaderLogout;

window.handleHeaderLogin = function(e) {
  if (e) e.preventDefault();
  const userField = document.getElementById('loginUsername');
  const passField = document.getElementById('loginPassword');
  const username = userField ? userField.value.trim() : '';
  const password = passField ? passField.value : '';

  if (!username || !password) {
    window.openAuthModal('login');
    return;
  }

  const prefix = getApiPrefix();
  const body = new URLSearchParams();
  body.append('username', username);
  body.append('password', password);

  fetch(prefix + 'api/auth.php?action=login', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: body
  })
  .then(res => res.json())
  .then(data => {
    if (data.success && data.user) {
      if (data.token) localStorage.setItem(AUTH_TOKEN_KEY, data.token);
      localStorage.setItem(CURRENT_USER_KEY, JSON.stringify(data.user));
      localStorage.setItem(WALLET_KEY, walletFromServer(data.user.wallet_balance).toFixed(2));
      window.showToast(`Welcome back, ${data.user.username}!`, 'success');
      updateAuthHeaderUI();
      setTimeout(() => location.reload(), 600);
    } else {
      window.showToast(data.error || 'Incorrect username or password.', 'error');
    }
  })
  .catch(() => {
    window.showToast('Login failed. Please check credentials.', 'error');
  });
};

function syncUserSession() {
  const user = getCurrentUser();
  if (!user || !user.username) return;
  
  const prefix = getApiPrefix();
  const token = localStorage.getItem(AUTH_TOKEN_KEY);
  const headers = {};
  if (token) headers['Authorization'] = 'Bearer ' + token;
  
  fetch(prefix + 'api/auth.php?action=status&username=' + encodeURIComponent(user.username), {
    headers: headers
  })
  .then(res => res.json())
  .then(data => {
    if (data.logged_in && data.user) {
      localStorage.setItem(CURRENT_USER_KEY, JSON.stringify(data.user));
      if (data.user.wallet_balance !== undefined) {
        localStorage.setItem(WALLET_KEY, parseFloat(data.user.wallet_balance).toFixed(2));
      }
      updateAuthHeaderUI();
    } else {
      // The server does not recognise this session: the token expired, was revoked, or predates the
      // move to signed tokens. Clearing it here means the visitor sees the Log In button and can
      // sign in again, rather than a logged-in header whose every action silently 401s.
      localStorage.removeItem(AUTH_TOKEN_KEY);
      localStorage.removeItem(CURRENT_USER_KEY);
      localStorage.removeItem(WALLET_KEY);
      updateAuthHeaderUI();
    }
  })
  .catch(() => {});
}

document.addEventListener('DOMContentLoaded', () => {
  updateAuthHeaderUI();
  syncUserSession();

  // Clean up sub-navbar: remove Admin tab and ensure Mines exists
  const subNav = document.querySelector('.sub-navbar-links');
  if (subNav) {
    // Always remove Admin tab from sub-navbar
    Array.from(subNav.children).forEach(li => {
      const a = li.querySelector('a');
      if (li.textContent.toLowerCase().includes('admin') || (a && a.getAttribute('href') && a.getAttribute('href').includes('parity'))) {
        li.remove();
      }
    });

    const prefix = getApiPrefix();
    const hasMines = Array.from(subNav.querySelectorAll('a')).some(a => a.getAttribute('href') && (a.getAttribute('href').includes('mining') || a.getAttribute('href').includes('mines')));
    if (!hasMines) {
      const mineLi = document.createElement('li');
      mineLi.className = 'sub-navbar-item';
      mineLi.innerHTML = `<a href="${prefix}mining.html" class="sub-navbar-link">Mines</a>`;
      subNav.appendChild(mineLi);
    }
  }
});

function resetDemo() {
  const prefix = getApiPrefix();
  fetch(prefix + 'api/auth.php?action=logout')
  .then(() => {
    localStorage.removeItem(WALLET_KEY);
    localStorage.removeItem(HISTORY_KEY);
    localStorage.removeItem(CURRENT_USER_KEY);
    location.reload();
  });
}

/* ---------------- Pulse strip (signature element) ---------------- */
function renderPulseStrip(container, results) {
  container.innerHTML = '';
  results.forEach(r => {
    const bar = document.createElement('div');
    bar.className = 'pulse-bar ' + r.dotClass;
    bar.style.height = (20 + Math.random() * 44) + 'px';
    bar.title = `#${r.roundNumber} → ${r.number} (${r.color})`;
    container.appendChild(bar);
  });
}

/* ---------------------------------------------------------------------------------------------
 * Removed with the demo scaffolding:
 *
 *   startCountdownRing()  — dead code; nothing had called it since the colour rooms moved to
 *                           server-driven timers. It also depended on randomInt()/formatRoundId()
 *                           from dummy-data.js.
 *
 *   startActivityFeed()   — drove the "Live Activity" card on the four colour rooms from
 *                           generateFakeActivityEvent(). That generator had already been emptied,
 *                           so the card rendered nothing at all and simply sat blank on every room.
 *                           Fabricating player activity is not an option on a live money site, and
 *                           the room's own results history already sits directly above it, so the
 *                           card was removed rather than refilled.
 *
 * A genuine room-wide bet feed is possible later — the backend already records every bet per round
 * in colour state — but it needs a deliberate endpoint that decides what other players' activity is
 * safe to expose, which is a product decision rather than a cleanup.
 * ------------------------------------------------------------------------------------------- */

/* ---------------- Animated counters ---------------- */
function animateCounter(el, target, opts = {}) {
  const { duration = 900, prefix = '', suffix = '' } = opts;
  const start = 0;
  const startTime = performance.now();
  function frame(now) {
    const progress = Math.min(1, (now - startTime) / duration);
    const eased = 1 - Math.pow(1 - progress, 3);
    const value = Math.floor(start + (target - start) * eased);
    el.textContent = prefix + value.toLocaleString('en-IN') + suffix;
    if (progress < 1) requestAnimationFrame(frame);
  }
  requestAnimationFrame(frame);
}

/* ---------------- Win Celebration Particles ---------------- */
function triggerWinShower(container) {
  if (!container) return;
  const position = window.getComputedStyle(container).position;
  if (position === 'static') {
    container.style.position = 'relative';
  }
  
  const emojis = ['₹', '₹', '✨', '⭐', '🎉'];
  for (let i = 0; i < 35; i++) {
    const p = document.createElement('div');
    p.className = 'coin-particle';
    p.textContent = emojis[Math.floor(Math.random() * emojis.length)];
    p.style.left = Math.random() * 80 + 10 + '%';
    p.style.top = '75%';
    
    const tx = (Math.random() - 0.5) * 300 + 'px';
    const ty = '-' + (Math.random() * 200 + 120) + 'px';
    const rot = Math.floor(Math.random() * 360 + 180) + 'deg';
    p.style.setProperty('--tx', tx);
    p.style.setProperty('--ty', ty);
    p.style.setProperty('--rot', rot);
    
    container.appendChild(p);
    setTimeout(() => p.remove(), 1450);
  }
}

/* ============================================================
   bet1x — Dynamic Signup/Login Modal, Splash, and Toasts
   ============================================================ */

window.switchAuthTab = function(tab) {
  const loginTab = document.getElementById('tab-login');
  const signupTab = document.getElementById('tab-signup');
  const loginForm = document.getElementById('form-login');
  const signupForm = document.getElementById('form-signup');
  
  if (tab === 'login') {
    loginTab.classList.add('active');
    signupTab.classList.remove('active');
    loginForm.classList.add('active');
    signupForm.classList.remove('active');
  } else {
    loginTab.classList.remove('active');
    signupTab.classList.add('active');
    loginForm.classList.remove('active');
    signupForm.classList.add('active');
  }
  
  document.getElementById('login-error').style.display = 'none';
  document.getElementById('signup-error').style.display = 'none';
};

window.handleAuthSubmit = function(e, type) {
  e.preventDefault();
  
  if (window.isOfflineMode) {
    // Offline local storage fallback
    if (type === 'login') {
      const userInp = document.getElementById('login-username').value.trim();
      const passInp = document.getElementById('login-password').value;
      const errEl = document.getElementById('login-error');
      
      const users = getUsers();
      const matched = users.find(u => u.username.toLowerCase() === userInp.toLowerCase() && u.password === passInp);
      
      if (matched) {
        localStorage.setItem(CURRENT_USER_KEY, JSON.stringify({
          username: matched.username,
          email: matched.email
        }));
        localStorage.setItem(WALLET_KEY, (parseFloat(matched.wallet) || STARTING_BALANCE).toFixed(2));
        closeAuthModal();
        showToast(`Welcome back, ${matched.username}!`, 'success');
        updateNavbarAuth();
        setTimeout(() => { location.reload(); }, 800);
      } else {
        errEl.textContent = 'Incorrect username or password.';
        errEl.style.display = 'block';
      }
    } else {
      const userInp = document.getElementById('signup-username').value.trim();
      const passInp = document.getElementById('signup-password').value;
      const confirmPassInp = document.getElementById('signup-confirm-password').value;
      const errEl = document.getElementById('signup-error');
      
      if (passInp !== confirmPassInp) {
        errEl.textContent = 'Passwords do not match.';
        errEl.style.display = 'block';
        return;
      }
      
      const users = getUsers();
      const exists = users.some(u => u.username.toLowerCase() === userInp.toLowerCase());
      
      if (exists) {
        errEl.textContent = 'Username already exists.';
        errEl.style.display = 'block';
        return;
      }
      
      const newUser = {
        username: userInp,
        email: `${userInp.toLowerCase()}@bet1x.com`,
        password: passInp,
        wallet: STARTING_BALANCE
      };
      users.push(newUser);
      saveUsers(users);
      
      localStorage.setItem(CURRENT_USER_KEY, JSON.stringify({
        username: userInp,
        email: `${userInp.toLowerCase()}@bet1x.com`
      }));
      localStorage.setItem(WALLET_KEY, STARTING_BALANCE.toFixed(2));
      
      closeAuthModal();
      showToast(`Account created successfully! Welcome, ${userInp}!`, 'success');
      updateNavbarAuth();
      setTimeout(() => { location.reload(); }, 800);
    }
    return;
  }

  const prefix = getApiPrefix();
  
  if (type === 'login') {
    const userInp = document.getElementById('login-username').value.trim();
    const passInp = document.getElementById('login-password').value;
    const errEl = document.getElementById('login-error');
    const submitBtn = e.target ? e.target.querySelector('button[type="submit"]') : null;
    if (submitBtn) submitBtn.disabled = true;
    
    const body = new URLSearchParams();
    body.append('username', userInp);
    body.append('password', passInp);
    
    fetch(prefix + 'api/auth.php?action=login', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: body
    })
    .then(res => res.json())
    .then(data => {
      if (submitBtn) submitBtn.disabled = false;
      if (data.success && data.user) {
        if (data.token) localStorage.setItem(AUTH_TOKEN_KEY, data.token);
        localStorage.setItem(CURRENT_USER_KEY, JSON.stringify(data.user));
        localStorage.setItem(WALLET_KEY, walletFromServer(data.user.wallet_balance).toFixed(2));
        closeAuthModal();
        if (window.SoundFX) SoundFX.play('login');
        showToast(`Welcome back, ${data.user.username}!`, 'success');
        updateAuthHeaderUI();
        setTimeout(() => { location.reload(); }, 600);
      } else {
        errEl.textContent = data.error || 'Incorrect username or password.';
        errEl.style.display = 'block';
      }
    })
    .catch(err => {
      if (submitBtn) submitBtn.disabled = false;
      console.warn("Login API error:", err);
      // Ask for the offline fallback, but only retry if it was actually granted. The setter refuses
      // on any deployment that has not opted in, and retrying regardless simply ran the same failing
      // request again — an unbounded loop that hammered the server and left the player staring at a
      // form that never responded.
      window.isOfflineMode = true;
      if (window.isOfflineMode) {
        window.handleAuthSubmit(e, type);
        return;
      }
      errEl.textContent = 'Cannot reach the game server. Please check your connection and try again.';
      errEl.style.display = 'block';
    });
  } else {
    const userInp = document.getElementById('signup-username').value.trim();
    const passInp = document.getElementById('signup-password').value;
    const confirmPassInp = document.getElementById('signup-confirm-password').value;
    const errEl = document.getElementById('signup-error');
    const submitBtn = e.target ? e.target.querySelector('button[type="submit"]') : null;
    
    if (passInp !== confirmPassInp) {
      errEl.textContent = 'Passwords do not match.';
      errEl.style.display = 'block';
      return;
    }

    if (passInp.length < 8) {
      errEl.textContent = 'Password must be at least 8 characters.';
      errEl.style.display = 'block';
      return;
    }

    // Phone number: plain contact info, collected but not OTP-verified (see the note in
    // wireSignupVerification below for why).
    const phoneVal = normaliseMobileInput(document.getElementById('signup-phone').value);
    if (!phoneVal) {
      errEl.textContent = 'Enter a valid 10-digit Indian mobile number.';
      errEl.style.display = 'block';
      return;
    }

    // Email, verified via OTP when the deployment has it switched on. The address is sent so the
    // server can match it against the verification it recorded; the server decides, not this check,
    // which only saves a round trip and gives a clearer message than a rejected signup would.
    const emailVal = document.getElementById('signup-email').value.trim();
    if (!isValidEmail(emailVal)) {
      errEl.textContent = 'Enter a valid email address.';
      errEl.style.display = 'block';
      return;
    }
    if (window.bet1xEmailVerify && window.bet1xEmailVerify.enabled) {
      if (!window.bet1xEmailVerify.verified ||
          window.bet1xEmailVerify.verifiedEmail !== emailVal.toLowerCase()) {
        errEl.textContent = 'Please verify your email address first.';
        errEl.style.display = 'block';
        return;
      }
    }

    if (submitBtn) submitBtn.disabled = true;

    const body = new URLSearchParams();
    body.append('username', userInp);
    body.append('email', emailVal);
    body.append('phone', phoneVal);
    body.append('password', passInp);
    body.append('confirm_password', confirmPassInp);

    fetch(prefix + 'api/auth.php?action=signup', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: body
    })
    .then(res => res.json())
    .then(data => {
      if (submitBtn) submitBtn.disabled = false;
      if (data.success && data.user) {
        if (data.token) localStorage.setItem(AUTH_TOKEN_KEY, data.token);
        localStorage.setItem(CURRENT_USER_KEY, JSON.stringify(data.user));
        localStorage.setItem(WALLET_KEY, walletFromServer(data.user.wallet_balance).toFixed(2));
        closeAuthModal();
        if (window.SoundFX) SoundFX.play('login');
        showToast(`Account created successfully! Welcome, ${data.user.username}!`, 'success');
        updateAuthHeaderUI();
        setTimeout(() => { location.reload(); }, 600);
      } else {
        errEl.textContent = data.error || 'Registration failed.';
        errEl.style.display = 'block';
      }
    })
    .catch(err => {
      if (submitBtn) submitBtn.disabled = false;
      console.warn("Signup API error:", err);
      // Ask for the offline fallback, but only retry if it was actually granted. The setter refuses
      // on any deployment that has not opted in, and retrying regardless simply ran the same failing
      // request again — an unbounded loop that hammered the server and left the player staring at a
      // form that never responded.
      window.isOfflineMode = true;
      if (window.isOfflineMode) {
        window.handleAuthSubmit(e, type);
        return;
      }
      errEl.textContent = 'Cannot reach the game server. Please check your connection and try again.';
      errEl.style.display = 'block';
    });
  }
};

window.openAuthModal = function(tab = 'login') {
  injectAuthModal();
  const overlay = document.getElementById('bet1x-auth-modal');
  if (overlay) {
    switchAuthTab(tab);
    overlay.classList.add('active');
    if (window.SoundFX) SoundFX.play('modalOpen');
  }
};

window.closeAuthModal = function() {
  const overlay = document.getElementById('bet1x-auth-modal');
  if (overlay) {
    overlay.classList.remove('active');
    if (window.SoundFX) SoundFX.play('modalClose');
  }
};

window.showToast = function(msg, type = 'success') {
  if (window.SoundFX) {
    if (type === 'success') SoundFX.play('success');
    else if (type === 'error' || type === 'danger') SoundFX.play('error');
    else SoundFX.play('notification');
  }

  let container = document.getElementById('toast-container');
  if (!container) {
    container = document.createElement('div');
    container.id = 'toast-container';
    container.className = 'toast-container';
    document.body.appendChild(container);
  }
  
  const toast = document.createElement('div');
  toast.className = `toast ${type}`;

  // The message is assigned as TEXT, not markup. Callers routinely interpolate values that came
  // from a user — "Welcome back, ${username}!", "Detonated ${username}'s session" — and this used
  // to be innerHTML, which made every one of those call sites an XSS sink without any of them
  // looking like one. Setting textContent here fixes them all at once and cannot regress when a
  // new caller is added. Toast messages are plain sentences; none of them need markup.
  const msgSpan = document.createElement('span');
  msgSpan.textContent = msg;

  const closeBtn = document.createElement('button');
  closeBtn.className = 'toast-close';
  closeBtn.innerHTML = '&times;';
  closeBtn.addEventListener('click', () => toast.remove());

  toast.appendChild(msgSpan);
  toast.appendChild(closeBtn);
  container.appendChild(toast);
  
  setTimeout(() => {
    toast.style.opacity = '0';
    toast.style.transform = 'translateY(-10px)';
    setTimeout(() => toast.remove(), 300);
  }, 4000);
};

window.toggleUserDropdown = function(e) {
  if (e) e.stopPropagation();
  const dropdown = document.getElementById('navUserDropdown');
  const chevron = document.querySelector('.dropdown-chevron');
  if (dropdown) {
    const isActive = dropdown.classList.contains('active');
    if (!isActive) {
      dropdown.classList.add('active');
      if (chevron) chevron.classList.add('open');
      
      const closeHandler = () => {
        dropdown.classList.remove('active');
        if (chevron) chevron.classList.remove('open');
        document.removeEventListener('click', closeHandler);
      };
      
      setTimeout(() => {
        document.addEventListener('click', closeHandler);
      }, 50);
    } else {
      dropdown.classList.remove('active');
      if (chevron) chevron.classList.remove('open');
    }
  }
};

window.logoutUser = function() {
  if (window.SoundFX) SoundFX.play('logout');
  if (window.isOfflineMode) {
    localStorage.removeItem(CURRENT_USER_KEY);
    localStorage.removeItem(WALLET_KEY);
    showToast('Logged out successfully!', 'info');
    setTimeout(() => { location.reload(); }, 800);
    return;
  }

  const prefix = getApiPrefix();
  fetch(prefix + 'api/auth.php?action=logout')
  .then(() => {
    localStorage.removeItem(CURRENT_USER_KEY);
    localStorage.removeItem(WALLET_KEY);
    showToast('Logged out successfully!', 'info');
    setTimeout(() => { location.reload(); }, 800);
  });
};

window.resetDemoWallet = function() {
  if (window.isOfflineMode) {
    localStorage.setItem(WALLET_KEY, STARTING_BALANCE.toFixed(2));
    showToast('Virtual balance reset to ₹' + STARTING_BALANCE.toLocaleString('en-IN', { minimumFractionDigits: 2 }), 'success');
    renderWalletChips();
    return;
  }
  
  const prefix = getApiPrefix();
  fetch(prefix + 'api/wallet.php?action=reset_wallet')
  .then(res => {
    if (!res.ok) throw new Error("HTTP error");
    return res.json();
  })
  .then(data => {
    if (data.success) {
      localStorage.setItem(WALLET_KEY, parseFloat(data.balance).toFixed(2));
      showToast('Virtual balance reset to ₹' + parseFloat(data.balance).toLocaleString('en-IN', { minimumFractionDigits: 2 }), 'success');
      renderWalletChips();
    } else {
      resetDemo();
    }
  })
  .catch(() => {
    resetDemo();
  });
};

/* ============================================================
   Signup verification: phone (plain field) + email (OTP)
   ============================================================
   Phone used to be the OTP-gated channel; it is now collected as plain contact info only — see the
   comment above the phone block in routes/gamesync.php's signup handler for why (Fast2SMS's OTP
   route needs account-side enablement that is still pending). Email has taken over as the verified
   channel, sent via Brevo. Everything below the state object is a convenience layer, not a security
   boundary: the server records the verification itself and re-checks it when the account is
   created, so a player who flips bet1xEmailVerify.verified in the console gets a rejected signup,
   not a free account.
   ------------------------------------------------------------ */

window.bet1xEmailVerify = {
  enabled: false,        // what /api/health reported; until it answers, OTP controls stay hidden
  probed: false,         // the probe runs once per page load, not once per modal open
  verified: false,       // this browser saw /api/email-otp/verify succeed
  verifiedEmail: '',     // for exactly this address — a later edit invalidates it
  cooldownTimer: null
};

// Digits only, and never more than the last ten of them.
//
// Taking the LAST ten rather than the first is what makes a pasted +91 98765 43210 or 09876543210
// come out as 9876543210 instead of 9198765432 — the country code and the trunk 0 both sit in
// front, so keeping the leading ten is exactly the wrong end to keep.
function trimMobileDigits(raw) {
  const digits = String(raw || '').replace(/\D/g, '');
  return digits.length > 10 ? digits.slice(-10) : digits;
}

// Accepts the shapes people actually type — 9876543210, 09876543210, +919876543210 — and returns
// the bare ten digits, or null. Mirrors sms_normalise_indian_mobile() on the server, which is the
// one that counts; this copy only exists to fail fast without spending a request.
function normaliseMobileInput(raw) {
  const local = trimMobileDigits(raw);
  return /^[6-9]\d{9}$/.test(local) ? local : null;
}

// Mirrors the email regex used server-side; this copy only exists to fail fast without a request.
function isValidEmail(raw) {
  return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(String(raw || '').trim());
}

function setEmailOtpStatus(msg, kind) {
  const el = document.getElementById('signup-email-otp-status');
  if (!el) return;
  el.textContent = msg || '';
  el.style.color = kind === 'error'   ? '#ff6b6b'
                 : kind === 'success' ? '#2ecc71'
                 : 'var(--text-dim)';
}

function showSignupError(msg) {
  const errEl = document.getElementById('signup-error');
  if (!errEl) return;
  errEl.textContent = msg;
  errEl.style.display = 'block';
}

// Ticks the resend button down so the player can see when retrying is worth it, instead of pressing
// a dead button and being told off by the per-email cooldown.
function startEmailOtpCooldown(seconds) {
  const btn = document.getElementById('signup-email-otp-send');
  if (!btn) return;
  const state = window.bet1xEmailVerify;
  if (state.cooldownTimer) clearInterval(state.cooldownTimer);
  let left = Math.max(0, parseInt(seconds, 10) || 0);
  if (left === 0) { btn.disabled = false; btn.textContent = 'Send OTP'; return; }
  btn.disabled = true;
  btn.textContent = 'Resend ' + left + 's';
  state.cooldownTimer = setInterval(() => {
    left -= 1;
    if (left <= 0) {
      clearInterval(state.cooldownTimer);
      state.cooldownTimer = null;
      btn.disabled = false;
      btn.textContent = 'Resend OTP';
    } else {
      btn.textContent = 'Resend ' + left + 's';
    }
  }, 1000);
}

window.sendSignupEmailOtp = function () {
  const input = document.getElementById('signup-email');
  const btn = document.getElementById('signup-email-otp-send');
  if (!input || !btn) return;

  const email = input.value.trim();
  if (!isValidEmail(email)) {
    showSignupError('Enter a valid email address.');
    return;
  }
  document.getElementById('signup-error').style.display = 'none';
  btn.disabled = true;
  btn.textContent = 'Sending...';

  fetch(getApiPrefix() + 'api/email-otp/send', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ email: email })
  })
    .then(res => res.json().then(data => ({ ok: res.ok, data })))
    .then(result => {
      const data = result.data || {};
      if (!result.ok || !data.success) {
        btn.disabled = false;
        btn.textContent = 'Send OTP';
        showSignupError(data.error || 'Could not send the verification code.');
        // A 429 carries the seconds left, so honour it rather than letting them hammer the button.
        if (data.retry_after) startEmailOtpCooldown(data.retry_after);
        return;
      }
      document.getElementById('signup-email-otp-group').style.display = '';
      const otpInput = document.getElementById('signup-email-otp');
      if (otpInput) { otpInput.value = ''; otpInput.focus(); }
      setEmailOtpStatus('Code sent to ' + email + '. It expires in '
                   + Math.max(1, Math.round((data.expires_in || 300) / 60))
                   + ' minutes. Not in your inbox? Check your Spam/Junk folder.');
      startEmailOtpCooldown(data.retry_after || 60);
    })
    .catch(err => {
      console.warn('Email OTP send error:', err);
      btn.disabled = false;
      btn.textContent = 'Send OTP';
      showSignupError('Cannot reach the server. Please check your connection and try again.');
    });
};

window.verifySignupEmailOtp = function () {
  const emailInput = document.getElementById('signup-email');
  const otpInput = document.getElementById('signup-email-otp');
  const btn = document.getElementById('signup-email-otp-verify');
  if (!emailInput || !otpInput || !btn) return;

  const email = emailInput.value.trim();
  if (!isValidEmail(email)) { setEmailOtpStatus('Enter a valid email address.', 'error'); return; }
  const code = otpInput.value.trim();
  if (!code) { setEmailOtpStatus('Enter the code that was sent to your email.', 'error'); return; }

  btn.disabled = true;
  btn.textContent = 'Checking...';
  setEmailOtpStatus('Checking the code...');

  fetch(getApiPrefix() + 'api/email-otp/verify', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ email: email, otp: code })
  })
    .then(res => res.json().then(data => ({ ok: res.ok, data })))
    .then(result => {
      const data = result.data || {};
      btn.disabled = false;
      btn.textContent = 'Verify';
      if (!result.ok || !data.verified) {
        setEmailOtpStatus(data.error || 'That code is not correct.', 'error');
        return;
      }
      const state = window.bet1xEmailVerify;
      state.verified = true;
      state.verifiedEmail = email.toLowerCase();
      setEmailOtpStatus('Email verified. You can create your account now.', 'success');
      btn.disabled = true;
      otpInput.disabled = true;
      const sendBtn = document.getElementById('signup-email-otp-send');
      if (sendBtn) {
        if (state.cooldownTimer) { clearInterval(state.cooldownTimer); state.cooldownTimer = null; }
        sendBtn.disabled = true;
        sendBtn.textContent = 'Verified';
      }
    })
    .catch(err => {
      console.warn('Email OTP verify error:', err);
      btn.disabled = false;
      btn.textContent = 'Verify';
      setEmailOtpStatus('Cannot reach the server. Please check your connection and try again.', 'error');
    });
};

// Editing the address after verifying has to throw the verification away — otherwise the form would
// happily submit a freshly typed address carrying the previous one's approval. The server would
// reject it anyway; catching it here just explains why.
function resetEmailVerification() {
  const state = window.bet1xEmailVerify;
  const emailEl = document.getElementById('signup-email');
  if (!emailEl) return;
  const email = emailEl.value.trim().toLowerCase();
  if (!state.verified || email === state.verifiedEmail) return;

  state.verified = false;
  state.verifiedEmail = '';
  const otpGroup = document.getElementById('signup-email-otp-group');
  const otpInput = document.getElementById('signup-email-otp');
  const sendBtn = document.getElementById('signup-email-otp-send');
  const verifyBtn = document.getElementById('signup-email-otp-verify');
  if (otpGroup) otpGroup.style.display = 'none';
  if (otpInput) { otpInput.disabled = false; otpInput.value = ''; }
  if (verifyBtn) { verifyBtn.disabled = false; verifyBtn.textContent = 'Verify'; }
  if (sendBtn) { sendBtn.disabled = false; sendBtn.textContent = 'Send OTP'; }
  setEmailOtpStatus('');
}

// Asks the backend whether the signup form should gate on an email OTP at all. If the probe fails
// the OTP controls stay hidden and signup behaves as if the flag were off; should verification
// actually be on, the server rejects that signup with its own message, which is the correct place
// for the decision.
function probeEmailVerification() {
  const state = window.bet1xEmailVerify;
  if (state.probed) { applyEmailVerificationVisibility(); return; }
  state.probed = true;

  fetch(getApiPrefix() + 'api/health')
    .then(res => res.json())
    .then(data => {
      state.enabled = !!(data && data.email_verification);
      applyEmailVerificationVisibility();
    })
    .catch(err => {
      console.warn('Email-verification probe failed, leaving signup unchanged:', err);
    });
}

function applyEmailVerificationVisibility() {
  const sendBtn = document.getElementById('signup-email-otp-send');
  if (sendBtn) sendBtn.style.display = window.bet1xEmailVerify.enabled ? '' : 'none';
  if (!window.bet1xEmailVerify.enabled) {
    const otpGroup = document.getElementById('signup-email-otp-group');
    if (otpGroup) otpGroup.style.display = 'none';
  }
}

window.generateSignupUsername = function () {
  const btn = document.getElementById('signup-generate-username');
  const input = document.getElementById('signup-username');
  if (!btn || !input) return;
  btn.disabled = true;
  const prevText = btn.textContent;
  btn.textContent = '...';

  fetch(getApiPrefix() + 'api/auth/generate-username')
    .then(res => res.json())
    .then(data => {
      if (data && data.username) {
        input.value = data.username;
      } else {
        showSignupError((data && data.error) || 'Could not generate a username. Please try again.');
      }
    })
    .catch(err => {
      console.warn('Generate-username error:', err);
      showSignupError('Cannot reach the server. Please check your connection and try again.');
    })
    .then(() => { btn.disabled = false; btn.textContent = prevText; });
};

function wireSignupVerification() {
  // Phone: a plain field now, just digit-formatted as the player types — no OTP wiring.
  const phoneInput = document.getElementById('signup-phone');
  if (phoneInput) {
    phoneInput.addEventListener('input', () => {
      phoneInput.value = trimMobileDigits(phoneInput.value);
    });
  }

  // Email OTP.
  const sendBtn = document.getElementById('signup-email-otp-send');
  const verifyBtn = document.getElementById('signup-email-otp-verify');
  const emailInput = document.getElementById('signup-email');
  const otpInput = document.getElementById('signup-email-otp');
  if (sendBtn) sendBtn.addEventListener('click', window.sendSignupEmailOtp);
  if (verifyBtn) verifyBtn.addEventListener('click', window.verifySignupEmailOtp);
  if (emailInput) emailInput.addEventListener('input', resetEmailVerification);
  if (otpInput) {
    otpInput.addEventListener('input', () => {
      otpInput.value = otpInput.value.replace(/\D/g, '').slice(0, 8);
    });
    // Enter inside the code field should verify, not submit a form that is not ready yet.
    otpInput.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') { e.preventDefault(); window.verifySignupEmailOtp(); }
    });
  }
  probeEmailVerification();

  // "Generate a username" button, for a player who would rather not think one up.
  const genBtn = document.getElementById('signup-generate-username');
  if (genBtn) genBtn.addEventListener('click', window.generateSignupUsername);
}

/* ============================================================
   Forgot password
   ============================================================
   Reuses the same email-OTP shape as signup (send -> verify -> act), against
   /api/auth/forgot-password/{send,verify} and /api/auth/reset-password. Available
   unconditionally — unlike signup's email step, this is not gated behind /api/health's
   email_verification flag, since account recovery shouldn't depend on that switch.
   ------------------------------------------------------------ */

window.bet1xForgotPassword = {
  verified: false,       // this browser saw /api/auth/forgot-password/verify succeed
  verifiedEmail: '',     // for exactly this address — a later edit invalidates it
  cooldownTimer: null
};

function setForgotStatus(msg, kind) {
  const el = document.getElementById('forgot-otp-status');
  if (!el) return;
  el.textContent = msg || '';
  el.style.color = kind === 'error'   ? '#ff6b6b'
                 : kind === 'success' ? '#2ecc71'
                 : 'var(--text-dim)';
}

function showForgotError(msg) {
  const el = document.getElementById('forgot-error');
  if (!el) return;
  el.textContent = msg;
  el.style.display = 'block';
}

function startForgotOtpCooldown(seconds) {
  const btn = document.getElementById('forgot-otp-send');
  if (!btn) return;
  const state = window.bet1xForgotPassword;
  if (state.cooldownTimer) clearInterval(state.cooldownTimer);
  let left = Math.max(0, parseInt(seconds, 10) || 0);
  if (left === 0) { btn.disabled = false; btn.textContent = 'Send Code'; return; }
  btn.disabled = true;
  btn.textContent = 'Resend ' + left + 's';
  state.cooldownTimer = setInterval(() => {
    left -= 1;
    if (left <= 0) {
      clearInterval(state.cooldownTimer);
      state.cooldownTimer = null;
      btn.disabled = false;
      btn.textContent = 'Resend Code';
    } else {
      btn.textContent = 'Resend ' + left + 's';
    }
  }, 1000);
}

// Resets the whole panel to its just-opened state — used both when the modal opens it and after a
// successful reset, so a second attempt (or a re-open) never carries a stale email's progress.
function resetForgotPasswordForm() {
  const state = window.bet1xForgotPassword;
  if (state.cooldownTimer) { clearInterval(state.cooldownTimer); state.cooldownTimer = null; }
  state.verified = false;
  state.verifiedEmail = '';

  const emailInput = document.getElementById('forgot-email');
  const otpInput = document.getElementById('forgot-otp');
  const newPassInput = document.getElementById('forgot-new-password');
  const confirmPassInput = document.getElementById('forgot-confirm-password');
  if (emailInput) emailInput.value = '';
  if (otpInput) { otpInput.value = ''; otpInput.disabled = false; }
  if (newPassInput) newPassInput.value = '';
  if (confirmPassInput) confirmPassInput.value = '';

  const sendBtn = document.getElementById('forgot-otp-send');
  const verifyBtn = document.getElementById('forgot-otp-verify');
  const resetBtn = document.getElementById('forgot-reset-btn');
  if (sendBtn) { sendBtn.disabled = false; sendBtn.textContent = 'Send Code'; }
  if (verifyBtn) { verifyBtn.disabled = false; verifyBtn.textContent = 'Verify'; }
  if (resetBtn) resetBtn.style.display = 'none';

  document.getElementById('forgot-otp-group').style.display = 'none';
  document.getElementById('forgot-newpass-group').style.display = 'none';
  document.getElementById('forgot-confirmpass-group').style.display = 'none';
  const errEl = document.getElementById('forgot-error');
  if (errEl) errEl.style.display = 'none';
  setForgotStatus('');
}

window.showForgotPasswordForm = function () {
  resetForgotPasswordForm();
  const tabsBar = document.getElementById('auth-tabs-bar');
  const loginForm = document.getElementById('form-login');
  const signupForm = document.getElementById('form-signup');
  const forgotForm = document.getElementById('form-forgot-password');
  if (tabsBar) tabsBar.style.display = 'none';
  if (loginForm) loginForm.classList.remove('active');
  if (signupForm) signupForm.classList.remove('active');
  if (forgotForm) forgotForm.classList.add('active');
};

window.showLoginFormFromForgot = function () {
  const tabsBar = document.getElementById('auth-tabs-bar');
  const forgotForm = document.getElementById('form-forgot-password');
  if (forgotForm) forgotForm.classList.remove('active');
  if (tabsBar) tabsBar.style.display = '';
  switchAuthTab('login');
};

window.sendForgotOtp = function () {
  const input = document.getElementById('forgot-email');
  const btn = document.getElementById('forgot-otp-send');
  if (!input || !btn) return;

  const email = input.value.trim();
  if (!isValidEmail(email)) {
    showForgotError('Enter a valid email address.');
    return;
  }
  document.getElementById('forgot-error').style.display = 'none';
  btn.disabled = true;
  btn.textContent = 'Sending...';

  fetch(getApiPrefix() + 'api/auth/forgot-password/send', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ email: email })
  })
    .then(res => res.json().then(data => ({ ok: res.ok, data })))
    .then(result => {
      const data = result.data || {};
      if (!result.ok || !data.success) {
        btn.disabled = false;
        btn.textContent = 'Send Code';
        showForgotError(data.error || 'Could not send the verification code.');
        if (data.retry_after) startForgotOtpCooldown(data.retry_after);
        return;
      }
      document.getElementById('forgot-otp-group').style.display = '';
      const otpInput = document.getElementById('forgot-otp');
      if (otpInput) { otpInput.value = ''; otpInput.focus(); }
      // The backend answers identically whether or not the email has an account, so the message
      // here has to stay just as non-committal — it cannot say "code sent" as fact.
      setForgotStatus('If that email is registered, a code has been sent. It expires in a few '
                     + 'minutes. Not in your inbox? Check your Spam/Junk folder.');
      startForgotOtpCooldown(60);
    })
    .catch(err => {
      console.warn('Forgot-password OTP send error:', err);
      btn.disabled = false;
      btn.textContent = 'Send Code';
      showForgotError('Cannot reach the server. Please check your connection and try again.');
    });
};

window.verifyForgotOtp = function () {
  const emailInput = document.getElementById('forgot-email');
  const otpInput = document.getElementById('forgot-otp');
  const btn = document.getElementById('forgot-otp-verify');
  if (!emailInput || !otpInput || !btn) return;

  const email = emailInput.value.trim();
  if (!isValidEmail(email)) { setForgotStatus('Enter a valid email address.', 'error'); return; }
  const code = otpInput.value.trim();
  if (!code) { setForgotStatus('Enter the code that was sent to your email.', 'error'); return; }

  btn.disabled = true;
  btn.textContent = 'Checking...';
  setForgotStatus('Checking the code...');

  fetch(getApiPrefix() + 'api/auth/forgot-password/verify', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ email: email, otp: code })
  })
    .then(res => res.json().then(data => ({ ok: res.ok, data })))
    .then(result => {
      const data = result.data || {};
      btn.disabled = false;
      btn.textContent = 'Verify';
      if (!result.ok || !data.verified) {
        setForgotStatus(data.error || 'That code is not correct.', 'error');
        return;
      }
      const state = window.bet1xForgotPassword;
      state.verified = true;
      state.verifiedEmail = email.toLowerCase();
      setForgotStatus('Email verified. Choose a new password below.', 'success');
      btn.disabled = true;
      otpInput.disabled = true;
      const sendBtn = document.getElementById('forgot-otp-send');
      if (sendBtn) {
        if (state.cooldownTimer) { clearInterval(state.cooldownTimer); state.cooldownTimer = null; }
        sendBtn.disabled = true;
        sendBtn.textContent = 'Verified';
      }
      document.getElementById('forgot-newpass-group').style.display = '';
      document.getElementById('forgot-confirmpass-group').style.display = '';
      const resetBtn = document.getElementById('forgot-reset-btn');
      if (resetBtn) resetBtn.style.display = '';
      const newPassInput = document.getElementById('forgot-new-password');
      if (newPassInput) newPassInput.focus();
    })
    .catch(err => {
      console.warn('Forgot-password OTP verify error:', err);
      btn.disabled = false;
      btn.textContent = 'Verify';
      setForgotStatus('Cannot reach the server. Please check your connection and try again.', 'error');
    });
};

window.submitPasswordReset = function () {
  const state = window.bet1xForgotPassword;
  const emailInput = document.getElementById('forgot-email');
  const newPassInput = document.getElementById('forgot-new-password');
  const confirmPassInput = document.getElementById('forgot-confirm-password');
  const btn = document.getElementById('forgot-reset-btn');
  if (!emailInput || !newPassInput || !confirmPassInput || !btn) return;

  const email = emailInput.value.trim();
  const errEl = document.getElementById('forgot-error');
  errEl.style.display = 'none';

  if (!state.verified || state.verifiedEmail !== email.toLowerCase()) {
    showForgotError('Please verify your email first.');
    return;
  }
  if (newPassInput.value.length < 8) {
    showForgotError('Password must be at least 8 characters.');
    return;
  }
  if (newPassInput.value !== confirmPassInput.value) {
    showForgotError('Passwords do not match.');
    return;
  }

  btn.disabled = true;
  btn.textContent = 'Resetting...';

  fetch(getApiPrefix() + 'api/auth/reset-password', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      email: email,
      password: newPassInput.value,
      confirm_password: confirmPassInput.value
    })
  })
    .then(res => res.json().then(data => ({ ok: res.ok, data })))
    .then(result => {
      const data = result.data || {};
      if (!result.ok || !data.success || !data.user) {
        btn.disabled = false;
        btn.textContent = 'Reset Password';
        showForgotError(data.error || 'Could not reset the password.');
        return;
      }
      // Same post-success shape as login/signup: store the session, close the modal, reload.
      if (data.token) localStorage.setItem(AUTH_TOKEN_KEY, data.token);
      localStorage.setItem(CURRENT_USER_KEY, JSON.stringify(data.user));
      localStorage.setItem(WALLET_KEY, walletFromServer(data.user.wallet_balance).toFixed(2));
      resetForgotPasswordForm();
      closeAuthModal();
      if (window.SoundFX) SoundFX.play('login');
      showToast(`Password reset. Welcome back, ${data.user.username}!`, 'success');
      updateAuthHeaderUI();
      setTimeout(() => { location.reload(); }, 600);
    })
    .catch(err => {
      console.warn('Reset-password error:', err);
      btn.disabled = false;
      btn.textContent = 'Reset Password';
      showForgotError('Cannot reach the server. Please check your connection and try again.');
    });
};

function wireForgotPassword() {
  const showLink = document.getElementById('show-forgot-password');
  const backLink = document.getElementById('show-login-from-forgot');
  if (showLink) showLink.addEventListener('click', (e) => { e.preventDefault(); window.showForgotPasswordForm(); });
  if (backLink) backLink.addEventListener('click', (e) => { e.preventDefault(); window.showLoginFormFromForgot(); });

  const sendBtn = document.getElementById('forgot-otp-send');
  const verifyBtn = document.getElementById('forgot-otp-verify');
  const resetBtn = document.getElementById('forgot-reset-btn');
  const emailInput = document.getElementById('forgot-email');
  const otpInput = document.getElementById('forgot-otp');
  if (sendBtn) sendBtn.addEventListener('click', window.sendForgotOtp);
  if (verifyBtn) verifyBtn.addEventListener('click', window.verifyForgotOtp);
  if (resetBtn) resetBtn.addEventListener('click', window.submitPasswordReset);
  if (emailInput) {
    emailInput.addEventListener('input', () => {
      const state = window.bet1xForgotPassword;
      if (state.verified && emailInput.value.trim().toLowerCase() !== state.verifiedEmail) {
        resetForgotPasswordForm();
        emailInput.focus();
      }
    });
  }
  if (otpInput) {
    otpInput.addEventListener('input', () => {
      otpInput.value = otpInput.value.replace(/\D/g, '').slice(0, 8);
    });
    otpInput.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') { e.preventDefault(); window.verifyForgotOtp(); }
    });
  }
}

function injectAuthModal() {
  if (document.getElementById('bet1x-auth-modal')) return;
  const modal = document.createElement('div');
  modal.id = 'bet1x-auth-modal';
  modal.className = 'auth-modal-overlay';
  modal.innerHTML = `
    <div class="auth-modal-card">
      <button class="auth-modal-close" onclick="closeAuthModal()">&times;</button>
      <div class="auth-tabs" id="auth-tabs-bar">
        <button class="auth-tab active" id="tab-login" onclick="switchAuthTab('login')">Log In</button>
        <button class="auth-tab" id="tab-signup" onclick="switchAuthTab('signup')">Sign Up</button>
      </div>

      <!-- Login Form -->
      <form id="form-login" class="auth-form active" onsubmit="handleAuthSubmit(event, 'login')">
        <div class="auth-form-group">
          <label for="login-username">Username</label>
          <input type="text" id="login-username" placeholder="Enter username" required autocomplete="username">
        </div>
        <div class="auth-form-group">
          <label for="login-password">Password</label>
          <input type="password" id="login-password" placeholder="Enter password" required autocomplete="current-password">
        </div>
        <div id="login-error" class="auth-error-msg">Incorrect username or password.</div>
        <div style="text-align:right; margin-top:6px;">
          <a href="#" id="show-forgot-password" style="font-size:12.5px; color:var(--text-dim);">Forgot password?</a>
        </div>
        <button type="submit" class="btn btn-primary btn-block" style="margin-top: 10px; color: #000; font-weight:700;">Sign In</button>
      </form>
      
      <!-- Signup Form -->
      <form id="form-signup" class="auth-form" onsubmit="handleAuthSubmit(event, 'signup')">
        <div class="auth-form-group">
          <label for="signup-username">Username</label>
          <div style="display:flex; gap:8px;">
            <input type="text" id="signup-username" placeholder="Choose a username" required
                   autocomplete="username" style="flex:1;">
            <button type="button" id="signup-generate-username" class="btn btn-ghost"
                    style="white-space:nowrap; padding:0 14px;" title="Generate a unique username for me">Generate</button>
          </div>
        </div>
        <div class="auth-form-group">
          <label for="signup-email">Email Address</label>
          <div style="display:flex; gap:8px;">
            <input type="email" id="signup-email" placeholder="you@example.com" required
                   autocomplete="email" style="flex:1;">
            <!-- Hidden until /api/health reports email_verification on, so the form is exactly as
                 it was (no OTP step) with the feature off. -->
            <button type="button" id="signup-email-otp-send" class="btn btn-ghost"
                    style="white-space:nowrap; padding:0 14px; display:none;">Send OTP</button>
          </div>
        </div>
        <div class="auth-form-group" id="signup-email-otp-group" style="display:none;">
          <label for="signup-email-otp">Enter OTP</label>
          <div style="display:flex; gap:8px;">
            <input type="text" id="signup-email-otp" placeholder="6-digit code" inputmode="numeric"
                   maxlength="8" autocomplete="one-time-code" style="flex:1;">
            <button type="button" id="signup-email-otp-verify" class="btn btn-ghost"
                    style="white-space:nowrap; padding:0 14px;">Verify</button>
          </div>
          <div id="signup-email-otp-status" style="font-size:12px; margin-top:6px; color:var(--text-dim);"></div>
        </div>
        <div class="auth-form-group">
          <label for="signup-phone">Mobile Number</label>
          <!-- maxlength is 16, not 10, so that pasting "+91 98765 43210" is not truncated to
               "+91 98765 " before the input handler can reduce it to the ten digits. -->
          <input type="tel" id="signup-phone" placeholder="10-digit mobile number" required
                 autocomplete="tel" inputmode="numeric" maxlength="16">
        </div>
        <div class="auth-form-group">
          <label for="signup-password">Password</label>
          <input type="password" id="signup-password" placeholder="Create password" required autocomplete="new-password">
        </div>
        <div class="auth-form-group">
          <label for="signup-confirm-password">Confirm Password</label>
          <input type="password" id="signup-confirm-password" placeholder="Confirm password" required autocomplete="new-password">
        </div>

        <div id="signup-error" class="auth-error-msg">Passwords do not match.</div>
        <button type="submit" class="btn btn-primary btn-block" style="margin-top: 10px; color: #000; font-weight:700;">Create Account</button>
      </form>

      <!-- Forgot Password — not part of the tab bar; reached via the link under the login form
           and returns there via "Back to Log In". Three steps revealed in sequence: email -> OTP
           -> new password, mirroring the signup email-OTP flow's own progressive disclosure. -->
      <form id="form-forgot-password" class="auth-form" onsubmit="return false;">
        <div class="auth-form-group">
          <label for="forgot-email">Email Address</label>
          <div style="display:flex; gap:8px;">
            <input type="email" id="forgot-email" placeholder="you@example.com" required
                   autocomplete="email" style="flex:1;">
            <button type="button" id="forgot-otp-send" class="btn btn-ghost"
                    style="white-space:nowrap; padding:0 14px;">Send Code</button>
          </div>
        </div>
        <div class="auth-form-group" id="forgot-otp-group" style="display:none;">
          <label for="forgot-otp">Enter Code</label>
          <div style="display:flex; gap:8px;">
            <input type="text" id="forgot-otp" placeholder="6-digit code" inputmode="numeric"
                   maxlength="8" autocomplete="one-time-code" style="flex:1;">
            <button type="button" id="forgot-otp-verify" class="btn btn-ghost"
                    style="white-space:nowrap; padding:0 14px;">Verify</button>
          </div>
          <div id="forgot-otp-status" style="font-size:12px; margin-top:6px; color:var(--text-dim);"></div>
        </div>
        <div class="auth-form-group" id="forgot-newpass-group" style="display:none;">
          <label for="forgot-new-password">New Password</label>
          <input type="password" id="forgot-new-password" placeholder="Create new password" autocomplete="new-password">
        </div>
        <div class="auth-form-group" id="forgot-confirmpass-group" style="display:none;">
          <label for="forgot-confirm-password">Confirm New Password</label>
          <input type="password" id="forgot-confirm-password" placeholder="Confirm new password" autocomplete="new-password">
        </div>
        <div id="forgot-error" class="auth-error-msg" style="display:none;"></div>
        <button type="button" id="forgot-reset-btn"
                class="btn btn-primary btn-block" style="margin-top:10px; color:#000; font-weight:700; display:none;">Reset Password</button>
        <div style="text-align:center; margin-top:12px;">
          <a href="#" id="show-login-from-forgot" style="font-size:12.5px; color:var(--text-dim);">&larr; Back to Log In</a>
        </div>
      </form>
    </div>
  `;
  document.body.appendChild(modal);

  // The modal is injected once per page, so this is also the right place to hook up the phone and
  // email fields and ask the backend whether email verification should be shown.
  wireSignupVerification();
  wireForgotPassword();
}

function updateNavbarAuth() {
  const currentUser = getCurrentUser();
  const walletChips = document.querySelectorAll('[data-wallet-chip]');
  
  if (currentUser) {
    walletChips.forEach(chip => {
      const navLinks = chip.closest('.nav-links');
      if (navLinks) {
        let userContainer = navLinks.querySelector('.nav-user-container');
        if (!userContainer) {
          chip.style.display = 'none';
          
          userContainer = document.createElement('div');
          userContainer.className = 'nav-user-container';
          userContainer.innerHTML = `
            <span class="wallet-chip" data-wallet-chip>₹ ${getWallet().toLocaleString('en-IN', { minimumFractionDigits: 2 })}</span>
            <div class="user-dropdown-wrapper">
              <button class="nav-user-btn" onclick="toggleUserDropdown(event)">
                <span class="user-avatar-dot"></span>
                <span>${escapeHtml(currentUser.username)}</span>
                <span class="dropdown-chevron">▼</span>
              </button>
              <div class="nav-user-dropdown" id="navUserDropdown">
                <div class="dropdown-header">
                  <div class="dropdown-username">${escapeHtml(currentUser.username)}</div>
                  <div class="dropdown-email">${escapeHtml(currentUser.email || (currentUser.username + '@bet1x.com'))}</div>
                </div>
                <div class="dropdown-divider"></div>
                <a href="cashier.html" class="dropdown-item">💰 Deposit & Withdraw</a>
                <a href="admin.html" class="dropdown-item">Admin Dashboard</a>
                <div class="dropdown-divider"></div>
                <a href="#" class="dropdown-item" onclick="resetDemoWallet(); return false;">Reset Wallet</a>
                <a href="#" class="dropdown-item logout" onclick="logoutUser(); return false;">Log Out</a>
              </div>
            </div>
          `;
          navLinks.appendChild(userContainer);
        }
      }
      
      const topbar = chip.closest('.topbar');
      if (topbar) {
        chip.textContent = '₹ ' + getWallet().toLocaleString('en-IN', { minimumFractionDigits: 2 });
      }
    });
  } else {
    walletChips.forEach(chip => {
      const navLinks = chip.closest('.nav-links');
      if (navLinks) {
        let authButtons = navLinks.querySelector('.nav-auth-buttons');
        if (!authButtons) {
          chip.style.display = 'none';
          authButtons = document.createElement('div');
          authButtons.className = 'nav-auth-buttons';
          authButtons.innerHTML = `
            <button class="btn btn-ghost" style="padding: 6px 12px; font-size: 13px;" onclick="openAuthModal('login')">Log In</button>
            <button class="btn btn-primary" style="padding: 6px 12px; font-size: 13px; color: #000; font-weight:700;" onclick="openAuthModal('signup')">Sign Up</button>
          `;
          navLinks.appendChild(authButtons);
        }
      }
      
      const topbar = chip.closest('.topbar');
      if (topbar) {
        let topbarAuth = topbar.querySelector('.topbar-auth');
        if (!topbarAuth) {
          chip.style.display = 'none';
          topbarAuth = document.createElement('button');
          topbarAuth.className = 'btn btn-primary topbar-auth';
          topbarAuth.style.cssText = 'padding: 6px 12px; font-size: 13px; color: #000; font-weight:700;';
          topbarAuth.textContent = 'Log In / Sign Up';
          topbarAuth.onclick = () => openAuthModal('login');
          topbar.appendChild(topbarAuth);
        }
      }
    });
  }
}

/**
 * Reveal the lobby that index.html's pre-boot script hid.
 *
 * That script adds html.splash-booting before first paint so the page cannot flash into view ahead
 * of the splash. This is the counterpart: every path that ends the splash calls it, so the class is
 * never left on. index.html also runs a 6s watchdog in case this file fails to load at all.
 */
function endSplashBoot() {
  document.documentElement.classList.remove('splash-booting');
}

document.addEventListener('DOMContentLoaded', () => {
  // 1. Inject and animate Splash Screen (only on initial website load in this tab session)
  const isSplashShown = sessionStorage.getItem('bet1x_splash_shown');
  
  if (!isSplashShown) {
    sessionStorage.setItem('bet1x_splash_shown', 'true');
    let splash = document.getElementById('bet1x-splash');
    
    if (!splash) {
      splash = document.createElement('div');
      splash.id = 'bet1x-splash';
      splash.className = 'bet1x-splash-overlay';
      splash.innerHTML = `
        <!-- Glowing background elements -->
        <div class="splash-aurora aurora-1"></div>
        <div class="splash-aurora aurora-2"></div>
        
        <!-- Floating background particles -->
        <div class="splash-particles">
          <span class="sp-particle p1"></span>
          <span class="sp-particle p2"></span>
          <span class="sp-particle p3"></span>
          <span class="sp-particle p4"></span>
          <span class="sp-particle p5"></span>
          <span class="sp-particle p6"></span>
          <span class="sp-particle p7"></span>
          <span class="sp-particle p8"></span>
        </div>

        <div class="bet1x-splash-content">
          <!-- Brand Emblem with rotating dashed ring -->
          <div class="splash-emblem-container">
            <div class="splash-ring"></div>
            <div class="splash-logo-center">
              <span class="splash-dot-core"></span>
            </div>
          </div>
          
          <!-- Brand Typography -->
          <div class="bet1x-splash-logo-text">
            bet<span class="accent-text">1x</span>
          </div>
          
          <!-- Segmented modern progress bar -->
          <div class="splash-loader-container">
            <div class="splash-loader-bar-modern">
              <div class="splash-progress-modern"></div>
            </div>
          </div>
          
          <!-- High-tech rotating status text -->
          <div class="bet1x-splash-tagline" id="splash-status-text">CONNECTING TO SECURE LOBBY...</div>
        </div>
      `;
      document.body.appendChild(splash);
    }
    
    // High-tech tagline rotation over the 2-second loading period
    const statusEl = document.getElementById('splash-status-text');
    const statuses = [
      "CONNECTING TO SECURE EXCHANGE...",
      "VERIFYING ENCRYPTION & GATEWAY...",
      "ESTABLISHING SECURE WEBHOOKS...",
      "WELCOME TO BET1X ARENA..."
    ];
    
    statuses.forEach((status, index) => {
      setTimeout(() => {
        if (statusEl) statusEl.textContent = status;
      }, index * 480);
    });

    // Click to instantly skip if desired
    splash.onclick = () => {
      splash.style.opacity = '0';
      splash.style.pointerEvents = 'none';
      document.body.style.overflow = '';
      endSplashBoot();
      setTimeout(() => splash.remove(), 250);
    };

    // Exactly 2 seconds (2000ms) loading screen
    setTimeout(() => {
      splash.style.opacity = '0';
      splash.style.pointerEvents = 'none';
      document.body.style.overflow = '';
      endSplashBoot();
      setTimeout(() => splash.remove(), 400);
    }, 2000);
  } else {
    endSplashBoot();
    const staticSplash = document.getElementById('bet1x-splash');
    if (staticSplash) {
      staticSplash.remove();
    }
  }

  // 2. Setup Navbar elements & Sync Session/Balance from Server
  updateAuthHeaderUI();
  syncSessionAndBalance();

  // 3. Dynamically route hardcoded parity.html links to the full admin control panel (admin.html)
  document.querySelectorAll('a[href="parity.html"]').forEach(link => {
    link.setAttribute('href', 'admin.html');
  });
});

function syncSessionAndBalance() {
  updateAuthHeaderUI();
  renderWalletChips();

  if (window.isOfflineMode) {
    return;
  }
  
  const prefix = getApiPrefix();
  const token = localStorage.getItem(AUTH_TOKEN_KEY);
  const user = getCurrentUser();
  if (!user || !user.username) {
    updateAuthHeaderUI();
    return;
  }

  const headers = {};
  if (token) headers['Authorization'] = 'Bearer ' + token;

  fetch(prefix + 'api/auth.php?action=status&username=' + encodeURIComponent(user.username), {
    headers: headers
  })
  .then(res => res.json())
  .then(authData => {
    if (authData.logged_in && authData.user) {
      localStorage.setItem(CURRENT_USER_KEY, JSON.stringify(authData.user));
      if (authData.user.wallet_balance !== undefined) {
        localStorage.setItem(WALLET_KEY, parseFloat(authData.user.wallet_balance).toFixed(2));
      }
    }
    updateAuthHeaderUI();
    renderWalletChips();
  })
  .catch(err => {
    console.warn("API status fetch error, using cached session:", err);
    updateAuthHeaderUI();
    renderWalletChips();
  });
}

// 3. Global click interceptor (capture phase) for blocking unauthorized betting/actions
document.addEventListener('click', (e) => {
  const target = e.target;
  if (!target) return;
  
  const blockSelector = 'button[onclick*="newGame"], button[onclick*="doChaal"], button[onclick*="doShow"], button[onclick*="placeWager"], button[onclick*="lockAndStart"], button[onclick*="simulateMatch"], #placeBetBtn, .bet-btn, .chip-amt, .color-btn, .size-btn, .number-grid button, #seeCardsBtn, button[onclick*="seeCards"], .lobby-card, [onclick*="enterMatchDraft"]';
  
  const isBetBtn = target.closest(blockSelector);
  
  if (isBetBtn && !getCurrentUser()) {
    e.preventDefault();
    e.stopPropagation();
    showToast('Please log in or sign up to play and place wagers!', 'info');
    openAuthModal('login');
  }
}, true);

function getOfflineAviatorRoundId() {
  let rid = localStorage.getItem('bet1x_aviator_round_id');
  if (!rid) {
    rid = '99246958';
    localStorage.setItem('bet1x_aviator_round_id', rid);
  }
  return parseInt(rid, 10);
}

function incrementOfflineAviatorRoundId() {
  let nextId = getOfflineAviatorRoundId() + 1;
  localStorage.setItem('bet1x_aviator_round_id', nextId.toString());
  return nextId;
}

function getAviatorCurrentBets() {
  try {
    const raw = localStorage.getItem('bet1x_aviator_current_bets');
    return raw ? JSON.parse(raw) : [];
  } catch (e) {
    return [];
  }
}

/* ---------------------------------------------------------------------------------------------
 * HTML escaping — the single output-encoding helper for this site.
 *
 * Every page builds markup with innerHTML and template literals. Any value that originated from a
 * user (a chat message, a withdrawal's bank name, an operator-typed reason) MUST pass through this
 * before it is interpolated, or the browser parses it as markup.
 *
 * This is not theoretical. Two live vectors existed before this was added:
 *   1. A chat message is free-form and rendered into the lobby feed every 3 seconds, so
 *      `<img src=x onerror=...>` executed in EVERY visitor's browser, operators included.
 *   2. Withdrawal `bank_name` / `bank_acc_name` were validated only as "not empty", concatenated
 *      into the stored `details` string, and rendered in the admin Pending Cashouts table — a
 *      payload aimed squarely at the operator's session.
 *
 * The site's CSP deliberately allows inline handlers (`script-src-attr: 'unsafe-inline'`) because
 * the pages are hand-written with onclick= attributes throughout, so CSP does NOT save us here.
 * Output encoding is the control.
 *
 * Escapes the five XML significant characters. `&` must be replaced first or it would double-encode
 * the entities produced by the later replacements.
 * ------------------------------------------------------------------------------------------- */
function escapeHtml(value) {
  if (value === null || value === undefined) return '';
  return String(value)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}
window.escapeHtml = escapeHtml;

/* ---------------------------------------------------------------------------------------------
 * UPI deposit QR — shared between cashier.html (renders it for a paying customer) and admin.html
 * (renders a live preview while an operator edits the platform's UPI ID). Both pages load
 * assets/js/qrcode-gen.js (the vendored kazuhikoarase/qrcode-generator, MIT) before this file.
 * ------------------------------------------------------------------------------------------- */

// Standard UPI deep link — any UPI app (GPay, PhonePe, Paytm, BHIM...) recognises this scheme and
// pre-fills the payee and amount when the QR is scanned.
function buildUpiPaymentUri(upiId, payeeName, amount) {
  const params = new URLSearchParams();
  params.set('pa', upiId);
  params.set('pn', payeeName || 'bet1x');
  if (amount) params.set('am', Number(amount).toFixed(2));
  params.set('cu', 'INR');
  return 'upi://pay?' + params.toString();
}

// Renders a real, scannable QR code (not a decorative mockup) into `containerEl`, replacing
// whatever it currently holds. Requires assets/js/qrcode-gen.js to already be loaded.
function renderUpiQr(containerEl, text, sizePx) {
  if (!containerEl || typeof qrcode !== 'function') return;
  containerEl.innerHTML = '';
  const qr = qrcode(0, 'M'); // type 0 = auto-detect smallest version for the data length
  qr.addData(text);
  qr.make();
  const cellSize = Math.max(2, Math.floor((sizePx || 180) / qr.getModuleCount()));
  containerEl.innerHTML = qr.createImgTag(cellSize, cellSize * 2);
  const img = containerEl.querySelector('img');
  if (img) { img.style.maxWidth = '100%'; img.style.height = 'auto'; img.style.display = 'block'; }
}

// Automatically forfeit all active/pending bets when leaving any game room
function handleRoomLeaveForfeit() {
  const currentRoom = getCurrentGameRoom();
  if (currentRoom) {
    forfeitAllPendingBets(currentRoom);
  }
}

window.addEventListener('beforeunload', handleRoomLeaveForfeit);
window.addEventListener('pagehide', handleRoomLeaveForfeit);

document.addEventListener('DOMContentLoaded', () => {
  updateAuthHeaderUI();
  renderWalletChips();
});

/* ============================================================
   Bottom "recent wins" ticker
   ============================================================
   Purely cosmetic social proof — there is no backend for this, nothing here reads a real bet.
   Player-facing pages only (guarded on body.exchange-theme, the same class every CSS rule in this
   file's stylesheet already scopes the reskin to), so it never shows up on admin.html/parity.html,
   which load this same script for the fetch interceptor and auth helpers.

   The visible ORDER names cycle in reshuffles roughly every 3.5 days: a fixed day-bucket seeds a
   deterministic shuffle, so every visitor sees the same order on the same day with no server or
   storage involved, and it looks different again a few days later. The amount and game shown are
   freshly randomised on every tick regardless of that order.
   ------------------------------------------------------------ */
const WIN_TICKER_NAMES = [
  'Player438462', 'Player370945', 'Player233770', 'Player821367', 'Player384253',
  'Player536395', 'Player669594', 'Player281083', 'Player284980', 'Player955788',
  'Player793884', 'Player435589', 'Player432990', 'Player428500', 'Player435696',
  'Player823511', 'Player333861', 'Player944542', 'Player175284', 'Player559642',
  'Player384126', 'Player648946', 'Player931919', 'Player350709', 'Player755781',
  'Player545474', 'Player919731', 'Player977209', 'Player293577', 'Player576019',
  'Player730887', 'Player323498', 'Player799078', 'Player850002', 'Player991919',
  'Player334592', 'Player868664', 'Player551960', 'Player989360', 'Player514554',
  'Player536705', 'Player242799', 'Player859712', 'Player269855', 'Player849037',
  'Player719545', 'Player534254', 'Player935115', 'Player863840', 'Player708974',
  'Player323487', 'Player250230', 'Player486769', 'Player760349', 'Player175544',
  'Player983163', 'Player340041', 'Player347136'
];
const WIN_TICKER_GAMES = ['Vimaan', 'Sapre Color', 'Becone Color', 'Emred Color', 'VIP Room', 'Teen Patti', 'Mines'];

// mulberry32 — a small, deterministic PRNG. Math.random() can't be seeded, and the whole point
// here is that the same day-bucket produces the same shuffle for every visitor.
function ticker_mulberry32(seed) {
  return function () {
    seed |= 0; seed = (seed + 0x6D2B79F5) | 0;
    let t = Math.imul(seed ^ (seed >>> 15), 1 | seed);
    t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;
    return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
  };
}

function ticker_shuffledNames() {
  const dayBucket = Math.floor(Date.now() / (3.5 * 24 * 60 * 60 * 1000));
  const rand = ticker_mulberry32(dayBucket);
  const arr = WIN_TICKER_NAMES.slice();
  for (let i = arr.length - 1; i > 0; i--) {
    const j = Math.floor(rand() * (i + 1));
    const tmp = arr[i]; arr[i] = arr[j]; arr[j] = tmp;
  }
  return arr;
}

function injectWinTicker() {
  if (!document.body.classList.contains('exchange-theme')) return; // player-facing pages only
  if (document.getElementById('bet1x-win-ticker')) return;

  const bar = document.createElement('div');
  bar.id = 'bet1x-win-ticker';
  bar.className = 'bet1x-win-ticker';
  bar.innerHTML = '<span class="bet1x-win-ticker-icon">🏆</span>'
                + '<span class="bet1x-win-ticker-text" id="bet1x-win-ticker-text"></span>';
  document.body.appendChild(bar);

  const order = ticker_shuffledNames();
  const textEl = document.getElementById('bet1x-win-ticker-text');
  let idx = 0;

  function renderNext() {
    const name = order[idx % order.length];
    idx++;
    const game = WIN_TICKER_GAMES[Math.floor(Math.random() * WIN_TICKER_GAMES.length)];
    const amount = Math.floor(250 + Math.random() * 49750);
    textEl.classList.add('fading');
    setTimeout(() => {
      // Every value here comes from the fixed arrays above or Math.random() — never user input —
      // so, unlike showToast()'s messages, innerHTML is safe: there is nothing here to inject.
      textEl.innerHTML = '<strong>' + name + '</strong> has won <span class="amt">₹'
        + amount.toLocaleString('en-IN') + '</span> in ' + game;
      textEl.classList.remove('fading');
    }, 300);
  }

  renderNext();
  setInterval(renderNext, 4500);
}

document.addEventListener('DOMContentLoaded', injectWinTicker);


