# Automatic testing

Everything below runs by itself. Nobody has to click through the site to know it still works.

## What runs, and when

| When | What | Where |
|---|---|---|
| **Every push to `main`** | Backend suites (money, cricket, Your 11, Ball by Ball, match betting, crash games — ~800 checks) and **browser tests** on every page at laptop and phone size | GitHub → **Actions** tab → **Tests** |
| **Every night (03:00 IST)** and on demand | The stress / road tests: 50 simulated players, parallel races, real-match replays | same place, the **Stress** job |

A run uses a **fresh test database** and the simulated cricket league. It never touches the live site,
real players or real money. It costs no Claude usage; GitHub's free plan covers it.

## Reading the result

- **Green tick** next to a commit on GitHub = everything passed. Safe to update cPanel.
- **Red cross** = something broke. Open it: the failing check says what and where, in plain words
  (e.g. *"Play should be on the same row as the bet amount"*).
- Every browser run keeps a **report** (Actions → the run → *Artifacts* → `browser-test-report`).
  Download, unzip, open `index.html`: every page has a **full screenshot at laptop and phone size**,
  so the whole site can be reviewed in a minute without opening it.

## What the browser tests check

On every page (home, Vimaan, the four colour rooms, Teen Patti, Mines, Chicken Road, Astronaut,
Cricket, Your 11, Ball by Ball, Cashier), desktop **and** phone:

- the page loads, with **no JavaScript errors** and **no server errors**;
- nothing makes the page **scroll sideways** (the classic phone-layout bug);
- the game tabs' labels fit their pills (no overflowing "Chicken Road").

Plus the rules agreed for specific screens:

- **Home cricket table:** BM / F / S on every match, full match names (never cut with "…"), no prices
  on phones, a row opens its match page.
- **Cricket match page:** *Recent Result* shows the newest ball first.
- **Ball by Ball:** *This over* shows the newest ball first; markets render.
- **Chicken Road:** on phones Play sits beside the bet amount, on the first screen; a full round
  (bet → cross → cash out) works; the background music starts.
- **Astronaut:** the animated character is drawn and the round runs.

**When you ask for a change, the rule goes into the tests**, so it is checked on every push from then on.

## Running it on this computer

```
npm run test:seed     # once: create the test account in the dev database
npm run test:ui       # every browser test, laptop + phone (~4 minutes)
npm run test:ui:report
```

Files: `playwright.config.js`, `e2e/*.spec.js`, `.github/workflows/tests.yml`,
`php-backend/tools/e2e-seed.php`.
