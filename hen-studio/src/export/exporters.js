/**
 * Export formats. All of them render the character from a clean copy of the master artwork posed
 * at exact times — never the preview, its background, guides or debug overlay.
 *
 *   A  Animated SVG         CSS @keyframes (one per moving joint) sampled from the clip.
 *   B  PNG sprite sheet     transparent grid of frames + a JSON manifest.
 *   C  PNG frames (ZIP)     clip_000.png, clip_001.png ... + the same manifest.
 *   D  Video                not rendered in the browser; see README (ffmpeg from the PNG frames).
 */

import { CLIPS } from '../animation/clips.js';
import { evaluateClip } from '../animation/controller.js';
import { JOINTS, LASHES, GROUND_Y } from '../rig/skeleton.js';
import { jointMatrix, wingEdgeOpacity, lashOpacity } from '../rig/rig.js';
import { FRAME_BOX, poseToSvgString, svgToCanvas, frameHeightFor, canvasToBlob } from './render.js';
import { makeZip } from './zip.js';

/**
 * The times at which to sample a clip. A loop gets N frames covering [0, duration) so frame N would
 * equal frame 0 (no doubled frame at the seam); a one-shot gets both ends, start and finish at rest.
 */
export function frameTimes(clipId, fps, params) {
  const clip = CLIPS[clipId];
  const dur = clip.duration / (clip.kind === 'base' && clipId !== 'idle' ? (params?.walkSpeed || 1) : 1);
  if (clip.loop) {
    const n = Math.max(1, Math.round(dur * fps));
    return { duration: dur, times: Array.from({ length: n }, (_, i) => (i * dur) / n) };
  }
  const n = Math.max(2, Math.round(dur * fps) + 1);
  return { duration: dur, times: Array.from({ length: n }, (_, i) => Math.min(dur, (i * dur) / (n - 1))) };
}

/** Render every frame of a clip to transparent canvases. */
export async function renderFrames(template, clipId, { fps = 24, width = 256, shadow = true, params, onProgress } = {}) {
  const height = frameHeightFor(width);
  const { duration, times } = frameTimes(clipId, fps, params);
  const frames = [];
  for (let i = 0; i < times.length; i++) {
    const svg = poseToSvgString(template, evaluateClip(clipId, times[i], params), { width, height, shadow });
    frames.push({ t: times[i], canvas: await svgToCanvas(svg, width, height) });
    if (onProgress) onProgress(i + 1, times.length);
  }
  return { frames, width, height, duration };
}

function manifestFor(clipId, fps, r, extra = {}) {
  return {
    generator: 'hen-studio',
    clip: clipId,
    label: CLIPS[clipId].label,
    loop: CLIPS[clipId].loop,
    fps,
    duration: +r.duration.toFixed(4),
    frameCount: r.frames.length,
    frameWidth: r.width,
    frameHeight: r.height,
    // Where the hen's feet meet the ground inside every frame — anchor the sprite here in a game.
    groundAnchor: {
      x: Math.round(((690 - FRAME_BOX.x) / FRAME_BOX.w) * r.width),
      y: Math.round(((GROUND_Y - FRAME_BOX.y) / FRAME_BOX.h) * r.height),
    },
    artworkBox: FRAME_BOX,
    ...extra,
  };
}

/** B: one transparent PNG with every frame in a grid, plus its manifest. */
export async function exportSpriteSheet(template, clipId, opts = {}) {
  const fps = opts.fps || 24;
  const r = await renderFrames(template, clipId, opts);
  const n = r.frames.length;
  const cols = opts.columns || Math.ceil(Math.sqrt(n));
  const rows = Math.ceil(n / cols);
  const sheet = document.createElement('canvas');
  sheet.width = cols * r.width;
  sheet.height = rows * r.height;
  const ctx = sheet.getContext('2d');
  ctx.clearRect(0, 0, sheet.width, sheet.height);
  const frames = r.frames.map((f, i) => {
    const x = (i % cols) * r.width, y = Math.floor(i / cols) * r.height;
    ctx.drawImage(f.canvas, x, y);
    return { index: i, x, y, w: r.width, h: r.height, t: +f.t.toFixed(4) };
  });
  const png = await canvasToBlob(sheet);
  const manifest = manifestFor(clipId, fps, r, {
    image: `${clipId}_sheet.png`, sheetWidth: sheet.width, sheetHeight: sheet.height, columns: cols, rows,
    order: 'row-major, left to right then top to bottom', frames,
  });
  return { png, manifest };
}

/** C: numbered transparent PNG frames in one ZIP, plus the manifest inside it. */
export async function exportFramesZip(template, clipId, opts = {}) {
  const fps = opts.fps || 24;
  const r = await renderFrames(template, clipId, opts);
  const files = [];
  const names = [];
  for (let i = 0; i < r.frames.length; i++) {
    const name = `${clipId}_${String(i).padStart(3, '0')}.png`;
    const blob = await canvasToBlob(r.frames[i].canvas);
    files.push({ name, data: new Uint8Array(await blob.arrayBuffer()) });
    names.push({ index: i, file: name, t: +r.frames[i].t.toFixed(4) });
  }
  const manifest = manifestFor(clipId, fps, r, { frames: names });
  files.push({ name: `${clipId}_manifest.json`, data: new TextEncoder().encode(JSON.stringify(manifest, null, 2)) });
  return { zip: makeZip(files), manifest };
}

/**
 * A: a self-contained animated SVG. Each moving joint gets a CSS @keyframes track of 2D matrices
 * sampled from the clip (opacity tracks for the wing edge and the lash lines). Loops play forever;
 * a one-shot plays, rests for `restAfter` seconds, and repeats. Plays in any modern browser; game
 * engines generally do not run CSS animation inside SVG — use the PNG exports there.
 */
export function exportAnimatedSvg(template, clipId, { fps = 30, size = 512, params, restAfter = 0.8, shadow = true } = {}) {
  const clip = CLIPS[clipId];
  const { duration, times } = frameTimes(clipId, fps, params);
  const total = clip.loop ? duration : duration + restAfter;
  const samples = times.map((t) => ({ t, pose: evaluateClip(clipId, t, params) }));
  if (clip.loop) samples.push({ t: duration, pose: samples[0].pose });           // close the loop exactly
  else samples.push({ t: total, pose: samples[samples.length - 1].pose });       // hold at rest

  const pct = (t) => `${((t / total) * 100).toFixed(3)}%`;
  const css = [];
  const ids = Object.keys(JOINTS).filter((id) =>
    samples.some((s) => jointMatrix(id, s.pose[id] || {}).join() !== '1,0,0,1,0,0'));
  for (const id of ids) {
    const kf = samples.map((s) => `${pct(s.t)}{transform:matrix(${jointMatrix(id, s.pose[id] || {}).join(',')})}`).join('');
    css.push(`#${id}{transform-box:view-box;transform-origin:0 0;animation:k-${id} ${total.toFixed(3)}s linear infinite}`,
             `@keyframes k-${id}{${kf}}`);
  }
  const opacityTracks = { wingEdge: (p) => wingEdgeOpacity(p) };
  for (const lash of Object.keys(LASHES)) opacityTracks[lash] = (p) => lashOpacity(p, lash);
  for (const [id, fn] of Object.entries(opacityTracks)) {
    if (!samples.some((s) => fn(s.pose) > 0)) continue;
    css.push(`#${id}{animation:o-${id} ${total.toFixed(3)}s linear infinite}`,
             `@keyframes o-${id}{${samples.map((s) => `${pct(s.t)}{opacity:${fn(s.pose)}}`).join('')}}`);
  }

  const svg = template.cloneNode(true);
  const box = FRAME_BOX;
  svg.setAttribute('viewBox', `${box.x} ${box.y} ${box.w} ${box.h}`);
  svg.setAttribute('width', String(size));
  svg.setAttribute('height', String(frameHeightFor(size)));
  if (!shadow) svg.querySelector('#shadow')?.remove();
  const style = document.createElementNS('http://www.w3.org/2000/svg', 'style');
  style.textContent = `/* hen-studio: ${clip.label}, ${total.toFixed(2)} s, sampled at ${fps} fps */\n` + css.join('\n');
  svg.insertBefore(style, svg.firstChild);
  return new XMLSerializer().serializeToString(svg);
}

/** Save a Blob or string as a download. */
export function download(data, filename, type = 'application/octet-stream') {
  const blob = data instanceof Blob ? data : new Blob([data], { type });
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url; a.download = filename;
  document.body.appendChild(a); a.click(); a.remove();
  setTimeout(() => URL.revokeObjectURL(url), 2000);
}
