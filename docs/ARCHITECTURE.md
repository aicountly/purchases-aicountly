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

## Stock moves once: GRN on challan, received by the bill

```
PO ──▶ GRN                    ──▶ bill
       INWARD_CHALLAN               Books purchase (party.acc_id, bill.bill_ref = supplier invoice)
       challan_only:                stock_effect from_challan + challan_settlements
       goods in, pending the bill   → Books sends PURCHASE_RECEIPT to Inventory, which
                                      settles the challans and receives the goods ONCE,
                                      valued at the billed cost
```

The GRN used to be a `PURCHASE_RECEIPT` of its own, and the bill's item lines made
Books post another one: every billed purchase received its goods twice. Now the
bill is the single owner of the stock receipt. The settlements are allocated first
in, first out per order line, after what earlier bills settled (`billed_qty`). A
bill for more than was received and not yet billed is refused (one Books voucher
has one stock effect), and so is a bill whose goods were received before this
change (they are already in stock; post that bill in Books). A bill with nothing
received behind it (goods billed ahead) receives the goods itself (`on_invoice`).

**The trade-off, to be decided on:** received goods sit on Inventory's pending-in
register — not on hand, not sellable — until their bill is posted. Receiving them
into stock at the GRN needs a valued inward challan that the bill settles without
receiving again (a "physical GRN"); neither Inventory nor Books has that path yet.
Freight captured on the GRN is no longer sent (a challan values nothing): charges
are capitalised from the bill, where Books allocates bill sundries onto the goods.

Purchase returns follow the same rule: the dispatch is a `DELIVERY_CHALLAN`
(challan_only) and the debit note settles it (`from_challan`), so the goods leave
stock once, with the debit note.

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
stock balance, no GRN table, no payable balance, no input-GST or TDS figure.

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

## Running the tests

```bash
server-php/tests/run.sh
```

Against a real PostgreSQL and a stub standing in for Books and Inventory. The
suite covers every three-way match outcome and includes the **release-blocking
ownership tests**, which read `information_schema` and fail if a mirror table, a
cached remote field or a stored balance has appeared anywhere in the schema.
