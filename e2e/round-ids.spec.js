const {test, expect} = require('@playwright/test');
const {expectNoSidewaysScroll} = require('./helpers');
const games = ['dice','coinflip','wheel','plinko','keno','roulette','baccarat','dragontiger','andarbahar','slots','blackjack','matka'];
test.beforeEach(async ({page}) => {
  await page.route(/https:\/\/fonts\.(googleapis|gstatic)\.com\//, r => r.fulfill({status:200,body:'',contentType:'text/css'}));
});
for (const game of games) test(game + ': round ID is visible at the top without a bet', async ({page}) => {
  await page.goto('/' + game + '.html');
  const badge = page.locator('#ogRoundId');
  await expect(badge).toBeVisible();
  await expect(badge).toHaveText(/^(Round ID|Betting window ID): \d+$/);
  const bounds = await badge.boundingBox();
  expect(bounds.y).toBeLessThan(200);
  await expectNoSidewaysScroll(page);
});
