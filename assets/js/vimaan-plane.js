/**
 * Vimaan (Aviator) plane — original cartoon prop plane in the site's illustrated style (dark ink
 * outline, glossy shading), with a propeller that visibly spins.
 *
 * VISUAL ONLY. aviator.html keeps every bit of its game logic; its plane-drawing line simply calls
 *
 *     VimaanPlane.draw(ctx, planeSize)
 *
 * with the canvas already translated to the plane's centre and rotated to its pitch — exactly where
 * it used to draw assets/plane.png — and falls back to that image if this file is not ready.
 *
 * The airframe is a static SVG, rasterised once; the propeller, the pilot's scarf and a tiny engine
 * shudder are drawn per frame from the wall clock, so they animate whatever the game is doing.
 */
(function () {
  'use strict';
  const INK = '#2a1a14';
  // Airframe, facing right, in a 200 x 120 box. Propeller hub at (186, 62).
  const HUB = { x: 186, y: 62 };
  const BODY = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 200 120" width="600" height="360">
  <defs>
    <linearGradient id="vpRed" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#ff6b5e"/><stop offset="0.5" stop-color="#e8302a"/><stop offset="1" stop-color="#a51a16"/></linearGradient>
    <linearGradient id="vpRedWing" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#ff5a4d"/><stop offset="1" stop-color="#b81f1a"/></linearGradient>
    <linearGradient id="vpGlass" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#bfe6ff"/><stop offset="0.6" stop-color="#5a9fd6"/><stop offset="1" stop-color="#2d5f8f"/></linearGradient>
    <linearGradient id="vpSteel" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#f1f3f7"/><stop offset="1" stop-color="#9aa1b0"/></linearGradient>
    <radialGradient id="vpSkin" cx="40%" cy="35%" r="70%"><stop offset="0" stop-color="#ffe0c2"/><stop offset="1" stop-color="#e9a77a"/></radialGradient>
  </defs>
  <!-- far wing, just peeking out under the fuselage -->
  <path d="M84 70 L118 70 L108 84 L76 84 Z" fill="#8f1612" stroke="${INK}" stroke-width="2.6" stroke-linejoin="round"/>
  <!-- tailplane and fin -->
  <path d="M10 58 L40 60 L36 68 L8 66 Z" fill="url(#vpRedWing)" stroke="${INK}" stroke-width="2.8" stroke-linejoin="round"/>
  <path d="M14 58 Q10 30 22 24 L34 26 Q34 42 44 56 Z" fill="url(#vpRed)" stroke="${INK}" stroke-width="3" stroke-linejoin="round"/>
  <path d="M19 33 Q20 30 25 29" stroke="#fff" stroke-width="2.4" fill="none" stroke-linecap="round" opacity="0.6"/>
  <!-- fuselage -->
  <path d="M12 56 Q40 50 70 44 Q110 36 150 40 Q176 43 182 54 Q184 64 178 72 Q160 84 120 84 Q70 82 34 72 Q16 68 12 62 Z"
        fill="url(#vpRed)" stroke="${INK}" stroke-width="3.2" stroke-linejoin="round"/>
  <path d="M40 54 Q90 42 150 45" stroke="#fff" stroke-width="3.2" fill="none" stroke-linecap="round" opacity="0.45"/>
  <path d="M30 70 Q90 80 160 74" stroke="#000" stroke-width="3" fill="none" stroke-linecap="round" opacity="0.15"/>
  <!-- engine cowling and spinner -->
  <path d="M158 42 Q178 44 183 54 Q186 64 179 73 Q170 79 158 80 Z" fill="url(#vpSteel)" stroke="${INK}" stroke-width="3" stroke-linejoin="round"/>
  <path d="M166 48 L166 76 M172 50 L172 74" stroke="${INK}" stroke-width="1.6" opacity="0.4"/>
  <path d="M180 52 Q194 62 180 72 Q177 62 180 52 Z" fill="#ffd23d" stroke="${INK}" stroke-width="2.6" stroke-linejoin="round"/>
  <!-- cockpit: canopy, pilot -->
  <path d="M92 44 Q100 26 122 26 Q136 28 140 40 Z" fill="url(#vpGlass)" stroke="${INK}" stroke-width="2.8" stroke-linejoin="round" opacity="0.95"/>
  <circle cx="114" cy="36" r="8" fill="url(#vpSkin)" stroke="${INK}" stroke-width="2.2"/>
  <path d="M106 34 Q114 24 122 34 Q114 31 106 34 Z" fill="#7a4a2a" stroke="${INK}" stroke-width="1.8"/>
  <rect x="110" y="31.5" width="11" height="5" rx="2.5" fill="#ffd23d" stroke="${INK}" stroke-width="1.6"/>
  <path d="M100 40 Q104 30 112 28" stroke="#fff" stroke-width="2.4" fill="none" stroke-linecap="round" opacity="0.8"/>
  <!-- roundel -->
  <circle cx="64" cy="62" r="8" fill="#fff" stroke="${INK}" stroke-width="2.2"/>
  <circle cx="64" cy="62" r="4" fill="#ffd23d"/>
  <!-- near wing -->
  <!-- seen from the side the wing is a slim blade, tipping slightly toward the viewer -->
  <path d="M64 70 Q100 64 142 64 Q152 66 146 72 Q104 82 70 84 Q58 84 58 78 Q58 72 64 70 Z"
        fill="url(#vpRedWing)" stroke="${INK}" stroke-width="3" stroke-linejoin="round"/>
  <path d="M68 73 Q104 67 140 67" stroke="#fff" stroke-width="2.4" fill="none" stroke-linecap="round" opacity="0.55"/>
  <path d="M84 79 L96 78" stroke="${INK}" stroke-width="1.6" opacity="0.35"/>
</svg>`;

  const img = new Image();
  let ready = false;
  img.onload = () => { ready = true; };
  img.src = URL.createObjectURL(new Blob([BODY], { type: 'image/svg+xml' }));

  const SPIN_HZ = 7;            // revolutions per second: fast, but slow enough to read as spinning

  /** One blade, drawn from the hub out to length `len` (negative = the other way), seen side-on. */
  function blade(ctx, len, width, shade) {
    ctx.beginPath();
    ctx.moveTo(-width * 0.35, 0);
    ctx.quadraticCurveTo(-width * 0.9, len * 0.55, -width * 0.25, len);
    ctx.quadraticCurveTo(width * 0.6, len * 0.98, width * 0.55, len * 0.5);
    ctx.lineTo(width * 0.35, 0);
    ctx.closePath();
    ctx.fillStyle = shade;
    ctx.fill();
    ctx.stroke();
  }

  /**
   * The propeller spins about the flight axis, so from the side each blade is seen foreshortened:
   * its visible length is R*cos(angle) and its face turns from lit to shadowed as it comes round.
   * Faint ghost blades trail behind the real ones, over a translucent spin disc.
   */
  function propeller(ctx, x, y, R, t) {
    const a = (t * SPIN_HZ * Math.PI * 2) % (Math.PI * 2);
    ctx.save();
    ctx.translate(x, y);
    // spin disc
    ctx.beginPath();
    ctx.ellipse(0, 0, R * 0.16, R, 0, 0, Math.PI * 2);
    ctx.fillStyle = 'rgba(220, 228, 240, 0.22)';
    ctx.fill();
    ctx.lineWidth = Math.max(1, R * 0.03);
    ctx.strokeStyle = 'rgba(42, 26, 20, 0.25)';
    ctx.stroke();
    ctx.strokeStyle = INK;
    ctx.lineJoin = 'round';
    ctx.lineWidth = Math.max(1.2, R * 0.06);
    // ghosts first, then the two real blades
    for (const [lag, alpha] of [[0.55, 0.12], [0.3, 0.22]]) {
      ctx.globalAlpha = alpha;
      for (const k of [0, Math.PI]) blade(ctx, R * Math.cos(a - lag + k), R * 0.22, '#c9ced8');
    }
    ctx.globalAlpha = 1;
    for (const k of [0, Math.PI]) {
      const c = Math.cos(a + k), s = Math.sin(a + k);
      blade(ctx, R * c, R * 0.24, s > 0 ? '#eef1f6' : '#9aa1b0');
    }
    // hub cap over the root of the blades
    ctx.beginPath();
    ctx.ellipse(0, 0, R * 0.12, R * 0.16, 0, 0, Math.PI * 2);
    ctx.fillStyle = '#ffd23d';
    ctx.fill();
    ctx.stroke();
    ctx.restore();
  }

  /** The pilot's scarf, streaming back from the cockpit and flapping in the wind. */
  function scarf(ctx, s, ox, oy, t) {
    const px = (x) => ox + x * s, py = (y) => oy + y * s;
    const wave = (i) => Math.sin(t * 18 - i * 1.3) * 3.2;
    ctx.beginPath();
    ctx.moveTo(px(108), py(40));
    ctx.bezierCurveTo(px(98), py(40 + wave(0)), px(88), py(36 + wave(1)), px(78), py(38 + wave(2)));
    ctx.lineTo(px(76), py(44 + wave(2)));
    ctx.bezierCurveTo(px(86), py(42 + wave(1)), px(96), py(46 + wave(0)), px(108), py(44));
    ctx.closePath();
    ctx.fillStyle = '#ffd23d';
    ctx.fill();
    ctx.lineWidth = Math.max(1, 1.8 * s);
    ctx.strokeStyle = INK;
    ctx.stroke();
  }

  /**
   * Draw the plane centred on the current canvas origin, `size` across (the same footprint the
   * old image used). The canvas transform (position, pitch) is the caller's and is restored.
   */
  function draw(ctx, size) {
    if (!ready) return false;
    const t = performance.now() / 1000;
    const w = size * 1.12, h = w * 0.6, s = w / 200;
    const ox = -w / 2, oy = -h / 2 + Math.sin(t * 55) * 0.35 * s;    // a tiny engine shudder
    ctx.save();
    ctx.rotate(-0.18);                                                // the old art was drawn nose-up
    scarf(ctx, s, ox, oy, t);
    ctx.drawImage(img, ox, oy, w, h);
    propeller(ctx, ox + HUB.x * s, oy + HUB.y * s, 38 * s, t);
    ctx.restore();
    return true;
  }

  window.VimaanPlane = { draw, get ready() { return ready; } };
})();
