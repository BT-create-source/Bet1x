# 🎨 BT-master (bet1x) — Complete Theme & Style Guide

> This document contains **every visual design token, color, font, animation, and styling pattern** used in the project. Copy these into your other project to replicate the exact same look and feel **without changing any content or data**.

---

## 1. Google Fonts Import

```css
@import url('https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Inter:wght@400;500;600&family=JetBrains+Mono:wght@500;700&display=swap');
```

### Font Families
| Token | Value | Usage |
|---|---|---|
| `--font-display` | `'Space Grotesk', 'Segoe UI', sans-serif` | Headings, brand text, buttons labels |
| `--font-body` | `'Inter', 'Segoe UI', sans-serif` | Body text, paragraphs, UI labels |
| `--font-mono` | `'JetBrains Mono', monospace` | Monospace: stats, codes, amounts, IDs |

---

## 2. Color Palette — CSS Custom Properties

### Core Backgrounds & Surfaces
| Token | Hex | sRGB | Usage |
|---|---|---|---|
| `--bg` | `#0b0e1d` | `rgb(11, 14, 29)` | Page background (deepest dark) |
| `--bg-soft` | `#10142a` | `rgb(16, 20, 42)` | Soft background panels |
| `--surface` | `#141833` | `rgb(20, 24, 51)` | Cards, panels, containers |
| `--surface-2` | `#1a2040` | `rgb(26, 32, 64)` | Secondary surface (inputs, chips, buttons) |
| `--border` | `#262b4a` | `rgb(38, 43, 74)` | All borders, dividers |

### Text Colors
| Token | Hex | Usage |
|---|---|---|
| `--text` | `#edeffa` | Primary text (near-white) |
| `--text-dim` | `#8790b8` | Secondary/muted text |
| `--text-faint` | `#5b628a` | Tertiary/placeholder text |

### Signal / Accent Colors (The "Game Pulse" Palette)
| Token | Hex | RGB | Soft Variant (12% opacity) | Usage |
|---|---|---|---|---|
| `--green` | `#2ed47a` | `rgb(46, 212, 122)` | `rgba(46,212,122,0.12)` → `--green-soft` | Win, success, positive, live indicators |
| `--red` | `#ff4b6e` | `rgb(255, 75, 110)` | `rgba(255,75,110,0.12)` → `--red-soft` | Loss, error, danger, alerts |
| `--violet` | `#a56bff` | `rgb(165, 107, 255)` | `rgba(165,107,255,0.12)` → `--violet-soft` | Special, premium, accent highlights |
| `--gold` | `#ffc53d` | `rgb(255, 197, 61)` | `rgba(255,197,61,0.12)` → `--gold-soft` | VIP, currency, selected state, brand accent |

### Additional Fixed Colors Used
| Color | Hex | Context |
|---|---|---|
| Brand Red (CTA) | `#c8102e` | Sign-up button, ticker title, logo box background |
| Brand Red Hover | `#e01234` / `#e61c38` | Hover state for brand red CTA |
| Exchange Green | `#00e676` | Exchange theme active tabs, chevrons, section borders |
| Odds Back (Blue) | `#38bdf8` | Back bet boxes |
| Odds Lay (Rose) | `#fb7185` | Lay bet boxes |
| Card Front White | `#ffffff` | Card face foreground |
| Card Back Pattern | `#1f2544` / `#12162b` | 45° stripe pattern on card backs |
| Splash Gold | `rgba(201,160,84, ...)` | Splash screen auroras, particles, rings |
| Exchange Text | `#c9d1d9` / `#8b949e` | Exchange theme text hierarchy |

---

## 3. CSS Custom Properties Block (Copy-Paste Ready)

```css
:root {
  /* ── Color Tokens ── */
  --bg:        #0b0e1d;
  --bg-soft:   #10142a;
  --surface:   #141833;
  --surface-2: #1a2040;
  --border:    #262b4a;
  --text:      #edeffa;
  --text-dim:  #8790b8;
  --text-faint:#5b628a;

  --green:  #2ed47a;
  --red:    #ff4b6e;
  --violet: #a56bff;
  --gold:   #ffc53d;

  --green-soft:  rgba(46,212,122,0.12);
  --red-soft:    rgba(255,75,110,0.12);
  --violet-soft: rgba(165,107,255,0.12);
  --gold-soft:   rgba(255,197,61,0.12);

  /* ── Typography ── */
  --font-display: 'Space Grotesk', 'Segoe UI', sans-serif;
  --font-body:    'Inter', 'Segoe UI', sans-serif;
  --font-mono:    'JetBrains Mono', monospace;

  /* ── Layout ── */
  --radius-sm: 10px;
  --radius:    16px;
  --radius-lg: 22px;
  --shadow-card: 0 8px 30px rgba(0,0,0,0.35);
  --max-width: 1180px;
}
```

---

## 4. Body & Page Background

```css
body {
  margin: 0;
  background:
    radial-gradient(ellipse 900px 500px at 15% -5%, rgba(165,107,255,0.10), transparent 60%),
    radial-gradient(ellipse 900px 500px at 90% 0%, rgba(46,212,122,0.08), transparent 60%),
    var(--bg);
  color: var(--text);
  font-family: var(--font-body);
  -webkit-font-smoothing: antialiased;
}
```

### Exchange Theme Override (for `body.exchange-theme`)
```css
body.exchange-theme {
  background: #0b0e11 !important;
  color: #e3e8ec !important;
  font-family: Arial, Helvetica, sans-serif !important;
}
```

---

## 5. Typography Scale

| Element | Font Family | Size | Weight | Extra |
|---|---|---|---|---|
| **Brand / Logo** | `--font-display` | `21px` | `700` | `letter-spacing: -0.02em` |
| **Hero H1** | `--font-display` | `46px` | `700` | `line-height: 1.08; letter-spacing: -0.02em` |
| **Section Heading H2** | `--font-display` | `22px` | default | — |
| **Room Card H3** | `--font-display` | `22px` | default | — |
| **Body / Lead** | `--font-body` | `16px` | default | `line-height: 1.6; color: --text-dim` |
| **Nav Links** | `--font-body` | `14px` | `500` | — |
| **Buttons** | inherited | `14px` | `600` | — |
| **Labels** | `--font-body` | `12.5px` | default | `text-transform: uppercase; letter-spacing: .04em` |
| **Stat Value** | `--font-mono` | `26px` | `700` | — |
| **Stat Label** | `--font-body` | `12.5px` | default | `color: --text-dim` |
| **Table Header** | `--font-body` | `11.5px` | `600` | `text-transform: uppercase; letter-spacing: .04em` |
| **Table Cell** | `--font-body` | `13px` | default | — |
| **Eyebrow / Tag** | `--font-mono` | `11-12px` | `500-700` | `text-transform: uppercase; letter-spacing: .08-.12em` |
| **Timer / Ring** | `--font-mono` | `24px` | `700` | — |
| **Wallet Chip** | `--font-mono` | `13px` | `700` | `color: --gold` |
| **Pill Badge** | inherited | `11px` | `600` | `letter-spacing: .04em` |
| **Footer** | `--font-body` | `12px` | default | `color: --text-faint` |
| **Splash Logo** | `--font-display` | `48px` | `800` | `letter-spacing: 0.05em; text-transform: uppercase` |
| **Aviator Multiplier** | `--font-display` | `64-72px` | `700` | — |

---

## 6. Border Radius System

| Token | Value | Usage |
|---|---|---|
| `--radius-sm` | `10px` | Buttons, inputs, small cards, card faces |
| `--radius` | `16px` | Main cards, panels, containers |
| `--radius-lg` | `22px` | Large containers, special elements |
| Pill / Chip | `20px` | Pills, chips, rounded buttons |
| Number Button | `50%` | Circular number buttons |
| Exchange/Compact | `4px` | Exchange theme boxes, compact elements |

---

## 7. Shadow System

| Token | Value | Usage |
|---|---|---|
| `--shadow-card` | `0 8px 30px rgba(0,0,0,0.35)` | Primary card shadow |
| Card Face | `0 4px 8px rgba(0,0,0,0.3)` | 3D card faces |
| Splash Content | `0 30px 70px rgba(0,0,0,0.7), inset 0 1px 1px rgba(255,255,255,0.06)` | Glassmorphic splash |
| Exchange Header | `0 4px 12px rgba(0,0,0,0.15)` | Exchange navbar |
| Green Glow | `0 0 12px var(--green)` | Live dot, active indicators |
| Gold Glow | `0 0 8px var(--gold)` → `0 0 24px var(--gold)` | VIP / selected pulsing |
| Brand Red Glow | `0 0 14px rgba(200,16,46,0.6)` | Sign-up button |

---

## 8. Gradient Patterns

```css
/* Body Ambient Background */
radial-gradient(ellipse 900px 500px at 15% -5%, rgba(165,107,255,0.10), transparent 60%)
radial-gradient(ellipse 900px 500px at 90% 0%, rgba(46,212,122,0.08), transparent 60%)

/* Splash Screen Center */
radial-gradient(circle at center, #0e121b 0%, #05060a 100%)

/* Splash Aurora Gold */
radial-gradient(circle, rgba(201,160,84,0.6) 0%, transparent 70%)
radial-gradient(circle, rgba(139,92,26,0.5) 0%, transparent 70%)

/* Button Gradients */
.green  → linear-gradient(135deg, #2ed47a, #1a9f5a)
.red    → linear-gradient(135deg, #ff4b6e, #d42a4e)
.violet → linear-gradient(135deg, #a56bff, #7b3ff0)
.gold   → linear-gradient(135deg, #ffc53d, #e0a316)

/* Splash Logo Text */
linear-gradient(135deg, #ffffff 40%, #c9a054 100%)  /* -webkit-background-clip: text */

/* Progress Bar */
linear-gradient(90deg, var(--gold), #ffffff, var(--gold))

/* Card Back Pattern */
repeating-linear-gradient(45deg, #1f2544, #1f2544 4px, #12162b 4px, #12162b 8px)

/* Section Headers (Exchange) */
linear-gradient(to right, var(--surface-2), var(--border))

/* Banner Overlay */
linear-gradient(rgba(11,14,29,0.4), rgba(11,14,29,0.8))
```

---

## 9. Glassmorphism Pattern

```css
/* Navbar */
background: rgba(11,14,29,0.85);
backdrop-filter: blur(10px);

/* Splash Content Box */
background: rgba(15, 18, 26, 0.45);
backdrop-filter: blur(25px);
-webkit-backdrop-filter: blur(25px);
border: 1px solid rgba(201, 160, 84, 0.15);
border-radius: 28px;
box-shadow: 0 30px 70px rgba(0,0,0,0.7), inset 0 1px 1px rgba(255,255,255,0.06);

/* Auth Modal Overlay */
background: rgba(11, 14, 29, 0.8);
backdrop-filter: blur(8px);

/* Mobile Drawer Overlay */
background: rgba(0, 0, 0, 0.7);
backdrop-filter: blur(4px);
```

---

## 10. Animation & Keyframes Catalog

### Micro-Interactions
```css
/* Blink (Live dot) */
@keyframes blink { 0%,100%{opacity:1;} 50%{opacity:.25;} }
/* Duration: 1.4s infinite */

/* Slide In (Activity feed items) */
@keyframes slideIn { from{opacity:0; transform:translateY(-6px);} to{opacity:1; transform:translateY(0);} }
/* Duration: 0.3s ease */

/* Toast Slide In */
@keyframes toastSlideIn { from{transform:translateX(50px); opacity:0;} to{transform:translateX(0); opacity:1;} }
/* Duration: 0.3s cubic-bezier(0.1, 0.8, 0.3, 1) */

/* Row Fade In */
@keyframes fadeInUpRow { from{opacity:0; transform:translateY(10px);} to{opacity:1; transform:translateY(0);} }
/* Duration: 0.4s; staggered delay: +0.05s per row */
```

### Premium Effects
```css
/* Gold Glow Pulse */
@keyframes glowPulse {
  0%   { box-shadow: 0 0 8px var(--gold); }
  50%  { box-shadow: 0 0 24px var(--gold), 0 0 12px rgba(255,197,61,0.4); }
  100% { box-shadow: 0 0 8px var(--gold); }
}
/* Duration: 1.5s infinite ease-in-out */

/* Float Particle (Win celebration) */
@keyframes floatParticle {
  0%   { transform: translate(0,0) scale(1) rotate(0deg); opacity:1; }
  100% { transform: translate(var(--tx),var(--ty)) scale(0.4) rotate(var(--rot)); opacity:0; }
}
/* Duration: 1.4s cubic-bezier(0.25,0.46,0.45,0.94) forwards */

/* Coin Shower */
@keyframes coinShowerAnimation {
  0%   { transform: translate(0,0) rotate(0deg) scale(0.5); opacity:0; }
  15%  { opacity:1; transform: translate(calc(var(--tx)*0.2), calc(var(--ty)*0.5)) rotate(45deg) scale(1.2); }
  50%  { opacity:1; }
  100% { transform: translate(var(--tx), var(--ty)) rotate(var(--rot)) scale(0.7); opacity:0; }
}
```

### Splash Screen Animations
```css
/* Aurora Move */
@keyframes auroraMove1 { 0%{transform:translate(0,0) scale(1);} 100%{transform:translate(120px,90px) scale(1.3);} }
/* Duration: 12s infinite alternate ease-in-out */

@keyframes auroraMove2 { 0%{transform:translate(0,0) scale(1);} 100%{transform:translate(-140px,-80px) scale(1.2);} }
/* Duration: 15s infinite alternate ease-in-out */

/* Particle Float (Gold dust) */
@keyframes particleFloat {
  0%   { transform:translateY(0) scale(0.5); opacity:0; }
  15%  { opacity:0.8; }
  85%  { opacity:0.8; }
  100% { transform:translateY(-105vh) scale(1.2); opacity:0; }
}
/* Duration: 3.8s–6s varied, infinite linear */

/* Splash Zoom In */
@keyframes splashZoomIn { 0%{transform:scale(0.95); opacity:0;} 100%{transform:scale(1); opacity:1;} }
/* Duration: 1s cubic-bezier(0.19,1,0.22,1) */

/* Ring Rotate */
@keyframes ringRotate { 0%{transform:rotate(0deg);} 100%{transform:rotate(360deg);} }
/* Duration: 3.5s infinite linear */

/* Core Pulse */
@keyframes corePulse {
  0%,100% { transform:scale(1); filter:drop-shadow(0 0 4px rgba(201,160,84,0.5)); }
  50%     { transform:scale(1.15); filter:drop-shadow(0 0 14px rgba(201,160,84,0.9)); }
}
/* Duration: 2s infinite ease-in-out */

/* Progress Bar */
@keyframes loadProgressModern { 0%{width:0%;} 100%{width:100%;} }
/* Duration: 2.0s cubic-bezier(0.19,1,0.22,1) forwards */

@keyframes progressMove { 0%{background-position:0% 50%;} 100%{background-position:200% 50%;} }
/* Duration: 1.8s infinite linear */
```

### Exchange / Banner Animations
```css
/* Ken Burns (Banner) */
@keyframes bannerKenBurns { 0%{transform:scale(1) translate(0,0);} 100%{transform:scale(1.12) translate(-1%,-0.5%);} }
/* Duration: 24s infinite alternate ease-in-out */

/* Banner Shimmer */
@keyframes bannerShimmer { 0%{left:-150%;} 25%,100%{left:150%;} }
/* Duration: 6s infinite ease-in-out */

/* Banner Text Fade */
@keyframes bannerTextFade { 0%{opacity:0; transform:translateY(15px);} 100%{opacity:1; transform:translateY(0);} }
/* Duration: 1.4s cubic-bezier(0.19,1,0.22,1) */

/* Odds Flash */
@keyframes odds-flash-up   { 0%{background-color:rgba(46,212,122,0.5); border-color:#2ed47a;} 100%{background-color:transparent;} }
@keyframes odds-flash-down { 0%{background-color:rgba(244,67,54,0.5); border-color:#f44336;} 100%{background-color:transparent;} }
/* Duration: 1.2s ease-out */

/* Live Pulse Tag */
@keyframes live-pulse { 0%{opacity:0.6; transform:scale(0.95);} 50%{opacity:1; transform:scale(1.05);} 100%{opacity:0.6; transform:scale(0.95);} }
/* Duration: 1.8s infinite ease-in-out */

/* Floating Dice */
@keyframes float-dice { 0%{transform:translateY(0) rotate(0deg);} 100%{transform:translateY(-8px) rotate(15deg);} }
/* Duration: 3s ease-in-out infinite alternate */

/* Marquee Ticker */
@keyframes marqueeScrolling { 0%{transform:translateX(0);} 100%{transform:translateX(-100%);} }
/* Duration: 26s linear infinite */

/* 3D Card Flip */
.card-wrapper { transition: transform 0.6s cubic-bezier(0.175, 0.885, 0.32, 1.275); }
.card-wrapper.flipped { transform: rotateY(180deg); }

/* Aviator waiting pulse */
@keyframes pulse-waiting { 0%,100%{opacity:0.8; transform:scale(0.98);} 50%{opacity:1; transform:scale(1.02);} }
/* Duration: 1.5s infinite ease-in-out */

/* Aviator bounce */
@keyframes bounce-scale { 0%{transform:scale(0.9);} 70%{transform:scale(1.03);} 100%{transform:scale(1);} }
/* Duration: 0.5s ease-out */
```

---

## 11. Transition Curves & Timing

| Usage | Transition |
|---|---|
| **Default hover** | `0.15s` ease (color, border-color, background) |
| **Button press** | `transform 0.12s, filter 0.12s` |
| **Card hover lift** | `transform 0.18s, border-color 0.18s` |
| **Drawer slide** | `0.3s cubic-bezier(0.4, 0, 0.2, 1)` |
| **Modal appear** | `opacity 0.3s ease, visibility 0.3s ease` |
| **Dropdown** | `opacity 0.2s, transform 0.2s, visibility 0.2s` |
| **Game card hover** | `0.3s cubic-bezier(0.25, 0.8, 0.25, 1)` |
| **Odds box** | `0.2s cubic-bezier(0.4, 0, 0.2, 1)` |
| **Pulse bar height** | `height 0.4s ease` |
| **Splash fade-out** | `opacity 0.35s cubic-bezier(0.77, 0, 0.175, 1), visibility 0.35s` |

---

## 12. Interactive State Patterns

### Active / Press Feedback
```css
.btn:active, .chip-amt:active, .size-btn:active, .color-btn:active {
  transform: scale(0.95);
  transition: transform 0.1s ease;
}
```

### Hover Card Lift
```css
.room-card:hover     { transform: translateY(-4px); border-color: var(--text-faint); }
.game-card-item:hover { transform: translateY(-5px); border-color: #00e676; box-shadow: 0 8px 24px rgba(0,230,118,0.15); }
```

### Selected States
```css
/* Color button selected */
border-color: var(--gold); box-shadow: 0 0 0 3px rgba(255,197,61,0.25);

/* Number button selected */
border-color: var(--gold); background: var(--gold); color: #2b1e00;

/* Size button selected */
border-color: var(--violet); background: var(--violet-soft); color: var(--violet);

/* Chip amount selected */
border-color: var(--gold); color: var(--gold); background: var(--gold-soft);

/* Input focus */
border-color: var(--green); box-shadow: 0 0 0 3px rgba(46,212,122,0.15);
```

### Disabled State
```css
opacity: .4; cursor: not-allowed;
/* or */
opacity: 0.6; background: var(--surface-2); color: var(--text-faint); box-shadow: none;
```

---

## 13. Scrollbar Styling

```css
/* Thin scrollbar */
scrollbar-width: thin;

/* WebKit custom scrollbar */
::-webkit-scrollbar       { width: 4px; }
::-webkit-scrollbar-track  { background: var(--bg-soft); }
::-webkit-scrollbar-thumb  { background: var(--border); border-radius: 2px; }

/* Hidden scrollbar (for horizontal scroll areas) */
scrollbar-width: none;
::-webkit-scrollbar { display: none; }
```

---

## 14. Badge / Status Patterns

```css
.badge         { padding: 3px 11px; border-radius: 20px; font-size: 11.5px; font-weight: 700; }
.badge.won     { background: var(--green-soft); color: var(--green); }
.badge.lost    { background: var(--red-soft);   color: var(--red); }
.badge.pending { background: var(--gold-soft);  color: var(--gold); }

.alert-error   { background: var(--red-soft);   color: var(--red);   border: 1px solid rgba(255,75,110,0.3); }
.alert-success { background: var(--green-soft); color: var(--green); border: 1px solid rgba(46,212,122,0.3); }
```

---

## 15. Color-Coded Top Borders (Card Types)

```css
.room-card.sapre     { border-top: 3px solid var(--green); }
.room-card.becone    { border-top: 3px solid var(--red); }
.room-card.emred     { border-top: 3px solid var(--violet); }
.room-card.vip       { border-top: 3px solid var(--gold); }
.room-card.teenpatti { border-top: 3px solid var(--gold); }
```

---

## 16. Responsive Breakpoints

| Breakpoint | Behavior |
|---|---|
| `≤ 1100px` | Sub-navbar font shrinks |
| `≤ 992px` | Exchange layout stacks to single column |
| `≤ 900px` | Hero + grid-2 switch to single column; aviator layout stacks |
| `≤ 800px` | Stat row → 2 columns |
| `≤ 768px` | Mobile: bottom nav shows, hamburger shows, exchange elements compact, body gets 66px bottom padding |
| `≤ 600px` | Game cards → 2 columns |
| `≤ 480px` | Aviator console body stacks vertically |

---

## 17. Key Spacing Values

| Pattern | Value |
|---|---|
| Navbar padding | `16px 32px` |
| Container side padding | `0 24px` |
| Section padding | `56px 0` |
| Section tight | `32px 0` |
| Card padding | `24px` |
| Card margin-bottom | `20px` |
| Grid gap (rooms) | `18px` |
| Grid gap (exchange layout) | `16px` |
| Button padding | `12px 22px` |
| Input padding | `12px 14px` |
| Table cell padding | `11px 8px` |

---

## 18. Full `:root` Tokens Block (Quick Copy)

```css
:root {
  --bg:        #0b0e1d;
  --bg-soft:   #10142a;
  --surface:   #141833;
  --surface-2: #1a2040;
  --border:    #262b4a;
  --text:      #edeffa;
  --text-dim:  #8790b8;
  --text-faint:#5b628a;
  --green:     #2ed47a;
  --red:       #ff4b6e;
  --violet:    #a56bff;
  --gold:      #ffc53d;
  --green-soft:  rgba(46,212,122,0.12);
  --red-soft:    rgba(255,75,110,0.12);
  --violet-soft: rgba(165,107,255,0.12);
  --gold-soft:   rgba(255,197,61,0.12);
  --font-display: 'Space Grotesk', 'Segoe UI', sans-serif;
  --font-body:    'Inter', 'Segoe UI', sans-serif;
  --font-mono:    'JetBrains Mono', monospace;
  --radius-sm: 10px;
  --radius:    16px;
  --radius-lg: 22px;
  --shadow-card: 0 8px 30px rgba(0,0,0,0.35);
  --max-width: 1180px;
}
```

---

> [!TIP]
> To replicate this theme in another project:
> 1. Add the Google Fonts `@import` at the top of your CSS
> 2. Copy the full `:root` block above
> 3. Apply `font-family: var(--font-body)` on `body`
> 4. Use `var(--font-display)` for headings/brand
> 5. Use `var(--font-mono)` for stats/codes
> 6. Apply the body background gradient pattern
> 7. Copy animations as needed from Section 10
> 8. Match border-radius, shadow, and transition values from the tables above

> [!IMPORTANT]
> This guide covers **only visual theme tokens** — no content, logic, or data structures. Your other project's HTML structure and data should remain completely untouched.
