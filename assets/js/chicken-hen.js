/**
 * The rigged hen from hen-studio, as the Chicken Road character.
 *
 * Loaded as a module by chickenroad.html. When it is ready it sets window.CRHen and fires a
 * 'crhen-ready' event; until then (or if it fails to load) the page keeps its simple built-in hen,
 * so the game never depends on this file to work.
 *
 *   CRHen.attach(el)  move the one rigged hen into a container (the page rebuilds its road)
 *   CRHen.hop()       one hop, 0.42 s — the page slides her sideways over the same time
 *   CRHen.cheer()     wing-flap celebration (cash-out)
 *   CRHen.celebrate() bigger celebration (Golden Egg / max win)
 *   CRHen.startle()   the flinch as a car bears down
 *   CRHen.setIdle(on) whether she may peck or look around now and then while waiting
 *
 * All animation logic is hen-studio's (src/animation); this file only drives it.
 */

import { loadHenSvg, createRig } from '../../hen-studio/src/rig/rig.js';
import { HenController } from '../../hen-studio/src/animation/controller.js';

// Crop around the hen with headroom for the hop and room for the raised wing.
const VIEWBOX = '60 -110 1260 1360';

async function init() {
  const svg = await loadHenSvg('hen-studio/src/character/hen.svg');
  svg.setAttribute('viewBox', VIEWBOX);
  svg.removeAttribute('width'); svg.removeAttribute('height');
  svg.setAttribute('preserveAspectRatio', 'xMidYMax meet');
  svg.setAttribute('aria-hidden', 'true');
  svg.style.width = '100%'; svg.style.height = '100%'; svg.style.overflow = 'visible'; svg.style.display = 'block';
  svg.querySelector('#shadow')?.remove();             // replaced by the live ground shadow below

  // Ground shadow, drawn in the hen's own coordinates but OUTSIDE her rig, so it stays on the road
  // while she jumps: a soft wide shadow plus a tight dark contact patch under her feet. Each frame
  // it shrinks and fades with her height off the ground, and follows her sideways.
  const NS = 'http://www.w3.org/2000/svg';
  const defs = svg.querySelector('defs') || svg.insertBefore(document.createElementNS(NS, 'defs'), svg.firstChild);
  defs.insertAdjacentHTML('beforeend',
    '<radialGradient id="crHenShadowSoft"><stop offset="0" stop-color="#140c08" stop-opacity="0.55"/>' +
    '<stop offset="0.55" stop-color="#140c08" stop-opacity="0.28"/><stop offset="1" stop-color="#140c08" stop-opacity="0"/></radialGradient>' +
    '<radialGradient id="crHenShadowCore"><stop offset="0" stop-color="#0b0604" stop-opacity="0.75"/>' +
    '<stop offset="0.7" stop-color="#0b0604" stop-opacity="0.3"/><stop offset="1" stop-color="#0b0604" stop-opacity="0"/></radialGradient>');
  const ground = document.createElementNS(NS, 'g');
  ground.innerHTML =
    '<ellipse class="soft" cx="0" cy="0" rx="420" ry="70" fill="url(#crHenShadowSoft)"/>' +
    '<ellipse class="core" cx="0" cy="0" rx="250" ry="34" fill="url(#crHenShadowCore)"/>';
  svg.insertBefore(ground, svg.querySelector('#root'));
  const SHADOW_X = 700, SHADOW_Y = 1208;               // under her feet, on the ground line
  function placeShadow(pose) {
    // Height off the ground = how far the legs (which carry the feet) have been lifted.
    const lift = Math.max(0, -Math.min(pose.legNear.ty, pose.legFar.ty));
    const k = Math.max(0.45, 1 - lift / 520);           // shrinks as she rises...
    const op = Math.max(0.25, 1 - lift / 420);          // ...and fades
    const x = SHADOW_X + pose.root.tx + (pose.body.tx || 0) * 0.6;
    ground.setAttribute('transform', `translate(${x.toFixed(1)} ${SHADOW_Y}) scale(${k.toFixed(3)} ${(k * 0.92 + 0.08).toFixed(3)})`);
    ground.setAttribute('opacity', op.toFixed(3));
    // The tight contact patch only exists while she is actually touching the ground.
    ground.lastChild.setAttribute('opacity', Math.max(0, 1 - lift / 90).toFixed(3));
  }

  // Cranked-up for the game: big, cartoony, readable at small size. (The studio defaults are 1.)
  const rig = createRig(svg);
  const ctl = new HenController(rig, { params: { amp: 2.3, bob: 1.1, head: 1.1, flap: 1.3 } });
  const render = () => { const pose = ctl.pose(); rig.apply(pose); placeShadow(pose); };
  let idleLife = true;
  let nextFidget = performance.now() + 4000 + Math.random() * 4000;

  let last = performance.now();
  function frame(now) {
    const dt = Math.min(0.1, (now - last) / 1000);
    last = now;
    // Now and then, while she is just standing about, a glance around or a peck at the ground.
    if (idleLife && !ctl.action && now >= nextFidget) {
      ctl.play(Math.random() < 0.6 ? 'look' : 'peck');
      nextFidget = now + 6000 + Math.random() * 6000;
    }
    if (svg.isConnected) { ctl.update(dt); render(); }
    requestAnimationFrame(frame);
  }
  requestAnimationFrame(frame);

  const deferFidget = (ms) => { nextFidget = Math.max(nextFidget, performance.now() + ms); };

  window.CRHen = {
    attach(el) { el.textContent = ''; el.appendChild(svg); render(); },
    hop() { ctl.play('hop'); deferFidget(5000); },
    cheer() { ctl.play('cheer'); deferFidget(5000); },
    celebrate() {
      ctl.play('cheer');
      setTimeout(() => ctl.play('cheer'), 1300);
      setTimeout(() => ctl.play('flap'), 2600);
      deferFidget(8000);
    },
    startle() { ctl.play('react'); ctl.play('blink'); deferFidget(5000); },
    setIdle(on) { idleLife = !!on; if (on) deferFidget(3000); },
    reset() { ctl.reset(); },
  };
  window.dispatchEvent(new Event('crhen-ready'));
}

init().catch((err) => console.warn('Chicken Road: rigged hen unavailable, using the simple hen.', err));
