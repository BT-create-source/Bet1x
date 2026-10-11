// Shared helpers for the browser tests.
const { expect } = require('@playwright/test');

const BASE = process.env.BASE_URL || `http://127.0.0.1:${process.env.E2E_PORT || 5077}`;
const USER = process.env.E2E_USER || 'e2e_player';
const PASSWORD = process.env.E2E_PASSWORD || 'e2e-Test-Pass-1';

// Noise that is not a bug in the site: third-party fonts / CDNs blocked or slow in CI, the browser's
// own favicon request, autoplay notices.
const IGNORE = [/fonts\.(googleapis|gstatic)\.com/i, /favicon/i, /AudioContext was not allowed to start/i,
                /net::ERR_(INTERNET_DISCONNECTED|NAME_NOT_RESOLVED|CONNECTION_REFUSED)/i, /cdnjs|jsdelivr|unpkg/i];

/**
 * Collect everything that would mean the page is broken: uncaught JavaScript errors, console errors,
 * and any same-origin request that came back 5xx. Call at the start of a test, assert at the end.
 */
function watchErrors(page) {
  const errors = [];
  const ignored = (s) => IGNORE.some((re) => re.test(s));
  page.on('pageerror', (e) => { if (!ignored(String(e.message))) errors.push('JS error: ' + e.message); });
  page.on('console', (m) => { if (m.type() === 'error' && !ignored(m.text())) errors.push('console: ' + m.text()); });
  page.on('response', (r) => {
    const u = r.url();
    if (r.status() >= 500 && u.startsWith(BASE)) errors.push(`HTTP ${r.status()} ${u}`);
  });
  return {
    errors,
    expectClean() { expect(errors, 'the page logged errors:\n' + errors.join('\n')).toEqual([]); },
  };
}

/** Sign the browser in as the seeded test account (php-backend/tools/e2e-seed.php), via the real API. */
async function login(page) {
  const res = await page.request.post('/api/auth/login', { data: { username: USER, password: PASSWORD } });
  expect(res.ok(), 'test account login failed — run php-backend/tools/e2e-seed.php').toBeTruthy();
  const data = await res.json();
  await page.addInitScript(([token, user]) => {
    localStorage.setItem('bet1x_auth_token', token);
    localStorage.setItem('bet1x_current_user', JSON.stringify(user));
    localStorage.setItem('bet1x_demo_wallet', Number(user.wallet_balance).toFixed(2));
    localStorage.setItem('bet1x_sound_muted', 'true');      // quiet test runs
  }, [data.token, data.user]);
  return data.user;
}

/** The page must not scroll sideways (the classic phone-layout bug). */
async function expectNoSidewaysScroll(page) {
  const { sw, iw } = await page.evaluate(() => ({ sw: document.documentElement.scrollWidth, iw: window.innerWidth }));
  expect(sw, `page is ${sw}px wide in a ${iw}px window — something overflows sideways`).toBeLessThanOrEqual(iw + 2);
}

/** Visible elements matching `selector` whose text does not fit (cut off / overflowing their box). */
async function overflowingText(page, selector) {
  return page.$$eval(selector, (els) => els.filter((e) => {
    const r = e.getBoundingClientRect();
    if (!r.width || getComputedStyle(e).display === 'none') return false;
    return e.scrollWidth > e.clientWidth + 1;
  }).map((e) => (e.textContent || '').trim().slice(0, 60)));
}

/** Attach a full-page screenshot to the HTML report, so every page can be reviewed at a glance. */
async function snapshot(page, testInfo, name) {
  await testInfo.attach(name, { body: await page.screenshot({ fullPage: true }), contentType: 'image/png' });
}

/** The first live / any match from the cricket lists (simulated league in local and CI runs). */
async function liveMatch(request) {
  const r = await request.get('/api/cricket/exchange-lobby');
  if (!r.ok()) return null;
  const d = await r.json();
  return (d.live && d.live[0]) || null;
}

/**
 * Chicken Road keeps a player's unfinished road across visits (correctly). A test that needs the Play
 * button first finishes any round left open: cash out if offered, else step until the round ends.
 */
async function finishChickenRound(page) {
  for (let i = 0; i < 40; i++) {
    if (await page.locator('#crPlayBtn').isVisible()) return;
    const cash = page.locator('#crCashBtn');
    if (await cash.isVisible() && await cash.isEnabled()) await cash.click();
    else if (await page.locator('#crGoBtn').isVisible() && await page.locator('#crGoBtn').isEnabled()) await page.locator('#crGoBtn').click();
    await page.waitForTimeout(800);
  }
  await expect(page.locator('#crPlayBtn')).toBeVisible();
}

module.exports = { finishChickenRound, USER, PASSWORD, watchErrors, login, expectNoSidewaysScroll, overflowingText, snapshot, liveMatch };
