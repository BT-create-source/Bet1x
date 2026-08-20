# Deploying bet1x

One Node process serves both the JSON API and the static site, so a deployment is a single
container (or a single `node backend/server.js` behind a reverse proxy) plus a PostgreSQL database.

---

## 1. Before you deploy: things you must do by hand

These are not code problems — they are secrets and obligations that only you can settle.

### Secrets that are already in git history

`backend/.env` and the hardcoded gateway keys in `api/config.php` were committed to this repository.
`.gitignore` and the move to environment variables stop *new* commits, but anything already in the
history is still there and must be treated as public:

```bash
# 1. Rotate first. Change the Postgres password and issue new payment-gateway keys.
# 2. Then stop tracking the files:
git rm --cached backend/.env
git rm -r --cached backend/node_modules
git commit -m "Stop tracking secrets and vendored dependencies"
```

Rewriting the history itself (`git filter-repo`, BFG) only matters if the repository has been, or
will be, shared. Rotating the credentials is what actually closes the exposure.

### Licensing

This is a real-money gambling platform. Operating one requires a licence in essentially every
jurisdiction, and the admin console includes house-side controls that decide round outcomes
(`/api/game_sync.php?action=admin_set_bot_takeover`, the per-game rig endpoints, and the Teen Patti
seat that is renamed to "Admin" when the takeover engine selects it). Running those against paying
players is fraud in most places and will also breach the terms of any payment processor you connect.
Get legal and regulatory advice before taking real money, and decide deliberately what stays enabled.

### Payments

`api/deposit.php`'s card/gateway path is intentionally not implemented — the old code carried
placeholder Razorpay keys and had no signature verification. The UPI flow records a **pending**
deposit that an operator approves in the admin console. Before going live you need either:

- a real gateway integration with server-side webhook signature verification, or
- a documented manual reconciliation process for the pending-deposit queue.

---

## 2. Configuration

Copy `backend/.env.example` to `backend/.env` and fill it in. In production the process **refuses to
start** if `APP_SECRET`, `ADMIN_PASSWORD_HASH` or `DATABASE_URL` are missing — that is deliberate.

```bash
# a signing secret for session tokens
npm run secret

# a bcrypt hash of the operator password (never store the password itself)
npm run hash-admin-password -- 'the-password-you-chose'
```

The settings that most often get missed:

| Variable | Why it matters |
|---|---|
| `NODE_ENV=production` | Turns on the strict config checks, HTTPS redirect, HSTS, JSON logging, and generic error bodies. |
| `TRUST_PROXY` | Number of proxies in front of the app. If it stays `0` behind nginx, every visitor looks like the proxy and per-IP rate limiting protects nobody. |
| `SIGNUP_BONUS=0` | The development default hands new accounts free credits. |
| `ALLOW_JSON_FALLBACK=false` | Defaults to false in production. Leaving it on means a database outage silently diverts balances into flat files. |
| `CORS_ORIGINS` | Leave empty for the normal same-origin setup. Only set it if the frontend is hosted separately. |

---

## 3. Database

```bash
npm run install:backend      # production dependencies only
npm run prisma:generate
npm run prisma:migrate       # prisma migrate deploy
```

`prisma migrate deploy` applies committed migrations without prompting, which is what you want in
CI/CD. Use `npx prisma migrate dev` only on a development machine.

---

## 4. Run it

### Docker Compose (recommended)

Create a `.env` next to `docker-compose.yml`:

```env
POSTGRES_PASSWORD=<a long random password>
APP_SECRET=<npm run secret>
ADMIN_PASSWORD_HASH=<npm run hash-admin-password -- '...'>
TRUST_PROXY=1
SIGNUP_BONUS=0
```

```bash
docker compose up -d --build
docker compose exec app npx prisma migrate deploy
```

The app binds to `127.0.0.1:5000`, so put TLS in front of it.

### Directly with a process manager

```bash
NODE_ENV=production node backend/server.js
```

Run it under systemd or PM2 so it restarts on failure. The process handles `SIGTERM` by draining
in-flight requests (15 second grace period) before exiting, so restarts do not cut a bet or payout
in half — make sure your supervisor sends `SIGTERM`, not `SIGKILL`.

### Reverse proxy (nginx)

```nginx
server {
    listen 443 ssl http2;
    server_name example.com;

    ssl_certificate     /etc/letsencrypt/live/example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/example.com/privkey.pem;

    location / {
        proxy_pass         http://127.0.0.1:5000;
        proxy_http_version 1.1;
        proxy_set_header   Host              $host;
        proxy_set_header   X-Real-IP         $remote_addr;
        proxy_set_header   X-Forwarded-For   $proxy_add_x_forwarded_for;
        proxy_set_header   X-Forwarded-Proto $scheme;   # required by FORCE_HTTPS
        proxy_read_timeout 65s;
    }
}
```

With exactly this one proxy in front, set `TRUST_PROXY=1`.

---

## 5. Health checks

| Endpoint | Meaning |
|---|---|
| `GET /api/health` | Liveness. The process is up. |
| `GET /api/ready` | Readiness. Returns 503 while the datastore is unreachable. Point your load balancer here. |

---

## 6. Verifying a deployment

```bash
npm test          # security and regression
npm run test:e2e  # the product itself
```

`backend/test_backend.js` boots the app on an ephemeral port and runs ~100 assertions covering the
happy paths plus every hole closed during hardening: token forgery, cross-account wallet access, the
open balance-adjust endpoint, unauthenticated admin/rig endpoints, double-spend races, and static
exposure of `.env` / `.git` / PHP source.

`backend/test_e2e.js` answers the other question — does the product work. It plays a full player
journey (sign up, get topped up, bet in every game, win or lose, deposit, withdraw) and checks the
wallet after each step, including the deposit and withdrawal approval lifecycle and the
double-approve race. Because it waits on the real game loops it takes about two minutes.

Run both against a **development** database — they create accounts and transactions.

Then check by hand that these all return 401 or 403 with no token:

```bash
curl -i https://your-host/api/admin/stats
curl -i -X POST https://your-host/api/wallet/adjust -d '{"username":"someone","delta":100000}' -H 'Content-Type: application/json'
curl -i https://your-host/backend/.env
```

---

## 7. Operating notes

**Single instance.** Aviator's crash-point tick loop, the Mines sessions and the Teen Patti timers
all live in process memory. Running two replicas gives players two different games under one domain,
and a restart abandons any round in flight. Scale vertically, or move that state into Postgres/Redis
first.

**Backups.** Everything that matters — balances, transactions, deposits, withdrawals — is in
Postgres. Back it up on a schedule and test a restore. `backend/data/*.json` is a development
convenience, not a backup.

**Logs.** Production emits one JSON object per line. Ship them somewhere durable; the audit trail
for balance adjustments, deposit/withdrawal approvals and admin sign-ins lives there.

**Operator accounts.** There is exactly one, from `ADMIN_USERNAME` / `ADMIN_PASSWORD_HASH`, and its
sessions last 8 hours. If more than one person needs access, give them separate credentials by
extending `lib/auth.js` — do not share the one password.

**Rotating `APP_SECRET`** invalidates every session immediately; everyone signs in again. That is
the revocation mechanism.
