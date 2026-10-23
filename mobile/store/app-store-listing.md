# Purchases - Aicountly — App Store listing

Pre-submit checklist (all must be true before an iOS submission):

1. `npm run check:store-copy` passes (CI runs it in `mobile-eas-submit.yml` before any iOS submit).
2. The demo account below exists in production, signs in with email + password, and holds the data the review notes promise (see `docs/APP_REVIEW_READINESS.md`, "Demo account").
3. The filled-in notes were checked with `node scripts/check-store-copy.mjs --final /path/outside/the/repo/review-notes.txt`.
4. Repository variable `IOS_SUBMISSION_APPROVED` is `true` — set only when the founder releases this app for iOS.

## Name and subtitle

- **Name:** Purchases - Aicountly
- **Subtitle:** Approve orders, receive goods

## Category

Business (primary), Productivity (secondary)

## Promotional text

Decide on purchase orders away from your desk, and count deliveries at the gate by scanning item barcodes against the order.

## Description

Purchases is the purchasing desk for your company, on your phone. It does the two jobs that cannot wait for a desk:

APPROVE
• See the purchase orders, order changes and requisitions waiting for your decision — with their lines, value and the reason they need you.
• Approve, or reject with a reason that goes back to whoever raised it and stays on the record.
• Your company's approval rules apply: nobody approves what they raised themselves without saying why.

RECEIVE
• Open an expected delivery and scan item barcodes with the camera: each scan counts one unit on its order line.
• Record what was short, and reject damaged goods with a reason — the order stays open for what is still owed.
• Batch numbers, expiry dates and serial numbers are captured at the gate for items that track them.
• Lost signal at the gate? Keep counting; the count is kept on the phone until you record it, and a delivery can never be recorded twice.

TRACK
• Find any purchase order by number or supplier and see its route: approved, issued, received, billed.
• Look up a supplier: their open orders and what your books say you owe them, by due date.

Purchases opens the companies your administrator has shared with you in AICOUNTLY. Writing purchase orders, bills, returns and reports stay on the web, where there is room for them.

## Keywords

purchase order,approval,PO,goods receipt,GRN,receiving,barcode,procurement,supplier,vendor,delivery

## Support URL

https://aicountly.com/support

## Privacy policy URL

https://aicountly.com/privacy-policy

## App Review notes

Purchases is used by people who approve purchase orders and by the receiving desk of a company.

Sign in with email and password: [DEMO_EMAIL] / [DEMO_PASSWORD]. (Sign in with Apple and Google are also offered; please use the email account for review, because it holds the sample company.)

What to try:
1. After sign-in, choose the company "Demo Traders". The first screen is the desk, with two queues.
2. "To approve": open the purchase order waiting there, read its lines and the reason it needs approval, and tap Reject — a reason is required. Then open the second waiting order and tap Approve.
3. "To receive": open the issued order. Tap + on a line (or scan the item barcode printed in the order notes with the camera), tap "Reject damaged…" on another line to record a rejected quantity with a reason, then Review and "Record goods receipt".
4. Back on the desk, "Track orders" shows the order you received against with its route. "Suppliers" shows a supplier's open orders and amount owed.
5. Account (top right) lists what is done on the web instead, and "Delete my AICOUNTLY account" closes the account from inside the app (it can be reopened by signing in within 45 days).

The camera is used only to read barcodes on delivered goods; no photos are taken or stored. There are no purchases in the app.

## Screenshots (6.9" and 6.5")

1. The desk — "To approve" queue.
2. A purchase order awaiting approval, with the reject reason sheet.
3. Receiving: line counts and the scanner.
4. Review before recording the goods receipt.
5. An order's route (approved, issued, received, billed).
6. A supplier: open orders and amount owed.
