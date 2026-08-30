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

function getWallet() {
  const balance = localStorage.getItem(WALLET_KEY);
  if (balance === null) {
    localStorage.setItem(WALLET_KEY, STARTING_BALANCE.toFixed(2));
    return STARTING_BALANCE;
  }
  return parseFloat(balance);
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
        <span>Welcome, <strong style="color:var(--gold);">${user.username}</strong></span>
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
      localStorage.setItem(WALLET_KEY, (parseFloat(data.user.wallet_balance) || 2000).toFixed(2));
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

/* ---------------- Countdown ring ---------------- */
function startCountdownRing(opts) {
  const { ringFg, timeEl, roundIdEl, durationSeconds, onComplete } = opts;
  const radius = ringFg.r.baseVal.value;
  const circumference = 2 * Math.PI * radius;
  ringFg.style.strokeDasharray = circumference;

  let remaining = durationSeconds;
  let roundSeq = randomInt(10000, 99999);
  if (roundIdEl) roundIdEl.textContent = formatRoundId(new Date(), roundSeq);

  function tick() {
    const pct = remaining / durationSeconds;
    ringFg.style.strokeDashoffset = circumference * (1 - pct);
    const m = Math.floor(remaining / 60).toString().padStart(2, '0');
    const s = Math.floor(remaining % 60).toString().padStart(2, '0');
    timeEl.textContent = `${m}:${s}`;

    if (remaining <= 0) {
      if (onComplete) onComplete();
      remaining = durationSeconds;
      roundSeq = randomInt(10000, 99999);
      if (roundIdEl) roundIdEl.textContent = formatRoundId(new Date(), roundSeq);
      return;
    }
    remaining -= 1;
    setTimeout(tick, 1000);
  }
  tick();
}

/* ---------------- Live activity feed simulation ---------------- */
function startActivityFeed(container, { intervalMs = 2200, maxItems = 12 } = {}) {
  function addEvent() {
    const ev = generateFakeActivityEvent();
    if (!ev || !ev.user) {
      return; // Do not render empty dummy events
    }
    const item = document.createElement('div');
    item.className = 'activity-item';
    const label = ev.category === 'color'
      ? ev.value
      : ev.category === 'size'
        ? ev.value
        : `Number ${ev.value}`;
    const cls = ev.category === 'color' ? ev.value.toLowerCase() : '';
    item.innerHTML = `
      <span class="user">${ev.user} · ${ev.room}</span>
      <span class="action ${cls}">₹${ev.amount} on ${label}</span>
    `;
    container.prepend(item);
    while (container.children.length > maxItems) {
      container.removeChild(container.lastChild);
    }
  }
  return setInterval(addEvent, intervalMs);
}

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
        localStorage.setItem(WALLET_KEY, (parseFloat(data.user.wallet_balance) || 2000).toFixed(2));
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

    if (submitBtn) submitBtn.disabled = true;
    
    const body = new URLSearchParams();
    body.append('username', userInp);
    body.append('email', `${userInp.toLowerCase()}@bet1x.com`);
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
        localStorage.setItem(WALLET_KEY, (parseFloat(data.user.wallet_balance) || 2000).toFixed(2));
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
  toast.innerHTML = `
    <span>${msg}</span>
    <button class="toast-close" onclick="this.parentElement.remove()">&times;</button>
  `;
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

function injectAuthModal() {
  if (document.getElementById('bet1x-auth-modal')) return;
  const modal = document.createElement('div');
  modal.id = 'bet1x-auth-modal';
  modal.className = 'auth-modal-overlay';
  modal.innerHTML = `
    <div class="auth-modal-card">
      <button class="auth-modal-close" onclick="closeAuthModal()">&times;</button>
      <div class="auth-tabs">
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
        <button type="submit" class="btn btn-primary btn-block" style="margin-top: 10px; color: #000; font-weight:700;">Sign In</button>
      </form>
      
      <!-- Signup Form -->
      <form id="form-signup" class="auth-form" onsubmit="handleAuthSubmit(event, 'signup')">
        <div class="auth-form-group">
          <label for="signup-username">Username</label>
          <input type="text" id="signup-username" placeholder="Choose a username" required autocomplete="username">
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
    </div>
  `;
  document.body.appendChild(modal);
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
                <span>${currentUser.username}</span>
                <span class="dropdown-chevron">▼</span>
              </button>
              <div class="nav-user-dropdown" id="navUserDropdown">
                <div class="dropdown-header">
                  <div class="dropdown-username">${currentUser.username}</div>
                  <div class="dropdown-email">${currentUser.email || (currentUser.username + '@bet1x.com')}</div>
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
      setTimeout(() => splash.remove(), 250);
    };

    // Exactly 2 seconds (2000ms) loading screen
    setTimeout(() => {
      splash.style.opacity = '0';
      splash.style.pointerEvents = 'none';
      document.body.style.overflow = '';
      setTimeout(() => splash.remove(), 400);
    }, 2000);
  } else {
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


