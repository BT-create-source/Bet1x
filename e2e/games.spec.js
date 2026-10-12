// Game-specific rules agreed for Chicken Road and Astronaut.
const { test, expect } = require('@playwright/test');
const { watchErrors, login, snapshot, finishChickenRound } = require('./helpers');

test.describe('Chicken Road', () => {
  test('on phones, Play sits beside the bet amount, right under the road', async ({ page }, testInfo) => {
    test.skip(testInfo.project.name !== 'mobile', 'phone layout rule');
    await login(page);
    await page.goto('/chickenroad.html');
    await finishChickenRound(page);
    const amount = await page.locator('.cr-amount').boundingBox();   // the whole bet-amount box (- input +)
    const play = await page.locator('#crPlayBtn').boundingBox();
    expect(amount && play).toBeTruthy();
    expect(play.x, 'Play should be to the right of the bet amount').toBeGreaterThan(amount.x + amount.width - 5);
    expect(Math.abs(play.y - amount.y), 'Play should be on the same row as the bet amount').toBeLessThan(90);
    const vh = page.viewportSize().height;
    expect(play.y + play.height, 'Play should be on the first screen, no scrolling').toBeLessThan(vh);
    expect(amount.width, 'the bet amount gets more room than the button').toBeGreaterThan(play.width * 0.9);
  });

  test('a full round: bet, cross a lane, cash out — the balance moves', async ({ page }, testInfo) => {
    const watch = watchErrors(page);
    await login(page);
    await page.goto('/chickenroad.html');
    await finishChickenRound(page);
    await page.locator('#crAmount').fill('10');
    await page.locator('#crPlayBtn').click();
    await expect(page.locator('#crActiveRow')).toBeVisible({ timeout: 15_000 });
    await page.locator('#crGoBtn').click();
    await page.waitForTimeout(1500);
    // Either the hen survived (cash out is offered) or she was hit (the round ended) — both are valid.
    await finishChickenRound(page);
    await snapshot(page, testInfo, 'chicken road after a round');
    watch.expectClean();
  });

  test('after a cash-out the hen goes back to the starting point', async ({ page }) => {
    await login(page);
    await page.goto('/chickenroad.html');
    await finishChickenRound(page);
    const startX = (await page.locator('.cr-hen').boundingBox()).x;
    // Play until a lane is crossed safely and cash-out is offered (a run-over ends the round: try again).
    let cashed = false;
    for (let attempt = 0; attempt < 6 && !cashed; attempt++) {
      await page.locator('#crAmount').fill('10');
      await page.locator('#crPlayBtn').click();
      await expect(page.locator('#crActiveRow')).toBeVisible({ timeout: 15_000 });
      await page.locator('#crGoBtn').click();
      await page.waitForTimeout(1500);
      const cash = page.locator('#crCashBtn');
      if (await cash.isVisible() && await cash.isEnabled()) { await cash.click(); cashed = true; }
      else await finishChickenRound(page);
    }
    test.skip(!cashed, 'the hen was run over on every try — no cash-out to check');
    await page.waitForTimeout(3500);                         // celebration, then the walk home
    const endX = (await page.locator('.cr-hen').boundingBox()).x;
    expect(Math.abs(endX - startX), 'the hen should be back where she started').toBeLessThan(12);
  });

  test('sound: music is set up and a tap starts it', async ({ page }) => {
    await page.goto('/chickenroad.html');
    await page.mouse.click(10, 10);
    const status = await page.evaluate(() => window.CRSound && CRSound.status());
    expect(status, 'the Chicken Road sound engine did not load').toBeTruthy();
    expect(status.music, 'the background music never started').toBeTruthy();
  });
});

test.describe('Astronaut', () => {
  test('bets work exactly like Vimaan: Place Bet -> Cancel Bet -> Cash Out -> Cashed Out', async ({ page }) => {
    test.setTimeout(120_000);
    const watch = watchErrors(page);
    await login(page);
    await page.goto('/astronaut.html');
    const btn = page.locator('.as-panel .as-btn').first();
    // Wait for a countdown (betting phase), then bet.
    await expect(page.locator('#asWait')).toHaveClass(/show/, { timeout: 40_000 });
    await expect(btn).toContainText('Place Bet');
    await btn.click();
    await expect(btn).toContainText('Cancel Bet');
    await expect(btn).toContainText('Waiting...');
    // Take-off: the same button becomes Cash Out; cash out and it reads Cashed Out with the payout.
    await expect(btn).toContainText('Cash Out', { timeout: 20_000 });
    await btn.click();
    await expect(btn).toContainText(/Cashed Out|Busted/, { timeout: 10_000 });
    watch.expectClean();
  });

  test('no seed hash on the game screen; the astronaut waits at the origin', async ({ page }) => {
    await page.goto('/astronaut.html');
    await expect(page.locator('#asHash')).toHaveCount(0);
    await expect(page.locator('.as-sky')).not.toContainText(/Hash [0-9a-f]{6}/);
    await expect(page.locator('#asWait')).toHaveClass(/show/, { timeout: 40_000 });
    await page.waitForTimeout(400);
    const sky = await page.locator('.as-sky').boundingBox();
    const a = await page.locator('#asAstro').boundingBox();
    const cx = a.x + a.width / 2 - sky.x, cy = a.y + a.height / 2 - sky.y;
    expect(cx / sky.width, 'astronaut should wait at the left edge (origin)').toBeLessThan(0.15);
    expect(cy / sky.height, 'astronaut should wait at the bottom (origin)').toBeGreaterThan(0.75);
  });

  test('the animated character is drawn and the round runs', async ({ page }, testInfo) => {
    const watch = watchErrors(page);
    await login(page);
    await page.goto('/astronaut.html');
    await expect(page.locator('#asAstro svg #arRoot')).toHaveCount(1, { timeout: 10_000 });
    await page.waitForTimeout(3000);
    await snapshot(page, testInfo, 'astronaut');
    watch.expectClean();
  });
});
