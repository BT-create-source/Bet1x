# Cricket road tests

High-volume, end-to-end tests for **Your 11** and **Ball by Ball**, with 50 fake players each.
They write to the database in `php-backend/.env` (use the development database), touch only their own
data (users named `road_*`, private far-future mock matches) and clean up after themselves.

```
npm run test:cricket:road                 # everything (about 5–6 minutes)
php -d extension=pdo_pgsql -d extension=curl -d extension=zip php-backend/tests/run_all.php --quick
```

Run a single suite with `php -d extension=pdo_pgsql php-backend/tests/<file>.php`. Options: `--users=N`
(Your 11, Ball by Ball, HTTP), `--matches=N` (Dream11), and `--keep` (leave the data behind for inspection).
Each run writes a full report to `tests/reports/`.

| Suite | What it proves |
|---|---|
| `road_dream11_points.php` | Player points equal Dream11's published T20 table **exactly**: 200 real IPL/T20I matches from Cricsheet go through the production pipeline and are compared with an independent oracle (`road_oracle.php`), player by player, plus worked edge cases (overthrow boundaries, relay run-outs, caught & bowled, stumpings off wides, substitutes, super overs). The first run downloads Cricsheet's archives into your temp folder (`CRICSHEET_CACHE` to override). |
| `road_your11.php` | 50 players, about 170 teams, illegal teams, every contest type, parallel races (last head-to-head spot, double joins, double spends, the multi-entry cap), team switches, lineups, the deadline, a full live match, oracle-checked points and team totals, settlement raced by three processes, a rained-off match refunded, and every rupee reconciled against the ledger. |
| `road_bbb.php` | 50 players betting on every ball of a T20 (about 6,700 bets), bad bets, parallel races (a ₹100 wallet firing five bets, the per-ball cap, duplicate pushes settling one ball), each result checked against an independent reading of the raw ball, pools = payouts + rake, refunds, a rained-off match, and reconciliation. |
| `road_http.php` | Both games over real HTTP on a private server (port 5099): token attacks, operator routes, other players' teams, 50 signed-in players building teams, joining and betting. Live betting needs a demo match in play on the real clock; if none is live, that part reports SKIPPED. |

| `road_exchange.php` | Match betting (Match Odds, Bookmaker, Fancy, Cash Out): 50 players through a whole T20 (about 3,700 bets), bad bets, simultaneous bets from separate processes (a ₹250 wallet firing five bets, 20 players at once, a triple cash-out), the house cap, every result recomputed from the simulator's raw balls, every bet's terms and payout recomputed, "too late" voids checked against the ball times, a rained-off match refunded, and reconciliation. |
| `road_sportmonks_replay.php` | The two real Sportmonks matches archived on 9 Oct 2026 (Pakistan v Sri Lanka, India v West Indies) replayed snapshot by snapshot with 40 players betting, under the polled-feed rules: final scores = the real ones, every Ball by Ball round = an independent reading of its ball, pools exact, Match Odds on the real winner, reconciliation. Needs the archive in `cricket_feed_raw` (skips without it). |

After `road_http.php` the `road_h*` users stay (they're reset on every
run) because their Ball by Ball bets are part of a real demo-league pool.
