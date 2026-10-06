# Aicountly Purchase — deep-link routes for Insights (MNY-15)

Insights (or any AICOUNTLY product) may link straight into an Aicountly Purchase page **in a given scope**:

```
https://purchase.aicountly.com/purchase-orders?cmp_id=9101&fy_id=92&bo_id=11
sandbox: https://purchase.gh.aicountly.com/purchase-orders?cmp_id=9101&fy_id=92&bo_id=11
```

## Scope parameters (any route)

| Parameter | Meaning |
| --- | --- |
| `cmp_id` | the company — required for the link to carry a scope at all |
| `fy_id` | the financial year; absent = the company's latest year (as the picker defaults) |
| `bo_id` | the branch; `0` or absent = all branches (company level) |

What the app does with them (`web/src/deepLink/`, tested in `scopeLink.test.ts`):

1. **Checked, not trusted.** Before anything is shown, the company is read from Manage — live, as the signed-in
   person, the same `v1/manage/companyinfo` read the company picker makes. The year must be one Manage lists for that company and the branch one of its branches.
2. **Refused, never swapped.** A company the person cannot open (Manage 403/404), a year that is not that company's, a branch that is not one of its, or a parameter
   that is not a whole number shows "This link was not opened … Nothing was changed. You are still working in
   company N." with a button to continue in the current company. The app never opens another company than
   the link's.
3. **Could not check ≠ refused.** If Manage cannot be asked (network, 5xx), the link is not opened and the
   person sees "Could not check this link" with **Try again**; nothing is changed.
4. **Applied.** A scope that checks out becomes the app's scope (and the remembered last choice), the scope
   parameters are removed from the address bar, and the destination page opens with any other query it
   understands (filters below) left in place.
5. **Kept through sign-in.** A person who is not signed in is sent to my.aicountly.com and comes back to the
   **same** path, query (scope included) and hash — kept in sessionStorage for the round trip (portal auth
   parameters dropped; only a same-origin path is ever restored) — and step 1 runs then.

Before this change the app took its scope from what this browser last used and ignored the URL, so a link from
company A silently opened company B (MNY-15, audit 2026-10-02).

## Routes

| Path | Page | Query the page honours (besides the scope) |
| --- | --- | --- |
| `/` | Overview | — |
| `/dashboard/:view` | dashboards: `overview`, `procurement`, `suppliers`, `bills-payables`, `ai-insights` | `supplier_id`, `buyer`, `warehouse_id`, `q` (dashboards/filters.ts) |
| `/requisitions · /requisitions/:id` | requisitions | list filters via hooks/useUrlFilter.ts |
| `/rfqs · /rfqs/:id` | RFQs | — |
| `/purchase-orders · /purchase-orders/:id` | purchase orders | list filters via hooks/useUrlFilter.ts |
| `/bills · /bills/:id` | bills | list filters via hooks/useUrlFilter.ts |
| `/returns · /returns/:id` | returns | — |
| `/claims · /statements · /suppliers · /reports` | claims, statements, suppliers, reports | — |

Unknown paths fall back to the home page. `auth/callback` is the portal's and is never a destination.

## Insights figures → where they link

| Insights figure (endpoint) | Destination |
| --- | --- |
| Open purchase commitment, received-not-billed (`v1/analytics/open-commitment`) | `/purchase-orders`, `/dashboard/procurement` |
| PO raised vs billed, bills not posted (`v1/analytics/po-to-bill`) | `/bills`, `/dashboard/bills-payables` |

Append the viewer's scope to the destination: `?cmp_id=…&fy_id=…&bo_id=…` (merge with any filter query).
