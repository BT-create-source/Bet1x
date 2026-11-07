# Dragon Tiger integration

The game is served at `dragontiger.html`, with the same Originals API, wallet transactions, fairness seeds, history and admin settings as the other PHP games. It is listed in the home page and game drawer. No Python runtime, third-party assets, new tables or database migration are required.

Rules: two independent draws from a 52-card pack, ace low and king high. Suits do not break ties. Winning Dragon/Tiger bets return 2x the stake; a tie returns half of those stakes. Winning Tie bets return 12x. All returns include the stake. Before flooring and maximum-return caps, RTP is 25/26 (96.1538%) on Dragon/Tiger and 12/13 (92.3077%) on Tie. These explicit site terms replace the reference desktop game's 8x Tie return. See `ORIGINALS-LICENSE.md` for reference provenance.

Bet confirmation uses the existing shared 30-second Originals cycle: 15 seconds open, then 15 seconds closed. The server checks the phase and submitted round ID after acquiring the player's lock, before debiting the wallet. Chips on the table are selections, not accepted bets. Like the other Originals, each confirmed deal has its own seeded result; the shared clock governs when bets are accepted.

Admin enablement, minimum/maximum total bet and maximum return are discovered automatically from the catalogue. RTP is fixed by the rules. The catalogue's headline RTP describes the main bets; the page also discloses Tie's separate RTP.

Validation:

```text
php php-backend/tests/dragontiger.php --math-only
php -d extension=pdo_pgsql php-backend/tests/dragontiger.php
php php-backend/tests/originals_clock.php
node backend/test_originals_clock.js
npx playwright test e2e/originals.spec.js --grep "dragontiger:|lobby Originals|operator can review"
php -d extension=pdo_pgsql -d extension=curl php-backend/test_subadmin.php
```

The wallet test refuses production mode, creates an isolated account in the development database, checks wallet/ledger/history/idempotency and real betting-window rejection, then deletes its own account and records. The browser tests use the existing seeded e2e account and local server configuration.

The subadmin HTTP suite includes Dragon Tiger in its funded-player flow, transaction classification, turnover, per-user/per-game reports, admin statistics and cash-outs. The integration audit passed all 186 checks. Subadmin sessions retain their existing role: funding, cash-outs and reporting for assigned users; game configuration stays admin-only. The admin win monitor uses a 3.3 payout spread for this game, covering the Tie bet's approximately 3.2 standard deviation.

Deploy the HTML, assets and PHP changes together using the existing cPanel Git deployment. Game-shell and Originals script URLs are versioned so existing pages pick up the new menu entry and RTP label.
