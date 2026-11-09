const {test, expect} = require('@playwright/test');
const {login, watchErrors, expectNoSidewaysScroll, snapshot} = require('./helpers');
const games = ['dice','wheel','plinko','keno','roulette','baccarat','dragontiger','andarbahar','slots','matka'];
test.beforeEach(async ({page}) => {
  await page.route(/https:\/\/fonts\.(googleapis|gstatic)\.com\//, route => route.fulfill({status: 200, body: '', contentType: 'text/css'}));
});

for (const game of games) test(game + ': public results require no bet or account', async ({page, browser}, info) => {
  const errors = watchErrors(page);
  await page.goto('/' + game + '.html');
  await page.waitForFunction(() => window.Orig && Orig.state.publicRounds && Orig.state.publicRounds.history.length);
  await expect(page.locator('#ogPublic')).toBeVisible();
  await expect(page.locator('#ogPublicResult')).not.toBeEmpty();
  expect(await page.evaluate(() => Orig.state.signedIn)).toBe(false);
  expect(await page.evaluate(() => Orig.state.pending.length)).toBe(0);
  const other = await browser.newPage({baseURL: test.info().project.use.baseURL || process.env.BASE_URL || `http://127.0.0.1:${process.env.E2E_PORT || 5077}`});
  try {
    await other.goto('/' + game + '.html');
    await other.waitForFunction(() => window.Orig && Orig.state.publicRounds && Orig.state.publicRounds.history.length);
    // Polling lets both independent devices cross a reveal boundary before comparing snapshots.
    await expect.poll(async () => {
      const a = await page.evaluate(() => Orig.state.publicRounds.history[0]);
      const b = await other.evaluate(() => Orig.state.publicRounds.history[0]);
      return JSON.stringify(a) === JSON.stringify(b);
    }, {timeout: 10000}).toBe(true);
    await expectNoSidewaysScroll(page);
    await snapshot(page, info, game + ' public result without a bet');
    errors.expectClean();
  } finally { await other.close(); }
});

test('a shared Dice bet settles to the result seen by a non-betting device', async ({page, browser}) => {
  await login(page);
  await page.goto('/dice.html');
  await page.waitForFunction(() => window.Orig && Orig.state.signedIn && Orig.state.publicRounds);
  const before = await page.evaluate(() => Orig.state.balance);
  await expect(page.locator('#ogPlay')).toBeEnabled({timeout: 35000});
  const response = page.waitForResponse(r => r.url().endsWith('/dice/play') && r.request().method() === 'POST');
  await page.locator('#ogPlay').click();
  const accepted = await (await response).json();
  expect(accepted.ok).toBe(true);
  expect(accepted.round.status).toBe('queued');
  expect(accepted.round.result).toBeNull();
  const other = await browser.newPage();
  try {
    await other.goto((process.env.BASE_URL || `http://127.0.0.1:${process.env.E2E_PORT || 5077}`) + '/dice.html');
    await page.waitForFunction(ref => !Orig.state.busy && Orig.state.recent.some(r => r.round_ref === ref), accepted.round.round_ref, {timeout: 35000});
    const settled = await page.evaluate(ref => Orig.state.recent.find(r => r.round_ref === ref), accepted.round.round_ref);
    await other.waitForFunction(id => window.Orig && Orig.state.publicRounds && Orig.state.publicRounds.history.some(r => r.round_id === id), settled.shared_round_id, {timeout: 10000});
    const publicResult = await other.evaluate(id => Orig.state.publicRounds.history.find(r => r.round_id === id), settled.shared_round_id);
    expect(publicResult.result.roll).toBe(settled.result.roll);
    expect(await other.evaluate(() => Orig.state.signedIn)).toBe(false);
    expect(await page.evaluate(() => Orig.state.balance)).toBeCloseTo(before - settled.bet + settled.payout, 2);
  } finally { await other.close(); }
});
