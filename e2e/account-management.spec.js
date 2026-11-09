const {test, expect} = require('@playwright/test');
const path = require('node:path');
test('super admin searches and confirms the exact player before deletion', async ({page}) => {
  await page.setContent('<form id="account-search-form"><input id="account-query"><button>Search accounts</button></form><p id="account-status"></p><div id="account-results"></div>');
  await page.evaluate(() => {
    window.calls = [];
    window.adminFetch = async (url, options) => {
      window.calls.push({url, options});
      return {ok:true, json:async () => url.includes('/search') ? {users:[{id:17,username:'player',email:'player@example.invalid',phone:'9999999999',phone_verified:0,wallet_balance:40}]} : {success:true}};
    };
  });
  await page.addScriptTag({path:path.resolve('assets/js/superadmin-accounts.js')});
  await page.locator('#account-query').fill('player@example.invalid');
  await page.getByText('Search accounts', {exact:true}).click();
  await expect(page.locator('#account-results')).toContainText('Phone unverified');
  page.once('dialog', dialog => dialog.accept('DELETE wrong'));
  await page.getByText('Delete permanently').click();
  expect(await page.evaluate(() => window.calls.length)).toBe(1);
  page.once('dialog', dialog => dialog.accept('DELETE player'));
  await page.getByText('Delete permanently').click();
  await expect(page.locator('#account-status')).toContainText('Account deleted');
  const calls = await page.evaluate(() => window.calls);
  expect(calls[0].url).toContain('q=player%40example.invalid');
  expect(JSON.parse(calls[1].options.body)).toEqual({id:17,confirmation:'DELETE player'});
  await expect(page.locator('#account-results')).toBeEmpty();
});
