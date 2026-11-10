const {test, expect} = require('@playwright/test');
const fs = require('node:fs');
const {watchErrors} = require('./helpers');
const games = ['dice','wheel','plinko','keno','roulette','baccarat','dragontiger','andarbahar','slots','matka'];
const framework = fs.readFileSync('assets/js/originals.js','utf8') + `
const originalMount = Orig.mount;
Orig.mount = options => {
  const animate = options.onResult;
  options.onResult = async (...args) => {
    window.publicAnimationCalls = (window.publicAnimationCalls || 0) + 1;
    return animate(...args);
  };
  return originalMount(options);
};`;

for (const game of games) test(game + ': a non-bettor sees the public round animate on the stage', async ({page, request}) => {
  const errors = watchErrors(page);
  const state = await (await request.get('/api/originals/' + game + '/state')).json();
  expect(state.ok).toBe(true);
  const source = state.public_rounds.history[0];
  expect(source).toBeTruthy();
  let revealed = false;
  let id = state.public_rounds.current.round_id + 100;
  const clock = {round_id:id,phase:'betting',server_time:Date.now(),betting_closes_at:Date.now()+15000,next_round_at:Date.now()+30000};
  const published = {...source,round_id:id};
  if (game === 'slots') published.result = {...source.result,free_spins:[
    {grid:source.result.grid,wins:[],win:0}, {grid:source.result.grid,wins:[],win:0}
  ]};
  const publicData = () => ({...state.public_rounds, current:{...state.public_rounds.current,round_id:id},history:revealed ? [published] : []});
  await page.route(/https:\/\/fonts\.(googleapis|gstatic)\.com\//, r => r.fulfill({body:'',contentType:'text/css'}));
  await page.route('**/assets/js/originals.js?*', r => r.fulfill({body:framework,contentType:'application/javascript'}));
  await page.route('**/api/originals/' + game + '/*', async route => {
    const path = new URL(route.request().url()).pathname;
    if (path.endsWith('/state')) return route.fulfill({json:{...state,signed_in:game==='roulette',balance:game==='roulette'?777:null,seed:null,recent:[],pending:[],open_round:null,public_rounds:publicData()}});
    if (path.endsWith('/public')) return route.fulfill({json:{ok:true,clock:{...clock,server_time:Date.now(),phase:revealed?'closed':'betting'},public_rounds:publicData()}});
    return route.continue();
  });
  await page.goto('/' + game + '.html');
  await page.waitForFunction(() => window.Orig?.state.stageReady);
  if (game === 'wheel') { await page.locator('#whRisk [data-v=high]').click(); await page.locator('#whSegs [data-v="30"]').click(); }
  if (game === 'plinko') {
    await page.locator('#pkRows').evaluate(input => { input.value = '8'; });
    await page.locator('#pkRows').dispatchEvent('input');
  }
  expect(await page.evaluate(() => window.publicAnimationCalls || 0)).toBe(0);
  revealed = true;
  await expect(page.locator('#ogStage')).toHaveAttribute('data-playback','playing');
  await expect(page.locator('#ogStage')).toHaveAttribute('data-round-id',String(id));
  await expect(page.locator('#ogStage')).toHaveAttribute('data-playback','complete');
  const r = published.result;
  if (game === 'dice') {
    await expect(page.locator('#dcRoll')).toHaveText(Number(r.roll).toFixed(2));
    await expect(page.locator('#dcRoll')).not.toHaveClass(/win|lose/);
  }
  if (game === 'roulette') {
    await expect(page.locator('#rlRes')).toHaveText(String(r.number));
    expect(await page.locator('#rlWheel').getAttribute('style')).toContain('rotate(');
  }
  if (game === 'wheel') {
    const multiplier = await page.evaluate(angle => Orig.fmtMult(Orig.state.info.tables.high[30][Math.floor(angle/360*30)]), r.position_degrees);
    await expect(page.locator('#whHub')).toHaveText(multiplier);
  }
  if (game === 'plinko') {
    await expect(page.locator('#pkBuckets .hit')).toHaveCount(1);
    await expect(page.locator('#pkBucketInfo')).toContainText('Bucket ' + r.path.slice(0,8).filter(Boolean).length + ' ·');
  }
  if (game === 'keno') await expect(page.locator('#knBoard .drawn')).toHaveCount(r.drawn.length);
  if (game === 'baccarat') {
    await expect(page.locator('#bcPC .og-card-face')).toHaveCount(r.player.length);
    await expect(page.locator('#bcPT')).toHaveText(String(r.player_total));
    await expect(page.locator('#bcBT')).toHaveText(String(r.banker_total));
  }
  if (game === 'dragontiger') {
    await expect(page.locator('#dtDC .og-card-face')).toHaveCount(1);
    await expect(page.locator('#dtTC .og-card-face')).toHaveCount(1);
    await expect(page.locator('#dtResult')).toContainText(r.winner === 'tie' ? 'Tie' : r.winner === 'dragon' ? 'Dragon wins' : 'Tiger wins');
  }
  if (game === 'andarbahar') await expect(page.locator('#abAC .og-card-face')).toHaveCount(r.andar.length);
  if (game === 'slots') await expect(page.locator('.sl-cell')).toHaveCount(15);
  if (game === 'matka') {
    await expect(page.locator('#mkOpen')).toHaveText(String(r.open_panna));
    await expect(page.locator('#mkJodi')).toHaveText(String(r.jodi));
    await expect(page.locator('#mkClose')).toHaveText(String(r.close_panna));
  }
  await page.evaluate(() => Orig.reload()); // Identical polls / state refreshes never replay the reveal.
  expect(await page.evaluate(() => window.publicAnimationCalls)).toBe(1);
  expect(await page.evaluate(() => Orig.state.session)).toEqual({staked:0,won:0});
  if (game === 'roulette') expect(await page.evaluate(() => Orig.state.balance)).toBe(777);
  await expect(page.locator('#ogBanner')).not.toHaveClass(/show/);
  if (['roulette','dragontiger'].includes(game)) await page.screenshot({path:'.scratch/public-stage-' + game + '-' + test.info().project.name + '.png'});
  errors.expectClean();
  if (game === 'roulette') {
    // Advancing the round plays again, while a late arrival sees the previous result immediately.
    revealed = false; id++; clock.round_id = id; clock.betting_closes_at = Date.now()+15000;
    published.round_id = id;
    await page.evaluate(() => Orig.reload());
    expect(await page.evaluate(() => window.publicAnimationCalls)).toBe(1);
    revealed = true;
    await expect(page.locator('#ogStage')).toHaveAttribute('data-round-id',String(id));
    await expect(page.locator('#ogStage')).toHaveAttribute('data-playback','complete');
    expect(await page.evaluate(() => window.publicAnimationCalls)).toBe(2);
    published.round_id = id-1;
    clock.betting_closes_at = Date.now()+15000;
    await page.reload({waitUntil:'domcontentloaded'});
    await expect(page.locator('#ogStage')).toHaveAttribute('data-playback','complete',{timeout:2000});
    await expect(page.locator('#rlRes')).toHaveText(String(r.number));
    errors.expectClean();
  }
});
