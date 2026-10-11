/**
 * Tower Rush sound pack — every sound synthesized live with the Web Audio API (no audio files), like
 * chicken-sfx.js. Obeys the site-wide mute (SoundFX.isMuted / the floating speaker button), and only
 * starts its audio context on the player's first click or key press, as browsers require.
 *
 *   TRSound.play('creak' | 'release' | 'whoosh' | 'thud' | 'up' | 'down' | 'even' | 'crumble' |
 *                'oops' | 'cashout' | 'key' | 'bigwin', opts?)
 */
(function () {
  'use strict';
  let ctx = null, master = null, noiseBuf = null;

  const muted = () => {
    try { return window.SoundFX && SoundFX.isMuted ? SoundFX.isMuted() : localStorage.getItem('bet1x_sound_muted') === 'true'; }
    catch (e) { return false; }
  };

  function audio() {
    if (ctx) return ctx;
    const AC = window.AudioContext || window.webkitAudioContext;
    if (!AC) return null;
    ctx = new AC();
    master = ctx.createGain();
    master.gain.value = 0.55;
    const comp = ctx.createDynamicsCompressor();
    comp.threshold.value = -14; comp.ratio.value = 4;
    master.connect(comp).connect(ctx.destination);
    noiseBuf = ctx.createBuffer(1, ctx.sampleRate, ctx.sampleRate);
    const d = noiseBuf.getChannelData(0);
    for (let i = 0; i < d.length; i++) d[i] = Math.random() * 2 - 1;
    return ctx;
  }
  const unlock = () => { const c = audio(); if (c && c.state === 'suspended') c.resume().catch(() => {}); };
  document.addEventListener('pointerdown', unlock, { passive: true });
  document.addEventListener('keydown', unlock);

  function env(t, peak, attack, dur) {
    const g = ctx.createGain();
    g.gain.setValueAtTime(0.0001, t);
    g.gain.exponentialRampToValueAtTime(peak, t + attack);
    g.gain.exponentialRampToValueAtTime(0.0001, t + dur);
    return g;
  }
  function tone(t, { type = 'sine', f0, f1 = f0, dur, peak = 0.3, attack = 0.005, dest = master, glide = dur }) {
    const o = ctx.createOscillator();
    o.type = type;
    o.frequency.setValueAtTime(f0, t);
    if (f1 !== f0) o.frequency.exponentialRampToValueAtTime(f1, t + glide);
    o.connect(env(t, peak, attack, dur)).connect(dest);
    o.start(t); o.stop(t + dur + 0.05);
  }
  function noise(t, { dur, peak = 0.3, attack = 0.005, type = 'bandpass', f0 = 1000, f1 = f0, q = 1, dest = master }) {
    const s = ctx.createBufferSource();
    s.buffer = noiseBuf;
    const f = ctx.createBiquadFilter();
    f.type = type; f.Q.value = q;
    f.frequency.setValueAtTime(f0, t);
    if (f1 !== f0) f.frequency.exponentialRampToValueAtTime(f1, t + dur);
    s.connect(f).connect(env(t, peak, attack, dur)).connect(dest);
    s.start(t, Math.random() * 0.5); s.stop(t + dur + 0.05);
  }

  const SOUNDS = {
    /** The crane's cable creaking as the block swings. */
    creak(t) {
      tone(t, { type: 'sawtooth', f0: 310, f1: 260, dur: 0.22, peak: 0.035, attack: 0.04 });
      noise(t, { dur: 0.2, peak: 0.03, f0: 1800, q: 6 });
    },
    /** The hook letting go: a metallic clank. */
    release(t) {
      tone(t, { type: 'square', f0: 880, f1: 820, dur: 0.08, peak: 0.07 });
      tone(t, { type: 'triangle', f0: 1760, dur: 0.18, peak: 0.05 });
      noise(t, { dur: 0.06, peak: 0.12, f0: 3200, q: 3 });
    },
    /** The block falling through the air. */
    whoosh(t) {
      noise(t, { dur: 0.45, peak: 0.12, attack: 0.15, f0: 500, f1: 2200, q: 0.9 });
    },
    /** The block landing on the tower: a heavy wooden thud and a puff of dust. */
    thud(t) {
      tone(t, { type: 'sine', f0: 140, f1: 48, dur: 0.3, peak: 0.45 });
      tone(t, { type: 'triangle', f0: 240, f1: 120, dur: 0.12, peak: 0.12 });
      noise(t, { dur: 0.35, peak: 0.14, type: 'lowpass', f0: 1400, f1: 300 });
    },
    /** The multiplier went up: a bright rising arpeggio. */
    up(t) {
      [659, 880, 1175].forEach((f, i) => tone(t + i * 0.06, { type: 'square', f0: f, dur: 0.16, peak: 0.06 }));
      tone(t + 0.18, { type: 'sine', f0: 2350, dur: 0.3, peak: 0.04 });
    },
    /** The multiplier went down: two falling notes. */
    down(t) {
      tone(t, { type: 'triangle', f0: 523, dur: 0.12, peak: 0.12 });
      tone(t + 0.1, { type: 'triangle', f0: 392, f1: 370, dur: 0.24, peak: 0.12 });
    },
    /** x1: a neutral blip. */
    even(t) {
      tone(t, { type: 'triangle', f0: 660, dur: 0.16, peak: 0.1 });
    },
    /** The tower giving way: cracking timber, a rumble, falling rubble. */
    crumble(t) {
      for (let i = 0; i < 6; i++) noise(t + i * 0.07, { dur: 0.09, peak: 0.16, f0: 900 + Math.random() * 1600, q: 2 });
      tone(t, { type: 'sine', f0: 90, f1: 38, dur: 0.9, peak: 0.4, attack: 0.03 });
      noise(t + 0.1, { dur: 0.9, peak: 0.16, type: 'lowpass', f0: 700, f1: 150 });
    },
    /** The OOPS banner: a cartoon "wah-wah". */
    oops(t) {
      tone(t, { type: 'sawtooth', f0: 392, f1: 370, dur: 0.24, peak: 0.07 });
      tone(t + 0.26, { type: 'sawtooth', f0: 349, f1: 262, dur: 0.5, peak: 0.07, glide: 0.45 });
    },
    /** Cash out: a cascade of coins over a chord. */
    cashout(t) {
      for (let i = 0; i < 7; i++) {
        const f = 1300 + Math.random() * 900;
        tone(t + i * 0.055, { type: 'square', f0: f, dur: 0.09, peak: 0.045 });
        tone(t + i * 0.055 + 0.04, { type: 'sine', f0: f * 1.5, dur: 0.18, peak: 0.03 });
      }
      [523, 659, 784, 1047].forEach((f) => tone(t + 0.05, { type: 'triangle', f0: f, dur: 0.7, peak: 0.06, attack: 0.02 }));
    },
    /** A keypad press. */
    key(t) {
      tone(t, { type: 'sine', f0: 1100, f1: 900, dur: 0.05, peak: 0.08 });
    },
    /** A floor of x2 or more: a small fanfare. */
    bigwin(t) {
      [523, 659, 784, 1047, 1319].forEach((f, i) => tone(t + i * 0.07, { type: 'square', f0: f, dur: 0.22, peak: 0.055 }));
      [1047, 1319, 1568].forEach((f) => tone(t + 0.36, { type: 'triangle', f0: f, dur: 0.8, peak: 0.05, attack: 0.02 }));
    },
  };

  window.TRSound = {
    play(name, opts = {}) {
      if (muted() || !SOUNDS[name]) return;
      const c = audio();
      if (!c) return;
      if (c.state === 'suspended') c.resume().catch(() => {});
      try { SOUNDS[name](c.currentTime + (opts.delay || 0)); } catch (e) { /* never let a sound break the game */ }
    },
  };
})();
