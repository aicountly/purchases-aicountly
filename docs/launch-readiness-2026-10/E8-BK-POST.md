# E8-BK-POST — Purchases receiver for Books voucher events; integration command classification (launch readiness 2026-10)

Branch `claude/e8-bk-post`. Producer side and the full finding list: books-react-app
`docs/launch-readiness-2026-10/E8-BK-POST.md`.

| Finding | Status | Commit | Evidence |
|---|---|---|---|
| A16 Books cancel/edit not propagated | implemented (receiver) | 6afc2ba | EXECUTED: tests in `tests/run.sh`; cross-product run from Books (`E8SourceEventsCrossProductTest`) against this receiver over HTTP |
| F333 in-flight / code-less Books 409 | implemented (consumer) | b719a09 | EXECUTED: `tests/run.sh` |

## What the receiver does

`POST /v1/integration/books/voucher-events` (service key `books` + `X-Books-Signature` HMAC-SHA256 over
`<t>.<body>`, 10-minute window). Applied once per `event_id` in one transaction with the inbox row
(migration `016_books_event_inbox.sql`). voucher.cancelled: the bill becomes CANCELLED (cancelled_in_books_at), its billed quantities are given back to the PO lines and PO progress is recomputed, so the supplier invoice number is free again; voucher.revised is recorded. An event for a voucher this product did not raise is answered `ignored` with
the reason, so Books lists it on its exception report instead of retrying.

F333: IntegrationCommand::classify reads Books' code as Books sends it; draft_post_in_progress / request_in_progress and a code-less 409 are UNCERTAIN (never BLOCKED or withdrawable); the stub's supplier-invoice duplicate refusal carries Books' real code.

## Configuration (operator)

- `SERVICE_KEYS` gains `books:<key>`; Books holds the same key as `BOOKS_SOURCE_EVENTS_<APP>_KEY`.
- `BOOKS_EVENTS_SIGNING_SECRET` = Books' `BOOKS_SOURCE_EVENTS_SIGNING_SECRET` (>= 16 characters).
- Run the migration before Books starts delivering.
