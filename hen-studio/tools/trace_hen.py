"""
Trace the reference hen into a layered, riggable master SVG.

    python -I hen-studio/tools/trace_hen.py hen-studio/reference/hen-reference.jpg hen-studio/src/character/hen.svg

Every visible shape comes from the reference's own pixels: each part is segmented by colour (or by a
cut line where two white parts meet), its contour is smoothed and fitted with cubic Bezier curves,
and the dark outline is divided between the parts it borders, so the outline keeps its original
thick-and-thin variation. What the flat image cannot show — the body under the head and the wing,
the base of the comb, the far eye behind the beak, the lower beak's hinge, the legs above the feet —
is added as hidden artwork so that moving a part never opens a hole.

The SVG keeps the reference's 1254 x 1254 coordinate system, so the original can be laid underneath
it for comparison. Group ids are the rig's joint names; see src/rig/skeleton.js for the pivots.

Requires: opencv-python, numpy.
"""
import sys
import cv2
import numpy as np

SRC, OUT = sys.argv[1], sys.argv[2]
img = cv2.imread(SRC)
H, W = img.shape[:2]
I = img.astype(int)
B, G, R = I[..., 0], I[..., 1], I[..., 2]
hsv = cv2.cvtColor(img, cv2.COLOR_BGR2HSV)
hh, ss, vv = hsv[..., 0].astype(int), hsv[..., 1].astype(int), hsv[..., 2].astype(int)
LUM = (0.299 * R + 0.587 * G + 0.114 * B)

def m8(a): return (a > 0).astype(np.uint8)
def disk(r): return cv2.getStructuringElement(cv2.MORPH_ELLIPSE, (2 * r + 1, 2 * r + 1))
def dil(m, r): return cv2.dilate(m8(m), disk(r))
def ero(m, r): return cv2.erode(m8(m), disk(r))
def clean(m, r=2): return cv2.morphologyEx(cv2.morphologyEx(m8(m), cv2.MORPH_OPEN, disk(r)), cv2.MORPH_CLOSE, disk(r))
def poly(points):
    m = np.zeros((H, W), np.uint8)
    cv2.fillPoly(m, [np.array(points, np.int32)], 1)
    return m
def ellipse(cx, cy, rx, ry, ang=0):
    m = np.zeros((H, W), np.uint8)
    cv2.ellipse(m, (int(cx), int(cy)), (int(rx), int(ry)), ang, 0, 360, 1, -1)
    return m
def below(points):
    """Mask of everything below a left-to-right polyline."""
    return poly(list(points) + [(W + 50, H + 50), (-50, H + 50)])
def leftof(points):
    """Mask of everything left of a top-to-bottom polyline."""
    return poly(list(points) + [(-50, H + 50), (-50, -50)])
def largest(m, k=1):
    n, lab, st, _ = cv2.connectedComponentsWithStats(m8(m), 8)
    if n <= 1: return m8(m)
    order = np.argsort(-st[1:, cv2.CC_STAT_AREA])[:k] + 1
    return np.isin(lab, order).astype(np.uint8)
def fill_holes(m):
    cs, _ = cv2.findContours(m8(m), cv2.RETR_EXTERNAL, cv2.CHAIN_APPROX_NONE)
    out = np.zeros((H, W), np.uint8)
    cv2.drawContours(out, cs, -1, 1, -1)
    return out
def drop_small(m, a=60):
    n, lab, st, _ = cv2.connectedComponentsWithStats(m8(m), 8)
    keep = [i for i in range(1, n) if st[i, cv2.CC_STAT_AREA] >= a]
    return np.isin(lab, keep).astype(np.uint8)
def mean_hex(m):
    sel = m8(m) > 0
    if sel.sum() == 0: return '#888888'
    r, g, b = int(np.median(R[sel])), int(np.median(G[sel])), int(np.median(B[sel]))
    return f'#{r:02x}{g:02x}{b:02x}'

# ---------------------------------------------------------------------------------------------
# 1. Colour classes
# ---------------------------------------------------------------------------------------------
DARK = m8(vv < 140)   # includes the dark red-brown of the comb, wattle and beak outlines
sat = ss > 90
RED = m8(sat & ((hh < 8) | (hh > 170)) & (vv > 90))
ORANGE = m8(sat & (hh >= 8) & (hh < 22) & (vv > 90))
YELLOW = m8((ss > 40) & (hh >= 22) & (hh < 40) & (vv > 150))

ff = ((1 - DARK) * 255).astype(np.uint8)
fmask = np.zeros((H + 2, W + 2), np.uint8)
cv2.floodFill(ff, fmask, (2, 2), 128)
SIL = clean(1 - (ff == 128), 2)                       # whole character incl. outline
PLUME = m8(SIL & (1 - DARK) & m8((I.max(axis=2) - I.min(axis=2)) < 45))  # white feathers
COOL = m8(PLUME & (vv < 246) & (B - R > 4))           # blue-grey shading
LINE = m8(PLUME & (vv < 200))                         # grey line art (wing edge)

# ---------------------------------------------------------------------------------------------
# 2. Parts
# ---------------------------------------------------------------------------------------------
def comp_at(mask, x, y, r=25):
    n, lab, st, _ = cv2.connectedComponentsWithStats(m8(mask), 8)
    win = lab[y - r:y + r, x - r:x + r]
    ids, cnt = np.unique(win[win > 0], return_counts=True)
    if len(ids) == 0: raise SystemExit(f'no component near {x},{y}')
    return (lab == ids[np.argmax(cnt)]).astype(np.uint8)

comb_vis = comp_at(RED, 720, 140)
wattle_vis = comp_at(RED, 930, 570)
eye_near_vis = clean(comp_at(YELLOW, 760, 420) | comp_at(dil(YELLOW, 1), 740, 470), 3)
eye_far_vis = comp_at(YELLOW, 1030, 400)
beak_low_vis = comp_at(ORANGE, 965, 500, 15)
beak_up_vis = clean(m8((ORANGE | YELLOW) & poly([(860, 300), (1090, 300), (1090, 500), (1010, 470), (930, 455), (860, 480)])) & (1 - beak_low_vis), 2)
beak_up_vis = largest(beak_up_vis)
foot_near_vis = comp_at(ORANGE, 590, 1160)
foot_far_vis = comp_at(ORANGE, 790, 1150)

# Red eye rims (a soft pinkish ring drawn just inside the near eye's outline)
eye_rim_near = m8(comp_at(RED, 640, 470, 12) | (RED & dil(eye_near_vis, 6)))
eye_rim_near = m8(eye_rim_near & (1 - comb_vis) & (1 - wattle_vis))

# Pupils: dark blobs inside the eyes, away from their outer outline.
eye_near_full = fill_holes(clean(m8(eye_near_vis | eye_rim_near), 6))
pupil_near = comp_at(DARK, 663, 387, 12)
# Far eye: fit an ellipse to its visible yellow (it disappears under the beak).
fy = np.column_stack(np.nonzero(eye_far_vis))[:, ::-1].astype(np.float32)
(fcx, fcy), (fa, fb), fang = cv2.fitEllipse(cv2.convexHull(fy))
EYE_FAR = dict(cx=fcx, cy=fcy, rx=fa / 2, ry=fb / 2, ang=fang)
eye_far_full = ellipse(fcx, fcy, fa / 2 + 4, fb / 2 + 4, fang)
pupil_far = largest(m8(DARK & ero(eye_far_full, 3) & poly([(1015, 280), (1085, 280), (1085, 375), (1015, 375)])))
pupil_near = fill_holes(pupil_near)
hl_near = largest(m8((vv > 200) & (ss < 60) & pupil_near))
pupil_far = fill_holes(pupil_far)
hl_far = largest(m8((vv > 200) & (ss < 60) & dil(pupil_far, 2) & poly([(1030, 285), (1070, 285), (1070, 325), (1030, 325)])))

# White plumage parts, separated by cut lines.
HEAD_SEAM = [(-10, 545), (470, 548), (560, 612), (680, 650), (820, 668), (960, 660), (1090, 632), (1260, 600)]
TAIL_SEAM = [(372, -10), (360, 560), (346, 640), (330, 720), (314, 800), (302, 870), (300, 940), (300, 1300)]
WING_POLY = [(328, 858), (346, 800), (400, 760), (490, 734), (590, 737), (680, 769), (713, 840), (715, 940),
             (683, 1002), (621, 1042), (560, 1066), (470, 1058), (380, 1030), (318, 992), (280, 940), (280, 878)]

colour_parts = m8(comb_vis | wattle_vis | eye_near_full | eye_far_vis | beak_low_vis | beak_up_vis | foot_near_vis | foot_far_vis)
white = m8(SIL & (1 - DARK) & (1 - colour_parts))
above_head_seam = 1 - below(HEAD_SEAM)
tail_side = leftof(TAIL_SEAM)
wing_vis = m8(white & poly(WING_POLY))
head_vis = m8(white & above_head_seam)
tail_vis = largest(m8(white & tail_side & below(HEAD_SEAM) & (1 - wing_vis)))
body_vis = m8(white & below(HEAD_SEAM) & (1 - tail_side) & (1 - wing_vis))
body_vis = largest(body_vis)

# ---------------------------------------------------------------------------------------------
# 3. Divide the dark outline between the parts it borders (nearest part wins).
# ---------------------------------------------------------------------------------------------
INK_PARTS = {
    'comb': comb_vis, 'head': head_vis, 'body': body_vis, 'tail': tail_vis, 'wing': wing_vis,
    'footNear': foot_near_vis, 'footFar': foot_far_vis, 'eyeNear': eye_near_full, 'eyeFar': eye_far_vis,
    'beakUpper': beak_up_vis, 'beakLower': beak_low_vis, 'wattle': wattle_vis,
}
ink_px = m8(DARK & (1 - pupil_near) & (1 - pupil_far))
names = list(INK_PARTS)
# Facial features win ties against the head they sit in: their outline must draw on top of them,
# and the head is drawn underneath.
INK_BIAS = {'eyeNear': 9, 'eyeFar': 9, 'beakUpper': 4, 'beakLower': 4, 'wattle': 4, 'wing': 2}
dists = np.stack([cv2.distanceTransform((1 - INK_PARTS[n]).astype(np.uint8), cv2.DIST_L2, 5) - INK_BIAS.get(n, 0)
                  for n in names])
owner = np.argmin(dists, axis=0)
near_enough = np.min(dists, axis=0) < 30 - 9
INK = {n: clean(drop_small(m8(ink_px & near_enough & (owner == i)), 30), 1) for i, n in enumerate(names)}

# ---------------------------------------------------------------------------------------------
# 4. Hidden artwork (full shapes for moving parts)
# ---------------------------------------------------------------------------------------------
sil_white = m8(SIL & (1 - DARK)) | m8(SIL)
HEAD_HIDDEN = 70
head_full = m8((head_vis | eye_near_full | eye_far_full | beak_up_vis | INK['head'] | INK['eyeNear'] | INK['eyeFar'] |
                (SIL & (1 - below([(x, y + HEAD_HIDDEN) for x, y in HEAD_SEAM])) & (1 - tail_side)))
               & (1 - comb_vis) & (1 - INK['comb']))
# The hidden part below the seam only reaches across the neck, never into the shoulder or back.
head_full = m8(head_full & (above_head_seam | poly([(500, 500), (1170, 500), (1170, 760), (500, 760)])))
head_full = clean(largest(m8(head_full & (SIL | ero(SIL, 0)))), 4)
# Keep the head's (slightly grown) fill off the comb's outline, which it would otherwise paint over.
head_full = m8(head_full & (1 - dil(comb_vis | INK['comb'], 5)) | head_vis)
body_full = m8(SIL & below([(x, y - 70) for x, y in HEAD_SEAM]) & (1 - leftof([(x + 40, y) for x, y in TAIL_SEAM])))
body_full = m8(body_full & (1 - (foot_near_vis | foot_far_vis | INK['footNear'] | INK['footFar'])) | body_vis | wing_vis | INK['wing'])
body_full = clean(largest(body_full), 4)
tail_full = clean(largest(m8((tail_vis | INK['tail'] | (SIL & tail_side & below(HEAD_SEAM))) |
                         (SIL & leftof([(x + 60, y) for x, y in TAIL_SEAM]) & poly([(300, 560), (420, 600), (420, 900), (300, 950)])))), 3)
comb_full = clean(m8(comb_vis | INK['comb'] | (ellipse(705, 292, 170, 62) & ero(head_full, 6))), 3)
beak_low_full = clean(m8(beak_low_vis | ellipse(958, 452, 48, 22)), 2)
leg_near = poly([(560, 1040), (612, 1040), (614, 1125), (566, 1125)])
leg_far = poly([(752, 1040), (802, 1040), (804, 1118), (756, 1118)])
foot_near_full = clean(m8(foot_near_vis | INK['footNear']), 2)
foot_far_full = clean(m8(foot_far_vis | INK['footFar']), 2)
wing_full = clean(m8(wing_vis | INK['wing']), 2)

# ---------------------------------------------------------------------------------------------
# 5. Tone layers (shading / highlights) traced from the reference
# ---------------------------------------------------------------------------------------------
def soft(m, blur=7, thr=0.5, minarea=150):
    f = cv2.GaussianBlur(m8(m).astype(np.float32), (0, 0), blur)
    return drop_small(m8(f > thr), minarea)
def tones(part_vis, light=16, darkd=16, blur=4):
    sel = m8(part_vis) > 0
    med = float(np.median(LUM[sel]))
    lt = soft(m8(part_vis & (LUM > med + light)), blur, 0.5, 80)
    dk = soft(m8(part_vis & (LUM < med - darkd)), blur, 0.5, 80)
    return lt, dk

# Two-step shading, as in the original: a pale cool wash and a deeper core shadow inside it.
SHADE_LIGHT = m8(COOL)
SHADE_DEEP = m8(PLUME & (vv < 236) & (B - R > 6))
shade = {n: (soft(m8(SHADE_LIGHT & ero(m, 1)), 6, 0.5, 150), soft(m8(SHADE_DEEP & ero(m, 1)), 6, 0.5, 150))
         for n, m in [('head', head_vis), ('body', body_vis), ('tail', tail_vis), ('wing', wing_vis)]}
COOL_LIGHT = mean_hex(SHADE_LIGHT & (vv >= 236))
COOL_DEEP = mean_hex(SHADE_DEEP)
def shading(name):
    lt, dp = shade[name]
    return [(lt, COOL_LIGHT, 1), (dp, COOL_DEEP, 1)]
wing_line = drop_small(m8(LINE & dil(wing_vis, 3) & (1 - ero(wing_vis, 14))), 40)
tone = {n: tones(m) for n, m in [('comb', comb_vis), ('wattle', wattle_vis), ('beakUpper', beak_up_vis),
                                ('beakLower', beak_low_vis), ('footNear', foot_near_vis), ('footFar', foot_far_vis),
                                ('eyeNear', eye_near_vis), ('eyeFar', eye_far_vis)]}

# ---------------------------------------------------------------------------------------------
# 6. Contours -> smooth cubic Bezier paths
# ---------------------------------------------------------------------------------------------
def smooth_closed(pts, win=5):
    k = np.ones(win) / win
    ext = np.concatenate([pts[-win:], pts, pts[:win]])
    xs = np.convolve(ext[:, 0], k, 'same')[win:-win]
    ys = np.convolve(ext[:, 1], k, 'same')[win:-win]
    return np.column_stack([xs, ys])

def rdp(points, eps):
    a = cv2.approxPolyDP(points.astype(np.float32).reshape(-1, 1, 2), eps, True)
    return a.reshape(-1, 2)

def to_bezier(pts):
    n = len(pts)
    if n < 3: return ''
    d = [f'M{pts[0][0]:.1f} {pts[0][1]:.1f}']
    for i in range(n):
        p0, p1, p2, p3 = pts[i - 1], pts[i], pts[(i + 1) % n], pts[(i + 2) % n]
        c1 = p1 + (p2 - p0) / 6.0
        c2 = p2 - (p3 - p1) / 6.0
        d.append(f'C{c1[0]:.1f} {c1[1]:.1f} {c2[0]:.1f} {c2[1]:.1f} {p2[0]:.1f} {p2[1]:.1f}')
    return ''.join(d) + 'Z'

def mask_path(m, eps=0.9, minarea=40, holes=True):
    # Fills are traced without holes (whatever sits on top of them is drawn later); outline bands
    # and tone layers keep their holes so rings stay rings.
    mode = cv2.RETR_CCOMP if holes else cv2.RETR_EXTERNAL
    cs, hier = cv2.findContours(m8(m), mode, cv2.CHAIN_APPROX_NONE)
    out = []
    for c in cs:
        if abs(cv2.contourArea(c)) < minarea: continue
        p = c[:, 0, :].astype(float)
        if len(p) > 12: p = smooth_closed(p, 5)
        p = rdp(p, eps).astype(float)
        out.append(to_bezier(p))
    return ''.join(out)

def path(m, fill, extra='', eps=0.9, minarea=40, pid=None, holes=True):
    d = mask_path(m, eps, minarea, holes)
    if not d: return ''
    idattr = f' id="{pid}"' if pid else ''
    return f'<path{idattr} d="{d}" fill="{fill}" fill-rule="evenodd"{extra}/>'

INKC = mean_hex(DARK & dil(SIL, 0) & (1 - pupil_near))
WHITE = '#ffffff'
def part_layers(base_mask, base_col, ink, details=(), clip=None):
    # The fill runs under the part's OWN outline (so fill and outline can never part), but is not
    # grown beyond its shape: a grown fill painted over neighbouring parts' outlines (white seams
    # where the wing meets the tail).
    s = path(m8(base_mask | ink), base_col, holes=False)
    layers = ''
    for m, col, op in details:
        if clip is not None: m = m8(m & clip)
        layers += path(m, col, f' opacity="{op}"' if op < 1 else '')
    # Tone layers get a slight blur so their edges read as the original's soft gradients.
    if layers: s += f'<g filter="url(#soft)">{layers}</g>'
    s += path(ink, INKC)
    return s

def tone_details(name):
    lt, dk = tone[name]
    return [(dk, mean_hex(dk), 1), (lt, mean_hex(lt), 1)]

svg = []
def grp(i, content, cls=''):
    c = f' class="{cls}"' if cls else ''
    return f'<g id="{i}"{c}>{content}</g>'

COOLC = mean_hex(COOL)
# Eyelids: head-coloured caps clipped to each eye, with the eye's dark upper line along their edge.
LID_TRAVEL_RY = 2.35   # open: lid edge 1.2 ry above the eye centre; shut: 1.15 ry below it (fully covered)
def eyelid(eid, lash_id, cx, cy, rx, ry):
    """
    A head-coloured lid, clipped to the eye by its parent, resting just above the eye. The rig slides
    it down by LID_TRAVEL_RY * ry to blink. Fully shut, the lid covers the whole eye, so a lash line
    (a soft downward curve, the usual closed-eye mark) rides on the lid and is faded in by the rig
    only as it nears closing — otherwise a shut eye would read as a blank white disc.
    """
    t = LID_TRAVEL_RY * ry
    lash_y0, lash_y1 = cy + 0.10 * ry - t, cy + 0.62 * ry - t
    return (f'<g id="{eid}" class="lid">'
            f'<ellipse cx="{cx:.1f}" cy="{cy - ry * 2.45:.1f}" rx="{rx * 1.4:.1f}" ry="{ry * 1.25:.1f}" fill="#f6f7fb" '
            f'stroke="{INKC}" stroke-width="10"/>'
            f'<path id="{lash_id}" opacity="0" d="M{cx - 0.8 * rx:.1f} {lash_y0:.1f} Q{cx:.1f} {lash_y1 + 0.18 * ry:.1f} {cx + 0.8 * rx:.1f} {lash_y0:.1f}" '
            f'fill="none" stroke="{INKC}" stroke-width="{max(8, 0.1 * rx):.1f}" stroke-linecap="round"/></g>')

ys_n, xs_n = np.nonzero(eye_near_full)
ENX, ENY = (xs_n.min() + xs_n.max()) / 2, (ys_n.min() + ys_n.max()) / 2
ENRX, ENRY = (xs_n.max() - xs_n.min()) / 2, (ys_n.max() - ys_n.min()) / 2
ys_f, xs_f = np.nonzero(eye_far_full)
EFX, EFY = (xs_f.min() + xs_f.max()) / 2, (ys_f.min() + ys_f.max()) / 2
EFRX, EFRY = (xs_f.max() - xs_f.min()) / 2, (ys_f.max() - ys_f.min()) / 2

parts = {}
parts['legFar'] = path(leg_far, '#f29a0f') + path(ero(dil(leg_far, 5), 0) & (1 - leg_far), INKC)
parts['footFar'] = part_layers(foot_far_full, mean_hex(foot_far_vis), INK['footFar'], tone_details('footFar'))
parts['legNear'] = path(leg_near, '#f29a0f') + path(dil(leg_near, 5) & (1 - leg_near), INKC)
parts['footNear'] = part_layers(foot_near_full, mean_hex(foot_near_vis), INK['footNear'], tone_details('footNear'))
parts['tail'] = part_layers(tail_full, WHITE, INK['tail'], shading('tail'))
# Hidden outline along the top of the body (the shoulders under the head). It is invisible at rest,
# under the head; when the head dips or rises it is what keeps the body's silhouette closed.
def band(m, w): return m8(m & (1 - ero(m, w)))
body_shoulder_ink = m8(band(body_full, 7) & (1 - below([(x, y + 25) for x, y in HEAD_SEAM])))
# Under the folded wing the body carries on: it keeps its own copy of the silhouette outline there
# (the wing owns the visible one) and the wing's shading, so lifting the wing reveals a finished
# body rather than a white blob with no edge.
outside = m8(1 - SIL)
body_under_wing_ink = m8(INK['wing'] & dil(outside, 3))
body_shading = shading('body') + [(m & ero(wing_full, 2), c, o) for m, c, o in shading('wing')]
parts['body'] = part_layers(body_full, WHITE, m8(INK['body'] | body_shoulder_ink | body_under_wing_ink), body_shading)
parts['wing'] = part_layers(wing_full, WHITE, INK['wing'], shading('wing') + [(wing_line, mean_hex(wing_line), 1)])
# The wing's top edge is only a soft grey line where it lies on the body. Lifted past the back it
# needs a real outline, so a full dark edge is kept here at opacity 0 and faded in by the rig.
parts['wingEdge'] = path(m8(band(wing_full, 7) & (1 - INK['wing'])), INKC)
parts['comb'] = part_layers(comb_full, mean_hex(comb_vis), INK['comb'], tone_details('comb'))
parts['headShape'] = part_layers(head_full, WHITE, INK['head'], shading('head'))
parts['eyeFarBall'] = (path(eye_far_full, mean_hex(eye_far_vis), pid='eyeFarSclera', holes=False) +
                       ''.join(path(m & eye_far_full, c) for m, c, _ in tone_details('eyeFar')))
parts['pupilFar'] = path(pupil_far, mean_hex(DARK & pupil_far), holes=False) + path(hl_far, '#ffffff')
parts['eyeFarInk'] = path(INK['eyeFar'], INKC)
parts['eyeNearBall'] = (path(eye_near_full, mean_hex(eye_near_vis), pid='eyeNearSclera', holes=False) +
                        ''.join(path(m & eye_near_full, c) for m, c, _ in tone_details('eyeNear')) +
                        path(eye_rim_near, mean_hex(eye_rim_near), ' opacity="0.75"'))
parts['pupilNear'] = path(pupil_near, mean_hex(DARK & pupil_near), holes=False) + path(hl_near, '#ffffff')
parts['eyeNearInk'] = path(INK['eyeNear'], INKC)
parts['wattle'] = part_layers(wattle_vis | INK['wattle'], mean_hex(wattle_vis), INK['wattle'], tone_details('wattle'))
parts['beakLower'] = part_layers(beak_low_full, mean_hex(beak_low_vis), INK['beakLower'], tone_details('beakLower'))
parts['beakUpper'] = part_layers(beak_up_vis | INK['beakUpper'], mean_hex(beak_up_vis), INK['beakUpper'], tone_details('beakUpper'))

# Clip paths for the eyes (pupils and lids stay inside their eye).
clip_near = mask_path(eye_near_full, 0.9, holes=False)
clip_far = mask_path(eye_far_full, 0.9, holes=False)
for n in names:
    print(f'ink[{n}] = {int(INK[n].sum())} px')

# Nested hierarchy: root > legs/feet, tail, body, wing, neck > head > ...
svg = f'''<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {W} {H}" width="{W}" height="{H}" id="hen">
<!-- Generated by hen-studio/tools/trace_hen.py from reference/hen-reference.jpg. Do not hand-edit paths;
     re-run the tracer. Group ids are rig joints (see src/rig/skeleton.js). -->
<defs>
  <filter id="soft" x="-5%" y="-5%" width="110%" height="110%"><feGaussianBlur stdDeviation="2.2"/></filter>
  <clipPath id="clipEyeNear"><path d="{clip_near}"/></clipPath>
  <clipPath id="clipEyeFar"><path d="{clip_far}"/></clipPath>
</defs>
<g id="root">
  <g id="shadow"><ellipse cx="690" cy="1196" rx="300" ry="26" fill="#000" opacity="0.12"/></g>
  <g id="legFar">{parts['legFar']}<g id="footFar">{parts['footFar']}</g></g>
  <g id="legNear">{parts['legNear']}<g id="footNear">{parts['footNear']}</g></g>
  <g id="body">
    <g id="tail">{parts['tail']}</g>
    <g id="torso">{parts['body']}</g>
    <g id="wingNear">{parts['wing']}<g id="wingEdge" opacity="0">{parts['wingEdge']}</g></g>
    <g id="neck">
      <g id="head">
        <g id="comb">{parts['comb']}</g>
        <g id="headShape">{parts['headShape']}</g>
        <g id="eyeFar">
          {parts['eyeFarBall']}
          <g clip-path="url(#clipEyeFar)"><g id="pupilFar">{parts['pupilFar']}</g>{eyelid('eyelidFar', 'lashFar', EFX, EFY, EFRX, EFRY)}</g>
          {parts['eyeFarInk']}
        </g>
        <g id="eyeNear">
          {parts['eyeNearBall']}
          <g clip-path="url(#clipEyeNear)"><g id="pupilNear">{parts['pupilNear']}</g>{eyelid('eyelidNear', 'lashNear', ENX, ENY, ENRX, ENRY)}</g>
          {parts['eyeNearInk']}
        </g>
        <g id="wattle">{parts['wattle']}</g>
        <g id="beakLower">{parts['beakLower']}</g>
        <g id="beakUpper">{parts['beakUpper']}</g>
      </g>
    </g>
  </g>
</g>
</svg>
'''
open(OUT, 'w', encoding='utf-8').write(svg)

# Report geometry the rig needs.
def bbox(m):
    ys, xs = np.nonzero(m8(m))
    return [int(xs.min()), int(ys.min()), int(xs.max()), int(ys.max())]
print('written', OUT, f'{len(svg) / 1024:.0f} KB')
print('ink colour', INKC, 'cool shade', COOLC)
print('eyeNear centre', round(ENX), round(ENY), 'radii', round(ENRX), round(ENRY))
print('eyeFar ellipse', {k: round(v, 1) for k, v in EYE_FAR.items()})
print('EYELID_TRAVEL', {'eyelidNear': round(LID_TRAVEL_RY * ENRY), 'eyelidFar': round(LID_TRAVEL_RY * EFRY)})
print('eye centres', {'near': (round(ENX), round(ENY)), 'far': (round(EFX), round(EFY), round(EFRX), round(EFRY))})
for n, m in [('comb', comb_full), ('head', head_full), ('body', body_full), ('tail', tail_full), ('wing', wing_full),
             ('beakUpper', beak_up_vis), ('beakLower', beak_low_full), ('wattle', wattle_vis), ('footNear', foot_near_full),
             ('footFar', foot_far_full), ('pupilNear', pupil_near), ('pupilFar', pupil_far)]:
    print(n, bbox(m))
