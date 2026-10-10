/**
 * Render the CHARACTER ONLY (never the preview UI) into standalone SVG strings and transparent
 * canvases, at an exact pose. Every exporter goes through here, so all formats share one framing
 * and one scale.
 */

import { createRig } from '../rig/rig.js';

/**
 * One frame box for every clip: big enough for the peck's furthest reach (beak ~x 1296, y 940),
 * the startle hop (comb ~y -45) and the raised wing, so nothing is ever cropped and the hen is the
 * same size in every frame of every export. In artwork units.
 */
export const FRAME_BOX = { x: 60, y: -190, w: 1440, h: 1440 };   // headroom for exaggerated jumps

/** Serialize the artwork posed at `pose` as a self-contained SVG string. */
export function poseToSvgString(templateSvg, pose, { box = FRAME_BOX, width, height, background = null, shadow = true } = {}) {
  const svg = templateSvg.cloneNode(true);
  createRig(svg).apply(pose);
  svg.setAttribute('viewBox', `${box.x} ${box.y} ${box.w} ${box.h}`);
  svg.setAttribute('width', String(width));
  svg.setAttribute('height', String(height));
  svg.removeAttribute('style');
  svg.removeAttribute('class');
  const sh = svg.querySelector('#shadow');
  if (sh && !shadow) sh.remove();
  if (background) {
    const r = document.createElementNS('http://www.w3.org/2000/svg', 'rect');
    r.setAttribute('x', box.x); r.setAttribute('y', box.y); r.setAttribute('width', box.w); r.setAttribute('height', box.h);
    r.setAttribute('fill', background);
    svg.insertBefore(r, svg.firstChild);
  }
  return new XMLSerializer().serializeToString(svg);
}

/** Rasterize an SVG string onto a fresh transparent canvas of the given size. */
export function svgToCanvas(svgString, width, height) {
  return new Promise((resolve, reject) => {
    const img = new Image();
    const url = URL.createObjectURL(new Blob([svgString], { type: 'image/svg+xml' }));
    img.onload = () => {
      const c = document.createElement('canvas');
      c.width = width; c.height = height;
      const ctx = c.getContext('2d');
      ctx.clearRect(0, 0, width, height);
      ctx.drawImage(img, 0, 0, width, height);
      URL.revokeObjectURL(url);
      resolve(c);
    };
    img.onerror = () => { URL.revokeObjectURL(url); reject(new Error('Frame failed to rasterize')); };
    img.src = url;
  });
}

/** Frame height for a given width, keeping the frame box's proportions. */
export const frameHeightFor = (width) => Math.round(width * FRAME_BOX.h / FRAME_BOX.w);

export function canvasToBlob(canvas, type = 'image/png') {
  return new Promise((resolve) => canvas.toBlob(resolve, type));
}
