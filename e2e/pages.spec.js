// Every player-facing page, on a laptop and on a phone: it loads, nothing errors, nothing scrolls
// sideways, the game tabs fit their labels, and a full-page screenshot is attached to the report.
const { test, expect } = require('@playwright/test');
const { watchErrors, login, expectNoSidewaysScroll, overflowingText, snapshot } = require('./helpers');

const PAGES = [
  ['Home', '/index.html', '#homeCricket, .game-cover-card'],
  ['Vimaan', '/aviator.html', 'canvas'],
  ['Sapre', '/win.html', 'body'],
  ['Becone', '/win1.html', 'body'],
  ['Emred', '/win2.html', 'body'],
  ['VIP', '/win3.html', 'body'],
  ['Teen Patti', '/teenpatti.html', 'body'],
  ['Mines', '/mining.html', 'body'],
  ['Chicken Road', '/chickenroad.html', '.cr-actions'],
  ['Astronaut', '/astronaut.html', '#asAstro'],
  ['Cricket', '/cricket.html', 'body'],
  ['Your 11', '/youreleven.html', 'body'],
  ['Ball by Ball', '/boundarybaazi.html', '#viewLobby'],
  ['Cashier', '/cashier.html', 'body'],
];

for (const [name, path, mustSee] of PAGES) {
  test(`${name} loads cleanly`, async ({ page }, testInfo) => {
    const watch = watchErrors(page);
    await login(page);
    const res = await page.goto(path, { waitUntil: 'domcontentloaded' });
    expect(res.status(), `${path} answered ${res.status()}`).toBeLessThan(400);
    await expect(page.locator(mustSee).first()).toBeVisible();
    await page.waitForTimeout(2500);                       // let the page's first polls and animations run
    await expectNoSidewaysScroll(page);
    const cut = await overflowingText(page, '.sub-navbar-link');
    expect(cut, 'game tab labels overflowing their pills: ' + cut.join(', ')).toEqual([]);
    await snapshot(page, testInfo, `${name} (${testInfo.project.name})`);
    watch.expectClean();
  });
}
