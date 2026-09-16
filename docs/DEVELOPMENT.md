# Running Purchases locally

Two servers and a database. The React app talks to the PHP API; the API talks
to Smart Books, Inventory, Manage and the portal — which, locally, are all
answered by one stub.

## What you need

- PHP 8.2+ with `pdo_pgsql` and `curl`
- PostgreSQL 14+
- Node 22+

## 1. Database

```bash
createuser --pwprompt purchases_dev      # password: purchases_dev
createdb -O purchases_dev purchases_dev
```

## 2. API

```bash
cd server-php
cp .env.example .env
```

Fill in the database block, and point the cross-app bases at the stub:

```ini
APP_ENV=local
DB_NAME=purchases_dev
DB_USER=purchases_dev
DB_PASS=purchases_dev

BOOKS_API_BASE=http://127.0.0.1:8792
INVENTORY_API_BASE=http://127.0.0.1:8792
MANAGE_API_BASE=http://127.0.0.1:8792
PORTAL_AUTH_BASE=http://127.0.0.1:8792

CORS_ALLOWED_ORIGINS=http://127.0.0.1:5173
```

`PORTAL_AUTH_BASE` pointing at the stub is what lets you sign in without a real
portal session. **It belongs in a local `.env` and nowhere else** — a deployed
build always talks to `my.aicountly.com`.

```bash
php bin/migrate.php

# The stub, standing in for Books, Inventory, Manage and the portal.
PHP_CLI_SERVER_WORKERS=6 php -S 127.0.0.1:8792 tests/stub/router.php &

# The API itself.
PHP_CLI_SERVER_WORKERS=6 php -S 127.0.0.1:8791 -t . index.php &
```

`PHP_CLI_SERVER_WORKERS` matters: the built-in server is single-threaded, so
without it the API blocks on its own call to the stub and a dashboard composed
from three sources times out waiting for itself.

## 3. App

```bash
cd web
npm install
printf 'VITE_API_BASE_URL=http://127.0.0.1:8791\nVITE_APP_ENV=local\n' > .env.local
npm run dev
```

Then, in the browser console once, to stand in for the SSO hop:

```js
localStorage.setItem('auth_token', 'preview-auth-token')
localStorage.setItem('purchases:scope', JSON.stringify({ cmp_id: 88, fy_id: 6, bo_id: 0 }))
```

The stub answers `/seskey` and `/validatesession`, so the rest of the auth flow
is the real one.

## Checks

```bash
server-php/tests/run.sh          # integration tests, real PostgreSQL + stub
npm --prefix web run typecheck
npm --prefix web run build

# Browser checks — needs the stack above running.
PURCHASE_APP_URL=http://127.0.0.1:5173 npm --prefix web run test:ui
```

`test:ui` uses Playwright's Chromium. Where one is already installed, point at
it with `PURCHASE_CHROMIUM_PATH`; otherwise `npx playwright install chromium`.

The integration suite **rewrites `server-php/.env`** with its own test
configuration. Restore your local one afterwards.

## Seeding something to look at

There is no seed script in the repository — production data comes from the
product, and a fixture that ships with the app is a fixture that eventually
reaches a customer's screen. Raise a few requisitions, orders, receipts and
bills through the UI; the stub accepts everything Books and Inventory would.
