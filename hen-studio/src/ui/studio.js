/**
 * Hen Studio: the preview page. Loads the master artwork, binds the rig and the animation
 * controller, and wires the controls. All animation logic lives in src/animation; all drawing of
 * the character in src/rig; this file only connects them to the page.
 */

import { loadHenSvg, createRig } from '../rig/rig.js';
import { GROUND_Y } from '../rig/skeleton.js';
import { CLIPS } from '../animation/clips.js';
import { HenController } from '../animation/controller.js';
import { FRAME_BOX } from '../export/render.js';
import { exportSpriteSheet, exportFramesZip, exportAnimatedSvg, download } from '../export/exporters.js';
import { drawDebug } from './debug.js';

const $ = (id) => document.getElementById(id);
const NS = 'http://www.w3.org/2000/svg';
const VIEWBOX = `${FRAME_BOX.x} ${FRAME_BOX.y} ${FRAME_BOX.w} ${FRAME_BOX.h}`;

const template = await loadHenSvg('src/character/hen.svg');   // pristine copy, used by exports
const live = template.cloneNode(true);
live.setAttribute('viewBox', VIEWBOX);
live.removeAttribute('width'); live.removeAttribute('height');
live.style.overflow = 'visible';                               // lets her walk past the frame edge
live.setAttribute('role', 'img');
live.setAttribute('aria-label', 'Animated hen');
$('henMount').appendChild(live);
for (const id of ['refOverlay', 'guides', 'debug']) $(id).setAttribute('viewBox', VIEWBOX);

const rig = createRig(live);
const ctl = new HenController(rig);

// ---- overlays -------------------------------------------------------------------------------------
const ref = document.createElementNS(NS, 'image');
ref.setAttribute('href', 'reference/hen-reference.jpg');
ref.setAttribute('x', 0); ref.setAttribute('y', 0); ref.setAttribute('width', 1254); ref.setAttribute('height', 1254);
$('refOverlay').appendChild(ref);

const ground = document.createElementNS(NS, 'line');
ground.setAttribute('x1', -4000); ground.setAttribute('x2', 6000);
ground.setAttribute('y1', GROUND_Y); ground.setAttribute('y2', GROUND_Y);
ground.setAttribute('stroke', '#7a8496'); ground.setAttribute('stroke-width', 3); ground.setAttribute('stroke-dasharray', '14 10');
$('guides').appendChild(ground);

function syncOverlays() {
  $('guides').style.display = $('optGround').checked ? '' : 'none';
  $('debug').style.display = $('optDebug').checked ? '' : 'none';
  $('refOverlay').style.display = $('optRef').checked ? '' : 'none';
  if (!$('optDebug').checked) $('debug').textContent = '';
}
for (const id of ['optGround', 'optDebug', 'optRef']) $(id).addEventListener('change', syncOverlays);
syncOverlays();

// ---- stage size -------------------------------------------------------------------------------------
function layoutStage() {
  const s = parseFloat($('sScale').value);
  const w = Math.round(FRAME_BOX.w * s), h = Math.round(FRAME_BOX.h * s);
  $('stageInner').style.width = w + 'px';
  $('stageInner').style.height = h + 'px';
  // Walking across: wrap over the visible stage width (in artwork units) plus a body length.
  ctl.travelSpan = ($('stage').clientWidth / s) + 900;
  $('sScale').nextElementSibling.textContent = Math.round(s * 100) + '%';
}
window.addEventListener('resize', layoutStage);

document.querySelectorAll('[data-bg]').forEach((b) => b.addEventListener('click', () => {
  document.querySelectorAll('[data-bg]').forEach((x) => x.classList.toggle('on', x === b));
  $('stage').className = 'stage bg-' + b.dataset.bg;
}));

// ---- clips ------------------------------------------------------------------------------------------
const clipButtons = {};
for (const [id, clip] of Object.entries(CLIPS)) {
  const b = document.createElement('button');
  b.type = 'button';
  b.innerHTML = `<span>${clip.label}</span><small>${clip.loop ? 'loop' : clip.duration.toFixed(2) + ' s'}</small>`;
  b.setAttribute('aria-pressed', 'false');
  b.addEventListener('click', () => { ctl.play(id); if (!ctl.playing) togglePlay(true); });
  $('clips').appendChild(b);
  clipButtons[id] = b;
  const o = document.createElement('option');
  o.value = id; o.textContent = clip.label;
  $('eClip').appendChild(o);
}
$('eClip').value = 'walk';

function syncState() {
  for (const id in clipButtons) {
    const on = id === ctl.base.id || (ctl.action && id === ctl.action.id);
    clipButtons[id].setAttribute('aria-pressed', on ? 'true' : 'false');
  }
  const parts = [`Base: ${CLIPS[ctl.base.id].label}`];
  if (ctl.prev) parts.push(`blending from ${CLIPS[ctl.prev.id].label}`);
  if (ctl.action) parts.push(`playing: ${CLIPS[ctl.action.id].label}`);
  if (!ctl.playing) parts.push('paused');
  $('state').textContent = parts.join(' · ');
}
ctl.onChange(syncState);

// ---- playback & tuning ------------------------------------------------------------------------------
function togglePlay(force) {
  ctl.playing = force === undefined ? !ctl.playing : force;
  $('btnPlay').textContent = ctl.playing ? 'Pause' : 'Play';
  syncState();
}
$('btnPlay').addEventListener('click', () => togglePlay());
$('btnRestart').addEventListener('click', () => ctl.restart());
$('btnReset').addEventListener('click', () => { ctl.reset(); $('optTravel').checked = false; ctl.travel = false; ctl.render(); });
$('optTravel').addEventListener('change', (e) => { ctl.travel = e.target.checked; });

function bindSlider(id, apply, fmt = (v) => v.toFixed(2) + '×') {
  const input = $(id), out = input.nextElementSibling;
  const run = () => { const v = parseFloat(input.value); apply(v); out.textContent = fmt(v); };
  input.addEventListener('input', run);
  run();
}
bindSlider('sSpeed', (v) => { ctl.speed = v; });
bindSlider('sScale', () => layoutStage(), (v) => Math.round(v * 100) + '%');
bindSlider('sWalk', (v) => { ctl.params.walkSpeed = v; });
bindSlider('sBob', (v) => { ctl.params.bob = v; });
bindSlider('sHead', (v) => { ctl.params.head = v; });
bindSlider('sFlap', (v) => { ctl.params.flap = v; });

// Keyboard: space toggles play, R resets.
document.addEventListener('keydown', (e) => {
  if (e.target.closest('input, select, button')) return;
  if (e.code === 'Space') { e.preventDefault(); togglePlay(); }
  if (e.key === 'r' || e.key === 'R') ctl.reset();
});

// ---- the loop ---------------------------------------------------------------------------------------
let last = performance.now();
function frame(now) {
  const dt = Math.min(0.1, (now - last) / 1000);   // a background tab must not fast-forward on return
  last = now;
  ctl.update(dt);
  ctl.render();
  if ($('optDebug').checked) drawDebug($('debug'), live, rig);
  requestAnimationFrame(frame);
}
layoutStage();
syncState();
requestAnimationFrame(frame);

// ---- exports ----------------------------------------------------------------------------------------
function exportOptions() {
  return {
    fps: parseInt($('eFps').value, 10),
    width: parseInt($('eWidth').value, 10),
    shadow: $('eShadow').checked,
    params: { ...ctl.params },
  };
}
async function runExport(btn, job) {
  const buttons = ['xSheet', 'xFrames', 'xSvg'].map($);
  buttons.forEach((b) => { b.disabled = true; });
  const msg = $('exportMsg');
  try {
    await job((done, total) => { msg.textContent = `Rendering frame ${done} of ${total}…`; });
  } catch (err) {
    msg.textContent = 'Export failed: ' + err.message;
    throw err;
  } finally {
    buttons.forEach((b) => { b.disabled = false; });
  }
}
$('xSheet').addEventListener('click', () => runExport($('xSheet'), async (onProgress) => {
  const clip = $('eClip').value;
  const { png, manifest } = await exportSpriteSheet(template, clip, { ...exportOptions(), onProgress });
  download(png, `${clip}_sheet.png`);
  download(JSON.stringify(manifest, null, 2), `${clip}_sheet.json`, 'application/json');
  $('exportMsg').textContent = `Saved ${clip}_sheet.png (${manifest.sheetWidth}×${manifest.sheetHeight}, ${manifest.frameCount} frames of ${manifest.frameWidth}×${manifest.frameHeight}) and its JSON.`;
}));
$('xFrames').addEventListener('click', () => runExport($('xFrames'), async (onProgress) => {
  const clip = $('eClip').value;
  const { zip, manifest } = await exportFramesZip(template, clip, { ...exportOptions(), onProgress });
  download(zip, `${clip}_frames.zip`);
  $('exportMsg').textContent = `Saved ${clip}_frames.zip: ${manifest.frameCount} PNGs (${clip}_000.png …) of ${manifest.frameWidth}×${manifest.frameHeight}, plus ${clip}_manifest.json.`;
}));
$('xSvg').addEventListener('click', () => runExport($('xSvg'), async () => {
  const clip = $('eClip').value;
  const o = exportOptions();
  const svg = exportAnimatedSvg(template, clip, { fps: Math.min(o.fps, 30), size: o.width * 2, params: o.params, shadow: o.shadow });
  download(svg, `${clip}_animated.svg`, 'image/svg+xml');
  $('exportMsg').textContent = `Saved ${clip}_animated.svg (${Math.round(svg.length / 1024)} KB, CSS-animated).`;
}));

// Exposed for automated checks (tests/export-check) — not used by the page itself.
window.HenStudio = { ctl, rig, template, exportSpriteSheet, exportFramesZip, exportAnimatedSvg };
