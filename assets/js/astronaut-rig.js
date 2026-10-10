/**
 * The Astronaut character: a rigged cartoon astronaut animated part by part, in the spirit of Chicken
 * Road's hen (hen-studio), simpler. Plain script; sets window.AstroRig.
 *
 *   AstroRig.mount(el)     draw him inside a container (replaces its contents)
 *   AstroRig.setMode(m)    'idle' (waiting to launch), 'fly' (round running), 'lost' (crashed)
 *   AstroRig.setSpeed(v)   0..1 — how hard he is flying (lean, legs trailing, jet size, excitement)
 *   AstroRig.cheer()       a celebration (the player cashed out)
 *
 * Every part hangs off its own joint (hips, shoulders, neck), and secondary motion lags the body, so
 * he reads as a body floating in zero-g rather than a sticker moving about: the head settles after
 * the torso, the legs trail, he blinks, looks around while waiting, grins as the multiplier climbs,
 * waves on a cash-out and flails as he is lost. If this file fails, the page keeps its static art.
 */
(function () {
  'use strict';
  const NS = 'http://www.w3.org/2000/svg';
  const INK = '#22172e';

  const ART = `
<defs>
  <radialGradient id="arSuit" cx="35%" cy="28%" r="80%"><stop offset="0" stop-color="#ffffff"/><stop offset="0.6" stop-color="#e9ebf5"/><stop offset="1" stop-color="#aeb4cf"/></radialGradient>
  <linearGradient id="arLimb" x1="0" y1="0" x2="1" y2="0"><stop offset="0" stop-color="#ffffff"/><stop offset="0.6" stop-color="#e2e5f1"/><stop offset="1" stop-color="#a7adc9"/></linearGradient>
  <linearGradient id="arLimbFar" x1="0" y1="0" x2="1" y2="0"><stop offset="0" stop-color="#d4d8e8"/><stop offset="1" stop-color="#8d93b2"/></linearGradient>
  <linearGradient id="arPack" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#ffb35c"/><stop offset="0.55" stop-color="#ff7a2f"/><stop offset="1" stop-color="#c94a14"/></linearGradient>
  <radialGradient id="arHelm" cx="34%" cy="28%" r="75%"><stop offset="0" stop-color="#ffffff"/><stop offset="0.65" stop-color="#e8ebf5"/><stop offset="1" stop-color="#a3a9c6"/></radialGradient>
  <radialGradient id="arGlass" cx="40%" cy="35%" r="75%"><stop offset="0" stop-color="#bfe9ff" stop-opacity="0.35"/><stop offset="0.7" stop-color="#5b8de0" stop-opacity="0.32"/><stop offset="1" stop-color="#2a2f7a" stop-opacity="0.55"/></radialGradient>
  <radialGradient id="arSkin" cx="40%" cy="35%" r="75%"><stop offset="0" stop-color="#ffdcc2"/><stop offset="1" stop-color="#eab08e"/></radialGradient>
  <linearGradient id="arBoot" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#8a90b0"/><stop offset="1" stop-color="#4b5070"/></linearGradient>
  <linearGradient id="arFlame" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#ffffff"/><stop offset="0.2" stop-color="#fff3a8"/><stop offset="0.5" stop-color="#ffb02e"/><stop offset="0.8" stop-color="#ff4f7b" stop-opacity="0.6"/><stop offset="1" stop-color="#a56bff" stop-opacity="0"/></linearGradient>
  <radialGradient id="arFlameGlow"><stop offset="0" stop-color="#ffd27a" stop-opacity="0.8"/><stop offset="1" stop-color="#ff5bd2" stop-opacity="0"/></radialGradient>
  <filter id="arBlur" x="-50%" y="-50%" width="200%" height="200%"><feGaussianBlur stdDeviation="2"/></filter>
  <filter id="arShadow" x="-30%" y="-30%" width="160%" height="160%"><feDropShadow dx="1.5" dy="3" stdDeviation="2.2" flood-color="#06030f" flood-opacity="0.5"/></filter>
  <clipPath id="arVisorClip"><ellipse cx="7" cy="-27" rx="13" ry="11"/></clipPath>
</defs>
<g id="arRoot" filter="url(#arShadow)">
  <g id="arJet">
    <ellipse cx="0" cy="12" rx="9" ry="15" fill="url(#arFlameGlow)" filter="url(#arBlur)"/>
    <path id="arFlamePath" d="M-5 0 C-5 10 -2 20 0 30 C2 20 5 10 5 0 Z" fill="url(#arFlame)"/>
    <path d="M-2 0 C-2 5 -1 10 0 14 C1 10 2 5 2 0 Z" fill="#fff" opacity="0.9"/>
  </g>
  <g id="arLegFar">
    <path d="M-9 13 L-10 27 Q-10 30 -7 30 L-2 30 Q0 30 0 27 L0 13 Z" fill="url(#arLimbFar)" stroke="${INK}" stroke-width="2.2" stroke-linejoin="round"/>
    <path d="M-11 27 Q-12 35 -5 35 L1 35 Q2.5 35 2 32 L1 27 Z" fill="url(#arBoot)" stroke="${INK}" stroke-width="2.2" stroke-linejoin="round"/>
  </g>
  <g id="arArmFar">
    <path d="M-12 -6 Q-22 -4 -25 5 Q-26 9 -22 10 Q-19 10 -18 6 Q-16 1 -10 0 Z" fill="url(#arLimbFar)" stroke="${INK}" stroke-width="2.2" stroke-linejoin="round"/>
    <circle cx="-23" cy="8.5" r="4" fill="url(#arBoot)" stroke="${INK}" stroke-width="2"/>
  </g>
  <g id="arPackG">
    <rect x="-23" y="-13" width="14" height="30" rx="5" fill="url(#arPack)" stroke="${INK}" stroke-width="2.4"/>
    <rect x="-20.5" y="-10" width="3" height="22" rx="1.5" fill="#fff" opacity="0.35"/>
    <rect x="-19.5" y="16" width="7" height="5" rx="1.5" fill="#5d6390" stroke="${INK}" stroke-width="2"/>
  </g>
  <g id="arBody">
    <path d="M-14 -4 Q-14 -13 -5 -13 L6 -13 Q15 -13 15 -4 L15 12 Q15 19 8 19 L-7 19 Q-14 19 -14 12 Z" fill="url(#arSuit)" stroke="${INK}" stroke-width="2.6" stroke-linejoin="round"/>
    <path d="M-10.5 -6 Q-10 -10 -5 -10.5" stroke="#fff" stroke-width="2.2" fill="none" stroke-linecap="round"/>
    <rect x="-14" y="9" width="29" height="4" fill="#ff8a3d" stroke="${INK}" stroke-width="1.8"/>
    <rect x="-4" y="-5" width="13" height="9" rx="2.2" fill="#3a3f5c" stroke="${INK}" stroke-width="1.8"/>
    <circle id="arLedA" cx="-0.5" cy="-0.5" r="1.5" fill="#2ee6a6"/>
    <circle id="arLedB" cx="4" cy="-0.5" r="1.5" fill="#ff5a7a"/>
  </g>
  <g id="arLegNear">
    <path d="M1 13 L1 27 Q1 30 3 30 L8 30 Q11 30 11 27 L10 13 Z" fill="url(#arLimb)" stroke="${INK}" stroke-width="2.2" stroke-linejoin="round"/>
    <path d="M0 27 L-0.5 32 Q-1 35 1 35 L7 35 Q13 35 12 27 Z" fill="url(#arBoot)" stroke="${INK}" stroke-width="2.2" stroke-linejoin="round"/>
  </g>
  <g id="arHead">
    <path d="M-6 -42 L-10 -50" stroke="${INK}" stroke-width="2" stroke-linecap="round"/>
    <circle id="arAntenna" cx="-10" cy="-50" r="2.4" fill="#ff5a7a" stroke="${INK}" stroke-width="1.6"/>
    <rect x="-9" y="-14.5" width="22" height="5" rx="2.5" fill="#c3c8dc" stroke="${INK}" stroke-width="2"/>
    <circle cx="2" cy="-28" r="17.5" fill="url(#arHelm)" stroke="${INK}" stroke-width="2.6"/>
    <path d="M-10 -36 Q-6 -42 1 -43.5" stroke="#fff" stroke-width="2.4" fill="none" stroke-linecap="round"/>
    <g clip-path="url(#arVisorClip)">
      <ellipse cx="7" cy="-27" rx="13" ry="11" fill="#1d2150"/>
      <g id="arFace">
        <ellipse cx="7.5" cy="-25.5" rx="11.5" ry="10.5" fill="url(#arSkin)"/>
        <ellipse cx="1.5" cy="-21.5" rx="2.2" ry="1.3" fill="#ff8f9a" opacity="0.55"/>
        <ellipse cx="14.5" cy="-21.5" rx="1.8" ry="1.2" fill="#ff8f9a" opacity="0.5"/>
        <path id="arBrowL" d="M1 -33.2 Q3.5 -34.6 6 -33.4" stroke="${INK}" stroke-width="1.3" fill="none" stroke-linecap="round"/>
        <path id="arBrowR" d="M10.5 -33.4 Q12.8 -34.6 15 -33.2" stroke="${INK}" stroke-width="1.3" fill="none" stroke-linecap="round"/>
        <g id="arEyeL"><ellipse cx="3.6" cy="-28.6" rx="2.5" ry="3.1" fill="#fff" stroke="${INK}" stroke-width="0.9"/><circle class="pupil" cx="4.2" cy="-28.4" r="1.45" fill="${INK}"/><circle class="glint" cx="4.8" cy="-29.4" r="0.5" fill="#fff"/></g>
        <g id="arEyeR"><ellipse cx="12.6" cy="-28.6" rx="2.3" ry="2.9" fill="#fff" stroke="${INK}" stroke-width="0.9"/><circle class="pupil" cx="13.2" cy="-28.4" r="1.35" fill="${INK}"/><circle class="glint" cx="13.8" cy="-29.4" r="0.5" fill="#fff"/></g>
        <path id="arLidL" d="M0.8 -31.8 Q3.6 -33 6.4 -31.8 L6.4 -31.8 Q3.6 -32.2 0.8 -31.8 Z" fill="url(#arSkin)" stroke="${INK}" stroke-width="0.9"/>
        <path id="arLidR" d="M10 -31.6 Q12.6 -32.8 15.2 -31.6 L15.2 -31.6 Q12.6 -32 10 -31.6 Z" fill="url(#arSkin)" stroke="${INK}" stroke-width="0.9"/>
        <path id="arMouth" d="M5 -21.5 Q8 -19 11 -21.5" stroke="${INK}" stroke-width="1.4" fill="none" stroke-linecap="round" stroke-linejoin="round"/>
      </g>
      <ellipse cx="7" cy="-27" rx="13" ry="11" fill="url(#arGlass)"/>
      <path d="M-2 -32 Q2 -37 9 -37.5" stroke="#fff" stroke-width="2.2" fill="none" stroke-linecap="round" opacity="0.75"/>
      <rect id="arSheen" x="-12" y="-42" width="4" height="30" fill="#fff" opacity="0.28" transform="skewX(-22)"/>
    </g>
    <ellipse cx="7" cy="-27" rx="13" ry="11" fill="none" stroke="${INK}" stroke-width="2.2"/>
  </g>
  <g id="arArmNear">
    <path d="M12 -7 Q22 -8 27 -16 Q29 -20 26 -21.5 Q23 -22.5 21.5 -19 Q19 -14.5 11 -13 Z" fill="url(#arLimb)" stroke="${INK}" stroke-width="2.2" stroke-linejoin="round"/>
    <circle cx="27" cy="-19.5" r="4.2" fill="url(#arBoot)" stroke="${INK}" stroke-width="2"/>
  </g>
</g>`;

  // Mouth shapes in face coordinates.
  const MOUTH = {
    smile: { d: 'M5 -21.5 Q8 -19 11 -21.5', fill: 'none' },
    grin:  { d: 'M4.4 -22 Q8 -16.4 11.6 -22 Q8 -20.6 4.4 -22 Z', fill: '#7a1f3d' },
    huge:  { d: 'M4 -22.4 Q8 -15 12 -22.4 Q8 -21 4 -22.4 Z', fill: '#7a1f3d' },
    focus: { d: 'M5.6 -21 Q8 -20.2 10.4 -21', fill: 'none' },
    oh:    { d: 'M6.3 -21.2 a1.9 2.5 0 1 0 3.8 0 a1.9 2.5 0 1 0 -3.8 0 Z', fill: '#7a1f3d' },
  };

  let el = null, svg = null, parts = {}, mode = 'idle', speed = 0, cheerAt = -1e9, spin = 0;
  let nextBlink = 1.5, blinkAt = -1, last = 0, started = 0, running = false;
  const sm = { lean: -6, legs: 0, flame: 0.2 }; // smoothed values (springy follow)

  function mount(container) {
    el = container;
    svg = document.createElementNS(NS, 'svg');
    svg.setAttribute('viewBox', '-55 -62 110 110');
    svg.setAttribute('aria-hidden', 'true');
    svg.style.cssText = 'width:100%;height:100%;display:block;overflow:visible';
    svg.innerHTML = ART;
    el.innerHTML = '';
    el.appendChild(svg);
    ['arRoot', 'arJet', 'arFlamePath', 'arLegFar', 'arLegNear', 'arArmFar', 'arArmNear', 'arBody', 'arPackG', 'arHead',
     'arFace', 'arEyeL', 'arEyeR', 'arLidL', 'arLidR', 'arMouth', 'arBrowL', 'arBrowR', 'arAntenna', 'arLedA', 'arLedB', 'arSheen']
      .forEach((id) => { parts[id] = svg.getElementById ? svg.getElementById(id) : svg.querySelector('#' + id); });
    parts.pupils = svg.querySelectorAll('.pupil');
    parts.glints = svg.querySelectorAll('.glint');
    if (!running) { running = true; started = last = performance.now(); requestAnimationFrame(tick); }
  }

  const rot = (a, x, y) => 'rotate(' + a.toFixed(2) + ' ' + x + ' ' + y + ')';
  const follow = (cur, target, rate, dt) => cur + (target - cur) * (1 - Math.exp(-rate * dt));

  function setMouth(name) {
    const m = MOUTH[name];
    if (parts.arMouth.getAttribute('data-m') === name) return;
    parts.arMouth.setAttribute('d', m.d);
    parts.arMouth.setAttribute('fill', m.fill);
    parts.arMouth.setAttribute('data-m', name);
  }

  function tick(now) {
    if (!svg || !svg.isConnected) { running = false; return; }
    const dt = Math.min(0.05, (now - last) / 1000); last = now;
    const t = (now - started) / 1000;
    const cheering = t - cheerAt < 1.5;
    const lost = mode === 'lost';
    const fly = mode === 'fly';

    // Body: lean into the flight, a slow float, a spin when lost.
    const leanTarget = lost ? 0 : fly ? 22 + speed * 14 : -6;
    sm.lean = follow(sm.lean, leanTarget, 2.2, dt);
    if (lost) spin += dt * (380 + 200 * Math.sin(t * 1.3)); else spin = follow(spin, 0, 6, dt);
    const floatY = Math.sin(t * (fly ? 2.4 : 1.4)) * (fly ? 1.4 : 2.2);
    const sway = Math.sin(t * 0.9) * (fly ? 2 : 4);
    let hop = 0;
    if (cheering) { const u = (t - cheerAt) / 1.5; hop = -Math.sin(Math.min(1, u * 2) * Math.PI) * 5; }
    parts.arRoot.setAttribute('transform', 'translate(0 ' + (floatY + hop).toFixed(2) + ') ' + rot(sm.lean + sway + spin, 0, 4));

    // Head lags the body (follow-through), tilts with excitement.
    const headLag = Math.sin(t * (fly ? 2.4 : 1.4) - 0.7) * (fly ? 3 : 4);
    parts.arHead.setAttribute('transform', rot(headLag + (cheering ? Math.sin(t * 10) * 6 : 0) + (lost ? Math.sin(t * 9) * 10 : 0), 2, -12));

    // Legs: trail behind in flight, dangle while waiting, kick when lost; each with its own phase.
    sm.legs = follow(sm.legs, fly ? 12 + speed * 16 : 0, 2.5, dt);
    const lp = t * (fly ? 2.4 : 1.3);
    const kick = lost ? 28 : cheering ? 14 : 0;
    parts.arLegNear.setAttribute('transform', rot(sm.legs + Math.sin(lp - 1.1) * (fly ? 7 : 5) + Math.sin(t * 13) * kick, 5, 13));
    parts.arLegFar.setAttribute('transform', rot(sm.legs + 8 + Math.sin(lp - 1.7) * (fly ? 8 : 6) - Math.sin(t * 13 + 1) * kick, -5, 13));

    // Arms: the near arm reaches ahead in flight and waves on a cheer; the far one swims.
    let near, far;
    if (cheering) { near = -48 + Math.sin(t * 18) * 18; far = 40 + Math.sin(t * 9) * 12; }
    else if (lost) { near = Math.sin(t * 15) * 55 - 20; far = Math.sin(t * 15 + 2) * 55 + 20; }
    else if (fly) { near = -8 + Math.sin(t * 2) * 6 - speed * 10; far = 18 + Math.sin(t * 2 + 1.4) * 10; }
    else {
      const wave = (t % 6) > 4.6 ? Math.sin(((t % 6) - 4.6) / 1.4 * Math.PI * 3) * 26 - 30 : 0;   // a little wave now and then
      near = 22 + Math.sin(t * 1.2) * 6 + wave; far = -6 + Math.sin(t * 1.2 + 1.5) * 7;
    }
    parts.arArmNear.setAttribute('transform', rot(near, 12, -10));
    parts.arArmFar.setAttribute('transform', rot(far, -11, -3));

    // Jet: grows with speed, flickers; sputters out when lost, a gentle idle flame on the pad.
    sm.flame = follow(sm.flame, lost ? 0 : fly ? 0.75 + speed * 0.9 : 0.28, 4, dt);
    const flick = 1 + Math.sin(t * 47) * 0.08 + Math.sin(t * 29) * 0.06;
    parts.arJet.setAttribute('transform', 'translate(-16 21) ' + rot(18, 0, 0) + ' scale(' + (0.85 + sm.flame * 0.25).toFixed(3) + ' ' + Math.max(0.01, sm.flame * flick).toFixed(3) + ')');
    parts.arJet.setAttribute('opacity', Math.min(1, sm.flame * 1.6).toFixed(2));

    // Face: where he looks, blinking, brows and mouth by mood.
    const lookX = fly ? 0.9 : Math.sin(t * 0.7) * 0.9 + Math.sin(t * 1.9) * 0.3;
    const lookY = fly ? -0.5 : Math.sin(t * 0.5) * 0.5;
    parts.pupils.forEach((p) => p.setAttribute('transform', 'translate(' + lookX.toFixed(2) + ' ' + lookY.toFixed(2) + ')'));
    parts.glints.forEach((p) => p.setAttribute('transform', 'translate(' + (lookX * 0.6).toFixed(2) + ' ' + (lookY * 0.6).toFixed(2) + ')'));
    if (t > nextBlink && blinkAt < 0) { blinkAt = t; nextBlink = t + 2.2 + Math.random() * 3.2; }
    let lid = 0;
    if (blinkAt >= 0) { const b = t - blinkAt; lid = b < 0.07 ? b / 0.07 : b < 0.12 ? 1 : b < 0.24 ? 1 - (b - 0.12) / 0.12 : 0; if (b >= 0.24) blinkAt = -1; }
    if (cheering) lid = 0.62;                                  // happy squint
    if (lost) lid = 0;
    const eyeScale = lost ? 1.35 : fly ? 1 + speed * 0.12 : 1;
    parts.arEyeL.setAttribute('transform', 'translate(3.6 -28.6) scale(' + eyeScale + ') translate(-3.6 28.6)');
    parts.arEyeR.setAttribute('transform', 'translate(12.6 -28.6) scale(' + eyeScale + ') translate(-12.6 28.6)');
    // Eyelids: a skin-coloured cap that slides down over each eye.
    const lidL = -31.8 + lid * 6.2, lidR = -31.6 + lid * 5.8;
    parts.arLidL.setAttribute('d', 'M0.8 -31.8 Q3.6 -33 6.4 -31.8 L6.4 ' + lidL.toFixed(2) + ' Q3.6 ' + (lidL + 0.9 * lid).toFixed(2) + ' 0.8 ' + lidL.toFixed(2) + ' Z');
    parts.arLidR.setAttribute('d', 'M10 -31.6 Q12.6 -32.8 15.2 -31.6 L15.2 ' + lidR.toFixed(2) + ' Q12.6 ' + (lidR + 0.9 * lid).toFixed(2) + ' 10 ' + lidR.toFixed(2) + ' Z');
    const browUp = lost ? -1.8 : cheering ? -1.2 : fly ? -0.4 - speed * 0.8 : 0;
    parts.arBrowL.setAttribute('transform', 'translate(0 ' + browUp + ')' + (lost ? ' ' + rot(-10, 3.5, -33.8) : ''));
    parts.arBrowR.setAttribute('transform', 'translate(0 ' + browUp + ')' + (lost ? ' ' + rot(10, 12.8, -33.8) : ''));
    setMouth(lost ? 'oh' : cheering ? 'huge' : fly ? (speed > 0.45 ? 'grin' : 'focus') : 'smile');

    // Little lights: the antenna and chest LEDs blink; a sheen crosses the visor every few seconds.
    parts.arAntenna.setAttribute('fill', (t % 1.2) < 0.6 ? '#ff5a7a' : '#ffd0d8');
    parts.arLedA.setAttribute('opacity', (0.55 + 0.45 * Math.sin(t * 3)).toFixed(2));
    parts.arLedB.setAttribute('opacity', (t % 0.9) < 0.45 ? '1' : '0.3');
    const sh = (t % 5) / 5;
    parts.arSheen.setAttribute('x', (sh < 0.7 ? -12 : -12 + (sh - 0.7) / 0.3 * 40).toFixed(1));

    requestAnimationFrame(tick);
  }

  window.AstroRig = {
    mount,
    setMode(m) { if (m !== mode) { mode = m; if (m !== 'lost') spin = spin % 360; } },
    setSpeed(v) { speed = Math.max(0, Math.min(1, +v || 0)); },
    cheer() { cheerAt = (performance.now() - started) / 1000; },
  };
})();
