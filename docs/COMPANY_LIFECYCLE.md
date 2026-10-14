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
| Tables `aic_lifecycle_events / _state / _jobs / _acks` (the kit's `schema.sql`, verbatim) | `server-php/database/migrations/025_aic_company_lifecycle.sql` |
| Adapter: route, write gate, companyinfo sync, Kit on Purchases' own connection | `server-php/src/CompanyLifecycle.php` |
| Receiver route `POST /api/internal/manage/company-lifecycle` | `server-php/src/Routes.php` (no session, no company check: the HMAC is its only credential) |
| Write gate + companyinfo sync | `server-php/src/Context.php` (`assertAllowed()` after Manage confirmed access; `askManage()` syncs); `Controllers/ManageController::companyInfo` (sync) |
| Reviewed purge manifest | `server-php/config/lifecycle-manifest.php` |
| Worker bootstrap (Purchases' PDO: DB name/user from Console) | `server-php/config/lifecycle-kit.php` |
| Cron | `server-php/bin/ops/jobs.txt` → `company-lifecycle` (through `bin/ops/run-job.php`, monitor `purchases.company_lifecycle`) |
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
* **Machine callers**: a product's service key (Books' signed voucher events, Insights' reads) is not refused: Books' events report what Books already did to a Purchases bill (as in the reference receivers).
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

**Retained** (counted, reported to Manage, never copied or deleted), each with its legal reason in the manifest:

| Table | What | Basis |
|---|---|---|
| `purchase_bill_requests` | the purchase voucher as asked of Books: supplier invoice no./date, GSTIN, reverse charge, lines and tax | CGST Act s.35-36 + Rules 56-58 (72 months from the annual-return due date); Income-tax Act s.44AA + Rule 6F; Companies Act s.128(5) — 8 years |
| `purchase_receipt_requests` | goods received (GRN) against an order | same |
| `purchase_returns`, `purchase_return_lines` | purchase returns (the debit note raised in Books) | same |
| `purchase_claims`, `purchase_claim_resolutions` | supplier claims and the debit / credit notes settled on them (amount, tax category, HSN/SAC) | same |
| `purchase_orders`, `purchase_order_lines`, `purchase_quotes`, `purchase_quote_lines`, `purchase_rfqs`, `purchase_rfq_lines`, `purchase_requisitions`, `purchase_requisition_lines` | **minimal chain** the records above reference by foreign key (bill / GRN / return → PO → its quote / RFQ / requisition) | the PO is the voucher behind the purchase entry (Companies Act s.128(1)); kept only as long as those records |
| `purchase_audit_log` | append-only audit trail (its triggers refuse DELETE) | Companies Act s.128(5) + Rule 3(1), Companies (Accounts) Rules — 8 years |

Why the whole procurement chain: the engine keeps or deletes whole tables per company, and the retained bills / GRNs / returns point at `purchase_orders` (`ON DELETE SET NULL` / `CASCADE`), which in turn points at quotes, RFQs and requisitions — deleting any link would rewrite or cascade into a retained record, which the engine refuses. The supplier master itself lives in Books.

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
```

Cron (`bin/ops/jobs.txt`, installed by the deploy):

```
company-lifecycle | */5 * * * *   | {php} bin/ops/run-job.php --name=company-lifecycle --every=300 --monitor=purchases.company_lifecycle -- {php} lib/company-lifecycle/bin/worker.php --bootstrap=config/lifecycle-kit.php
```

Register monitor `purchases.company_lifecycle` in Console's `cron_monitors`; `bin/ops/release.conf` protects `writable/ops/` (the job's heartbeat).

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
