# Purchases — what Insights may bind (Insights alignment 2026-10)

What AICOUNTLY Purchases publishes for AICOUNTLY Insights, as shipped. Contract of record:
`Insights-aicountly/docs/alignment-2026-10/CONTRACTS.md` (common rules and §7). Where this file differs
from CONTRACTS.md, this file is what the code does.

Every `*.json` beside this file was **captured from the real handler** (`server-php/tests/insights_contract.php`,
run through `tests/run.sh` with `CAPTURE_INSIGHTS_CONTRACTS=1`: real PostgreSQL, the real controllers and session
check against Purchases' stub portal/Manage); the suite fails if a handler's shape drifts from its capture.

## Canonical host (NFN-06)

**`purchase.aicountly.com` — singular** (sandbox `purchase.gh.aicountly.com`); API at `https://purchase.aicountly.com/api/`.
Evidence in this repository's deploy configuration: `.github/workflows/deploy-production.yml` (target
`https://purchase.aicountly.com`), `.github/workflows/deploy-sandbox.yml` (`https://purchase.gh.aicountly.com`),
`.github/workflows/verify-live.yml`, `scripts/ci/post-deploy-checks.sh`, `README.md`. The plural
`purchases.aicountly.com` / `purchases.gh.aicountly.com` are aliases only (`web/src/config/aicountlyApps.ts`
`altHosts`); the stale plural in `server-php/index.php`'s header comment was corrected in this change. Insights should
use the singular host.

## Common rules

| Rule | What Purchases does |
| --- | --- |
| Caller | The signed-in person (`Authorization: Bearer <ses_key>`, validated with my.aicountly); company, FY and branch verified with Manage. A product service key is refused. |
| Scope | `cmp_id`, `fy_id` required, `bo_id` (0 = all). A branch also sees company-level (`bo_id = 0`) orders. Orders are stamped with the FY they were raised in; `fy_id` is a hard filter. |
| Unknown parameter | **400** `unknown_parameter`, `error.parameter` names it (`purchases.error-unknown-parameter.json`). |
| Period | `po-to-bill`: `from`/`to` required (`YYYY-MM-DD`, `from <= to`, ≤ 400 days), else **400** naming it. `open-commitment` is "right now" and refuses a period parameter. |
| Permission | `reports.view`. Refusal: **403** with `error.permission`. |
| Money | Decimal strings, 4 places, PostgreSQL `NUMERIC`. Currency **per row** (`meta.currency_per_row: true`); never summed across currencies. |
| Lists | `meta.total`, `meta.returned`, `meta.truncated` (one row per currency; empty = `rows: []`). |
| Basis | `meta.basis`: `accounting: "operational"`, `tax_inclusive: false`, `posted_only: false`, plus a sentence. **Not accounting figures: Books owns the purchase voucher and the supplier balance; never add these to Books'.** Purchases' own Overview reads net purchases and dues from Books — Insights should take those from Books directly, not from here. |
| Read-only | No row is written (row counts compared before/after). |
| Session outage | my.aicountly down/5xx/unreadable → **503** `auth_unavailable` + `Retry-After` (also `GET /api/session`). Only the portal's own 401/403 (or `status ≠ 1`) is a 401. (MNY-18) |

## `GET /api/v1/analytics/open-commitment`

Capture: `purchases.open-commitment.json`. As at the request (a balance; no past `as_of`).

| Field | Meaning |
| --- | --- |
| `open_orders`, `open_lines` | Orders issued / acknowledged / partly received; their lines still awaiting goods. |
| `commitment_value` | Σ (ordered − received) × agreed rate, before line discounts and tax — the definition of Purchases' "Open commitment" card. |
| `received_not_billed_value` | Σ (received − billed) × agreed rate on open and closed orders: goods in that Books has no purchase voucher for yet. |
| `overdue_orders` | Open orders with a line past its promised date. |
| `oldest_open_po_date` | Earliest PO date among open orders. |

## `GET /api/v1/analytics/po-to-bill`

Capture: `purchases.po-to-bill.json`. Cohort: POs dated in `[from, to]`, issued onwards (drafts, unapproved,
cancelled excluded).

| Field | Meaning |
| --- | --- |
| `orders`, `orders_fully_billed`, `orders_partly_billed`, `orders_not_billed` | Cohort counts. |
| `ordered_value`, `received_value`, `billed_value` | Quantity × agreed rate (before discounts and tax): ordered; received (Inventory-confirmed); billed (on bills Smart Books posted). |
| `received_not_billed_value` | Per line, received but not billed. |
| `billed_pc` | `billed / ordered × 100`; `null` when nothing was ordered. |
| `bill_requests` | Bills raised against these orders by state: `posted` (in Books), `in_progress` (draft/matching/matched), `exception`, `failed`. Only posted bills are liabilities in Books. |

The payment leg (PO → bill → payment) stays in Books: Purchases holds no payments.

## Deviations from CONTRACTS.md

- Paths are Purchases' own; no capabilities endpoint.
- `open-commitment` takes no period (current state).
- Errors also carry the fleet's `error.details` object.
