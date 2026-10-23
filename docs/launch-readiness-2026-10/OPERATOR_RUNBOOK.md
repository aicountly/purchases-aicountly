# Purchases launch — operator runbook (2026-10-23)

Purchases is `purchase.aicountly.com` (sandbox `purchase.gh.aicountly.com`), Manage app key **`purchases`**.
What is left before launch is configuration that only someone with WHM / cPanel, GitHub admin and
Console access can do. Do the sections in order; each one says how to check it.

| # | Step | Where | Blocks |
|---|---|---|---|
| 1 | Purchases `api/.env`: the five settings `ready` needs, plus the optional two | WHM terminal / cPanel File Manager | the production deploy's `ready` check |
| 2 | The mirror of each key in Books, Inventory and Manage | the siblings' `api/.env` | Books cancellations, goods receipts, restrict / purge / seats |
| 3 | Console: database details key, cron monitors | Console | migrations, job heartbeats |
| 4 | Deploy Purchases (manual workflow) | GitHub Actions | — |
| 5 | After the deploy: health, signed test events, open E8 checks | WHM terminal / browser | launch |
| 6 | Rollback, if needed | GitHub Actions / WHM | — |

`<docroot>` below is the document root of `purchase.aicountly.com` (the API is `<docroot>/api`). Make
every new secret with `openssl rand -hex 32`. Put **no inline `#` comment on a value line** (the
comments in the blocks below are for this page only).

---

## 1. Purchases `api/.env`

### What `/api/health` checks

`GET https://purchase.aicountly.com/api/health` answers `ready: true` only when the database is migrated,
the environment is consistent, the command monitor cron runs, **and** every setting in `config.items`
is present (`server-php/src/Ops/OpsStatus.php`, `REQUIRED_CONFIG`). The production deploy is fatal on
`ready`. Health shows booleans only, never a value.

| `config.items` (required) | Why | Minimum |
|---|---|---|
| `INVENTORY_SERVICE_KEY` | goods receipts (inward challan), purchase returns (outward challan), batch / serial registration are posted to Inventory as Purchases | non-empty, not `CHANGE_ME` |
| `BOOKS_EVENTS_SIGNING_SECRET` | verifies Books' voucher events (a bill or debit note cancelled / revised in Books reopens it here and frees the supplier invoice number) | 16 characters |
| `SERVICE_KEYS:books` | the `books:<key>` entry in `SERVICE_KEYS` that Books presents with those events | non-empty, not `CHANGE_ME` |
| `LIFECYCLE_WEBHOOK_SECRET` | Manage's signed lifecycle events (restrict / unrestrict / purge / account purge) **and** seat events (`POST /api/internal/manage/seats`) | 32 characters |
| `MANAGE_API_URL` | where purge acknowledgements go (`https://manage.aicountly.com`; sandbox `https://manage.gh.aicountly.com`) — no default on purpose | URL |

| `config.warnings` (optional — reported, never blocks `ready`) | Without it |
|---|---|
| `PULSE_SERVICE_KEY` | AI Insights / Ask fall back to rules. Pulse accepts a session-only call until **2026-11-15 00:00 UTC**; after that every AI call is refused, so set it before then. |
| `MANAGE_PRODUCT_SERVICE_KEY` | the purge worker cannot ask Manage whether a queued purge was reversed just before it runs |

**There is no `BOOKS_SERVICE_KEY`.** Books is always called as the signed-in person (F374/F510). Until
October 2026 health required that key, so `ready` could never be true on a correctly configured host
(audit P0-P1); it is no longer read anywhere and can be removed from an old `.env`. Contacts needs no
key either (called as the person).

### The block to add

```
# <docroot>/api/.env  (Purchases)
APP_ENV=production
INVENTORY_SERVICE_KEY=<inv-key>                  # = Inventory's INVENTORY_SERVICE_KEYS entry purchases:<inv-key>
SERVICE_KEYS=books:<books-key>                   # = Books' BOOKS_SOURCE_EVENTS_PURCHASES_KEY
BOOKS_EVENTS_SIGNING_SECRET=<events-secret>      # = Books' BOOKS_SOURCE_EVENTS_PURCHASES_SECRET
LIFECYCLE_WEBHOOK_SECRET=<lifecycle-secret>      # = Manage's LIFECYCLE_WEBHOOK_SECRET_PURCHASES
MANAGE_API_URL=https://manage.aicountly.com
MANAGE_PRODUCT_SERVICE_KEY=<mps-key>             # = Manage's MANAGE_PRODUCT_SERVICE_KEYS entry purchases:<mps-key>
PULSE_SERVICE_KEY=<pulse-key>                    # minted on Pulse: php spark pulse:gateway-key mint purchases
CONSOLE_API_URL=https://console.aicountly.org/api
CONSOLE_DB_DETAILS_KEY=<sdb_…>                   # Console > SaaS Database Details, this deployment's row
DB_HOST=127.0.0.1
DB_PORT=5432
DB_PASS=<password of the user Console names>
```

Keep any value already set (an existing `insights:` entry in `SERVICE_KEYS` stays:
`SERVICE_KEYS=books:<books-key>,insights:<insights-key>`). Each product gets its **own** key: Inventory's
soft-launch audit (#17) found Books, POS and Purchases sharing one Inventory key — `<inv-key>` must be
distinct from the `books:` and `pos:` entries there.

Optional, only to point somewhere other than what `APP_ENV` derives: `BOOKS_API_BASE`,
`INVENTORY_API_BASE`, `MANAGE_API_BASE`, `CONTACTS_API_BASE`, `PULSE_API_ORIGIN`, `PORTAL_AUTH_BASE`.
With `APP_ENV=production` a base pointing at a sandbox or loopback host is refused and listed in
`environment.problems`.

Check: `curl -s https://purchase.aicountly.com/api/health | python3 -m json.tool` — `config.ready: true`,
`config.warnings: []`.

---

## 2. The mirrors in the sibling products

Every secret above has exactly one partner. Set both sides to the same value in the same sitting.

| Purchases `api/.env` | Sibling | Sibling setting | Effect if missing or different |
|---|---|---|---|
| `INVENTORY_SERVICE_KEY=<inv-key>` | Inventory `api/.env` | `INVENTORY_SERVICE_KEYS=…,purchases:<inv-key>` (ServiceCallerPolicy `purchases`: inward_challan, delivery_challan, batch/serial) | every GRN and return sits FAILED on its screen |
| `SERVICE_KEYS=books:<books-key>` | Smart Books `api/.env` | `BOOKS_SOURCE_EVENTS_PURCHASES_KEY=<books-key>` | Books' event answered 401; Books dead-letters it |
| `BOOKS_EVENTS_SIGNING_SECRET=<events-secret>` | Smart Books | `BOOKS_SOURCE_EVENTS_PURCHASES_SECRET=<events-secret>` (per app; Books falls back to the shared `BOOKS_SOURCE_EVENTS_SIGNING_SECRET`, which may already sign other products' events — do not change that one) | 401 `signature_mismatch` / 503 `receiver_not_configured` |
| — | Smart Books | `BOOKS_SOURCE_EVENTS_PURCHASES_URL=https://purchase.aicountly.com/api` (sandbox `https://purchase.gh.aicountly.com/api`) — the API **base**: Books appends `/v1/integration/books/voucher-events` | `receiver_not_configured` in Books' outbox; a cancelled bill stays POSTED here |
| `LIFECYCLE_WEBHOOK_SECRET=<lifecycle-secret>` | Manage `api/.env` | `LIFECYCLE_WEBHOOK_SECRET_PURCHASES=<lifecycle-secret>` (+ `LIFECYCLE_WEBHOOK_URL_PURCHASES=https://purchase.aicountly.com` only if the catalog host is not right) | Manage treats Purchases as `not_integrated`: no restrict, purge or account purge reaches it |
| (same secret) | Manage | seats: `ProductSeatService::APPS` must list `purchases` (fleet X-P1-2; on Manage `main` today it is `['inventory','billing']`), URL `MANAGE_SEAT_URL_PURCHASES` or the catalog host + `/api/internal/manage/seats` | a person granted Purchases in Manage holds nothing until an administrator assigns a profile (workspace-wide access still seats on first entry) |
| `MANAGE_PRODUCT_SERVICE_KEY=<mps-key>` | Manage | `MANAGE_PRODUCT_SERVICE_KEYS=…,purchases:<mps-key>` | purge runs without the reversal check (warning only) |
| `PULSE_SERVICE_KEY=<pulse-key>` | AI Pulse host | `php spark pulse:gateway-key mint purchases` (one per environment; printed once) | rules-only AI; refused after 2026-11-15 |
| `CONSOLE_DB_DETAILS_KEY` | Console | Console > SaaS Database Details, Purchases row, Generate key | `database.reason: console_key_*`, 503 everywhere |
| — | Contacts | nothing to set: company contacts are read as the person (`/api/companies/{cmp}/contacts…`) | the supplier screen says company contacts are unavailable |

Books delivers its events from its scheduler crontab (`deploy/books-scheduler.crontab` in
books-react-app, installed by hand): `crontab -l -u <books cpuser>` must show it. Dead-lettered events
after a fix: `books:source-events-report` in Books, then `POST integration/source-events/{id}/requeue`.

---

## 3. Console

- **Database details**: the Purchases row for this environment exists and has a key (above).
- **Cron monitors**: `purchases.company_lifecycle` and `purchases.company_lifecycle_expiry` must be
  registered in Console's `cron_monitors` (migration 059 pattern). Heartbeats are sent only when
  Purchases' `api/.env` holds `CONSOLE_CRON_MONITOR_KEY` (else `CONSOLE_SERVICE_KEY`) — give it the same
  value the other products send (Billing runbook §2).

---

## 4. Deploy

Actions → **Deploy to cPanel Production** → Run workflow (`main`). It runs `ci.yml` first (PHP suite,
ops kit, web unit tests, typecheck, build — all manual, nothing runs on push), checks every receiver
(`scripts/ops/receivers.txt`: Inventory document post / availability, Books drafts, Manage health),
then `bin/ops/release.sh`: snapshot → migrate (expand-only; this release adds `030_purchase_access_removals`)
→ switch → first job run → crontab install → post-deploy checks (fatal on `ready` in production;
`config.warnings` only print a warning).

### Scheduled jobs (installed by the deploy from `server-php/bin/ops/jobs.txt`)

| Job | Schedule | What it does | Health |
|---|---|---|---|
| `command-monitor` | every 5 min | counts cross-app commands (Books posts, Inventory documents) left open too long; reviews DEFERRED_RESTRICTED ones | `monitor.state` must be `running` for `ready`; `commands.stale` |
| `company-lifecycle` | every 5 min | runs the purges Manage queued and acks them | Console monitor `purchases.company_lifecycle` |
| `company-lifecycle-expiry` | 03:37 daily | expires retained rows of purged companies after their period (archived first) | Console monitor `purchases.company_lifecycle_expiry` |

Check: `bash <docroot>/api/bin/ops/install-jobs.sh --check`.

---

## 5. After the deploy

1. **Health**: `ready: true`, `revision` = the deployed commit, `config.warnings: []`,
   `environment.ok: true`, `commands.stale: 0`.
2. **Headers** (P1-P3): `curl -sI https://purchase.aicountly.com/ | grep -iE 'content-security|x-frame|strict-transport'`
   shows `frame-ancestors 'self' https://*.aicountly.com`, `X-Frame-Options: SAMEORIGIN`,
   `Strict-Transport-Security: max-age=31536000`; the same three (`frame-ancestors 'self'`) on `/api/health`.
3. **Books → Purchases event** (sandbox first): post a purchase bill, cancel it in Smart Books; within
   Books' scheduler interval the bill reads cancelled in Purchases and its supplier invoice number is
   free again. Books' `books:source-events-report` shows the event `delivered`, not
   `receiver_not_configured`. This is the real-Books run E8-PUR left OPERATIONALLY-PENDING (F222/F223,
   audit P1-P10): record the evidence (voucher id, event id, timestamps) in the launch tracker.
4. **Manage events**: send one signed lifecycle test event per environment from Manage (restricted →
   unrestricted on a test company): Purchases answers 200 and shows the read-only banner in between. Once
   Manage lists `purchases` for seats, grant a test member Purchases and check they hold the starter
   profile (`buyer`, `approver`, `payables` or `viewer`).
5. **Open E8 checks**: the host answers 404 for `/api/bin` and `/api/tests` (F483 — post-deploy checks it
   from the server); run `php bin/receipt-repair.php --cmp=<id>` in `<docroot>/api` for each live
   company and act on `bill_double_posts`, `duplicate_invoices`, `missing_indexes` (F221).
6. **Purchase order document**: open an approved order → Download. It prints both GSTINs, bill-to /
   ship-to, place of supply, HSN/SAC, CGST + SGST or IGST and the amount in words, with `₹`. A
   "GSTIN Not known" means Books' branch GST (`branch-gst/{bo}/resolve`) or the supplier ledger has none
   — fix it in Smart Books, nothing to set here.

---

## 6. Rollback

- **Code**: run the deploy workflow again on the previous good commit (or tag). Migrations are expand-only
  by policy: `030_purchase_access_removals` only adds a table, so older code runs on the new schema and
  no down-migration is needed.
- **Data**: `release.sh` took a snapshot before migrating (`~/aicountly-backups/purchases-production/`,
  newest 14 kept). Restore only for data damage, never for a code rollback:
  `RESTORE_DRILL_ADMIN_URL=postgresql://… php bin/ops/restore-drill.php --manifest=<snapshot>.manifest.json --target-db=purchases_restore_drill --keep`
  restores it into a **new** database to inspect; point `api/.env` at it only after checking.
- **Configuration**: every key in §1/§2 is read per request — revert the line in `api/.env` (both sides of
  a mirror together). Removing `BOOKS_EVENTS_SIGNING_SECRET` or the `books:` entry makes Books defer its
  events (it redelivers until acknowledged), it does not lose them.
- **Clickjacking headers**: if an AICOUNTLY page outside `*.aicountly.com` must frame Purchases, add its
  origin to `frame-ancestors` in `web/public/.htaccess` and redeploy; do not remove the header.

---

## What this release changed (phase 2)

| Audit item | Change | Files |
|---|---|---|
| P0-P1 | `ready` requires the five settings above, not the nonexistent `BOOKS_SERVICE_KEY`; optional ones are `config.warnings` | `server-php/src/Ops/OpsStatus.php`, `scripts/ci/post-deploy-checks.sh`, `server-php/.env.example` |
| P1-P2 / X-P1-1 | Books → Purchases event keys documented | `server-php/.env.example`, this page |
| P1-P3 | `frame-ancestors`, `X-Frame-Options`, HSTS | `web/public/.htaccess`, `server-php/.htaccess` |
| P1-P4 | GST-complete purchase order, embedded DejaVu Sans | `server-php/src/Pdf/*`, `server-php/resources/fonts/` |
| P1-P6 | app-level error boundary with a reference | `web/src/shell/AppErrorBoundary.tsx` |
| P1-P7 | web unit tests in `ci.yml` (still manual) | `.github/workflows/ci.yml` |
| P1-P8 | new supplier from the order editor (Books creditor ledger), Inventory link for items, first-run checklist | `SuppliersController::createLedger`, `web/src/pages/purchase-order/`, `web/src/dashboards/FirstRunChecklist.tsx` |
| sticky removal | an administrator's removal of a workspace member is remembered | migration 030, `ManageSeats.php`, `AccessController.php` |
| P-P2-5 / P-P2-9 | README; privacy / terms / grievance links in the shell | `README.md`, `web/src/shell/legal.ts` |
| X-P2-4 | ops kit runs on any PostgreSQL port (`DB_ADMIN_URL`'s, default 5432) | `server-php/tests/ops/ops-kit-test.sh` |
