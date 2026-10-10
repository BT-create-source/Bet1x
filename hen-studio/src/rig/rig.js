/**
 * Applies poses to the hen's SVG.
 *
 * A pose is { jointId: { tx, ty, rot, sx, sy } } in the artwork's coordinates. Each joint's group
 * gets ONE transform built around its own pivot:
 *
 *     translate(tx ty) translate(px py) rotate(rot) scale(sx sy) translate(-px -py)
 *
 * Because the groups are nested exactly as the skeleton is, a child's transform composes on top of
 * its parent's automatically — the browser does the forward kinematics.
 *
 * The same function is used by the live preview (writing to DOM nodes) and by the exporters
 * (writing transform attributes into a detached copy), so what you export is what you previewed.
 */

import { JOINTS, GAZE, EYELID_TRAVEL, LASHES } from './skeleton.js';

const r3 = (n) => Math.round(n * 1000) / 1000;

/**
 * A joint's pose after the rig's own rules: pupils clamped inside their eye, eyelid closure turned
 * into lid travel. Shared by the live transform and the matrix the animated-SVG export writes.
 */
export function resolveJoint(id, j) {
  let tx = j.tx || 0, ty = j.ty || 0;
  const rot = j.rot || 0, sx = j.sx ?? 1, sy = j.sy ?? 1;
  // Pupils never leave their eye: clamp the pupil centre to the gaze ellipse.
  const g = GAZE[id];
  if (g) {
    const x = g.rest[0] + tx - g.cx, y = g.rest[1] + ty - g.cy;
    const k = Math.hypot(x / g.rx, y / g.ry);
    if (k > 1) { tx = g.cx + x / k - g.rest[0]; ty = g.cy + y / k - g.rest[1]; }
  }
  // Eyelids are driven by `close` (0 open .. 1 shut), converted here to the lid's travel.
  if (EYELID_TRAVEL[id] !== undefined) {
    ty = Math.max(0, Math.min(1, j.close || 0)) * EYELID_TRAVEL[id];
    tx = 0;
  }
  return { tx, ty, rot, sx, sy };
}

/** The same transform as a 2D matrix [a, b, c, d, e, f] in artwork units. */
export function jointMatrix(id, j) {
  const [px, py] = JOINTS[id].pivot;
  const { tx, ty, rot, sx, sy } = resolveJoint(id, j);
  const r = rot * Math.PI / 180, c = Math.cos(r), s = Math.sin(r);
  const a = c * sx, b = s * sx, cc = -s * sy, d = c * sy;
  // T(tx,ty) * T(p) * R * S * T(-p)
  const e = tx + px - (a * px + cc * py);
  const f = ty + py - (b * px + d * py);
  return [a, b, cc, d, e, f].map(r3);
}

/** The transform string for one joint, or '' when it is at rest. */
export function jointTransform(id, j) {
  const [px, py] = JOINTS[id].pivot;
  const { tx, ty, rot, sx, sy } = resolveJoint(id, j);
  if (!tx && !ty && !rot && sx === 1 && sy === 1) return '';
  let t = '';
  if (tx || ty) t += `translate(${r3(tx)} ${r3(ty)}) `;
  if (rot || sx !== 1 || sy !== 1) {
    t += `translate(${px} ${py}) `;
    if (rot) t += `rotate(${r3(rot)}) `;
    if (sx !== 1 || sy !== 1) t += `scale(${r3(sx)} ${r3(sy)}) `;
    t += `translate(${-px} ${-py})`;
  }
  return t.trim();
}

/**
 * The wing's full outline fades in as the wing lifts away from the body: invisible folded (where
 * the art has only a soft grey line), fully dark once raised ~30 degrees past the back.
 */
export function wingEdgeOpacity(pose) {
  const rot = (pose.wingNear && pose.wingNear.rot) || 0;
  return r3(Math.max(0, Math.min(1, (rot - 6) / 22)));
}

/** A closed-eye lash line fades in over the last 30% of its lid's travel. */
export function lashOpacity(pose, lashId) {
  const lid = pose[LASHES[lashId]];
  const c = lid ? Math.max(0, Math.min(1, lid.close || 0)) : 0;
  return r3(Math.max(0, (c - 0.7) / 0.3));
}

/** Bind a rig to an <svg> element (live or detached) holding the master artwork. */
export function createRig(svgRoot) {
  const nodes = {};
  for (const id of Object.keys(JOINTS)) {
    const el = svgRoot.querySelector('#' + id);
    if (!el) throw new Error(`hen.svg is missing the joint group #${id}`);
    nodes[id] = el;
  }
  const lashes = {};
  for (const id of Object.keys(LASHES)) lashes[id] = svgRoot.querySelector('#' + id);
  return {
    svg: svgRoot,
    nodes,
    /** Write a full pose into the SVG. */
    apply(pose) {
      for (const id in nodes) {
        const j = pose[id];
        const t = j ? jointTransform(id, j) : '';
        if (t) nodes[id].setAttribute('transform', t);
        else nodes[id].removeAttribute('transform');
        if (id === 'wingEdge') nodes[id].setAttribute('opacity', String(wingEdgeOpacity(pose)));
      }
      for (const id in lashes) {
        if (lashes[id]) lashes[id].setAttribute('opacity', String(lashOpacity(pose, id)));
      }
    },
  };
}

/** Load the master artwork and return its <svg> element (not yet in the document). */
export async function loadHenSvg(url) {
  const res = await fetch(url);
  if (!res.ok) throw new Error(`Could not load ${url}: ${res.status}`);
  const text = await res.text();
  const doc = new DOMParser().parseFromString(text, 'image/svg+xml');
  const svg = doc.documentElement;
  if (svg.nodeName !== 'svg') throw new Error('hen.svg did not parse as SVG');
  return document.importNode(svg, true);
}
