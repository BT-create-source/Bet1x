/**
 * Chicken Road scene art — the road, pavement, props, vehicles and effects, drawn in the SAME
 * illustration style as the rigged hen (hen-studio): one dark ink outline colour, rounded shapes,
 * soft cel shading with a darker underside and a glossy highlight. Original artwork, all vector.
 *
 *   CRScene.ensure()                inject the shared <defs> (symbols, gradients) and the scene CSS
 *   CRScene.kerb(side, w, h, L)     inline SVG for the start ('start') or finish ('end') pavement
 *   CRScene.VEHICLES                [{ id, w, h, weight }] — symbol ids and viewBox sizes
 *   CRScene.fx.dust(layer, x, y, L) dust puffs where the hen lands
 *   CRScene.fx.sparkle(layer, x, y, L)   sparkles round a fresh coin
 *   CRScene.fx.bonk(layer, x, y, L, stage) cartoon impact burst + screen shake
 *   CRScene.fx.coins(layer, x, y, L, n)    a fountain of coins (cash-out / Golden Egg)
 */
(function () {
  'use strict';
  const INK = '#2a1a14';          // matches the hen's outline (#1c0905), a touch warmer for the props
  const NS = 'http://www.w3.org/2000/svg';

  // ---------------------------------------------------------------------------------------------
  // Vehicles (top-down, driving DOWN the screen: front at the bottom). One builder keeps every car
  // in the same style; each type adds its own roof features.
  // ---------------------------------------------------------------------------------------------
  function grad(id, light, mid, dark) {
    return `<linearGradient id="${id}" x1="0" y1="0" x2="1" y2="0">
      <stop offset="0" stop-color="${light}"/><stop offset="0.45" stop-color="${mid}"/><stop offset="1" stop-color="${dark}"/></linearGradient>`;
  }
  function car(id, h, c, extras = '', opts = {}) {
    const w = 64, r = opts.round ?? 15;
    const front = h - 6;
    const wheels = [[0, h * 0.18], [56, h * 0.18], [0, h * 0.72], [56, h * 0.72]]
      .map(([x, y]) => `<rect x="${x}" y="${y.toFixed(1)}" width="8" height="${(h * 0.13).toFixed(1)}" rx="3" fill="#29222a" stroke="${INK}" stroke-width="2"/>`).join('');
    const glass = opts.noCabin ? '' : `
      <path d="M13 ${front - 32} H51 L47 ${front - 15} Q32 ${front - 12} 17 ${front - 15} Z" fill="url(#crGlass)" stroke="${INK}" stroke-width="2.6" stroke-linejoin="round"/>
      <path d="M20 ${front - 29} L28 ${front - 29} L23 ${front - 18} L17 ${front - 18} Z" fill="#fff" opacity="0.5"/>
      <path d="M17 ${opts.rearY ?? 16} H47 L50 ${(opts.rearY ?? 16) + 10} H14 Z" fill="url(#crGlass)" stroke="${INK}" stroke-width="2.4" stroke-linejoin="round"/>`;
    const roof = opts.noRoof ? '' : `<rect x="13" y="${(h * 0.3).toFixed(1)}" width="38" height="${(h * 0.34).toFixed(1)}" rx="8" fill="url(#g_${id})" stroke="${INK}" stroke-width="2" opacity="0.9"/>
      <rect x="17" y="${(h * 0.32).toFixed(1)}" width="7" height="${(h * 0.28).toFixed(1)}" rx="3" fill="#fff" opacity="0.35"/>`;
    return `<symbol id="${id}" viewBox="-2 -2 ${w + 4} ${h + 10}">
      ${grad('g_' + id, c[0], c[1], c[2])}
      <ellipse cx="18" cy="${h + 2}" rx="11" ry="6" fill="#fff6b0" opacity="0.35"/><ellipse cx="46" cy="${h + 2}" rx="11" ry="6" fill="#fff6b0" opacity="0.35"/>
      ${wheels}
      <rect x="4" y="3" width="56" height="${h - 6}" rx="${r}" fill="url(#g_${id})" stroke="${INK}" stroke-width="3"/>
      <rect x="8.5" y="11" width="6" height="${h - 28}" rx="3" fill="#fff" opacity="0.32"/>
      <path d="M12 ${front - 44} Q32 ${front - 48} 52 ${front - 44}" fill="none" stroke="${INK}" stroke-width="1.6" opacity="0.35"/>
      ${roof}${glass}
      <ellipse cx="2.5" cy="${front - 36}" rx="3.2" ry="2.4" fill="${c[1]}" stroke="${INK}" stroke-width="1.6"/>
      <ellipse cx="61.5" cy="${front - 36}" rx="3.2" ry="2.4" fill="${c[1]}" stroke="${INK}" stroke-width="1.6"/>
      <rect x="9" y="${front - 7}" width="12" height="6" rx="3" fill="#fff8cf" stroke="${INK}" stroke-width="2"/>
      <rect x="43" y="${front - 7}" width="12" height="6" rx="3" fill="#fff8cf" stroke="${INK}" stroke-width="2"/>
      <rect x="9" y="5" width="11" height="5" rx="2.5" fill="#ff4b4b" stroke="${INK}" stroke-width="1.6"/>
      <rect x="44" y="5" width="11" height="5" rx="2.5" fill="#ff4b4b" stroke="${INK}" stroke-width="1.6"/>
      ${extras}
    </symbol>`;
  }
  const blink = (a, b, dur = '0.5s') => `<animate attributeName="opacity" values="${a};${b};${a}" dur="${dur}" repeatCount="indefinite"/>`;

  const VEHICLE_SYMBOLS = [
    car('vTaxi', 112, ['#ffe066', '#ffc21a', '#e09a00'],
      `<g>${Array.from({ length: 5 }, (_, i) => `<rect x="${4 + (i % 2) * 0}" y="${48 + i * 5}" width="4" height="5" fill="${i % 2 ? '#fff' : INK}"/><rect x="56" y="${48 + i * 5}" width="4" height="5" fill="${i % 2 ? '#fff' : INK}"/>`).join('')}</g>
       <rect x="20" y="49" width="24" height="13" rx="4" fill="#fffbe6" stroke="${INK}" stroke-width="2.2"/>
       <text x="32" y="58.5" font-family="Poppins, Arial, sans-serif" font-size="7.5" font-weight="800" text-anchor="middle" fill="${INK}">TAXI</text>`),
    car('vPolice', 112, ['#4a4c58', '#2b2d36', '#15161c'],
      `<rect x="4" y="34" width="56" height="44" fill="url(#crWhite)" stroke="${INK}" stroke-width="2.4"/>
       <rect x="13" y="50" width="38" height="11" rx="4" fill="#2b2d36" stroke="${INK}" stroke-width="2"/>
       <rect x="15" y="52" width="16" height="7" rx="2" fill="#ff3b3b">${blink(1, 0.25, '0.45s')}</rect>
       <rect x="33" y="52" width="16" height="7" rx="2" fill="#3b82ff">${blink(0.25, 1, '0.45s')}</rect>
       <text x="32" y="74" font-family="Poppins, Arial, sans-serif" font-size="6.5" font-weight="800" text-anchor="middle" fill="${INK}">POLICE</text>`,
      { noRoof: true }),
    car('vSport', 106, ['#ff7a8a', '#e8304a', '#a8162c'],
      `<rect x="25" y="3" width="5" height="97" fill="#fff" opacity="0.9"/><rect x="34" y="3" width="5" height="97" fill="#fff" opacity="0.9"/>
       <rect x="6" y="1" width="52" height="7" rx="3" fill="#2b2d36" stroke="${INK}" stroke-width="2"/>`, { round: 18 }),
    car('vVan', 124, ['#ffffff', '#e6e9f0', '#b9bfcc'],
      `<rect x="4" y="60" width="56" height="9" fill="#3d8bfd" stroke="${INK}" stroke-width="1.8"/>
       <g stroke="${INK}" stroke-width="1.8" opacity="0.5"><line x1="16" y1="20" x2="16" y2="70"/><line x1="48" y1="20" x2="48" y2="70"/></g>`,
      { round: 11, rearY: 9 }),
    car('vIce', 140, ['#a6dcff', '#62b9ff', '#2f86d1'],
      `<g>${Array.from({ length: 6 }, (_, i) => `<rect x="4" y="${10 + i * 14}" width="56" height="5" fill="#ff8fc8" opacity="0.85"/>`).join('')}</g>
       <rect x="4" y="3" width="56" height="94" rx="10" fill="none" stroke="${INK}" stroke-width="3"/>
       <path d="M32 18 L19 54 H45 Z" fill="url(#crCone)" stroke="${INK}" stroke-width="2.6" stroke-linejoin="round"/>
       <path d="M24 42 L40 42 M22 48 L42 48 M27 30 L37 30" stroke="#b56a1c" stroke-width="1.6"/>
       <circle cx="32" cy="62" r="15" fill="url(#crScoop)" stroke="${INK}" stroke-width="2.6"/>
       <circle cx="32" cy="76" r="10" fill="#ffd2ea" stroke="${INK}" stroke-width="2.4"/>
       <circle cx="27" cy="57" r="4" fill="#fff" opacity="0.6"/>
       <g fill="#fff">${[[26, 66], [37, 60], [30, 52], [36, 70]].map(([x, y]) => `<rect x="${x}" y="${y}" width="3" height="1.6" rx="0.8"/>`).join('')}</g>
       <circle cx="32" cy="12" r="4" fill="#ff3b4f" stroke="${INK}" stroke-width="1.8"/>`,
      { noRoof: true, round: 10, rearY: 100 }),
    car('vLorry', 146, ['#7fe07a', '#43b44a', '#2a8231'],
      `<rect x="4" y="3" width="56" height="94" rx="7" fill="url(#crCargo)" stroke="${INK}" stroke-width="3"/>
       <g stroke="${INK}" stroke-width="1.8" opacity="0.45">${[22, 40, 58, 76].map((y) => `<line x1="6" y1="${y}" x2="58" y2="${y}"/>`).join('')}</g>
       <rect x="8" y="7" width="6" height="84" rx="3" fill="#fff" opacity="0.3"/>
       <rect x="6" y="97" width="52" height="5" fill="#29222a"/>`,
      { noRoof: true, round: 9, rearY: 104 }),
    car('vFire', 156, ['#ff6a5c', '#e8352e', '#a81f1a'],
      `<rect x="17" y="8" width="6" height="100" rx="2" fill="#dfe3ea" stroke="${INK}" stroke-width="1.8"/>
       <rect x="41" y="8" width="6" height="100" rx="2" fill="#dfe3ea" stroke="${INK}" stroke-width="1.8"/>
       <g fill="#dfe3ea" stroke="${INK}" stroke-width="1.4">${[16, 30, 44, 58, 72, 86, 100].map((y) => `<rect x="21" y="${y}" width="22" height="4" rx="1"/>`).join('')}</g>
       <rect x="12" y="112" width="40" height="8" rx="3" fill="#2b2d36" stroke="${INK}" stroke-width="2"/>
       <rect x="14" y="113.5" width="17" height="5" rx="2" fill="#3b82ff">${blink(1, 0.2, '0.4s')}</rect>
       <rect x="33" y="113.5" width="17" height="5" rx="2" fill="#3b82ff">${blink(0.2, 1, '0.4s')}</rect>`,
      { noRoof: true, round: 10, rearY: 122 }),
  ];
  const VEHICLES = [
    { id: 'vTaxi', h: 112, weight: 3 }, { id: 'vPolice', h: 112, weight: 2 }, { id: 'vSport', h: 106, weight: 2 },
    { id: 'vVan', h: 124, weight: 2 }, { id: 'vIce', h: 140, weight: 2 }, { id: 'vLorry', h: 146, weight: 2 },
    { id: 'vFire', h: 156, weight: 1 },
  ].map((v) => ({ ...v, w: 64, vbW: 68, vbH: v.h + 10 }));

  // ---------------------------------------------------------------------------------------------
  // Props
  // ---------------------------------------------------------------------------------------------
  const PROPS = `
    <symbol id="crHole" viewBox="0 0 100 100">
      <circle cx="51" cy="53" r="47" fill="#000" opacity="0.28"/>
      <circle cx="50" cy="50" r="46" fill="url(#crRim)" stroke="${INK}" stroke-width="3.2"/>
      <path d="M17 38 A36 36 0 0 1 60 14" fill="none" stroke="#fff" stroke-width="3" stroke-linecap="round" opacity="0.35"/>
      <circle cx="50" cy="50" r="36" fill="#363233" stroke="${INK}" stroke-width="2.6"/>
      <clipPath id="crGrateClip"><circle cx="50" cy="50" r="32"/></clipPath>
      <g clip-path="url(#crGrateClip)">
        <rect x="14" y="14" width="72" height="72" fill="#211d1e"/>
        ${Array.from({ length: 10 }, (_, i) => `<rect x="${17 + i * 7}" y="16" width="4.4" height="68" rx="2" fill="#5a5556"/><rect x="${17 + i * 7}" y="16" width="1.6" height="68" fill="#8b8687" opacity="0.6"/>`).join('')}
        <rect x="14" y="47" width="72" height="5" fill="#4a4647"/>
      </g>
      <circle cx="50" cy="50" r="32" fill="none" stroke="${INK}" stroke-width="2"/>
    </symbol>

    <symbol id="crCoin" viewBox="0 0 100 100">
      <circle cx="51" cy="54" r="46" fill="#000" opacity="0.25"/>
      <circle cx="50" cy="50" r="46" fill="url(#crGoldRim)" stroke="${INK}" stroke-width="3.2"/>
      <circle cx="50" cy="50" r="36" fill="url(#crGoldFace)" stroke="#b47a06" stroke-width="2.4"/>
      <path d="M22 40 A30 30 0 0 1 58 18" fill="none" stroke="#fffbe0" stroke-width="4" stroke-linecap="round" opacity="0.8"/>
      <!-- stamped roast chicken -->
      <g transform="translate(50 52)">
        <path d="M-22 4 C-22 -10 -8 -16 4 -14 C18 -12 24 -2 20 8 C16 18 -16 18 -22 4 Z" fill="#c9800d" stroke="#8a5300" stroke-width="2.4"/>
        <path d="M-14 -2 C-8 -8 2 -9 8 -6" fill="none" stroke="#f6c25a" stroke-width="2.4" stroke-linecap="round"/>
        <path d="M14 -8 L24 -18 M24 -18 a3.4 3.4 0 1 1 3.4 3.4 M18 8 L29 13 M29 13 a3.4 3.4 0 1 1 1 4.6" stroke="#fff3cf" stroke-width="4" stroke-linecap="round" fill="none"/>
        <path d="M14 -8 L24 -18 M18 8 L29 13" stroke="#8a5300" stroke-width="1.2" stroke-linecap="round" fill="none" opacity="0.5"/>
      </g>
      <circle cx="30" cy="30" r="5" fill="#fff" opacity="0.7"/>
    </symbol>

    <symbol id="crBarrier" viewBox="0 0 150 72">
      <ellipse cx="75" cy="66" rx="66" ry="5" fill="#000" opacity="0.25"/>
      <rect x="20" y="40" width="10" height="24" rx="3" fill="url(#crSteel)" stroke="${INK}" stroke-width="2.4"/>
      <rect x="120" y="40" width="10" height="24" rx="3" fill="url(#crSteel)" stroke="${INK}" stroke-width="2.4"/>
      <rect x="10" y="59" width="30" height="8" rx="3" fill="url(#crSteel)" stroke="${INK}" stroke-width="2.4"/>
      <rect x="110" y="59" width="30" height="8" rx="3" fill="url(#crSteel)" stroke="${INK}" stroke-width="2.4"/>
      <clipPath id="crRailClip"><rect x="4" y="14" width="142" height="30" rx="7"/></clipPath>
      <g clip-path="url(#crRailClip)">
        <rect x="4" y="14" width="142" height="30" fill="#ffad1f"/>
        ${Array.from({ length: 9 }, (_, i) => `<path d="M${i * 22 - 14} 44 L${i * 22 + 2} 14 L${i * 22 + 13} 14 L${i * 22 - 3} 44 Z" fill="#3a3640"/>`).join('')}
        <rect x="4" y="14" width="142" height="9" fill="#fff" opacity="0.25"/>
        <rect x="4" y="37" width="142" height="7" fill="#000" opacity="0.2"/>
      </g>
      <rect x="4" y="14" width="142" height="30" rx="7" fill="none" stroke="${INK}" stroke-width="3"/>
      <rect x="14" y="5" width="22" height="12" rx="4" fill="url(#crSteel)" stroke="${INK}" stroke-width="2.4"/>
      <rect x="114" y="5" width="22" height="12" rx="4" fill="url(#crSteel)" stroke="${INK}" stroke-width="2.4"/>
      <circle cx="25" cy="6" r="6" fill="#ffd23d" stroke="${INK}" stroke-width="2.2">${blink(1, 0.35, '0.9s')}</circle>
      <circle cx="125" cy="6" r="6" fill="#ffd23d" stroke="${INK}" stroke-width="2.2">${blink(0.35, 1, '0.9s')}</circle>
    </symbol>

    <symbol id="crNestEgg" viewBox="0 0 120 130">
      <ellipse cx="60" cy="104" rx="52" ry="20" fill="#000" opacity="0.22"/>
      <ellipse cx="60" cy="96" rx="50" ry="22" fill="url(#crStraw)" stroke="${INK}" stroke-width="3"/>
      <g stroke="#8a5a1c" stroke-width="2.2" stroke-linecap="round" opacity="0.8">
        <path d="M18 92 Q40 82 58 90"/><path d="M62 88 Q84 80 102 92"/><path d="M24 104 Q50 96 78 106"/><path d="M70 100 Q90 96 100 104"/>
      </g>
      <path d="M60 14 C38 14 26 48 26 70 C26 92 42 104 60 104 C78 104 94 92 94 70 C94 48 82 14 60 14 Z" fill="url(#crEggGold)" stroke="${INK}" stroke-width="3.4"/>
      <ellipse cx="46" cy="44" rx="7" ry="14" fill="#fff" opacity="0.6" transform="rotate(18 46 44)"/>
      <path d="M80 60 Q84 76 74 90" fill="none" stroke="#b97a0a" stroke-width="3" stroke-linecap="round" opacity="0.5"/>
      <ellipse cx="60" cy="88" rx="44" ry="12" fill="url(#crStraw)" stroke="${INK}" stroke-width="3"/>
      <g stroke="#8a5a1c" stroke-width="2" stroke-linecap="round" opacity="0.7"><path d="M24 88 Q44 80 62 88"/><path d="M58 90 Q78 82 96 88"/></g>
    </symbol>

    <symbol id="crStar" viewBox="-12 -12 24 24">
      <path d="M0 -10 L2.6 -2.6 L10 0 L2.6 2.6 L0 10 L-2.6 2.6 L-10 0 L-2.6 -2.6 Z" fill="#fff7b0" stroke="${INK}" stroke-width="1.6" stroke-linejoin="round"/>
    </symbol>
    <symbol id="crMiniCoin" viewBox="0 0 24 24">
      <circle cx="12" cy="12" r="10.5" fill="url(#crGoldRim)" stroke="${INK}" stroke-width="2"/>
      <circle cx="12" cy="12" r="6.5" fill="url(#crGoldFace)" stroke="#b47a06" stroke-width="1.4"/>
      <path d="M7 9 A6 6 0 0 1 13 6" stroke="#fff" stroke-width="1.6" fill="none" stroke-linecap="round" opacity="0.8"/>
    </symbol>
    <symbol id="crBonk" viewBox="-100 -70 200 140">
      <path d="M0 -66 L18 -34 L58 -50 L44 -14 L94 -8 L50 14 L74 50 L28 34 L8 66 L-12 34 L-58 54 L-42 16 L-94 6 L-48 -14 L-66 -50 L-22 -34 Z"
            fill="#ffd23d" stroke="${INK}" stroke-width="6" stroke-linejoin="round"/>
      <path d="M0 -46 L13 -22 L40 -34 L30 -8 L64 -4 L34 10 L50 34 L20 24 L6 46 L-8 24 L-40 38 L-28 12 L-64 4 L-32 -10 L-46 -34 L-16 -24 Z" fill="#ff7a1a"/>
      <text x="0" y="12" font-family="Poppins, Arial Black, sans-serif" font-size="38" font-weight="900" text-anchor="middle"
            fill="#fff" stroke="${INK}" stroke-width="7" paint-order="stroke" transform="rotate(-8)">BONK!</text>
    </symbol>`;

  const GRADIENTS = `
    <linearGradient id="crGlass" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#7ea6c8"/><stop offset="0.55" stop-color="#3b5b7a"/><stop offset="1" stop-color="#22374d"/></linearGradient>
    <linearGradient id="crWhite" x1="0" y1="0" x2="1" y2="0"><stop offset="0" stop-color="#ffffff"/><stop offset="1" stop-color="#cfd5e2"/></linearGradient>
    <linearGradient id="crCargo" x1="0" y1="0" x2="1" y2="0"><stop offset="0" stop-color="#f4f6fa"/><stop offset="1" stop-color="#c4cad8"/></linearGradient>
    <linearGradient id="crCone" x1="0" y1="0" x2="1" y2="0"><stop offset="0" stop-color="#ffcf7a"/><stop offset="1" stop-color="#d98a2b"/></linearGradient>
    <radialGradient id="crScoop" cx="38%" cy="35%" r="70%"><stop offset="0" stop-color="#ffd2ea"/><stop offset="1" stop-color="#ff6fb5"/></radialGradient>
    <linearGradient id="crSteel" x1="0" y1="0" x2="1" y2="0"><stop offset="0" stop-color="#eef1f6"/><stop offset="1" stop-color="#9aa1b0"/></linearGradient>
    <radialGradient id="crRim" cx="38%" cy="32%" r="75%"><stop offset="0" stop-color="#9c9799"/><stop offset="0.6" stop-color="#6e696b"/><stop offset="1" stop-color="#4a4647"/></radialGradient>
    <radialGradient id="crGoldRim" cx="35%" cy="30%" r="80%"><stop offset="0" stop-color="#ffe680"/><stop offset="0.55" stop-color="#f4b31c"/><stop offset="1" stop-color="#c47f00"/></radialGradient>
    <radialGradient id="crGoldFace" cx="40%" cy="35%" r="75%"><stop offset="0" stop-color="#ffd84d"/><stop offset="1" stop-color="#e59a08"/></radialGradient>
    <radialGradient id="crEggGold" cx="38%" cy="30%" r="75%"><stop offset="0" stop-color="#fff6c8"/><stop offset="0.5" stop-color="#ffc53d"/><stop offset="1" stop-color="#c47f00"/></radialGradient>
    <linearGradient id="crStraw" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#e8b65c"/><stop offset="1" stop-color="#b47a2e"/></linearGradient>
    <radialGradient id="crLampGlow" cx="50%" cy="50%" r="50%"><stop offset="0" stop-color="#fff4b0" stop-opacity="0.9"/><stop offset="1" stop-color="#fff4b0" stop-opacity="0"/></radialGradient>`;

  // ---------------------------------------------------------------------------------------------
  // Pavement (start and finish), generated to the stage's real pixel size so nothing stretches.
  // ---------------------------------------------------------------------------------------------
  function blob(cx, cy, r, n, seed) {
    // A cloud-like canopy outline: n bumps round a circle, deterministic per seed.
    let d = '';
    for (let i = 0; i <= n; i++) {
      const a = (i / n) * Math.PI * 2;
      const rr = r * (0.86 + 0.14 * Math.abs(Math.sin(seed + i * 1.7)));
      const x = cx + Math.cos(a) * rr, y = cy + Math.sin(a) * rr;
      if (i === 0) { d += `M${x.toFixed(1)} ${y.toFixed(1)}`; continue; }
      const am = ((i - 0.5) / n) * Math.PI * 2, rm = rr * 1.16;
      d += `Q${(cx + Math.cos(am) * rm).toFixed(1)} ${(cy + Math.sin(am) * rm).toFixed(1)} ${x.toFixed(1)} ${y.toFixed(1)}`;
    }
    return d + 'Z';
  }
  function tree(cx, cy, r, seed, dark = '#3f9a37', mid = '#5cbf4a', light = '#93e06c') {
    return `<ellipse cx="${cx + r * 0.25}" cy="${cy + r * 0.35}" rx="${r}" ry="${r * 0.9}" fill="#000" opacity="0.2"/>
      <path d="${blob(cx, cy, r, 9, seed)}" fill="${mid}" stroke="${INK}" stroke-width="${Math.max(2.5, r * 0.06)}" stroke-linejoin="round"/>
      <path d="${blob(cx + r * 0.12, cy + r * 0.18, r * 0.62, 7, seed + 2)}" fill="${dark}" opacity="0.55"/>
      <path d="${blob(cx - r * 0.25, cy - r * 0.28, r * 0.42, 6, seed + 4)}" fill="${light}" opacity="0.9"/>
      <circle cx="${cx - r * 0.38}" cy="${cy - r * 0.42}" r="${r * 0.1}" fill="#fff" opacity="0.5"/>`;
  }
  function kerb(side, w, h, L) {
    const s = Math.round(L * 0.42);               // paving stone size
    const sw = Math.max(2, L * 0.022);            // outline width at this scale
    const curbW = Math.round(L * 0.11);
    const grassW = Math.round(w * 0.2);
    const id = 'crPave' + side;
    const start = side === 'start';
    const curbX = start ? w - curbW : 0;
    const grassX = start ? 0 : w - grassW;
    const joints = Array.from({ length: Math.ceil(h / s) + 1 }, (_, i) => `<line x1="${curbX}" y1="${i * s}" x2="${curbX + curbW}" y2="${i * s}" stroke="${INK}" stroke-width="${sw * 0.7}" opacity="0.5"/>`).join('');
    const tufts = Array.from({ length: Math.ceil(h / (L * 0.32)) }, (_, i) => {
      const y = i * L * 0.32 + L * 0.1, x = start ? grassW : grassX;
      const dir = start ? 1 : -1;
      return `<path d="M${x} ${y} l${dir * L * 0.06} ${-L * 0.05} l${-dir * L * 0.02} ${L * 0.06} l${dir * L * 0.07} ${-L * 0.02} l${-dir * L * 0.05} ${L * 0.07} Z" fill="#4aa83c"/>`;
    }).join('');
    let decor = '';
    if (start) {
      decor += tree(-L * 0.05, L * 0.42, L * 0.56, 1);
      decor += tree(-L * 0.12, h - L * 0.4, L * 0.42, 5, '#3c8f35', '#55b545', '#8bd864');
      // Lamp post: a pole down from the top edge to a round foot, the lamp head reaching out over the kerb.
      // Arm and lamp sit below the "Live wins" strip that overlays the top-left of the stage.
      const px = w * 0.42, foot = h * 0.36, pw = L * 0.085, arm = L * 0.62;
      decor += `<ellipse cx="${px + L * 0.42}" cy="${arm + L * 0.08}" rx="${L * 0.48}" ry="${L * 0.34}" fill="url(#crLampGlow)" opacity="0.75"/>
        <rect x="${px - pw / 2}" y="-10" width="${pw}" height="${foot + 10}" rx="${pw / 2}" fill="url(#crSteel)" stroke="${INK}" stroke-width="${sw}"/>
        <ellipse cx="${px}" cy="${foot}" rx="${pw * 1.5}" ry="${pw * 1.15}" fill="url(#crSteel)" stroke="${INK}" stroke-width="${sw}"/>
        <path d="M${px} ${arm} H${px + L * 0.38}" stroke="${INK}" stroke-width="${pw * 0.75 + sw * 2}" stroke-linecap="round"/>
        <path d="M${px} ${arm} H${px + L * 0.38}" stroke="#c9ced8" stroke-width="${pw * 0.75}" stroke-linecap="round"/>
        <rect x="${px + L * 0.3}" y="${arm - L * 0.06}" width="${L * 0.24}" height="${L * 0.14}" rx="${L * 0.05}" fill="#fff4b0" stroke="${INK}" stroke-width="${sw}"/>`;
    } else {
      decor += tree(w + L * 0.08, h - L * 0.45, L * 0.48, 3);
    }
    return `<svg xmlns="${NS}" width="${w}" height="${h}" viewBox="0 0 ${w} ${h}" style="display:block">
      <defs>
        <pattern id="${id}" width="${s}" height="${s}" patternUnits="userSpaceOnUse">
          <rect width="${s}" height="${s}" fill="#9d9894"/>
          <rect x="1.5" y="1.5" width="${s - 3}" height="${s - 3}" rx="${s * 0.12}" fill="#bcb7b2"/>
          <rect x="1.5" y="1.5" width="${s - 3}" height="${s * 0.22}" rx="${s * 0.1}" fill="#fff" opacity="0.18"/>
          <rect x="1.5" y="${s * 0.7}" width="${s - 3}" height="${s * 0.28}" rx="${s * 0.1}" fill="#000" opacity="0.07"/>
          <circle cx="${s * 0.3}" cy="${s * 0.62}" r="${s * 0.03}" fill="#8f8a86"/><circle cx="${s * 0.72}" cy="${s * 0.38}" r="${s * 0.025}" fill="#8f8a86"/>
        </pattern>
      </defs>
      <rect width="${w}" height="${h}" fill="url(#${id})"/>
      <rect x="${grassX}" y="0" width="${grassW}" height="${h}" fill="#5cbf4a"/>
      <line x1="${start ? grassW : grassX}" y1="0" x2="${start ? grassW : grassX}" y2="${h}" stroke="${INK}" stroke-width="${sw}"/>
      ${tufts}
      <rect x="${curbX}" y="0" width="${curbW}" height="${h}" fill="url(#crSteel)"/>
      <rect x="${start ? curbX + curbW * 0.65 : curbX}" y="0" width="${curbW * 0.35}" height="${h}" fill="#000" opacity="0.16"/>
      ${joints}
      <line x1="${start ? curbX : curbX + curbW}" y1="0" x2="${start ? curbX : curbX + curbW}" y2="${h}" stroke="${INK}" stroke-width="${sw}" opacity="0.6"/>
      <line x1="${start ? w - 1 : 1}" y1="0" x2="${start ? w - 1 : 1}" y2="${h}" stroke="${INK}" stroke-width="${sw}"/>
      ${decor}
    </svg>`;
  }

  // ---------------------------------------------------------------------------------------------
  // Scene CSS (road texture, dashes, effects)
  // ---------------------------------------------------------------------------------------------
  const speck = (() => {
    let r = 7, c = '';
    const rnd = () => (r = (r * 9301 + 49297) % 233280) / 233280;
    for (let i = 0; i < 70; i++) {
      const x = (rnd() * 120).toFixed(1), y = (rnd() * 120).toFixed(1), rr = (0.6 + rnd() * 1.4).toFixed(1);
      c += `<circle cx='${x}' cy='${y}' r='${rr}' fill='${rnd() < 0.5 ? '#000' : '#fff'}' opacity='${(0.06 + rnd() * 0.08).toFixed(2)}'/>`;
    }
    return `url("data:image/svg+xml,${encodeURIComponent(`<svg xmlns='http://www.w3.org/2000/svg' width='120' height='120'>${c}</svg>`)}")`;
  })();
  const dash = `url("data:image/svg+xml,${encodeURIComponent(`<svg xmlns='http://www.w3.org/2000/svg' width='16' height='100' viewBox='0 0 16 100' preserveAspectRatio='none'><rect x='5.5' y='4' width='7' height='46' rx='3.5' fill='#000' opacity='0.28'/><rect x='4' y='2' width='7' height='46' rx='3.5' fill='#f6f2ea'/><rect x='4.6' y='3' width='2' height='40' rx='1' fill='#fff'/></svg>`)}")`;

  const CSS = `
    .cr-stage { background: #5d595a !important; }
    .cr-stage::after { content: ''; position: absolute; inset: 0; pointer-events: none; z-index: 7;
      background: radial-gradient(ellipse at 50% 45%, transparent 55%, rgba(20,14,12,0.35) 100%),
                  linear-gradient(180deg, rgba(255,255,255,0.05), transparent 30%, rgba(0,0,0,0.12)); }
    .cr-lane { background: ${speck}, linear-gradient(90deg, rgba(0,0,0,0.05), transparent 30%, transparent 70%, rgba(0,0,0,0.05)) !important; background-size: 120px 120px, 100% 100% !important; }
    .cr-lane.first { box-shadow: inset 14px 0 12px -10px rgba(0,0,0,0.45); }
    .cr-lane.dash::before { left: -8px !important; width: 16px !important; opacity: 1 !important;
      background: ${dash} repeat-y !important; background-size: 16px calc(var(--L) * 0.5) !important; }
    .cr-walk { background: none !important; box-shadow: none !important; }
    .cr-walk > svg { position: absolute; inset: 0; }
    .cr-hole { background: none !important; box-shadow: none !important; overflow: visible; }
    .cr-hole::before { content: none !important; }
    .cr-hole > svg { position: absolute; inset: -6%; width: 112%; height: 112%; }
    .cr-hole span { -webkit-text-stroke: calc(var(--L) * 0.022) ${INK}; paint-order: stroke fill;
      text-shadow: 0 calc(var(--L) * 0.02) 0 ${INK}, 0 0 6px rgba(0,0,0,0.5) !important; letter-spacing: 0 !important; }
    .cr-coin svg { filter: none !important; }
    .cr-coin { transform-origin: 50% 50%; }
    .cr-lane.passed .cr-coin { animation: crCoinShine 2.6s ease-in-out infinite; }
    @keyframes crCoinShine { 0%, 80%, 100% { filter: brightness(1); } 88% { filter: brightness(1.25) drop-shadow(0 0 8px rgba(255,210,61,0.9)); } }
    .cr-tag { border: calc(var(--L) * 0.018) solid ${INK} !important; box-shadow: 0 4px 0 ${INK}, inset 0 2px 0 rgba(255,255,255,0.25) !important;
      background: linear-gradient(180deg, #3d5089, #26355f) !important; text-shadow: 0 2px 0 ${INK}; }
    .cr-tag::before { border-bottom-color: ${INK} !important; top: -9px !important; }
    .cr-car svg { filter: drop-shadow(calc(var(--L) * 0.03) calc(var(--L) * 0.07) calc(var(--L) * 0.03) rgba(0,0,0,0.38)) !important; }
    .cr-egg { filter: drop-shadow(0 0 18px rgba(255,197,61,0.85)) !important; width: calc(var(--L) * 0.85) !important; height: calc(var(--L) * 0.92) !important; }

    .cr-fx { position: absolute; left: 0; top: 0; pointer-events: none; z-index: 9; }
    .cr-puff { position: absolute; border-radius: 50%; background: radial-gradient(circle at 35% 35%, #ffffff, #d9d3cc); border: 2px solid ${INK};
      animation: crPuff 0.65s ease-out forwards; }
    @keyframes crPuff { 0% { transform: translate(-50%, -50%) scale(0.3); opacity: 1; }
      100% { transform: translate(calc(-50% + var(--dx)), calc(-50% + var(--dy))) scale(1.25); opacity: 0; } }
    .cr-spark { position: absolute; animation: crSpark 0.7s ease-out forwards; }
    @keyframes crSpark { 0% { transform: translate(-50%, -50%) scale(0) rotate(0); opacity: 1; }
      60% { opacity: 1; } 100% { transform: translate(calc(-50% + var(--dx)), calc(-50% + var(--dy))) scale(1.1) rotate(160deg); opacity: 0; } }
    .cr-bonk { position: absolute; animation: crBonk 1.1s cubic-bezier(.2,1.6,.4,1) forwards; }
    @keyframes crBonk { 0% { transform: translate(-50%, -50%) scale(0) rotate(-20deg); opacity: 1; }
      25% { transform: translate(-50%, -50%) scale(1.15) rotate(6deg); } 40% { transform: translate(-50%, -50%) scale(1) rotate(0); }
      80% { opacity: 1; } 100% { transform: translate(-50%, -60%) scale(1.05); opacity: 0; } }
    .cr-flycoin { position: absolute; animation: crFly var(--t, 1.1s) cubic-bezier(.25,.6,.5,1) forwards; }
    @keyframes crFly { 0% { transform: translate(-50%, -50%) scale(0.4) rotate(0); opacity: 1; }
      50% { transform: translate(calc(-50% + var(--dx) * 0.6), calc(-50% + var(--up))) scale(1) rotate(calc(var(--spin) * 0.5)); opacity: 1; }
      100% { transform: translate(calc(-50% + var(--dx)), calc(-50% + var(--down))) scale(0.9) rotate(var(--spin)); opacity: 0; } }
    .cr-stage.shake { animation: crShakeStage 0.45s linear; }
    @keyframes crShakeStage { 0%, 100% { transform: none; } 10% { transform: translate(-8px, 4px) rotate(-0.6deg); } 25% { transform: translate(7px, -5px) rotate(0.5deg); }
      40% { transform: translate(-6px, 3px); } 55% { transform: translate(5px, -2px); } 70% { transform: translate(-3px, 2px); } 85% { transform: translate(2px, -1px); } }
    .cr-hen.spin .body { animation: crSpin 0.62s cubic-bezier(.4,.1,.3,1) !important; transform-origin: 50% 55%; }
    @keyframes crSpin { from { transform: rotate(0); } to { transform: rotate(-360deg); } }
  `;

  // ---------------------------------------------------------------------------------------------
  // Effects
  // ---------------------------------------------------------------------------------------------
  function fxLayer(track) {
    let layer = track.querySelector(':scope > .cr-fx');
    if (!layer) { layer = document.createElement('div'); layer.className = 'cr-fx'; track.appendChild(layer); }
    return layer;
  }
  function spawn(track, cls, html, x, y, size, vars, life) {
    const el = document.createElement('div');
    el.className = cls;
    el.style.left = x + 'px'; el.style.top = y + 'px';
    if (size) { el.style.width = size + 'px'; el.style.height = size + 'px'; }
    for (const k in vars) el.style.setProperty(k, vars[k]);
    if (html) el.innerHTML = html;
    fxLayer(track).appendChild(el);
    setTimeout(() => el.remove(), life);
    return el;
  }
  const fx = {
    dust(track, x, y, L) {
      for (let i = 0; i < 9; i++) {
        const a = Math.PI * (0.05 + 0.9 * (i / 8)), r = L * (0.34 + Math.random() * 0.22);
        spawn(track, 'cr-puff', '', x + (Math.random() - 0.5) * L * 0.2, y, L * (0.15 + Math.random() * 0.1),
          { '--dx': (Math.cos(a) * r * (i % 2 ? 1 : -1)) + 'px', '--dy': (-Math.sin(a) * r * 0.35) + 'px' }, 700);
      }
    },
    sparkle(track, x, y, L) {
      for (let i = 0; i < 6; i++) {
        const a = (i / 6) * Math.PI * 2 + Math.random() * 0.5, r = L * (0.42 + Math.random() * 0.24), s = L * (0.18 + Math.random() * 0.1);
        spawn(track, 'cr-spark', `<svg viewBox="-12 -12 24 24" width="${s}" height="${s}"><use href="#crStar" x="-12" y="-12" width="24" height="24"/></svg>`, x, y, 0,
          { '--dx': Math.cos(a) * r + 'px', '--dy': Math.sin(a) * r + 'px' }, 750);
      }
    },
    bonk(track, x, y, L, stage) {
      const w = L * 1.5;
      spawn(track, 'cr-bonk', `<svg viewBox="-100 -70 200 140" width="${w}" height="${w * 0.7}"><use href="#crBonk" x="-100" y="-70" width="200" height="140"/></svg>`, x, y, 0, {}, 1150);
      if (stage) { stage.classList.remove('shake'); void stage.offsetWidth; stage.classList.add('shake'); setTimeout(() => stage.classList.remove('shake'), 500); }
    },
    coins(track, x, y, L, n = 14) {
      for (let i = 0; i < n; i++) {
        const s = L * (0.16 + Math.random() * 0.1);
        spawn(track, 'cr-flycoin', `<svg viewBox="0 0 24 24" width="${s}" height="${s}"><use href="#crMiniCoin"/></svg>`, x, y, 0, {
          '--dx': ((Math.random() - 0.5) * L * 2.4) + 'px', '--up': (-L * (0.8 + Math.random() * 0.9)) + 'px',
          '--down': (L * (0.1 + Math.random() * 0.4)) + 'px', '--spin': ((Math.random() - 0.5) * 900) + 'deg',
          '--t': (0.9 + Math.random() * 0.5) + 's',
        }, 1500).style.animationDelay = (i * 0.03) + 's';
      }
    },
  };

  let injected = false;
  function ensure() {
    if (injected) return;
    injected = true;
    const defs = document.createElement('div');
    defs.innerHTML = `<svg xmlns="${NS}" width="0" height="0" style="position:absolute" aria-hidden="true"><defs>${GRADIENTS}</defs>${PROPS}${VEHICLE_SYMBOLS.join('')}</svg>`;
    document.body.appendChild(defs.firstChild);
    const st = document.createElement('style');
    st.textContent = CSS;
    document.head.appendChild(st);
  }

  window.CRScene = { ensure, kerb, VEHICLES, fx, INK };
})();
