# The five purchase dashboards

`/dashboard/:view` in the app, `GET /api/v1/dashboards/{view}` in the API.

| View | Answers | Audience |
|---|---|---|
| `overview` | What needs a decision today, and what did we spend? | Owner, purchase head |
| `procurement` | What must be ordered, and what is expected to arrive? | Buyer |
| `suppliers` | Is this supplier worth buying from, and what do we do if not? | Category manager |
| `bills-payables` | What is held up, and what falls due next? | Accounts payable |
| `ai-insights` | What would somebody notice reading all of it? | Anyone |

The view is in the **path**, not in component state, so a link to a dashboard
is a link to that dashboard and the browser's Back button moves between them.
Filters are query parameters for the same reason.

## What a metric card is

Not a number. A contract, built by `Dashboards\Metric`:

```
id, label, status, raw_value, formatted_value, format, currency, unit,
basis, explanation, direction, comparison{…}, footnote, drilldown{route, filters}
```

`basis` says what it counts and over what period. `direction` says whether a
bigger number is good news — overdue payables going up is a red line even
though the number grew. `drilldown` is a route in this app plus the filters
that reproduce the figure, and the destination honours them.

### Three states that never collapse into each other

| State | Looks like | Means |
|---|---|---|
| `ready`, `raw_value` `"0"` | ₹0.00 | We looked. The answer is nothing. |
| `ready`, `raw_value` `null` | "Not applicable" | Not a question for this scope. |
| `unavailable` | "Unavailable" + the reason | We could not ask. |

A payables card showing ₹0 because Books timed out is a lie that looks like
good news. The third state exists so that cannot happen, and the export writes
the word `Unavailable` rather than an empty cell that a spreadsheet would sum.

## Money never becomes a float

PostgreSQL sums are cast to `text` in SQL, added up by `Dashboards\Decimal`
(exact integer-string arithmetic), and formatted once by `Dashboards\Format`.
The browser renders `formatted_value`; it never parses `raw_value`.

`bcmath` would do the arithmetic, but it is not a default extension and a
dashboard that silently falls back to floats when it is missing is worse than
one that does the sums itself.

## Comparisons

The comparison period is the **same number of days** immediately before the
selected range. Comparing a 16-day month-to-date against a full previous month
is the commonest way a dashboard reports a collapse in purchasing that did not
happen.

A zero baseline has no percentage: the absolute change is shown instead, and
the card says there was no prior baseline.

## Sample size

Every rate carries the count behind it. Below three observations a rate is not
stated at all — the card reads "too few to rate" with the count. One late
delivery out of one is not a 0% on-time supplier, and a rating that says so
will be ignored the first time somebody checks it.

The composite supplier score publishes its components, their weights, what was
missing and the observation period. Components below the minimum are excluded
and the remaining weights re-normalised, so a supplier is never penalised for
data that does not exist.

## Currency

`scope.reporting_currency` is `null` when documents in the period use more than
one currency. Panels that would have to add them together say so and show
nothing rather than producing a plausible wrong number.

## Where each figure comes from

| Figure | Owner | Read |
|---|---|---|
| Net posted purchases, input GST, payables, ageing | **Smart Books** | `GET dashboard/purchase`, live |
| Open items and their due dates | **Smart Books** | `GET reports/bill-by-bill`, per supplier |
| Stock, reorder level, lead time, item names | **Inventory** | `GET v1/reports/replenishment`, `POST v1/items/bulk-lookup` |
| Orders, receipts, bills, matches, approvals | **Purchases** | its own tables |

Nothing from Books or Inventory is stored. Each source reports its own status,
so a screen degrades one panel at a time rather than all at once.

## Verified upstream gaps

Two things this product cannot answer, stated on the screen rather than
approximated:

**1. "Due within 7 / 15 / 30 days", company-wide.** Books' `reports/bill-by-bill`
requires a single `acc_id` and answers `400` without one
(`ReportsController::billByBill`). There is no company-wide open-items report.
The KPI is therefore `unavailable` with that reason, and payment planning shows
real bill-level due dates for a **bounded, stated** set of suppliers — those
with bills posted from this application in the period, capped at twelve.

*What would close it:* an endpoint returning open items for all creditors, e.g.
`GET reports/open-items?party_type=creditor&as_on=YYYY-MM-DD`, paginated,
returning `acc_id`, `bill_ref`, `bill_date`, `due_date`, `pending_amount`.

**2. Bills with no due date.** Books' `payables_ageing` places them in `not_due`
(`PurchaseDashboardService::payablesAgeing`), and this product cannot separate
them out. The ageing panel says so, and does **not** substitute the invoice date
for a missing due date.

*What would close it:* either a `no_due_date` bucket in `payables_ageing`, or
the open-items endpoint above, from which the bucket can be derived.

A regression test asserts the `acc_id` requirement, so the second gap cannot be
"fixed" by going back to a call that never worked.

## AI

`Ai\AiClient` is the only place this product talks to a model. Three rules:

1. **The key stays on the server.** Read from the server `.env` at request time;
   never returned by an endpoint, never in the bundle, never logged.
2. **The model never writes a query.** `Ai\AskEngine` holds a fixed catalogue of
   questions, each naming the permission it needs and the parameterised query
   behind it. The model only picks which question was meant and writes the
   summary sentence over rows already fetched.
3. **Everything it is given is data.** Supplier names and document text are
   wrapped and labelled untrusted. A supplier called "ignore previous
   instructions" is a supplier with an odd name.

Permissions are applied **before retrieval**, not before display.

With no key configured the rules engine answers instead, every panel says it is
rules-based, and the screen reads "AI insights are currently unavailable". Set
`PURCHASES_AI_API_KEY` on the server to enable commentary; the hint naming that
variable is shown only to somebody holding `settings.manage`.

## Exports

`GET /api/v1/dashboards/{view}/export` builds the CSV by calling the **same
dashboard class** with the same period and filters. An export built from a
second query is an export that disagrees with the screen the first time either
one changes. The `basis` travels with every row: a spreadsheet of figures whose
definitions were left behind on the screen is how two people argue about the
same number. Cells beginning `=`, `+`, `-` or `@` are prefixed, so a supplier
name cannot become a formula.

## Access administration

`Administration → Access`, at `/access`, backed by `GET|POST /api/v1/access/*`.

The permission tables and the 27-permission catalogue have existed since the
first migration. What was missing until now was any way to write to them: the
company owner held everything implicitly, and nobody else could be granted
anything. A single-owner company never noticed; adding a buyer hit a wall no
administrator could open.

### Who the owner is, and where that answer comes from

**The portal does not say.** `POST my.aicountly.com/api/validatesession` answers
with `status`, `uuid_aictly`, `aic_auth_id` and `aic_ses_id` and nothing else —
it is pure authentication and holds no company, so it has never sent `acs_type`.
This product read that field off the session anyway. It was null on every
request for every human, so the owner bypass could not fire, and a company owner
with no assignment row — which was every company, because nothing could write
one — held no permissions at all. The symptom was an app that looked unbuilt:
three of six sidebar groups gone, every Books-backed figure reading
*"Not requested — needs the reports.view or cost.view permission"*.

**Manage says.** Ownership is per company and `GET manage/api/companyinfo`
reports it three ways — `access_type` (1 = owner), `ownership` (`owner` /
`shared`) and `is_creator`. `Context::assertAllowed()` already made that exact
call on every scoped request, to check the session may open the company at all,
and threw the rest of the answer away. It now reads both questions out of the
one response: no extra round trip, and nothing about a role stored here.
`CompanyAccess` maps the three shapes; `Auth::accessTypeFor($cmpId)` holds the
result, keyed by company because the same person can own one and be a delegate
in the next.

**Unknown is not zero.** A Manage payload naming no role resolves to `null`, not
`0`. Callers refuse on null, which is the strict reading, but `/v1/session`
reports `access_resolved: false` separately from `is_owner: false` so the
difference survives to the screen. With no permissions the app says which of the
two it is instead of rendering an empty workspace and leaving the user to guess
— the failure above was invisible for a whole release precisely because it had
no words attached to it.

Under test, the role travels in the stub's bearer token (`…role-0`, `…as-buyer`)
because that is the only thing about a caller the real `ManageClient` sends. The
fixture that used to set `session['acs_type']` directly is gone: it answered a
question the portal is never asked, which is why 91 passing tests did not catch
any of this.

**Profiles** name a set of permissions. **People** hold profiles, and their
permissions are the union across the active ones. A company with none is told
so plainly, and offered four starter profiles shaped around the jobs this
product already separates — a Buyer who can raise an order but not approve it,
an Approver who can do the reverse, Accounts payable, and Read only.

Two rules do the real work, and both are enforced in `AccessController`, not in
React:

**No escalation.** An administrator who is not the owner may grant only
permissions they hold themselves — on a new profile, on an edit of an existing
one, and on an assignment. Without it, `access.manage` is not one permission
among twenty-seven; it is a key to all of them, and the segregation of duties
enforced elsewhere would be one profile edit away from decorative. The profile
editor greys out what the administrator cannot grant, so the rule reads as
design rather than as a surprise rejection.

**No self-lockout.** You cannot remove your own last grant of `access.manage`.
The owner is exempt, because the owner is the recovery path: their access comes
from the portal and nothing here can take it away.

Every change is written to the append-only audit log, and a profile somebody
holds cannot be deleted — cascading would strip their access silently and the
administrator would find out when they could not post a bill.

### Identity stays with the portal

This product stores a **uuid**, a **label the administrator typed**, and the
access decision. It never copies a name from my.aicountly.com: a copied name is
wrong the day somebody marries, and user identity is not this product's to own.

`GET /api/v1/access/people` offers candidates from **this product's own audit
log** — the uuids that have actually acted in this company, with how recently.
It is not a user directory and must not become one; it is the difference
between an administrator typing a uuid from memory and picking one that has
demonstrably signed in.

### Missing upstream capability

Manage provisions members into **Books** and **Auditor** only
(`CompanyAccessService::provisionBooksAccess`, `AuditorProvisionService`). There
is no Purchases equivalent, so an invitation accepted in Manage does not create
anything here — an administrator assigns the profile in this screen.

*What would close it:* a `PurchasesProvisionService` in Manage calling a
provision hook on this product with `{user_uuid, profile}` when an invitation is
accepted, mirroring the Books contract. That is a change to `manage-aicountly`
and is deliberately not made here.

## Running the checks

```bash
server-php/tests/run.sh                       # 73 integration tests
PURCHASE_APP_URL=http://127.0.0.1:5173 \
  npm --prefix web run test:ui                # 13 browser checks
```

See `docs/DEVELOPMENT.md` for the local stack the browser checks need.
