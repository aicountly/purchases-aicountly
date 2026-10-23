# purchases-aicountly

Purchases for Aicountly — a React single-page app built with Vite and TypeScript,
with a small PHP API alongside it. Both halves deploy to cPanel.

| Environment | App | API |
| --- | --- | --- |
| Production | https://purchase.aicountly.com | https://purchase.aicountly.com/api |
| Sandbox | https://purchase.gh.aicountly.com | https://purchase.gh.aicountly.com/api |

## What this app does today

Purchasing for a company, from need to payable, with Smart Books and Inventory as the
books and the stock of record (nothing here keeps a copy of either):

- **Requisitions** — raise, approve (with segregation of duties), convert to an order.
- **Sourcing (RFQ)** — record who was asked, compare quotes, award and convert. Nothing is
  sent to suppliers from here; the screen says so.
- **Purchase orders** — approval rules, issue/acknowledge, amendments with version history,
  short-close, cancel and reopen; supplier, items and warehouses confirmed with Books and
  Inventory before anything is stored. A supplier the company has never bought from is added from
  the order editor (a Smart Books creditor ledger, once per form). The order's PDF is GST-complete:
  both GSTINs, bill-to / ship-to, place of supply, HSN/SAC, CGST + SGST or IGST, amount in words.
- **Goods receipts (GRN)** — each delivery is its own Inventory document (batches, expiry,
  serials, rejected quantity, duplicate-challan guard), recorded exactly once with
  reconcile/retry for an unknown outcome; receipt returns and reversals.
- **Bills** — three-way match at net rate with tolerances and binding decisions, direct
  and service bills, reverse charge, place of supply and GSTIN checks, posting to Smart
  Books exactly once (idempotent, lost-answer safe).
- **Returns and claims** — purchase returns with debit notes that settle the bill they
  correct, supplier claims and resolutions.
- **Dashboards and Ask** — overview, procurement, suppliers, bills and payables, insights;
  every figure states its basis; Ask answers from fixed rules, AI Pulse commentary (when
  bound) is labelled and checked against the figures.
- **Access** — permission profiles per company, segregation of duties, audit log.

Signing in is the AICOUNTLY portal's job, the same as every other AICOUNTLY
SaaS: the app redirects to the portal, the portal returns an `auth_token`, and
the app exchanges it for a short-lived session key. A user who is already signed
in to another AICOUNTLY product lands straight on the dashboard.

See [docs/auth/AICOUNTLY_AUTH_WORKFLOW.md](docs/auth/AICOUNTLY_AUTH_WORKFLOW.md).

## Company access (registry)

Which companies a person may **open** in Purchases is decided by Manage's company registry, per
application. Purchases' catalog key is **`purchases`**.

- **Server gate.** `server-php/src/Context.php` (`askManage()`) asks Manage `GET /api/companyinfo`
  on every scoped request. `ApiClient` names the product on every outbound call
  (`X-Saas-Origin` / `X-Source-App` = `APP_PRODUCT_KEY`, default `purchases`), so Manage answers
  for Purchases: a workspace member who may see the company but not open it here gets
  `403 COMPANY_ACCESS_REQUIRED`, which Purchases returns as `403 company_access_denied` with
  `details.manage_code = COMPANY_ACCESS_REQUIRED` (the app then shows "Company access restricted"
  with "Request access"). Manage unreachable stays a 503, never an allow.
- **Client.** `web/src/services/companyRegistry.ts` reaches Manage's `/api/v1/*` through Purchases'
  own same-origin relay (`/api/manage/v1/*`, `server-php/src/ManageRelay.php`) with the person's
  session key — never cross-site, which the host's bot shield breaks on some networks. The shared
  screens are vendored verbatim in `web/src/company-access/` (package 1.2.0, copied from
  manage-aicountly `Saas Common Handler/company-access`; change them there, copy again).
- **Company picker.** The choose-company page has "Browse all workspace companies", listing every
  company of the person's workspaces; a company restricted in Purchases is badged and shows
  "Request access" instead of opening.
- **Users & Access.** Administration → Users & Access (`/users-access`): the owner grants, changes
  and revokes access to this company in Purchases and reviews requests, with no redirect to Manage.
  Administration → Access (Purchases permission profiles) is unchanged and still decides what a
  person may *do* in Purchases.
- **Discovery is not access.** Seeing a company in the directory never opens it; the server check
  above is what decides.
- **Seats from Manage.** `POST /api/internal/manage/seats` (CONTRACTS §5, `server-php/src/ManageSeats.php`):
  Manage, after granting someone Purchases, sends `{event_id, cmp_id, platform_user_id, uuid, role_key,
  action: grant|revoke}` signed like the lifecycle events (`X-Manage-Timestamp`, `X-Manage-Signature:
  sha256=HMAC("{ts}.{body}", LIFECYCLE_WEBHOOK_SECRET)`, 300 s). `role_key` is a starter profile —
  `buyer` (Buyer), `approver` (Purchase approver), `payables` (Accounts payable), `viewer` (Read only;
  the least privileged); never owner (422 `ROLE_UNKNOWN`). A grant adds that profile (created from the
  starter if the company has none) and never takes anything away; revoke releases every Purchases
  profile the person holds. Idempotent by `event_id`; answers `{success, data:{status: seated|already|revoked}}`.
- **Workspace-wide access.** When the workspace owner gives a member Purchases on every company of the
  workspace, `companyinfo` says `app_access_source: workspace` with `app_role_key`; the member is given
  that starter profile on first entry (an unknown key → Read only; never owner; someone who already
  holds a Purchases profile keeps exactly what they hold).

## Plan limits

The company's plan for Purchases comes from Manage: `companyinfo` (asked on every scoped request,
naming Purchases) carries `plan.entitlements` (CONTRACTS §3.5). Values are data in Engage; an absent
code, or a Manage that sends no plan, means no limit. Over the limit a new purchase order answers
**402 `PLAN_LIMIT_REACHED`** `{code, limit, used}` and the web says "Your plan allows N … — ask the
account owner to upgrade in Manage". Reads, exports, approvals, receipts, bills and returns are never
limited, and nothing already recorded is touched (`server-php/src/PlanEntitlements.php`).

| Code | What is counted | Where enforced |
| --- | --- | --- |
| `purchases.max_purchase_orders_per_month` | New purchase orders the company raised this calendar month (IST); an amendment of an existing order is not a new one | `POST v1/purchase-orders` |

## Launch readiness (October 2026)

Operators: every `api/.env` key, its mirror in Books, Inventory, Contacts and Manage, the activation
order, crons, what `/api/health` checks and how to roll back are in
[docs/launch-readiness-2026-10/OPERATOR_RUNBOOK.md](docs/launch-readiness-2026-10/OPERATOR_RUNBOOK.md).
`/api/health` answers `ready: true` with `INVENTORY_SERVICE_KEY`, `BOOKS_EVENTS_SIGNING_SECRET`, a
`books` entry in `SERVICE_KEYS`, `LIFECYCLE_WEBHOOK_SECRET` and `MANAGE_API_URL` set (there is no Books
service key: Books is called as the signed-in person); `PULSE_SERVICE_KEY` and
`MANAGE_PRODUCT_SERVICE_KEY` are reported as `config.warnings` only.

## Company lifecycle (Manage)

A lapsed Purchases subscription makes the company view/export-only here (writes answer `423
COMPANY_RESTRICTED` — Books' voucher events too, which Books defers; the app shows a read-only banner), and
Manage later asks Purchases to purge it — purchase vouchers, goods receipts, returns, claims and the audit trail
are retained by statute for 9 years (then expired daily, archived first), with only the PO / quote / RFQ /
requisition chain they reference. Built on the AICOUNTLY receiver kit 1.1 (`server-php/lib/company-lifecycle/`). See
[docs/COMPANY_LIFECYCLE.md](docs/COMPANY_LIFECYCLE.md).

## Layout

```
web/          React app (Vite). Builds to web/dist, deployed to the document root.
server-php/   PHP API. Deployed to the api/ folder inside the document root.
docs/         deployment and auth notes
```

## Getting started

Requires Node.js 22 or newer.

```bash
cd web
npm install
cp ../.env.example ../.env
npm run dev
```

The dev server runs on http://localhost:5173 and signs in through the **sandbox**
portal. Point `VITE_API_BASE_URL` at the deployed sandbox API
(`https://purchase.gh.aicountly.com/api`) so the token exchange has somewhere to
go — and add `http://localhost:5173` to `CORS_ALLOWED_ORIGINS` in that server's
`api/.env`, since localhost is the one case where the app and API are not
same-origin.

| Script | Purpose |
| --- | --- |
| `npm run dev` | Vite dev server on http://localhost:5173 |
| `npm run build` | Type-check, then build to `web/dist/` |
| `npm run typecheck` | Type-check only |
| `npm test` | Web unit tests (node:test over `src/**/*.test.ts`; also run by `ci.yml`) |
| `npm run preview` | Serve the production build locally |

The PHP API has no build step and no dependencies. To run it locally:

```bash
cd server-php
cp .env.example .env      # set APP_ENV=local
php -S localhost:8000
```

## Environment variables

`.env` is git-ignored and is never deployed — `.env.example` is the tracked
template. There are two of them, and they work in opposite ways:

| File | Read | Used by |
| --- | --- | --- |
| `.env.example` | **Build time**, inlined into the bundle | `web/` |
| `server-php/.env.example` | **Runtime**, on every request | `server-php/` |

| Variable | Description |
| --- | --- |
| `VITE_API_BASE_URL` | API base URL. Empty = this app's own origin + `/api` |
| `VITE_APP_NAME` | Display name shown in the UI |
| `VITE_APP_ENV` | `local`, `sandbox`, or `production` |
| `VITE_PRODUCT_KEY` | Portal product key. Derived from the hostname when unset |
| `VITE_PORTAL_LOGIN_URL` | Login portal override. Local development only |

The API's database is set in `server-php/.env`: its **name and username come from
Console > SaaS Database Details** (`CONSOLE_API_URL` + `CONSOLE_DB_DETAILS_KEY`, the
`sdb_` key generated on this deployment's row), while host, port and password stay
in that file. `DB_NAME` / `DB_USER` are a local-development fallback and are not read
while both Console variables are set. `php server-php/bin/db-check.php` shows where
the connection comes from and whether it works.

Only `VITE_`-prefixed variables reach the browser bundle, and Vite inlines them
at build time, so **treat every one of them as public**. Never put a secret,
token, or password in a `VITE_` variable.

### These are build-time values, not runtime values

This matters for how you change an endpoint in production.

Vite substitutes each `VITE_*` value into the JavaScript bundle when the app is
compiled. The deployed result is plain static files — **the app never reads a
`.env` from disk at runtime**, so placing a `.env` next to it in the cPanel
document root has no effect. Changing an endpoint means rebuilding and
redeploying.

This is the opposite of `server-php`, which is PHP and does read its own `.env`
on every request.

## Deployment

Deployment is **manual only**. Nothing deploys on push or merge — both
workflows trigger exclusively via `workflow_dispatch`.

**CI and the pre-deploy gate.** [`ci.yml`](.github/workflows/ci.yml) — `server-php/tests/run.sh` (integration, AI gateway, remediation and launch-readiness tests on a throwaway PostgreSQL with the local stub), the ops kit, and the web unit tests, typecheck and build — runs on demand only (`workflow_dispatch`, and `workflow_call` from the deploys); no workflow runs on push or pull request, so run the suites locally before merging. It only tests and never deploys. Both deploy workflows stay manual (`workflow_dispatch` only) and run that full suite first: their first job calls `ci.yml` on the commit being deployed and the deploy job `needs:` it, so a red suite stops the deploy.

To deploy: **Actions** → pick a workflow → **Run workflow** → pick a branch →
**Run**.

| Workflow | Deploys | To |
| --- | --- | --- |
| Deploy to cPanel Production | `web/dist/` then `server-php/` | document root, then `api/` inside it |
| Deploy to cPanel Sandbox | `web/dist/` then `server-php/` | document root, then `api/` inside it |

Production and sandbox deploy separately, so releasing to one cannot disturb
the other. Within one environment, web and API deploy together in the same
run — they always change in step, so there is no separate "API only" workflow
to remember to run. Source, `node_modules`, and `.env` never reach the server.

Before deploying, each workflow checks that every required SSH secret is set and
that the remote root is a safe path, so a misconfigured repository fails in
seconds instead of part-way through a deploy.

### Configuration

These repository **secrets** must be set (Settings → Secrets and variables →
Actions → Secrets):

`PROD_SSH_HOST`, `PROD_SSH_PORT`, `PROD_SSH_USER`, `PROD_SSH_PRIVATE_KEY`,
`PROD_SSH_REMOTE_ROOT` — and the same five with a `SANDBOX_` prefix.

`*_SSH_REMOTE_ROOT` is the document root to deploy into. It may be relative,
which is the usual cPanel form — `public_html` resolves against the SSH user's
home directory, giving `/home/<user>/public_html`. An absolute path works too.
Because the deploy runs with `--delete`, the workflow refuses a value that would
resolve to the home directory itself (`.`, `~`, empty), a system directory, or
anything containing `..`.

The repository **variables** `PROD_API_BASE_URL` and `SANDBOX_API_BASE_URL` are
optional. Unset, the app calls its own origin + `/api` — which is where the same
workflow puts the API. Set one only to point the app at a different API domain.

### Notes on the rsync steps

Each workflow runs two `rsync --delete` steps, one after the other, and the
excludes are what make that safe.

The **web** step syncs the document root and excludes:

- `api/` — the PHP backend lives inside the document root and is deployed by the
  next step in the same run. **Without this exclude the web step would delete
  the entire API.**
- `.well-known/` — Let's Encrypt / AutoSSL validation; removing it breaks
  certificate renewal
- `cgi-bin/` — cPanel-managed, present in every document root
- `.env`, `.env.*`, `.git*` — never published

The **API** step syncs `api/` and excludes `.env`, `.env.*` and `.git*`: the
API's `.env` is created once on the server and read at runtime, so it must
survive every deploy. See [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md).

`web/public/.htaccess` ships with the build and provides the SPA history
fallback — which is also what serves the portal's `/auth/callback` landing — plus
cache headers (`index.html` uncached, hashed assets cached for a year).
