/**
 * Game shell — full-screen game pages (Vimaan, Astronaut, Chicken Road, Tower Rush, Mines).
 *
 * On these pages the game takes the whole first screen, on phones and laptops alike: the site header,
 * the scrolling games strip and the winners ticker are hidden, and a hamburger at the top left opens a
 * drawer with Home, every game and the player's account (balance, deposit, log in / out — the header's
 * own account area is moved into the drawer, so ui-common.js keeps filling it as before). The page
 * still scrolls: the game's history sits below the first screen.
 *
 * Include it after the game markup:
 *   <link rel="stylesheet" href="assets/css/game-shell.css">
 *   <script src="assets/js/game-shell.js" data-game=".tr-game" data-stage=".tr-stage" defer></script>
 *
 *   data-game   the element that must fill the first screen
 *   data-stage  the part of it that stretches (its height is set so the game ends at the screen's
 *               bottom edge); leave it out to only give the game a full-screen minimum height
 *   data-until  optional: fit so THIS element's bottom meets the screen's bottom edge (anything in the
 *               game block after it, e.g. a fairness bar, sits just below the first screen)
 *   data-min    the smallest the stretching part may get, in px (default 300)
 *   data-max    the largest it may get, in px (default none)
 *
 * Every other player page uses it in menu-only mode, data-mode="nav": the page and its header stay as
 * they are, the scrolling games strip is hidden, and the hamburger sits in the header beside the logo:
 *   <script src="assets/js/game-shell.js" data-mode="nav" defer></script>
 */
(function () {
  'use strict';
  const me = document.currentScript;
  const cfg = {
    game: me && me.dataset.game,
    stage: me && me.dataset.stage,
    until: me && me.dataset.until,
    min: Number(me && me.dataset.min) || 300,
    max: Number(me && me.dataset.max) || Infinity,
    nav: !!(me && me.dataset.mode === 'nav'),
  };
  // The drawer, laid out like a sportsbook / casino menu: sections of game cards with their covers.
  const SECTIONS = [
    ['Crash Games', [
      ['aviator.html', 'Vimaan', 'assets/game_vimaan.jpg', 'HOT'],
      ['astronaut.html', 'Astronaut', 'assets/game_astronaut.jpg', 'NEW'],
    ]],
    ['Casino', [
      ['chickenroad.html', 'Chicken Road', 'assets/game_chickenroad.webp', 'HOT'],
      ['towerrush.html', 'Tower Rush', 'assets/game_towerrush.webp', 'NEW'],
      ['mining.html', 'Mines', 'assets/game_mines.jpg', ''],
      ['teenpatti.html', 'Teen Patti', 'assets/game_teenpatti.jpg', ''],
    ]],
    ['Colour Prediction', [
      ['win.html', 'Sapre', 'assets/game_sapre.jpg', ''],
      ['win1.html', 'Becone', 'assets/game_becone.jpg', ''],
      ['win2.html', 'Emred', 'assets/game_emred.jpg', ''],
      ['win3.html', 'VIP', 'assets/game_vip.jpg', ''],
    ]],
    ['Sports', [
      ['cricket.html', 'Cricket', '', 'LIVE'],
      ['boundarybaazi.html', 'Ball by Ball', 'assets/game_boundary.jpg', 'LIVE'],
      ['youreleven.html', 'Your 11', 'assets/game_youreleven.jpg', ''],
    ]],
  ];
  const ICON_HOME = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"><path d="M3 11l9-7 9 7v9a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z"/></svg>';
  // Cricket has no cover picture: a styled card (pitch-green, the game's name and what it offers)
  const ICON_CRICKET = '<span class="gs-cric"><b>CRICKET</b><i>Match Odds &middot; Fancy</i></span>';

  if (cfg.nav) document.body.classList.add('gs-nav');
  else { document.documentElement.classList.add('gs-on'); document.body.classList.add('gs-on'); }

  // ---- hamburger + drawer ----------------------------------------------------------------------
  const here = (location.pathname.split('/').pop() || 'index.html').toLowerCase();
  const btn = document.createElement('button');
  btn.type = 'button';
  btn.className = 'gs-burger';
  btn.setAttribute('aria-label', 'Menu');
  btn.setAttribute('aria-expanded', 'false');
  btn.innerHTML = '<span></span><span></span><span></span>';
  const scrim = document.createElement('div');
  scrim.className = 'gs-scrim';
  const drawer = document.createElement('nav');
  drawer.className = 'gs-drawer';
  drawer.setAttribute('aria-label', 'Games');
  const card = ([href, name, img, tag]) => `<a href="${href}" class="gs-card${href === here ? ' active' : ''}">`
    + `<span class="gs-thumb">${img ? `<img src="${img}" alt="" loading="lazy">` : ICON_CRICKET}`
    + `${tag ? `<em class="gs-tag gs-tag-${tag.toLowerCase()}">${tag}</em>` : ''}</span>`
    + `<span class="gs-name">${name}</span></a>`;
  drawer.innerHTML = '<div class="gs-head"><a href="index.html" class="gs-logo"><img src="assets/logo-icon.png" alt=""><span>Bet<b>1x</b></span></a>'
    + '<button type="button" class="gs-close" aria-label="Close menu">&times;</button></div>'
    + '<div class="gs-account"></div>'
    + '<div class="gs-cash"><a href="cashier.html" class="gs-dep">Deposit</a><a href="cashier.html?tab=withdraw" class="gs-wd">Withdraw</a></div>'
    + `<a href="index.html" class="gs-home${here === 'index.html' ? ' active' : ''}">${ICON_HOME}<span>Home</span></a>`
    + SECTIONS.map(([title, games]) => `<div class="gs-sec"><div class="gs-sec-h">${title}</div><div class="gs-grid">${games.map(card).join('')}</div></div>`).join('');
  document.body.append(scrim, drawer);
  const logo = cfg.nav && document.querySelector('.exchange-header .exchange-logo-box');
  if (logo) {
    // beside the logo, the two kept together so a header that spreads its items apart keeps them side by side
    btn.classList.add('gs-inline');
    const wrap = document.createElement('span');
    wrap.className = 'gs-logo-wrap';
    logo.parentNode.insertBefore(wrap, logo);
    wrap.append(btn, logo);
  }
  else document.body.append(btn);

  // full-screen pages: the header's account area (balance, deposit, log in / out) moves into the drawer
  const auth = !cfg.nav && document.getElementById('headerAuthArea');
  if (auth) drawer.querySelector('.gs-account').appendChild(auth);
  else drawer.querySelector('.gs-account').remove();

  const open = (yes) => {
    document.body.classList.toggle('gs-open', yes);
    btn.setAttribute('aria-expanded', yes ? 'true' : 'false');
  };
  btn.addEventListener('click', () => open(!document.body.classList.contains('gs-open')));
  scrim.addEventListener('click', () => open(false));
  drawer.querySelector('.gs-close').addEventListener('click', () => open(false));
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape') open(false); });

  // ---- the game fills the first screen -----------------------------------------------------------
  let fitting = false, lastH = 0;
  function fit() {
    const game = cfg.game && document.querySelector(cfg.game);
    if (!game || fitting) return;
    const stageEl = cfg.stage && (game.querySelector(cfg.stage) || document.querySelector(cfg.stage));
    // In the browser's own full screen the game sizes itself: step aside (and drop our fixed height)
    const fsEl = document.fullscreenElement || document.webkitFullscreenElement;
    if (fsEl && (fsEl === game || fsEl.contains(game) || game.contains(fsEl))) {
      if (stageEl) stageEl.style.removeProperty('height');
      lastH = 0;
      return;
    }
    const vh = window.innerHeight;
    const top = game.getBoundingClientRect().top + window.scrollY;
    const avail = vh - Math.max(0, top);                          // from the game's top to the screen's bottom edge
    const stage = cfg.stage && game.querySelector(cfg.stage) || (cfg.stage && document.querySelector(cfg.stage));
    if (!stage) { game.style.minHeight = avail + 'px'; return; }
    const until = cfg.until && document.querySelector(cfg.until);
    const gh = until ? until.getBoundingClientRect().bottom - game.getBoundingClientRect().top : game.getBoundingClientRect().height;
    const sh = stage.getBoundingClientRect().height;
    const want = Math.round(Math.max(cfg.min, Math.min(cfg.max, sh + (avail - gh))));
    if (Math.abs(want - lastH) < 2 && Math.abs(gh - avail) < 3) return;
    lastH = want;
    fitting = true;
    stage.style.setProperty('height', want + 'px', 'important');
    // let the game redraw for its new size (most listen for resize)
    requestAnimationFrame(() => { window.dispatchEvent(new Event('resize')); fitting = false; });
  }
  let t = 0;
  const refit = () => { clearTimeout(t); t = setTimeout(fit, 60); };
  window.addEventListener('resize', () => { if (!fitting) refit(); });
  window.addEventListener('orientationchange', () => setTimeout(fit, 250));
  // entering / leaving the browser's full screen: let the game redraw for the new size
  const onFs = () => { lastH = 0; fit(); setTimeout(() => { fit(); window.dispatchEvent(new Event('resize')); }, 120); };
  document.addEventListener('fullscreenchange', onFs);
  document.addEventListener('webkitfullscreenchange', onFs);
  window.addEventListener('load', () => { fit(); setTimeout(fit, 400); window.scrollTo(0, 0); });
  fit();
  // content that loads later (the game's own panels) can change the game's height: keep it fitted
  if (window.ResizeObserver && cfg.game) {
    const g = document.querySelector(cfg.game);
    if (g) new ResizeObserver(() => { if (!fitting) refit(); }).observe(g);
  }
})();
