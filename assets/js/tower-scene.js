/**
 * Tower Rush scene — the crane, the street and the tower, animated. All artwork is painted by
 * assets/js/tower-art.js (textured, lit, semi-realistic, like the original) and placed here as images.
 *
 *   const scene = new TowerScene(svgElement);
 *   scene.restore(count)          rebuild a tower already in progress (page reload)
 *   await scene.drop({ collapse }) drop the hanging block; resolves when it has landed / fallen
 *   await scene.clear()           knock the tower down and bring the camera back to the street
 *   scene.burst()                 coins fountain from the top of the tower (cash-out)
 *
 * Coordinates: the SVG's viewBox is 400 units wide and as tall as the box's aspect demands. The
 * world (street and tower) scrolls under a sky that deepens as the tower climbs and a fixed crane.
 */
(function () {
  'use strict';
  const NS = 'http://www.w3.org/2000/svg';
  const W = 400;                         // viewBox width
  const STREET = 104;                    // distance from the bottom of the view to street level, at rest
  const BASE_H = 104;                    // the Wine Store the tower stands on
  const HOOK = { x: 200, y: 41 };        // the hook's tip, where the slings meet (crane space)
  const SKIN_IDS = ['cabin', 'yellow', 'red', 'hex'];
  const art = (n) => window.TowerArt.get(n);

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
      this.t0 = performance.now();
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
      this.world.appendChild(svgEl('image', { href: st.url, x: 0, y: -window.TowerArt.STREET_TOP, width: st.w, height: st.h }));
      this.towerLayer = svgEl('g');
      this.world.appendChild(this.towerLayer);
      s.appendChild(this.world);
      this.fxLayer = svgEl('g');
      s.appendChild(this.fxLayer);
      // the crane: cable, hook, slings and the hanging block, in screen space
      const hk = art('hook');
      this.craneG = svgEl('g', {}, `<path d="M205 -400 V2" stroke="#3a3a40" stroke-width="2.4"/><path d="M204.3 -400 V2" stroke="#8a8f99" stroke-width="0.8"/>
        <image href="${hk.url}" x="${HOOK.x - 10}" y="-2" width="${hk.w}" height="${hk.h}"/>`);
      this.ropes = svgEl('path', { fill: 'none', stroke: '#2e2a28', 'stroke-width': 1.3, 'stroke-linecap': 'round' });
      this.hang = svgEl('g');
      this.craneG.appendChild(this.ropes);
      this.craneG.appendChild(this.hang);
      s.appendChild(this.craneG);
      this.setHanging(this.pickSkin());
    }

    setHanging(skin) {
      this.hangSkin = skin;
      this.hang.innerHTML = `<g class="blk">${imgB(skin)}</g>`;
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
    towerTop() { return this.floors.length ? this.floors[this.floors.length - 1].top : -BASE_H; }   // world y

    /** Where the hanging block's bottom-centre is on screen right now, and its tilt. */
    swing(now) {
      const t = (now - this.t0) / 1000;
      const x = W / 2 + Math.sin(t * 2 * Math.PI / 2.4) * 104;
      const rot = -Math.cos(t * 2 * Math.PI / 2.4) * 8;
      return { x, rot };
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

      // the swinging hook and block
      const sw = this.swing(now);
      const arrive = ease.out(Math.min(1, (now - this.hangArrive) / 500));
      const k = art(this.hangSkin);
      const lift = (1 - arrive) * 140;
      const top = HOOK.y + 26;
      this.craneG.setAttribute('transform', `translate(${(sw.x - W / 2).toFixed(2)} ${(-lift).toFixed(2)})`);
      this.hang.setAttribute('transform', `translate(${HOOK.x} ${(top + k.h).toFixed(2)}) rotate(${sw.rot.toFixed(2)} 0 ${-k.h})`);
      // slings follow the block's tilt: from the hook tip to two points on the block's top edge
      const half = k.w * 0.34, a = (sw.rot * Math.PI) / 180;
      const lx = HOOK.x - half * Math.cos(a), ly = top - half * Math.sin(a);
      const rx = HOOK.x + half * Math.cos(a), ry = top + half * Math.sin(a);
      this.ropes.setAttribute('d', this.hidden ? '' : `M${HOOK.x} ${HOOK.y} L${lx.toFixed(1)} ${ly.toFixed(1)} M${HOOK.x} ${HOOK.y} L${rx.toFixed(1)} ${ry.toFixed(1)}`);
      this.hang.style.display = this.hidden ? 'none' : '';

      this.anims = this.anims.filter((an) => {
        const t = Math.min(1, (now - an.start) / an.dur);
        an.step(t, now);
        if (t >= 1) { if (an.done) an.done(); return false; }
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
      // keep the top of the tower around 60% down the view, never below the street's rest position
      const topScreenAtRest = this.ground + this.towerTop();
      this.camTarget = Math.max(0, this.H * 0.6 - topScreenAtRest);
    }

    /** Rebuild a tower already in progress (page reload), with no animation. */
    restore(count) {
      this.towerLayer.innerHTML = '';
      this.floors = [];
      this.towerX = W / 2;
      for (let i = 0; i < count; i++) this.addFloor(this.pickSkin(this.floors.length ? this.floors[this.floors.length - 1].skin : null), (Math.random() - 0.5) * 8, false);
      this.aimCamera();
      this.cam = this.camTarget;
    }

    addFloor(skin, dx, squash) {
      const k = art(skin);
      const top = this.towerTop() - k.h;
      this.towerX = Math.max(W / 2 - 18, Math.min(W / 2 + 18, this.towerX + dx));
      // a soft contact shadow where this floor sits on the one below
      const g = svgEl('g', { transform: `translate(${this.towerX.toFixed(1)} ${(top + k.h).toFixed(1)})` },
        `<ellipse cx="0" cy="0" rx="${k.w * 0.48}" ry="3.2" fill="rgba(20,10,4,.35)"/><g class="blk">${imgB(skin)}</g>`);
      this.towerLayer.appendChild(g);
      const f = { el: g, skin, x: this.towerX, top, h: k.h };
      this.floors.push(f);
      if (squash) {
        const inner = g.lastChild;
        this.anim(340, (t) => {
          const s = 1 - Math.sin(Math.PI * t) * 0.1 * (1 - t);
          inner.setAttribute('transform', `scale(${(2 - s).toFixed(3)} ${s.toFixed(3)})`);
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
     * Drop the hanging block. `collapse` false: it lands on the tower, squashes and puffs smoke.
     * `collapse` true: it either lands badly and topples off the side, or bursts into a cloud of
     * smoke on the way down (both happen in the original).
     */
    drop({ collapse }) {
      const now = performance.now();
      const sw = this.swing(now);
      const skin = this.hangSkin;
      const k = art(skin);
      const startX = sw.x, startRot = sw.rot;
      const startY = HOOK.y + 26 + k.h;
      const gy = this.ground + this.cam;
      const landY = gy + this.towerTop();
      const landDx = collapse ? 0 : (Math.random() - 0.5) * 12;
      const landX = this.towerX + landDx;
      const variant = collapse ? (Math.random() < 0.5 ? 'topple' : 'poof') : 'land';
      const side = collapse ? (startX < this.towerX ? -1 : 1) : 0;
      const missX = this.towerX + side * k.w * 0.6;

      this.hidden = true;
      const g = svgEl('g', {}, `<g class="blk">${imgB(skin)}</g>`);
      this.fxLayer.appendChild(g);
      const fallDur = Math.max(380, Math.min(800, (landY - startY) * 1.4));
      const endY = variant === 'poof' ? startY + (landY - startY) * 0.6 : landY;
      const endX = variant === 'land' ? landX : (variant === 'topple' ? missX : startX + (this.towerX - startX) * 0.3);

      return this.anim(fallDur, (t) => {
        const x = startX + (endX - startX) * ease.inOut(t);
        const y = startY + (endY - startY) * ease.in(t);
        g.setAttribute('transform', `translate(${x.toFixed(1)} ${y.toFixed(1)}) rotate(${(startRot * (1 - t)).toFixed(2)} 0 ${-k.h / 2})`);
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
        // topple: pivot over the edge of the tower, then fall away
        this.puffs(this.towerX + side * k.w * 0.35, landY, true);
        const pivotX = -side * k.w * 0.5;
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

    /** A burst of smoke that swallows the block (x0 in mid-air). */
    poof(x, y) {
      for (let i = 0; i < 8; i++) {
        const a = (i / 8) * Math.PI * 2 + Math.random() * 0.4, d = 14 + Math.random() * 18;
        const s0 = 0.55 + Math.random() * 0.3, flip = Math.random() < 0.5 ? -1 : 1;
        const p = svgEl('g', {}, imgC('puff' + (1 + (i % 3))));
        this.fxLayer.appendChild(p);
        this.anim(900, (t) => {
          const e = ease.out(t);
          const sc = s0 * (0.55 + 0.7 * e);
          p.setAttribute('transform', `translate(${(x + Math.cos(a) * d * (0.3 + e)).toFixed(1)} ${(y + Math.sin(a) * d * (0.3 + e)).toFixed(1)}) rotate(${(a * 30).toFixed(0)}) scale(${(flip * sc).toFixed(3)} ${sc.toFixed(3)})`);
          p.setAttribute('opacity', (t < 0.55 ? 1 : 1 - (t - 0.55) / 0.45).toFixed(2));
        }, () => p.remove());
      }
    }

    /** Bring the next block down from above. */
    nextBlock() {
      this.setHanging(this.pickSkin(this.hangSkin));
      this.hidden = false;
      return this.anim(260, () => {});
    }

    /** After a collapse or a cash-out: the floors fall away, then a fresh block for a fresh street. */
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
