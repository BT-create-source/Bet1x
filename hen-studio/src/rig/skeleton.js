/**
 * The hen's skeleton: every joint the rig can move, where it pivots, and how it nests.
 *
 * Coordinates are the master artwork's (src/character/hen.svg), which keeps the reference image's
 * own 1254 x 1254 pixel space: x grows to the right, y grows DOWN, angles are degrees clockwise.
 * The hen faces right, so a positive rotation tips a part forward (toward her beak).
 *
 * Pivots sit on anatomical joints read off the reference, not on the centres of the shapes:
 * the wing turns at the shoulder, the head at the base of the neck, each foot at its ankle.
 *
 * Nesting lives in the SVG itself (a joint's group is inside its parent's group), so moving the
 * neck carries the head, eyes, comb, beak and wattle with it. `parent` below documents that
 * structure for the debug overlay and must match the SVG.
 */

export const ARTBOARD = { width: 1254, height: 1254 };

/** Where her feet meet the ground, used by the ground guide and as the root pivot. */
export const GROUND_Y = 1206;

export const JOINTS = {
  root:       { parent: null,    pivot: [690, GROUND_Y] },
  body:       { parent: 'root',  pivot: [700, 1100] },   // hips: bob, sway and squash happen here
  tail:       { parent: 'body',  pivot: [372, 735] },    // where the tail feathers leave the back
  wingNear:   { parent: 'body',  pivot: [655, 772] },    // shoulder, top-right corner of the folded wing
  wingEdge:   { parent: 'wingNear', pivot: [655, 772] }, // the wing's full outline; opacity follows the wing's lift
  neck:       { parent: 'body',  pivot: [812, 655] },    // base of the neck under the chin fluff
  head:       { parent: 'neck',  pivot: [800, 560] },    // skull turns about the top of the neck
  comb:       { parent: 'head',  pivot: [715, 262] },    // comb base along the crown
  eyeNear:    { parent: 'head',  pivot: [730, 396] },
  pupilNear:  { parent: 'eyeNear', pivot: [666, 384] },
  eyelidNear: { parent: 'eyeNear', pivot: [730, 396] },
  eyeFar:     { parent: 'head',  pivot: [1014, 355] },
  pupilFar:   { parent: 'eyeFar', pivot: [1045, 330] },
  eyelidFar:  { parent: 'eyeFar', pivot: [1014, 355] },
  beakUpper:  { parent: 'head',  pivot: [905, 395] },
  beakLower:  { parent: 'head',  pivot: [928, 456] },    // jaw hinge, tucked under the upper beak
  wattle:     { parent: 'head',  pivot: [935, 470] },    // hangs from under the lower beak
  legNear:    { parent: 'root',  pivot: [588, 1052] },   // hip, hidden inside the body
  footNear:   { parent: 'legNear', pivot: [590, 1118] }, // ankle
  legFar:     { parent: 'root',  pivot: [778, 1052] },
  footFar:    { parent: 'legFar', pivot: [781, 1116] },
};

/**
 * How far each eyelid travels from open (resting above the eye, out of sight inside the clip) to
 * fully closed. The lids are drawn by the tracer at their open position.
 */
export const EYELID_TRAVEL = { eyelidNear: 290, eyelidFar: 237 };   // = 2.35 x eye radius (trace_hen.py)

/** Closed-eye lash lines riding on each lid; their opacity follows the lid's closure. */
export const LASHES = { lashNear: 'eyelidNear', lashFar: 'eyelidFar' };

/**
 * Where each pupil may go. Her pupils rest off-centre (looking up and back, as in the reference),
 * so the limit is not a box around the rest position but an ellipse around the EYE's centre that
 * the pupil's centre must stay inside — eye radius minus pupil radius, minus a small margin.
 */
export const GAZE = {
  pupilNear: { cx: 730, cy: 396, rx: 70, ry: 64, rest: [666, 384] },
  pupilFar:  { cx: 1014, cy: 355, rx: 34, ry: 52, rest: [1045, 330] },
};

/** A neutral pose: every joint at rest. */
export function restPose() {
  const p = {};
  for (const id of Object.keys(JOINTS)) p[id] = { tx: 0, ty: 0, rot: 0, sx: 1, sy: 1 };
  return p;
}
