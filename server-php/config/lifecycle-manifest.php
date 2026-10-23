<?php

declare(strict_types=1);

// Purchases purge manifest for the AICOUNTLY company-lifecycle kit (lib/company-lifecycle, README § Manifest
// review). Drafted with bin/discover-manifest.php from the migrated schema (001–023 and 025–026), then reviewed line by
// line — docs/COMPANY_LIFECYCLE.md § "What a purge deletes and keeps" explains each decision.
//
// Change it in the SAME commit as any migration that adds a table with cmp_id or user_uuid: strict mode
// refuses to purge while such a table is in no list, and tests/company_lifecycle.php fails on the schema.

// Records of inward supplies: the supplier's invoice as booked (number, date, GSTIN, reverse charge, tax),
// the goods received against it, purchase returns and the debit / credit notes settled on supplier claims.
$inwardSupplies = 'CGST Act 2017 s.35-36 and CGST Rules 56-58: accounts and records of inward supplies (purchase '
    . 'invoices, goods received, debit and credit notes, reverse-charge details) kept 72 months from the due date '
    . 'of the annual return for the year; Income-tax Act 1961 s.44AA with Rule 6F: books and documents kept 9 years; '
    . 'Companies Act 2013 s.128(1),(5): books of account and the vouchers behind them kept 9 years';
// kit 1.1: the procurement chain the records above point at — purchase order (and its lines) -> the quote,
// RFQ and requisition (and their lines) it came from. 1.0 kept these tables whole for every company (deleting
// a row a retained record points at would rewrite it, or cascade into it); now only the rows a retained
// bill / goods receipt / return / claim still references — directly or through the chain — are kept, and
// every other row of the company is purged (archived first).
$chain = static fn (string $what): string => $what . ' referenced by the retained purchase bills, goods receipts, returns or '
    . 'claims (directly or through the order -> quote -> RFQ -> requisition chain): the order is the voucher behind the '
    . 'purchase entry (Companies Act 2013 s.128(1)); kept as long as those records (CGST Act 2017 s.36: 72 months; '
    . 'Companies Act 2013 s.128(5): eight financial years (9 years from the document date)), then removed by --expire-retained';

// Retention periods (kit 1.1, `bin/worker.php --expire-retained`, daily): 9 years from the record's date.
// CGST Act s.36 runs 72 months from the due date of the annual return for the year — 31 December after the
// financial year, at most 21 months after a document dated 1 April — so 72 + 21 = 93 months < 96: "9 years
// from the document's date" covers it, and Income-tax Rule 6F (6 years from the end of the assessment
// year), with one approximation; Companies Act s.128(5) means up to ~9 years from a document date (owner decision 2026-10-16: 9).
// Bills and goods receipts run from created_at (booked on or after the supplier's invoice / delivery date);
// returns and claims from retention_anchor_at (migration 028: the latest of the record and of every return
// line / claim resolution recorded against it), so a child recorded later is never deleted with its parent
// by ON DELETE CASCADE.

return [
    'app_key' => 'purchases',
    // Env::get('DB_SCHEMA') when a deployment keeps Purchases in its own schema (Db::connect sets search_path).
    'schema'  => class_exists(\Aicountly\Api\Env::class, false) && \Aicountly\Api\Env::get('DB_SCHEMA') !== ''
        ? \Aicountly\Api\Env::get('DB_SCHEMA')
        : 'public',
    'strict'  => true,

    // Deleted (archived first) on company_purge_requested, in THIS order: children before parents.
    'company_tables' => [
        // Rate agreements with suppliers (no foreign key from the retained records).
        ['table' => 'purchase_agreement_lines', 'key' => 'cmp_id'],
        ['table' => 'purchase_agreements', 'key' => 'cmp_id'],
        // Approval workflow: requests, then the rules they cite.
        ['table' => 'purchase_approval_requests', 'key' => 'cmp_id'],
        ['table' => 'purchase_approval_rules', 'key' => 'cmp_id'],
        // Sourcing decisions (awards) and RFQ invitations (supplier portal token hashes).
        ['table' => 'purchase_bid_awards', 'key' => 'cmp_id'],
        ['table' => 'purchase_rfq_invitations', 'key' => 'cmp_id'],
        // Three-way match workings: exceptions, results, then the tolerance policies.
        ['table' => 'purchase_match_exceptions', 'key' => 'cmp_id'],
        ['table' => 'purchase_match_results', 'key' => 'cmp_id'],
        ['table' => 'purchase_match_policies', 'key' => 'cmp_id'],
        // Books' cancel / edit notices and the cross-product command queue: delivery bookkeeping.
        ['table' => 'purchase_books_event_inbox', 'key' => 'cmp_id'],
        ['table' => 'purchase_integration_commands', 'key' => 'cmp_id'],
        // Order follow-up: delivery schedules, amendment workings, the "sent / acknowledged" evidence log.
        ['table' => 'purchase_delivery_schedules', 'key' => 'cmp_id'],
        ['table' => 'purchase_po_amendments', 'key' => 'cmp_id'],
        ['table' => 'purchase_po_communications', 'key' => 'cmp_id'],
        // Supplier qualification and computed scorecards (the supplier master itself lives in Books).
        ['table' => 'purchase_supplier_profiles', 'key' => 'cmp_id'],
        ['table' => 'purchase_supplier_scorecards', 'key' => 'cmp_id'],
        // Roles and their assignments (the person's seat in THIS company: company data), settings, and each
        // person's saved views in the company.
        ['table' => 'purchase_permission_assignments', 'key' => 'cmp_id'],
        ['table' => 'purchase_permission_profiles', 'key' => 'cmp_id'],
        // Manage's signed seat events (CONTRACTS §5): the company's record of who Manage seated here.
        ['table' => 'purchase_manage_seat_events', 'key' => 'cmp_id'],
        // An administrator's removal of a person from Purchases in the company (migration 030).
        ['table' => 'purchase_access_removals', 'key' => 'cmp_id'],
        ['table' => 'purchase_settings', 'key' => 'cmp_id'],
        ['table' => 'purchase_user_preferences', 'key' => 'cmp_id'],

        // kit 1.1 row-level retention — the chain the retained records point at, children before parents. A kept
        // document keeps its lines (the {table: <header>, column: <id>} reference follows the header to them).
        ['table' => 'purchase_order_lines', 'key' => 'cmp_id', 'mode' => 'retain_referenced',
            'referenced_by' => ['purchase_return_lines', ['table' => 'purchase_orders', 'column' => 'po_id', 'parent_column' => 'po_id']],
            'reason' => $chain('Lines of the purchase orders')],
        ['table' => 'purchase_orders', 'key' => 'cmp_id', 'mode' => 'retain_referenced',
            'referenced_by' => ['purchase_bill_requests', 'purchase_receipt_requests', 'purchase_returns', 'purchase_claims', 'purchase_orders'],
            'reason' => $chain('Purchase orders (and the versions they superseded)')],
        ['table' => 'purchase_quote_lines', 'key' => 'cmp_id', 'mode' => 'retain_referenced',
            'referenced_by' => ['purchase_order_lines', ['table' => 'purchase_quotes', 'column' => 'quote_id', 'parent_column' => 'quote_id']],
            'reason' => $chain('Lines of the supplier quotes')],
        ['table' => 'purchase_quotes', 'key' => 'cmp_id', 'mode' => 'retain_referenced',
            'referenced_by' => ['purchase_orders'],
            'reason' => $chain('Supplier quotes')],
        ['table' => 'purchase_rfq_lines', 'key' => 'cmp_id', 'mode' => 'retain_referenced',
            'referenced_by' => ['purchase_quote_lines', ['table' => 'purchase_rfqs', 'column' => 'rfq_id', 'parent_column' => 'rfq_id']],
            'reason' => $chain('Lines of the RFQs')],
        ['table' => 'purchase_rfqs', 'key' => 'cmp_id', 'mode' => 'retain_referenced',
            'referenced_by' => ['purchase_orders', 'purchase_quotes'],
            'reason' => $chain('RFQs')],
        ['table' => 'purchase_requisition_lines', 'key' => 'cmp_id', 'mode' => 'retain_referenced',
            'referenced_by' => ['purchase_order_lines', 'purchase_rfq_lines', ['table' => 'purchase_requisitions', 'column' => 'requisition_id', 'parent_column' => 'requisition_id']],
            'reason' => $chain('Lines of the requisitions')],
        ['table' => 'purchase_requisitions', 'key' => 'cmp_id', 'mode' => 'retain_referenced',
            'referenced_by' => ['purchase_orders'],
            'reason' => $chain('Purchase requisitions')],
    ],

    // Kept on a company purge because a statute requires it (counted, never copied or deleted while their period
    // runs). Children before parents: --expire-retained deletes in this order.
    'company_retain' => [
        // The purchase voucher as Purchases asked Books to post it: supplier invoice number / date, GSTIN,
        // reverse charge, the lines and their tax.
        ['table' => 'purchase_bill_requests', 'key' => 'cmp_id', 'reason' => $inwardSupplies, 'retain_years' => 9, 'retain_from' => 'created_at'],
        // Goods received (GRN) against an order.
        ['table' => 'purchase_receipt_requests', 'key' => 'cmp_id', 'reason' => $inwardSupplies, 'retain_years' => 9, 'retain_from' => 'created_at'],
        // Purchase returns (the debit note raised in Books), and supplier claims with the debit / credit
        // notes settled on them (amount, tax category, HSN/SAC).
        ['table' => 'purchase_return_lines', 'key' => 'cmp_id', 'reason' => $inwardSupplies, 'retain_years' => 9, 'retain_from' => 'created_at'],
        ['table' => 'purchase_returns', 'key' => 'cmp_id', 'reason' => $inwardSupplies, 'retain_years' => 9, 'retain_from' => 'retention_anchor_at'],
        ['table' => 'purchase_claim_resolutions', 'key' => 'cmp_id', 'reason' => $inwardSupplies, 'retain_years' => 9, 'retain_from' => 'created_at'],
        ['table' => 'purchase_claims', 'key' => 'cmp_id', 'reason' => $inwardSupplies, 'retain_years' => 9, 'retain_from' => 'retention_anchor_at'],
        // Append-only audit trail of every change to the records above (its triggers refuse DELETE except to the
        // purge worker's session, migration 028).
        ['table' => 'purchase_audit_log', 'key' => 'cmp_id', 'reason' => 'Companies Act 2013 s.128(5) and Rule 3(1) of the Companies (Accounts) Rules 2014: the audit trail of the books of account kept 9 years',
            'retain_years' => 9, 'retain_from' => 'created_at'],
    ],

    // Deleted on account_purge_requested: the person's own data. Purchases keeps nothing outside a company;
    // the one personal table is each person's saved views inside a company.
    'user_tables' => [
        ['table' => 'purchase_user_preferences', 'key' => 'user_uuid'],
    ],
    'user_retain' => [],

    'ignore' => [
        // Found by the person-scope scan (user_uuid): the person's role in a company is the company's
        // record of who may do what there — removed with the company (above) or by Manage's share revoke.
        ['table' => 'purchase_permission_assignments', 'reason' => 'company seat (role per company), not the person\'s own data: purged with the company, revoked by Manage'],
        ['table' => 'purchase_manage_seat_events', 'reason' => 'Manage\'s signed record of a company seat (who was given which role), not the person\'s own data: purged with the company'],
        ['table' => 'purchase_access_removals', 'reason' => 'the company\'s record that an administrator removed a person from Purchases there, not the person\'s own data: purged with the company'],
    ],
];
