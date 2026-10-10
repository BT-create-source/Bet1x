/**
 * The animation state controller: one place that decides what the hen is doing.
 *
 *   base layer    exactly one looping clip (idle / walk / run). Switching cross-fades over
 *                 BASE_BLEND seconds, so no joint ever jumps.
 *   action layer  at most one body action (peck / flap / look / react) at a time, ADDED on top of
 *                 the base. Starting a new action while one plays fades the old one out over
 *                 ACTION_FADE seconds instead of cutting it.
 *   eye layer     blinks, which may overlap anything (lid closure is clamped to fully shut).
 *
 * Time is real elapsed time scaled by `speed`, never "one frame". The controller can also be driven
 * by hand (`evaluate(t)`), which is how exports render frames at exact times.
 */

import { CLIPS, withDefaults, groundSpeed } from './clips.js';
import { restPose } from '../rig/skeleton.js';
import { smoothstep } from './curves.js';

export const BASE_BLEND = 0.3;
export const ACTION_FADE = 0.18;

/** Blend b into a by weight w (0 = a, 1 = b). Missing joints count as rest. */
function blendPoses(a, b, w) {
  const out = restPose();
  for (const id in out) {
    const ja = a[id] || {}, jb = b[id] || {};
    const o = out[id];
    o.tx = (ja.tx || 0) * (1 - w) + (jb.tx || 0) * w;
    o.ty = (ja.ty || 0) * (1 - w) + (jb.ty || 0) * w;
    o.rot = (ja.rot || 0) * (1 - w) + (jb.rot || 0) * w;
    o.sx = (ja.sx ?? 1) * (1 - w) + (jb.sx ?? 1) * w;
    o.sy = (ja.sy ?? 1) * (1 - w) + (jb.sy ?? 1) * w;
    o.close = (ja.close || 0) * (1 - w) + (jb.close || 0) * w;
  }
  return out;
}

/**
 * Add a sparse additive pose into a full pose, scaled by w. `amp` exaggerates the motion itself —
 * translations, rotations and squash/stretch — but not eyelid closure, so a blink keeps its timing.
 */
// The head and neck move as one block over hidden neck artwork; pushed much further than this they
// swing that artwork out past the silhouette, so exaggeration is capped for them.
const AMP_CAP = { neck: 1.35, head: 1.35 };

function addPose(into, add, w, amp = 1) {
  for (const id in add) {
    const o = into[id], a = add[id];
    if (!o) continue;
    const k = w * (AMP_CAP[id] ? Math.min(amp, AMP_CAP[id]) : amp);
    o.tx += (a.tx || 0) * k;
    o.ty += (a.ty || 0) * k;
    o.rot += (a.rot || 0) * k;
    if (a.sx !== undefined) o.sx *= Math.max(0.35, 1 + (a.sx - 1) * k);
    if (a.sy !== undefined) o.sy *= Math.max(0.35, 1 + (a.sy - 1) * k);
    o.close += (a.close || 0) * w;
  }
  return into;
}

/** Exaggerate a base (looping) pose about rest by `a` — milder than actions, so loops stay readable. */
function ampBase(pose, a) {
  if (a === 1) return pose;
  for (const id in pose) {
    const o = pose[id];
    o.tx *= a; o.ty *= a; o.rot *= a;
    o.sx = 1 + (o.sx - 1) * a; o.sy = 1 + (o.sy - 1) * a;
  }
  return pose;
}
const baseAmp = (P) => 1 + ((P.amp || 1) - 1) * 0.5;

export class HenController {
  constructor(rig, opts = {}) {
    this.rig = rig;
    this.params = withDefaults(opts.params);
    this.speed = 1;
    this.playing = true;
    this.travel = false;            // walk/run across the stage instead of in place
    this.travelSpan = opts.travelSpan || 1400;
    this.listeners = new Set();
    this.reset();
  }

  /** Back to a neutral idle at time zero, with nothing layered on top. */
  reset() {
    this.base = { id: 'idle', t: 0 };
    this.prev = null;                // { id, t } while a cross-fade is running
    this.blendT = BASE_BLEND;
    this.action = null;              // { id, t }
    this.fading = null;              // an action being faded out: { id, t, f }
    this.blinks = [];                // [t, ...]
    this.worldX = 0;
    this.emit();
  }

  /** Switch the looping base clip (cross-faded). */
  setBase(id) {
    if (!CLIPS[id] || CLIPS[id].kind !== 'base') return;
    if (id === this.base.id) return;
    this.prev = { ...this.base };
    this.base = { id, t: 0 };
    this.blendT = 0;
    this.emit();
  }

  /** Start a one-shot action. Blinks layer freely; body actions replace each other smoothly. */
  play(id) {
    const clip = CLIPS[id];
    if (!clip) return;
    if (clip.kind === 'base') return this.setBase(id);
    if (clip.layer === 'eyes') { this.blinks.push(0); this.emit(); return; }
    if (this.action) this.fading = { id: this.action.id, t: this.action.t, f: 0 };
    this.action = { id, t: 0 };
    this.emit();
  }

  /** Restart whatever is current: the action if one is playing, else the base loop. */
  restart() {
    if (this.action) this.action.t = 0;
    else this.base.t = 0;
    this.emit();
  }

  /** What to show as "now playing". */
  get current() { return this.action ? this.action.id : this.base.id; }

  onChange(fn) { this.listeners.add(fn); return () => this.listeners.delete(fn); }
  emit() { for (const fn of this.listeners) fn(this); }

  /** Advance by real seconds (already measured by the caller). */
  update(dt) {
    if (!this.playing) return;
    dt *= this.speed;
    const P = this.params;
    this.base.t += dt;              // walk speed is applied inside the gait itself (clips.js)
    if (this.prev) {
      this.prev.t += dt;
      this.blendT += dt;
      if (this.blendT >= BASE_BLEND) { this.prev = null; this.emit(); }
    }
    if (this.action) {
      this.action.t += dt;
      if (this.action.t >= CLIPS[this.action.id].duration) { this.action = null; this.emit(); }
    }
    if (this.fading) {
      this.fading.t += dt; this.fading.f += dt;
      if (this.fading.f >= ACTION_FADE || this.fading.t >= CLIPS[this.fading.id].duration) this.fading = null;
    }
    this.blinks = this.blinks.map((b) => b + dt).filter((b) => b < CLIPS.blink.duration);
    if (this.travel) {
      const v = groundSpeed(this.base.id, P) * (this.prev ? smoothstep(0, BASE_BLEND, this.blendT) : 1);
      this.worldX += v * dt;
      if (this.worldX > this.travelSpan / 2) this.worldX -= this.travelSpan;
    } else {
      this.worldX *= Math.max(0, 1 - dt * 6);   // glide back to centre when travel is switched off
    }
  }

  /** The full pose right now. */
  pose() {
    const P = this.params;
    let pose = CLIPS[this.base.id].sample(this.base.t, P);
    if (this.prev) {
      const w = smoothstep(0, BASE_BLEND, this.blendT);
      pose = blendPoses(CLIPS[this.prev.id].sample(this.prev.t, P), pose, w);
    } else {
      pose = blendPoses(pose, pose, 1);
    }
    ampBase(pose, baseAmp(P));
    if (this.fading) {
      addPose(pose, CLIPS[this.fading.id].sample(this.fading.t, P), 1 - smoothstep(0, ACTION_FADE, this.fading.f), P.amp);
    }
    if (this.action) addPose(pose, CLIPS[this.action.id].sample(this.action.t, P), 1, P.amp);
    for (const b of this.blinks) addPose(pose, CLIPS.blink.sample(b, P), 1);
    pose.root.tx += this.worldX;
    return pose;
  }

  render() { this.rig.apply(this.pose()); }
}

/**
 * Evaluate a single clip at time t, standing alone (for exports): a base clip as itself, an action
 * layered over a still idle at time 0, so the frame shows the action and nothing else moving.
 */
export function evaluateClip(id, t, params) {
  const P = withDefaults(params);
  const clip = CLIPS[id];
  if (clip.kind === 'base') return ampBase(blendPoses(clip.sample(t, P), clip.sample(t, P), 1), baseAmp(P));
  const pose = blendPoses({}, {}, 1);
  return addPose(pose, clip.sample(t, P), 1, P.amp);
}
