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

## Running the checks

```bash
server-php/tests/run.sh                       # 73 integration tests
PURCHASE_APP_URL=http://127.0.0.1:5173 \
  npm --prefix web run test:ui                # 13 browser checks
```

See `docs/DEVELOPMENT.md` for the local stack the browser checks need.
