/**
 * bet1x Sound Effects Engine
 * ---------------------------------------------------------------------------
 * Every sound is synthesized live via the Web Audio API — there are no audio
 * files to fetch, license, or ship, and it works fully offline. This file is
 * self-contained and safe to include on every page: it never throws (all
 * playback is wrapped so a sound failure can never break gameplay), it
 * respects browser autoplay policies (the AudioContext is created/resumed
 * lazily on the first user gesture), and it injects its own floating
 * mute/unmute toggle so no page's HTML needs to change to expose one.
 *
 * Signal chain (why it sounds "produced" instead of like raw beeps):
 *   each sound's own gain node
 *     -> busGain (dry path, always)
 *     -> [optional reverb send] -> convolver -> reverbReturn -> busGain
 *   busGain -> softClipper (gentle tanh saturation, like a mix-bus limiter,
 *              so several sounds stacking at once — e.g. a coin cascade
 *              under a win chime — never digitally clips)
 *            -> masterGain (mute: 0/1)
 *            -> destination
 * Individual tones can also request a `chorus` detune layer (a second,
 * slightly detuned oscillator summed in) for a fuller, less thin timbre,
 * stereo `pan`, and small randomized pitch/timing "humanization" so
 * repeated sounds (coin cascades, chip stacks) don't sound like a loop.
 *
 * Usage from any page/game script, once this file is included after
 * ui-common.js:
 *   SoundFX.play('win');                        // or 'lose', 'click', ...
 *   SoundFX.play('gemReveal', { streak: 4 });    // some sounds take options
 *   SoundFX.setMuted(true);
 *   SoundFX.isMuted();
 *   SoundFX.toggle();
 */
(function () {
  if (window.SoundFX) return; // guard against double-include

  const MUTE_KEY = 'bet1x_sound_muted';

  let ctx = null;
  let masterGain = null;   // mute control (0/1)
  let softClipper = null;  // gentle saturation "limiter" on the mix bus
  let busGain = null;      // overall dry level trim, everything routes here
  let convolver = null;    // shared reverb impulse
  let reverbReturn = null; // wet return level
  let noiseBuffer = null;
  let toggleBtnRef = null;

  function isMuted() {
    return localStorage.getItem(MUTE_KEY) === 'true';
  }

  function makeSoftClipCurve() {
    const n = 1024;
    const curve = new Float32Array(n);
    for (let i = 0; i < n; i++) {
      const x = (i / (n - 1)) * 2 - 1;
      curve[i] = Math.tanh(x * 1.4);
    }
    return curve;
  }

  // A short, synthesized decaying-noise impulse response — gives sounds a
  // sense of space without needing an actual recorded IR file.
  function makeReverbImpulse(c) {
    const duration = 0.7, decay = 2.4;
    const rate = c.sampleRate;
    const length = Math.floor(rate * duration);
    const impulse = c.createBuffer(2, length, rate);
    for (let ch = 0; ch < 2; ch++) {
      const data = impulse.getChannelData(ch);
      for (let i = 0; i < length; i++) {
        data[i] = (Math.random() * 2 - 1) * Math.pow(1 - i / length, decay);
      }
    }
    return impulse;
  }

  function getCtx() {
    if (ctx) return ctx;
    const AC = window.AudioContext || window.webkitAudioContext;
    if (!AC) return null;
    try {
      ctx = new AC();

      masterGain = ctx.createGain();
      masterGain.gain.value = isMuted() ? 0 : 1;

      softClipper = ctx.createWaveShaper();
      softClipper.curve = makeSoftClipCurve();
      softClipper.oversample = '2x';

      busGain = ctx.createGain();
      busGain.gain.value = 0.9;

      convolver = ctx.createConvolver();
      convolver.buffer = makeReverbImpulse(ctx);
      reverbReturn = ctx.createGain();
      reverbReturn.gain.value = 0.4;

      busGain.connect(softClipper).connect(masterGain).connect(ctx.destination);
      convolver.connect(reverbReturn).connect(busGain);
    } catch (e) {
      ctx = null;
    }
    return ctx;
  }

  function setMuted(m) {
    localStorage.setItem(MUTE_KEY, m ? 'true' : 'false');
    const c = getCtx();
    if (c && masterGain) {
      masterGain.gain.setTargetAtTime(m ? 0 : 1, c.currentTime, 0.01);
    }
    updateToggleUI();
  }

  // --- Low-level synthesis helpers ---------------------------------------

  function envelope(gainNode, peak, start, attack, release) {
    const g = gainNode.gain;
    g.cancelScheduledValues(start);
    g.setValueAtTime(0, start);
    g.linearRampToValueAtTime(peak, start + attack);
    g.exponentialRampToValueAtTime(0.0001, start + attack + release);
  }

  // Routes a sound's gain node to the dry bus, and optionally sends a copy
  // into the shared reverb for sounds that should feel like they have space
  // around them (wins, cashouts, jackpots) rather than sitting flat/dry.
  function routeOut(node, opts) {
    let outNode = node;
    if (opts && opts.pan != null && ctx.createStereoPanner) {
      const panner = ctx.createStereoPanner();
      panner.pan.value = Math.max(-1, Math.min(1, opts.pan));
      node.connect(panner);
      outNode = panner;
    }
    outNode.connect(busGain);
    if (opts && opts.reverb) {
      const send = ctx.createGain();
      send.gain.value = opts.reverb;
      outNode.connect(send);
      send.connect(convolver);
    }
  }

  function jitter(val, pct) {
    const p = pct == null ? 0.03 : pct;
    return val * (1 + (Math.random() * 2 - 1) * p);
  }

  // A single synthesized tone, optionally sweeping frequency (crash / whoosh
  // style effects) via freqEnd + glide, panned/reverbed, and optionally
  // doubled by a second, slightly-detuned oscillator ("chorus") for a
  // fuller, more produced timbre instead of a thin single sine wave.
  function tone(freq, opts) {
    const c = getCtx();
    if (!c) return;
    opts = opts || {};
    const start = c.currentTime + (opts.delay || 0);
    const attack = opts.attack != null ? opts.attack : 0.005;
    const release = opts.release != null ? opts.release : 0.12;
    const peak = opts.peak != null ? opts.peak : 0.15;
    const stopAt = start + attack + release + 0.05;

    function layer(freqMul, peakMul) {
      const osc = c.createOscillator();
      const gain = c.createGain();
      osc.type = opts.type || 'sine';
      osc.frequency.setValueAtTime(freq * freqMul, start);
      if (opts.freqEnd) {
        osc.frequency.exponentialRampToValueAtTime(Math.max(opts.freqEnd, 1) * freqMul, start + (opts.glide || 0.15));
      }
      osc.connect(gain);
      routeOut(gain, opts);
      envelope(gain, peak * peakMul, start, attack, release);
      osc.start(start);
      osc.stop(stopAt);
    }

    layer(1, 1);
    if (opts.chorus) layer(1 + (opts.detune || 0.006), 0.6);
  }

  // A plucked/coin-like metallic tone: a fundamental plus a quiet inharmonic
  // overtone (roughly a bell ratio), which reads as "metal" far better than
  // a plain oscillator — used for chips, coins, and gem reveals.
  function pluck(freq, opts) {
    opts = opts || {};
    tone(freq, Object.assign({ type: 'triangle' }, opts));
    tone(freq * 2.76, Object.assign({}, opts, {
      type: 'sine',
      peak: (opts.peak != null ? opts.peak : 0.15) * 0.22,
      release: (opts.release != null ? opts.release : 0.12) * 0.55
    }));
  }

  // Filtered white-noise burst, used for percussive/textural effects
  // (mine hit, crash, card deal/flip/shuffle, whooshes) a pure oscillator
  // can't sell on its own.
  function getNoiseBuffer(c) {
    if (noiseBuffer) return noiseBuffer;
    const size = c.sampleRate * 1;
    noiseBuffer = c.createBuffer(1, size, c.sampleRate);
    const data = noiseBuffer.getChannelData(0);
    for (let i = 0; i < size; i++) data[i] = Math.random() * 2 - 1;
    return noiseBuffer;
  }

  function noiseBurst(opts) {
    const c = getCtx();
    if (!c) return;
    opts = opts || {};
    const start = c.currentTime + (opts.delay || 0);
    const duration = opts.duration || 0.2;
    const attack = opts.attack != null ? opts.attack : 0.002;
    const peak = opts.peak != null ? opts.peak : 0.2;

    const src = c.createBufferSource();
    src.buffer = getNoiseBuffer(c);
    const filter = c.createBiquadFilter();
    filter.type = opts.filterType || 'lowpass';
    filter.frequency.setValueAtTime(opts.filterFreq || 1500, start);
    if (opts.filterFreqEnd) {
      filter.frequency.exponentialRampToValueAtTime(Math.max(opts.filterFreqEnd, 1), start + duration);
    }
    const gain = c.createGain();
    src.connect(filter).connect(gain);
    routeOut(gain, opts);
    envelope(gain, peak, start, attack, opts.release != null ? opts.release : duration);
    src.start(start);
    src.stop(start + duration + 0.05);
  }

  // A cluster of coin/chip plucks with humanized pitch + timing — the
  // signature "casino win cascade" sound. Density front-loaded, decaying.
  function coinCascade(opts) {
    opts = opts || {};
    const count = opts.count || 10;
    const baseFreq = opts.baseFreq || 1200;
    let t = 0;
    for (let i = 0; i < count; i++) {
      t += jitter(0.028 + (i / count) * 0.02, 0.4);
      pluck(jitter(baseFreq + Math.random() * 700, 0.06), {
        peak: 0.12 * (1 - (i / count) * 0.5),
        release: 0.16,
        delay: t,
        pan: Math.random() * 1.6 - 0.8,
        reverb: 0.3
      });
    }
  }

  // --- The sound library ---------------------------------------------------
  // Grouped by role. Every entry is a function(opts) so a caller can pass
  // context (e.g. gemReveal's streak) — SoundFX.play() forwards opts through.

  const PENTATONIC_UP = [1568, 1760, 1976, 2093, 2349, 2637, 2793, 3136];

  const SOUNDS = {
    // --- Generic UI ---
    click: () => tone(700, { type: 'sine', peak: 0.06, attack: 0.002, release: 0.05 }),
    clickSecondary: () => tone(480, { type: 'sine', peak: 0.045, attack: 0.002, release: 0.06 }),
    hover: () => {},
    tabSwitch: () => tone(950, { type: 'square', peak: 0.05, attack: 0.001, release: 0.035 }),
    toggleOn: () => { tone(700, { peak: 0.07, release: 0.05 }); tone(1000, { peak: 0.07, release: 0.06, delay: 0.045 }); },
    toggleOff: () => { tone(1000, { peak: 0.07, release: 0.05 }); tone(700, { peak: 0.07, release: 0.06, delay: 0.045 }); },
    modalOpen: () => noiseBurst({ filterType: 'bandpass', filterFreq: 400, filterFreqEnd: 1400, duration: 0.18, peak: 0.05 }),
    modalClose: () => noiseBurst({ filterType: 'bandpass', filterFreq: 1400, filterFreqEnd: 400, duration: 0.14, peak: 0.05 }),

    // --- Notifications / feedback ---
    notification: () => {
      tone(1000, { type: 'sine', peak: 0.07, attack: 0.002, release: 0.08 });
      tone(1300, { type: 'sine', peak: 0.05, attack: 0.002, release: 0.06, delay: 0.05 });
    },
    success: () => {
      tone(523.25, { peak: 0.12, attack: 0.004, release: 0.12, chorus: true, reverb: 0.15 });
      tone(659.25, { peak: 0.12, attack: 0.004, release: 0.16, delay: 0.09, chorus: true, reverb: 0.15 });
    },
    warning: () => {
      tone(440, { type: 'triangle', peak: 0.09, attack: 0.003, release: 0.09 });
      tone(440, { type: 'triangle', peak: 0.09, attack: 0.003, release: 0.09, delay: 0.13 });
    },
    error: () => {
      tone(220, { type: 'square', peak: 0.1, release: 0.08 });
      tone(180, { type: 'square', peak: 0.1, release: 0.12, delay: 0.1 });
    },
    login: () => [659.25, 830.61, 1046.5].forEach((f, i) =>
      tone(f, { type: 'sine', peak: 0.1, attack: 0.005, release: 0.15, delay: i * 0.07, chorus: true, reverb: 0.25 })),
    logout: () => [880, 659.25, 523.25].forEach((f, i) =>
      tone(f, { type: 'sine', peak: 0.09, attack: 0.005, release: 0.14, delay: i * 0.06, reverb: 0.2 })),

    // --- Money / betting ---
    betPlace: () => {
      tone(160, { type: 'triangle', peak: 0.18, attack: 0.002, release: 0.09 });
      pluck(1300, { peak: 0.08, attack: 0.001, release: 0.06, delay: 0.03, reverb: 0.12 });
    },
    betCancel: () => tone(500, { type: 'triangle', peak: 0.09, attack: 0.002, release: 0.09, freqEnd: 220, glide: 0.12 }),
    chipStack: (opts) => {
      const n = (opts && opts.count) || 3;
      for (let i = 0; i < n; i++) {
        pluck(jitter(1100 + i * 40, 0.05), { peak: 0.09, release: 0.08, delay: i * 0.045 + Math.random() * 0.01, pan: jitter(0, 1) * 0.35, reverb: 0.1 });
      }
    },
    coinDrop: () => pluck(jitter(1300, 0.05), { peak: 0.11, release: 0.12, reverb: 0.2 }),
    coinCascade: (opts) => coinCascade(opts),
    walletTick: () => tone(1500, { type: 'sine', peak: 0.03, attack: 0.001, release: 0.02 }),

    // --- Round flow ---
    win: () => [523.25, 659.25, 783.99, 1046.5].forEach((f, i) =>
      tone(f, { type: 'triangle', peak: 0.14, attack: 0.005, release: 0.18, delay: i * 0.09, chorus: true, reverb: 0.2 })),
    bigWin: () => {
      [[523.25, 659.25, 783.99], [587.33, 739.99, 880], [659.25, 830.61, 987.77], [783.99, 987.77, 1174.66]]
        .forEach((chord, i) => chord.forEach(f =>
          tone(f, { type: 'triangle', peak: 0.1, attack: 0.01, release: 0.35, delay: i * 0.14, chorus: true, reverb: 0.4 })));
      coinCascade({ count: 16 });
    },
    lose: () => {
      tone(300, { type: 'sawtooth', peak: 0.12, attack: 0.005, release: 0.15, freqEnd: 120, glide: 0.2 });
      noiseBurst({ filterType: 'lowpass', filterFreq: 400, duration: 0.15, peak: 0.08, delay: 0.02 });
    },
    tick: () => tone(880, { type: 'square', peak: 0.05, attack: 0.001, release: 0.03 }),
    tickUrgent: () => tone(1100, { type: 'square', peak: 0.09, attack: 0.001, release: 0.04 }),
    go: () => {
      tone(1046.5, { type: 'triangle', peak: 0.15, attack: 0.003, release: 0.12, chorus: true, reverb: 0.2 });
      noiseBurst({ filterType: 'bandpass', filterFreq: 600, filterFreqEnd: 2000, duration: 0.15, peak: 0.06 });
    },
    roundStart: () => noiseBurst({ filterType: 'bandpass', filterFreq: 300, filterFreqEnd: 1200, duration: 0.3, peak: 0.06 }),

    // --- Cards (Teen Patti) ---
    cardDeal: () => noiseBurst({ filterType: 'highpass', filterFreq: 2000, duration: 0.05, peak: 0.1 }),
    cardFlip: () => noiseBurst({ filterType: 'highpass', filterFreq: 2500, duration: 0.04, peak: 0.09 }),
    cardShuffle: () => {
      for (let i = 0; i < 8; i++) {
        noiseBurst({ filterType: 'highpass', filterFreq: jitter(2200, 0.15), duration: 0.03, peak: 0.05, delay: i * 0.045 + Math.random() * 0.01 });
      }
    },
    fold: () => {
      noiseBurst({ filterType: 'lowpass', filterFreq: 800, filterFreqEnd: 200, duration: 0.18, peak: 0.06 });
      tone(200, { type: 'sine', peak: 0.05, attack: 0.005, release: 0.15, freqEnd: 100, glide: 0.15 });
    },
    showdown: () => {
      tone(220, { type: 'sawtooth', peak: 0.14, attack: 0.005, release: 0.2, freqEnd: 280, glide: 0.08 });
      tone(440, { type: 'square', peak: 0.08, attack: 0.005, release: 0.15, delay: 0.02 });
    },

    // --- Aviator ---
    crash: () => {
      noiseBurst({ filterType: 'lowpass', filterFreq: 2500, filterFreqEnd: 200, duration: 0.4, peak: 0.22 });
      tone(600, { type: 'sawtooth', freqEnd: 60, glide: 0.4, peak: 0.15, release: 0.4 });
    },
    takeoff: () => {
      tone(300, { type: 'sawtooth', peak: 0.1, attack: 0.01, release: 0.3, freqEnd: 900, glide: 0.25 });
      noiseBurst({ filterType: 'highpass', filterFreq: 800, duration: 0.25, peak: 0.06 });
    },
    milestone: () => {
      tone(1318.5, { type: 'sine', peak: 0.12, attack: 0.003, release: 0.12, chorus: true, reverb: 0.3 });
      tone(1567.98, { type: 'sine', peak: 0.08, attack: 0.003, release: 0.14, delay: 0.05, reverb: 0.3 });
    },
    cashout: () => [659.25, 783.99, 987.77, 1318.5].forEach((f, i) =>
      tone(f, { type: 'sine', peak: 0.16, attack: 0.003, release: 0.14, delay: i * 0.06, chorus: true, reverb: 0.25 })),

    // --- Mines ---
    mineHit: () => {
      noiseBurst({ filterType: 'lowpass', filterFreq: 1800, filterFreqEnd: 100, duration: 0.35, peak: 0.28 });
      tone(80, { type: 'square', peak: 0.2, release: 0.3 });
    },
    gemReveal: (opts) => {
      const streak = Math.max(1, (opts && opts.streak) || 1);
      const freq = PENTATONIC_UP[Math.min(streak - 1, PENTATONIC_UP.length - 1)];
      tone(freq, { type: 'sine', peak: 0.11, attack: 0.002, release: 0.09, chorus: true, reverb: 0.15 });
      tone(freq * 1.5, { type: 'sine', peak: 0.05, attack: 0.002, release: 0.07, delay: 0.03, reverb: 0.15 });
    }
  };

  function play(name, opts) {
    try {
      if (isMuted()) return;
      const c = getCtx();
      if (!c) return;
      if (c.state === 'suspended') c.resume().catch(() => {});
      const fn = SOUNDS[name];
      if (fn) fn(opts);
    } catch (e) {
      // Audio must never be able to break gameplay.
    }
  }

  // --- Floating mute/unmute toggle (auto-injected, no HTML changes needed) -

  function updateToggleUI() {
    if (!toggleBtnRef) return;
    toggleBtnRef.textContent = isMuted() ? '🔇' : '🔊';
    toggleBtnRef.setAttribute('aria-pressed', isMuted() ? 'true' : 'false');
  }

  function injectToggle() {
    if (document.getElementById('soundfx-toggle')) return;

    const style = document.createElement('style');
    style.textContent = `
      #soundfx-toggle {
        position: fixed; bottom: 16px; right: 16px; z-index: 9999;
        width: 44px; height: 44px; border-radius: 50%;
        background: rgba(20,24,32,0.85); border: 1px solid rgba(255,197,61,0.35);
        color: #ffc53d; font-size: 18px; line-height: 1; cursor: pointer;
        display: flex; align-items: center; justify-content: center; padding: 0;
        box-shadow: 0 4px 14px rgba(0,0,0,0.4);
        transition: transform 0.15s ease, border-color 0.15s ease;
        -webkit-tap-highlight-color: transparent;
      }
      #soundfx-toggle:hover { transform: scale(1.08); border-color: rgba(255,197,61,0.7); }
      #soundfx-toggle:active { transform: scale(0.94); }
      @media (max-width: 640px) {
        #soundfx-toggle { width: 38px; height: 38px; font-size: 15px; bottom: 12px; right: 12px; }
      }
    `;
    document.head.appendChild(style);

    const btn = document.createElement('button');
    btn.id = 'soundfx-toggle';
    btn.type = 'button';
    btn.setAttribute('aria-label', 'Toggle sound effects');
    btn.title = 'Toggle sound effects';
    btn.addEventListener('click', function (e) {
      e.stopPropagation();
      setMuted(!isMuted());
    });
    document.body.appendChild(btn);
    toggleBtnRef = btn;
    updateToggleUI();
  }

  // --- Global auto-wiring: generic click feedback + first-gesture unlock --

  function unlockOnce() {
    getCtx();
    if (ctx && ctx.state === 'suspended') ctx.resume().catch(() => {});
  }

  function init() {
    injectToggle();

    document.addEventListener('pointerdown', unlockOnce, { once: true, passive: true });
    document.addEventListener('keydown', unlockOnce, { once: true });

    // A page that defines its own window.playSound (currently just aviator.html, which has
    // a bespoke engine-pitch/cashout/crash sound design) is assumed to already wire click
    // feedback itself — the generic auto-click/hover layer below would only double it up, so
    // it's skipped there. The shared mute toggle above still covers that page either way.
    const hasCustomSoundSystem = typeof window.playSound === 'function';
    if (hasCustomSoundSystem) return;

    // Any real button/submit control gets a light click sound automatically — tab-style
    // controls get a distinct tab-switch blip instead — individual games layer richer
    // sounds (win/lose/deal/etc.) on top of this.
    document.addEventListener('click', function (e) {
      const tabEl = e.target.closest('.exchange-tab-btn, .role-tab, [role="tab"]');
      if (tabEl && !tabEl.disabled) { play('tabSwitch'); return; }
      const el = e.target.closest('button, .btn, input[type="submit"], input[type="button"], [role="button"]');
      if (el && el.id !== 'soundfx-toggle' && !el.disabled) play('click');
    }, true);

    // (Automatic mouseover hover sounds disabled to prevent duplicate audio triggers across text/element boundaries)
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

  window.SoundFX = {
    play,
    setMuted,
    isMuted,
    toggle: () => setMuted(!isMuted())
  };
})();
