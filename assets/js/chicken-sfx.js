/**
 * Chicken Road sound pack — every sound synthesized live with the Web Audio API (no audio files),
 * in the same spirit as sound-fx.js. Obeys the site-wide mute (SoundFX.isMuted / the floating
 * speaker button), and only starts its audio context on the player's first click or key press, as
 * browsers require.
 *
 *   CRSound.play('cluck' | 'hop' | 'land' | 'coin' | 'barrier' | 'whoosh' | 'horn' | 'screech' |
 *                'crash' | 'squawk' | 'cashout' | 'fanfare' | 'tick', opts?)
 *
 * opts.pan (-1..1) places a sound left/right — used for cars passing on either side of the hen.
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
    // Loud enough for laptop and phone speakers: full level in, a compressor to even it out, make-up
    // gain, then a limiter so a busy moment (crash + horn + music) can never clip.
    master = ctx.createGain();
    master.gain.value = 1.0;
    const comp = ctx.createDynamicsCompressor();
    comp.threshold.value = -20; comp.knee.value = 8; comp.ratio.value = 4; comp.attack.value = 0.004; comp.release.value = 0.2;
    const makeup = ctx.createGain(); makeup.gain.value = 1.9;
    const limit = ctx.createDynamicsCompressor();
    limit.threshold.value = -2; limit.knee.value = 0; limit.ratio.value = 20; limit.attack.value = 0.001; limit.release.value = 0.08;
    master.connect(comp).connect(makeup).connect(limit).connect(ctx.destination);
    noiseBuf = ctx.createBuffer(1, ctx.sampleRate, ctx.sampleRate);
    const d = noiseBuf.getChannelData(0);
    for (let i = 0; i < d.length; i++) d[i] = Math.random() * 2 - 1;
    return ctx;
  }
  const unlock = () => { const c = audio(); if (c && c.state === 'suspended') c.resume().catch(() => {}); };
  document.addEventListener('pointerdown', unlock, { passive: true });
  document.addEventListener('keydown', unlock);

  // --- building blocks -------------------------------------------------------------------------------
  function out(pan) {
    if (pan === undefined || !ctx.createStereoPanner) return master;
    const p = ctx.createStereoPanner();
    p.pan.value = Math.max(-1, Math.min(1, pan));
    p.connect(master);
    return p;
  }
  /** A gain envelope: attack to `peak`, then exponential decay to silence over `dur`. */
  function env(t, peak, attack, dur) {
    const g = ctx.createGain();
    g.gain.setValueAtTime(0.0001, t);
    g.gain.exponentialRampToValueAtTime(peak, t + attack);
    g.gain.exponentialRampToValueAtTime(0.0001, t + dur);
    return g;
  }
  function tone(t, { type = 'sine', f0, f1 = f0, dur, peak = 0.3, attack = 0.005, dest, glide = dur }) {
    const o = ctx.createOscillator();
    o.type = type;
    o.frequency.setValueAtTime(f0, t);
    if (f1 !== f0) o.frequency.exponentialRampToValueAtTime(f1, t + glide);
    const g = env(t, peak, attack, dur);
    o.connect(g).connect(dest);
    o.start(t); o.stop(t + dur + 0.05);
    return o;
  }
  function noise(t, { dur, peak = 0.3, attack = 0.005, type = 'bandpass', f0 = 1000, f1 = f0, q = 1, dest }) {
    const s = ctx.createBufferSource();
    s.buffer = noiseBuf;
    const f = ctx.createBiquadFilter();
    f.type = type; f.Q.value = q;
    f.frequency.setValueAtTime(f0, t);
    if (f1 !== f0) f.frequency.exponentialRampToValueAtTime(f1, t + dur);
    const g = env(t, peak, attack, dur);
    s.connect(f).connect(g).connect(dest);
    s.start(t, Math.random() * 0.5); s.stop(t + dur + 0.05);
  }
  /** A hen's "bok": a buzzy voiced pulse pushed through a nasal formant. */
  function bok(t, dest, { f0 = 520, f1 = 380, dur = 0.075, peak = 0.22, formant = 1250 } = {}) {
    const o = ctx.createOscillator();
    o.type = 'sawtooth';
    o.frequency.setValueAtTime(f0, t);
    o.frequency.exponentialRampToValueAtTime(f1, t + dur);
    const bp = ctx.createBiquadFilter();
    bp.type = 'bandpass'; bp.frequency.value = formant; bp.Q.value = 3.5;
    const lp = ctx.createBiquadFilter();
    lp.type = 'lowpass'; lp.frequency.value = 3200;
    const g = env(t, peak, 0.006, dur);
    o.connect(bp).connect(lp).connect(g).connect(dest);
    o.start(t); o.stop(t + dur + 0.05);
  }

  // --- the sounds ------------------------------------------------------------------------------------
  const SOUNDS = {
    /** Two quick "bok-bok" clucks, slightly varied each time so repeats don't sound canned. */
    cluck(t, d) {
      const v = 0.92 + Math.random() * 0.16;
      bok(t, d, { f0: 560 * v, f1: 420 * v });
      bok(t + 0.1, d, { f0: 610 * v, f1: 440 * v, dur: 0.09, peak: 0.2, formant: 1350 });
    },
    /** The spring of the hop: a rising "boing" over a little air whoosh. */
    hop(t, d) {
      tone(t, { type: 'sine', f0: 240, f1: 560, dur: 0.16, peak: 0.16, glide: 0.12, dest: d });
      noise(t, { dur: 0.22, peak: 0.05, f0: 700, f1: 2400, q: 0.8, dest: d });
    },
    /** Feet back on the ground. */
    land(t, d) {
      tone(t, { type: 'sine', f0: 150, f1: 70, dur: 0.12, peak: 0.25, dest: d });
      noise(t, { dur: 0.06, peak: 0.06, type: 'lowpass', f0: 900, dest: d });
    },
    /** The manhole turning to gold: a bright two-note coin. */
    coin(t, d) {
      tone(t, { type: 'square', f0: 988, dur: 0.07, peak: 0.07, dest: d });
      tone(t + 0.065, { type: 'square', f0: 1319, dur: 0.32, peak: 0.08, dest: d });
      tone(t + 0.065, { type: 'sine', f0: 2638, dur: 0.25, peak: 0.04, dest: d });
    },
    /** The barrier dropping into place: a hollow metallic clunk. */
    barrier(t, d) {
      tone(t, { type: 'triangle', f0: 220, f1: 110, dur: 0.18, peak: 0.22, dest: d });
      tone(t, { type: 'square', f0: 640, f1: 590, dur: 0.09, peak: 0.05, dest: d });
      noise(t, { dur: 0.08, peak: 0.12, f0: 2200, q: 2, dest: d });
    },
    /** A car going past: a swelling, falling band of road noise. */
    whoosh(t, d) {
      // Brighter band than a real car's rumble so small speakers reproduce it.
      noise(t, { dur: 0.6, peak: 0.24, attack: 0.16, f0: 520, f1: 2300, q: 1.1, dest: d });
      tone(t, { type: 'sawtooth', f0: 150, f1: 95, dur: 0.5, peak: 0.045, attack: 0.14, dest: d });
    },
    /** Two detuned blasts of a car horn. */
    horn(t, d) {
      const lp = ctx.createBiquadFilter(); lp.type = 'lowpass'; lp.frequency.value = 2800; lp.connect(d);
      for (const f of [415, 523]) tone(t, { type: 'square', f0: f, dur: 0.34, peak: 0.17, attack: 0.01, dest: lp });
    },
    /** Tyres locking up. */
    screech(t, d) {
      noise(t, { dur: 0.42, peak: 0.26, attack: 0.02, f0: 3600, f1: 2200, q: 14, dest: d });
      tone(t, { type: 'sawtooth', f0: 1900, f1: 1300, dur: 0.4, peak: 0.04, attack: 0.02, dest: d });
    },
    /** The hit: a deep thump with a crunch on top. */
    crash(t, d) {
      tone(t, { type: 'sine', f0: 110, f1: 38, dur: 0.45, peak: 0.55, dest: d });
      noise(t, { dur: 0.35, peak: 0.35, type: 'lowpass', f0: 2600, f1: 300, dest: d });
      noise(t + 0.02, { dur: 0.18, peak: 0.12, f0: 5200, q: 3, dest: d });
    },
    /** The hen's alarmed "bwaAAK!" */
    squawk(t, d) {
      bok(t, d, { f0: 520, f1: 900, dur: 0.12, peak: 0.24, formant: 1500 });
      bok(t + 0.11, d, { f0: 980, f1: 620, dur: 0.28, peak: 0.26, formant: 1700 });
    },
    /** Cash-out: a register "ka" then a cascade of coins. */
    cashout(t, d) {
      noise(t, { dur: 0.05, peak: 0.12, f0: 3000, q: 2, dest: d });
      [1047, 1319, 1568, 2093, 2637].forEach((f, i) =>
        tone(t + 0.06 + i * 0.055, { type: 'triangle', f0: f, dur: 0.35, peak: 0.09, dest: d }));
      tone(t + 0.34, { type: 'sine', f0: 3136, dur: 0.5, peak: 0.04, dest: d });
    },
    /** The Golden Egg: a little fanfare. */
    fanfare(t, d) {
      const notes = [[523, 0], [659, 0.12], [784, 0.24], [1047, 0.36], [784, 0.52], [1047, 0.62]];
      for (const [f, dt] of notes) {
        tone(t + dt, { type: 'square', f0: f, dur: dt >= 0.62 ? 0.7 : 0.16, peak: 0.06, dest: d });
        tone(t + dt, { type: 'triangle', f0: f * 2, dur: dt >= 0.62 ? 0.7 : 0.16, peak: 0.04, dest: d });
      }
      noise(t + 0.62, { dur: 0.7, peak: 0.03, f0: 6000, q: 1, dest: d });
    },
    /** A soft UI tick. */
    tick(t, d) { tone(t, { type: 'triangle', f0: 1800, dur: 0.04, peak: 0.05, dest: d }); },
  };

  // --- background music --------------------------------------------------------------------------------
  // A gentle, cheerful loop in C major at 96 bpm: a soft marimba melody over a plucky bass, warm chord
  // pads and a whisper of shaker. Eight bars (C Am F G | C Am F-G C), scheduled a little ahead of time
  // on the audio clock so it never drifts. It starts with the first click or key press, fades out when
  // the site is muted or the tab is hidden, and fades back in after.
  const BPM = 96, EIGHTH = 60 / BPM / 2, MUSIC_VOL = 0.42;
  const mtof = (m) => 440 * Math.pow(2, (m - 69) / 12);
  const _ = null;
  const MELODY = [                       // one MIDI note per eighth, 8 bars
    76, _, 79, _, 76, 74, 72, _,   72, _, 76, _, 81, _, 79, _,
    81, _, 79, 77, 76, _, 72, _,   74, _, 76, _, 74, _, _, _,
    76, _, 79, _, 84, _, 79, _,   81, _, 79, 76, 72, _, 76, _,
    77, _, 81, _, 79, _, 74, _,   72, _, 76, _, 72, _, _, _,
  ];
  const CHORDS = [[48, 52, 55], [45, 48, 52], [41, 45, 48], [43, 47, 50],
                  [48, 52, 55], [45, 48, 52], [41, 45, 48, 43], [48, 52, 55]];   // bar 7 turns to G halfway
  let musicGain = null, musicTimer = null, step = 0, nextAt = 0;

  function marimba(t, f, vol) {
    const d = musicGain;
    tone(t, { type: 'sine', f0: f, dur: 0.42, peak: vol, dest: d });
    tone(t, { type: 'sine', f0: f * 4, dur: 0.07, peak: vol * 0.22, dest: d });          // the mallet's tick
    tone(t, { type: 'triangle', f0: f * 2, dur: 0.18, peak: vol * 0.18, dest: d });
  }
  function pad(t, notes, len) {
    notes.forEach((n) => {
      const o = ctx.createOscillator(), g = ctx.createGain();
      o.type = 'sine'; o.frequency.value = mtof(n + 12); o.detune.value = (Math.random() - 0.5) * 8;
      g.gain.setValueAtTime(0.0001, t);
      g.gain.linearRampToValueAtTime(0.022, t + 0.35);
      g.gain.setValueAtTime(0.022, t + len - 0.4);
      g.gain.linearRampToValueAtTime(0.0001, t + len);
      o.connect(g).connect(musicGain); o.start(t); o.stop(t + len + 0.05);
    });
  }
  function scheduleStep(i, t) {
    const bar = Math.floor(i / 8) % 8, e = i % 8;
    const n = MELODY[i % MELODY.length];
    if (n !== null) marimba(t, mtof(n), e % 2 === 0 ? 0.11 : 0.085);
    const chord = CHORDS[bar];
    if (e === 0) { pad(t, chord.slice(0, 3), EIGHTH * (chord.length > 3 ? 4 : 8)); }
    if (e === 4 && chord.length > 3) pad(t, [43, 47, 50], EIGHTH * 4);
    const root = (e >= 4 && chord.length > 3) ? chord[3] : chord[0];
    if (e === 0 || e === 4 || e === 6) {
      const bf = mtof(root - 12 + (e === 6 ? 7 : 0));
      tone(t, { type: 'triangle', f0: bf, dur: 0.26, peak: 0.075, dest: musicGain });
      tone(t, { type: 'sine', f0: bf * 2, dur: 0.2, peak: 0.04, dest: musicGain });   // an octave up, for phone speakers
    }
    if (e % 2 === 1) noise(t, { dur: 0.05, peak: 0.012, f0: 7500, q: 1.2, dest: musicGain });
  }
  function musicLevel() {
    if (!musicGain) return;
    const want = (muted() || document.hidden) ? 0.0001 : MUSIC_VOL;
    if (Math.abs(musicGain.gain.value - want) > 0.001) musicGain.gain.setTargetAtTime(want, ctx.currentTime, 0.35);
  }
  function startMusic() {
    const c = audio();
    if (!c || musicTimer) return;
    musicGain = c.createGain();
    musicGain.gain.value = 0.0001;
    musicGain.connect(master);
    nextAt = c.currentTime + 0.15; step = 0;
    musicTimer = setInterval(() => {
      if (c.state !== 'running') return;
      musicLevel();
      while (nextAt < c.currentTime + 0.25) {               // keep a quarter-second scheduled ahead
        if (!muted() && !document.hidden) { try { scheduleStep(step, nextAt); } catch (e) { /* never break the game */ } }
        step = (step + 1) % (MELODY.length);
        nextAt += EIGHTH;
      }
    }, 60);
  }
  const startOnGesture = () => { unlock(); startMusic(); };
  document.addEventListener('pointerdown', startOnGesture, { passive: true });
  document.addEventListener('keydown', startOnGesture);
  document.addEventListener('visibilitychange', musicLevel);

  window.CRSound = {
    play(name, opts = {}) {
      if (muted() || !SOUNDS[name]) return;
      const c = audio();
      if (!c) return;
      if (c.state === 'suspended') c.resume().catch(() => {});
      try { SOUNDS[name](c.currentTime + (opts.delay || 0), out(opts.pan)); } catch (e) { /* never let a sound break the game */ }
    },
    /** Start the background music now (it otherwise starts on the first click or key press). */
    music: startMusic,
  };
})();
