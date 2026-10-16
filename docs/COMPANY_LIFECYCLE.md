# Company lifecycle — read-only after a lapsed subscription, then purge

AICOUNTLY Manage owns subscriptions. When a company's paid Purchases subscription lapses, Manage makes the
company **view/export-only in Purchases for 45 days** and then asks Purchases to **purge** the company's data; when an
AICOUNTLY account is closed it asks Purchases to purge the person's own (non-company) data. Purchases receives those
decisions through the AICOUNTLY **company-lifecycle receiver kit**, vendored unchanged at
`server-php/lib/company-lifecycle/` (version in its `VERSION`; guide: its `README.md`). Contract and
Manage's side: `manage-aicountly/docs/company-lifecycle/README.md`.

| Piece | Where |
|---|---|
| Kit (copy, don't edit — fix it in Manage and copy again) | `server-php/lib/company-lifecycle/` |
| Tables `aic_lifecycle_events / _state / _jobs / _acks` (the kit's `schema.sql`, verbatim) | `server-php/database/migrations/026_aic_company_lifecycle.sql` |
| Kit 1.1: `aic_lifecycle_expiries`, job status `held` (the kit's `schema-1.1.sql`, verbatim) | `server-php/database/migrations/027_aic_company_lifecycle_1_1.sql` |
| Kit 1.1: the purge worker may delete audit rows (`aicountly.lifecycle_purge`); `retention_anchor_at` on returns / claims (+ triggers) | `server-php/database/migrations/028_purchase_lifecycle_retention.sql` |
| Adapter: route, write gate, machine gate (kit 1.1), companyinfo sync, Kit on Purchases' own connection | `server-php/src/CompanyLifecycle.php` |
| Receiver route `POST /api/internal/manage/company-lifecycle` | `server-php/src/Routes.php` (no session, no company check: the HMAC is its only credential) |
| Write gate + companyinfo sync | `server-php/src/Context.php` (`assertAllowed()` after Manage confirmed access; `askManage()` syncs); `Controllers/ManageController::companyInfo` (sync) |
| Reviewed purge manifest | `server-php/config/lifecycle-manifest.php` |
| Worker bootstrap (Purchases' PDO: DB name/user from Console) | `server-php/config/lifecycle-kit.php` |
| Cron | `server-php/bin/ops/jobs.txt` → `company-lifecycle` (through `bin/ops/run-job.php`, monitor `purchases.company_lifecycle`) and, kit 1.1, the daily `company-lifecycle-expiry` (monitor `purchases.company_lifecycle_expiry`) |
| Browser | `web/src/lifecycle/` — read-only banner + 423 toast (`services/api.ts`, `services/manage.ts`, `shell/AppShell.tsx`) |
| Tests | `server-php/tests/company_lifecycle.php` (run by `tests/run.sh`), `web/src/lifecycle/companyLifecycle.test.ts` |

## Behaviour

* **Events** (`company_restricted`, `company_unrestricted`, `company_purge_requested`,
  `account_purge_requested`): HMAC-SHA256 over `"{ts}.{body}"` with `LIFECYCLE_WEBHOOK_SECRET`, ±300 s;
  `202 {status: accepted, event_id, cmp_id|uuid, outcome: applied|stale|queued, duplicate}`, `401` bad
  signature, `413` too big, `422 invalid_event`, `503` not configured / not recorded (Manage retries).
  Idempotent on `event_id`; company events apply in `occurred_at` order.
* **Restricted / purge_pending / purged**: every `GET` works; a person's write answers **HTTP 423**
  `{code: "COMPANY_RESTRICTED", message, restricted_until, lifecycle, error: {code, message, restricted_until}}`
  — never 403/404, the seat stays. A caller who may not open the company still gets Manage's 403 (the gate
  runs after it). Writes that only read, render or export stay open (`CompanyLifecycle::LIFECYCLE_ALLOW`):

  | Allowed while read-only | Why |
  |---|---|
  | `POST /api/v1/connect/share-check` | Connect: may a document be shared — a permission check |
  | `POST /api/v1/insights/ask` | an Insights question over figures already recorded |
  | `POST /api/v1/import/preview` | reads an uploaded file, no side effects |
  | `POST /api/v1/import/reconcile-statement` | compares a supplier statement with the bills recorded, writes nothing |

  The test fails when an entry stops matching a route. Exports (`GET …/export`) are GETs. Purchases has no per-person UI-state write.
* **Machine callers** (kit 1.1 `checkMachine`, `CompanyLifecycle::enforceMachine()`): a product backend's
  write into a read-only company answers the same **423 `COMPANY_RESTRICTED`** (with `restricted_until` and
  `lifecycle`) once its key / signature and the company were verified — Books' signed voucher events
  (`POST v1/integration/books/voucher-events`) and any other service-key write (`Context::assertAllowed()`;
  Insights only reads). Reads pass. **Senders defer, never fail** (kit `DeferredSend`): Books' source-event
  outbox keeps the event as `deferred_restricted` and sends it again on renewal.
  `CompanyLifecycle::LIFECYCLE_MACHINE_ALLOW` is empty: Purchases receives no money-moved / telephony /
  provider webhook and no Manage-signed callback (Manage's own lifecycle event is always allowed by the kit).
* **companyinfo is the source of truth**: `lifecycle` / `restricted_until` on Manage's companyinfo (read on
  every fresh access check and by the company picker) are written through, so a missed event is corrected.
* **Browser**: the shell shows a read-only banner (from companyinfo, or the first 423) and a toast with the
  423's `message`.

## What a purge deletes and keeps

The worker (`*/5`) runs the kit's `PurgeEngine`: refusals first (state reversed, or Manage's
company-status says active/restricted when `MANAGE_PRODUCT_SERVICE_KEY` is set), then in **one
transaction** copies each listed table's rows for the company into `aic_lifecycle_archive_c<cmp>_<UTC>`,
deletes them, checks the counts, reads back after commit, marks the company `purged` and acks Manage
(`purged | nothing_to_purge | failed`, `retained[]`). Strict mode refuses to purge while a table with
`cmp_id` (company scope) or `user_uuid` (person scope) is missing from the manifest — a migration adding one must update the manifest in the same commit.

**Retained** (counted, reported to Manage, never copied or deleted while their period runs), each with its legal reason in the manifest:

| Table | What | Basis | Kept until (kit 1.1 `--expire-retained`) |
|---|---|---|---|
| `purchase_bill_requests` | the purchase voucher as asked of Books: supplier invoice no./date, GSTIN, reverse charge, lines and tax | CGST Act s.35-36 + Rules 56-58 (72 months from the annual-return due date); Income-tax Act s.44AA + Rule 6F; Companies Act s.128(5) — eight financial years (9 years from the document date) | 9 years from `created_at` (booked on or after the supplier's invoice date) |
| `purchase_receipt_requests` | goods received (GRN) against an order | same | 9 years from `created_at` |
| `purchase_returns`, `purchase_return_lines` | purchase returns (the debit note raised in Books) | same | 9 years from `retention_anchor_at` (migration 028) / `created_at` |
| `purchase_claims`, `purchase_claim_resolutions` | supplier claims and the debit / credit notes settled on them (amount, tax category, HSN/SAC) | same | 9 years from `retention_anchor_at` (migration 028) / `created_at` |
| `purchase_audit_log` | append-only audit trail | Companies Act s.128(5) + Rule 3(1), Companies (Accounts) Rules — 9 years | 9 years from `created_at` (the worker's session may delete it: migration 028) |

**Kept only while referenced** (kit 1.1 `retain_referenced`; 1.0 kept these tables whole for every company):
the procurement chain. A row a retained bill / goods receipt / return / claim still points at — directly or
through the chain — stays; every other row of the company is purged (archived first); once the retained
records expire, the rows nothing references any more go too (`no_longer_referenced`).

| Table | Kept when |
|---|---|
| `purchase_orders`, `purchase_order_lines` | a bill, goods receipt, return or claim names the PO (its lines stay with it; a return line names its PO line; superseded versions stay with the kept version) |
| `purchase_quotes`, `purchase_quote_lines` | a kept PO came from the quote (its lines stay with it) |
| `purchase_rfqs`, `purchase_rfq_lines` | a kept PO or quote came from the RFQ |
| `purchase_requisitions`, `purchase_requisition_lines` | a kept PO / PO line / RFQ line came from it |

**Retention periods** (kit 1.1). 9 years from the record's own date. The statutes run from later dates: CGST
Act s.36 keeps accounts 72 months from the due date of the annual return for the year — 31 December after the
financial year, at most 21 months after a document dated 1 April, so 72 + 21 = 93 months, inside 96; Income-tax
Rule 6F is 6 years from the end of the assessment year (shorter still). "9 years from the document's date" is
therefore one approximation that covers both; Companies Act s.128(5) ("eight financial years immediately
preceding") can mean up to ~9 years from a document date: owner decision (2026-10-16) — statutory financial
records are kept **9 years** (`retain_years: 9`); GST s.36 needs at most ~93 months.

Returns and claims run from `retention_anchor_at` (migration 028, kept by triggers: the latest of the record and
of every return line / claim resolution recorded against it) so a child recorded later is never deleted with its
parent by `ON DELETE CASCADE`, unarchived and inside its own period. The worker's session
(`config/lifecycle-kit.php`, CLI only) sets `aicountly.lifecycle_purge = on`, which lets `purchase_audit_log`'s
append-only trigger accept its DELETEs; the web API never sets it.

**Scrub**: none — Purchases holds no raw provider payload, card, bank or UPI number.

**Purged** (archived first): rate agreements, approval rules and requests, bid awards and RFQ invitations (supplier portal token hashes), three-way-match results / exceptions / policies, Books' event inbox, the command queue, delivery schedules, PO amendments and the sent/acknowledged log, supplier qualification profiles and scorecards, roles and role assignments, settings, saved views.

**Payment card data**: Purchases holds no payment instrument data — no card, bank account or UPI column (payments to suppliers are made in Books / Pay).

**Account purge** (`account_purge_requested`): the person's saved views (`purchase_user_preferences` by `user_uuid`, in every company) are archived and deleted; their role assignments are company records (in `ignore` for the person scope — purged with the company, revoked by Manage). Ack carries `uuid`, no `cmp_id`.

## Operating it

```bash
cd ~/public_html/api      # the API folder
php lib/company-lifecycle/bin/worker.php --bootstrap=config/lifecycle-kit.php --status
php lib/company-lifecycle/bin/worker.php --bootstrap=config/lifecycle-kit.php --dry-run=company:<cmp_id>
php lib/company-lifecycle/bin/worker.php --bootstrap=config/lifecycle-kit.php --dry-run=account:<uuid>
php lib/company-lifecycle/bin/worker.php --bootstrap=config/lifecycle-kit.php --requeue=<event_id>
php lib/company-lifecycle/bin/worker.php --bootstrap=config/lifecycle-kit.php --list-archives
php lib/company-lifecycle/bin/worker.php --bootstrap=config/lifecycle-kit.php --drop-archives-older-than=90 --confirm
php lib/company-lifecycle/bin/worker.php --bootstrap=config/lifecycle-kit.php --restore=<archive schema> --confirm
php lib/company-lifecycle/bin/worker.php --bootstrap=config/lifecycle-kit.php --check-manifest          # kit 1.1: refusals + warnings, exit 1 on a refusal
php lib/company-lifecycle/bin/worker.php --bootstrap=config/lifecycle-kit.php --expire-retained=dry-run # kit 1.1: what the daily expiry would delete
```

Cron (`bin/ops/jobs.txt`, installed by the deploy):

```
company-lifecycle | */5 * * * *   | {php} bin/ops/run-job.php --name=company-lifecycle --every=300 --monitor=purchases.company_lifecycle -- {php} lib/company-lifecycle/bin/worker.php --bootstrap=config/lifecycle-kit.php
company-lifecycle-expiry | 37 3 * * * | {php} bin/ops/run-job.php --name=company-lifecycle-expiry --every=86400 --monitor=purchases.company_lifecycle_expiry -- {php} lib/company-lifecycle/bin/worker.php --bootstrap=config/lifecycle-kit.php --expire-retained
```

The expiry (kit 1.1, daily) archives and deletes the retained rows of purged companies whose 9 years have run
out, then the orders / quotes / RFQs / requisitions nothing references any more, and records each table in
`aic_lifecycle_expiries`; Manage is not told. A company that renewed (state `active`) is never expired. Purchases
has no other expiry sweep, so each table has one owner. Purchases records no legal holds, so the kit's
`legal_hold` option is not set (a purge is never `held`).

Register monitors `purchases.company_lifecycle` and `purchases.company_lifecycle_expiry` in Console's `cron_monitors`; `bin/ops/release.conf` protects `writable/ops/` (the job's heartbeat).

The archive schemas hold personal data: drop them after the archive window (90 days). The worker's
database user needs `CREATE` on the database (it creates the archive schemas).

## Environment (`server-php/.env`)

| Variable | Required | Meaning |
|---|---|---|
| `LIFECYCLE_WEBHOOK_SECRET` | yes | HMAC secret (≥ 32 chars); same value in Manage as `LIFECYCLE_WEBHOOK_SECRET_PURCHASES`. Unset → events 503, nothing restricted or purged. |
| `MANAGE_API_URL` | yes | `https://manage.aicountly.com` (sandbox `https://manage.gh.aicountly.com`) — where acks go. No default. |
| `MANAGE_PRODUCT_SERVICE_KEY` | no | bearer for Manage `internal/company-status`, asked before each company purge |
| `LIFECYCLE_MANIFEST` | no | another manifest path |

**Go live**: deploy (migration, route, gate, manifest, cron) → set the secret here → set
`LIFECYCLE_WEBHOOK_SECRET_PURCHASES` (same value) in Manage → Purchases is "integrated".
