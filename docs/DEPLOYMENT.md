# Deploying Purchases

## Layout

```
web/          React app (Vite). Builds to web/dist.
server-php/   PHP API. Plain PHP, no build step — deployed as-is.
docs/         this file, plus the auth notes
```

## What lands where on cPanel

| Workflow | Deploys | Destination | Reachable at |
| --- | --- | --- | --- |
| Deploy to cPanel Production | `web/dist/` then `server-php/` | `<remote root>/` and `<remote root>/api/` | https://purchase.aicountly.com (+ `/api`) |
| Deploy to cPanel Sandbox | `web/dist/` then `server-php/` | `<remote root>/` and `<remote root>/api/` | https://purchase.gh.aicountly.com (+ `/api`) |

`<remote root>` is the `*_SSH_REMOTE_ROOT` secret for that environment,
normally `public_html` (or the subdomain's own document root).

Deployment is manual only — **Actions → pick a workflow → Run workflow**.
Nothing deploys on push or merge.

## CI and the pre-deploy gate

- **CI** (`.github/workflows/ci.yml`: server-php/tests/run.sh on a throwaway PostgreSQL with the local stub; web typecheck and build) runs automatically on every pull
  request and on every push to `main`, and can also be run by hand. It only
  tests and builds; it never deploys or reaches a live host.
- **Deploys are manual only** (`workflow_dispatch`) and run the full CI suite
  first: each deploy workflow's first job calls `ci.yml` as a reusable workflow
  on the commit being deployed, and the deploy job `needs:` it, so a red suite
  stops the deploy before anything is built or uploaded.

## One workflow per environment, not per half

Production and sandbox are genuinely separate targets — different SSH
credentials, different servers — so each gets its own workflow. Within one
environment, though, the web build and the API are deployed by the same run,
one after the other: first `web/dist/` to the document root, then
`server-php/` to `api/` inside it. Splitting those into separate workflows
would only mean clicking twice for something that is always meant to happen
together, with two SSH sessions and two sets of runner setup instead of one.

### Why the api folder survives the web deploy step

The web deploy step runs `rsync --delete` against the document root, which
would otherwise remove everything not in the build — including `api/`, since
the API lives inside the document root. That step therefore excludes `api/`
explicitly. **Removing that exclude would delete the entire backend on the
next deploy.**

### Why the API's .env survives the API deploy step

The API deploy step also runs `rsync --delete`, this time against `api/`. The
API's `.env` is created once by hand on the server and exists nowhere else, so
both `--exclude='.env'` and `--exclude='.env.*'` are what keep it alive.
Removing them would wipe the live configuration on the next deploy.

Neither `.env` is ever uploaded either: `.gitignore` keeps them out of the
repository, and the workflow fails the build outright if a committed `.env`
appears under `server-php/`.

## Configuration: two different mechanisms

This is the part worth reading carefully, because the frontend and the backend
behave in opposite ways.

### React (web/) — build time

Vite inlines every `VITE_*` value into the JavaScript bundle when the app is
compiled. The deployed result is plain static files that **never read a `.env`
from disk**. Putting a `.env` in the document root has no effect.

To change a frontend value: change it in the workflow (or in the optional
repository variable), then re-run the workflow. The rebuild is what applies it.

Never put a secret in a `VITE_` variable — anything inlined into the bundle is
public to anyone who views the page source.

The API URL needs no configuration in the normal case: with
`PROD_API_BASE_URL` / `SANDBOX_API_BASE_URL` unset, the app calls its own origin
+ `/api`, which is where the same workflow's API step deploys `server-php`.
Those repository variables exist only to override that — for example if the API
moves to its own domain.

### server-php — runtime

PHP reads its `.env` on **every request**. So the API's `.env` belongs on the
server, and only on the server.

Create it once by hand — cPanel File Manager or SSH — at
`<remote root>/api/.env`, from `server-php/.env.example`:

```
APP_ENV=production
DB_HOST=localhost
DB_PORT=5432
CONSOLE_API_URL=https://console.aicountly.org/api
CONSOLE_DB_DETAILS_KEY=<the key generated on Purchases's row in Console, starting sdb_>
DB_PASS=<password of the user Console names>
```

`APP_ENV=sandbox` for the sandbox. `GET /api/health` reports the value back, which is how
you confirm you are looking at the environment you think you are.

**The database name and username are not set here.** Console > SaaS Database Details records
them per product and environment, and the API asks Console for them
(`GET $CONSOLE_API_URL/database-details/resolve`, the key as a bearer token) on every request,
worker and `bin/migrate.php`. This is the same split Connect uses: Console holds no password,
host or port, so `DB_PASS`, `DB_HOST`, `DB_PORT` (and the optional `DB_SSLMODE`, `DB_SCHEMA`) stay in
this file, and any other field in Console's answer is ignored. cPanel prefixes both database and
user with the account name, so the password in `DB_PASS` must belong to the (prefixed) user Console
names for this row, and that user needs **ALL PRIVILEGES** on the database. `DB_HOST` is `localhost`
(on cPanel the database is on the same machine).

**The variables must be named exactly `CONSOLE_API_URL` and `CONSOLE_DB_DETAILS_KEY`.** The key is
the per-row key Console shows once under *Generate key* (it starts with `sdb_`); Console shows
it only once, so *Rotate key* gives a new one if it was not saved, and rotating kills the old
one. `CONSOLE_SERVICE_KEY` is a different credential and Console rejects it here. If either variable
is missing, misspelled, commented out or empty, Console is simply never asked: the API quietly uses
`DB_NAME` / `DB_USER`, and **commenting those out then leaves no database at all**. A key for the other
environment (production vs sandbox) is refused. `DB_NAME` / `DB_USER` are a local-development fallback
only: they are not read while both Console variables are set.

To see where the connection really comes from, and whether the database accepts it, run on the
server (from `api/`): `php bin/db-check.php`. It asks Console right now (cache bypassed), prints
what Console answered, connects, and checks that every migration is applied; it never prints
the key or the password, and ends with a `Reason:` and what to do when something is wrong.
`/api/health` reports the same: `database.source` is `console` when Console supplies the name and
username and `env` when `DB_NAME` / `DB_USER` do (on a deployed server it should say `console`),
and a failure to obtain them is reported by its own `database.reason` with a `database.hint`:

| `database.reason` | What it means | Fix |
| --- | --- | --- |
| `console_key_missing` | `CONSOLE_API_URL` is set but `CONSOLE_DB_DETAILS_KEY` is not (and `DB_NAME` / `DB_USER` are not set), so Console is never asked | put the `sdb_` key generated in Console > SaaS Database Details in `CONSOLE_DB_DETAILS_KEY` |
| `console_url_missing` | `CONSOLE_DB_DETAILS_KEY` is set but `CONSOLE_API_URL` is not | `CONSOLE_API_URL=https://console.aicountly.org/api` |
| `console_key_rejected` | Console answered 401: the key is revoked, rotated or wrong | generate a key on this deployment's row in Console |
| `console_row_inactive` | Console answered 403: the row is inactive | activate it in Console > SaaS Database Details |
| `console_unreachable` | this server could not reach Console (and no earlier answer is cached) | check `CONSOLE_API_URL` and outbound HTTPS |
| `console_environment_mismatch` | the key belongs to the other environment (compared with `APP_ENV`) | use the key from this deployment's own row |
| `console_no_database_recorded` | Console has no database name and username for this row | record them in Console |
| `not_configured` | neither the two Console settings nor `DB_NAME` + `DB_USER` are set | `api/.env` |

### Protecting the API's .env over HTTP

Because `api/` sits inside the document root, `.env` would be fetchable at
`https://purchase.aicountly.com/api/.env` unless Apache is told otherwise.
`server-php/.htaccess` ships the rule that denies it:

```apache
RedirectMatch 404 /\.(?!well-known)
```

The web build does the same for the document root via `web/public/.htaccess`,
but those rules stop applying inside `api/` once the API's own take over.

### The Authorization header

`server-php/.htaccess` also copies the `Authorization` header into the request
environment. Apache does not pass it to PHP under CGI/FastCGI unless told to,
and without it the auth relay forwards no credential — the portal answers 401
and sign-in fails for everyone, with nothing in the logs to explain why.

## Required secrets

Per environment, under Settings → Secrets and variables → Actions → Secrets:

`PROD_SSH_HOST`, `PROD_SSH_PORT`, `PROD_SSH_USER`, `PROD_SSH_PRIVATE_KEY`,
`PROD_SSH_REMOTE_ROOT` — and the same five with a `SANDBOX_` prefix.

Both workflows validate these before building, and verify SSH authentication
before writing anything to the server. Because the deploys run with
`--delete`, a `*_SSH_REMOTE_ROOT` that would resolve to the home directory
itself, a system directory, or anything containing `..` is refused.

## First deploy checklist

1. Create the subdomain in cPanel and note its document root.
2. Add the five SSH secrets for that environment.
3. Run **Deploy to cPanel …**. This deploys web and API together; the API is
   deployed but unconfigured until the next step.
4. Create `api/.env` on the server (see above), from `server-php/.env.example`, then
   `php api/bin/db-check.php` (is the database reached, and from where?) and `php api/bin/migrate.php`.
5. Re-run **Deploy to cPanel …** (or just confirm the API), then confirm
   `https://<host>/api/health` returns the right `env` and open the site to
   sign in. See [auth/AICOUNTLY_AUTH_WORKFLOW.md](auth/AICOUNTLY_AUTH_WORKFLOW.md)
   for what a healthy login looks like.

## After deploying the key-length fix (launch, October 2026)

Until keys were sized on the wire, every purchase-return and claim debit note (and a bill of a
company whose ids had enough digits) reached Books with an Idempotency-Key longer than the 64
characters Books keeps. Books refused it before writing anything, and Purchase recorded the
command as BLOCKED, which nothing sends again by itself. The deploy stops new ones; the ones
already blocked are recovered per company, by somebody of that company who may post bills and
raise debit notes (usually the owner), with their own portal session:

```bash
cd <remote root>/api
php bin/books-key-recovery.php --cmp=<cmp_id>                     # dry run: lists them, changes nothing
RECOVERY_SES_KEY=<ses_key> php bin/books-key-recovery.php --cmp=<cmp_id> --check   # asks Books, read-only
RECOVERY_SES_KEY=<ses_key> php bin/books-key-recovery.php --cmp=<cmp_id> --apply --reason="…"
```

`--apply` asks Books again and re-issues only what Books confirms it holds nothing for — no
posted voucher under the document's reference, no draft from the document — through the same
operation the screen runs. Anything Books does hold (a debit note keyed in by hand while the
command was stuck) is left alone and reported for a person. Running it twice is safe. Add
`--json` for a report to keep. The session is read from the environment and never printed.
