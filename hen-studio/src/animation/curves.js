/**
 * Timing helpers shared by every clip: easing curves and keyframe tracks.
 */

export const TAU = Math.PI * 2;
export const clamp01 = (x) => Math.max(0, Math.min(1, x));
export const lerp = (a, b, t) => a + (b - a) * t;
export const smoothstep = (a, b, x) => { const t = clamp01((x - a) / (b - a)); return t * t * (3 - 2 * t); };
export const easeInOut = (t) => 0.5 - 0.5 * Math.cos(Math.PI * clamp01(t));
export const easeOut = (t) => 1 - Math.pow(1 - clamp01(t), 3);
export const easeIn = (t) => Math.pow(clamp01(t), 3);
/** Overshoots and settles: for follow-through on the way back to rest. */
export const easeOutBack = (t) => { const c = 1.7; t = clamp01(t) - 1; return 1 + (c + 1) * t * t * t + c * t * t; };
export const fract = (x) => x - Math.floor(x);

/**
 * A keyframe track: [[time 0..1, value, ease?], ...]. The ease named on a key shapes the segment
 * that ENDS at that key. Times must be ascending; values before the first / after the last key hold.
 */
export function track(keys, t) {
  if (t <= keys[0][0]) return keys[0][1];
  for (let i = 1; i < keys.length; i++) {
    const [t1, v1, ease] = keys[i];
    if (t <= t1) {
      const [t0, v0] = keys[i - 1];
      const u = (t - t0) / (t1 - t0 || 1);
      const f = ease === 'out' ? easeOut(u) : ease === 'in' ? easeIn(u) : ease === 'back' ? easeOutBack(u)
              : ease === 'linear' ? u : easeInOut(u);
      return v0 + (v1 - v0) * f;
    }
  }
  return keys[keys.length - 1][1];
}

/** A blink's eyelid closure (0 open .. 1 shut) at time `t` seconds after it started. */
export function blinkCurve(t, close = 0.075, hold = 0.045, open = 0.11) {
  if (t < 0) return 0;
  if (t < close) return easeIn(t / close) * 0.6 + (t / close) * 0.4;
  if (t < close + hold) return 1;
  if (t < close + hold + open) return 1 - easeOut((t - close - hold) / open);
  return 0;
}
export const BLINK_LENGTH = 0.075 + 0.045 + 0.11;
