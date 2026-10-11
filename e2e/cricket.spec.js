// The cricket screens: the home odds table, the match page and Ball by Ball — the rules agreed for them.
const { test, expect } = require('@playwright/test');
const { watchErrors, login, liveMatch, snapshot } = require('./helpers');

test.describe('Home: cricket odds table', () => {
  test('every match shows BM / F / S, full names (never cut with "..."), and opens its match page', async ({ page }, testInfo) => {
    const watch = watchErrors(page);
    await page.goto('/index.html');
    const box = page.locator('#homeCricket');
    await expect(box).toBeVisible({ timeout: 20_000 });
    const rows = page.locator('#homeCricketRows .hck-row');
    expect(await rows.count()).toBeGreaterThan(0);
    for (let i = 0; i < await rows.count(); i++) {
      const row = rows.nth(i);
      await expect(row.locator('.hck-mk a.bm')).toHaveCount(1);
      await expect(row.locator('.hck-mk a.f')).toHaveCount(1);
      await expect(row.locator('.hck-mk a.s')).toHaveCount(1);
      const fits = await row.locator('.nm').evaluate((e) => e.scrollWidth <= e.clientWidth + 1 && getComputedStyle(e).textOverflow !== 'ellipsis');
      expect(fits, `match name cut off in row ${i + 1}`).toBeTruthy();
    }
    const prices = rows.first().locator('.hck-px').first();
    if (testInfo.project.name === 'mobile') await expect(prices, 'phones show no prices on the home screen').toBeHidden();
    else await expect(prices).toBeVisible();
    await snapshot(page, testInfo, 'home cricket table');
    await rows.first().locator('.nm').click();
    await expect(page).toHaveURL(/cricket\.html#match\//);
    watch.expectClean();
  });
});

test.describe('Match page', () => {
  test('Recent Result shows the newest ball first', async ({ page, request }) => {
    const m = await liveMatch(request);
    test.skip(!m, 'no live match right now');
    const st = await (await request.get('/api/cricket/exchange/' + encodeURIComponent(m.match_key))).json();
    const expected = ((st.scoreboard && st.scoreboard.last_balls) || []).slice().reverse().map((b) => String(b.token));
    test.skip(!expected.length, 'no balls bowled yet');
    await page.goto('/cricket.html#match/' + encodeURIComponent(m.match_key));
    const shown = await page.locator('#recent .ck-ball').allTextContents();
    expect(shown.length).toBeGreaterThan(0);
    // The page and the API are read a moment apart, so compare the overlapping start (newest balls).
    const n = Math.min(4, shown.length, expected.length);
    expect(shown.slice(0, n).map((s) => s.trim())).toEqual(expected.slice(0, n));
  });
});

test.describe('Ball by Ball', () => {
  test('This over shows the newest ball first; markets render', async ({ page, request }, testInfo) => {
    const m = await liveMatch(request);
    test.skip(!m, 'no live match right now');
    const watch = watchErrors(page);
    await login(page);
    await page.goto('/boundarybaazi.html#match/' + encodeURIComponent(m.match_key));
    await expect(page.locator('#tiles')).toBeVisible({ timeout: 20_000 });
    const st = await (await request.get('/api/cricket/bbb/' + encodeURIComponent(m.match_key) + '/state')).json();
    const expected = ((st.scoreboard && st.scoreboard.this_over) || []).slice().reverse().map(String);
    const shown = (await page.locator('#stOver .bb-ball').allTextContents()).map((s) => s.trim());
    if (expected.length && shown.length) {
      const n = Math.min(3, shown.length, expected.length);
      expect(shown.slice(0, n)).toEqual(expected.slice(0, n));
    }
    expect(st.latest_ball === null || typeof st.latest_ball.uid === 'string').toBeTruthy();
    await snapshot(page, testInfo, 'ball by ball');
    watch.expectClean();
  });
});
