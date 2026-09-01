# Deploying the PHP backend to cPanel

This replaces the Node/Express process. The frontend is unchanged — every HTML, CSS and JS file in
the repository stays exactly as it is.

---

## 1. What goes on the server

Upload the whole repository to the account's document root (`public_html`), or `git pull` it there.
Everything is needed except `backend/` and `node_modules/`, which the `.htaccess` blocks from the
web anyway and which you may leave in place for reference.

The two files that make it work:

| File | Job |
|---|---|
| `.htaccess` (repo root) | Sends every `/api/*` request into the PHP front controller, and blocks the paths that must never be served |
| `php-backend/index.php` | The front controller — the whole API |

**The dead PHP layer has been removed.** `api/`, `aviator/`, `mining/`, `teenpati/` and
`backend/api/` contained an older, unused PHP implementation that the root `.htaccess` had to keep
unreachable. Two things made that worth deleting rather than merely blocking:

* `api/config.php` and `backend/api/config.php` carried a **hardcoded superadmin password**. The
  only thing standing between that and the public internet was a rewrite rule.
* Under PHP, Apache would *execute* those files rather than leak them as text, so a single
  `.htaccess` mistake — mod_rewrite unavailable, a botched edit — meant the old login logic running
  against the old config.

Deleting them is safe because the API rewrite is unconditional (`RewriteRule ^api/.*$` has no `!-f`
guard), so `/api/*` reaches the front controller whether or not a matching file exists. The denial
rules for those paths are left in `.htaccess` as belt-and-braces in case anything is ever restored.

The `.htaccess` is still load-bearing for `backend/`, `node_modules/`, `.env` and `.git` — see §6.

---

## 2. Database

Create a MySQL database and user in cPanel, grant all privileges, then run the schema once:

```bash
mysql -u DBUSER -p DBNAME < php-backend/sql/schema.sql
```

Or paste the file into phpMyAdmin's SQL tab.

This creates the 11 tables that mirror the Prisma models, 4 runtime tables that replace state which
used to live in the Node process's memory, and seeds the six Teen Patti rooms.

**Do not run `sql/schema-cricket.sql`** — it does not exist in this build. The two cricket games are
deferred to v2.

### The `Admin` house account — required, and it fails silently without it

`schema.sql` seeds a user row named `Admin`. **Do not delete it, and if you are migrating an existing
database, make sure the row exists there too.**

Teen Patti's two house-win paths — the operator rig (`/api/teenpatti/admin/rig`) and the per-table
takeover — both seat a player literally named `Admin`. When the hand is dealt, the engine checks that
every seated non-filler can cover the boot, looks the name up in `User`, finds nothing, and ejects the
seat. The operator flips the rig, the seat vanishes, and the house wins nothing — with no error
anywhere. The original Node build behaves identically; this was an install gap in both, found during
live-play testing of the port.

The rest of the design assumes the row exists: the engine tops the account up to 5000 before each
hand, credits it the pot when it wins, and the superadmin dashboard excludes it from house-profit
maths by name. The winnings need a real wallet to land in.

The seeded row's password column deliberately holds a value that is not a valid bcrypt hash, so
nobody can sign in as the house. Operators sign in with `ADMIN_USERNAME` / `ADMIN_PASSWORD_HASH`;
this is a ledger account, not a login.

### Migrating existing data

Table and column names are identical to the Prisma model names, so a dump from the old PostgreSQL
database loads with no renaming. Order matters because of the foreign key:
`User`, `Transaction`, `Deposit`, `Withdrawal`, `PaymentLog`, `GameState`, `RecentResult`,
`ChatMessage`, `TeenPattiRoom`, `TeenPattiSeat`, `GameBet`.

Password hashes carry over untouched — see §5.

---

## 3. Configuration

### Generate the production .env — do not hand-edit the development one

The most likely way to go live insecurely is to upload the development `.env`. Every fail-fast guard
in `config.php` only runs when `NODE_ENV=production`, and the development file sets
`NODE_ENV=development` — so the entire safety net sits switched off, with a placeholder `APP_SECRET`
(forgeable session tokens), a plaintext `admin123` password, and `DISABLE_RATE_LIMITS=true`.

Generate a correct one instead:

```bash
php php-backend/tools/make-production-env.php \
    --domain=https://yourdomain.com \
    --db-name=cpaneluser_bet1x --db-user=cpaneluser_bet1x --db-pass='...'
```

It writes `php-backend/.env.production` (mode 0600) with a 96-character `APP_SECRET`, bcrypt hashes
for both operator accounts, HTTPS forced, an exact CORS allowlist, rate limits on, JSON fallback off,
`SIGNUP_BONUS=0`, and the withdrawal controls enabled. **It prints the two operator passwords once —
save them immediately**, only the hashes are written to disk.

Then on the server:

```bash
mv php-backend/.env.production php-backend/.env && chmod 600 php-backend/.env
```

Confirm the cutover actually took — this is invisible from the browser otherwise:

```bash
curl -s https://SITE/api/health     # must show "env":"production"
```

And confirm the old credentials are dead (must return 401):

```bash
curl -s -X POST https://SITE/api/admin.php -d 'action=login&username=admin&password=admin123'
```

### Apply the risk-control migration

```bash
mysql -u DBUSER -p DBNAME < php-backend/sql/migration-002-risk-controls.sql
```

Additive and safe on a live database. Without it the withdrawal/abuse checks degrade to permissive
rather than erroring, so applying it is what actually switches them on.



```bash
cp php-backend/.env.example php-backend/.env
```

Fill it in. The four that matter most:

| Variable | Why |
|---|---|
| `APP_SECRET` | Set it to the **same value the Node build used** and every session token already in a player's browser stays valid. Change it and everyone is signed out once — nothing else breaks. |
| `ADMIN_PASSWORD_HASH` | `php -r "echo password_hash('your-password', PASSWORD_BCRYPT, ['cost'=>12]);"` |
| `DATABASE_URL` or `DB_*` | Note the scheme is now `mysql://`, not `postgresql://` |
| `APP_TIMEZONE` | Must match the zone the Node process ran in. Colour-prediction history timestamps are bare local `HH:MM:SS` with no date and no offset, so a wrong zone shifts them by hours. |

`php-backend/.htaccess` blocks HTTP access to the whole directory, so `.env` is not web-readable.

---

## 4. The cron job

cPanel → **Cron Jobs** → add, every minute:

```bash
* * * * * /usr/local/bin/php /home/USERNAME/public_html/php-backend/cron/tick.php >/dev/null 2>&1
```

Adjust the PHP binary path and the document root to match the account.

**What breaks without it:** nothing a player can see while they are playing — every game page polls,
and the traffic that reads a game is what advances it. What stops is an *idle* site: an empty Teen
Patti lobby never fills, a colour round that ends with nobody watching does not settle until the
next visitor arrives, and Aviator sits in whatever phase it was in when the last player left. The
cron runs exactly the same functions those requests would have, so an unattended site keeps running.

### Detecting a dead cron

Because none of the above breaks page loads, a stopped cron is otherwise silent — you would find out
from customer complaints. Each completed tick now records a heartbeat, and `/api/ready` turns it
into an alertable signal:

```bash
curl -s https://SITE/api/ready
```

```json
{"ready":true,"database":"connected","store":"mysql","cron":"ok","cron_age_sec":12,"env":"production"}
```

Once the heartbeat is older than `CRON_STALE_SECONDS` (default 300s — four missed runs), the same
endpoint returns **HTTP 503** with `"cron":"stale"`. Point any uptime monitor at `/api/ready` and
alert on non-200; that one check covers both a dead database and a dead cron.

### The nightly backup

Add a second cron job, offset from the minute tick so the two never overlap:

```bash
17 3 * * * /usr/local/bin/php /home/USERNAME/public_html/php-backend/cron/backup.php >/dev/null 2>&1
```

Set `BACKUP_DIR` to a path **outside** `public_html` — the dumps contain every password hash on the
platform. The script writes a compressed `mysqldump`, prunes anything older than `BACKUP_KEEP_DAYS`
(default 14), and treats a dump under 2 KB as a failure, deleting it rather than leaving a truncated
file to be mistaken for a good backup.

**Test the restore before you need it.** An untested restore is a guess:

```bash
gunzip -c backups/bet1x-YYYY-MM-DD-HHMM.sql.gz | mysql -u DBUSER -p SCRATCH_DB
```

Then compare `SELECT COUNT(*) FROM User` and `SELECT SUM(wallet_balance) FROM User` against the live
database — total wallet liability is the number that must match exactly.

---

## 5. Passwords — one check before you trust the cutover

Existing hashes are bcrypt (`$2a$` and `$2b$`), written by bcryptjs. PHP's `password_verify()`
handles both, so nobody should be locked out — but confirm it on **your** host's PHP version rather
than taking it on trust. Take a hash from the `User` table whose password you know and run:

```bash
php -r "var_dump(password_verify('the-known-password', '\$2b\$12\$paste.the.hash.here'));"
```

Expect `bool(true)`. If it returns false, tell me — the fix is a transparent rehash at next login,
never a lockout, but it needs to be built before go-live rather than after.

---

## 6. Verify after deploying

Run these in order. Each one should behave as described.

| # | Check | Expected |
|---|---|---|
| 1 | `curl https://SITE/api/health` | `{"status":"ok","service":"bet1x-backend","timestamp":"..."}` |
| 2 | `curl https://SITE/api/ready` | `{"ready":true,"database":"connected",...}` — if `false`, the DB credentials are wrong |
| 3 | `curl https://SITE/api/auth.php` | JSON, **not** PHP source and **not** the old implementation's output. If you see anything else, the `.htaccess` rewrite is not active — check `AllowOverride`. |
| 4 | `curl https://SITE/backend/.env` | 403 or 404, never the file |
| 5 | `curl https://SITE/php-backend/lib/db.php` | 403, never the source |
| 6 | Open the site, sign up, log in | Balance shows, no console errors |
| 7 | Open `win.html` | Timer counts down, history fills, a round settles |
| 8 | Open `aviator.html` | Plane flies, crashes, next round starts ~9s later |
| 9 | Place a bet and cash out on Aviator | Balance moves by the right amount both ways |
| 10 | Open `teenpatti.html`, join a room | Fillers arrive, a hand is dealt, turns advance |
| 11 | Play a Mines round | Reveals work, cash-out pays, double-clicking Start takes one stake |
| 12 | Log into `admin.html` | All six tabs load; Cricket Ops is hidden |
| 13 | Toggle a bot percentage in admin | `/api/admin/rig-audit` shows decisions accumulating |
| 14 | Open `superadmin.html` | Dashboard populates from the transaction ledger |

**Check 3 is the important one.** Under Node those `.php` paths were only ever leaked as source
text; now that the backend genuinely is PHP, Apache would *execute* the stale files in `api/` if the
rewrite were not catching every `/api/*` request first. That is why the rewrite has no `!-f`
condition — adding one would let those files win precisely because they exist.

---

## 7. Rolling back

Nothing in this port modifies the Node backend. `backend/server.js` and its data are untouched, so
rolling back is: stop using the PHP host, point DNS back, start Node. The only shared state is the
database, and the two use different engines (PostgreSQL vs MySQL), so they cannot corrupt each
other's data — but they also do not share it, so anything played on one is not visible to the other.
Pick one and stay on it.

---

## 8. Known differences from the Node build

Four, all documented in full where they occur in the code:

1. **Aviator's in-flight crash intercepts are lazy.** They evaluate on each incoming request rather
   than every 100 ms. Because every player in a round polls independently at 250 ms, they fire
   within a fraction of a second of where they used to. The one genuine loss: if nobody polls at all
   between a cash-out and the natural crash, the erosion trip is missed and the house makes slightly
   less on that rigged round. Players see no difference.

2. **Mines boards now survive a restart.** They lived in process memory before, so a Node restart
   wiped every board in flight. They are rows now. Strictly better; still a difference.

3. **Rate-limit counters now survive a restart** for the same reason. A limit is genuinely enforced
   across a deploy where it previously reset.

4. **A Teen Patti seat records its heartbeat at join.** The Node build only recorded one when the
   player first polled for room state, and got away with it because its presence sweep ran on a
   five-second timer, so the client's first poll always won that race. Here the sweep is synchronous
   at the top of every Teen Patti request, so without recording at join the player's own next request
   would evict them and nobody could stay seated. Net effect: a player who joins and then vanishes is
   removed after ~10 seconds rather than at the next 5-second tick.

Everything else — routes, JSON shapes, status codes, error strings, game rules, payout maths, the
house-edge engine, the admin and superadmin surfaces — is reproduced as-is, including the handful of
latent bugs listed in the migration dossier.
