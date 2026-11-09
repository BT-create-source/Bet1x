# Public Originals rounds

Dice, Wheel, Plinko, Keno, Roulette, Baccarat, Dragon Tiger, Andar Bahar, Slots and Matka use public rounds. Blackjack and Coinflip retain their interactive personal rounds, as requested.

Every public round follows the UTC server clock: 15 seconds open for betting, then 15 seconds showing the result. The browser cannot extend or restart a window. Anonymous visitors, signed-in spectators and bettors receive the same published result and history from `/api/originals/:game/public`. No bet is required to see a result. Rounds advance even without bets; empty rounds since feature initialization are derived on demand.

Bets are recorded as `queued` and stakes debited during the betting phase. Neither the result nor its server seed is returned before closing. When the window closes, public polling, a state request, a subsequent bet or the existing cron tick settles all due bets for that game, including bets from disconnected players. Settlement, credit and ledger entry are atomic under a per-game database lock; a repeated request cannot pay twice. Historical rounds predating this feature remain personal records.

The server commits the SHA-256 of each round's derived seed before close and publishes that seed with the result. Each game's client seed is `public:<game>` and its nonce is the shared round ID. Payouts use the configuration frozen for that round and the player's recorded selections. Keno picks, roulette bets and other choices therefore affect payouts rather than the draw. Wheel layouts share one stop angle; segment counts map that angle to their own layouts. Plinko layouts use prefixes of the same 16-step path. Risk-dependent multipliers can differ while the underlying event is the same. The public panel labels reference layouts and shows no invented personal win/loss.

No manual migration is needed: queued bets use the existing round table and public commitments/configuration use GameState. A composite index for pending settlement is added automatically on first use (schema version 2), under a schema lock. Deploy `originals_shared.php` alongside the routes, framework, CSS, cron and versioned HTML files. Current admin statistics count queued bets as active. Player, admin and subadmin accounting continues to read the existing ledger; private bet history remains separate from public results.

Validation:

```text
php -d extension=pdo_pgsql php-backend/tests/originals_public.php
php php-backend/tests/originals_clock.php
node backend/test_originals_clock.js
npx playwright test e2e/originals-public.spec.js
```

The public-round money test refuses production mode and removes its isolated player accounts and their records. It verifies all ten games with two bettors, a signed-in non-bettor and an anonymous spectator, including shared seeds, publication timing, settlement against the published draw, replay protection and wallet/ledger reconciliation.
