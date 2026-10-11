# E8-OPS — operations, deploy safety and test gates (launch readiness 2026-10)

Package E8-OPS of the eight-product launch-readiness corrections, redone on current `main`
(branch `claude/e8-ops`, merged with `origin/main` before the final gates). This file covers what
the package changed **in this repository**; the estate-wide pieces (ops kit, contract graph, repair
register, secret-exposure kit) live in `console-react-app/ops/estate/`.

Evidence labels: EXECUTED (run here, on PostgreSQL 16 / real HTTP where it matters), CONTRACT,
STATIC, OPERATIONALLY-PENDING (an operator step; prepared, not performed).


## Findings touched in `purchases-aicountly`

| Finding | Status | What was done / evidence |
|---|---|---|
| A24 | implemented | console ops/estate/contract-graph.py (routes called vs served, cycles books<->inventory and pay<->sales, deploy order, reviewed unmatched set; estate test 7). scripts/ops/receivers.txt + preflight-receivers.sh in pay/sales/purchases/billing/pos/books/Inventory deploys: a receiver that does not serve a needed route stops the deploy unless preflight_override_reason is given (kit test EXECUTED, self-validating 404 control). |
| F096 | implemented | Staged schema-before-code release (bin/ops/release.sh: stage outside docroot, pre-migration snapshot, migrate from stage, switch, jobs) in pay/sales/purchases/billing/pos/books/Inventory; production deploys main-only, bound to GitHub environments, cancel-in-progress false, host keys pinned (StrictHostKeyChecking=yes, appleboy fingerprints) in 16+ repos. Kit/release tests EXECUTED (router 27-29/0, books 17/0, inventory 16/0). Environment reviewers and *_SSH_KNOWN_HOSTS secrets are operator settings. |
| F159 | implemented | bin/db-snapshot.php + kit snapshot-lib (pg_dump --snapshot, manifest with exact row counts, sha256, 0700, retention 14) before every migration; restore-drill.php (drill/restore-named targets only). EXECUTED in kit/estate tests (tampered dump refused, populated drill). RPO/RTO sign-off operator. |
| F160 | implemented | Deploys install the scheduler (kit install-jobs.sh marker blocks from bin/ops/jobs.txt; legacy lines removed) for pay, sales, purchases, billing, pos, books, Inventory, pulse; health reports job states (running/failing/stale/never_ran/scheduled). |
| F162 | implemented | Health reports revision (compared by post-deploy), job heartbeats and ready; Console Cron Job Monitor gets heartbeats (migration 059 registers the product jobs). Alert routing is an operator setting. |
| F175 | implemented | CI PHP matrices: router family 8.2/8.4, books 8.2/8.4 unit + 8.4 integration, Inventory matrix, portal 8.1/8.4; release.sh picks ea-php84..81 and requires >=8.1 with pdo_pgsql. |
| F262 | implemented | sales/purchases/billing/pos bin/command-monitor.php (stale and failed cross-app commands) run every 5 min by the deploy; heartbeat to Console; health reports stale counts (kit test EXECUTED). |
| F320 | implemented | Every production/sandbox deploy runs the repository's CI through workflow_call and needs it (all eight products + adjacent repos), so a [skip ci] landing cannot ship untested. History itself is immutable. |
| F342 | implemented | Production deploys refuse any ref but main and run CI first. |
| F508 | partially_implemented | Contacts anonymous /api/health answers status, database and booleans only; full diagnostics need X-Health-Diagnostics = HEALTH_DIAGNOSTICS_TOKEN (HealthDisclosureApiTest over real HTTP on PostgreSQL EXECUTED). Sales/Purchases GET side effects and key environment binding handed to E8-SAL / E8-PUR. |

## Gates run for this repository (2026-10-11, after merging origin/main)

* Ops kit test: 29/0 (EXECUTED, PostgreSQL 16).
* Workflow rule scan over all 98 workflows in the 22 E8-OPS checkouts: every trigger is workflow_dispatch / workflow_call, every file parses (EXECUTED).
* console-react-app/ops/estate/secret-incident/check-literals.sh over the 22 checkouts: no known secret shape at HEAD (EXECUTED).

## Operator steps this package leaves (none performed here)

* GitHub: set required reviewers and the deployment-branch rule on the `production` / `sandbox`
  environments; store each product's `*_SSH_KNOWN_HOSTS` (and `*_SSH_FINGERPRINT` for appleboy
  actions) — deploys refuse to trust an unpinned host key unless `DEPLOY_ALLOW_KEYSCAN=true`.
* Deploy receivers before their consumers (the receiver preflight stops a deploy otherwise; the order
  is in `console-react-app/ops/estate/CONTRACT_GRAPH.md`).
* A08: access-log review, rotation in the documented order, provider revocation (Alankit), evidence in
  `console-react-app/ops/estate/secret-incident/tracker.csv`.
* A27 / F089: work through `python3 ops/estate/repair-register/register.py plan` per company, recording
  dry-run, apply and post-check (or a dated risk acceptance).
* Route Console's Cron Job Monitor alerts to a person; confirm the first heartbeat of each job after the
  first staged deploy.

