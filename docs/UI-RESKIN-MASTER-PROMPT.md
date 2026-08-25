# Master Prompt — Reskin bet1x user-facing UI to match the reference screenshot

> Created 2026-08-25. This is a **reusable prompt**, meant to be pasted into a fresh Claude Code
> conversation together with the reference screenshot(s) (a mobile view of a "66Lottery"-style
> app) to actually execute the reskin. It is not itself the implementation.

---

## Objective

Restyle every **user-facing** page of this site so it visually matches the attached reference
screenshot(s) as closely as possible — same color palette, same fonts, same component shapes
(pill buttons, rounded gradient icon tiles, card layout, bottom nav), same overall visual density
and mood. Build it for **both mobile and desktop** viewports. The reference is a mobile screenshot
only — desktop has no 1:1 reference, so it must be extrapolated from the same design language
(Section 6), not invented independently or left unstyled.

**This is a pure visual/presentational change. Zero functional change.** Every game, bet flow,
wallet action, auth flow, admin panel, and API call must behave after this change exactly as it
did before it. If you cannot tell whether a given edit is "just styling," stop and treat it as
functional — do not guess.

## 0. Safety checkpoint — do this before editing a single file

1. Confirm `git status` is clean relative to what you intend to keep. If there is uncommitted work
   present that isn't part of this task, stop and ask rather than folding it into your first commit.
2. Confirm you are on a **dedicated branch**, not `master`/`main` — e.g. `git checkout -b ui-reskin`.
   `master` must stay exactly as it is right now so it is always a clean fallback: if the reskin
   goes wrong or is abandoned, `git checkout master` restores today's working site with zero
   archaeology required.
3. Commit early and often on the branch as you go (per-page or per-component commits), not one
   giant commit at the end — this makes it possible to roll back a single bad page instead of the
   whole effort.

## 1. Extract the actual design tokens from the attached image(s) first

Do not rely on hex codes, font names, or measurements written in this document as ground truth —
treat any color/size/spacing mentioned below as an approximate starting description only. Before
writing CSS:

- Sample exact colors from the screenshot for: primary red/coral (buttons, nav accents), gold/
  yellow (bonus banner, premium accents), the hero banner's dark maroon background, each gradient
  tile's two stops (there appear to be at least 4-5 distinct gradient families — teal/cyan, orange/
  amber, purple/indigo, pink/magenta), page background, card/section backgrounds, and text colors
  on both light and dark surfaces.
- Identify the actual typeface family (or the closest freely-licensed match — check Google Fonts)
  for: UI/body text (bold, rounded, friendly sans-serif) vs. the promotional banner headline (a
  chunky 3D/extruded display face with an outline and drop shadow — this is a `text-shadow` /
  `-webkit-text-stroke` effect, not a different font on its own).
- Note exact shapes: button corner radius (looks fully pill-shaped, i.e. `border-radius: 999px`),
  icon-tile corner radius (large rounded-square, not pill), spacing/gaps between grid tiles,
  relative sizing of the header, promo banner, category grid, and bottom nav.

## 2. Work within the existing architecture — do not build a parallel system

This site has **no build step** (plain HTML/CSS/JS, served as-is) and **one shared stylesheet**,
`assets/css/style.css` (2,587 lines as of 2026-08-25), included by every page. Reskinning must
happen primarily by editing this one file, not by scattering new inline styles or a second CSS
file across pages.

- **Design tokens already exist** at `:root` in `assets/css/style.css` (lines 10-41): `--bg`,
  `--bg-soft`, `--surface`, `--surface-2`, `--border`, `--text`, `--text-dim`, `--text-faint`,
  accent colors `--green`/`--red`/`--violet`/`--gold` (+ `-soft` translucent variants), fonts
  `--font-display` / `--font-body` / `--font-mono`, radii `--radius-sm`/`--radius`/`--radius-lg`,
  `--shadow-card`, `--max-width`. **The current theme is dark** (`--bg: #0b0e1d`) with a modern/
  neon aesthetic — the reference is a **light, warm, red-and-gold, playful** aesthetic. This is a
  full token flip, not a tweak: redefine these variables (and add new ones as needed — e.g. you
  will likely need distinct gradient-pair variables per icon-tile color family, which don't exist
  yet) rather than leaving the dark tokens in place and overriding piecemeal per page.
- **Reuse existing component classes** instead of inventing new ones: `.navbar` (top nav, sticky),
  `.btn` / `.btn-primary` / `.btn-ghost` / `.btn-gold` / `.btn-block`, `.card`, `.pill`,
  `.wallet-chip`, `.container` / `.section`, `.hero`. Restyle these in place.
- **`.mobile-bottom-nav` / `.mobile-bottom-nav-item` already exist** (style.css ~line 2322) — fixed
  to the viewport bottom, 60px tall, hidden above 768px via the existing `@media (max-width: 768px)`
  block. This is the exact component for the reference's 5-icon bottom bar (Home / Promotion /
  Get Money / Agent / Account). Restyle this one; do not add a second bottom nav. The reference's
  center "Get Money" item is enlarged and visually elevated above the bar line in a circular gold/
  red badge — this needs a one-off modifier class (e.g. `.mobile-bottom-nav-item--cta`), not a
  redesign of the other four items' markup.
- **No web font is currently loaded.** None of the user-facing pages actually `<link>` a Google
  Font or declare an `@font-face` (only `superadmin.html`, an admin page, does) — `--font-display`/
  `--font-body` are pure fallback stacks ending in system fonts. So matching the reference's
  rounded, bold display type requires *adding* a font-loading mechanism (a Google Fonts `<link>` in
  each page's `<head>`, or a self-hosted `@font-face` in style.css), not swapping an existing one.
- Before renaming or removing any element's `id`, class, or `data-*` attribute, grep
  `assets/js/*.js` and the page's own inline `<script>` block for references to it
  (`getElementById`, `querySelector`, event delegation selectors). `assets/js/sound-fx.js` in
  particular injects a floating mute-toggle button at runtime and auto-wires clicks/hovers via a
  delegated listener — check what it expects before changing global click/hover styling.

## 3. Scope — which pages

**Restyle (user-facing):** `index.html`, `aviator.html`, `teenpatti.html`, `mining.html`,
`cashier.html`, `win.html`, `win1.html`, `win2.html`, `win3.html`, `youreleven.html`,
`boundarybaazi.html`.

**Do not touch:** `admin.html`, `superadmin.html`, `parity.html` (admin/ops surfaces), anything
under `backend/`, and the PHP layer under the game folders / `backend/api/*.php` (not on the live
code path per `CLAUDE.md` — irrelevant to what a user sees).

## 4. Component-by-component target (from the reference screenshot)

Reproduce these as distinct, reusable pieces — most map directly onto an existing class from
Section 2:

1. **Dismissible top promo strip** — thin banner, coral-red background, "Download now to get" +
   white pill "Download" button + close (×). New component; keep it dismissible (CSS/JS toggle,
   no functional dependency).
2. **Header bar** — logo/wordmark left; "Register" and "Log in" as white-background, colored-
   border pill buttons, right-aligned. Maps onto `.navbar` + `.btn-ghost`-style pills.
3. **Hero/promo banner** — large rounded card, dark maroon background, bold gold 3D display
   headline with outline + drop shadow, decorative imagery, gold pill CTA button ("EARN NOW!"
   equivalent). New component, but built from existing `--radius-lg` / `--shadow-card` tokens.
4. **News/announcement ticker** — slim white bar, speaker icon, single-line scrolling or truncated
   text, red pill "Detail" button. New, lightweight component.
5. **Category icon grid** — a row of individual rounded-square gradient tiles (icon + label each,
   one distinct gradient per tile), plus one wider multi-icon gradient card grouping several
   categories together. Two related but visually distinct grid patterns — don't collapse them into
   one.
6. **Section header pattern** — small numbered/icon badge + section title + right-aligned "All >"
   link. Reusable across every list section on the page (this site already has multiple game-list
   sections that can adopt this pattern consistently).
7. **Sub-category pill row** — horizontal row of solid coral-red pill buttons, equal width, wraps
   or scrolls on narrow viewports.
8. **Bottom navigation** — see Section 2's `.mobile-bottom-nav` notes above.

## 5. Desktop adaptation (no direct reference image — extrapolate deliberately)

- Keep the same color/type/shape language; do not invent a different visual style for desktop.
- The existing responsive pattern already hides `.mobile-bottom-nav` above 768px and shows the
  `.navbar` instead — keep that split. Desktop users get the restyled top `.navbar` (Section 4.2)
  as their primary nav; they do not need the bottom bar.
- The category icon grid (4.5) should reflow from a wrapping/scrollable mobile row into a wider
  fixed grid or row on desktop (existing breakpoints at 900px/768px in style.css are the natural
  places to hook this) — same tiles, same gradients, more columns.
- The hero/promo banner (4.3) can grow in max-width but should not stretch full-bleed on very wide
  viewports — respect the existing `--max-width` / `.container` convention already used elsewhere
  on the site.
- Test at minimum one narrow mobile width (~390px, matching the reference) and one desktop width
  (~1440px) per page — see Section 7.

## 6. Explicit non-goals / do-not-touch

- No changes to game logic, timers, round/tick loops, wallet math, bet placement, WebSocket/
  polling, or any `api/`-prefixed fetch call.
- No changes to admin.html / superadmin.html / parity.html.
- No changes to `backend/` at all.
- No renamed/removed `id`/`class`/`data-*` that any script depends on (verify per Section 2).
- No new build tooling — stay plain HTML/CSS/JS, hand-edited, no bundler.

## 7. Process

1. Do Section 0 (branch) first.
2. Re-derive real values from the attached screenshot (Section 1) before writing any CSS.
3. Update the shared tokens and shared components in `assets/css/style.css` first (Sections 2 & 4).
4. Load `index.html` in a real browser at both a mobile and a desktop width and compare side-by-
   side against the reference before touching any other page — this is the page closest to a 1:1
   match, and mistakes here will otherwise get copied into every other page.
5. Go through the remaining pages one at a time, restyling and browser-testing each (per
   `CLAUDE.md`'s standing rule: UI changes must be verified in an actual browser, not assumed from
   reading code), committing after each page.
6. After all pages are done, run the existing test suites (`npm test`, `npm run test:e2e`) — they
   exercise wallet/game logic and static file serving, not visual appearance, so they should be
   unaffected by a correct pure-CSS/markup change. A failure here means something functional broke
   and must be fixed before continuing.
7. Do a final side-by-side pass against the reference screenshot(s), mobile and desktop both.

## 8. Definition of done

- [ ] Branch created before any edit; `master` untouched.
- [ ] `:root` tokens in `assets/css/style.css` reflect the reference palette/fonts, sampled from the
      actual image, not guessed.
- [ ] Shared components (`.navbar`, `.btn*`, `.card`, `.pill`, `.mobile-bottom-nav*`) restyled once,
      centrally, and reused — not reimplemented per page.
- [ ] All 11 in-scope pages visually match the reference's language at both mobile (~390px) and
      desktop (~1440px) widths, verified in a real browser.
- [ ] admin.html / superadmin.html / parity.html unchanged.
- [ ] No `id`/`class`/`data-*` referenced by JS was renamed or removed without updating the JS.
- [ ] `npm test` and `npm run test:e2e` still pass.
- [ ] Every game/wallet/auth flow manually smoke-tested and behaves identically to before.
