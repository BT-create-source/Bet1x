const {test, expect} = require('@playwright/test');
const targets = {
  roulette:'.rl-c[data-k="red|"]',
  baccarat:'.bc-spot[data-k="player"]',
  andarbahar:'.ab-spot[data-k="andar|"]',
  dragontiger:'#dtSpots [data-side="dragon"]',
  matka:'#mkAdd'
};
test.beforeEach(async ({page}) => {
  await page.route(/https:\/\/fonts\.(googleapis|gstatic)\.com\//, r => r.fulfill({body:'',contentType:'text/css'}));
});
for (const [game, target] of Object.entries(targets)) test(game + ': custom table amount and presets stay connected', async ({page}) => {
  await page.goto('/' + game + '.html');
  await page.waitForFunction(() => window.Orig?.state.config && !Orig.state.busy);
  const input = page.locator('#ogChipAmount');
  await page.getByText('Bet amount', {exact:true}).click();
  await expect(input).toBeFocused();
  await input.fill('37.50');
  await page.locator('.og-chip[data-v="100"]').click();
  await expect(input).toHaveValue('100');
  await input.fill('37.50');
  if (game === 'matka') await page.locator('#mkValue').fill('3');
  await page.locator(target).click();
  const amounts = await page.evaluate(() => Orig.state.opt.collect().bets.map(b => b.amount));
  expect(amounts).toEqual([37.5]);
  await expect(page.locator('#ogTotal')).toHaveText('₹37.50');
  await expect(page.locator('.og-chip.on')).toHaveCount(0);
  await page.locator('#ogUndo').click();
  await expect(page.locator('#ogTotal')).toHaveText('₹0.00');
});
test('Dice amount can be focused by its label and typed with decimals', async ({page}) => {
  await page.goto('/dice.html');
  await page.waitForFunction(() => window.Orig?.state.config && !Orig.state.busy);
  await page.getByText('Bet amount', {exact:true}).click();
  const input = page.locator('#ogAmount');
  await expect(input).toBeFocused();
  await input.fill('37.50');
  await input.blur();
  expect(await page.evaluate(() => Orig.amount())).toBe(37.5);
  await page.locator('[data-q="100"]').click();
  await expect(input).toHaveValue('100');
});
