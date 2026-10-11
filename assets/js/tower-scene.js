/**
 * Tower Rush scene — art and animation, all vector, in the same illustrated style as the rest of the
 * site's game art (one dark ink outline colour, rounded shapes, cel shading with glossy highlights).
 *
 *   const scene = new TowerScene(svgElement);
 *   scene.restore(factors)        rebuild a tower already in progress (page reload)
 *   await scene.drop({ collapse }) drop the hanging block; resolves when it has landed / fallen
 *   await scene.clear()           crumble the tower away and bring the camera back to the street
 *   scene.burst(kind)             'win' coin fountain from the top of the tower
 *
 * Coordinates: the SVG's viewBox is 400 units wide and as tall as the box's aspect demands. The
 * world (street, tower, clouds) scrolls under a fixed sky and a fixed crane.
 */
(function () {
  'use strict';
  const NS = 'http://www.w3.org/2000/svg';
  const INK = '#2a1a14';
  const W = 400;                       // viewBox width
  const STREET = 132;                  // distance from the bottom of the view to street level, at rest
  const BASE = { w: 150, h: 132 };     // the shop the tower stands on

  // ---------------------------------------------------------------------------------------------
  // Shared defs: gradients and patterns
  // ---------------------------------------------------------------------------------------------
  const DEFS = `
    <linearGradient id="trSky" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#5fb4cf"/><stop offset="0.7" stop-color="#8fd0dd"/><stop offset="1" stop-color="#c5ecec"/></linearGradient>
    <radialGradient id="trSun" cx="50%" cy="50%" r="50%"><stop offset="0" stop-color="#fff6c9" stop-opacity="1"/><stop offset="0.35" stop-color="#ffe08a" stop-opacity="0.8"/><stop offset="1" stop-color="#ffd166" stop-opacity="0"/></radialGradient>
    <linearGradient id="trLog" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#cf8a4c"/><stop offset="0.5" stop-color="#a8652f"/><stop offset="1" stop-color="#7d4520"/></linearGradient>
    <radialGradient id="trLogEnd" cx="40%" cy="40%" r="60%"><stop offset="0" stop-color="#f0c48a"/><stop offset="1" stop-color="#b9783e"/></radialGradient>
    <linearGradient id="trOchre" x1="0" y1="0" x2="1" y2="0"><stop offset="0" stop-color="#f7c45a"/><stop offset="1" stop-color="#d98f2a"/></linearGradient>
    <linearGradient id="trPink" x1="0" y1="0" x2="1" y2="0"><stop offset="0" stop-color="#e98478"/><stop offset="1" stop-color="#b84a45"/></linearGradient>
    <linearGradient id="trWood" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#f0b55a"/><stop offset="1" stop-color="#bb7a2e"/></linearGradient>
    <linearGradient id="trGreen" x1="0" y1="0" x2="1" y2="0"><stop offset="0" stop-color="#4f9547"/><stop offset="1" stop-color="#2f6a2e"/></linearGradient>
    <linearGradient id="trGlassWarm" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#ffe7a6"/><stop offset="1" stop-color="#e99b3c"/></linearGradient>
    <linearGradient id="trGlass" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#bfe4f6"/><stop offset="1" stop-color="#4f88b4"/></linearGradient>
    <linearGradient id="trConcrete" x1="0" y1="0" x2="1" y2="0"><stop offset="0" stop-color="#c3bfcf"/><stop offset="1" stop-color="#8f8aa4"/></linearGradient>
    <linearGradient id="trMauve" x1="0" y1="0" x2="1" y2="0"><stop offset="0" stop-color="#d9b3c4"/><stop offset="1" stop-color="#a87f97"/></linearGradient>
    <linearGradient id="trSoil" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#5a4334"/><stop offset="1" stop-color="#2d211b"/></linearGradient>
    <linearGradient id="trSteel" x1="0" y1="0" x2="1" y2="0"><stop offset="0" stop-color="#eef1f6"/><stop offset="1" stop-color="#8f96a6"/></linearGradient>
    <pattern id="trHazard" width="16" height="16" patternUnits="userSpaceOnUse" patternTransform="rotate(45)"><rect width="16" height="16" fill="#ffc21a"/><rect width="8" height="16" fill="#26201c"/></pattern>
    <pattern id="trAwning" width="16" height="10" patternUnits="userSpaceOnUse"><rect width="16" height="10" fill="#fff6ef"/><rect width="8" height="10" fill="#e3473b"/></pattern>`;

  // ---------------------------------------------------------------------------------------------
  // Building blocks (local origin at bottom-centre; y up is negative)
  // ---------------------------------------------------------------------------------------------
  function star(x, y, r) {
    let d = '';
    for (let i = 0; i < 10; i++) {
      const a = -Math.PI / 2 + i * Math.PI / 5, rr = i % 2 ? r * 0.45 : r;
      d += (i ? 'L' : 'M') + (x + Math.cos(a) * rr).toFixed(1) + ' ' + (y + Math.sin(a) * rr).toFixed(1);
    }
    return `<path d="${d}Z" fill="#ffd23d" stroke="${INK}" stroke-width="1.6" stroke-linejoin="round"/>`;
  }
  const SKINS = {
    cabin: { w: 120, h: 100, svg() {
      let logs = '';
      for (let i = 0; i < 6; i++) {
        const y = -92 + i * 14;
        logs += `<rect x="-58" y="${y}" width="116" height="14" rx="7" fill="url(#trLog)" stroke="${INK}" stroke-width="2"/>
          <circle cx="-56" cy="${y + 7}" r="7.5" fill="url(#trLogEnd)" stroke="${INK}" stroke-width="2"/><circle cx="-56" cy="${y + 7}" r="3" fill="none" stroke="#9a5d2c" stroke-width="1.2"/>
          <circle cx="56" cy="${y + 7}" r="7.5" fill="url(#trLogEnd)" stroke="${INK}" stroke-width="2"/><circle cx="56" cy="${y + 7}" r="3" fill="none" stroke="#9a5d2c" stroke-width="1.2"/>`;
      }
      return `${logs}
        <rect x="-62" y="-102" width="124" height="12" rx="4" fill="#7a431d" stroke="${INK}" stroke-width="2.4"/>
        <rect x="-15" y="-86" width="30" height="24" rx="2" fill="#f1d3a0" stroke="${INK}" stroke-width="2.2"/>
        <rect x="-11" y="-82" width="22" height="16" fill="url(#trGlass)"/><path d="M0 -82V-66M-11 -74H11" stroke="#f1d3a0" stroke-width="2.4"/>
        <rect x="-17" y="-50" width="34" height="50" rx="3" fill="#f1d3a0" stroke="${INK}" stroke-width="2.4"/>
        <rect x="-13" y="-46" width="26" height="46" fill="#7d4520"/>
        <path d="M-6.5 -46V0M0 -46V0M6.5 -46V0" stroke="#5c3216" stroke-width="1.6"/>
        <rect x="-19" y="-54" width="38" height="7" rx="2" fill="#b56f35" stroke="${INK}" stroke-width="2"/>
        <circle cx="8" cy="-23" r="2" fill="#ffd23d" stroke="${INK}" stroke-width="1"/>
        <rect x="-60" y="-4" width="120" height="6" rx="3" fill="#8b8698" stroke="${INK}" stroke-width="2"/>`;
    } },
    yellow: { w: 116, h: 106, svg() {
      return `<rect x="-54" y="-100" width="108" height="100" fill="url(#trOchre)" stroke="${INK}" stroke-width="2.6"/>
        <rect x="-50" y="-96" width="7" height="92" fill="#fff" opacity="0.25"/>
        <rect x="-58" y="-106" width="116" height="9" rx="2" fill="#c27626" stroke="${INK}" stroke-width="2.4"/>
        ${[-26, 14].map((x) => `
          <rect x="${x - 15}" y="-88" width="8" height="26" rx="1.5" fill="#5aa83c" stroke="${INK}" stroke-width="1.8"/>
          <rect x="${x + 19}" y="-88" width="8" height="26" rx="1.5" fill="#5aa83c" stroke="${INK}" stroke-width="1.8"/>
          <rect x="${x - 7}" y="-88" width="26" height="26" fill="#2f4f6f" stroke="${INK}" stroke-width="2"/>
          <path d="M${x + 6} -88V-62" stroke="#f3dba6" stroke-width="2"/>
          <rect x="${x - 9}" y="-62" width="30" height="4" fill="#f3dba6" stroke="${INK}" stroke-width="1.6"/>`).join('')}
        <path d="M-36 -58 Q2 -48 40 -58" stroke="${INK}" stroke-width="1.4" fill="none"/>
        <rect x="-22" y="-56" width="9" height="11" fill="#fff" stroke="${INK}" stroke-width="1.4"/><rect x="-6" y="-53" width="8" height="10" fill="#ff9fb8" stroke="${INK}" stroke-width="1.4"/><rect x="10" y="-55" width="9" height="10" fill="#9fd2ff" stroke="${INK}" stroke-width="1.4"/>
        <rect x="-44" y="-40" width="8" height="26" rx="1.5" fill="#5aa83c" stroke="${INK}" stroke-width="1.8"/>
        <rect x="-14" y="-40" width="8" height="26" rx="1.5" fill="#5aa83c" stroke="${INK}" stroke-width="1.8"/>
        <rect x="-36" y="-40" width="22" height="24" fill="#2f4f6f" stroke="${INK}" stroke-width="2"/>
        <rect x="-40" y="-16" width="30" height="7" rx="2" fill="#8a5a2a" stroke="${INK}" stroke-width="1.8"/>
        <circle cx="-34" cy="-18" r="3.2" fill="#e8352e" stroke="${INK}" stroke-width="1"/><circle cx="-25" cy="-19" r="3.2" fill="#ff9fb8" stroke="${INK}" stroke-width="1"/><circle cx="-16" cy="-18" r="3.2" fill="#e8352e" stroke="${INK}" stroke-width="1"/>
        <path d="M8 0V-34 Q22 -46 36 -34V0Z" fill="#c43a2a" stroke="${INK}" stroke-width="2.4"/>
        <path d="M22 -40V0" stroke="#8f2418" stroke-width="1.6"/><circle cx="18" cy="-17" r="1.8" fill="#ffd23d"/>
        <rect x="4" y="-4" width="36" height="5" rx="2" fill="#b9b2c4" stroke="${INK}" stroke-width="1.8"/>`;
    } },
    pink: { w: 96, h: 124, svg() {
      return `<path d="M-40 0 L40 0 L44 -110 L-46 -116 Z" fill="url(#trPink)" stroke="${INK}" stroke-width="2.6" stroke-linejoin="round"/>
        <path d="M-38 -10 L-42 -108" stroke="#fff" stroke-width="4" opacity="0.25"/>
        <path d="M-58 -118 L56 -110 L54 -102 L-58 -110 Z" fill="#8a5a2a" stroke="${INK}" stroke-width="2.4" stroke-linejoin="round"/>
        <path d="M-50 -116 L-50 -96 M50 -110 L50 -92" stroke="${INK}" stroke-width="1.2"/>
        ${star(-50, -92, 5)}${star(50, -88, 4.5)}${star(-36, -84, 3.5)}
        <rect x="-15" y="-92" width="30" height="26" rx="2" fill="#f2d7b0" stroke="${INK}" stroke-width="2.2"/>
        <rect x="-11" y="-88" width="22" height="18" fill="url(#trGlass)"/><path d="M0 -88V-70" stroke="#f2d7b0" stroke-width="2"/>
        <path d="M-24 -56 Q0 -66 24 -56" stroke="${INK}" stroke-width="1.4" fill="none"/>
        ${star(-14, -56, 3.5)}${star(10, -58, 3.5)}
        <rect x="-20" y="-48" width="40" height="48" rx="3" fill="#f2d7b0" stroke="${INK}" stroke-width="2.4"/>
        <rect x="-16" y="-44" width="15" height="44" fill="#3c6fb0"/><rect x="1" y="-44" width="15" height="44" fill="#3c6fb0"/>
        <path d="M-8.5 -40V-6M8.5 -40V-6" stroke="#2a4f82" stroke-width="2"/>
        <circle cx="-3" cy="-22" r="1.6" fill="#ffd23d"/><circle cx="3" cy="-22" r="1.6" fill="#ffd23d"/>
        <path d="M24 -30 l6 -3 M-30 -70 l-4 6 M28 -78 l5 4" stroke="${INK}" stroke-width="1.4" opacity="0.6"/>`;
    } },
    hex: { w: 134, h: 98, svg() {
      return `<path d="M-50 0 L50 0 L66 -49 L50 -98 L-50 -98 L-66 -49 Z" fill="url(#trWood)" stroke="${INK}" stroke-width="3" stroke-linejoin="round"/>
        <g stroke="#a3652a" stroke-width="1.6" opacity="0.8">${[-82, -66, -50, -34, -18].map((y) => `<line x1="-60" y1="${y}" x2="60" y2="${y}"/>`).join('')}</g>
        <path d="M-46 -94 L46 -94" stroke="#fff" stroke-width="3" opacity="0.3"/>
        <circle cx="-26" cy="-58" r="19" fill="#cfa0d6" stroke="${INK}" stroke-width="2.6"/>
        <circle cx="-26" cy="-58" r="14" fill="#f2e6f6" stroke="${INK}" stroke-width="1.6"/>
        <path d="M-26 -72V-44M-40 -58H-12" stroke="#8b5a9a" stroke-width="2.2"/>
        <rect x="6" y="-56" width="28" height="52" rx="3" fill="#3f8fb5" stroke="${INK}" stroke-width="2.4"/>
        <path d="M20 -52V-6" stroke="#2c6a89" stroke-width="1.8"/><circle cx="28" cy="-28" r="1.8" fill="#ffd23d"/>
        <rect x="30" y="-90" width="16" height="12" rx="2" fill="#b56f35" stroke="${INK}" stroke-width="1.8"/>
        <path d="M33 -90 Q30 -102 38 -104 M38 -90 Q38 -104 46 -100 M42 -90 Q48 -98 50 -92" stroke="#3f9a37" stroke-width="3" fill="none" stroke-linecap="round"/>
        <rect x="2" y="-4" width="36" height="5" rx="2" fill="#9b6a35" stroke="${INK}" stroke-width="1.8"/>`;
    } },
  };
  const SKIN_IDS = Object.keys(SKINS);

  // ---------------------------------------------------------------------------------------------
  // Street: base shop, neighbours, lamp, pavement, underground
  // ---------------------------------------------------------------------------------------------
  function streetSvg() {
    const bx = W / 2;
    return `
      <ellipse cx="${bx}" cy="-120" rx="170" ry="120" fill="url(#trSun)"/>
      <!-- far row -->
      <rect x="-10" y="-150" width="96" height="150" fill="#b7a9c8" stroke="${INK}" stroke-width="2"/>
      <rect x="300" y="-176" width="70" height="176" fill="#c1b2cf" stroke="${INK}" stroke-width="2"/>
      <!-- left: grey garage block -->
      <rect x="-4" y="-104" width="120" height="104" fill="url(#trConcrete)" stroke="${INK}" stroke-width="2.4"/>
      <rect x="-4" y="-112" width="124" height="10" fill="#7a7591" stroke="${INK}" stroke-width="2"/>
      <rect x="10" y="-70" width="56" height="70" fill="#d6d3df" stroke="${INK}" stroke-width="2"/>
      <g stroke="#9a96aa" stroke-width="2">${[-62, -54, -46, -38, -30, -22, -14, -6].map((y) => `<line x1="12" y1="${y}" x2="64" y2="${y}"/>`).join('')}</g>
      <rect x="76" y="-90" width="30" height="24" fill="#7f6b8c" stroke="${INK}" stroke-width="2"/>
      <!-- right: mauve shop with striped awning -->
      <rect x="282" y="-120" width="124" height="120" fill="url(#trMauve)" stroke="${INK}" stroke-width="2.4"/>
      <path d="M278 -98 H404 L398 -80 H284 Z" fill="url(#trAwning)" stroke="${INK}" stroke-width="2.2" stroke-linejoin="round"/>
      <g>${[296, 330, 364].map((x) => `<rect x="${x}" y="-72" width="24" height="34" fill="url(#trGlass)" stroke="${INK}" stroke-width="2"/><path d="M${x + 12} -72V-38" stroke="#efe6f2" stroke-width="2"/>`).join('')}</g>
      <rect x="290" y="-110" width="40" height="8" rx="2" fill="#8f6a83"/>
      <!-- tall building with a roof garden -->
      <rect x="330" y="-250" width="62" height="130" fill="url(#trConcrete)" stroke="${INK}" stroke-width="2.4"/>
      ${[-230, -200, -170].map((y) => `<rect x="346" y="${y}" width="12" height="18" fill="#5d5a7a" stroke="${INK}" stroke-width="1.6"/><rect x="366" y="${y}" width="12" height="18" fill="#5d5a7a" stroke="${INK}" stroke-width="1.6"/>`).join('')}
      <path d="M332 -250 q8 -18 18 -6 q6 -16 18 -4 q8 -14 20 0 q6 -6 6 6 Z" fill="#5cbf4a" stroke="${INK}" stroke-width="2"/>
      <!-- lamp post -->
      <rect x="66" y="-150" width="5" height="150" fill="url(#trSteel)" stroke="${INK}" stroke-width="1.8"/>
      <path d="M68.5 -150 q0 -14 12 -14" stroke="${INK}" stroke-width="5" fill="none" stroke-linecap="round"/><path d="M68.5 -150 q0 -14 12 -14" stroke="#c9ced8" stroke-width="2.4" fill="none" stroke-linecap="round"/>
      <path d="M76 -166 h16 l-3 10 h-10 Z" fill="#fff4b0" stroke="${INK}" stroke-width="2"/>
      <ellipse cx="84" cy="-150" rx="16" ry="10" fill="#fff4b0" opacity="0.35"/>
      <!-- the shop the tower stands on -->
      <g transform="translate(${bx} 0)">
        <rect x="-75" y="-132" width="150" height="132" fill="url(#trGreen)" stroke="${INK}" stroke-width="2.8"/>
        <rect x="-70" y="-128" width="8" height="124" fill="#fff" opacity="0.18"/>
        <rect x="-79" y="-136" width="158" height="10" rx="2" fill="#24521f" stroke="${INK}" stroke-width="2.4"/>
        <rect x="-46" y="-122" width="92" height="20" rx="3" fill="#1f3f1c" stroke="#d9c98a" stroke-width="2"/>
        <text x="0" y="-107.5" font-family="Poppins, Arial, sans-serif" font-size="11" font-weight="800" text-anchor="middle" fill="#f3e3a8">Wine Store</text>
        ${[-60, 14].map((x) => `
          <rect x="${x}" y="-92" width="46" height="60" fill="url(#trGlassWarm)" stroke="${INK}" stroke-width="2.4"/>
          <path d="M${x + 15} -92V-32M${x + 31} -92V-32M${x} -62H${x + 46}" stroke="#3a6a32" stroke-width="2.4"/>
          <g fill="#7a2a2a">${[6, 18, 30, 40].map((dx) => `<rect x="${x + dx}" y="-58" width="3.5" height="12" rx="1.5"/>`).join('')}</g>`).join('')}
        <rect x="-12" y="-92" width="24" height="92" fill="#2c5d27" stroke="${INK}" stroke-width="2.4"/>
        <rect x="-8" y="-86" width="16" height="40" fill="url(#trGlassWarm)" stroke="${INK}" stroke-width="1.6"/>
        <circle cx="6" cy="-40" r="1.8" fill="#ffd23d"/>
        <rect x="-82" y="-82" width="7" height="16" rx="3" fill="#e8352e" stroke="${INK}" stroke-width="1.8"/>
        <rect x="75" y="-82" width="7" height="16" rx="3" fill="#e8352e" stroke="${INK}" stroke-width="1.8"/>
        <rect x="-74" y="-28" width="16" height="14" fill="#b56f35" stroke="${INK}" stroke-width="1.8"/>
        <rect x="56" y="-24" width="14" height="12" fill="#3d8bfd" stroke="${INK}" stroke-width="1.8"/>
        <rect x="60" y="-36" width="10" height="12" fill="#e8352e" stroke="${INK}" stroke-width="1.6"/>
      </g>
      <!-- pavement and the street edge -->
      <rect x="-10" y="0" width="420" height="16" fill="#cbc6d2" stroke="${INK}" stroke-width="2"/>
      <g stroke="#a7a1b3" stroke-width="1.6">${[30, 80, 130, 180, 230, 280, 330, 380].map((x) => `<line x1="${x}" y1="1" x2="${x - 6}" y2="15"/>`).join('')}</g>
      <rect x="-10" y="16" width="420" height="${STREET}" fill="url(#trSoil)" stroke="${INK}" stroke-width="2"/>
      ${[[28, 52, 22], [96, 84, 16], [170, 46, 26], [246, 92, 20], [318, 56, 24], [372, 104, 14], [140, 118, 18]].map(([x, y, r]) =>
        `<path d="M${x - r} ${y} Q${x - r * 0.6} ${y - r * 0.8} ${x} ${y - r * 0.7} Q${x + r} ${y - r * 0.6} ${x + r} ${y} Q${x + r * 0.4} ${y + r * 0.5} ${x - r * 0.3} ${y + r * 0.4} Z" fill="#7a6658" stroke="${INK}" stroke-width="1.8"/>`).join('')}`;
  }

  function cloud(x, y, s) {
    return `<g transform="translate(${x} ${y}) scale(${s})">
      <path d="M-50 10 Q-60 -8 -40 -12 Q-36 -32 -12 -28 Q0 -46 22 -32 Q46 -36 48 -12 Q66 -6 56 10 Z" fill="#ffffff" stroke="${INK}" stroke-width="2.4" stroke-linejoin="round"/>
      <path d="M-40 4 Q-20 10 0 6 Q30 12 50 4" fill="none" stroke="#c9dff0" stroke-width="5" stroke-linecap="round"/></g>`;
  }

  const CRANE = `
    <path d="M200 -400 V0" stroke="#2b2b30" stroke-width="3"/>
    <g>
      <path d="M186 -6 H214 L210 18 H190 Z" fill="url(#trHazard)" stroke="${INK}" stroke-width="2.4" stroke-linejoin="round"/>
      <path d="M200 18 v8 q0 10 -9 10 q-7 0 -8 -7" fill="none" stroke="${INK}" stroke-width="5" stroke-linecap="round"/>
      <path d="M200 18 v8 q0 10 -9 10 q-7 0 -8 -7" fill="none" stroke="#c9ced8" stroke-width="2.4" stroke-linecap="round"/>
    </g>`;

  // ---------------------------------------------------------------------------------------------
  // The engine
  // ---------------------------------------------------------------------------------------------
  const ease = { out: (t) => 1 - Math.pow(1 - t, 3), inOut: (t) => 0.5 - 0.5 * Math.cos(Math.PI * Math.min(1, Math.max(0, t))), in: (t) => t * t };
  const svgEl = (name, attrs = {}, html = '') => {
    const e = document.createElementNS(NS, name);
    for (const k in attrs) e.setAttribute(k, attrs[k]);
    if (html) e.innerHTML = html;
    return e;
  };

  class TowerScene {
    constructor(svg) {
      this.svg = svg;
      this.H = 700;
      this.floors = [];           // [{ el, skin, x, top, h }]
      this.cam = 0; this.camTarget = 0;
      this.towerX = W / 2;
      this.state = 'hang';        // hang | drop | settle | collapse | clear
      this.anims = [];
      this.t0 = performance.now();
      this.nextSkin = this.pickSkin();
      this.build();
      this.resize();
      this.loop = this.loop.bind(this);
      requestAnimationFrame(this.loop);
      window.addEventListener('resize', () => this.resize());
    }

    pickSkin(avoid) {
      const pool = SKIN_IDS.filter((s) => s !== avoid);
      return pool[Math.floor(Math.random() * pool.length)];
    }

    build() {
      const s = this.svg;
      s.innerHTML = `<defs>${DEFS}</defs>`;
      this.sky = svgEl('rect', { x: 0, y: 0, width: W, height: 2000, fill: 'url(#trSky)' });
      s.appendChild(this.sky);
      this.cloudLayer = svgEl('g');
      for (let i = 0; i < 9; i++) {
        this.cloudLayer.insertAdjacentHTML('beforeend', cloud(40 + ((i * 137) % 320), -380 - i * 260, 0.7 + ((i * 7) % 5) * 0.12));
      }
      s.appendChild(this.cloudLayer);
      this.world = svgEl('g');
      this.world.appendChild(svgEl('g', {}, streetSvg()));
      this.towerLayer = svgEl('g');
      this.world.appendChild(this.towerLayer);
      s.appendChild(this.world);
      this.fxLayer = svgEl('g');
      s.appendChild(this.fxLayer);
      // crane + the hanging block, in screen space
      this.craneG = svgEl('g', {}, CRANE);
      this.hang = svgEl('g');
      this.craneG.appendChild(this.hang);
      this.ropes = svgEl('path', { fill: 'none', stroke: INK, 'stroke-width': 2 });
      this.craneG.insertBefore(this.ropes, this.hang);
      s.appendChild(this.craneG);
      this.setHanging(this.nextSkin);
    }

    setHanging(skin) {
      this.hangSkin = skin;
      const k = SKINS[skin];
      this.hang.innerHTML = `<g class="blk">${k.svg()}</g>`;
      this.hangDrop = 0;           // slides down from above when a new block arrives
      this.hangArrive = performance.now();
    }

    resize() {
      const r = this.svg.getBoundingClientRect();
      if (!r.width) return;
      this.H = Math.round(W * r.height / r.width);
      this.svg.setAttribute('viewBox', `0 0 ${W} ${this.H}`);
      this.sky.setAttribute('height', this.H);
    }

    get ground() { return this.H - STREET; }                 // street level on screen at cam = 0
    towerTop() { return this.floors.length ? this.floors[this.floors.length - 1].top : -BASE.h; }   // world y
    hookY() { return 22; }

    /** Where the hanging block's bottom-centre is on screen right now, and its tilt. */
    swing(now) {
      const t = (now - this.t0) / 1000;
      const x = W / 2 + Math.sin(t * 2 * Math.PI / 2.3) * 108;
      const rot = -Math.cos(t * 2 * Math.PI / 2.3) * 9;
      return { x, rot };
    }

    loop(now) {
      // camera eases toward its target
      this.cam += (this.camTarget - this.cam) * 0.08;
      const gy = this.ground + this.cam;
      this.world.setAttribute('transform', `translate(0 ${gy.toFixed(2)})`);
      this.cloudLayer.setAttribute('transform', `translate(0 ${(this.H - STREET + this.cam * 0.55).toFixed(2)})`);

      // the swinging hook and block (hidden while a block is falling)
      const sw = this.swing(now);
      const arrive = ease.out(Math.min(1, (now - this.hangArrive) / 500));
      const blockH = SKINS[this.hangSkin].h;
      const ropeTop = this.hookY() + 14;
      const by = ropeTop + 26 + blockH - (1 - arrive) * (blockH + 120);
      this.craneG.setAttribute('transform', `translate(${(sw.x - W / 2).toFixed(2)} ${(-(1 - arrive) * 120).toFixed(2)})`);
      this.hang.setAttribute('transform', `translate(${W / 2} ${(by + (1 - arrive) * 120).toFixed(2)}) rotate(${sw.rot.toFixed(2)} 0 ${-blockH})`);
      const half = SKINS[this.hangSkin].w * 0.36;
      const topY = by + (1 - arrive) * 120 - blockH;
      this.ropes.setAttribute('d', this.hidden ? '' : `M${W / 2} ${ropeTop + 12} L${W / 2 - half} ${topY.toFixed(1)} M${W / 2} ${ropeTop + 12} L${W / 2 + half} ${topY.toFixed(1)}`);
      this.hang.style.display = this.hidden ? 'none' : '';

      // one-shot animations
      this.anims = this.anims.filter((a) => {
        const k = Math.min(1, (now - a.start) / a.dur);
        a.step(k, now);
        if (k >= 1) { if (a.done) a.done(); return false; }
        return true;
      });
      requestAnimationFrame(this.loop);
    }

    anim(dur, step, done) {
      return new Promise((resolve) => {
        this.anims.push({ start: performance.now(), dur, step, done: () => { if (done) done(); resolve(); } });
      });
    }

    aimCamera() {
      // keep the top of the tower around 58% down the view, never below the street's rest position
      const topScreenAtRest = this.ground + this.towerTop();
      this.camTarget = Math.max(0, this.H * 0.58 - topScreenAtRest);
    }

    /** Rebuild a tower already in progress (page reload), with no animation. */
    restore(count) {
      this.towerLayer.innerHTML = '';
      this.floors = [];
      this.towerX = W / 2;
      for (let i = 0; i < count; i++) this.addFloor(this.pickSkin(this.floors.length ? this.floors[this.floors.length - 1].skin : null), (Math.random() - 0.5) * 10, false);
      this.aimCamera();
      this.cam = this.camTarget;
    }

    addFloor(skin, dx, squash) {
      const k = SKINS[skin];
      const top = this.towerTop() - k.h;
      this.towerX = Math.max(W / 2 - 22, Math.min(W / 2 + 22, this.towerX + dx));
      const g = svgEl('g', { transform: `translate(${this.towerX.toFixed(1)} ${(top + k.h).toFixed(1)})` }, `<g class="blk">${k.svg()}</g>`);
      this.towerLayer.appendChild(g);
      const f = { el: g, skin, x: this.towerX, top, h: k.h };
      this.floors.push(f);
      if (squash) {
        const inner = g.firstChild;
        this.anim(320, (t) => {
          const s = 1 - Math.sin(Math.PI * t) * 0.12 * (1 - t);
          inner.setAttribute('transform', `scale(${(2 - s).toFixed(3)} ${s.toFixed(3)})`);
        }, () => inner.removeAttribute('transform'));
      }
      return f;
    }

    /** Smoke puffs either side of a landing at screen point (x, y). */
    puffs(x, y, big) {
      for (const dir of [-1, 1]) {
        for (let i = 0; i < (big ? 4 : 3); i++) {
          const r = 10 + Math.random() * 8;
          const c = svgEl('circle', { cx: x, cy: y, r, fill: '#ffffff', stroke: INK, 'stroke-width': 2, opacity: 0.95 });
          this.fxLayer.appendChild(c);
          const dx = dir * (40 + Math.random() * 40 + i * 12), dy = -6 - Math.random() * 20;
          this.anim(650 + i * 60, (t) => {
            const e = ease.out(t);
            c.setAttribute('cx', (x + dx * e).toFixed(1));
            c.setAttribute('cy', (y + dy * e).toFixed(1));
            c.setAttribute('r', (r * (0.6 + 0.7 * e)).toFixed(1));
            c.setAttribute('opacity', (0.95 * (1 - t)).toFixed(2));
          }, () => c.remove());
        }
      }
    }

    /**
     * Drop the hanging block. `collapse` false: it lands on the tower (small offset), squashes and
     * puffs smoke. `collapse` true: it either lands badly and topples off the side, or bursts into a
     * cloud of smoke on the way down. Resolves when the block's story is over.
     */
    drop({ collapse }) {
      const now = performance.now();
      const sw = this.swing(now);
      const skin = this.hangSkin;
      const k = SKINS[skin];
      const startX = sw.x, startRot = sw.rot;
      const startY = this.hookY() + 40 + k.h;                  // bottom of the block on screen
      const gy = this.ground + this.cam;
      const landY = gy + this.towerTop();                       // screen y of the tower's top
      const landDx = collapse ? 0 : (Math.random() - 0.5) * 14;
      const landX = this.towerX + landDx;
      const variant = collapse ? (Math.random() < 0.5 ? 'topple' : 'poof') : 'land';
      const side = collapse ? (startX < this.towerX ? -1 : 1) : 0;
      const missX = this.towerX + side * k.w * 0.62;

      this.hidden = true;
      const g = svgEl('g', {}, `<g class="blk">${k.svg()}</g>`);
      this.fxLayer.appendChild(g);
      const fallDur = Math.max(380, Math.min(800, (landY - startY) * 1.4));
      const endY = variant === 'poof' ? startY + (landY - startY) * 0.6 : landY;
      const endX = variant === 'land' ? landX : (variant === 'topple' ? missX : startX + (this.towerX - startX) * 0.3);

      return this.anim(fallDur, (t) => {
        const e = ease.in(t);
        const x = startX + (endX - startX) * ease.inOut(t);
        const y = startY + (endY - startY) * e;
        const rot = startRot * (1 - t);
        g.setAttribute('transform', `translate(${x.toFixed(1)} ${y.toFixed(1)}) rotate(${rot.toFixed(2)} 0 ${-k.h / 2})`);
      }).then(() => {
        if (variant === 'land') {
          g.remove();
          this.addFloor(skin, landX - this.towerX, true);
          this.puffs(landX, landY, false);
          this.aimCamera();
          return this.nextBlock();
        }
        if (variant === 'poof') {
          g.remove();
          this.poof(endX, endY - k.h / 2);
          return this.anim(500, () => {});
        }
        // topple: pivot over the edge of the tower, then fall away and fade
        this.puffs(this.towerX + side * k.w * 0.35, landY, true);
        const pivotX = side * k.w * 0.5 * -1;
        return this.anim(520, (t) => {
          const r = side * 95 * ease.in(t);
          g.setAttribute('transform', `translate(${missX.toFixed(1)} ${landY.toFixed(1)}) rotate(${r.toFixed(1)} ${pivotX} 0)`);
        }).then(() => this.anim(700, (t) => {
          const r = side * (95 + 90 * t);
          const fy = landY + 600 * ease.in(t), fx = missX + side * 80 * t;
          g.setAttribute('transform', `translate(${fx.toFixed(1)} ${fy.toFixed(1)}) rotate(${r.toFixed(1)} ${pivotX} 0)`);
          g.setAttribute('opacity', (1 - t * 0.6).toFixed(2));
        }, () => g.remove()));
      });
    }

    /** A cartoon smoke burst. */
    poof(x, y) {
      const parts = [];
      for (let i = 0; i < 9; i++) {
        const a = (i / 9) * Math.PI * 2, d = 16 + Math.random() * 18, r = 16 + Math.random() * 14;
        const c = svgEl('circle', { cx: x, cy: y, r, fill: '#ffffff', stroke: INK, 'stroke-width': 2.4 });
        this.fxLayer.appendChild(c);
        parts.push(c);
        this.anim(800, (t) => {
          const e = ease.out(t);
          c.setAttribute('cx', (x + Math.cos(a) * d * (0.4 + e)).toFixed(1));
          c.setAttribute('cy', (y + Math.sin(a) * d * (0.4 + e)).toFixed(1));
          c.setAttribute('r', (r * (0.5 + 0.8 * e)).toFixed(1));
          c.setAttribute('opacity', (t < 0.6 ? 1 : 1 - (t - 0.6) / 0.4).toFixed(2));
        }, () => c.remove());
      }
    }

    /** Bring the next block down from above. */
    nextBlock() {
      this.setHanging(this.pickSkin(this.hangSkin));
      this.hidden = false;
      return this.anim(260, () => {});
    }

    /** After a collapse or a cash-out: shake the tower down, then a fresh block for a fresh street. */
    clear() {
      const fl = this.floors.slice();
      this.floors = [];
      this.towerX = W / 2;
      this.camTarget = 0;
      const p = fl.map((f, i) => {
        const dir = i % 2 ? 1 : -1;
        const delay = (fl.length - 1 - i) * 60;
        return new Promise((res) => setTimeout(() => {
          this.anim(650, (t) => {
            f.el.setAttribute('transform', `translate(${(f.x + dir * 60 * t).toFixed(1)} ${(f.top + f.h + 500 * ease.in(t)).toFixed(1)}) rotate(${(dir * 40 * t).toFixed(1)})`);
            f.el.setAttribute('opacity', (1 - t).toFixed(2));
          }, () => { f.el.remove(); res(); });
        }, delay));
      });
      return Promise.all(p).then(() => {
        if (this.hidden) return this.nextBlock();
      });
    }

    /** Coins bursting from the top of the tower. */
    burst() {
      const gy = this.ground + this.cam;
      const x0 = this.towerX, y0 = gy + this.towerTop();
      for (let i = 0; i < 18; i++) {
        const c = svgEl('g', {}, `<circle r="8" fill="#ffc21a" stroke="${INK}" stroke-width="2"/><circle r="4.5" fill="#ffe27a" stroke="#c98a00" stroke-width="1.2"/>`);
        this.fxLayer.appendChild(c);
        const vx = (Math.random() - 0.5) * 260, vy = -220 - Math.random() * 200;
        this.anim(1300, (t) => {
          const s = t * 1.3;
          c.setAttribute('transform', `translate(${(x0 + vx * s).toFixed(1)} ${(y0 + vy * s + 420 * s * s).toFixed(1)}) scale(${(1 - 0.3 * t).toFixed(2)})`);
          c.setAttribute('opacity', (1 - t * t).toFixed(2));
        }, () => c.remove());
      }
    }

    /** Screen position of the tower top (for floating labels in the page). */
    topOnScreen() {
      return { x: this.towerX, y: this.ground + this.camTarget + this.towerTop() };
    }
  }

  TowerScene.art = { DEFS, SKINS, INK, cloud };   // for the home-page cover and tools
  window.TowerScene = TowerScene;
})();
