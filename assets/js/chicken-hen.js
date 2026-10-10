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
  svg.querySelector('#shadow')?.remove();             // the road draws its own contact shadow

  // Cranked-up for the game: big, cartoony, readable at small size. (The studio defaults are 1.)
  const ctl = new HenController(createRig(svg), { params: { amp: 2.3, bob: 1.1, head: 1.1, flap: 1.3 } });
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
    if (svg.isConnected) { ctl.update(dt); ctl.render(); }
    requestAnimationFrame(frame);
  }
  requestAnimationFrame(frame);

  const deferFidget = (ms) => { nextFidget = Math.max(nextFidget, performance.now() + ms); };

  window.CRHen = {
    attach(el) { el.textContent = ''; el.appendChild(svg); ctl.render(); },
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
