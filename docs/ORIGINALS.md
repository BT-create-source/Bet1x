# bet1x Originals

The PHP/cPanel application now contains Dice, Coinflip, Wheel, Plinko, Keno, Roulette, Baccarat, Andar Bahar, Slots, Blackjack and Matka. Existing Crash, Mines and multiplayer Teen Patti remain the site's versions. All eleven new pages share the site's header, wallet, colours, navigation, history and fairness controls.

## Implementation

- `php-backend/lib/originals.php`: catalogue, configuration, transactional wallet/ledger settlement, idempotency, saved multi-step state and HMAC seed stream.
- `php-backend/games/originals/`: rules and payout tables for each game.
- `php-backend/routes/originals.php`: `/api/originals/*` and operator configuration endpoints.
- `assets/js/originals.js`, `assets/css/originals.css`: shared player page framework.
- `assets/js/admin-originals.js`: the admin dashboard's Originals tab.
- Eleven root HTML pages, lobby covers in `assets/game_*.svg`, lobby cards and shared game drawer entries.

Every stake and payout is committed with its round in one database transaction. Duplicate play references replay the original round; action steps prevent duplicate hits/cash-outs. Blackjack's dealer hole card and active server seeds stay server-side. Open rounds survive reloads and retain their starting RTP and return limit. Seed hashes are available before the first bet; rotation reveals prior seeds and is blocked while any round is open.

## Game differences

This integrates the games into the server-backed site rather than embedding the source simulator. Wheel, Plinko, Keno, Slots, Andar Bahar and Matka use independently calculated tables. Roulette follows single-zero payouts; Baccarat and Blackjack use an infinite shoe. Blackjack supports hit, stand, double, one split and insurance. Matka is explicitly an **individual instant draw**, with market labels rather than a shared scheduled market. The UI states these rules.

RTP figures are expectations before the configured maximum return and currency flooring. Baccarat's displayed figure is for Banker; other bets have different returns. Blackjack's figure is an estimate with good play. The maximum return applies to total returned funds, including the stake. Configurable games accept 80–99% RTP; the other games' rules fix their returns.

## Local verification

Use a development database configured in `php-backend/.env`. The API suite refuses production mode, creates only `ortest_*` players, and cleans up their rows. It temporarily changes Originals settings, so avoid running it while anyone else uses the development instance.

```powershell
npm run test:originals
php php-backend/tools/e2e-seed.php
$env:PHP_EXT_FLAGS = '' # when the PHP extensions are already loaded
npm run test:ui:originals
```

The API tests cover exact table expectations, simulated returns, game rules, real HTTP settlement, wallet/ledger reconciliation, races, duplicate requests, operator access, seed reveal and player history. Browser tests place real test bets on every new page on desktop and mobile, check balances/history, round recovery, verification, game navigation, lobby filtering and admin settings. Browser tests substitute local font fallbacks for external Google Fonts. Required PHP modules include PDO for the chosen database and curl; Playwright uses the project's existing Chromium installation.

## cPanel deployment

These games target the **PHP backend**. The legacy Node application does not implement the Originals API. Use `php -S 127.0.0.1:5000 router.php` for manual local preview.

The existing `.cpanel.yml` copies the root HTML pages, assets and PHP backend, and now also copies `ORIGINALS-LICENSE.md`. No frontend build is needed. The new tables self-create on first Originals API use; the database account needs table/index creation privileges. PostgreSQL administrators can instead apply `php-backend/sql/migration-019-originals-postgres.sql`; fresh MySQL installs include the tables in `schema-mysql.sql`.

After deploying the reviewed changes with the existing cPanel Git workflow, open the Originals lobby filter, visit the admin Originals tab, check game availability and limits, and verify `/api/originals/catalogue` returns eleven entries. Existing live `.env` settings must stay on the server. Local test reports do not constitute a production deployment or a MySQL runtime test.

For a manual file update to an existing installation, `php-backend/tools/package-originals.ps1` creates `originals-update.zip` with 64 feature files and their directory paths. Extract it over the matching PHP site's document root; it contains no environment files, test users or test tools. It is an update package, not a fresh installation. The older `cpanel_deploy.zip` is unrelated and has not been rebuilt.

## Funding and operator integration audit

`php-backend/test_subadmin.php` includes a combined flow: a sub-admin creates a player, confirms their deposit, the player settles all eleven Originals, and the operator and sub-admin reports are checked against the player's ledger. It then pays a routed cashier withdrawal and a counter cash-out, checking that the game wallet updates and sub-admin inventory reconciles. It also checks the platform-funded player's Originals bet followed by an Admin-approved withdrawal. Game play changes player balances and platform results; it does not credit or debit a sub-admin's inventory.

Transaction responses carry server-derived game labels and stake/payout classification. The admin betting history uses those fields, and new stakes and payouts carry their round reference. `/api/admin/game-stats` includes all Originals. The game framework refreshes balances periodically and when the tab regains focus, and discards responses from an account whose token changed during the request. Server-side wallet checks remain authoritative even when the browser has an older balance.

Known inherited limitation: the username settings route changes `User.username` and migrates Originals seeds/rounds, but older `Transaction`, `Deposit` and `Withdrawal` records remain under the old name. Renaming can therefore detach older cashier history, withdrawal eligibility and sub-admin gameplay reporting. This audit does not claim username-renaming history is solved. Cash/UPI/bank payment confirmation remains the existing manual operator workflow; the platform records approvals rather than independently confirming external money transfers. Production deployment and MySQL runtime verification remain separate from these local database/browser checks.

Attribution and the upstream license are retained in `ORIGINALS-LICENSE.md`.
