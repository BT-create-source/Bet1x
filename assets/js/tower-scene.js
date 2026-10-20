/**
 * Tower Rush scene — the crane, the street and the tower, animated. All artwork is painted by
 * assets/js/tower-art.js (textured, lit, semi-realistic, like the original) and placed here as images.
 *
 *   const scene = new TowerScene(svgElement);
 *   scene.restore(count)          rebuild a tower already in progress (page reload)
 *   await scene.drop({ collapse, factor })  drop the hanging block; resolves when it has settled /
 *                                 fallen, with { perfect } (a dead-centre landing)
 *   await scene.clear()           knock the tower down and bring the camera back to the street
 *   scene.burst()                 coins fountain from the top of the tower (cash-out)
 *
 * Coordinates: the SVG's viewBox is 400 units wide and as tall as the box's aspect demands. The
 * world (street and tower) scrolls under a sky that deepens as the tower climbs and a fixed crane.
 *
 * PHYSICS. The block hangs from the hook as a damped pendulum driven by the crane trolley, so the
 * load lags, overshoots and springs back like a real one, and the hook bobs on its stretchy cable
 * (fixed 240 Hz steps). It is let go and falls ballistically with the speed and spin it had: it lands
 * where it lands, never steered. The server has already decided the floor, so the release is timed
 * to the first moment of the swing whose natural fall lands as that result deserves: the bigger the
 * multiplier the closer to dead centre (x2 and up: a perfect landing); a collapse lands with its
 * centre past the edge, tips over the corner of the floor below and falls away.
 */
(function () {
  'use strict';
  const NS = 'http://www.w3.org/2000/svg';
  const W = 400;                         // viewBox width
  const STREET = 26;                     // distance from the bottom of the view to street level, at rest (just the kerb, as in the original)
  const BASE_H = 104;                    // the Wine Store the tower stands on
  const BASE_W = 118;                    // ...and its width (tower-art.js)
  const HOOK = { x: 200, y: 41 };        // the hook's tip, where the slings meet (crane space)
  const SLING = 26;                      // hook tip to the block's top edge
  const SKIN_IDS = ['cabin', 'yellow', 'red', 'hex'];
  const art = (n) => window.TowerArt.get(n);

  // the trolley's travel and the pendulum hanging from it
  const TROLLEY_A = 70, TROLLEY_P = 2.2; // the trolley's travel (units) and period (s): an unhurried swing
  const W0SQ = 30;                       // pendulum stiffness g/L (rad/s^2): ~1.15 s natural period - a soft, elastic load that lags and overshoots, still clear of the trolley's period
  const DAMP = 0.35;                     // swing damping (1/s): low, so a jolt keeps the load wobbling for a few seconds
  const GRAV = 3000;                     // free-fall gravity (units/s^2)
  const STEP = 1 / 240;                  // physics step (s)
  // the cable stretches under the load: the hook bobs on it like a spring
  const BOB_K = 60, BOB_C = 2.6;         // stiffness (1/s^2) and damping (1/s): ~0.8 s bounce
  const BOB_SAG = 9;                     // how far a block's weight stretches the cable (units)
  const BOB_DRIVE = 230, BOB_P = 1.7;    // the winch's slow breathing (units/s^2, s): ~5 units up and down

  // sky colours at street level and high above it (the original deepens to blue as you climb)
  const SKY_LOW = [[0, [111, 183, 201]], [0.7, [146, 205, 214]], [1, [196, 232, 226]]];
  const SKY_HIGH = [[0, [62, 110, 190]], [0.7, [84, 140, 205]], [1, [108, 166, 214]]];

  const ease = { out: (t) => 1 - Math.pow(1 - t, 3), inOut: (t) => 0.5 - 0.5 * Math.cos(Math.PI * Math.min(1, Math.max(0, t))), in: (t) => t * t };
  const svgEl = (name, attrs = {}, html = '') => {
    const e = document.createElementNS(NS, name);
    for (const k in attrs) e.setAttribute(k, attrs[k]);
    if (html) e.innerHTML = html;
    return e;
  };
  /** An <image> of a painted asset, anchored at its bottom centre. */
  const imgB = (n) => { const a = art(n); return `<image href="${a.url}" x="${-a.w / 2}" y="${-a.h}" width="${a.w}" height="${a.h}"/>`; };
  /** An <image> of a painted asset, centred on the origin. */
  const imgC = (n, s = 1) => { const a = art(n); return `<image href="${a.url}" x="${(-a.w * s) / 2}" y="${(-a.h * s) / 2}" width="${a.w * s}" height="${a.h * s}"/>`; };

  class TowerScene {
    constructor(svg) {
      this.svg = svg;
      this.H = 700;
      this.floors = [];           // [{ el, skin, x, top, h }]
      this.cam = 0; this.camTarget = 0;
      this.towerX = W / 2;
      this.anims = [];
      this.simT = 0; this.acc = 0; this.lastNow = performance.now();
      this.pend = { th: 0, om: 0, by: 0, bv: 0 };   // hanging block: swing angle (rad, + = right) and rate; hook bob (units, + = down)
      this.sway = { a: 0, v: 0 };             // the tower's sway after an impact (degrees, deg/s)
      this.craneX = W / 2;                    // the trolley sways about this point, which follows the tower top
      this.pending = null;                    // a drop waiting for its release moment
      this.skyKey = '';
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
      s.innerHTML = `<defs><linearGradient id="trSky" x1="0" y1="0" x2="0" y2="1">
          <stop offset="0"/><stop offset="0.7"/><stop offset="1"/></linearGradient></defs>`;
      this.skyStops = s.querySelectorAll('#trSky stop');
      this.sky = svgEl('rect', { x: 0, y: 0, width: W, height: 2000, fill: 'url(#trSky)' });
      s.appendChild(this.sky);
      this.world = svgEl('g');
      const st = art('street');
      // On a wide screen the view is wider than the 400-unit street, so the street continues either
      // side: copies of it, every other one mirrored so the repeat is not obvious.
      for (let k = -3; k <= 3; k++) {
        if (!k) continue;
        const x = k * st.w, flip = k % 2 !== 0, side = art('streetSide');
        this.world.appendChild(svgEl('image', { href: side.url, x: flip ? -st.w : 0, y: -window.TowerArt.STREET_TOP, width: st.w, height: st.h,
          transform: flip ? `translate(${x} 0) scale(-1 1)` : `translate(${x} 0)` }));
      }
      this.world.appendChild(svgEl('image', { href: st.url, x: 0, y: -window.TowerArt.STREET_TOP, width: st.w, height: st.h }));
      this.towerLayer = svgEl('g');
      this.world.appendChild(this.towerLayer);
      s.appendChild(this.world);
      this.fxLayer = svgEl('g');
      s.appendChild(this.fxLayer);
      // the crane: cable and hook ride the trolley; slings and block swing about the hook tip
      const hk = art('hook');
      this.craneG = svgEl('g', {}, `<path d="M205 -400 V2" stroke="#3a3a40" stroke-width="2.4"/><path d="M204.3 -400 V2" stroke="#8a8f99" stroke-width="0.8"/>
        <image href="${hk.url}" x="${HOOK.x - 10}" y="-2" width="${hk.w}" height="${hk.h}"/>`);
      this.hang = svgEl('g');
      this.craneG.appendChild(this.hang);
      s.appendChild(this.craneG);
      this.setHanging(this.pickSkin());
    }

    setHanging(skin) {
      this.hangSkin = skin;
      const k = art(skin), half = k.w * 0.34;
      this.hang.innerHTML = `<path d="M0 0 L${-half} ${SLING} M0 0 L${half} ${SLING}" fill="none" stroke="#2e2a28" stroke-width="1.3" stroke-linecap="round"/>`
        + `<g class="blk" transform="translate(0 ${SLING + k.h})">${imgB(skin)}</g>`;
      this.hangArrive = performance.now();
      this.pend.th = 0; this.pend.om = (Math.random() - 0.5) * 0.8;   // a little jolt as it is hooked on
      this.pend.bv += 110;                                             // and its weight pulls the cable down
    }

    resize() {
      const r = this.svg.getBoundingClientRect();
      if (!r.width) return;
      // The scene is designed 400 units wide. A tall box shows it exactly that wide; a wide box keeps at
      // least MIN_VIEW_H units of height and shows more street either side, centred on the tower.
      const MIN_VIEW_H = 600;
      const aspect = r.height / r.width;
      let vw = W, h = Math.round(W * aspect);
      if (h < MIN_VIEW_H) { h = MIN_VIEW_H; vw = Math.round(MIN_VIEW_H / aspect); }
      this.H = h;
      const x0 = -Math.round((vw - W) / 2);
      this.svg.setAttribute('viewBox', `${x0} 0 ${vw} ${this.H}`);
      this.sky.setAttribute('x', x0); this.sky.setAttribute('width', vw);
      this.sky.setAttribute('height', this.H);
      if (this.floors) { this.aimCamera(); if (!this.floors.length) this.cam = this.camTarget; }
    }

    get ground() { return this.H - STREET; }                 // street level on screen at cam = 0
    towerTop() { return this.floors.length ? this.floors[this.floors.length - 1].top : -BASE_H; }   // world y

    // ---- the crane and the pendulum ------------------------------------------------------------
    trolley(t) {
      const w = 2 * Math.PI / TROLLEY_P;
      const A = TROLLEY_A;
      return { x: this.craneX + A * Math.sin(w * t), v: A * w * Math.cos(w * t), a: -A * w * w * Math.sin(w * t) };
    }
    /** One physics step of the pendulum: driven by the trolley's acceleration, damped. */
    stepPend(p, t) {
      const L = SLING + art(this.hangSkin).h * 0.55;
      const acc = -W0SQ * Math.sin(p.th) - (this.trolley(t).a / L) * Math.cos(p.th) - DAMP * p.om;
      p.om += acc * STEP; p.th += p.om * STEP;
      // the hook on its stretchy cable: sags under the load, breathes with the winch, and the swing's
      // pull (strongest at the bottom of each swing) tugs it down twice per swing
      const rest = this.hidden ? 0 : BOB_SAG;
      const bacc = -BOB_K * (p.by - rest) - BOB_C * p.bv + BOB_DRIVE * Math.sin(2 * Math.PI * t / BOB_P)
        + (this.hidden ? 0 : 0.8 * L * p.om * p.om);
      p.bv += bacc * STEP; p.by += p.bv * STEP;
    }
    /** The block's bottom centre on screen and its velocity, for pendulum state p at time t. */
    blockKin(p, t) {
      const Lb = SLING + art(this.hangSkin).h;
      const tr = this.trolley(t);
      return {
        x: tr.x + Lb * Math.sin(p.th), y: HOOK.y + p.by + Lb * Math.cos(p.th),
        vx: tr.v + Lb * Math.cos(p.th) * p.om, vy: p.bv - Lb * Math.sin(p.th) * p.om,
      };
    }
    /** The surface the next block lands on: its centre x and width (the Wine Store for the first). */
    below() {
      const f = this.floors[this.floors.length - 1];
      return f ? { x: f.x, w: art(f.skin).w } : { x: W / 2, w: BASE_W };
    }
    /** Where a block released with kinematics b comes down on a surface at screen height landY. */
    landingX(b, landY) {
      const d = landY - b.y;
      const tf = (-b.vy + Math.sqrt(Math.max(0, b.vy * b.vy + 2 * GRAV * d))) / GRAV;
      return b.x + b.vx * tf;
    }

    paintSky() {
      const k = Math.max(0, Math.min(1, this.cam / 1400));
      const key = k.toFixed(3);
      if (key === this.skyKey) return;
      this.skyKey = key;
      SKY_LOW.forEach(([, lo], i) => {
        const hi = SKY_HIGH[i][1];
        const c = lo.map((v, j) => Math.round(v + (hi[j] - v) * k));
        this.skyStops[i].setAttribute('stop-color', `rgb(${c[0]},${c[1]},${c[2]})`);
      });
    }

    loop(now) {
      this.cam += (this.camTarget - this.cam) * 0.08;
      const gy = this.ground + this.cam;
      this.world.setAttribute('transform', `translate(0 ${gy.toFixed(2)})`);
      this.paintSky();

      // physics at fixed steps: the pendulum, the tower's sway, and a pending release
      this.acc += Math.min(0.1, (now - this.lastNow) / 1000); this.lastNow = now;
      while (this.acc >= STEP) {
        this.acc -= STEP;
        this.stepPend(this.pend, this.simT);
        this.simT += STEP;
        const sw = this.sway;
        sw.v += (-70 * sw.a - 5 * sw.v) * STEP; sw.a += sw.v * STEP;
        // the crane eases over the top of the tower (a little toward the middle, so the tower never wanders off)
        this.craneX += (this.towerX * 0.5 + (W / 2) * 0.5 - this.craneX) * Math.min(1, STEP * 1.5);
        if (this.pending && this.simT >= this.pending.at) { const pd = this.pending; this.pending = null; this.release(pd); }
      }
      const lift = (1 - ease.out(Math.min(1, (now - this.hangArrive) / 500))) * 140;
      const tr = this.trolley(this.simT);
      this.craneG.setAttribute('transform', `translate(${(tr.x - W / 2).toFixed(2)} ${(this.pend.by - lift).toFixed(2)})`);
      this.hang.setAttribute('transform', `translate(${HOOK.x} ${HOOK.y}) rotate(${(-this.pend.th * 180 / Math.PI).toFixed(2)})`);
      this.hang.style.display = this.hidden ? 'none' : '';
      this.towerLayer.setAttribute('transform', `rotate(${this.sway.a.toFixed(3)} ${W / 2} ${-BASE_H})`);

      // Animations may start new ones while they run (a landing starts the puffs and the next block):
      // those land in a fresh list and are kept, not lost when this frame's list is rebuilt.
      const running = this.anims;
      this.anims = [];
      const kept = running.filter((an) => {
        const t = Math.min(1, (now - an.start) / an.dur);
        if (an.step(t, now) === true) return false;            // a physics animation that has finished
        if (t >= 1) { if (an.done) an.done(); return false; }
        return true;
      });
      this.anims = kept.concat(this.anims);
      requestAnimationFrame(this.loop);
    }

    anim(dur, step, done) {
      return new Promise((resolve) => {
        this.anims.push({ start: performance.now(), dur, step, done: () => { if (done) done(); resolve(); } });
      });
    }
    /** A frame-by-frame physics animation: step(dt) returns true when it is over. */
    physics(step) {
      let last = performance.now();
      this.anims.push({ start: last, dur: 1e9, step: (_, now) => { const dt = Math.min(0.05, (now - last) / 1000); last = now; return step(dt); } });
    }

    aimCamera() {
      // keep the top of the tower a clear gap below the lowest the hanging block reaches (sagging and
      // bobbing on its cable), with the top floor still in view; never lift the street off the bottom
      const hangBottom = HOOK.y + BOB_SAG + 14 + SLING + art(this.hangSkin).h;
      const gap = Math.max(60, Math.min(170, this.H * 0.24));     // ~a quarter of the view, as in the original
      const want = Math.min(this.H - 80, hangBottom + gap);
      const topScreenAtRest = this.ground + this.towerTop();
      this.camTarget = Math.max(0, want - topScreenAtRest);
    }

    /** Rebuild a tower already in progress (page reload), with no animation. */
    restore(count) {
      this.towerLayer.innerHTML = '';
      this.floors = [];
      this.towerX = W / 2;
      for (let i = 0; i < count; i++) {
        const prev = this.floors[this.floors.length - 1];
        const x = this.below().x + (Math.random() - 0.5) * 14;
        this.addFloor(this.pickSkin(prev ? prev.skin : null), Math.max(W / 2 - 30, Math.min(W / 2 + 30, x)), false);
      }
      this.aimCamera();
      this.cam = this.camTarget;
    }

    /** Put a floor on the tower with its bottom centre at x, where it actually landed. */
    addFloor(skin, x, squash, tilt) {
      const k = art(skin);
      const top = this.towerTop() - k.h;
      this.towerX = x;
      // a soft contact shadow where this floor sits on the one below
      const g = svgEl('g', { transform: `translate(${x.toFixed(1)} ${(top + k.h).toFixed(1)})` },
        `<ellipse cx="0" cy="0" rx="${k.w * 0.48}" ry="3.2" fill="rgba(20,10,4,.35)"/><g class="blk">${imgB(skin)}</g>`);
      this.towerLayer.appendChild(g);
      const f = { el: g, skin, x, top, h: k.h };
      this.floors.push(f);
      if (squash) {
        const inner = g.lastChild;
        const t0 = Math.max(-25, Math.min(25, tilt || 0));             // it came down at an angle: it rocks level
        this.anim(420, (t) => {
          const s = 1 - Math.sin(Math.PI * t) * 0.1 * (1 - t);
          const r = t0 * Math.exp(-6 * t) * Math.cos(9 * t);
          inner.setAttribute('transform', `rotate(${r.toFixed(2)} 0 0) scale(${(2 - s).toFixed(3)} ${s.toFixed(3)})`);
        }, () => inner.removeAttribute('transform'));
      }
      return f;
    }

    /** Smoke billowing out either side of a landing at screen point (x, y). */
    puffs(x, y, big) {
      const n = big ? 4 : 3;
      for (const dir of [-1, 1]) {
        for (let i = 0; i < n; i++) {
          const s0 = 0.32 + Math.random() * 0.14;
          const p = svgEl('g', {}, imgC('puff' + (1 + ((i + (dir > 0 ? 1 : 0)) % 3))));
          this.fxLayer.appendChild(p);
          const dx = dir * (34 + Math.random() * 34 + i * 16), dy = -4 - Math.random() * 16;
          this.anim(700 + i * 70, (t) => {
            const e = ease.out(t);
            const sc = s0 * (0.7 + 0.8 * e);
            p.setAttribute('transform', `translate(${(x + dx * e).toFixed(1)} ${(y + dy * e).toFixed(1)}) scale(${(dir * sc).toFixed(3)} ${sc.toFixed(3)})`);
            p.setAttribute('opacity', (t < 0.5 ? 1 : 1 - (t - 0.5) / 0.5).toFixed(2));
          }, () => p.remove());
        }
      }
    }

    /**
     * How far from the middle of the floor below (as a fraction of its half-width) a floor with this
     * multiplier lands: the bigger the multiplier, the closer to dead centre.
     */
    static band(factor) {
      if (factor >= 2) return [0, 0.07];          // perfect
      if (factor > 1) return [0.1, 0.22];         // x1.35, x1.6
      if (factor === 1) return [0.24, 0.36];
      if (factor >= 0.8) return [0.38, 0.5];      // x0.8, x0.85
      return [0.52, 0.66];                        // x0.45, x0.7: well off-centre, but it holds
    }

    /**
     * Drop the hanging block for a floor the server has decided. The swing is simulated ahead with the
     * same fixed steps the screen uses, to find the first moment whose free fall naturally lands where
     * that result belongs; the block is let go exactly then and is never steered afterwards.
     */
    drop({ collapse, factor }) {
      return new Promise((resolve) => {
        const k = art(this.hangSkin), bl = this.below();
        const landY = this.ground + this.camTarget + this.towerTop();
        const half = Math.min(bl.w, k.w) / 2;
        const drift = bl.x - W / 2;                                         // keep the tower near the middle
        const [b0, b1] = collapse ? [0, 0] : TowerScene.band(factor);
        const lo = bl.w * 0.5 + k.w * 0.1, hi = bl.w * 0.5 + k.w * 0.4;     // collapse: centre past the edge
        const fits = (o) => {
          if (collapse) return Math.abs(o) > lo && Math.abs(o) < hi;
          const f = Math.abs(o) / half;
          if (f < b0 || f > b1) return false;
          if (b1 > 0.1 && drift > 40 && o > 0) return false;                // drifted right: land on the left
          if (b1 > 0.1 && drift < -40 && o < 0) return false;
          return true;
        };
        const want = collapse ? (lo + hi) / 2 : half * (b0 + b1) / 2;
        const p = Object.assign({}, this.pend);
        let t = this.simT, best = this.simT, bestScore = Infinity;
        for (let i = 0; i < 240 * 5; i++) {
          const o = this.landingX(this.blockKin(p, t), landY) - bl.x;
          if (fits(o)) { best = t; break; }
          const score = Math.abs(Math.abs(o) - want);
          if (score < bestScore) { bestScore = score; best = t; }
          this.stepPend(p, t); t += STEP;
        }
        this.pending = { at: best, collapse, resolve, perfect: !collapse && b1 <= 0.07 };
      });
    }

    /** Let go: the block falls freely from where it is, with the speed and spin it had. */
    release({ collapse, resolve, perfect }) {
      const skin = this.hangSkin, k = art(skin);
      const b = this.blockKin(this.pend, this.simT);
      const bl = this.below();
      this.hidden = true;
      this.pend.bv -= 120;                                               // unloaded, the hook springs up
      if (this.onRelease) this.onRelease();
      const g = svgEl('g', {}, `<g class="blk">${imgB(skin)}</g>`);
      this.fxLayer.appendChild(g);
      let x = b.x, y = b.y, vx = b.vx, vy = b.vy;
      let rot = -this.pend.th * 180 / Math.PI, spin = -this.pend.om * 180 / Math.PI;   // degrees, deg/s
      const draw = () => g.setAttribute('transform', `translate(${x.toFixed(1)} ${y.toFixed(1)}) rotate(${rot.toFixed(2)} 0 ${(-k.h / 2).toFixed(1)})`);
      draw();
      const done = () => resolve({ perfect });
      this.physics((dt) => {
        const landY = this.ground + this.cam + this.towerTop();
        vy += GRAV * dt; x += vx * dt; y += vy * dt;
        rot += spin * dt; spin *= Math.pow(0.5, dt);
        if (y < landY) { draw(); return false; }
        y = landY;
        this.puffs(x, landY, collapse);
        if (!collapse) this.land(g, skin, x, vx, rot, bl, landY, done);
        else this.topple(g, k, x, rot, bl, landY, done);
        return true;
      });
    }

    /** A safe landing: it stays where it came down, levels out with a squash, and the tower sways. */
    land(g, skin, x, vx, rot, bl, landY, resolve) {
      g.remove();
      // A floor that HOLDS must keep more than half its base on the one below. If the block came down
      // with its base more than ~45% off the edge, pull it in to that limit — anything further off is
      // a slip (topple), never a hold, so the 50%-off rule reads cleanly.
      const k = art(skin);
      const maxHold = Math.max(0, bl.w * 0.5 - k.w * 0.05);
      const off = x - bl.x;
      if (Math.abs(off) > maxHold) x = bl.x + (off < 0 ? -1 : 1) * maxHold;
      this.addFloor(skin, x, true, rot);
      this.sway.v += ((x - bl.x) / bl.w) * 70 + vx * 0.05 + rot * 1.2;
      this.aimCamera();
      this.nextBlock().then(resolve);
    }

    /** A collapse: it landed with its centre past the edge, tips over the corner below and falls away. */
    topple(g, k, x, rot, bl, landY, resolve) {
      const side = x >= bl.x ? 1 : -1;
      // A collapse must look like a collapse: the base sits clearly past half-off the edge, then slips.
      const minSlip = bl.w * 0.5 + k.w * 0.06;
      if (Math.abs(x - bl.x) < minSlip) x = bl.x + side * minSlip;
      const edgeX = bl.x + side * bl.w / 2;
      const ex = edgeX - x;                                     // the pivot, in the block's own frame
      this.sway.v += side * 40;
      let r = rot * Math.PI / 180 * 0.5, w = side * 0.8;          // tipping angle (rad) and its rate
      let sx = 0, fy = 0, fvx = 0, fvy = 0, free = false, alpha = 1;
      this.physics((dt) => {
        if (!free) {
          // the block's weight about the edge: lever = horizontal distance from the pivot to its centre
          const lever = -ex * Math.cos(r) + (k.h / 2) * Math.sin(r);
          w += (lever / (k.h * 0.6)) * 18 * dt;
          r += w * dt;
          sx += side * 46 * Math.abs(w) * dt;                     // and slides outward as it goes
          g.setAttribute('transform', `translate(${(x + sx).toFixed(1)} ${landY.toFixed(1)}) rotate(${(r * 180 / Math.PI).toFixed(2)} ${ex.toFixed(1)} 0)`);
          if (Math.abs(r) > 1.05) { free = true; fvx = side * (70 + Math.abs(w) * k.h * 0.35); fvy = -Math.abs(w) * 25; }
          return false;
        }
        fvy += GRAV * dt; sx += fvx * dt; fy += fvy * dt; r += w * dt;
        alpha = Math.max(0, alpha - dt * 0.8);
        g.setAttribute('transform', `translate(${(x + sx).toFixed(1)} ${(landY + fy).toFixed(1)}) rotate(${(r * 180 / Math.PI).toFixed(2)} ${ex.toFixed(1)} 0)`);
        g.setAttribute('opacity', alpha.toFixed(2));
        if (fy > 700 || alpha <= 0) { g.remove(); resolve(); return true; }
        return false;
      });
    }

    /** Bring the next block down from above. */
    nextBlock() {
      this.setHanging(this.pickSkin(this.hangSkin));
      this.hidden = false;
      this.aimCamera();
      return this.anim(260, () => {});
    }

    /** After a collapse or a cash-out: the floors fall away, then a fresh block for a fresh street. */
    clear() {
      const fl = this.floors.slice();
      this.floors = [];
      this.towerX = W / 2;
      this.camTarget = 0;
      this.sway.a = 0; this.sway.v = 0;
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
      for (let i = 0; i < 20; i++) {
        const c = svgEl('g', {}, imgC('coin', 0.9 + Math.random() * 0.4));
        this.fxLayer.appendChild(c);
        const vx = (Math.random() - 0.5) * 260, vy = -220 - Math.random() * 200, spin = 2 + Math.random() * 5;
        this.anim(1300, (t) => {
          const s = t * 1.3;
          c.setAttribute('transform', `translate(${(x0 + vx * s).toFixed(1)} ${(y0 + vy * s + 420 * s * s).toFixed(1)}) scale(${Math.cos(t * spin * Math.PI).toFixed(2)} 1)`);
          c.setAttribute('opacity', (1 - t * t).toFixed(2));
        }, () => c.remove());
      }
    }

    /** Screen position of the tower top (for floating labels in the page). */
    topOnScreen() {
      return { x: this.towerX, y: this.ground + this.camTarget + this.towerTop() };
    }
  }

  window.TowerScene = TowerScene;
})();
