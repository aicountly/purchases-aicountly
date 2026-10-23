/**
 * What this app does, and what stays on the web — decided from the web app's modules
 * (web/src/App.tsx routes) and what each one asks of the person doing it. README.md explains
 * the same list; the Account screen shows `onTheWeb` under "On the web". A test holds the two
 * lists apart and complete.
 */

export interface ScopeEntry {
  module: string;
  reason: string;
}

export const onThePhone: ScopeEntry[] = [
  { module: 'Approvals (purchase orders, amendments, requisitions)', reason: 'A decision on one document, with its lines and the reason it needs you; reject needs a reason.' },
  { module: 'Goods receipts against a purchase order', reason: 'Done at the gate, with the goods: scan, count, reject damaged, record once.' },
  { module: 'Order tracking', reason: 'Where an order stands, its receipts, and Check / Retry for a receipt Inventory has not confirmed.' },
  { module: 'Supplier lookup', reason: 'Standing, open orders and Smart Books dues for one supplier.' },
];

export const onTheWeb: ScopeEntry[] = [
  { module: 'Writing purchase orders', reason: 'Many lines, each with an item, unit, warehouse, rate, discount and tax category, checked against Smart Books and Inventory: a desk job.' },
  { module: 'Issuing, amending, short-closing and cancelling orders', reason: 'Changes the commitment to a supplier, with the PO document and its version history.' },
  { module: 'Requisitions and sourcing (RFQs, quotes, comparison, award)', reason: 'Side-by-side comparison of several suppliers’ quotes needs a wide screen.' },
  { module: 'Bills and three-way match', reason: 'Supplier invoices matched line by line against orders and receipts, then posted to Smart Books.' },
  { module: 'Returns, debit notes and supplier claims', reason: 'Accounting documents that settle bills; reviewed against the books.' },
  { module: 'Receipt returns and reversals', reason: 'Undoing stock already in Inventory is a correction, made where the whole order history is visible.' },
  { module: 'Dashboards, reports, statements and Ask', reason: 'Tables, charts and exports built for a large screen.' },
  { module: 'Settings, access profiles, Users & Access, audit log', reason: 'Administration, done deliberately at a desk.' },
  { module: 'Importing statements and documents', reason: 'File uploads and column mapping.' },
];
