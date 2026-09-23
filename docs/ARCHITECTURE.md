# Aicountly Purchases — architecture

Purchases answers exactly one question:

> **What do we need to buy, from whom, on what terms, through what approval and
> purchase order?**

It does not answer "what actually arrived and what is it worth?" (Inventory) or
"what did the supplier bill us and what do we owe?" (Books). If it ever starts
answering those from its own tables, the architecture has failed.

## The rule

**Authoritative data owned by another product is read LIVE, on the request that
needs it. It is never copied into this database.**

There is no synchronisation job in this product, no reconciliation cron, no
mirror table and no "cache" table holding another product's rows. There is
nothing to reconcile because there is no second copy of anything.

| Question | Answered by | How Purchases gets it |
|---|---|---|
| Which company, branch, financial year? | **Manage** | `ManageClient`, live |
| What is this item, and did it arrive? | **Inventory** | `InventoryClient`, live |
| What do we owe, and what tax can we claim? | **Smart Books** | `BooksClient`, live |
| Who is this supplier? | **Contacts** (identity) / **Books** (ledger) | live |
| Are they approved, and how do they perform? | **Purchases** | its own tables |

## Three-way match — the reason this matters

The match compares three documents that live in three products:

```
purchase order   ours
goods receipt    Inventory's  ← fetched live, by source reference, at match time
vendor bill      what the buyer entered, on its way to Books
```

`ThreeWayMatchService` composes them on the request. It stores the **verdict**
and the **human decision** about an exception, and nothing else:
`purchase_match_results.compared_references` holds ids and a timestamp, and a
test asserts it holds nothing more.

A copied receipt quantity would be a number that was true when it was copied. A
short delivery corrected in Inventory an hour later would leave the engine
matching against a quantity nobody believes — and passing it. That is the whole
argument, and it is why there is no `purchase_grn_lines` table.

Verdicts: `MATCHED`, `WITHIN_TOLERANCE`, `REVIEW_REQUIRED`, `BLOCKED`. Two
behaviours worth knowing:

- **Billing less than agreed is never an exception.** Blocking a supplier for
  charging less is a support ticket waiting to happen.
- **An unreachable Inventory is `REVIEW_REQUIRED`, never `MATCHED`.** "We could
  not check" and "we checked and it was fine" are different facts.

## What is stored here, and what is not

`server-php/database/migrations/` is the complete list. The reference columns —
`item_id`, `supplier_account_id`, `inventory_document_uuid`,
`books_voucher_uuid` — point at rows other products own.

**Columns that look like exceptions and are not:**

- `purchase_order_lines.agreed_rate` — the price *this order* committed to. Still
  true when the supplier's list price changes next month.
- `received_qty` / `billed_qty` — progress against *our* commitment, written from
  Inventory's and Books' own responses. They answer "is this PO complete?", never
  "what is in stock?" or "what do we owe?".

**Absent on purpose, and tested for:** no item master, no supplier ledger, no
stock balance, no GRN table, no payable balance, no input-GST or TDS figure —
and no analytics or metrics table: every count and average this product shows
is derived on the request that draws it.

## Segregation of duties

Procurement is where this actually matters, so three separate permissions:
`requisition.approve`, `po.approve`, `match.resolve`. And the person who raised
a requisition or a purchase order **cannot approve it**, whatever permissions
they hold — enforced in the service, not in the UI.

Accepting a match variance costs the company money, so it requires a written
reason and is recorded against the bill.

## How a write to another product works

```
User presses Save
   │
   ├─ 1. IntegrationCommand::open()   mint the idempotency key and STORE it
   ├─ 2. our own row is written and committed
   └─ 3. call Books / Inventory with that key
            ok    → COMPLETED, store the id they returned
            5xx   → FAILED, retryable on the SAME key
            4xx   → BLOCKED, retrying will not help
```

A **retry drives the original request row**, not a new one — `ReceiptService`
splits `request()` from `dispatch()` for exactly this reason. A fresh row would
mint a fresh key, and a receipt Inventory had already recorded but whose response
was lost would be recorded twice.

## Why there is no reconciliation cron

A retry runs when the user asks. A scheduled job walking the command table would
be indistinguishable from the synchronisation this architecture exists to avoid,
and it would hide failures from the one person who could fix them. Anything
unfinished appears on the document (`CommandStrip`) and on the dashboard.

## Cross-service re-entry

Every outbound call carries `X-Saas-Origin: purchases`, and this product refuses
to call back into whichever product is currently calling it. Not a recursion
guard — see `books-react-app/docs/CROSS_SERVICE_CALL_RULES.md`. Every call has a
**connect** bound as well as an overall one.

## The five dashboards

`docs/DASHBOARDS.md` covers them: the metric contract, why an unavailable
figure is never a zero, how money stays decimal from PostgreSQL to the screen,
and the two Smart Books gaps this product states rather than approximates.

They compose the same way everything else here does — our workflow counts from
our own tables, posted money from Books and stock from Inventory, both live, on
the request that renders the screen. Each source reports its own status, so a
dashboard degrades one panel at a time.

## Sourcing, and its figures

`GET /v1/rfqs` returns each enquiry with five numbers counted beside it —
suppliers invited, suppliers who answered, quotations received, lines, and
whether it has been awarded — plus `meta.status_counts` for the lifecycle tabs.
`GET /v1/rfqs/summary` answers for the whole financial year.

**None of it is stored.** There is no sourcing metrics table and no roll-up
job; every figure is counted from `purchase_rfqs`, `purchase_rfq_invitations`,
`purchase_quotes` and `purchase_bid_awards` on the request that draws the
screen. A stored "quotes received" would be a second answer to a question the
quotations already answer, and wrong from the first write that missed it.

Three definitions the screen depends on, so they cannot drift:

- **A quotation** is the latest non-withdrawn revision from one supplier — the
  same rule `compare()` uses. Counting revisions reports four quotations when
  one supplier priced the same enquiry twice.
- **Value** is estimated landed cost: lines plus freight plus other charges.
  Averaged within ONE currency, the one most quotations are in; the rest are
  counted and reported so the screen can say they were left out.
- **Cost savings potential** is the spread between the dearest and cheapest
  comparable quotation on enquiries nobody has decided yet. It stops counting
  the moment an enquiry is awarded, because by then it is a decision and not an
  opportunity.

Quoted money needs `cost.view`. Without it the summary sends `values: null` and
the cards say so rather than showing a zero. A month with no previous month to
compare against gets no percentage at all.

**Not available, and therefore not drawn:** nothing in this product carries a
category — not the RFQ, not its lines, not the item read through from Inventory
— so the sourcing screen has no category column and no category filter. Bulk
RFQ import and saved RFQ templates have no endpoint behind them, so both
buttons are inert and labelled.

## Running the tests

```bash
server-php/tests/run.sh
```

Against a real PostgreSQL and a stub standing in for Books and Inventory. The
suite covers every three-way match outcome and includes the **release-blocking
ownership tests**, which read `information_schema` and fail if a mirror table, a
cached remote field or a stored balance has appeared anywhere in the schema.
