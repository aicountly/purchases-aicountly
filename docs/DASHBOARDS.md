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
id, label, status, raw_value, formatted_value, exact_value, format, currency,
unit, basis, explanation, direction, comparison{…}, trend[], footer, footnote,
drilldown{route, filters}
```

`formatted_value` may be a short form — ₹28.45L, ₹1.20Cr — where the card asked
for one; `exact_value` is always the full figure and is what the tooltip, the
table and the export show. Nothing rounded is ever the only figure on screen.

`trend` is the card's sparkline: points formatted on the server, with `value`
null for a month that cannot be rated. A null is a GAP in the line, never a
zero. A figure with no honest monthly series behind it — a count of open risks
is a position as at now, not a series — carries an empty `trend` and the card
says so in words instead of drawing a flat line.

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

## The procurement workspace

`/dashboard/procurement` is a workspace rather than a report: six KPIs, the flow
they belong to, three analytics cards, an action list and a feed — then the
working lists (workbench, reorder review, incoming deliveries, approval inbox,
quotation comparison) that a buyer actually spends the day in.

### The flow never adds up

Six stages, requisition → RFQ → quote comparison → purchase order → delivery →
receipt, each with a count, a value and where to click. The panel shows **no
total**, and each stage labels the kind of money it is holding, because the four
kinds are not the same thing:

| Stage | Value shown | What it is |
|---|---|---|
| Requisition | `estimated` | the requester's own estimate, used for approval routing |
| RFQ | `estimated` | that estimate against the quantity asked for — nobody has quoted |
| Quote comparison | `best offers` | the lowest live offer per line, summed |
| Purchase order | `ordered` | a price somebody agreed to |
| Delivery | `still to arrive` | remaining quantity × the rate agreed on the line |
| Receipt / GRN | `awaiting Inventory` | the receipt's own lines at their order-line rate |

An RFQ raised without a requisition behind it has no estimate at all. That stage
reads `—`, not `₹0`.

### Why five of the six cards carry no percentage

Five of them are **positions** — what is open as at now — and one, "Purchase
orders released", is a period total. Only the period total has a comparison,
because only the period total has one: nothing in this schema records how long a
queue was a month ago, and measuring the same queue over an older window always
flatters it, since those documents have had longer to clear. A card with no
comparison shows its **ageing footnote** instead ("3 waiting more than 3 days"),
which is the thing that can be acted on, and the reason there is no delta is in
the card's basis and its tooltip.

The two bars beside a card are that comparison drawn. They appear only where the
server sent a previous figure. A sparkline over invented points is a chart of
nothing, and a reader who finds out is right to stop trusting the rest of the
screen.

### On-time delivery counts deliveries, not orders

`supplier_performance` measures accepted goods receipts dated in the period
against the promised date on the order. An order that is not yet due has not
been late and is not counted; an order with no promised date cannot be judged
either way and is excluded. Counting open orders as failures is the commonest
way a scorecard defames a supplier who has done nothing wrong. The sample
travels with every rate, in the bar's tooltip and in the figures table.

### The insights panel is rules, and says so

`InsightRules::procurement` measures five things — late deliveries by supplier,
receipts Inventory refused, approvals older than three days, a rate more than
10% above the 90-day average for the **same item and the same unit**, and
suppliers invited to quote who have not answered in five days — plus the
clearest saving from the same `opportunities()` rules the AI Insights screen
uses, so the two screens cannot disagree. Every finding clears a stated
threshold; "approval bottleneck" over a two-hour-old queue is an insight nobody
can act on and everybody learns to ignore.

`AiClient::status()` travels in the envelope so the panel can say whether a
model is configured. It changes nothing on this panel: no finding here is
written by a model, none is phrased as a prediction, and an empty list says so
rather than being filled.

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

## Purchase intelligence

The fifth dashboard is a control tower rather than a chat window. Six figures
across the top — purchase value, purchase orders, average PO value, price
anomalies, potential savings and open purchase risks — then three zones: the
opportunities somebody acts on, the analytics they check those against, and the
two lists they scan.

**Nothing on it carries a confidence percentage.** Opportunities come from fixed
rules over the company's own orders, and a rule does not have a confidence
interval. Each card carries an *evidence strength* instead — Strong, Moderate or
Indicative — which is a COUNT of observations with the count stated beside it.
"94% confident" would be the only number on the screen nobody could reproduce.

Three panels are worth stating in full:

- **Price anomalies** compare the latest agreed rate against the MEDIAN of the
  same item's earlier rates in the same period and the same unit, not against
  the previous rate: one unusual order would otherwise make the next ordinary
  one look like a correction. Three observations is the floor — a pair of rates
  is not a distribution. Rates are compared before discount, freight and tax, so
  a change in quantity break or delivery terms can show here legitimately.
- **Spend concentration by category** groups by Inventory's item groups, read
  live on the request. With Inventory unavailable the panel says so rather than
  grouping the spend by something else and calling the result a category.
- **AI Insights** labels every row as an observation, an estimate or a
  projection. Where a model is configured it can comment on these figures in Ask
  Aicountly AI; it writes none of them.

Opportunities and risks are recomputed from the records on every load. There is
nowhere to record that one was reviewed or dismissed, and the drawer says so
rather than offering a Dismiss button that forgets itself on the next refresh.

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

### The screen

Four sections behind one route, with the open one in the URL (`/access?tab=…`)
so a link to the activity log is a link to the activity log:

| Tab | Backed by | Notes |
| --- | --- | --- |
| Profiles | `GET /v1/access/profiles` | Search, and filter by starter / custom / active / inactive — all of it read off fields the endpoint already returns |
| People with access | `GET /v1/access/members` | Grouped by person, because "what can Priya do?" is the question an administrator has |
| Access requests | `GET /v1/access/people` | **There is no request workflow.** See below |
| Activity log | `GET /v1/access/activity` | The `access.*` rows of the append-only audit log |

Two read-only endpoints were added to serve it, and neither stores anything new:

`GET /v1/access/activity` reads back the `access.*` actions this product has
been writing to `purchase_audit_log` since access administration existed. The
rows were always there; until now the only way to read them was psql. The actor
and the target are rendered from the state recorded **at the time**, never
looked up now — a profile deleted last week still has to name itself in the row
that deleted it.

`GET /v1/access/starters` returns the same `STARTER_PROFILES` constant that
`bootstrap()` writes from, with an `exists` flag per company. The confirmation
dialog renders that rather than keeping its own copy: a dialog that previews
four profiles and creates three different ones is worse than no dialog, and the
flag is what stops a second press being offered as though it were the first.

A count that has not arrived yet draws a skeleton and a count this product
cannot answer draws an em dash. Neither draws a zero — "0 people with access"
and "we have not been told yet" look identical as a zero, and only one of them
means somebody needs to do something.

### No request workflow

There is no request table, no endpoint and no approval step. The Access requests
tab says so in one sentence rather than staging a queue out of browser storage:
a fake pending request is worse than an empty tab, because somebody would
approve it and nothing would happen. The KPI card reads `—`, not `0`.

What it shows instead is real — the uuids from `GET /v1/access/people` that have
acted in this company and hold no profile, each with a button that carries them
into the grant form. It is the nearest honest answer to "who is waiting on
access" out of data this product already has.

*What would close it:* a request table and `POST /v1/access/requests` plus
approve/reject, at which point the tab renders the queue and the card counts it.

### Missing upstream capability

Manage provisions members into **Books** and **Auditor** only
(`CompanyAccessService::provisionBooksAccess`, `AuditorProvisionService`). There
is no Purchases equivalent, so an invitation accepted in Manage does not create
anything here — an administrator assigns the profile in this screen.

*What would close it:* a `PurchasesProvisionService` in Manage calling a
provision hook on this product with `{user_uuid, profile}` when an invitation is
accepted, mirroring the Books contract. That is a change to `manage-aicountly`
and is deliberately not made here.

## Reading documents, reconciling statements, printing

### The reader answers facts; a separate step interprets them

`Import\Table` holds every cell as a STRING. A statement holds `1,23,456.78`
and `1.234,56` and `(2,500.00)`, and each becomes a different float depending on
who parses it — so reading and interpreting are separate passes with separate
tests. `Import\Values` does the interpreting and returns **null**, never `0`,
for anything it cannot read: a zero looks like an answer.

The rule that decides a decimal point: whichever of `.` and `,` appears LAST is
the decimal separator, because no notation puts a thousands separator after the
decimal point. That one rule reads Indian, Western and European notation without
knowing where the file came from. The grouping character is stripped BEFORE the
decimal is converted — the other order turns `1.250,00` into `125000`, a
hundredfold error that looks like a plausible number.

| Format | How | Notes |
|---|---|---|
| CSV | delimiter decided by counting consistency across the first rows | comma, semicolon, tab and pipe; BOM and CP1252 handled and reported |
| XLSX | ZipArchive + SimpleXML, no library | shared strings, date serials (including Excel's 1900 leap-year bug), cells placed by column letter so a skipped column does not shift the row |
| PDF | Flate streams inflated, text operators parsed | fragments carry their position, a shared baseline is a line, a wide gap is a column |
| Scan | — | **not read.** There are no characters in a picture. The reader says which of the two it was given rather than returning an empty table |

`ColumnMap` finds the header row by SCORING rows rather than assuming the first
one — statements begin with a letterhead. A mapping the vocabulary cannot
justify comes back as null with its candidates, and the screen asks: a "Credit"
column silently read as the invoice amount reconciles cleanly and is entirely
wrong.

### Statement reconciliation

`/statements`. Two steps on purpose — the file is read and shown BEFORE anything
is compared, because a statement read with the wrong amount column reconciles
confidently and there is no way to tell from the result.

Matching goes in order of evidence: invoice reference first (compared in reduced
form, so `INV-4460` and `INV/004460` agree), then amount and date within five
days, then a unique amount — and only a unique one, because two bills for the
same money make it a coin toss.

Four buckets: **agreed**, **same bill different amount**, **on the statement
only**, **in Smart Books only**. It REPORTS AND STOPS. Books owns the ledger, a
statement is the supplier's opinion of it, and the only thing this product may
do with a disagreement is put it in front of somebody who can decide.

This is the gap `docs` recorded earlier as blocked: Books answers
`reports/bill-by-bill` for one account at a time, which is why there is no
company-wide "due within N days". A reconciliation is inherently one supplier at
a time, so the same constraint costs nothing here.

**Nothing is stored.** The upload is parsed in memory and deleted before the
response is written. It is the supplier's document; what the audit log records
is that a reconciliation happened, by whom, for which supplier, and how it came
out — counts only.

### PDF output

`?format=pdf` on any dashboard export, and the buttons on `/reports`.

`Pdf\PdfDocument` writes the file directly. No library, because this API has no
dependencies at all; no headless browser, because Gotenberg or wkhtmltopdf means
a second service to deploy and patch so that a table of numbers can be laid out
by a rendering engine built for web pages. Fonts are the base fourteen, which
every conforming reader must have, so nothing is embedded and the output is a
few kilobytes.

It renders the SAME payload the screen drew and the CSV exports, so a figure
cannot differ between what somebody saw, exported and printed. An unavailable
figure prints the word **Unavailable** — a printout gets circulated, filed and
quoted months later, and a zero standing in for "we could not ask" becomes a
fact the moment it is printed. Anything the renderer cannot draw (a chart, a
drawer) is NAMED on the page rather than silently dropped.

Helvetica cannot write Devanagari or Tamil. A name that will not survive the
format is substituted visibly rather than dropped — a report that silently omits
a supplier is worse than one that admits it could not print their name.

### Exports are fetched, not linked

Every export button posts through the API client with the session key and saves
the resulting Blob. A plain `<a href>` cannot carry a bearer token, so the links
this replaced were answering 401 and the click did nothing — a bug that had been
in the CSV export since it shipped, found by the first test that actually
downloaded the file. There is no cookie to fall back on and there should not be:
a cookie that authenticates a download authenticates every other request the
same way.

## Running the checks

```bash
server-php/tests/run.sh                       # 122 integration tests
PURCHASE_APP_URL=http://127.0.0.1:5173 \
  npm --prefix web run test:ui                # 31 browser checks
```

See `docs/DEVELOPMENT.md` for the local stack the browser checks need.
