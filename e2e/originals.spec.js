const { test, expect } = require('@playwright/test');
const { login, watchErrors, expectNoSidewaysScroll, snapshot } = require('./helpers');

const games = ['dice','coinflip','wheel','plinko','keno','roulette','baccarat','andarbahar','slots','blackjack','matka'];
test.beforeEach(async ({page}) => {
  // Use the local font fallbacks when external font services are unavailable.
  await page.route(/https:\/\/fonts\.(googleapis|gstatic)\.com\//, route => route.fulfill({status:200,body:'',contentType:'text/css'}));
});
async function ready(page,game) {
  await page.goto('/'+game+'.html');
  await page.waitForFunction(() => window.Orig && Orig.state.config && Orig.state.signedIn);
}
async function finish(page) {
  for(let i=0;i<30;i++) {
    await page.waitForFunction(() => !Orig.state.busy);
    const state=await page.evaluate(() => ({game:Orig.state.game,open:Orig.state.open}));
    if(!state.open) return;
    if(state.game==='coinflip') await page.locator('#cfCash').click();
    else {
      const a=state.open.result.phase==='insurance'?'no_insurance':'stand';
      await page.locator('[data-action="'+a+'"]').click();
    }
  }
  throw new Error('Round did not settle');
}

for(const game of games) {
  test(game+': real bet, wallet, history and responsive layout',async ({page},testInfo) => {
    const watch=watchErrors(page);
    await login(page); await ready(page,game); await finish(page);
    const before=await page.evaluate(() => Orig.state.balance);
    const hash=await page.evaluate(() => Orig.state.seed.server_seed_hash);
    expect(hash).toMatch(/^[a-f0-9]{64}$/);
    if(game==='keno') await page.locator('#knAuto').click();
    if(game==='roulette') await page.locator('[data-k="red|"]').click();
    if(game==='baccarat') await page.locator('[data-k="banker"]').click();
    if(game==='andarbahar') await page.locator('[data-k="andar|"]').click();
    if(game==='matka') { await page.locator('#mkValue').fill('7'); await page.locator('#mkAdd').click(); }
    const response=page.waitForResponse(r=>r.url().endsWith('/'+game+'/play')&&r.request().method()==='POST');
    await page.locator('#ogPlay').click();
    const data=await (await response).json();
    expect(data.ok,JSON.stringify(data)).toBe(true);
    expect(data.round.server_seed_hash).toBe(hash);
    await page.waitForFunction(() => !Orig.state.busy);
    expect(await page.evaluate(() => Orig.state.balance)).toBeCloseTo(before-data.round.bet+data.round.payout,2);
    await finish(page);
    const round=await page.evaluate(() => Orig.state.recent[0]);
    expect(round.status).toBe('settled');
    await expect(page.locator('#ogHistory tbody tr')).not.toHaveCount(0);
    await expect(page.locator('#ogMsg')).toBeEmpty();
    await expectNoSidewaysScroll(page);
    if(game==='plinko') {
      const bounds=await page.evaluate(()=>{
        const stage=document.getElementById('ogStage').getBoundingClientRect();
        return [...document.querySelectorAll('.pk-b')].map(el=>{const b=el.getBoundingClientRect(); return {left:b.left-stage.left,right:stage.right-b.right};});
      });
      expect(bounds).toHaveLength(17);
      expect(bounds.every(b=>b.left>=0&&b.right>=0),'all payout buckets must be visible inside the board').toBe(true);
      await page.locator('.pk-b').last().click();
      await expect(page.locator('#pkBucketInfo')).toContainText('Bucket 16');
    }
    await snapshot(page,testInfo,game+' after round');
    await page.screenshot({path:testInfo.outputPath(game+'-page.png'),fullPage:true});
    await page.getByRole('button',{name:'Menu',exact:true}).click();
    for(const k of games) await expect(page.locator('.gs-drawer a[href="'+k+'.html"]')).toHaveCount(1);
    watch.expectClean();
  });
}

test('fairness: rotate and verify a completed Dice round',async ({page}) => {
  const watch=watchErrors(page);
  await login(page); await ready(page,'dice');
  await page.locator('#ogPlay').click();
  await page.waitForFunction(() => !Orig.state.busy && Orig.state.recent.length>0);
  const hash=await page.evaluate(() => Orig.state.seed.server_seed_hash);
  await page.locator('#ogClientSeed').fill('browser-verification');
  await page.locator('#ogRotate').click();
  await page.waitForFunction(h=>Orig.state.seed.server_seed_hash!==h,hash);
  await expect(page.locator('#ogMsg')).toContainText('Seed changed');
  await page.locator('[data-verify="0"]').click();
  await expect(page.locator('#ogVerifyOut')).toContainText('matches the hash');
  watch.expectClean();
});

test('Blackjack restores an open hand after reload and accepts the next action',async ({page}) => {
  await login(page); await ready(page,'blackjack'); await finish(page);
  for(let i=0;i<12;i++) {
    await page.locator('#ogPlay').click(); await page.waitForFunction(()=>!Orig.state.busy);
    if(await page.evaluate(()=>!!Orig.state.open)) break;
  }
  expect(await page.evaluate(()=>!!Orig.state.open)).toBe(true);
  let phase=await page.evaluate(()=>Orig.state.open.result.phase);
  if(phase==='insurance') {await page.locator('[data-action="no_insurance"]').click();await page.waitForFunction(()=>!Orig.state.busy);}
  if(await page.evaluate(()=>!!Orig.state.open)) {
    const old=await page.evaluate(()=>({ref:Orig.state.open.round_ref,step:Orig.state.step,balance:Orig.state.balance}));
    await page.reload(); await page.waitForFunction(()=>Orig.state.config&&Orig.state.signedIn);
    expect(await page.evaluate(()=>Orig.state.open.round_ref)).toBe(old.ref);
    expect(await page.evaluate(()=>Orig.state.step)).toBe(old.step);
    expect(await page.evaluate(()=>Orig.state.balance)).toBe(old.balance);
    await expect(page.locator('#ogPlay')).toBeHidden();
    await finish(page);
  }
});

test('lobby Originals filter displays all eleven games',async ({page}) => {
  await page.goto('/index.html');
  await page.locator('[data-filter="originals"]').click();
  for(const game of games) await expect(page.locator('#gamesContainer a[href="'+game+'.html"]')).toBeVisible();
  await expect(page.locator('#gamesContainer a[href="aviator.html"]')).toBeHidden();
  await expectNoSidewaysScroll(page);
});

test('operator can review and save all eleven Originals configurations',async ({page}) => {
  test.skip(!!process.env.BASE_URL,'local development token only');
  const {execFileSync}=require('child_process');
  const path=require('path');
  const token=execFileSync(process.env.PHP_BIN||'php',[path.join(__dirname,'originals-admin-token.php')],{encoding:'utf8'}).trim();
  expect(token).not.toBe('');
  await page.addInitScript(t=>{localStorage.setItem('bet1x_admin_token',t);localStorage.setItem('bet1x_admin_logged_in','true');localStorage.setItem('admin_active_tab','originals');},token);
  await page.goto('/admin.html');
  await expect(page.locator('#admin-originals form')).toHaveCount(11);
  await expect(page.locator('#admin-originals')).toBeVisible();
  const fixed=page.locator('#admin-originals [data-game="blackjack"] [name="rtp"]');
  await expect(fixed).toBeDisabled();
  const dice=page.locator('#admin-originals [data-game="dice"]');
  await dice.getByRole('button',{name:'Save Dice'}).click();
  await expect(dice.locator('[data-message]')).toHaveText('Saved.', {timeout:30000});
  await page.getByRole('button',{name:'Game Betting History',exact:true}).click();
  await expect(page.locator('#adminWagersBody')).toContainText('Dice', {timeout:30000});
  await expect(page.locator('#adminWagersBody')).toContainText('Blackjack', {timeout:30000});
});

test('an open game refreshes external funding and accepts it without a reload',async ({page}) => {
  test.skip(!!process.env.BASE_URL,'local development token only');
  const {execFileSync}=require('child_process'), path=require('path');
  const token=execFileSync(process.env.PHP_BIN||'php',[path.join(__dirname,'originals-admin-token.php')],{encoding:'utf8'}).trim();
  const user=await login(page); await ready(page,'dice');
  const original=await page.evaluate(()=>Orig.state.balance);
  const adjust=async delta=>{
    const res=await page.request.post('/api/wallet/adjust',{headers:{Authorization:'Bearer '+token},data:{username:user.username,delta,details:'Originals external balance test'}});
    expect(res.ok()).toBe(true);
  };
  await adjust(5-original);
  await page.evaluate(()=>window.dispatchEvent(new Event('focus')));
  await page.waitForFunction(()=>Orig.state.balance===5);
  try {
    await adjust(1000);
    await page.locator('#ogAmount').fill('10');
    const response=page.waitForResponse(r=>r.url().endsWith('/dice/play'));
    await page.locator('#ogPlay').click();
    const d=await (await response).json(); expect(d.ok).toBe(true);
    await page.waitForFunction(()=>!Orig.state.busy);
    expect(await page.evaluate(()=>Orig.state.balance)).toBe(d.balance);
    await expect(page.locator('#ogMsg')).toBeEmpty();
  } finally { await adjust(original-1005); }
});

test('an open game clears the old account history when another account signs in',async ({page}) => {
  test.skip(!!process.env.BASE_URL,'local development accounts only');
  const {execFileSync}=require('child_process'), path=require('path');
  execFileSync(process.env.PHP_BIN||'php',[path.join(__dirname,'../php-backend/tools/e2e-seed.php')],{env:{...process.env,E2E_USER:'e2e_originals_switch',E2E_PASSWORD:'Originals-Switch-1'}});
  await login(page); await ready(page,'dice');
  const response=await page.request.post('/api/auth/login',{data:{username:'e2e_originals_switch',password:'Originals-Switch-1'}});
  expect(response.ok()).toBe(true); const d=await response.json();
  await page.evaluate(({token,user})=>{
    localStorage.setItem('bet1x_auth_token',token); localStorage.setItem('bet1x_current_user',JSON.stringify(user));
    window.dispatchEvent(new StorageEvent('storage',{key:'bet1x_auth_token'}));
  },d);
  await page.waitForFunction(t=>Orig.state.token===t,d.token);
  expect(await page.evaluate(()=>Orig.state.balance)).toBe(100000);
  await expect(page.locator('#ogHistory')).toContainText('No rounds yet');
  await expect(page.locator('#ogWagered')).toContainText('0.00');
});

test('Roulette inside bets can be selected, undone and settled',async ({page}) => {
  await login(page); await ready(page,'roulette');
  for(const type of ['split','street','corner','line']) {
    await page.locator('#rlInsideType').selectOption(type);
    await page.locator('#rlInsideAdd').click();
  }
  await expect(page.locator('#ogTotal')).toContainText('40.00');
  await page.locator('#ogUndo').click();
  await expect(page.locator('#ogTotal')).toContainText('30.00');
  const response=page.waitForResponse(r=>r.url().endsWith('/roulette/play'));
  await page.locator('#ogPlay').click();
  const d=await (await response).json();
  expect(d.ok).toBe(true); expect(d.round.params.bets.map(b=>b.type)).toEqual(['split','street','corner']);
  await page.waitForFunction(()=>!Orig.state.busy);
  await expect(page.locator('#ogMsg')).toBeEmpty();
});
