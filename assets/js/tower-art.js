/**
 * Tower Rush — painted assets.
 *
 * Every asset is PAINTED in code onto a high-resolution canvas: procedural wood grain, plaster, brick,
 * concrete and soil textures, cylinder and ambient-occlusion shading, soft cast shadows and one warm
 * key light from the upper left — the semi-realistic casual-game look of the original, with no
 * cartoon outlines. Nothing is downloaded; each asset is painted once per page load and cached.
 *
 *   TowerArt.get(name) -> { url, w, h }   size in scene units (the scene is 400 units wide)
 *
 *   blocks   cabin, yellow, red, hex     anchor: bottom centre
 *   street   street                      anchor: x 0, y -STREET_TOP (ground level is y 0)
 *   props    hook, puff1..3, coin, tool_hammer, tool_wrench, tool_plank, tool_brick, tool_gear
 */
(function () {
  'use strict';
  const SCALE = 3;                       // painted at 3x the scene unit: crisp on phones and when scaled
  const STREET_TOP = 300, STREET_BOTTOM = 140;

  // ---------------------------------------------------------------------------------------------
  // Noise
  // ---------------------------------------------------------------------------------------------
  function hash(x, y, s) {
    let h = (x * 374761393 + y * 668265263 + (s | 0) * 2147483647) | 0;
    h = Math.imul(h ^ (h >>> 13), 1274126177);
    return ((h ^ (h >>> 16)) >>> 0) / 4294967295;
  }
  function vnoise(x, y, s) {
    const xi = Math.floor(x), yi = Math.floor(y), xf = x - xi, yf = y - yi;
    const u = xf * xf * (3 - 2 * xf), v = yf * yf * (3 - 2 * yf);
    const a = hash(xi, yi, s), b = hash(xi + 1, yi, s), c = hash(xi, yi + 1, s), d = hash(xi + 1, yi + 1, s);
    return a + (b - a) * u + (c - a) * v + (a - b - c + d) * u * v;
  }
  function fbm(x, y, s, oct = 5) {
    let f = 0, amp = 0.5, n = 0;
    for (let i = 0; i < oct; i++) { f += amp * vnoise(x, y, s + i * 17); n += amp; x *= 2.03; y *= 2.03; amp *= 0.5; }
    return f / n;
  }
  let seedCounter = 1;
  let rs = 1234567;
  const rnd = () => { rs = (rs * 16807) % 2147483647; return rs / 2147483647; };
  const rr = (a, b) => a + rnd() * (b - a);

  // ---------------------------------------------------------------------------------------------
  // Canvas helpers
  // ---------------------------------------------------------------------------------------------
  function canvas(w, h) {
    const c = document.createElement('canvas');
    c.width = Math.ceil(w * SCALE); c.height = Math.ceil(h * SCALE);
    const ctx = c.getContext('2d');
    ctx.scale(SCALE, SCALE);
    return { c, ctx };
  }
  /** A grey texture (128 = neutral) from a per-pixel shader, for 'overlay' / 'soft-light' blending. */
  function texture(w, h, shader) {
    const c = document.createElement('canvas');
    c.width = w; c.height = h;
    const ctx = c.getContext('2d');
    const img = ctx.createImageData(w, h);
    const d = img.data;
    for (let y = 0; y < h; y++) for (let x = 0; x < w; x++) {
      const v = Math.max(0, Math.min(255, shader(x, y) * 255));
      const i = (y * w + x) * 4;
      d[i] = d[i + 1] = d[i + 2] = v; d[i + 3] = 255;
    }
    ctx.putImageData(img, 0, 0);
    return c;
  }
  const TEX = {};
  const tex = {
    /** Long-fibre wood grain, running horizontally. */
    grain() {
      if (TEX.grain) return TEX.grain;
      const s = seedCounter++;
      return (TEX.grain = texture(900, 300, (x, y) => {
        const warp = fbm(x * 0.006, y * 0.02, s, 4) * 9;
        const lines = Math.sin(y * 0.55 + warp * 2.2) * 0.5 + 0.5;
        const fine = fbm(x * 0.05, y * 0.6, s + 5, 3);
        const blotch = fbm(x * 0.01, y * 0.03, s + 9, 3);
        return 0.5 + (lines - 0.5) * 0.24 + (fine - 0.5) * 0.32 + (blotch - 0.5) * 0.34;
      }));
    },
    /** Plaster / painted render: soft mottling, fine speckle, faint streaks of weathering. */
    plaster() {
      if (TEX.plaster) return TEX.plaster;
      const s = seedCounter++;
      return (TEX.plaster = texture(600, 600, (x, y) => {
        const m = fbm(x * 0.012, y * 0.012, s, 5);
        const streak = fbm(x * 0.05, y * 0.004, s + 7, 3);
        const sp = hash(x, y, s) > 0.987 ? -0.1 : 0;
        const f = fbm(x * 0.15, y * 0.15, s + 3, 2);
        return 0.5 + (m - 0.5) * 0.36 + (streak - 0.5) * 0.16 + (f - 0.5) * 0.14 + sp;
      }));
    },
    /** Stone, soil and concrete: clumpy noise. */
    grit() {
      if (TEX.grit) return TEX.grit;
      const s = seedCounter++;
      return (TEX.grit = texture(600, 400, (x, y) => {
        const m = fbm(x * 0.03, y * 0.03, s, 5);
        const f = fbm(x * 0.22, y * 0.22, s + 3, 2);
        const sp = hash(x, y, s + 1) > 0.97 ? 0.12 : 0;
        return 0.5 + (m - 0.5) * 0.55 + (f - 0.5) * 0.25 + sp;
      }));
    },
  };

  /** Paint a texture over whatever is currently clipped, with a blend mode, at a random offset. */
  function overlay(ctx, t, x, y, w, h, mode = 'overlay', alpha = 1, scale = 1, rotate = false) {
    ctx.save();
    ctx.globalCompositeOperation = mode;
    ctx.globalAlpha = alpha;
    if (rotate) {
      ctx.translate(x + w / 2, y + h / 2); ctx.rotate(Math.PI / 2);
      const sw = Math.min(t.width, h / scale), sh = Math.min(t.height, w / scale);
      ctx.drawImage(t, rnd() * (t.width - sw), rnd() * (t.height - sh), sw, sh, -h / 2, -w / 2, h, w);
    } else {
      const sw = Math.min(t.width, w / scale), sh = Math.min(t.height, h / scale);
      ctx.drawImage(t, rnd() * (t.width - sw), rnd() * (t.height - sh), sw, sh, x, y, w, h);
    }
    ctx.restore();
  }
  function lin(ctx, x0, y0, x1, y1, stops) {
    const g = ctx.createLinearGradient(x0, y0, x1, y1);
    stops.forEach(([o, c]) => g.addColorStop(o, c));
    return g;
  }
  function rad(ctx, x, y, r0, x1, y1, r1, stops) {
    const g = ctx.createRadialGradient(x, y, r0, x1, y1, r1);
    stops.forEach(([o, c]) => g.addColorStop(o, c));
    return g;
  }
  /** Lighten (t>0) or darken (t<0) a hex colour. */
  function shade(hex, t) {
    if (!t) return hex;
    const n = parseInt(hex.slice(1), 16);
    let r = n >> 16, g = (n >> 8) & 255, b = n & 255;
    if (t > 0) { r += (255 - r) * t; g += (255 - g) * t; b += (255 - b) * t; }
    else { r *= 1 + t; g *= 1 + t; b *= 1 + t; }
    return '#' + [r, g, b].map((v) => Math.round(Math.max(0, Math.min(255, v))).toString(16).padStart(2, '0')).join('');
  }
  function rgba(hex, a) {
    const n = parseInt(hex.slice(1), 16);
    return `rgba(${n >> 16},${(n >> 8) & 255},${n & 255},${a})`;
  }
  /** A slightly irregular rounded rect, so nothing looks ruler-drawn. */
  function wobblyRect(ctx, x, y, w, h, r, amt = 0.6) {
    const j = () => (rnd() - 0.5) * amt;
    ctx.beginPath();
    ctx.moveTo(x + r, y + j());
    ctx.lineTo(x + w - r, y + j());
    ctx.quadraticCurveTo(x + w + j(), y + j(), x + w + j(), y + r);
    ctx.lineTo(x + w + j(), y + h - r);
    ctx.quadraticCurveTo(x + w + j(), y + h + j(), x + w - r, y + h + j());
    ctx.lineTo(x + r, y + h + j());
    ctx.quadraticCurveTo(x + j(), y + h + j(), x + j(), y + h - r);
    ctx.lineTo(x + j(), y + r);
    ctx.quadraticCurveTo(x + j(), y + j(), x + r, y + j());
    ctx.closePath();
  }
  /** An organic blob (log end, stone, leaf clump). */
  function blob(ctx, cx, cy, rx, ry, lump = 0.08, s = seedCounter++, rot = 0) {
    ctx.beginPath();
    const N = 32;
    for (let i = 0; i <= N; i++) {
      const a = (i / N) * Math.PI * 2;
      const k = 1 + (fbm(Math.cos(a) * 1.4 + 5, Math.sin(a) * 1.4 + 5, s, 2) - 0.5) * 2.4 * lump;
      const lx = Math.cos(a) * rx * k, ly = Math.sin(a) * ry * k;
      const px = cx + lx * Math.cos(rot) - ly * Math.sin(rot), py = cy + lx * Math.sin(rot) + ly * Math.cos(rot);
      if (i === 0) ctx.moveTo(px, py); else ctx.lineTo(px, py);
    }
    ctx.closePath();
  }
  function poly(ctx, pts) {
    ctx.beginPath();
    pts.forEach(([x, y], i) => (i ? ctx.lineTo(x, y) : ctx.moveTo(x, y)));
    ctx.closePath();
  }
  /** Soft drop shadow for whatever `draw` fills. */
  function withShadow(ctx, blur, dx, dy, color, draw) {
    ctx.save();
    ctx.shadowColor = color; ctx.shadowBlur = blur * SCALE; ctx.shadowOffsetX = dx * SCALE; ctx.shadowOffsetY = dy * SCALE;
    draw();
    ctx.restore();
  }
  /** Fill the current path, then paint inside it: `inside(ctx)` runs clipped to that path. */
  function fillIn(ctx, fill, inside, shadow) {
    if (shadow) withShadow(ctx, shadow[0], shadow[1], shadow[2], shadow[3], () => { ctx.fillStyle = fill; ctx.fill(); });
    else { ctx.fillStyle = fill; ctx.fill(); }
    if (inside) { ctx.save(); ctx.clip(); inside(ctx); ctx.restore(); }
  }
  /** Darken the rim of the clipped area (ambient occlusion at the edges of a surface). */
  function edgeAO(ctx, x, y, w, h, a = 0.35, k = 0.18) {
    const m = Math.min(w, h) * k;
    ctx.save();
    ctx.globalCompositeOperation = 'multiply';
    ctx.fillStyle = lin(ctx, x, 0, x + m, 0, [[0, `rgba(40,24,14,${a})`], [1, 'rgba(40,24,14,0)']]); ctx.fillRect(x, y, m, h);
    ctx.fillStyle = lin(ctx, x + w, 0, x + w - m, 0, [[0, `rgba(40,24,14,${a})`], [1, 'rgba(40,24,14,0)']]); ctx.fillRect(x + w - m, y, m, h);
    ctx.fillStyle = lin(ctx, 0, y, 0, y + m, [[0, `rgba(40,24,14,${a})`], [1, 'rgba(40,24,14,0)']]); ctx.fillRect(x, y, w, m);
    ctx.fillStyle = lin(ctx, 0, y + h, 0, y + h - m, [[0, `rgba(40,24,14,${a})`], [1, 'rgba(40,24,14,0)']]); ctx.fillRect(x, y + h - m, w, m);
    ctx.restore();
  }
  /** Final pass on a finished asset: warm key light upper left, cool shade lower right. */
  function keyLight(ctx, w, h, k = 1) {
    ctx.save();
    ctx.globalCompositeOperation = 'source-atop';
    ctx.fillStyle = lin(ctx, 0, 0, w, h, [[0, `rgba(255,236,200,${0.16 * k})`], [0.5, 'rgba(255,236,200,0)'], [1, `rgba(30,40,70,${0.24 * k})`]]);
    ctx.fillRect(0, 0, w, h);
    ctx.restore();
  }

  // ---------------------------------------------------------------------------------------------
  // Materials and parts
  // ---------------------------------------------------------------------------------------------
  /** A plaster wall over the current path's bounding box. */
  function plasterWall(ctx, x, y, w, h, base) {
    ctx.fillStyle = lin(ctx, 0, y, 0, y + h, [[0, shade(base, 0.08)], [0.6, base], [1, shade(base, -0.12)]]);
    ctx.fillRect(x, y, w, h);
    overlay(ctx, tex.plaster(), x, y, w, h, 'overlay', 0.95, 1.4);
    overlay(ctx, tex.grit(), x, y, w, h, 'soft-light', 0.35, 1);
    // rain streaks below sills and the cornice
    ctx.save(); ctx.globalCompositeOperation = 'multiply';
    for (let i = 0; i < 7; i++) {
      const sx = x + rnd() * w, sy = y + rnd() * h * 0.6;
      ctx.fillStyle = lin(ctx, 0, sy, 0, sy + h * 0.3, [[0, 'rgba(90,60,40,.12)'], [1, 'rgba(90,60,40,0)']]);
      ctx.fillRect(sx, sy, rr(1, 3), h * 0.3);
    }
    ctx.restore();
  }

  /** One horizontal log (a cylinder seen side-on). */
  function paintLog(ctx, x, y, w, h, tone = 0) {
    const t = (c) => shade(c, tone);
    ctx.save();
    wobblyRect(ctx, x, y, w, h, h * 0.48, 1.1);
    ctx.clip();
    ctx.fillStyle = lin(ctx, 0, y, 0, y + h, [[0, t('#eccb98')], [0.22, t('#dbb07a')], [0.58, t('#bd8a52')], [0.86, t('#8c5a30')], [1, t('#5c381e')]]);
    ctx.fillRect(x - 2, y - 2, w + 4, h + 4);
    overlay(ctx, tex.grain(), x, y, w, h, 'overlay', 0.7, 0.8);
    overlay(ctx, tex.grain(), x, y, w, h, 'multiply', 0.08, 0.45);
    overlay(ctx, tex.grit(), x, y, w, h, 'soft-light', 0.45, 2);
    // checks and splits along the grain
    ctx.lineWidth = 0.5;
    for (let i = 0; i < 3 + Math.floor(rnd() * 3); i++) {
      const sx = x + rnd() * w * 0.75, sy = y + h * rr(0.25, 0.75), L = w * rr(0.08, 0.3);
      ctx.strokeStyle = `rgba(60,34,16,${rr(0.3, 0.6)})`;
      ctx.beginPath(); ctx.moveTo(sx, sy);
      for (let k = 1; k <= 7; k++) ctx.lineTo(sx + (L * k) / 7, sy + (rnd() - 0.5) * 1.1);
      ctx.stroke();
      ctx.strokeStyle = 'rgba(255,235,200,.22)';
      ctx.beginPath(); ctx.moveTo(sx, sy + 0.6); ctx.lineTo(sx + L, sy + 0.6); ctx.stroke();
    }
    if (rnd() < 0.6) {
      const kx = x + w * rr(0.15, 0.85), ky = y + h * rr(0.35, 0.65);
      ctx.fillStyle = rad(ctx, kx, ky, 0, kx, ky, h * 0.3, [[0, 'rgba(60,32,14,.75)'], [0.45, 'rgba(110,70,40,.35)'], [1, 'rgba(110,70,40,0)']]);
      ctx.beginPath(); ctx.ellipse(kx, ky, h * 0.36, h * 0.18, 0, 0, Math.PI * 2); ctx.fill();
    }
    // sheen along the top, deep crease below
    ctx.fillStyle = lin(ctx, 0, y, 0, y + h * 0.42, [[0, 'rgba(255,244,220,0)'], [0.5, 'rgba(255,244,220,.3)'], [1, 'rgba(255,244,220,0)']]);
    ctx.fillRect(x, y, w, h * 0.42);
    ctx.fillStyle = lin(ctx, 0, y + h * 0.72, 0, y + h, [[0, 'rgba(30,16,6,0)'], [1, 'rgba(30,16,6,.55)']]);
    ctx.fillRect(x, y + h * 0.7, w, h * 0.3);
    ctx.fillStyle = lin(ctx, 0, y, 0, y + h * 0.12, [[0, 'rgba(30,16,6,.45)'], [1, 'rgba(30,16,6,0)']]);
    ctx.fillRect(x, y, w, h * 0.12);
    ctx.restore();
  }

  /** A protruding log end at a corner: rounded, lumpy and pale, like the original. */
  function paintLogEnd(ctx, cx, cy, rx, ry, flip, tone = 0) {
    const t = (c) => shade(c, tone);
    const s = seedCounter++;
    ctx.save();
    blob(ctx, cx, cy, rx, ry, 0.14, s);
    withShadow(ctx, 3, flip * 1, 1.8, 'rgba(25,12,4,.55)', () => { ctx.fillStyle = t('#b48a60'); ctx.fill(); });
    ctx.clip();
    ctx.fillStyle = rad(ctx, cx - rx * 0.35, cy - ry * 0.5, rx * 0.05, cx + rx * 0.1, cy + ry * 0.1, rx * 1.3,
      [[0, t('#f8e6c6')], [0.35, t('#e8c99c')], [0.72, t('#b88a5a')], [1, t('#6a4426')]]);
    ctx.fillRect(cx - rx * 1.4, cy - ry * 1.4, rx * 2.8, ry * 2.8);
    overlay(ctx, tex.grit(), cx - rx, cy - ry, rx * 2, ry * 2, 'overlay', 0.8, 1.2);
    overlay(ctx, tex.grain(), cx - rx, cy - ry, rx * 2, ry * 2, 'soft-light', 0.5, 1.5, true);
    // chipped, darker crevices
    ctx.strokeStyle = 'rgba(70,42,20,.45)'; ctx.lineWidth = 0.55;
    for (let i = 0; i < 3; i++) {
      const a = rnd() * Math.PI * 2;
      ctx.beginPath(); ctx.moveTo(cx + Math.cos(a) * rx * 0.95, cy + Math.sin(a) * ry * 0.95);
      ctx.quadraticCurveTo(cx + Math.cos(a + 0.3) * rx * 0.6, cy + Math.sin(a + 0.3) * ry * 0.6, cx + Math.cos(a + 0.2) * rx * rr(0.2, 0.5), cy + Math.sin(a + 0.2) * ry * rr(0.2, 0.5));
      ctx.stroke();
    }
    ctx.lineWidth = rx * 0.22;
    ctx.strokeStyle = 'rgba(80,50,26,.45)';
    blob(ctx, cx, cy, rx, ry, 0.14, s); ctx.stroke();
    ctx.restore();
  }

  /** Light planed timber: frames, boards, posts. */
  function paintBoard(ctx, x, y, w, h, base = '#d9bf95', vertical = false, sh = true) {
    ctx.save();
    wobblyRect(ctx, x, y, w, h, Math.min(w, h) * 0.14, 0.45);
    if (sh) withShadow(ctx, 2.6, 0.9, 1.8, 'rgba(25,12,4,.5)', () => { ctx.fillStyle = base; ctx.fill(); });
    else { ctx.fillStyle = base; ctx.fill(); }
    ctx.clip();
    ctx.fillStyle = vertical
      ? lin(ctx, x, 0, x + w, 0, [[0, shade(base, 0.14)], [0.45, base], [1, shade(base, -0.22)]])
      : lin(ctx, 0, y, 0, y + h, [[0, shade(base, 0.16)], [0.5, base], [1, shade(base, -0.22)]]);
    ctx.fillRect(x, y, w, h);
    overlay(ctx, tex.grain(), x, y, w, h, 'overlay', 0.85, 1.3, vertical);
    overlay(ctx, tex.grit(), x, y, w, h, 'soft-light', 0.4, 2);
    ctx.strokeStyle = 'rgba(255,248,230,.45)'; ctx.lineWidth = 0.6;
    ctx.beginPath(); ctx.moveTo(x + 0.6, y + h - 0.6); ctx.lineTo(x + 0.6, y + 0.6); ctx.lineTo(x + w - 0.6, y + 0.6); ctx.stroke();
    ctx.strokeStyle = 'rgba(50,28,12,.4)';
    ctx.beginPath(); ctx.moveTo(x + w - 0.5, y + 0.6); ctx.lineTo(x + w - 0.5, y + h - 0.5); ctx.lineTo(x + 0.6, y + h - 0.5); ctx.stroke();
    ctx.restore();
  }

  /** A painted board (shop fronts, shutters frames): flat colour with brushed texture. */
  function paintPainted(ctx, x, y, w, h, base, vertical = false, sh = true) {
    ctx.save();
    ctx.beginPath(); ctx.rect(x, y, w, h);
    if (sh) withShadow(ctx, 2.2, 0.8, 1.6, 'rgba(20,12,6,.45)', () => { ctx.fillStyle = base; ctx.fill(); });
    ctx.clip();
    ctx.fillStyle = vertical
      ? lin(ctx, x, 0, x + w, 0, [[0, shade(base, 0.12)], [0.5, base], [1, shade(base, -0.2)]])
      : lin(ctx, 0, y, 0, y + h, [[0, shade(base, 0.12)], [0.5, base], [1, shade(base, -0.2)]]);
    ctx.fillRect(x, y, w, h);
    overlay(ctx, tex.grain(), x, y, w, h, 'soft-light', 0.55, 1.6, vertical);
    overlay(ctx, tex.plaster(), x, y, w, h, 'soft-light', 0.4, 2);
    ctx.strokeStyle = 'rgba(255,255,255,.22)'; ctx.lineWidth = 0.5;
    ctx.beginPath(); ctx.moveTo(x + 0.5, y + h); ctx.lineTo(x + 0.5, y + 0.5); ctx.lineTo(x + w, y + 0.5); ctx.stroke();
    ctx.restore();
  }

  /** Glass with the sky reflected in it, and a dim interior. */
  function paintGlass(ctx, x, y, w, h, tint = 'cool') {
    ctx.save();
    ctx.beginPath(); ctx.rect(x, y, w, h); ctx.clip();
    ctx.fillStyle = tint === 'warm'
      ? lin(ctx, x, y, x, y + h, [[0, '#f6d58e'], [0.5, '#d9913f'], [1, '#8f4a1c']])
      : lin(ctx, x, y, x + w * 0.4, y + h, [[0, '#8eacc6'], [0.35, '#3f5978'], [1, '#1b283b']]);
    ctx.fillRect(x, y, w, h);
    ctx.fillStyle = tint === 'warm' ? 'rgba(255,250,230,.25)' : 'rgba(255,255,255,.24)';
    ctx.beginPath(); ctx.moveTo(x + w * 0.05, y + h); ctx.lineTo(x + w * 0.5, y); ctx.lineTo(x + w * 0.75, y); ctx.lineTo(x + w * 0.3, y + h); ctx.fill();
    ctx.fillStyle = 'rgba(255,255,255,.12)';
    ctx.beginPath(); ctx.moveTo(x + w * 0.48, y + h); ctx.lineTo(x + w * 0.88, y); ctx.lineTo(x + w, y); ctx.lineTo(x + w * 0.6, y + h); ctx.fill();
    ctx.fillStyle = lin(ctx, 0, y, 0, y + h * 0.3, [[0, 'rgba(10,15,25,.55)'], [1, 'rgba(10,15,25,0)']]);
    ctx.fillRect(x, y, w, h * 0.3);
    ctx.restore();
  }

  /** A louvred shutter. */
  function paintShutter(ctx, x, y, w, h, base) {
    paintPainted(ctx, x, y, w, h, base, true);
    ctx.save();
    ctx.beginPath(); ctx.rect(x + 1.4, y + 1.6, w - 2.8, h - 3.2); ctx.clip();
    ctx.fillStyle = shade(base, -0.35); ctx.fillRect(x, y, w, h);
    for (let sy = y + 1.6; sy < y + h - 1.6; sy += 2.3) {
      ctx.fillStyle = lin(ctx, 0, sy, 0, sy + 2.1, [[0, shade(base, 0.25)], [0.5, base], [1, shade(base, -0.3)]]);
      ctx.fillRect(x, sy, w, 2.0);
    }
    ctx.restore();
  }

  /** Curtains inside a window opening. */
  function paintCurtains(ctx, x, y, w, h, base) {
    ctx.save();
    ctx.beginPath(); ctx.rect(x, y, w, h); ctx.clip();
    for (const side of [0, 1]) {
      const cw = w * 0.36, cx = side ? x + w - cw : x;
      ctx.beginPath();
      if (side) { ctx.moveTo(x + w, y); ctx.lineTo(cx, y); ctx.quadraticCurveTo(cx + cw * 0.7, y + h * 0.55, cx + cw * 0.5, y + h); ctx.lineTo(x + w, y + h); }
      else { ctx.moveTo(x, y); ctx.lineTo(cx + cw, y); ctx.quadraticCurveTo(cx + cw * 0.3, y + h * 0.55, cx + cw * 0.5, y + h); ctx.lineTo(x, y + h); }
      ctx.closePath();
      const g = ctx.createLinearGradient(cx, 0, cx + cw, 0);
      for (let i = 0; i <= 6; i++) g.addColorStop(i / 6, i % 2 ? shade(base, -0.25) : shade(base, 0.2));
      ctx.fillStyle = g; ctx.fill();
    }
    ctx.restore();
  }

  /** A window: frame, glass, glazing bars, sill. */
  function paintWindow(ctx, x, y, w, h, o = {}) {
    const frame = o.frame || '#efe7d6', bar = o.bar || frame;
    const fw = o.fw || 2.4;
    paintPainted(ctx, x - fw, y - fw, w + fw * 2, h + fw * 2, frame, false);
    paintGlass(ctx, x, y, w, h, o.tint);
    if (o.curtains) paintCurtains(ctx, x, y, w, h, o.curtains);
    ctx.fillStyle = bar;
    if (o.bars !== false) {
      ctx.fillRect(x + w / 2 - 0.8, y, 1.6, h);
      ctx.fillRect(x, y + h * (o.split || 0.42) - 0.8, w, 1.6);
    }
    if (o.sill !== false) paintPainted(ctx, x - fw - 2, y + h + fw - 0.5, w + fw * 2 + 4, 2.6, shade(frame, -0.06));
  }

  /** A potted plant: pot plus a clump of shaded leaves, optional flowers. */
  function paintPlant(ctx, cx, by, s, pot = '#c06a3d', flowers = null, trough = false) {
    const pw = (trough ? 2.4 : 1) * s, ph = s * 0.75;
    for (let i = 0; i < 26; i++) {
      const a = -Math.PI / 2 + (rnd() - 0.5) * 2.6, d = rr(0.2, 1) * s * 0.9;
      const lx = cx + Math.cos(a) * d * (trough ? 1.6 : 1), ly = by - ph - s * 0.15 + Math.sin(a) * d * 0.8;
      const g = ['#3f6b25', '#5a8a2e', '#76a83a', '#2f5420'][i % 4];
      ctx.save();
      ctx.translate(lx, ly); ctx.rotate(a + Math.PI / 2 + rr(-0.4, 0.4));
      ctx.beginPath(); ctx.ellipse(0, 0, s * 0.16, s * 0.34, 0, 0, Math.PI * 2);
      ctx.fillStyle = lin(ctx, -s * 0.16, 0, s * 0.16, 0, [[0, shade(g, 0.18)], [1, shade(g, -0.25)]]);
      ctx.fill();
      ctx.restore();
    }
    if (flowers) for (let i = 0; i < (trough ? 9 : 5); i++) {
      const fx = cx + (rnd() - 0.5) * pw * 0.9, fy = by - ph - s * rr(0.3, 0.9);
      ctx.fillStyle = rad(ctx, fx - 0.4, fy - 0.4, 0, fx, fy, s * 0.17, [[0, shade(flowers, 0.4)], [1, flowers]]);
      ctx.beginPath(); ctx.arc(fx, fy, s * 0.15, 0, Math.PI * 2); ctx.fill();
    }
    ctx.save();
    poly(ctx, [[cx - pw / 2, by - ph], [cx + pw / 2, by - ph], [cx + pw * 0.4, by], [cx - pw * 0.4, by]]);
    fillIn(ctx, pot, (c) => {
      c.fillStyle = lin(c, cx - pw / 2, 0, cx + pw / 2, 0, [[0, shade(pot, 0.2)], [0.5, pot], [1, shade(pot, -0.35)]]);
      c.fillRect(cx - pw, by - ph, pw * 2, ph);
      overlay(c, tex.grit(), cx - pw / 2, by - ph, pw, ph, 'overlay', 0.6, 2);
      c.fillStyle = shade(pot, 0.15); c.fillRect(cx - pw / 2, by - ph, pw, ph * 0.18);
    }, [2, 0.6, 1, 'rgba(20,10,4,.45)']);
    ctx.restore();
  }

  /** Laundry: a cloth hanging from a line, with folds. */
  function paintCloth(ctx, x, y, w, h, base) {
    ctx.save();
    ctx.beginPath(); ctx.moveTo(x, y); ctx.lineTo(x + w, y + 0.6);
    ctx.lineTo(x + w * 0.96, y + h); ctx.quadraticCurveTo(x + w * 0.5, y + h * 1.08, x + w * 0.04, y + h * 0.96); ctx.closePath();
    withShadow(ctx, 2, 0.6, 1.4, 'rgba(20,10,4,.35)', () => { ctx.fillStyle = base; ctx.fill(); });
    ctx.clip();
    const g = ctx.createLinearGradient(x, 0, x + w, 0);
    for (let i = 0; i <= 5; i++) g.addColorStop(i / 5, i % 2 ? shade(base, -0.16) : shade(base, 0.08));
    ctx.fillStyle = g; ctx.fillRect(x, y, w, h * 1.1);
    ctx.restore();
  }

  /** Little hanging star ornament on a string. */
  function paintStar(ctx, x, y, r, col = '#d9b25a') {
    ctx.save();
    ctx.beginPath();
    for (let i = 0; i < 10; i++) {
      const a = -Math.PI / 2 + (i * Math.PI) / 5, q = i % 2 ? r * 0.45 : r;
      ctx[i ? 'lineTo' : 'moveTo'](x + Math.cos(a) * q, y + Math.sin(a) * q);
    }
    ctx.closePath();
    fillIn(ctx, col, (c) => { c.fillStyle = rad(c, x - r * 0.3, y - r * 0.3, 0, x, y, r * 1.2, [[0, shade(col, 0.45)], [1, shade(col, -0.3)]]); c.fillRect(x - r, y - r, r * 2, r * 2); },
      [1.5, 0.4, 0.8, 'rgba(0,0,0,.35)']);
    ctx.restore();
  }

  function text(ctx, s, x, y, size, color, weight = 700, family = 'Georgia, "Times New Roman", serif') {
    ctx.save();
    ctx.font = `${weight} ${size}px ${family}`;
    ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
    ctx.fillStyle = 'rgba(0,0,0,.45)'; ctx.fillText(s, x + 0.4, y + 0.6);
    ctx.fillStyle = color; ctx.fillText(s, x, y);
    ctx.restore();
  }

  // ---------------------------------------------------------------------------------------------
  // Blocks (bottom-centre anchored)
  // ---------------------------------------------------------------------------------------------
  const PAINTERS = {};

  PAINTERS.cabin = function () {
    const W = 116, H = 146;
    const { c, ctx } = canvas(W, H);
    const L = 11, R = W - 11, rows = 10, lh = (H - 4) / rows;
    for (let i = 0; i < rows; i++) paintLog(ctx, L - 5, 2 + i * lh, R - L + 10, lh + 0.9, rr(-0.09, 0.07));
    // window: light timber frame with a little pediment, white sashes
    const wx = W / 2 - 13, wy = 18, ww = 26, wh = 30;
    paintBoard(ctx, wx - 5, wy - 2, 5.5, wh + 5, '#d2b48b', true);
    paintBoard(ctx, wx + ww - 0.5, wy - 2, 5.5, wh + 5, '#d2b48b', true);
    paintBoard(ctx, wx, wy - 1, ww, wh + 2, '#c9aa80');
    paintGlass(ctx, wx + 2.6, wy + 2, ww - 5.2, wh - 4);
    ctx.fillStyle = '#f2ece0';
    ctx.fillRect(W / 2 - 0.8, wy + 2, 1.6, wh - 4);
    ctx.fillRect(wx + 2.6, wy + 2 + (wh - 4) * 0.4, ww - 5.2, 1.6);
    ctx.strokeStyle = '#f2ece0'; ctx.lineWidth = 1.3; ctx.strokeRect(wx + 3.2, wy + 2.6, ww - 6.4, wh - 5.2);
    ctx.save();
    poly(ctx, [[wx - 8, wy + 1], [W / 2, wy - 7], [wx + ww + 8, wy + 1], [wx + ww + 7, wy + 4], [wx - 7, wy + 4]]);
    fillIn(ctx, '#d8bc93', (k) => { k.fillStyle = lin(k, 0, wy - 7, 0, wy + 4, [[0, '#ead2ad'], [1, '#b8946a']]); k.fillRect(wx - 9, wy - 8, ww + 18, 13); overlay(k, tex.grain(), wx - 9, wy - 8, ww + 18, 13, 'overlay', 0.8, 1.3); },
      [2.6, 0.8, 1.8, 'rgba(25,12,4,.5)']);
    ctx.restore();
    paintBoard(ctx, wx - 9, wy + wh + 2, ww + 18, 5, '#d4b68c');
    // door: arched lintel board, posts, plank leaf
    const dx = W / 2 - 18, dw = 36, dy = 80, dh = H - dy - 1;
    ctx.save();
    ctx.beginPath(); ctx.rect(dx + 5, dy + 7, dw - 10, dh - 7); ctx.clip();
    ctx.fillStyle = lin(ctx, dx, 0, dx + dw, 0, [[0, '#7c4128'], [0.5, '#9a5636'], [1, '#62321c']]);
    ctx.fillRect(dx, dy, dw, dh);
    overlay(ctx, tex.grain(), dx + 5, dy + 7, dw - 10, dh - 7, 'overlay', 0.95, 1.1, true);
    ctx.strokeStyle = 'rgba(40,16,6,.6)'; ctx.lineWidth = 0.7;
    for (let k = 1; k < 4; k++) { const px = dx + 5 + ((dw - 10) * k) / 4; ctx.beginPath(); ctx.moveTo(px, dy + 7); ctx.lineTo(px, dy + dh); ctx.stroke(); }
    paintBoard(ctx, dx + 6, dy + dh * 0.52, dw - 12, 3.4, '#8a4c2e', false, false);
    paintBoard(ctx, dx + 6, dy + 13, dw - 12, 3.4, '#8a4c2e', false, false);
    ctx.fillStyle = lin(ctx, 0, dy + 7, 0, dy + 20, [[0, 'rgba(15,6,2,.65)'], [1, 'rgba(15,6,2,0)']]);
    ctx.fillRect(dx, dy + 7, dw, 13);
    ctx.restore();
    paintBoard(ctx, dx - 1, dy + 5, 6.5, dh - 4, '#cfb189', true);
    paintBoard(ctx, dx + dw - 5.5, dy + 5, 6.5, dh - 4, '#cfb189', true);
    ctx.save();
    ctx.beginPath(); ctx.moveTo(dx - 8, dy + 8); ctx.quadraticCurveTo(W / 2, dy - 1, dx + dw + 8, dy + 8);
    ctx.lineTo(dx + dw + 7, dy + 1); ctx.quadraticCurveTo(W / 2, dy - 8, dx - 7, dy + 1); ctx.closePath();
    fillIn(ctx, '#d6b88e', (k) => { k.fillStyle = lin(k, 0, dy - 8, 0, dy + 9, [[0, '#ecd5b1'], [1, '#a98560']]); k.fillRect(dx - 9, dy - 9, dw + 18, 19); overlay(k, tex.grain(), dx - 9, dy - 9, dw + 18, 19, 'overlay', 0.85, 1.3); },
      [3, 1, 2.2, 'rgba(25,12,4,.55)']);
    ctx.restore();
    for (let i = 0; i < rows; i++) {
      const y = 2 + i * lh + lh / 2, o = i % 2 ? 1.6 : -0.4;
      paintLogEnd(ctx, L - 2 + o, y, 9.2, lh * 0.64, -1, rr(-0.08, 0.06));
      paintLogEnd(ctx, R + 2 - o, y, 9.2, lh * 0.64, 1, rr(-0.08, 0.06));
    }
    for (let i = 0; i < 30; i++) {
      const gx = rr(4, W - 4), gy = H - rr(0, 2.5);
      ctx.strokeStyle = ['#5c7f2f', '#7a9b3a', '#46672a'][i % 3]; ctx.lineWidth = 0.9;
      ctx.beginPath(); ctx.moveTo(gx, gy + 2); ctx.quadraticCurveTo(gx + rr(-1.5, 1.5), gy - 2, gx + rr(-2, 2), gy - rr(3, 6)); ctx.stroke();
    }
    keyLight(ctx, W, H);
    return { c, w: W, h: H };
  };

  PAINTERS.yellow = function () {
    const W = 112, H = 150;
    const { c, ctx } = canvas(W, H);
    const x0 = 6, x1 = W - 4, top = 9;
    ctx.save();
    ctx.beginPath(); ctx.rect(x0, top, x1 - x0, H - top);
    fillIn(ctx, '#e3a53e', (k) => { plasterWall(k, x0, top, x1 - x0, H - top, '#e2a33c'); edgeAO(k, x0, top, x1 - x0, H - top, 0.3, 0.12); });
    ctx.restore();
    // drainpipe down the left
    ctx.save();
    ctx.beginPath(); ctx.rect(x0 + 0.5, top, 3.2, H - top - 2);
    fillIn(ctx, '#8d6a5a', (k) => { k.fillStyle = lin(k, x0, 0, x0 + 4, 0, [[0, '#b39383'], [0.5, '#8a6656'], [1, '#4f3a32']]); k.fillRect(x0, top, 5, H); },
      [1.8, 1, 0.6, 'rgba(30,15,5,.45)']);
    for (let y = top + 20; y < H - 8; y += 34) paintPainted(ctx, x0, y, 4.2, 1.6, '#6a4d42', false, false);
    ctx.restore();
    // cornice: cream moulding, overhanging, with a soffit shadow
    ctx.save();
    ctx.beginPath(); ctx.rect(x0 + 3, top, x1 - x0 - 3, 6);
    ctx.fillStyle = lin(ctx, 0, top, 0, top + 6, [[0, 'rgba(60,30,10,.45)'], [1, 'rgba(60,30,10,0)']]); ctx.fill();
    ctx.restore();
    paintPainted(ctx, 2, top - 6, W - 2, 4.4, '#efe3cc');
    paintPainted(ctx, 0.5, top - 9, W - 0.5, 3.6, '#f6ecd9');
    paintPainted(ctx, 3.5, top - 1.8, W - 5, 2.4, '#d8c4a4');
    // upper floor: two windows with open green shutters, a washing line
    const shut = '#5f8a3a';
    for (const wx of [24, 66]) {
      paintShutter(ctx, wx - 13, 22, 8, 26, shut);
      paintShutter(ctx, wx + 13, 22, 8, 26, shut);
      paintWindow(ctx, wx - 5, 23, 18, 24, { frame: '#f1e6d2', curtains: '#d9c7b4', bars: true, split: 0.45 });
    }
    ctx.strokeStyle = 'rgba(60,40,30,.8)'; ctx.lineWidth = 0.5;
    ctx.beginPath(); ctx.moveTo(22, 52); ctx.quadraticCurveTo(52, 60, 86, 53); ctx.stroke();
    paintCloth(ctx, 40, 55.5, 12, 13, '#eef0ee');
    paintCloth(ctx, 55, 56.5, 7, 7, '#f0dce6');
    paintCloth(ctx, 64, 56, 6, 6.5, '#d7e7f2');
    // ground floor: shuttered window with a flower box, the front door with a fanlight
    paintShutter(ctx, 13, 76, 9, 28, shut);
    paintShutter(ctx, 23, 76, 9, 28, shut);
    paintPainted(ctx, 11, 75, 22, 1.8, '#efe3cc');
    paintPainted(ctx, 9, 104, 26, 3, '#a35e33');
    paintPlant(ctx, 22, 104, 6, '#a35e33', '#d2384a', true);
    // door
    const dx = 58, dw = 24, dy = 82, dh = H - dy - 4;
    ctx.save();
    ctx.beginPath(); ctx.moveTo(dx - 3, H - 3); ctx.lineTo(dx - 3, dy); ctx.quadraticCurveTo(dx + dw / 2, dy - 9, dx + dw + 3, dy); ctx.lineTo(dx + dw + 3, H - 3); ctx.closePath();
    fillIn(ctx, '#efe3cc', (k) => { k.fillStyle = lin(k, dx - 3, 0, dx + dw + 3, 0, [[0, '#fbf3e4'], [1, '#cdb898']]); k.fillRect(dx - 4, dy - 10, dw + 8, dh + 12); },
      [2.4, 1, 1.6, 'rgba(25,12,4,.45)']);
    ctx.restore();
    ctx.save();
    ctx.beginPath(); ctx.rect(dx, dy + 4, dw, dh - 3); ctx.clip();
    ctx.fillStyle = lin(ctx, dx, 0, dx + dw, 0, [[0, '#9b4428'], [0.5, '#b8583a'], [1, '#6d2c18']]); ctx.fillRect(dx, dy, dw, dh);
    overlay(ctx, tex.grain(), dx, dy, dw, dh, 'overlay', 0.9, 1.4, true);
    for (const [px, py, pw, ph] of [[3, 14, 7.5, 16], [13.5, 14, 7.5, 16], [3, 34, 7.5, 24], [13.5, 34, 7.5, 24]]) {
      ctx.strokeStyle = 'rgba(40,12,4,.55)'; ctx.lineWidth = 0.8; ctx.strokeRect(dx + px, dy + px * 0 + py, pw, ph);
      ctx.strokeStyle = 'rgba(255,200,170,.25)'; ctx.lineWidth = 0.5; ctx.strokeRect(dx + px + 0.7, dy + py + 0.7, pw - 1.4, ph - 1.4);
    }
    ctx.fillStyle = lin(ctx, 0, dy + 4, 0, dy + 14, [[0, 'rgba(15,6,2,.55)'], [1, 'rgba(15,6,2,0)']]); ctx.fillRect(dx, dy + 4, dw, 10);
    ctx.restore();
    // fanlight
    ctx.save();
    ctx.beginPath(); ctx.moveTo(dx + 1, dy + 4); ctx.quadraticCurveTo(dx + dw / 2, dy - 5, dx + dw - 1, dy + 4); ctx.closePath();
    ctx.clip(); paintGlass(ctx, dx, dy - 6, dw, 11);
    ctx.strokeStyle = '#efe3cc'; ctx.lineWidth = 0.8;
    for (let k = 1; k < 5; k++) { ctx.beginPath(); ctx.moveTo(dx + dw / 2, dy + 4); ctx.lineTo(dx + (dw * k) / 5, dy - 4); ctx.stroke(); }
    ctx.restore();
    ctx.fillStyle = rad(ctx, dx + dw - 5, dy + 38, 0, dx + dw - 5, dy + 38, 1.6, [[0, '#ffe9a0'], [1, '#a77a20']]);
    ctx.beginPath(); ctx.arc(dx + dw - 5, dy + 38, 1.4, 0, Math.PI * 2); ctx.fill();
    // house number and letterbox
    paintPainted(ctx, dx + dw + 6, dy + 2, 7, 5, '#f2ece0');
    text(ctx, '12', dx + dw + 9.5, dy + 4.7, 3.6, '#3b5a8a', 700, 'Arial, sans-serif');
    paintPainted(ctx, dx - 15, dy + 26, 9, 7, '#b9b4ab');
    ctx.fillStyle = '#3a3530'; ctx.fillRect(dx - 13.5, dy + 28.5, 6, 1);
    // step and pots at the foot
    paintPainted(ctx, dx - 5, H - 4, dw + 10, 4, '#cfc6b8');
    paintPlant(ctx, 12, H - 1, 8, '#3f6f96');
    paintPlant(ctx, 40, H - 1, 6.5, '#b0623a', null, true);
    paintPlant(ctx, 94, H - 1, 5, '#a8593a');
    keyLight(ctx, W, H);
    return { c, w: W, h: H };
  };

  PAINTERS.red = function () {
    const W = 122, H = 162;
    const { c, ctx } = canvas(W, H);
    // the crooked wall: narrow top, flared foot, a slight lean
    const wall = () => {
      ctx.beginPath();
      ctx.moveTo(25, 24); ctx.lineTo(95, 22);
      ctx.bezierCurveTo(96, 70, 98, 120, 112, H - 2);
      ctx.lineTo(12, H - 2);
      ctx.bezierCurveTo(22, 120, 24, 70, 25, 24);
      ctx.closePath();
    };
    ctx.save(); wall();
    fillIn(ctx, '#c4574a', (k) => {
      plasterWall(k, 8, 20, 108, H - 20, '#c25548');
      // pale polka dots
      for (let i = 0; i < 22; i++) {
        const px = rr(24, 98), py = rr(30, H - 10);
        k.fillStyle = `rgba(248,212,180,${rr(0.45, 0.75)})`;
        k.beginPath(); k.arc(px, py, rr(1.1, 1.9), 0, Math.PI * 2); k.fill();
      }
      k.fillStyle = lin(k, 8, 0, 116, 0, [[0, 'rgba(50,10,6,.35)'], [0.18, 'rgba(50,10,6,0)'], [0.82, 'rgba(50,10,6,0)'], [1, 'rgba(50,10,6,.4)']]);
      k.fillRect(8, 20, 108, H);
    }, [3, 1.2, 2, 'rgba(25,8,4,.45)']);
    ctx.restore();
    // window with blue curtains
    paintWindow(ctx, 47, 40, 24, 22, { frame: '#d9cdb8', curtains: '#7fa6c8', bars: false, sill: true, fw: 2.8 });
    ctx.fillStyle = rgba('#2b2a35', 0.6); ctx.fillRect(58.4, 40, 1.4, 22);
    // door: slate-teal with studs, flanked by splayed timber posts with teal bands
    const dx = 42, dw = 38, dy = 92;
    ctx.save();
    ctx.beginPath(); ctx.moveTo(dx + 2, dy); ctx.lineTo(dx + dw - 2, dy); ctx.lineTo(dx + dw + 5, H - 2); ctx.lineTo(dx - 5, H - 2); ctx.closePath();
    fillIn(ctx, '#35505a', (k) => {
      k.fillStyle = lin(k, dx, 0, dx + dw, 0, [[0, '#2a414b'], [0.5, '#46646d'], [1, '#22343c']]); k.fillRect(dx - 6, dy, dw + 12, H);
      overlay(k, tex.grain(), dx - 6, dy, dw + 12, H - dy, 'overlay', 0.8, 1.2, true);
      k.strokeStyle = 'rgba(10,20,24,.6)'; k.lineWidth = 0.7;
      for (let i = 1; i < 5; i++) { k.beginPath(); k.moveTo(dx + (dw * i) / 5, dy); k.lineTo(dx - 5 + ((dw + 10) * i) / 5, H); k.stroke(); }
      for (let r = 0; r < 4; r++) for (let q = 0; q < 4; q++) {
        const sx = dx + 7 + q * 8 + (r * 1.2 - 2) * (q - 1.5) * 0.3, sy = dy + 10 + r * 15;
        k.fillStyle = rad(k, sx - 0.3, sy - 0.3, 0, sx, sy, 1.2, [[0, '#d7e3df'], [1, '#55707a']]);
        k.beginPath(); k.arc(sx, sy, 1, 0, Math.PI * 2); k.fill();
      }
      k.fillStyle = lin(k, 0, dy, 0, dy + 12, [[0, 'rgba(5,10,12,.6)'], [1, 'rgba(5,10,12,0)']]); k.fillRect(dx - 6, dy, dw + 12, 12);
    });
    ctx.restore();
    for (const [side, px] of [[-1, dx - 3], [1, dx + dw + 3]]) {
      ctx.save();
      ctx.translate(px, dy - 4); ctx.rotate(side * 0.1);
      paintBoard(ctx, -3.5, 0, 7, H - dy + 6, '#c9a476', true);
      paintPainted(ctx, -3.8, 22, 7.6, 4, '#4f9aa0', false, false);
      paintPainted(ctx, -3.8, 46, 7.6, 4, '#4f9aa0', false, false);
      ctx.restore();
    }
    paintBoard(ctx, dx - 7, dy - 7, dw + 14, 6, '#b88d5c');
    paintBoard(ctx, 6, 112, 16, 4.5, '#b48955');
    paintBoard(ctx, 100, 104, 16, 4.5, '#b48955');
    // roof: a plank beam with a draped burlap cloth and hanging stars
    paintBoard(ctx, 4, 13, W - 8, 7, '#9a6a3e');
    ctx.save();
    ctx.beginPath(); ctx.moveTo(10, 8);
    ctx.bezierCurveTo(40, 2, 80, 4, 112, 7);
    ctx.lineTo(113, 22);
    for (let x = 113; x >= 10; x -= 10.3) ctx.quadraticCurveTo(x - 5, 28 + rr(-2, 2), x - 10.3, 21 + rr(-1.5, 1.5));
    ctx.closePath();
    fillIn(ctx, '#b89466', (k) => {
      k.fillStyle = lin(k, 0, 2, 0, 28, [[0, '#d9bb8a'], [0.6, '#b08a5a'], [1, '#7d5c38']]); k.fillRect(0, 0, W, 32);
      overlay(k, tex.grit(), 0, 0, W, 32, 'overlay', 0.9, 2.5);
      k.strokeStyle = 'rgba(80,55,30,.35)'; k.lineWidth = 0.4;
      for (let i = 0; i < 26; i++) { const yy = rr(4, 28); k.strokeStyle = `rgba(80,55,30,${rr(0.12, 0.3)})`; k.beginPath(); k.moveTo(rr(0, W * 0.5), yy); k.quadraticCurveTo(W * 0.5, yy + rr(-2, 2), rr(W * 0.5, W), yy + rr(-1.5, 1.5)); k.stroke(); }
      for (let i = 0; i < 10; i++) { const xx = rr(8, W - 8); k.strokeStyle = 'rgba(70,45,22,.3)'; k.lineWidth = 0.7; k.beginPath(); k.moveTo(xx, 6); k.quadraticCurveTo(xx + rr(-3, 3), 16, xx + rr(-2, 2), 27); k.stroke(); }
      k.lineWidth = 0.4;
    }, [3, 0.8, 2.2, 'rgba(25,10,4,.5)']);
    ctx.restore();
    for (const [x, y, r] of [[22, 13, 2.5], [96, 12, 2.4]]) paintStar(ctx, x, y, r, '#8a5c34');
    for (const [x, L, r] of [[8, 26, 3.3], [114, 20, 3]]) {
      ctx.strokeStyle = 'rgba(60,40,25,.75)'; ctx.lineWidth = 0.4;
      ctx.beginPath(); ctx.moveTo(x, 18); ctx.lineTo(x + 0.6, 18 + L); ctx.stroke();
      paintStar(ctx, x + 0.6, 18 + L + r, r, '#e0c06a');
    }
    ctx.strokeStyle = 'rgba(60,40,25,.75)'; ctx.lineWidth = 0.4;
    ctx.beginPath(); ctx.moveTo(110, 18); ctx.lineTo(108, 52); ctx.stroke();
    ctx.fillStyle = rad(ctx, 108, 54, 0, 108, 54, 2, [[0, '#7f9fd8'], [1, '#2c3f78']]); ctx.beginPath(); ctx.arc(108, 54, 1.8, 0, Math.PI * 2); ctx.fill();
    keyLight(ctx, W, H);
    return { c, w: W, h: H };
  };

  PAINTERS.hex = function () {
    const W = 146, H = 112;
    const { c, ctx } = canvas(W, H);
    const shape = (i) => poly(ctx, [[30 + i, 2 + i], [W - 30 - i, 2 + i], [W - 2 - i, H / 2], [W - 30 - i, H - 2 - i], [30 + i, H - 2 - i], [2 + i, H / 2]]);
    // outer frame: thick golden timber border
    ctx.save(); shape(0);
    fillIn(ctx, '#d39a3e', (k) => {
      k.fillStyle = lin(k, 0, 0, 0, H, [[0, '#f3c46e'], [0.5, '#d4973a'], [1, '#975e20']]); k.fillRect(0, 0, W, H);
      overlay(k, tex.grain(), 0, 0, W, H, 'overlay', 0.6, 1.1);
      overlay(k, tex.grit(), 0, 0, W, H, 'soft-light', 0.4, 2);
    }, [4, 1.2, 2.6, 'rgba(25,12,4,.5)']);
    ctx.restore();
    // inner planked face
    ctx.save(); shape(9);
    fillIn(ctx, '#d9a65a', (k) => {
      const rows = 6, ph = (H - 18) / rows;
      for (let i = 0; i < rows; i++) {
        const y = 9 + i * ph, tone = rr(-0.06, 0.06);
        k.fillStyle = lin(k, 0, y, 0, y + ph, [[0, shade('#efc27c', tone)], [0.5, shade('#dca85e', tone)], [1, shade('#b07a38', tone)]]);
        k.fillRect(0, y, W, ph);
        overlay(k, tex.grain(), 0, y, W, ph, 'overlay', 0.6, 0.9);
        overlay(k, tex.grit(), 0, y, W, ph, 'soft-light', 0.35, 2);
        k.fillStyle = 'rgba(70,36,10,.6)'; k.fillRect(0, y + ph - 0.9, W, 0.9);
        k.fillStyle = 'rgba(255,235,195,.3)'; k.fillRect(0, y + 0.2, W, 0.6);
        for (let j = 0; j < 2; j++) { const nx = rr(14, W - 14); k.fillStyle = 'rgba(70,36,10,.45)'; k.fillRect(nx, y, 0.7, ph); k.fillStyle = 'rgba(255,235,195,.25)'; k.fillRect(nx + 0.7, y, 0.4, ph); }
      }
      edgeAO(k, 0, 0, W, H, 0.5, 0.12);
    });
    ctx.restore();
    // frame bevel lines
    ctx.save(); shape(9); ctx.strokeStyle = 'rgba(70,34,8,.7)'; ctx.lineWidth = 1.1; ctx.stroke(); ctx.restore();
    ctx.save(); shape(4.5); ctx.strokeStyle = 'rgba(120,70,20,.35)'; ctx.lineWidth = 0.6; ctx.stroke(); ctx.restore();
    ctx.save(); shape(1.2); ctx.strokeStyle = 'rgba(255,236,190,.45)'; ctx.lineWidth = 0.8; ctx.stroke(); ctx.restore();
    // octagonal window with lilac curtains
    const ox = 42, oy = 38, orad = 17;
    const oct = (r) => { ctx.beginPath(); for (let i = 0; i < 8; i++) { const a = Math.PI / 8 + (i * Math.PI) / 4; ctx[i ? 'lineTo' : 'moveTo'](ox + Math.cos(a) * r, oy + Math.sin(a) * r); } ctx.closePath(); };
    ctx.save(); oct(orad + 3.5);
    fillIn(ctx, '#e8e1d6', (k) => { k.fillStyle = lin(k, ox - orad, oy - orad, ox + orad, oy + orad, [[0, '#fbf8f2'], [1, '#bfb4a6']]); k.fillRect(ox - 30, oy - 30, 60, 60); },
      [3, 1, 2, 'rgba(25,12,4,.5)']);
    ctx.restore();
    ctx.save(); oct(orad); ctx.clip();
    paintGlass(ctx, ox - orad, oy - orad, orad * 2, orad * 2);
    paintCurtains(ctx, ox - orad, oy - orad, orad * 2, orad * 2, '#b59ad1');
    paintPlant(ctx, ox, oy + orad - 2, 6, '#c0753e');
    ctx.fillStyle = '#efe9df'; ctx.fillRect(ox - 0.9, oy - orad, 1.8, orad * 2); ctx.fillRect(ox - orad, oy + 2, orad * 2, 1.6);
    ctx.restore();
    // shelf with a potted plant, top right
    paintBoard(ctx, 96, 34, 22, 3.4, '#9a6a36');
    paintPlant(ctx, 106, 34, 8, '#c0703c');
    // sign board with the house number
    paintBoard(ctx, 82, 50, 38, 9, '#e0b066');
    paintPainted(ctx, 110, 51.5, 7, 6, '#f4efe6', false, false);
    text(ctx, '12', 113.5, 54.8, 4.2, '#4b3b2b', 700, 'Arial, sans-serif');
    // blue plank door
    const dx = 88, dw = 26, dy = 61, dh = H - dy - 9;
    ctx.save();
    ctx.beginPath(); ctx.rect(dx, dy, dw, dh);
    fillIn(ctx, '#4c8db0', (k) => {
      k.fillStyle = lin(k, dx, 0, dx + dw, 0, [[0, '#5aa1c4'], [0.5, '#4a8bad'], [1, '#2f6584']]); k.fillRect(dx, dy, dw, dh);
      overlay(k, tex.grain(), dx, dy, dw, dh, 'overlay', 0.9, 1.3, true);
      overlay(k, tex.plaster(), dx, dy, dw, dh, 'soft-light', 0.6, 2);
      k.strokeStyle = 'rgba(15,40,55,.65)'; k.lineWidth = 0.7;
      for (let i = 1; i < 5; i++) { const px = dx + (dw * i) / 5; k.beginPath(); k.moveTo(px, dy); k.lineTo(px, dy + dh); k.stroke(); }
      k.fillStyle = lin(k, 0, dy, 0, dy + 9, [[0, 'rgba(5,15,20,.6)'], [1, 'rgba(5,15,20,0)']]); k.fillRect(dx, dy, dw, 9);
    }, [2.4, 0.8, 1.6, 'rgba(25,12,4,.5)']);
    ctx.restore();
    paintBoard(ctx, dx - 3, dy + dh, dw + 6, 3.5, '#d8cfbf');
    keyLight(ctx, W, H);
    return { c, w: W, h: H };
  };

  // ---------------------------------------------------------------------------------------------
  // The street (one painted backdrop, ground level at y = STREET_TOP)
  // ---------------------------------------------------------------------------------------------
  PAINTERS.street = function (side) {
    const W = 400, H = STREET_TOP + STREET_BOTTOM, G = STREET_TOP;
    const { c, ctx } = canvas(W, H);
    // sun glow and rays behind the shop (only behind the real Wine Store)
    if (!side) {
    ctx.save();
    ctx.translate(200, G - 52);
    for (let i = 0; i < 26; i++) {
      const a = Math.PI + (i / 25) * Math.PI;
      ctx.fillStyle = `rgba(255,214,120,${i % 2 ? 0.045 : 0.015})`;
      ctx.beginPath(); ctx.moveTo(0, 0); ctx.lineTo(Math.cos(a - 0.05) * 210, Math.sin(a - 0.05) * 210); ctx.lineTo(Math.cos(a + 0.05) * 210, Math.sin(a + 0.05) * 210); ctx.fill();
    }
    ctx.fillStyle = rad(ctx, 0, 0, 0, 0, 0, 180, [[0, 'rgba(255,240,190,.95)'], [0.18, 'rgba(255,214,120,.7)'], [0.45, 'rgba(255,196,110,.25)'], [1, 'rgba(255,196,110,0)']]);
    ctx.fillRect(-200, -200, 400, 220);
    ctx.restore();
    }

    // far tall building, right, with a roof garden
    building(ctx, 318, G - 255, 72, 150, '#a59fbd', { cols: 2, rows: 3, winW: 9, winH: 15, winTop: 20, gapY: 30, frame: '#e9e4ef', glass: 'cool' });
    for (let i = 0; i < 14; i++) {
      const bx = 320 + rr(0, 68), by = G - 255 - rr(-2, 12);
      ctx.fillStyle = rad(ctx, bx - 2, by - 2, 0, bx, by, 9, [[0, '#9ccf5a'], [0.6, '#5f9a35'], [1, '#3c6d24']]);
      blob(ctx, bx, by, rr(6, 9), rr(5, 7), 0.2); ctx.fill();
    }
    // distant brick block between the garage and the shop
    building(ctx, 112, G - 128, 64, 128, '#9b6a6e', { cols: 2, rows: 3, winW: 9, winH: 11, winTop: 14, gapY: 28, frame: '#d9c9c4', glass: 'cool', brick: true, dim: 0.18 });
    // left: grey garage block
    building(ctx, -6, G - 122, 124, 122, '#aaa5ba', { parapet: true, cols: 0 });
    ctx.save();
    const gx = 2, gy = G - 82, gw = 68, gh = 82;
    ctx.beginPath(); ctx.rect(gx, gy, gw, gh);
    fillIn(ctx, '#cfcbd8', (k) => {
      for (let y = gy; y < gy + gh; y += 4.2) {
        k.fillStyle = lin(k, 0, y, 0, y + 4.2, [[0, '#e8e5ee'], [0.55, '#c3bfcd'], [1, '#8d889c']]);
        k.fillRect(gx, y, gw, 4.2);
      }
      overlay(k, tex.grit(), gx, gy, gw, gh, 'soft-light', 0.5, 2);
      k.fillStyle = lin(k, 0, gy, 0, gy + 14, [[0, 'rgba(20,15,30,.5)'], [1, 'rgba(20,15,30,0)']]); k.fillRect(gx, gy, gw, 14);
    }, [3, 0, 1, 'rgba(20,15,30,.45)']);
    ctx.restore();
    paintPainted(ctx, gx - 3, gy - 5, gw + 6, 5, '#8b859c');
    paintPainted(ctx, 80, G - 104, 30, 26, '#7c6f8f');
    paintGlass(ctx, 83, G - 101, 24, 20);
    // right: mauve shop with striped awnings
    building(ctx, 282, G - 150, 124, 150, '#b7a1b6', { cols: 0, parapet: true });
    awning(ctx, 270, G - 150, 140, 22, '#f2e6d6', '#b4523e');
    for (const wx of [296, 340]) paintWindow(ctx, wx, G - 112, 30, 40, { frame: '#f3eef2', tint: 'cool', split: 0.5 });
    awning(ctx, 330, G - 72, 76, 16, '#f2e6d6', '#a84a38');
    // picket fences behind the shop
    fence(ctx, 108, G - 44, 44, 44);
    fence(ctx, 250, G - 44, 34, 44);
    // lamp post
    lamp(ctx, 74, G);

    // the Wine Store: the foundation the tower is built on (the side streets have a plain block there)
    if (!side) wineStore(ctx, 200 - 59, G - 104, 118, 104);
    else building(ctx, 200 - 62, G - 112, 124, 112, '#b9a99a', { cols: 3, rows: 2, winW: 14, winH: 18, winTop: 18, gapY: 44, frame: '#efe7dc', glass: 'cool', parapet: true });
    // street furniture
    if (!side) chalkboard(ctx, 128, G);
    crates(ctx, 270, G);
    stool(ctx, 300, G);

    // pavement: raised concrete slabs with a front face
    ctx.save();
    ctx.beginPath(); ctx.rect(-4, G - 4, W + 8, 18);
    fillIn(ctx, '#b9b6be', (k) => {
      k.fillStyle = lin(k, 0, G - 4, 0, G + 14, [[0, '#d9d6dc'], [0.25, '#c4c1c9'], [0.3, '#8f8c96'], [1, '#6e6b75']]); k.fillRect(-4, G - 4, W + 8, 18);
      overlay(k, tex.grit(), -4, G - 4, W + 8, 18, 'overlay', 0.8, 1.2);
      k.strokeStyle = 'rgba(60,58,66,.55)'; k.lineWidth = 0.8;
      for (let x = 30; x < W; x += 62) { k.beginPath(); k.moveTo(x, G - 4); k.lineTo(x - 2, G + 14); k.stroke(); }
      k.fillStyle = 'rgba(255,255,255,.35)'; k.fillRect(-4, G - 4, W + 8, 0.8);
    });
    ctx.restore();
    // soil cross-section with buried flat stones
    ctx.save();
    ctx.beginPath(); ctx.rect(-4, G + 14, W + 8, STREET_BOTTOM - 14);
    fillIn(ctx, '#4a3a30', (k) => {
      k.fillStyle = lin(k, 0, G + 14, 0, H, [[0, '#2d221d'], [0.12, '#4b3b31'], [0.6, '#3f3129'], [1, '#2a201b']]); k.fillRect(-4, G + 14, W + 8, H);
      overlay(k, tex.grit(), -4, G + 14, W + 8, STREET_BOTTOM, 'overlay', 1, 0.8);
      overlay(k, tex.grit(), -4, G + 14, W + 8, STREET_BOTTOM, 'soft-light', 0.5, 2.2);
      // cracks
      k.strokeStyle = 'rgba(20,14,10,.6)'; k.lineWidth = 0.9;
      for (let i = 0; i < 9; i++) {
        let x = rr(0, W), y = G + rr(20, 120);
        k.beginPath(); k.moveTo(x, y);
        for (let s = 0; s < 6; s++) { x += rr(-14, 14); y += rr(-5, 5); k.lineTo(x, y); }
        k.stroke();
      }
      for (const [sx, sy, rx, ry] of [[38, 34, 14, 7], [112, 70, 20, 11], [192, 40, 12, 6], [268, 82, 22, 12], [340, 40, 15, 8], [22, 104, 16, 8], [372, 110, 13, 7], [160, 118, 18, 8]]) {
        stone(k, sx, G + sy, rx, ry);
      }
      k.fillStyle = lin(k, 0, G + 14, 0, G + 26, [[0, 'rgba(0,0,0,.55)'], [1, 'rgba(0,0,0,0)']]); k.fillRect(-4, G + 14, W + 8, 12);
    });
    ctx.restore();
    return { c, w: W, h: H };
  };

  function building(ctx, x, y, w, h, base, o = {}) {
    ctx.save();
    ctx.beginPath(); ctx.rect(x, y, w, h);
    fillIn(ctx, base, (k) => {
      plasterWall(k, x, y, w, h, base);
      if (o.brick) {
        k.strokeStyle = 'rgba(60,30,30,.25)'; k.lineWidth = 0.5;
        for (let by = y; by < y + h; by += 3.6) {
          k.beginPath(); k.moveTo(x, by); k.lineTo(x + w, by); k.stroke();
          for (let bx = x + ((by / 3.6) % 2 ? 0 : 4); bx < x + w; bx += 8) { k.beginPath(); k.moveTo(bx, by); k.lineTo(bx, by + 3.6); k.stroke(); }
        }
      }
      k.fillStyle = lin(k, x, 0, x + w, 0, [[0, 'rgba(255,240,220,.12)'], [1, 'rgba(30,20,50,.25)']]); k.fillRect(x, y, w, h);
    }, [5, 2, 0, 'rgba(30,20,50,.35)']);
    ctx.restore();
    if (o.parapet) paintPainted(ctx, x - 2, y - 5, w + 4, 6, shade(base, -0.25));
    for (let r = 0; r < (o.rows || 0); r++) for (let q = 0; q < (o.cols || 0); q++) {
      const wx = x + (w - o.cols * o.winW - (o.cols - 1) * 8) / 2 + q * (o.winW + 8), wy = y + o.winTop + r * o.gapY;
      paintWindow(ctx, wx, wy, o.winW, o.winH, { frame: o.frame, tint: o.glass, fw: 1.6, bars: false });
    }
    if (o.dim) {
      ctx.save(); ctx.globalCompositeOperation = 'source-atop';
      ctx.fillStyle = `rgba(150,140,180,${o.dim})`; ctx.fillRect(x - 4, y - 6, w + 8, h + 8);
      ctx.restore();
    }
  }

  function awning(ctx, x, y, w, h, c1, c2) {
    ctx.save();
    ctx.beginPath(); ctx.moveTo(x + 4, y); ctx.lineTo(x + w, y); ctx.lineTo(x + w, y + h);
    for (let px = x + w; px > x; px -= 10) ctx.quadraticCurveTo(px - 5, y + h + 4, px - 10, y + h);
    ctx.closePath();
    fillIn(ctx, c1, (k) => {
      for (let px = x; px < x + w; px += 20) {
        k.fillStyle = c1; k.fillRect(px, y, 10, h + 5);
        k.fillStyle = c2; k.fillRect(px + 10, y, 10, h + 5);
      }
      k.fillStyle = lin(k, 0, y, 0, y + h + 4, [[0, 'rgba(255,255,255,.25)'], [0.55, 'rgba(255,255,255,0)'], [1, 'rgba(40,10,10,.35)']]); k.fillRect(x, y, w, h + 5);
      overlay(k, tex.plaster(), x, y, w, h + 5, 'soft-light', 0.6, 1.5);
    }, [4, 1, 3, 'rgba(30,15,20,.45)']);
    ctx.restore();
  }

  function fence(ctx, x, y, w, h) {
    for (let px = x; px < x + w; px += 6.2) {
      ctx.save();
      ctx.beginPath(); ctx.moveTo(px, y + h); ctx.lineTo(px, y + 3); ctx.quadraticCurveTo(px + 2.6, y - 1, px + 5.2, y + 3); ctx.lineTo(px + 5.2, y + h); ctx.closePath();
      fillIn(ctx, '#e8dcc2', (k) => { k.fillStyle = lin(k, px, 0, px + 5.2, 0, [[0, '#f4ecd9'], [1, '#bfae8e']]); k.fillRect(px, y - 2, 6, h + 2); overlay(k, tex.grain(), px, y, 6, h, 'soft-light', 0.6, 1.6, true); },
        [2, 1, 0.5, 'rgba(30,20,10,.35)']);
      ctx.restore();
    }
    paintPainted(ctx, x - 1, y + 10, w + 2, 2.6, '#d6c8aa', false, false);
    paintPainted(ctx, x - 1, y + h - 12, w + 2, 2.6, '#d6c8aa', false, false);
  }

  function lamp(ctx, x, g) {
    const col = '#b8643a';
    ctx.save();
    ctx.beginPath(); ctx.moveTo(x - 6, g); ctx.lineTo(x - 3, g - 12); ctx.lineTo(x - 1.6, g - 120); ctx.lineTo(x + 1.6, g - 120); ctx.lineTo(x + 3, g - 12); ctx.lineTo(x + 6, g); ctx.closePath();
    fillIn(ctx, col, (k) => { k.fillStyle = lin(k, x - 6, 0, x + 6, 0, [[0, '#e0915f'], [0.4, col], [1, '#5f2c14']]); k.fillRect(x - 7, g - 125, 14, 126); },
      [4, 2, 1, 'rgba(20,10,4,.45)']);
    ctx.restore();
    // lantern
    ctx.save();
    ctx.fillStyle = rad(ctx, x, g - 128, 0, x, g - 128, 20, [[0, 'rgba(255,230,160,.6)'], [1, 'rgba(255,230,160,0)']]);
    ctx.fillRect(x - 22, g - 150, 44, 44);
    poly(ctx, [[x - 6, g - 122], [x + 6, g - 122], [x + 8, g - 136], [x - 8, g - 136]]);
    fillIn(ctx, '#ffe7a2', (k) => { k.fillStyle = rad(k, x, g - 128, 0, x, g - 128, 9, [[0, '#fff8d8'], [1, '#e9a84e']]); k.fillRect(x - 9, g - 137, 18, 16); });
    ctx.strokeStyle = '#5f2c14'; ctx.lineWidth = 1; ctx.stroke();
    poly(ctx, [[x - 10, g - 136], [x + 10, g - 136], [x + 4, g - 142], [x - 4, g - 142]]);
    fillIn(ctx, col, (k) => { k.fillStyle = lin(k, 0, g - 142, 0, g - 136, [[0, '#e0915f'], [1, '#6a3218']]); k.fillRect(x - 10, g - 143, 20, 8); });
    paintPainted(ctx, x - 7, g - 123, 14, 2, '#7a3a1c', false, false);
    ctx.restore();
  }

  function wineStore(ctx, x, y, w, h) {
    const green = '#3f6b45', dark = '#2b4c31';
    // body
    ctx.save();
    ctx.beginPath(); ctx.rect(x, y + 6, w, h - 6);
    fillIn(ctx, green, (k) => {
      k.fillStyle = lin(k, 0, y, 0, y + h, [[0, '#4d7d52'], [1, '#2f5636']]); k.fillRect(x, y, w, h);
      overlay(k, tex.grain(), x, y, w, h, 'soft-light', 0.6, 1.6, true);
      overlay(k, tex.plaster(), x, y, w, h, 'soft-light', 0.4, 2);
    }, [6, 2, 2, 'rgba(20,10,4,.5)']);
    ctx.restore();
    // cornice and sign
    paintPainted(ctx, x - 5, y, w + 10, 6, '#2c4f32');
    paintPainted(ctx, x - 3, y + 6, w + 6, 3, '#567f58');
    ctx.save();
    ctx.beginPath(); ctx.rect(x + 18, y + 11, w - 36, 12);
    fillIn(ctx, '#203c25', (k) => { k.fillStyle = lin(k, 0, y + 11, 0, y + 23, [[0, '#2f5236'], [1, '#18301d']]); k.fillRect(x, y + 10, w, 14); }, [2, 0.5, 1, 'rgba(0,0,0,.5)']);
    ctx.strokeStyle = '#c9b67a'; ctx.lineWidth = 0.9; ctx.strokeRect(x + 19, y + 12, w - 38, 10);
    ctx.restore();
    text(ctx, 'Wine Store', x + w / 2, y + 17.5, 7.2, '#f1e3b0', 700);
    // pilasters
    for (const px of [x + 1, x + w - 8, x + 37, x + w - 44]) paintPainted(ctx, px, y + 26, 7, h - 26, '#386341', true);
    // shop windows with shelves of bottles
    for (const wx of [x + 8, x + w - 37]) {
      const wy = y + 30, ww = 29, wh = 44;
      paintGlass(ctx, wx, wy, ww, wh, 'warm');
      ctx.save(); ctx.beginPath(); ctx.rect(wx, wy, ww, wh); ctx.clip();
      for (let s = 0; s < 4; s++) {
        const sy = wy + 9 + s * 10;
        ctx.fillStyle = '#6b3a1c'; ctx.fillRect(wx, sy, ww, 1.4);
        for (let b = 0; b < 7; b++) {
          const bx = wx + 2 + b * 3.9 + rr(-0.4, 0.4);
          const bc = ['#5a1a22', '#2f4a22', '#7a2a1c', '#3a2a16'][(b + s) % 4];
          ctx.fillStyle = lin(ctx, bx, 0, bx + 2.4, 0, [[0, shade(bc, 0.35)], [1, shade(bc, -0.3)]]);
          ctx.fillRect(bx, sy - 5.5, 2.4, 5.5); ctx.fillRect(bx + 0.7, sy - 7.8, 1, 2.6);
        }
      }
      ctx.fillStyle = 'rgba(255,240,200,.18)';
      ctx.beginPath(); ctx.moveTo(wx, wy + wh); ctx.lineTo(wx + ww * 0.5, wy); ctx.lineTo(wx + ww * 0.7, wy); ctx.lineTo(wx + ww * 0.2, wy + wh); ctx.fill();
      ctx.restore();
      ctx.fillStyle = dark;
      ctx.fillRect(wx + ww / 3 - 0.6, wy, 1.2, wh); ctx.fillRect(wx + (2 * ww) / 3 - 0.6, wy, 1.2, wh); ctx.fillRect(wx, wy + 14, ww, 1.2);
      paintPainted(ctx, wx - 1, wy + wh, ww + 2, 2.4, '#5d8a5f');
      // lower panels with vertical boards
      ctx.save(); ctx.beginPath(); ctx.rect(wx, wy + wh + 3, ww, h - (wy - y) - wh - 4); ctx.clip();
      for (let px = wx; px < wx + ww; px += 4.2) paintPainted(ctx, px, wy + wh + 3, 4, h, '#3a6840', true, false);
      ctx.restore();
    }
    // glazed double door
    const dx = x + 45, dw = w - 90, dy = y + 30;
    paintPainted(ctx, dx - 1, dy - 2, dw + 2, h - 28, '#355d3a');
    for (const hx of [dx + 2, dx + dw / 2 + 1]) {
      paintGlass(ctx, hx, dy + 2, dw / 2 - 3, h - 40, 'warm');
      ctx.fillStyle = dark; ctx.fillRect(hx, dy + 20, dw / 2 - 3, 1.2);
    }
    ctx.fillStyle = '#d9b860'; ctx.fillRect(dx + dw / 2 - 2.4, dy + 34, 1, 6); ctx.fillRect(dx + dw / 2 + 1.4, dy + 34, 1, 6);
    // wall lanterns
    for (const lx of [x + 40.5, x + w - 40.5]) {
      ctx.fillStyle = rad(ctx, lx, y + 36, 0, lx, y + 36, 12, [[0, 'rgba(255,220,150,.55)'], [1, 'rgba(255,220,150,0)']]);
      ctx.fillRect(lx - 12, y + 24, 24, 24);
      paintPainted(ctx, lx - 2.4, y + 30, 4.8, 11, '#b8503a', true);
      ctx.fillStyle = rad(ctx, lx, y + 35, 0, lx, y + 35, 2.4, [[0, '#fff3c8'], [1, '#f2a24a']]); ctx.fillRect(lx - 1.4, y + 32, 2.8, 6);
    }
    // step
    paintPainted(ctx, x - 3, y + h - 3, w + 6, 3, '#7c8a7a');
    keyLight(ctx, 400, STREET_TOP + STREET_BOTTOM, 0.5);
  }

  function chalkboard(ctx, x, g) {
    ctx.save();
    ctx.lineWidth = 1.6; ctx.strokeStyle = '#7a4a26';
    ctx.beginPath(); ctx.moveTo(x - 9, g); ctx.lineTo(x, g - 28); ctx.lineTo(x + 9, g); ctx.stroke();
    poly(ctx, [[x - 7, g - 6], [x - 1.5, g - 27], [x + 6.5, g - 25], [x + 1.6, g - 4]]);
    fillIn(ctx, '#2b2f2c', (k) => { k.fillStyle = lin(k, x - 6, g - 26, x + 6, g - 4, [[0, '#3e4440'], [1, '#1b1e1c']]); k.fillRect(x - 8, g - 28, 16, 26); overlay(k, tex.grit(), x - 8, g - 28, 16, 26, 'soft-light', 0.6, 2); },
      [3, 2, 1.5, 'rgba(0,0,0,.5)']);
    ctx.strokeStyle = '#a9733f'; ctx.lineWidth = 1.2; ctx.stroke();
    ctx.strokeStyle = 'rgba(240,240,230,.75)'; ctx.lineWidth = 0.5;
    ctx.beginPath(); ctx.moveTo(x - 2, g - 21); ctx.lineTo(x - 2.5, g - 14); ctx.moveTo(x + 1, g - 20); ctx.lineTo(x + 0.4, g - 12); ctx.moveTo(x - 4, g - 9); ctx.lineTo(x + 2, g - 8); ctx.stroke();
    ctx.restore();
  }

  function crates(ctx, x, g) {
    box(ctx, x - 18, g - 13, 21, 13, '#3d7fb0');
    box(ctx, x + 3, g - 11, 15, 11, '#3a76a6');
    box(ctx, x - 9, g - 29, 16, 16, '#d9573a');
  }
  function box(ctx, x, y, w, h, col) {
    ctx.save();
    ctx.beginPath(); ctx.rect(x, y, w, h);
    fillIn(ctx, col, (k) => {
      k.fillStyle = lin(k, x, y, x + w, y + h, [[0, shade(col, 0.2)], [1, shade(col, -0.3)]]); k.fillRect(x, y, w, h);
      overlay(k, tex.grit(), x, y, w, h, 'soft-light', 0.6, 2);
      k.fillStyle = 'rgba(255,255,255,.25)'; k.fillRect(x, y, w, 1.2);
      k.fillStyle = 'rgba(0,0,0,.2)'; k.fillRect(x + w * 0.45, y, w * 0.1, h);
    }, [3, 1.5, 1, 'rgba(0,0,0,.45)']);
    ctx.restore();
  }
  function stool(ctx, x, g) {
    paintBoard(ctx, x - 7, g - 19, 14, 2.6, '#b5703a');
    for (const lx of [x - 6, x + 4]) paintBoard(ctx, lx, g - 17, 2, 17, '#9a5a2c', true);
    paintBoard(ctx, x - 6, g - 7, 12, 1.6, '#9a5a2c');
  }
  function stone(ctx, cx, cy, rx, ry) {
    ctx.save();
    blob(ctx, cx, cy, rx, ry, 0.12);
    fillIn(ctx, '#6e625a', (k) => {
      k.fillStyle = lin(k, 0, cy - ry, 0, cy + ry, [[0, '#a29488'], [0.35, '#7e7068'], [1, '#463c37']]); k.fillRect(cx - rx * 1.3, cy - ry * 1.3, rx * 2.6, ry * 2.6);
      overlay(k, tex.grit(), cx - rx, cy - ry, rx * 2, ry * 2, 'overlay', 0.9, 1.4);
    }, [4, 1, 2.5, 'rgba(0,0,0,.6)']);
    ctx.restore();
  }

  // ---------------------------------------------------------------------------------------------
  // Props
  // ---------------------------------------------------------------------------------------------
  PAINTERS.hook = function () {
    const W = 30, H = 46;
    const { c, ctx } = canvas(W, H);
    // striped block
    ctx.save();
    poly(ctx, [[3, 2], [27, 2], [24, 22], [6, 22]]);
    fillIn(ctx, '#f2c21e', (k) => {
      k.save(); k.translate(15, 12); k.rotate(-0.6);
      for (let i = -6; i < 6; i++) { k.fillStyle = i % 2 ? '#f6c624' : '#26211d'; k.fillRect(i * 5, -30, 5, 60); }
      k.restore();
      k.fillStyle = lin(k, 3, 0, 27, 0, [[0, 'rgba(255,255,255,.3)'], [0.5, 'rgba(255,255,255,0)'], [1, 'rgba(0,0,0,.4)']]); k.fillRect(0, 0, W, 24);
      overlay(k, tex.grit(), 0, 0, W, 24, 'soft-light', 0.6, 2);
    }, [2, 0.6, 1.4, 'rgba(0,0,0,.45)']);
    ctx.restore();
    for (const bx of [7, 23]) { ctx.fillStyle = rad(ctx, bx - 0.4, 6 - 0.4, 0, bx, 6, 1.6, [[0, '#f3f3f3'], [1, '#666']]); ctx.beginPath(); ctx.arc(bx, 6, 1.3, 0, Math.PI * 2); ctx.fill(); }
    // steel hook
    ctx.save();
    ctx.lineCap = 'round';
    const hookPath = () => { ctx.beginPath(); ctx.moveTo(15, 22); ctx.lineTo(15, 30); ctx.bezierCurveTo(15, 42, 4, 42, 4.5, 34); };
    hookPath(); ctx.strokeStyle = '#3d4248'; ctx.lineWidth = 5; ctx.stroke();
    hookPath(); ctx.strokeStyle = '#9aa2ad'; ctx.lineWidth = 3.2; ctx.stroke();
    ctx.translate(-0.7, -0.7); hookPath(); ctx.strokeStyle = 'rgba(255,255,255,.75)'; ctx.lineWidth = 0.9; ctx.stroke();
    ctx.restore();
    return { c, w: W, h: H };
  };

  /** Cartoon smoke, as in the original: soft white billows with grey shade and a swirl. */
  function puff(seed) {
    const W = 90, H = 70;
    const { c, ctx } = canvas(W, H);
    const parts = [[45, 40, 22], [26, 44, 15], [64, 45, 16], [36, 26, 15], [57, 28, 14], [16, 52, 9], [76, 54, 9]];
    rs = 98765 + seed * 7919;
    for (const [x, y, r] of parts) {
      ctx.save();
      blob(ctx, x + rr(-2, 2), y + rr(-2, 2), r, r * 0.9, 0.1);
      ctx.fillStyle = rad(ctx, x - r * 0.35, y - r * 0.4, r * 0.1, x + r * 0.2, y + r * 0.25, r * 1.1, [[0, '#ffffff'], [0.55, '#eef1f5'], [0.85, '#bcc3cf'], [1, '#98a0ae']]);
      ctx.fill();
      ctx.clip();
      overlay(ctx, tex.plaster(), x - r, y - r, r * 2, r * 2, 'soft-light', 0.45, 1.5);
      ctx.restore();
    }
    return { c, w: W, h: H };
  }
  PAINTERS.puff1 = () => puff(1);
  PAINTERS.puff2 = () => puff(2);
  PAINTERS.puff3 = () => puff(3);

  PAINTERS.coin = function () {
    const W = 20, H = 20;
    const { c, ctx } = canvas(W, H);
    ctx.beginPath(); ctx.arc(10, 10, 9, 0, Math.PI * 2);
    fillIn(ctx, '#e9b21f', (k) => {
      k.fillStyle = rad(k, 7, 6, 0, 10, 10, 10, [[0, '#fff1a6'], [0.5, '#f1c232'], [1, '#a46b08']]); k.fillRect(0, 0, W, H);
      k.beginPath(); k.arc(10, 10, 6, 0, Math.PI * 2); k.strokeStyle = 'rgba(120,70,0,.55)'; k.lineWidth = 1; k.stroke();
      k.beginPath(); k.arc(10.4, 10.4, 6, 0, Math.PI * 2); k.strokeStyle = 'rgba(255,240,170,.6)'; k.lineWidth = 0.6; k.stroke();
    });
    text(ctx, '₹', 10, 10.4, 8, '#b07a08', 800, 'Arial, sans-serif');
    return { c, w: W, h: H };
  };

  PAINTERS.tool_hammer = function () {
    const { c, ctx } = canvas(40, 40);
    ctx.translate(20, 20); ctx.rotate(-0.5);
    paintBoard(ctx, -2.5, -6, 5, 24, '#b47038', true);
    ctx.beginPath(); ctx.rect(-10, -12, 20, 7);
    fillIn(ctx, '#8d96a3', (k) => { k.fillStyle = lin(k, 0, -12, 0, -5, [[0, '#e8edf3'], [0.5, '#8d96a3'], [1, '#4a525e']]); k.fillRect(-11, -13, 22, 9); }, [2, 1, 1, 'rgba(0,0,0,.5)']);
    return { c, w: 40, h: 40 };
  };
  PAINTERS.tool_wrench = function () {
    const { c, ctx } = canvas(40, 40);
    ctx.translate(20, 20); ctx.rotate(0.7);
    ctx.beginPath(); ctx.rect(-2, -8, 4, 22);
    ctx.arc(0, -11, 6, Math.PI * 0.65, Math.PI * 2.35);
    fillIn(ctx, '#aab2bd', (k) => { k.fillStyle = lin(k, -6, 0, 6, 0, [[0, '#f2f5f8'], [0.5, '#a3abb6'], [1, '#535a66']]); k.fillRect(-8, -18, 16, 34); }, [2, 1, 1, 'rgba(0,0,0,.5)']);
    ctx.globalCompositeOperation = 'destination-out'; ctx.fillRect(-2, -18, 4, 6);
    return { c, w: 40, h: 40 };
  };
  PAINTERS.tool_plank = function () {
    const { c, ctx } = canvas(60, 30);
    ctx.translate(30, 15); ctx.rotate(-0.25);
    paintBoard(ctx, -25, -5, 50, 10, '#c98a4c');
    for (const nx of [-20, 20]) { ctx.fillStyle = '#5a5d63'; ctx.beginPath(); ctx.arc(nx, 0, 1.1, 0, Math.PI * 2); ctx.fill(); }
    return { c, w: 60, h: 30 };
  };
  PAINTERS.tool_brick = function () {
    const { c, ctx } = canvas(36, 26);
    ctx.translate(18, 13); ctx.rotate(0.3);
    ctx.beginPath(); ctx.rect(-12, -6, 24, 12);
    fillIn(ctx, '#b85236', (k) => { k.fillStyle = lin(k, 0, -6, 0, 6, [[0, '#d9714f'], [1, '#7c2f1c']]); k.fillRect(-13, -7, 26, 14); overlay(k, tex.grit(), -12, -6, 24, 12, 'overlay', 0.9, 1.5); }, [2, 1, 1, 'rgba(0,0,0,.5)']);
    return { c, w: 36, h: 26 };
  };
  PAINTERS.tool_gear = function () {
    const { c, ctx } = canvas(36, 36);
    ctx.translate(18, 18);
    ctx.beginPath();
    for (let i = 0; i < 16; i++) { const a = (i / 16) * Math.PI * 2, r = i % 2 ? 11 : 14; ctx.lineTo(Math.cos(a) * r, Math.sin(a) * r); }
    ctx.closePath();
    fillIn(ctx, '#9aa3b0', (k) => { k.fillStyle = rad(k, -4, -4, 0, 0, 0, 15, [[0, '#eef2f6'], [1, '#5a6270']]); k.fillRect(-15, -15, 30, 30); }, [2, 1, 1, 'rgba(0,0,0,.5)']);
    ctx.globalCompositeOperation = 'destination-out'; ctx.beginPath(); ctx.arc(0, 0, 4, 0, Math.PI * 2); ctx.fill();
    return { c, w: 36, h: 36 };
  };

  /** The street either side of the tower on a wide screen: the same street, without the Wine Store. */
  PAINTERS.streetSide = function () { return PAINTERS.street(true); };

  // ---------------------------------------------------------------------------------------------
  const cache = {};
  window.TowerArt = {
    STREET_TOP, STREET_BOTTOM,
    get(name) {
      if (!cache[name]) {
        rs = 1234567 + name.length * 9973 + name.charCodeAt(0) * 31;   // same painting every load
        const r = PAINTERS[name]();
        cache[name] = { url: r.c.toDataURL('image/png'), w: r.w, h: r.h };
      }
      return cache[name];
    },
    names: () => Object.keys(PAINTERS),
  };
})();
