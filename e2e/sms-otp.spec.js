const {test,expect}=require('@playwright/test');
const {execFileSync}=require('node:child_process');
test.skip(process.env.SMS_MOCK !== '1', 'Requires the local fake SMS provider; never send SMS from the normal UI suite.');
test.beforeEach(async({page})=>{
 await page.route(/https:\/\/fonts\.(googleapis|gstatic)\.com\//,r=>r.fulfill({status:200,body:'',contentType:'text/css'}));
});
test('SMS signup verification and password recovery',{tag:'@sms-mock'},async({page,request})=>{
 const name='smstest_ui'+String(Date.now()).slice(-6),phone='96'+String(Date.now()).slice(-8);
 try {
  await page.goto('/auth.html?tab=signup');
  await expect(page.locator('#signup-phone-send')).toBeVisible();
  await page.locator('#signup-username').fill(name);
  await page.locator('#signup-email').fill(name+'@example.invalid');
  await page.locator('#signup-phone').fill(phone);
  await page.locator('#signup-password').fill('SMS-Test-Pass-123');
  await page.locator('#signup-confirm-password').fill('SMS-Test-Pass-123');
  await page.locator('#form-signup button[type=submit]').click();
  await expect(page.locator('#signup-error')).toContainText('verify your mobile');
  await page.locator('#signup-phone-send').click();
  await expect(page.locator('#signup-phone-code-group')).toBeVisible();
  const code=(await(await request.get('http://127.0.0.1:5260/test-code?phone='+phone)).json()).code;
  await page.locator('#signup-phone-code').fill(code);
  await page.locator('#signup-phone-verify').click();
  await expect(page.locator('#signup-phone-status')).toHaveText('Mobile number verified.');
  const signup=page.waitForResponse(r=>r.url().includes('/api/auth.php?action=signup'));
  await page.locator('#form-signup button[type=submit]').click();
  expect((await(await signup).json()).success).toBe(true);
  await page.waitForURL('**/index.html');
  await page.evaluate(()=>localStorage.clear());
  await page.goto('/auth.html');
  await page.waitForFunction(()=>window.bet1xPhoneVerify.enabled);
  await page.locator('#show-forgot-password').click();
  await expect(page.locator('label[for=forgot-email]')).toHaveText('Registered Mobile Number');
  await page.locator('#forgot-email').fill(phone);
  await page.locator('#forgot-otp-send').click();
  await expect(page.locator('#forgot-otp-group')).toBeVisible();
  const resetCode=(await(await request.get('http://127.0.0.1:5260/test-code?phone='+phone)).json()).code;
  await page.locator('#forgot-otp').fill(resetCode);
  await page.locator('#forgot-otp-verify').click();
  await expect(page.locator('#forgot-newpass-group')).toBeVisible();
  await page.locator('#forgot-new-password').fill('SMS-New-Pass-123');
  await page.locator('#forgot-confirm-password').fill('SMS-New-Pass-123');
  const reset=page.waitForResponse(r=>r.url().endsWith('/api/auth/phone-reset/reset'));
  await page.locator('#forgot-reset-btn').click();
  expect((await(await reset).json()).success).toBe(true);
 } finally {execFileSync(process.env.PHP_BIN||'php',['-d','extension=pdo_pgsql','php-backend/tests/sms_fixture.php','cleanup',name]);}
});
