const {test, expect} = require('@playwright/test');
test('lobby appears without a splash and defers off-screen game covers', async ({page}) => {
  await page.route(/https:\/\/fonts\.(googleapis|gstatic)\.com\//, route => route.fulfill({body:'',contentType:'text/css'}));
  const covers = new Set();
  page.on('request', request => { if (/\/assets\/game_[^/]+\.(webp|jpg)/.test(request.url())) covers.add(request.url()); });
  await page.goto('/index.html', {waitUntil:'domcontentloaded'});
  await expect(page.locator('#gamesContainer')).toBeVisible();
  await expect(page.locator('#bet1x-splash')).toHaveCount(0);
  expect((await page.locator('html').getAttribute('class')) || '').not.toContain('splash-booting');
  await expect(page.locator('.game-cover-card-bg[loading=lazy]')).toHaveCount(24);
  expect([...covers].some(url => url.includes('game_matka'))).toBe(false);
  const matka = page.locator('img[data-src="assets/game_matka.webp"]');
  await matka.scrollIntoViewIfNeeded();
  await expect.poll(() => matka.evaluate(img => img.complete && img.naturalWidth > 0)).toBe(true);
  expect(await matka.evaluate(img => getComputedStyle(img).objectFit)).toBe('cover');
});
