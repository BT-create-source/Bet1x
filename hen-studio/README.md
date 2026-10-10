# Hen Studio

A rigged, code-animated 2D version of the reference hen (`reference/hen-reference.jpg`), with a
browser preview and exporters for sprite sheets, PNG frames, animated SVG and (via ffmpeg) video.

No build step and no dependencies: plain HTML, CSS and ES modules. Python with OpenCV is needed only
to re-trace the artwork.

## Run it

ES modules need to be served over HTTP (opening `index.html` as a file will not work). Either:

```bash
# from the repository root (the PHP dev server the rest of the site uses)
php -S 127.0.0.1:5130 router.php
# then open http://127.0.0.1:5130/hen-studio/
```

or any static server from this folder:

```bash
cd hen-studio
python -m http.server 8080
# then open http://127.0.0.1:8080/
```

## Using the preview

- **Animation**: Idle, Walk and Run are looping *base* clips; switching between them cross-fades
  over 0.3 s. Peck, Blink, Wing flap, Look around and React are one-shots layered on top of the
  current base. Press one again to replay it. The highlighted buttons show what is playing, and the
  line under the title states the base, any blend in progress and any action.
- **Playback**: Pause/Play (or Space), Restart clip, Reset to neutral (or R). *Walk / run across the
  stage* moves the hen at exactly the speed her planted foot slides back, so the stance foot stays
  put on the ground. Otherwise she walks in place.
- **Tuning**: global speed, walk-cycle speed, body-bob, head-motion and wing amplitude. The defaults
  are the intended look; the sliders are for refinement.
- **Stage**: neutral, transparent (checkerboard) or dark background; ground guide; **Rig debug**
  (pivots, bones, part bounding boxes, root, ground — measured from the live transformed SVG);
  **Reference overlay** (the original image at 40% under the hen, for checking fidelity at rest).
- `tools/contact-sheet.html` renders evenly spaced frames of any clip side by side with the ground
  line through every cell: the quickest way to check foot contact and loop seams.

## How it is built

| Path | What it is |
|---|---|
| `reference/hen-reference.jpg` | The supplied reference (1254 × 1254). |
| `tools/trace_hen.py` | Traces the reference into the layered master SVG (see below). |
| `src/character/hen.svg` | **Generated** master artwork. Group ids are joint names. Do not hand-edit; re-run the tracer. |
| `src/rig/skeleton.js` | Joints, pivots and hierarchy; eyelid travel; pupil gaze limits. All coordinates live here. |
| `src/rig/rig.js` | Turns a pose into one transform per joint group (and the matrix form used by the SVG export). |
| `src/animation/curves.js` | Easing, keyframe tracks, the blink curve. |
| `src/animation/clips.js` | The eight clips, each a pure function of time. |
| `src/animation/controller.js` | State controller: base cross-fades, action layering and interruption, blinks, speed, travel. |
| `src/export/render.js` | Renders the character alone (never the UI) to SVG strings and transparent canvases. |
| `src/export/exporters.js` | Sprite sheet, PNG-frame ZIP, animated SVG. |
| `src/export/zip.js` | Minimal dependency-free ZIP writer. |
| `src/ui/studio.js`, `src/ui/debug.js`, `src/styles/studio.css`, `index.html` | The preview. |

### Artwork (`tools/trace_hen.py`)

Everything visible comes from the reference's own pixels. Each part is segmented by colour (red comb
and wattle, yellow eyes, orange beak and feet, dark pupils) or, for the all-white head, body, tail
and wing, by a cut line. Every contour is smoothed and fitted with cubic Béziers. The dark outline is
split between the parts it borders (nearest part wins, facial features win ties) so each part
carries its own outline, with the original's thick-and-thin variation. Shading is two traced tones
(a pale cool wash and a deeper core) plus each coloured part's own highlights and shadows, slightly
blurred to match the original's soft gradients.

Hidden artwork, invisible at rest, keeps moving parts from opening holes:

- the body continues under the head, with its own shoulder outline, so a dipping or rising head never
  exposes an edgeless body;
- the body continues under the wing, with outline and shading, so a raised wing reveals finished art;
- the wing has a full outline that fades in as it lifts (its top edge is only a soft grey line where
  it lies on the body);
- the comb continues under the crown, the far eye is completed as an ellipse behind the beak, the
  lower beak extends to its hinge, and short leg stubs run up from the feet into the body;
- eyelids rest above each eye inside the eye's clip path; a closed-eye lash curve rides on each lid
  and appears only as it shuts.

Re-trace after changing the tracer:

```bash
python -I hen-studio/tools/trace_hen.py hen-studio/reference/hen-reference.jpg hen-studio/src/character/hen.svg
```

### Rig

Coordinates are the artwork's pixel space (x right, **y down**, degrees clockwise). The hen faces
right, so a positive rotation tips the body forward, raises the tail, lifts the wing and lifts a
foot's heel. Pivots sit on joints read off the reference: hips (`body`), shoulder (`wingNear`), base
of the neck (`neck`), top of the neck (`head`), comb base, jaw hinge (`beakLower`), under-beak
(`wattle`), tail root, and hip and ankle for each leg. Nesting is in the SVG itself: the neck
carries the head, the head carries comb, eyes, beak and wattle, and each leg carries its foot.
Pupils are clamped to an ellipse inside their eye. Eyelids take a 0–1 closure.

The feet hang from the root, not the body, so a body tip (peck) or bob never drags the feet off the
ground.

### Clips

| Clip | Kind | Length | Notes |
|---|---|---|---|
| Idle | loop | 6.4 s | Two breaths, head drift, a glance, two blinks at fixed times (so the loop and exports repeat exactly). |
| Walk | loop | 0.9 s | Two-step cycle, stance 62%: contact → weight acceptance (body sinks) → mid-stance (body rises) → heel peel/toe-off → arcing swing → contact. Chicken head-bob (head holds, then thrusts), wing, tail, comb and wattle each lag their beat. Seamless by construction. |
| Run | loop | 0.46 s | Not a sped-up walk: stance 42%, bigger arcs and bob, forward lean with the head counter-levelled, wings half open beating with the stride, tail up. |
| Peck | one-shot | 1.05 s | Wind-up, dive, two taps (jaw opens a crack), eased return with tail follow-through. Mostly the whole body tipping at the hips (see limitations). |
| Blink | one-shot | 0.25 s | Both lids, the far one a frame behind; combines with anything. |
| Wing flap | one-shot | 1.2 s | Two beats about the shoulder, body lifts on each down-stroke, tail and head react. |
| Look around | one-shot | 3.4 s | Back over the shoulder, then forward and down, pupils leading, a blink at the turn. |
| React | one-shot | 0.95 s | Flinch, small hop with head thrown back, pupils contract, wing flick, tail cocked, settle with overshoot. |

**Controller rules**: one base at a time (switching cross-fades); at most one body action at a time
(a new one fades the old out over 0.18 s rather than cutting); blinks layer over anything. Actions
are additive offsets that start and end at exactly zero, so they can never snap the hen into or out
of a pose. Time is real elapsed time × speed; clips are pure functions of time, so exports are exact.

## Export

All exports render the hen from a clean copy of the artwork. The background, guides and debug
overlay are never included. Every format uses one fixed framing (artwork box x 100–1420,
y −70–1250), so the hen is the same size and position in every frame of every clip and nothing is
cropped (the peck's furthest reach and the startle hop both fit). The current tuning sliders apply.

### A. Animated SVG — *Animated SVG* button

One self-contained `.svg`: each moving joint has a CSS `@keyframes` track of 2D matrices sampled
from the clip (30 fps), plus opacity tracks for the wing edge and lash lines. Loops repeat forever;
a one-shot plays, rests 0.8 s, and repeats. Plays in any modern browser and in `<img>` tags.
**Limitation**: most game engines and many SVG libraries do not run CSS animation inside SVG — use B
or C there.

### B. PNG sprite sheet — *Sprite sheet (PNG + JSON)* button

`<clip>_sheet.png` (transparent) and `<clip>_sheet.json`. Frames are laid out row-major (left to
right, then top to bottom) in a near-square grid. The manifest gives `frameWidth`, `frameHeight`,
`columns`, `rows`, `fps`, `loop`, `duration`, the `x, y, t` of every frame, and `groundAnchor`: the
pixel inside each frame where the feet touch the ground. Anchor the sprite there.

Loops contain N frames over [0, duration), so the last frame leads straight into the first with no
duplicate. One-shots include both the first and last (rest) frames.

Playing it in a game (canvas example):

```js
const sheet = await fetch('walk_sheet.json').then((r) => r.json());
const img = new Image(); img.src = sheet.image;
function draw(ctx, timeSec, groundX, groundY) {
  let i = Math.floor(timeSec * sheet.fps);
  i = sheet.loop ? i % sheet.frameCount : Math.min(i, sheet.frameCount - 1);
  const f = sheet.frames[i];
  ctx.drawImage(img, f.x, f.y, f.w, f.h,
                groundX - sheet.groundAnchor.x, groundY - sheet.groundAnchor.y, f.w, f.h);
}
```

### C. Individual PNG frames — *PNG frames (ZIP)* button

`<clip>_frames.zip` holding `<clip>_000.png`, `<clip>_001.png` … (transparent, all the same size)
and `<clip>_manifest.json` (as B, with file names instead of sheet positions).

### D. Video — from the PNG frames with ffmpeg

The browser does not encode video here. Unzip the frames from C, then:

```bash
# Transparent WebM (VP9 with alpha) — loops the clip 4 times
ffmpeg -framerate 24 -stream_loop 3 -i walk_%03d.png -c:v libvpx-vp9 -pix_fmt yuva420p -b:v 0 -crf 30 -auto-alt-ref 0 walk.webm

# MP4 (H.264) — MP4 cannot carry transparency, so composite over a colour first
ffmpeg -f lavfi -i "color=c=0xf4f5f7:s=256x256:r=24" -framerate 24 -stream_loop 3 -i walk_%03d.png \
       -filter_complex "[0][1]overlay=shortest=1,format=yuv420p" -c:v libx264 -crf 18 -movflags +faststart walk.mp4

# GIF with a transparent background (1-bit alpha; soft edges become hard)
ffmpeg -framerate 24 -i walk_%03d.png -filter_complex "split[a][b];[a]palettegen=reserve_transparent=1[p];[b][p]paletteuse" -loop 0 walk.gif
```

Match `s=256x256` to your frame size. ffprobe reports the WebM as `yuv420p` with the tag
`alpha_mode=1`: that is how VP9 stores alpha. Decode with `-c:v libvpx-vp9` to get RGBA back.

## Verified (2026-10-10, headless Edge + Python)

- Rendered at rest against the reference: mean colour error 10.0 / 255 inside the character, ~2.6% of
  the character's pixels off by more than 60 levels (mostly the outline's anti-aliasing and the
  softened gradients).
- Every clip inspected frame by frame on contact sheets.
- Sprite sheet: size matches manifest; walk = 22 frames at 24 fps; frames transparent at the
  corners, nothing touching the frame edge; every frame differs from the next; the loop seam
  (last → first) is an ordinary step.
- ZIPs: CRCs valid, names `walk_000.png … walk_021.png` / `peck_000 … peck_025`, all frames
  identical in size, transparent; the peck's first and last frames are the same rest pose.
- Animated SVG: valid XML, and it animates when opened standalone in the browser.
- Video: the three ffmpeg commands above produced a VP9 WebM whose decoded frames keep
  transparent corners, an 88-frame H.264 MP4 and a 44-frame GIF.
- Controller: switching every clip mid-motion, interrupting actions, stacking blinks, travelling
  and changing speed raised no errors; the largest frame-to-frame joint change outside Reset was
  a running foot mid-swing.

## Known limitations and fidelity compromises

- **Fidelity at rest is close but not pixel-identical.** The beak's brown outline is a little
  lighter than the original (it is traced as a tone of the beak rather than as outline), the comb's
  lower-left lobes have a slightly thinner outline, and the original's smooth gradients are two
  soft tones here.
- **The peck cannot reach the ground.** The design has almost no neck, so the head cannot travel
  far without exposing hidden artwork past the silhouette. The peck is mostly a forward tip of the
  whole body at the hips, with a modest neck dip; the beak reaches the hen's chest level in front
  of her, not the floor.
- **Only the near wing exists**, as in the reference's three-quarter view; the far wing is never
  visible from this angle and was not invented.
- **The legs are short hidden stubs**, because the reference shows only feet. The walk is a waddle
  of the feet under the body, as the design implies.
- **Blink**: the lids are reconstructed artwork (the reference has none); a closed eye is drawn as
  a lid with a lash curve.
- **The animated SVG export** depends on CSS animation and will not play in engines that ignore it.
