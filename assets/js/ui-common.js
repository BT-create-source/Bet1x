/**
 * bet1x — shared UI behaviors for the pitch demo.
 * Synchronized with the PHP database-less backend api endpoints.
 */

function getApiPrefix() {
  const path = window.location.pathname;
  if (path.includes('/teenpati/') || path.includes('/cricket-player/') || path.includes('/cricket-team/') || path.includes('/aviator/') || path.includes('/mining/') || path.includes('/football/')) {
    return '../backend/';
  }
  return 'backend/';
}

const WALLET_KEY = 'bet1x_demo_wallet';
const HISTORY_KEY = 'bet1x_demo_history';
const STARTING_BALANCE = 1000;

const USERS_KEY = 'bet1x_users';
const CURRENT_USER_KEY = 'bet1x_current_user';

window.isOfflineMode = (window.location.protocol === 'file:');

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

function getHistory() {
  const stored = localStorage.getItem(HISTORY_KEY);
  return stored ? JSON.parse(stored) : [];
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
  
  if (user) {
    authArea.innerHTML = `
      <div style="display:flex; align-items:center; gap:10px; color:#ffffff; font-size:13.5px; flex-wrap:wrap; justify-content:flex-end;">
        <span>Welcome, <strong style="color:var(--gold);">${user.username}</strong></span>
        <span class="wallet-chip" data-wallet-chip style="margin:0;">₹ ${getWallet().toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</span>
        <a href="${prefix}cashier.html" style="background:var(--gold); color:#000; font-weight:800; font-size:12px; padding:4px 10px; border-radius:4px; text-decoration:none; display:inline-flex; align-items:center; gap:4px; box-shadow:0 0 12px rgba(201,160,84,0.5);">💰 Deposit</a>
        <a href="#" onclick="handleHeaderLogout(event)" style="color:#ff5d5d; font-weight:700; text-decoration:none; font-size:12.5px; border-left:1px solid rgba(255,255,255,0.2); padding-left:10px;">Logout ⎋</a>
      </div>
    `;
  } else {
    authArea.innerHTML = `
      <div class="header-guest-wrap" style="display:flex; align-items:center;">
        <a href="${prefix}cashier.html" style="background:var(--gold); color:#000; font-weight:800; font-size:12px; padding:6px 12px; border-radius:4px; text-decoration:none; display:inline-flex; align-items:center; gap:4px; box-shadow:0 0 12px rgba(201,160,84,0.5);">💰 Deposit</a>
      </div>
    `;
  }
  renderWalletChips();
}

function handleHeaderLogout(e) {
  if (e) e.preventDefault();
  localStorage.removeItem(CURRENT_USER_KEY);
  localStorage.removeItem(WALLET_KEY);
  localStorage.removeItem(HISTORY_KEY);
  location.reload();
}

document.addEventListener('DOMContentLoaded', () => {
  updateAuthHeaderUI();

  // Inject sub-navbar links for Football and Mines globally
  const subNav = document.querySelector('.sub-navbar-links');
  if (subNav) {
    const hasFootball = Array.from(subNav.querySelectorAll('a')).some(a => a.getAttribute('href').includes('football'));
    if (!hasFootball) {
      const prefix = getApiPrefix();
      const adminItem = Array.from(subNav.querySelectorAll('li')).find(li => li.textContent.toLowerCase().includes('admin'));
      
      const fbLi = document.createElement('li');
      fbLi.className = 'sub-navbar-item';
      fbLi.innerHTML = `<a href="${prefix}football.html" class="sub-navbar-link">Football</a>`;
      
      const mineLi = document.createElement('li');
      mineLi.className = 'sub-navbar-item';
      mineLi.innerHTML = `<a href="${prefix}mining.html" class="sub-navbar-link">💣 Mines</a>`;
      
      if (adminItem) {
        subNav.insertBefore(fbLi, adminItem);
        subNav.insertBefore(mineLi, adminItem);
      } else {
        subNav.appendChild(fbLi);
        subNav.appendChild(mineLi);
      }
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
      const emailInp = document.getElementById('signup-email').value.trim();
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
        email: emailInp,
        password: passInp,
        wallet: STARTING_BALANCE
      };
      users.push(newUser);
      saveUsers(users);
      
      localStorage.setItem(CURRENT_USER_KEY, JSON.stringify({
        username: userInp,
        email: emailInp
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
    
    const body = new URLSearchParams();
    body.append('username', userInp);
    body.append('password', passInp);
    
    fetch(prefix + 'api/auth.php?action=login', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: body
    })
    .then(res => {
      if (!res.ok) throw new Error("HTTP error " + res.status);
      const ct = res.headers.get("content-type");
      if (!ct || !ct.includes("application/json")) throw new Error("Not JSON");
      return res.json();
    })
    .then(data => {
      if (data.success) {
        localStorage.setItem(CURRENT_USER_KEY, JSON.stringify(data.user));
        closeAuthModal();
        showToast(`Welcome back, ${data.user.username}!`, 'success');
        updateNavbarAuth();
        setTimeout(() => { location.reload(); }, 800);
      } else {
        errEl.textContent = data.error || 'Incorrect username or password.';
        errEl.style.display = 'block';
      }
    })
    .catch(err => {
      console.warn("Login API error, triggering offline fallback:", err);
      window.isOfflineMode = true;
      // Re-trigger auth submit in offline mode immediately!
      window.handleAuthSubmit(e, type);
    });
  } else {
    const userInp = document.getElementById('signup-username').value.trim();
    const emailInp = document.getElementById('signup-email').value.trim();
    const passInp = document.getElementById('signup-password').value;
    const confirmPassInp = document.getElementById('signup-confirm-password').value;
    const errEl = document.getElementById('signup-error');
    
    if (passInp !== confirmPassInp) {
      errEl.textContent = 'Passwords do not match.';
      errEl.style.display = 'block';
      return;
    }
    
    const body = new URLSearchParams();
    body.append('username', userInp);
    body.append('email', emailInp);
    body.append('password', passInp);
    body.append('confirm_password', confirmPassInp);
    
    fetch(prefix + 'api/auth.php?action=signup', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: body
    })
    .then(res => {
      if (!res.ok) throw new Error("HTTP error " + res.status);
      const ct = res.headers.get("content-type");
      if (!ct || !ct.includes("application/json")) throw new Error("Not JSON");
      return res.json();
    })
    .then(data => {
      if (data.success) {
        localStorage.setItem(CURRENT_USER_KEY, JSON.stringify(data.user));
        closeAuthModal();
        showToast(`Account created successfully! Welcome, ${data.user.username}!`, 'success');
        updateNavbarAuth();
        setTimeout(() => { location.reload(); }, 800);
      } else {
        errEl.textContent = data.error || 'Registration failed.';
        errEl.style.display = 'block';
      }
    })
    .catch(err => {
      console.warn("Signup API error, triggering offline fallback:", err);
      window.isOfflineMode = true;
      window.handleAuthSubmit(e, type);
    });
  }
};

window.openAuthModal = function(tab = 'login') {
  injectAuthModal();
  const overlay = document.getElementById('bet1x-auth-modal');
  if (overlay) {
    switchAuthTab(tab);
    overlay.classList.add('active');
  }
};

window.closeAuthModal = function() {
  const overlay = document.getElementById('bet1x-auth-modal');
  if (overlay) {
    overlay.classList.remove('active');
  }
};

window.showToast = function(msg, type = 'success') {
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
          <label for="signup-email">Email Address</label>
          <input type="email" id="signup-email" placeholder="Enter email" required autocomplete="email">
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
    
    document.body.style.overflow = 'hidden';
    
    // High-tech tagline rotation
    const statusEl = document.getElementById('splash-status-text');
    const statuses = [
      "CONNECTING TO SECURE LOBBY...",
      "VERIFYING TRANSACTION GATEWAY...",
      "ESTABLISHING SECURE WEBHOOKS...",
      "WELCOME TO BET1X ARENA..."
    ];
    
    statuses.forEach((status, index) => {
      setTimeout(() => {
        if (statusEl) statusEl.textContent = status;
      }, index * 450);
    });
    
    setTimeout(() => {
      splash.style.opacity = '0';
      document.body.style.overflow = '';
      setTimeout(() => splash.remove(), 800);
    }, 1900);
  } else {
    const staticSplash = document.getElementById('bet1x-splash');
    if (staticSplash) {
      staticSplash.remove();
    }
  }

  // 2. Setup Navbar elements & Sync Session/Balance from Server
  syncSessionAndBalance();

  // 3. Dynamically route hardcoded parity.html links to the full admin control panel (admin.html)
  document.querySelectorAll('a[href="parity.html"]').forEach(link => {
    link.setAttribute('href', 'admin.html');
  });
});

function syncSessionAndBalance() {
  if (window.isOfflineMode) {
    updateNavbarAuth();
    renderWalletChips();
    return;
  }
  
  const prefix = getApiPrefix();
  fetch(prefix + 'api/auth.php?action=status')
  .then(res => {
    if (!res.ok) {
      throw new Error("Server returned HTTP status " + res.status);
    }
    const ct = res.headers.get("content-type");
    if (!ct || !ct.includes("application/json")) {
      throw new Error("Server did not return application/json headers");
    }
    return res.json();
  })
  .then(authData => {
    if (authData.logged_in) {
      localStorage.setItem(CURRENT_USER_KEY, JSON.stringify(authData.user));
      fetch(prefix + 'api/wallet.php?action=balance')
      .then(res => {
        if (!res.ok) throw new Error("HTTP error " + res.status);
        const ct = res.headers.get("content-type");
        if (!ct || !ct.includes("application/json")) throw new Error("Not JSON");
        return res.json();
      })
      .then(walletData => {
        if (walletData.balance !== undefined) {
          localStorage.setItem(WALLET_KEY, parseFloat(walletData.balance).toFixed(2));
        }
        updateNavbarAuth();
        renderWalletChips();
      })
      .catch(err => {
        console.warn("API balance fetch error:", err);
        updateNavbarAuth();
        renderWalletChips();
      });
    } else {
      localStorage.removeItem(CURRENT_USER_KEY);
      localStorage.removeItem(WALLET_KEY);
      updateNavbarAuth();
      renderWalletChips();
    }
  })
  .catch(err => {
    console.warn("API status fetch error - enabling Offline Fallback Mode. Error details:", err);
    window.isOfflineMode = true;
    updateNavbarAuth();
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

// Automatically initialize exchange headers (HeaderAuthArea)
function initializeExchangeHeader() {
  const authArea = document.getElementById('headerAuthArea');
  if (!authArea) return;

  const user = getCurrentUser();
  const path = window.location.pathname;
  const isSubdir = path.includes('/teenpati/') || path.includes('/aviator/');
  const rootPrefix = isSubdir ? '../' : '';

  if (user) {
    authArea.innerHTML = `
      <div style="display:flex; align-items:center; gap:12px; color:#ffffff; font-size:13.5px;">
        <span>Welcome, <strong style="color:var(--gold);">${user.username}</strong></span>
        <span class="wallet-chip" data-wallet-chip style="margin:0;">PTS ${getWallet().toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</span>
        <a href="#" id="headerLogoutLink" style="color:#ff5d5d; font-weight:700; text-decoration:none; font-size:12.5px; border-left:1px solid rgba(255,255,255,0.2); padding-left:12px;">Logout ⎋</a>
      </div>
    `;
    
    document.getElementById('headerLogoutLink').addEventListener('click', (e) => {
      e.preventDefault();
      localStorage.removeItem('bet1x_current_user');
      localStorage.removeItem('bet1x_demo_wallet');
      localStorage.removeItem('bet1x_demo_history');
      
      fetch(rootPrefix + 'api/auth.php?action=logout')
        .then(() => {
          window.location.reload();
        })
        .catch(() => {
          window.location.reload();
        });
    });
  } else {
    authArea.innerHTML = `
      <div class="header-guest-wrap" style="display:flex; align-items:center;">
        <button type="button" class="header-login-btn mobile-login-btn" onclick="openAuthModal('login')" style="background:#c8102e; color:#fff; font-weight:700; border:none; border-radius:4px; padding:0 14px; height:32px; font-size:13px; cursor:pointer;">Login / Sign Up</button>
        <form class="header-login-form desktop-only" id="headerLoginForm" style="display:flex; align-items:center; gap:8px;">
          <input type="text" class="header-login-input" id="loginUsername" placeholder="Username" required style="background:#fff !important; color:#333 !important; border:1px solid #ccc; border-radius:4px; padding:6px 10px; font-size:13px; width:140px; height:32px; margin:0;">
          <input type="password" class="header-login-input" id="loginPassword" placeholder="Password" style="background:#fff !important; color:#333 !important; border:1px solid #ccc; border-radius:4px; padding:6px 10px; font-size:13px; width:140px; height:32px; margin:0;">
          <button type="submit" class="header-login-btn" style="background:#c8102e; color:#fff; font-weight:700; border:none; border-radius:4px; padding:0 14px; height:32px; font-size:13px; cursor:pointer;">Login ➜</button>
        </form>
      </div>
    `;
    
    const loginForm = document.getElementById('headerLoginForm');
    if (loginForm) {
      loginForm.addEventListener('submit', (e) => {
      e.preventDefault();
      const userField = document.getElementById('loginUsername');
      const username = userField.value.trim() || 'DemoUser';
      
      localStorage.setItem('bet1x_current_user', JSON.stringify({ username: username }));
      if (localStorage.getItem('bet1x_demo_wallet') === null) {
        localStorage.setItem('bet1x_demo_wallet', '1000.00');
      }
      
      const rootPrefix = window.location.pathname.includes('/teenpati/') || window.location.pathname.includes('/aviator/') ? '../' : '';
      
      fetch(rootPrefix + 'api/auth.php?action=login', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'username=' + encodeURIComponent(username) + '&password=123456'
      })
      .then(res => res.json())
      .then(data => {
        if (data.error) {
          return fetch(rootPrefix + 'api/auth.php?action=signup', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'username=' + encodeURIComponent(username) + '&email=' + encodeURIComponent(username + '@bet1x.com') + '&password=123456&confirm_password=123456'
          }).then(res => res.json());
        }
        return data;
      })
      .then(() => {
        window.location.reload();
      })
      .catch(() => {
        window.location.reload();
      });
      });
    }
  }
}

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

function saveAviatorCurrentBets(betsArray) {
  try {
    localStorage.setItem('bet1x_aviator_current_bets', JSON.stringify(betsArray || []));
  } catch (e) {}
}

document.addEventListener('DOMContentLoaded', () => {
  initializeExchangeHeader();
  renderWalletChips();
});

