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
    // (A compressor in front squashed every sound to well under -10 dB; a hard limiter alone keeps
    //  sounds near full loudness and still stops a crash + horn + music moment from clipping.)
    master = ctx.createGain();
    master.gain.value = 1.7;
    const limit = ctx.createDynamicsCompressor();
    limit.threshold.value = -3; limit.knee.value = 0; limit.ratio.value = 20; limit.attack.value = 0.001; limit.release.value = 0.1;
    master.connect(limit).connect(ctx.destination);
    noiseBuf = ctx.createBuffer(1, ctx.sampleRate, ctx.sampleRate);
    const d = noiseBuf.getChannelData(0);
    for (let i = 0; i < d.length; i++) d[i] = Math.random() * 2 - 1;
    return ctx;
  }
  const unlock = () => { const c = audio(); if (c && c.state === 'suspended') c.resume().catch(() => {}); };
  ['pointerdown', 'pointerup', 'touchend', 'click', 'keydown'].forEach((ev) => document.addEventListener(ev, unlock, { passive: true }));

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
  // An original, bouncy 8-bit tune in the spirit of classic platformer music (not any real game's theme):
  // a square-wave lead with a short echo, a hopping octave bass on triangle, and chiptune drums, 8 bars
  // in C major at 150 bpm, scheduled on the audio clock. It starts with the first tap/click, fades out
  // when the site is muted or the tab is hidden, and fades back in after.
  const BPM = 150, EIGHTH = 60 / BPM / 2, MUSIC_VOL = 0.8;
  const mtof = (m) => 440 * Math.pow(2, (m - 69) / 12);
  const _ = null;
  const MELODY = [                       // one MIDI note per eighth, 8 bars (C Dm F G | C Am F-G C)
    72, _, 76, 79, _, 76, 72, _,   74, _, 77, 81, _, 79, _, _,
    81, _, 79, 77, 81, _, 84, _,   83, _, 81, 79, _, 74, _, _,
    72, 76, 79, 84, _, 83, 79, _,   81, _, 84, 81, 76, _, _, _,
    77, 81, 84, _, 79, 83, 86, _,   84, _, 79, 76, 72, _, _, _,
  ];
  const ROOTS = [36, 38, 41, 43, 36, 45, 41, 36];      // bass root per bar (bar 7 turns to G halfway)
  let musicGain = null, musicTimer = null, step = 0, nextAt = 0;

  /** The lead: a 25% pulse wave (the classic console tone), short and punchy. */
  function pulse(t, f, vol, dur) {
    const o = ctx.createOscillator(), g = ctx.createGain();
    const w = ctx.createPeriodicWave(...pulseWave());
    o.setPeriodicWave(w); o.frequency.setValueAtTime(f, t);
    g.gain.setValueAtTime(0.0001, t);
    g.gain.linearRampToValueAtTime(vol, t + 0.006);
    g.gain.setValueAtTime(vol * 0.8, t + dur * 0.6);
    g.gain.linearRampToValueAtTime(0.0001, t + dur);
    o.connect(g).connect(musicGain); o.start(t); o.stop(t + dur + 0.02);
  }
  let _pw = null;
  function pulseWave() {                 // Fourier series of a 25% duty pulse, built once
    if (_pw) return _pw;
    const n = 32, re = new Float32Array(n), im = new Float32Array(n);
    for (let k = 1; k < n; k++) im[k] = (2 / (k * Math.PI)) * Math.sin(k * Math.PI * 0.25);
    return (_pw = [re, im]);
  }
  function scheduleStep(i, t) {
    const bar = Math.floor(i / 8) % 8, e = i % 8;
    const n = MELODY[i % MELODY.length];
    if (n !== null) {
      pulse(t, mtof(n), e % 2 === 0 ? 0.075 : 0.06, EIGHTH * 0.9);
      pulse(t + EIGHTH * 0.75, mtof(n), 0.022, EIGHTH * 0.6);     // a soft echo, for that console shimmer
    }
    // Hopping bass: root, octave, root, octave...
    const root = (bar === 6 && e >= 4) ? 43 : ROOTS[bar];
    tone(t, { type: 'triangle', f0: mtof(root + (e % 2 ? 12 : 0)), dur: EIGHTH * 0.85, peak: 0.2, attack: 0.004, dest: musicGain });
    // Drums: kick on 1 and 3, snare on 2 and 4, hats on the off-beats.
    if (e === 0 || e === 4) tone(t, { type: 'sine', f0: 150, f1: 45, dur: 0.12, peak: 0.32, glide: 0.1, dest: musicGain });
    if (e === 2 || e === 6) noise(t, { dur: 0.09, peak: 0.08, f0: 1800, q: 0.7, dest: musicGain });
    if (e % 2 === 1) noise(t, { dur: 0.035, peak: 0.03, f0: 8000, q: 1.5, dest: musicGain });
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
  // Phones only allow audio after a full tap: a touch's start alone may not count, so the release and
  // the click start it too (whichever the browser accepts first).
  const startOnGesture = () => { unlock(); startMusic(); };
  ['pointerdown', 'pointerup', 'touchend', 'click', 'keydown'].forEach((ev) => document.addEventListener(ev, startOnGesture, { passive: true }));
  document.addEventListener('visibilitychange', musicLevel);

  // Try to start as the game opens. Browsers allow sound before a tap only for sites the visitor uses a
  // lot; everywhere else the context stays suspended, so a small "Tap to play sound" button appears and
  // disappears as soon as sound is running (any tap anywhere also starts it).
  function soundPrompt() {
    if (document.getElementById('crSoundPrompt')) return;
    const b = document.createElement('button');
    b.id = 'crSoundPrompt'; b.type = 'button';
    b.textContent = '\u{1F50A} Tap to play sound';
    b.style.cssText = 'position:fixed;left:50%;bottom:86px;transform:translateX(-50%);z-index:9999;border:0;border-radius:999px;'
      + 'padding:10px 18px;font:700 14px Poppins,sans-serif;color:#fff;background:linear-gradient(180deg,#4fd35d,#2a9c38);'
      + 'box-shadow:0 6px 18px rgba(0,0,0,.35);cursor:pointer';
    b.addEventListener('click', () => { startOnGesture(); });
    document.body.appendChild(b);
    const watch = setInterval(() => {
      if (ctx && ctx.state === 'running') { b.remove(); clearInterval(watch); }
    }, 250);
  }
  function autostart() {
    if (muted()) return;                 // a visitor who muted the site gets no prompt either
    startMusic();
    const c = audio();
    if (!c) return;
    if (c.state === 'suspended') c.resume().catch(() => {});
    setTimeout(() => { if (ctx && ctx.state !== 'running') soundPrompt(); }, 400);
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', autostart); else autostart();

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
    /** For troubleshooting from the browser console: CRSound.status() */
    status() { return { t: ctx ? +ctx.currentTime.toFixed(2) : null, next: +nextAt.toFixed(2), context: ctx ? ctx.state : 'none', music: !!musicTimer, musicLevel: musicGain ? +musicGain.gain.value.toFixed(3) : null, muted: muted(), step }; },
  };
})();
