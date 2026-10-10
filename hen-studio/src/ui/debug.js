/**
 * Rig debug overlay: pivots, bones (parent pivot -> child pivot), each part's bounding box, the
 * root and the ground line. Everything is measured from the LIVE, already-transformed SVG groups,
 * so the overlay shows where the rig actually is, not where the code thinks it should be.
 *
 * Drawn into its own <svg> on top of the hen; never part of any export.
 */

import { JOINTS, GROUND_Y } from '../rig/skeleton.js';

const NS = 'http://www.w3.org/2000/svg';
const BOXED = ['tail', 'torso', 'wingNear', 'headShape', 'comb', 'eyeNear', 'eyeFar', 'beakUpper', 'beakLower', 'wattle', 'footNear', 'footFar'];

function el(name, attrs) {
  const e = document.createElementNS(NS, name);
  for (const k in attrs) e.setAttribute(k, attrs[k]);
  return e;
}

export function drawDebug(overlay, henSvg, rig) {
  overlay.textContent = '';
  const toArt = henSvg.getScreenCTM().inverse();
  const world = (node, x, y) => {
    const m = toArt.multiply(node.getScreenCTM());
    return [m.a * x + m.c * y + m.e, m.b * x + m.d * y + m.f];
  };

  // Part bounding boxes (in each part's own frame, so they turn with it).
  for (const id of BOXED) {
    const node = henSvg.querySelector('#' + id);
    if (!node) continue;
    const b = node.getBBox();
    const pts = [[b.x, b.y], [b.x + b.width, b.y], [b.x + b.width, b.y + b.height], [b.x, b.y + b.height]]
      .map(([x, y]) => world(node, x, y).map((v) => v.toFixed(1)).join(','));
    overlay.appendChild(el('polygon', { points: pts.join(' '), fill: 'none', stroke: '#2f6fed', 'stroke-width': 2, 'stroke-dasharray': '8 6', opacity: 0.55 }));
  }

  // Bones and pivots. A joint's pivot moves with its own translation (rotation leaves it fixed),
  // so it is mapped through the joint group's full transform.
  const pos = {};
  for (const id in JOINTS) {
    const [px, py] = JOINTS[id].pivot;
    pos[id] = world(rig.nodes[id], px, py);
  }
  for (const id in JOINTS) {
    const parent = JOINTS[id].parent;
    if (!parent) continue;
    const [x1, y1] = pos[parent], [x2, y2] = pos[id];
    overlay.appendChild(el('line', { x1, y1, x2, y2, stroke: '#ff8a00', 'stroke-width': 4, opacity: 0.85 }));
  }
  for (const id in JOINTS) {
    const [x, y] = pos[id];
    const isRoot = !JOINTS[id].parent;
    overlay.appendChild(el('circle', { cx: x, cy: y, r: isRoot ? 14 : 9, fill: isRoot ? '#e0245e' : '#ff8a00', stroke: '#fff', 'stroke-width': 3 }));
    const t = el('text', { x: x + 12, y: y - 10, 'font-size': 22, 'font-family': 'system-ui, sans-serif', fill: '#1f2329', stroke: '#fff', 'stroke-width': 5, 'paint-order': 'stroke' });
    t.textContent = id;
    overlay.appendChild(t);
  }

  // Root cross and ground.
  const [rx, ry] = pos.root;
  overlay.appendChild(el('path', { d: `M${rx - 30} ${ry}H${rx + 30}M${rx} ${ry - 30}V${ry + 30}`, stroke: '#e0245e', 'stroke-width': 4 }));
  overlay.appendChild(el('line', { x1: -4000, y1: GROUND_Y, x2: 6000, y2: GROUND_Y, stroke: '#e0245e', 'stroke-width': 2, opacity: 0.6 }));
}
