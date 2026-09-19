<?php

declare(strict_types=1);

/**
 * Empty every table this product owns.
 *
 * Shared by the integration suite and the browser suite so the list lives in
 * one place: two copies of it drift, and the copy that drifts is the one that
 * leaves a table behind and makes a test pass on state nobody intended.
 *
 * Run directly to clear a local database before driving the UI:
 *
 *   php server-php/tests/reset.php
 */

namespace Aicountly\Api;

if (!function_exists(__NAMESPACE__ . '\\resetPurchaseTables')) {
    /** @return list<string> the tables that were emptied */
    function resetPurchaseTables(): array
    {
        $tables = [
            'purchase_match_exceptions', 'purchase_match_results', 'purchase_match_policies',
            'purchase_bill_requests', 'purchase_receipt_requests',
            'purchase_return_lines', 'purchase_returns', 'purchase_claims',
            'purchase_delivery_schedules', 'purchase_order_lines', 'purchase_orders',
            'purchase_agreement_lines', 'purchase_agreements',
            'purchase_bid_awards', 'purchase_quote_lines', 'purchase_quotes',
            'purchase_rfq_invitations', 'purchase_rfq_lines', 'purchase_rfqs',
            'purchase_requisition_lines', 'purchase_requisitions',
            'purchase_approval_requests', 'purchase_approval_rules',
            'purchase_supplier_scorecards', 'purchase_supplier_profiles',
            'purchase_integration_commands', 'purchase_permission_assignments',
            'purchase_permission_profiles', 'purchase_settings', 'purchase_user_preferences',
            'purchase_audit_log',
        ];

        Db::connect()->exec('TRUNCATE ' . implode(', ', $tables) . ' RESTART IDENTITY CASCADE');

        foreach (['stub-idempotency.json', 'stub-requests.jsonl', 'stub-documents.json'] as $file) {
            @unlink(sys_get_temp_dir() . '/' . $file);
        }

        return $tables;
    }
}

// Only when run directly, never when required by a suite that has its own bootstrap.
if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__)) {
    require __DIR__ . '/../src/Env.php';
    require __DIR__ . '/../src/Autoload.php';
    Env::load(__DIR__ . '/../.env');

    $tables = resetPurchaseTables();
    fwrite(STDERR, 'reset ' . count($tables) . " tables\n");
}
