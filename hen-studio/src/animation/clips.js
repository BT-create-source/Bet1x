/**
 * Animation clips.
 *
 * A clip is a pure function of time: sample(t, params) returns a sparse pose
 * { joint: { tx, ty, rot, sx, sy, close } } of offsets from the rest pose. Nothing here keeps state,
 * which is what makes playback speed, scrubbing and frame export exact and repeatable.
 *
 *   kind 'base'   — idle / walk / run. Loops. The controller cross-fades between them.
 *   kind 'action' — peck, wing flap, look around, react, blink. One-shot, ADDED on top of whatever
 *                   base is playing. Every action starts and ends at exactly zero, so it can never
 *                   snap the hen out of or back into a pose.
 *
 * Sign conventions (the hen faces right; y grows down): positive rot tips the body forward, raises
 * the tail, lifts the wing, and lifts a foot's heel. Joint pivots are in src/rig/skeleton.js.
 *
 * Tunables arrive in `P`: bob (body-bob intensity), head (head-motion intensity), flap (wing
 * amplitude) and walkSpeed (cadence multiplier). All default to 1.
 */

import { TAU, clamp01, lerp, smoothstep, easeInOut, fract, track, blinkCurve } from './curves.js';

const DEFAULTS = { bob: 1, head: 1, flap: 1, walkSpeed: 1 };

/**
 * One foot's position over a gait cycle, phase 0..1 starting at heel contact.
 *   stance (0..S): planted, sliding back under the body at a constant rate (walking in place, so
 *                  the ground moves under her) and peeling off the heel just before lift-off.
 *   swing  (S..1): lifted on an arc and carried forward, toes tipped up, landing flat.
 */
function foot(phase, { stride, lift, stance }) {
  const p = fract(phase);
  if (p < stance) {
    const s = p / stance;
    return { x: stride * (0.5 - s), y: 0, rot: 14 * smoothstep(0.72, 1, s) };      // heel peels up at toe-off
  }
  const s = (p - stance) / (1 - stance);
  const x = stride * (-0.5 + easeInOut(s));
  const y = -lift * Math.sin(Math.PI * s) * (1 - 0.15 * s);                       // arc peaks a touch early
  const rot = s < 0.45 ? lerp(14, -9, smoothstep(0, 0.45, s)) : lerp(-9, 0, smoothstep(0.55, 1, s));
  return { x, y, rot };
}

/** Shared by walk and run: two feet half a cycle apart, and a body that rides on them. */
function gait(t, P, g) {
  const period = g.period / P.walkSpeed;
  const p = fract(t / period);
  const near = foot(p, g), far = foot(p + 0.5, g);
  // Each contact happens at p = 0 and 0.5. The body sinks just after contact (weight acceptance)
  // and rises at mid-stance; `w` is that up/down wave, 2 cycles per stride.
  const w = Math.cos(TAU * 2 * (p - g.sinkLag));
  const bob = g.bob * P.bob;
  const pose = {
    legNear: { tx: near.x, ty: near.y },
    footNear: { rot: near.rot },
    legFar: { tx: far.x, ty: far.y },
    footFar: { rot: far.rot },
    body: {
      ty: bob * w - g.raise,
      tx: g.forward + 2.5 * Math.sin(TAU * 2 * p),
      rot: g.lean + g.rock * Math.sin(TAU * p),
      sy: 1 - g.squash * Math.max(0, w),
      sx: 1 + g.squash * 0.5 * Math.max(0, w),
    },
  };
  // The chicken head-bob: the head holds still in space through each stance, then thrusts forward.
  // q runs 0..1 once per step; the head drifts back for 75% of it and snaps forward in the rest.
  const q = fract(2 * p + g.headLead);
  const thrust = q < 0.75 ? lerp(1, -1, q / 0.75) : lerp(-1, 1, easeInOut((q - 0.75) / 0.25));
  const h = P.head;
  pose.neck = {
    tx: (g.headForward + g.headBob * thrust) * h,
    ty: (-0.55 * bob * Math.cos(TAU * 2 * (p - g.sinkLag - 0.08))) * h - g.raise * 0.2,
    rot: -g.lean * 0.55,                                                   // keep the gaze level when leaning
  };
  pose.head = { rot: (g.nod * Math.sin(TAU * 2 * p + 0.7) - g.lean * 0.25) * h };
  // Secondary motion, each a little behind the beat it follows.
  pose.wingNear = { rot: g.wingOpen * P.flap + g.wingBeat * P.flap * Math.sin(TAU * 2 * p + 1.1) };
  pose.tail = { rot: g.tailLift + g.tailSway * Math.sin(TAU * 2 * p - 0.9) };
  pose.comb = { rot: g.combSway * Math.sin(TAU * 2 * p - 1.3) * h };
  pose.wattle = { rot: g.wattleSway * Math.sin(TAU * 2 * p - 1.6) * h };
  pose.pupilNear = { tx: g.gaze, ty: g.gaze * 0.25 };
  pose.pupilFar = { tx: g.gaze * 0.4 };
  return pose;
}

// -------------------------------------------------------------------------------------------------
// Base clips
// -------------------------------------------------------------------------------------------------

/**
 * Idle: a 6.4 s seamless loop — two slow breaths, a lazy head drift, a glance, and two blinks at
 * irregular-looking moments (fixed times, so the loop and exports repeat exactly).
 */
const IDLE_LEN = 6.4;
const IDLE_BLINKS = [1.35, 4.62];
function idle(t, P) {
  const u = fract(t / IDLE_LEN) * IDLE_LEN;
  const b = TAU * u / (IDLE_LEN / 2);            // breath, twice per loop
  const d = TAU * u / IDLE_LEN;                  // drift, once per loop
  const h = P.head;
  let close = 0;
  for (const tb of IDLE_BLINKS) close = Math.max(close, blinkCurve(u - tb));
  const glance = track([[0, 0], [0.36, 0], [0.42, 1], [0.6, 1], [0.66, 0], [1, 0]], u / IDLE_LEN);
  return {
    body: { ty: -2.6 * P.bob * Math.sin(b), sy: 1 + 0.009 * P.bob * Math.sin(b), sx: 1 - 0.0045 * P.bob * Math.sin(b) },
    neck: { ty: -1.6 * Math.sin(b - 0.5) * h, tx: 2 * Math.sin(d) * h },
    head: { rot: (2.2 * Math.sin(d) + 0.9 * Math.sin(b + 1.0)) * h },
    tail: { rot: 1.6 * Math.sin(b - 0.8) },
    comb: { rot: 0.9 * Math.sin(b - 1.2) * h },
    wattle: { rot: 1.4 * Math.sin(b - 1.5) * h },
    wingNear: { rot: 0.8 * Math.sin(b - 0.3) },
    pupilNear: { tx: 34 * glance, ty: 10 * glance },
    pupilFar: { tx: 10 * glance, ty: 6 * glance },
    eyelidNear: { close },
    eyelidFar: { close: Math.max(0, Math.min(1, close)) },
  };
}

/** Walk: a 0.9 s two-step cycle, in place. Stance is 62% of each foot's cycle. */
const WALK = { period: 0.9, stride: 64, lift: 44, stance: 0.62, sinkLag: 0.07, bob: 9, squash: 0.012,
  raise: 0, forward: 0, lean: 1.5, rock: 1.4, headForward: 4, headBob: 9, headLead: 0.12, nod: 1.6,
  wingOpen: 0, wingBeat: 2.2, tailLift: 0, tailSway: 3.2, combSway: 2.2, wattleSway: 4.5, gaze: 10 };
function walk(t, P) { return gait(t, P, WALK); }

/**
 * Run: not a sped-up walk. Shorter stance (feet in the air longer), bigger arcs, a forward lean
 * with the head held level against it, wings half open and beating with the stride, tail up.
 */
const RUN = { period: 0.46, stride: 92, lift: 70, stance: 0.42, sinkLag: 0.05, bob: 15, squash: 0.03,
  raise: 6, forward: 10, lean: 7, rock: 1.2, headForward: 18, headBob: 6, headLead: 0.1, nod: 2.4,
  wingOpen: 16, wingBeat: 9, tailLift: 9, tailSway: 4.5, combSway: 4, wattleSway: 7, gaze: 26 };
function run(t, P) { return gait(t, P, RUN); }

// -------------------------------------------------------------------------------------------------
// Actions (additive, start and end at zero)
// -------------------------------------------------------------------------------------------------

/**
 * Peck (1.05 s): a small wind-up back, a quick dive forward and down, two taps at the target, and
 * an easing return with the tail following through.
 *
 * This hen has almost no neck, so most of the dive is the whole body tipping forward at the hips
 * (the feet stay planted — they hang from the root, not the body). The neck adds only a modest dip
 * on top, which keeps the head over the body: pushing the head itself further shows the hidden
 * neck artwork past the silhouette. The jaw opens a crack on each tap.
 */
function peck(t, P) {
  const u = t / 1.05, h = P.head;
  const dive = track([[0, 0], [0.16, -0.12, 'out'], [0.38, 1, 'in'], [0.47, 1.08], [0.53, 0.98], [0.6, 1.08],
                      [0.68, 1], [1, 0, 'out']], u);
  const tap = track([[0, 0], [0.42, 0], [0.47, 1, 'out'], [0.53, 0], [0.6, 1, 'out'], [0.67, 0], [1, 0]], u);
  return {
    body: { rot: 17 * dive, tx: 4 * dive, ty: 3 * dive },
    neck: { rot: 16 * dive * h, tx: 10 * dive * h, ty: 46 * dive * h },
    head: { rot: 10 * dive * h },
    beakLower: { rot: 10 * tap },
    tail: { rot: track([[0, 0], [0.38, 9], [0.7, 8], [0.88, -3], [1, 0]], u) },
    wattle: { rot: track([[0, 0], [0.38, -10], [0.5, 6], [0.62, -6], [0.8, 3], [1, 0]], u) },
    comb: { rot: track([[0, 0], [0.4, -4], [0.55, 3], [0.8, -1.5], [1, 0]], u) },
    pupilNear: { tx: 30 * dive, ty: 40 * dive },
    pupilFar: { tx: 8 * dive, ty: 20 * dive },
    wingNear: { rot: 3 * dive },
  };
}

/** Blink (0.23 s): both lids, the far one a frame behind. */
function blink(t) {
  return { eyelidNear: { close: blinkCurve(t) }, eyelidFar: { close: blinkCurve(t - 0.015) } };
}

/**
 * Wing flap (1.2 s): two beats about the shoulder with easing at both ends, a small lift of the
 * body on each down-stroke, and the tail and head reacting.
 */
function flap(t, P) {
  const u = t / 1.2, a = P.flap;
  const wing = track([[0, 0], [0.16, 62, 'out'], [0.32, 6, 'in'], [0.5, 58, 'out'], [0.68, 4, 'in'], [0.82, 14, 'out'], [1, 0]], u);
  const lift = track([[0, 0], [0.3, -10], [0.38, -2], [0.66, -11], [0.74, -2], [1, 0]], u);
  return {
    wingNear: { rot: wing * a },
    body: { ty: lift * a, sy: 1 + 0.01 * a * Math.max(0, -lift / 10) },
    neck: { ty: lift * 0.6 * a, rot: -2 * Math.sin(Math.PI * u) },
    head: { rot: track([[0, 0], [0.2, -3], [0.5, -4], [0.8, -1], [1, 0]], u) * P.head },
    tail: { rot: track([[0, 0], [0.18, 6], [0.34, -2], [0.52, 6], [0.7, -2], [1, 0]], u) * a },
    comb: { rot: track([[0, 0], [0.3, 2.5], [0.4, -2], [0.66, 2.5], [0.76, -2], [1, 0]], u) },
  };
}

/** Look around (3.4 s): back and up over the shoulder, then forward and down, then home. */
function look(t, P) {
  const u = t / 3.4, h = P.head;
  const back = track([[0, 0], [0.12, 1], [0.38, 1], [0.48, 0], [1, 0]], u);
  const fwd = track([[0, 0], [0.42, 0], [0.54, 1], [0.84, 1], [1, 0]], u);
  let close = blinkCurve(t - 1.55);           // a blink as the gaze changes direction
  return {
    neck: { rot: (-5 * back + 6 * fwd) * h, tx: (-8 * back + 12 * fwd) * h, ty: (-10 * back + 8 * fwd) * h },
    head: { rot: (-6 * back + 5 * fwd) * h },
    pupilNear: { tx: -36 * back + 64 * fwd, ty: -24 * back + 30 * fwd },
    pupilFar: { tx: -16 * back + 6 * fwd, ty: -20 * back + 22 * fwd },
    eyelidNear: { close }, eyelidFar: { close },
    comb: { rot: (1.5 * back - 1.5 * fwd) * h },
  };
}

/**
 * React / startle (0.95 s): a flinch down, then a little hop up with the head thrown back, eyes
 * wide (pupils contract), wing flicked out and tail cocked — then settling back with overshoot.
 */
function react(t, P) {
  const u = t / 0.95;
  const crouch = track([[0, 0], [0.08, 1, 'out'], [0.15, 0], [1, 0]], u);
  const jump = track([[0, 0], [0.08, 0], [0.22, 1, 'out'], [0.36, 0.85], [0.5, 0.1, 'in'], [0.58, -0.08], [0.72, 0.03], [1, 0]], u);
  const alarm = track([[0, 0], [0.12, 1, 'out'], [0.6, 0.8], [1, 0]], u);
  return {
    body: { ty: 8 * crouch - 46 * jump, sy: 1 - 0.05 * crouch + 0.05 * jump, sx: 1 + 0.03 * crouch - 0.025 * jump, rot: -4 * alarm },
    legNear: { ty: -38 * Math.max(0, jump) }, legFar: { ty: -38 * Math.max(0, jump) },
    footNear: { rot: -10 * Math.max(0, jump) }, footFar: { rot: -10 * Math.max(0, jump) },
    neck: { ty: -30 * alarm * P.head, tx: -10 * alarm * P.head, rot: -5 * alarm },
    head: { rot: -7 * alarm * P.head },
    pupilNear: { sx: 1 - 0.2 * alarm, sy: 1 - 0.2 * alarm, tx: 22 * alarm },
    pupilFar: { sx: 1 - 0.2 * alarm, sy: 1 - 0.2 * alarm },
    wingNear: { rot: track([[0, 0], [0.12, 32], [0.3, 22], [0.55, -3], [0.7, 2], [1, 0]], u) * P.flap },
    tail: { rot: track([[0, 0], [0.14, 14], [0.4, 10], [0.6, -3], [0.8, 1], [1, 0]], u) },
    comb: { rot: track([[0, 0], [0.12, -6], [0.3, 4], [0.5, -2], [0.7, 1], [1, 0]], u) },
    wattle: { rot: track([[0, 0], [0.15, 9], [0.32, -7], [0.5, 4], [0.7, -2], [1, 0]], u) },
  };
}

export const CLIPS = {
  idle:  { label: 'Idle',        kind: 'base',   loop: true,  duration: IDLE_LEN, sample: idle },
  walk:  { label: 'Walk',        kind: 'base',   loop: true,  duration: WALK.period, sample: walk },
  run:   { label: 'Run',         kind: 'base',   loop: true,  duration: RUN.period,  sample: run },
  peck:  { label: 'Peck',        kind: 'action', loop: false, duration: 1.05, sample: peck },
  blink: { label: 'Blink',       kind: 'action', loop: false, duration: 0.25, sample: blink, layer: 'eyes' },
  flap:  { label: 'Wing flap',   kind: 'action', loop: false, duration: 1.2,  sample: flap },
  look:  { label: 'Look around', kind: 'action', loop: false, duration: 3.4,  sample: look },
  react: { label: 'React',       kind: 'action', loop: false, duration: 0.95, sample: react },
};

/** Ground speed that keeps a planted foot still while the hen walks across the stage (px/s). */
export function groundSpeed(clipId, P = DEFAULTS) {
  const g = clipId === 'run' ? RUN : clipId === 'walk' ? WALK : null;
  if (!g) return 0;
  return g.stride / (g.stance * g.period / (P.walkSpeed || 1));
}

export function withDefaults(P) { return Object.assign({}, DEFAULTS, P || {}); }
export { clamp01 };
