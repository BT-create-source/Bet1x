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

  // Metallic, faceless: brushed-titanium suit, gunmetal joints, an anodised pack with a copper stripe,
  // and a mirrored gold visor reflecting the sky. Only gradients, no SVG filters, so animating every
  // frame stays cheap on phones (filters were re-rendered each frame and caused the lag).
  const ART = `
<defs>
  <linearGradient id="arTi" x1="0" y1="0" x2="1" y2="0.35">
    <stop offset="0" stop-color="#fbfcfe"/><stop offset="0.28" stop-color="#e3e7ef"/><stop offset="0.55" stop-color="#b9c0cf"/>
    <stop offset="0.78" stop-color="#8d95a9"/><stop offset="1" stop-color="#5f667b"/></linearGradient>
  <linearGradient id="arTiDark" x1="0" y1="0" x2="1" y2="0.35">
    <stop offset="0" stop-color="#c6ccda"/><stop offset="0.6" stop-color="#8a92a6"/><stop offset="1" stop-color="#4d5367"/></linearGradient>
  <linearGradient id="arGun" x1="0" y1="0" x2="0" y2="1">
    <stop offset="0" stop-color="#6b7183"/><stop offset="0.45" stop-color="#3b404f"/><stop offset="1" stop-color="#1c1f29"/></linearGradient>
  <linearGradient id="arPackM" x1="0" y1="0" x2="1" y2="1">
    <stop offset="0" stop-color="#9aa2b6"/><stop offset="0.4" stop-color="#5d6478"/><stop offset="1" stop-color="#2b2f3c"/></linearGradient>
  <linearGradient id="arCopper" x1="0" y1="0" x2="1" y2="0">
    <stop offset="0" stop-color="#ffcf8a"/><stop offset="0.5" stop-color="#e08a36"/><stop offset="1" stop-color="#9c4f15"/></linearGradient>
  <radialGradient id="arShell" cx="34%" cy="28%" r="78%">
    <stop offset="0" stop-color="#ffffff"/><stop offset="0.45" stop-color="#e6e9f1"/><stop offset="0.8" stop-color="#a9b0c2"/><stop offset="1" stop-color="#6d7489"/></radialGradient>
  <linearGradient id="arVisor" x1="0.1" y1="0" x2="0.8" y2="1">
    <stop offset="0" stop-color="#fff6d6"/><stop offset="0.16" stop-color="#f1c45c"/><stop offset="0.42" stop-color="#b7741f"/>
    <stop offset="0.7" stop-color="#4a2709"/><stop offset="1" stop-color="#1a0c03"/></linearGradient>
  <linearGradient id="arHorizon" x1="0" y1="0" x2="0" y2="1">
    <stop offset="0" stop-color="#8f7bff" stop-opacity="0"/><stop offset="0.5" stop-color="#b9a8ff" stop-opacity="0.55"/><stop offset="1" stop-color="#8f7bff" stop-opacity="0"/></linearGradient>
  <linearGradient id="arFlame" x1="0" y1="0" x2="0" y2="1">
    <stop offset="0" stop-color="#ffffff"/><stop offset="0.18" stop-color="#d9f2ff"/><stop offset="0.45" stop-color="#7fb2ff"/>
    <stop offset="0.75" stop-color="#8a5cff" stop-opacity="0.55"/><stop offset="1" stop-color="#8a5cff" stop-opacity="0"/></linearGradient>
  <radialGradient id="arFlameGlow"><stop offset="0" stop-color="#bfe1ff" stop-opacity="0.75"/><stop offset="0.55" stop-color="#8a7bff" stop-opacity="0.25"/><stop offset="1" stop-color="#8a5cff" stop-opacity="0"/></radialGradient>
  <clipPath id="arVisorClip"><path d="M-7 -27.5 Q-6 -37.5 6 -38 Q18.5 -37.5 19 -27.5 Q18.4 -18 6 -17.4 Q-6.4 -18 -7 -27.5 Z"/></clipPath>
</defs>
<g id="arRoot">
  <g id="arJet">
    <ellipse cx="0" cy="13" rx="10" ry="17" fill="url(#arFlameGlow)"/>
    <path id="arFlamePath" d="M-4.6 0 C-4.4 10 -1.8 21 0 31 C1.8 21 4.4 10 4.6 0 Z" fill="url(#arFlame)"/>
    <path d="M-1.8 0 C-1.6 5 -0.8 10 0 14 C0.8 10 1.6 5 1.8 0 Z" fill="#fff" opacity="0.92"/>
  </g>
  <g id="arLegFar">
    <path d="M-9 13 L-10 26 Q-10 29.5 -7 29.5 L-2 29.5 Q0 29.5 0 26.5 L0 13 Z" fill="url(#arTiDark)" stroke="#20232d" stroke-width="0.9"/>
    <rect x="-10.2" y="19" width="10.4" height="2.6" rx="1.2" fill="url(#arGun)"/>
    <path d="M-11 27 Q-12 35 -5 35 L1 35 Q2.5 35 2 32 L1 27 Z" fill="url(#arGun)" stroke="#14161d" stroke-width="0.9"/>
  </g>
  <g id="arArmFar">
    <path d="M-12 -6 Q-22 -4 -25 5 Q-26 9 -22 10 Q-19 10 -18 6 Q-16 1 -10 0 Z" fill="url(#arTiDark)" stroke="#20232d" stroke-width="0.9"/>
    <circle cx="-23" cy="8.5" r="3.8" fill="url(#arGun)" stroke="#14161d" stroke-width="0.8"/>
  </g>
  <g id="arPackG">
    <rect x="-23.5" y="-13.5" width="15" height="31" rx="4" fill="url(#arPackM)" stroke="#14161d" stroke-width="1"/>
    <rect x="-21.6" y="-10.5" width="2.2" height="24" rx="1.1" fill="#fff" opacity="0.32"/>
    <rect x="-17.4" y="-11" width="2" height="25" fill="url(#arCopper)"/>
    <g fill="#14161d" opacity="0.55"><rect x="-14" y="2" width="4" height="0.9"/><rect x="-14" y="4" width="4" height="0.9"/><rect x="-14" y="6" width="4" height="0.9"/></g>
    <path d="M-20 17.5 L-12 17.5 L-13 21.5 L-19 21.5 Z" fill="url(#arGun)"/>
  </g>
  <g id="arBody">
    <path d="M-14 -4 Q-14 -13 -5 -13 L6 -13 Q15 -13 15 -4 L15 12 Q15 19 8 19 L-7 19 Q-14 19 -14 12 Z" fill="url(#arTi)" stroke="#20232d" stroke-width="1"/>
    <path d="M-11 -7 Q-10.4 -10.6 -5 -11" stroke="#fff" stroke-width="1.6" fill="none" stroke-linecap="round" opacity="0.9"/>
    <path d="M-6 -12.5 L-6 9 M8 -12.5 L8 9" stroke="#5f667b" stroke-opacity="0.35" stroke-width="0.6"/>
    <rect x="-14" y="9" width="29" height="3.8" fill="url(#arGun)"/>
    <rect x="-3.5" y="-5" width="12" height="8.6" rx="1.6" fill="url(#arGun)" stroke="#0e1016" stroke-width="0.8"/>
    <rect x="-2.3" y="-3.8" width="5" height="3" rx="0.6" fill="#0b1a24"/>
    <rect id="arLedA" x="-2" y="-3.5" width="4.4" height="2.4" rx="0.5" fill="#39e6b8"/>
    <circle id="arLedB" cx="6" cy="-2.4" r="1" fill="#ff8a3d"/>
    <rect x="-2.3" y="1" width="9.6" height="0.9" rx="0.45" fill="#8a92a6"/>
  </g>
  <g id="arLegNear">
    <path d="M1 13 L1 26.5 Q1 29.5 3 29.5 L8 29.5 Q11 29.5 11 26 L10 13 Z" fill="url(#arTi)" stroke="#20232d" stroke-width="0.9"/>
    <rect x="0.6" y="19" width="10.4" height="2.6" rx="1.2" fill="url(#arGun)"/>
    <path d="M0 27 L-0.5 32 Q-1 35 1 35 L7 35 Q13 35 12 27 Z" fill="url(#arGun)" stroke="#14161d" stroke-width="0.9"/>
  </g>
  <g id="arHead">
    <rect x="-9" y="-14.5" width="22" height="4.6" rx="2.3" fill="url(#arGun)"/>
    <circle cx="2" cy="-28" r="17.5" fill="url(#arShell)" stroke="#20232d" stroke-width="1"/>
    <path d="M-11 -36.5 Q-6.5 -43 1 -44" stroke="#fff" stroke-width="1.8" fill="none" stroke-linecap="round" opacity="0.95"/>
    <rect x="-16.2" y="-32" width="3.6" height="7" rx="1.4" fill="url(#arGun)"/>
    <rect id="arAntenna" x="-15.6" y="-30.6" width="2.4" height="1.6" rx="0.6" fill="#e9fbff"/>
    <path d="M-7.8 -27.5 Q-6.8 -38.3 6 -38.8 Q19.3 -38.3 19.8 -27.5 Q19.2 -17.2 6 -16.6 Q-7.2 -17.2 -7.8 -27.5 Z" fill="url(#arGun)"/>
    <g clip-path="url(#arVisorClip)">
      <rect x="-8" y="-39" width="28" height="22" fill="url(#arVisor)"/>
      <rect x="-8" y="-27" width="28" height="5" fill="url(#arHorizon)"/>
      <ellipse cx="13" cy="-17" rx="12" ry="5" fill="#5b47d6" opacity="0.35"/>
      <circle cx="10" cy="-33" r="0.45" fill="#fff" opacity="0.85"/><circle cx="14.5" cy="-30" r="0.35" fill="#fff" opacity="0.7"/>
      <path d="M-4.5 -29 Q-2 -35.5 6.5 -36" stroke="#fff8e6" stroke-width="2.1" fill="none" stroke-linecap="round" opacity="0.85"/>
      <path d="M-4 -24 Q-3.4 -26.4 -2.2 -27.4" stroke="#fff8e6" stroke-width="0.9" fill="none" stroke-linecap="round" opacity="0.6"/>
      <rect id="arSheen" x="-12" y="-42" width="4" height="30" fill="#fff" opacity="0.3" transform="skewX(-22)"/>
    </g>
  </g>
  <g id="arArmNear">
    <path d="M12 -7 Q22 -8 27 -16 Q29 -20 26 -21.5 Q23 -22.5 21.5 -19 Q19 -14.5 11 -13 Z" fill="url(#arTi)" stroke="#20232d" stroke-width="0.9"/>
    <path d="M19.6 -12.6 Q21.4 -14.4 22.6 -16.8" stroke="#3b404f" stroke-width="2.4" fill="none"/>
    <circle cx="27" cy="-19.5" r="4" fill="url(#arGun)" stroke="#14161d" stroke-width="0.8"/>
    <path d="M24.6 -21.4 Q26.6 -23 28.6 -22" stroke="#fff" stroke-width="0.7" fill="none" opacity="0.55" stroke-linecap="round"/>
  </g>
</g>`;

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
     'arAntenna', 'arLedA', 'arLedB', 'arSheen']
      .forEach((id) => { parts[id] = svg.getElementById ? svg.getElementById(id) : svg.querySelector('#' + id); });
    if (!running) { running = true; started = last = performance.now(); requestAnimationFrame(tick); }
  }

  const rot = (a, x, y) => 'rotate(' + a.toFixed(2) + ' ' + x + ' ' + y + ')';
  const follow = (cur, target, rate, dt) => cur + (target - cur) * (1 - Math.exp(-rate * dt));


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

    // Little lights: the antenna and chest LEDs blink; a sheen crosses the visor every few seconds.
    parts.arAntenna.setAttribute('opacity', (t % 1.6) < 0.9 ? '1' : '0.45');
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
