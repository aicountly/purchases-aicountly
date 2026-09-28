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

## Stock moves once: physical GRN, settled by the bill

```
PO ──▶ GRN (one per delivery)          ──▶ bill
       INWARD_CHALLAN, physical:             Books purchase (party.acc_id, bill.bill_ref = supplier invoice)
       goods ON HAND now, at the             stock_effect from_physical_challan + challan_settlements
       order's rate (provisional cost)       → Books sends PURCHASE_RECEIPT to Inventory, which settles
                                               the GRNs WITHOUT moving stock again and trues their cost
                                               up to the billed rate (landed-cost semantics)
```

Every delivery is its own receipt with its own identity (`receipt_uuid`, GRN number) and its own
Inventory document; a retry of that delivery reaches the same document, a second delivery a new
one. The bill is the single owner of the *financial* receipt, the GRN of the *physical* one — so
goods are sellable the day they arrive and are never received twice. Settlements are allocated
first in, first out per order line after what earlier bills settled (`billed_qty`); a bill for
more than was received and not yet billed is refused; goods billed ahead of any GRN are received
by the bill itself (`on_invoice`). Cost the bill cannot absorb (the goods already left stock) is
reported by Inventory (`grn_cost_true_up_not_absorbed`), not silently dropped.

Purchase checks Inventory's `GET v1/capabilities` and Books' `GET integration/capabilities` and
refuses to post a bill whose stock effect either would not honour. `PURCHASE_GRN_STOCK_EFFECT=
challan_only` keeps the older model (goods pending until the bill) for a deployment whose
Inventory and Books predate the physical-GRN release.

Purchase returns follow the same one-movement rule: the dispatch is a `DELIVERY_CHALLAN`
(challan_only, nothing moves) and the debit note settles it (`from_challan`), so the goods leave
stock once, with the debit note. A financial-only return (no goods going back) is a separately
permitted debit note on a ledger, with a reason, and moves no stock.

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
`requisition.approve`, `po.approve`, `match.resolve`. Whether the person who raised
a requisition or a purchase order may also decide it is the company's stated policy
(`sod_policy`, Settings → Approval controls): `strict` — nobody; `owner_with_reason`
(default) — only the company owner, with a reason that is recorded. Enforced in
`SegregationOfDuties`, not in the UI, and changes to the policy are audited.

Accepting a match variance costs the company money, so it requires a written
reason and is recorded against the bill.

## How a write to another product works

```
User presses Save
   │
   ├─ 1. IntegrationCommand::ensure()  the key is the operation itself —
   │                                     purchases:{cmp}:{type}:{entity}:{id}:r{revision} —
   │                                     unique in the database; the body is stored once
   ├─ 2. our own row is written and committed
   └─ 3. claim a lease, call Books / Inventory with that key and the STORED body
            ok, ids validated   → COMPLETED, store the ids they returned
            response lost       → UNCERTAIN, Reconcile or Retry on the SAME key
            5xx / unreachable   → FAILED, retryable on the SAME key
            4xx                 → BLOCKED, retrying will not help
            cancelled meanwhile → CANCELLED (withdraw() is atomic with the cancel)
```

No lock is held across a network call; the lease stops two attempts running at once.

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
