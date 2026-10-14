<?php

declare(strict_types=1);

// Purchases purge manifest for the AICOUNTLY company-lifecycle kit (lib/company-lifecycle, README § Manifest
// review). Drafted with bin/discover-manifest.php from the migrated schema (001–025), then reviewed line by
// line — docs/COMPANY_LIFECYCLE.md § "What a purge deletes and keeps" explains each decision.
//
// Change it in the SAME commit as any migration that adds a table with cmp_id or user_uuid: strict mode
// refuses to purge while such a table is in no list, and tests/company_lifecycle.php fails on the schema.

// Records of inward supplies: the supplier's invoice as booked (number, date, GSTIN, reverse charge, tax),
// the goods received against it, purchase returns and the debit / credit notes settled on supplier claims.
$inwardSupplies = 'CGST Act 2017 s.35-36 and CGST Rules 56-58: accounts and records of inward supplies (purchase '
    . 'invoices, goods received, debit and credit notes, reverse-charge details) kept 72 months from the due date '
    . 'of the annual return for the year; Income-tax Act 1961 s.44AA with Rule 6F: books and documents kept 8 years; '
    . 'Companies Act 2013 s.128(1),(5): books of account and the vouchers behind them kept 8 years';
// The procurement chain the records above point at by foreign key (bill / goods receipt / return → purchase
// order → its lines → the quote, RFQ and requisition it came from). The engine keeps or deletes whole tables
// per company, and deleting these would rewrite (SET NULL) or cascade into the retained records.
$chainForRecords = 'Minimal records the retained purchase bills, goods receipts and returns reference by foreign key '
    . '(purchase order and lines, and the quote / RFQ / requisition each order came from): the order is the voucher '
    . 'behind the purchase entry (Companies Act 2013 s.128(1): books of account with the vouchers relevant to an entry). '
    . 'Kept only as long as those records (CGST Act 2017 s.36: 72 months; Companies Act 2013 s.128(5): 8 years)';

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
        ['table' => 'purchase_settings', 'key' => 'cmp_id'],
        ['table' => 'purchase_user_preferences', 'key' => 'cmp_id'],
    ],

    // Kept on a company purge because a statute requires it (counted, never copied or deleted).
    'company_retain' => [
        // The purchase voucher as Purchases asked Books to post it: supplier invoice number / date, GSTIN,
        // reverse charge, the lines and their tax.
        ['table' => 'purchase_bill_requests', 'key' => 'cmp_id', 'reason' => $inwardSupplies],
        // Goods received (GRN) against an order.
        ['table' => 'purchase_receipt_requests', 'key' => 'cmp_id', 'reason' => $inwardSupplies],
        // Purchase returns (the debit note raised in Books), and supplier claims with the debit / credit
        // notes settled on them (amount, tax category, HSN/SAC).
        ['table' => 'purchase_returns', 'key' => 'cmp_id', 'reason' => $inwardSupplies],
        ['table' => 'purchase_return_lines', 'key' => 'cmp_id', 'reason' => $inwardSupplies],
        ['table' => 'purchase_claims', 'key' => 'cmp_id', 'reason' => $inwardSupplies],
        ['table' => 'purchase_claim_resolutions', 'key' => 'cmp_id', 'reason' => $inwardSupplies],
        // The chain those records point at.
        ['table' => 'purchase_orders', 'key' => 'cmp_id', 'reason' => $chainForRecords],
        ['table' => 'purchase_order_lines', 'key' => 'cmp_id', 'reason' => $chainForRecords],
        ['table' => 'purchase_quotes', 'key' => 'cmp_id', 'reason' => $chainForRecords],
        ['table' => 'purchase_quote_lines', 'key' => 'cmp_id', 'reason' => $chainForRecords],
        ['table' => 'purchase_rfqs', 'key' => 'cmp_id', 'reason' => $chainForRecords],
        ['table' => 'purchase_rfq_lines', 'key' => 'cmp_id', 'reason' => $chainForRecords],
        ['table' => 'purchase_requisitions', 'key' => 'cmp_id', 'reason' => $chainForRecords],
        ['table' => 'purchase_requisition_lines', 'key' => 'cmp_id', 'reason' => $chainForRecords],
        // Append-only audit trail of every change to the records above (its triggers refuse DELETE).
        ['table' => 'purchase_audit_log', 'key' => 'cmp_id', 'reason' => 'Companies Act 2013 s.128(5) and Rule 3(1) of the Companies (Accounts) Rules 2014: the audit trail of the books of account kept 8 years'],
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
    ],
];
