<?php

declare(strict_types=1);

namespace Aicountly\Api;

use Aicountly\Api\Controllers\AccessController;
use Aicountly\Api\Controllers\BillsController;
use Aicountly\Api\Controllers\CatalogController;
use Aicountly\Api\Controllers\ClaimsController;
use Aicountly\Api\Controllers\DashboardController;
use Aicountly\Api\Controllers\DashboardsController;
use Aicountly\Api\Controllers\ImportController;
use Aicountly\Api\Controllers\ManageController;
use Aicountly\Api\Controllers\PurchaseOrdersController;
use Aicountly\Api\Controllers\RequisitionsController;
use Aicountly\Api\Controllers\ReturnsController;
use Aicountly\Api\Controllers\SettingsController;
use Aicountly\Api\Controllers\SourcingController;
use Aicountly\Api\Controllers\SuppliersController;

/**
 * Every route this API serves.
 *
 * The shape follows the rest of the fleet: `/api/v1/<resource>`, company context
 * on the query string or in the body, `{data}` / `{data, meta}` envelopes.
 */
final class Routes
{
    public static function register(Router $router): void
    {
        // The company switcher. Live reads from Manage, NOT company-scoped --
        // this is what the caller uses to choose the company in the first place.
        $router->get('v1/manage/companies', [ManageController::class, 'companies']);
        $router->get('v1/manage/companyinfo', [ManageController::class, 'companyInfo']);

        $router->get('v1/session', [SettingsController::class, 'session']);
        $router->get('v1/permissions', [SettingsController::class, 'permissions']);
        $router->get('v1/settings', [SettingsController::class, 'show']);
        $router->put('v1/settings', [SettingsController::class, 'update']);
        $router->get('v1/settings/match-policies', [SettingsController::class, 'matchPolicies']);
        $router->post('v1/settings/match-policies', [SettingsController::class, 'saveMatchPolicy']);

        // Who may do what. Every route here needs `access.manage`, and the
        // escalation and self-lockout rules live in the controller, not the UI.
        $router->get('v1/access/catalogue', [AccessController::class, 'catalogue']);
        $router->get('v1/access/profiles', [AccessController::class, 'profiles']);
        $router->post('v1/access/profiles', [AccessController::class, 'saveProfile']);
        $router->post('v1/access/profiles/bootstrap', [AccessController::class, 'bootstrap']);
        $router->delete('v1/access/profiles/{id}', [AccessController::class, 'deleteProfile']);
        $router->get('v1/access/members', [AccessController::class, 'members']);
        $router->post('v1/access/members', [AccessController::class, 'assign']);
        $router->delete('v1/access/members/{id}', [AccessController::class, 'unassign']);
        $router->get('v1/access/people', [AccessController::class, 'people']);

        // Read-through to the products that own the data. Pass-throughs:
        // nothing they return is stored.
        $router->get('v1/catalog/items', [CatalogController::class, 'items']);
        $router->get('v1/catalog/items/search', [CatalogController::class, 'searchItems']);
        $router->get('v1/catalog/availability', [CatalogController::class, 'availability']);
        $router->get('v1/catalog/warehouses', [CatalogController::class, 'warehouses']);
        $router->get('v1/catalog/uoms', [CatalogController::class, 'uoms']);
        $router->get('v1/catalog/suppliers', [CatalogController::class, 'suppliers']);
        $router->get('v1/catalog/tax-categories', [CatalogController::class, 'taxCategories']);
        $router->get('v1/catalog/price-history', [CatalogController::class, 'priceHistory']);

        // Requisitions.
        $router->get('v1/requisitions', [RequisitionsController::class, 'index']);
        $router->post('v1/requisitions', [RequisitionsController::class, 'create']);
        $router->get('v1/requisitions/replenishment', [RequisitionsController::class, 'replenishment']);
        $router->get('v1/requisitions/{id}', [RequisitionsController::class, 'show']);
        $router->post('v1/requisitions/{id}/submit', [RequisitionsController::class, 'submit']);
        $router->post('v1/requisitions/{id}/approve', [RequisitionsController::class, 'approve']);
        $router->post('v1/requisitions/{id}/reject', [RequisitionsController::class, 'reject']);

        // Sourcing: RFQ, quotes, comparison, award.
        $router->get('v1/rfqs', [SourcingController::class, 'index']);
        $router->post('v1/rfqs', [SourcingController::class, 'create']);
        $router->get('v1/rfqs/{id}', [SourcingController::class, 'show']);
        $router->post('v1/rfqs/{id}/issue', [SourcingController::class, 'issue']);
        $router->post('v1/rfqs/{id}/invite', [SourcingController::class, 'invite']);
        $router->post('v1/rfqs/{id}/quotes', [SourcingController::class, 'recordQuote']);
        $router->get('v1/rfqs/{id}/comparison', [SourcingController::class, 'compare']);
        $router->post('v1/rfqs/{id}/award', [SourcingController::class, 'award']);

        // Purchase orders and receiving.
        $router->get('v1/purchase-orders', [PurchaseOrdersController::class, 'index']);
        $router->post('v1/purchase-orders', [PurchaseOrdersController::class, 'create']);
        $router->get('v1/purchase-orders/{id}', [PurchaseOrdersController::class, 'show']);
        $router->get('v1/purchase-orders/{id}/receipt-status', [PurchaseOrdersController::class, 'receiptStatus']);
        $router->post('v1/purchase-orders/{id}/submit', [PurchaseOrdersController::class, 'submit']);
        $router->post('v1/purchase-orders/{id}/approve', [PurchaseOrdersController::class, 'approve']);
        $router->post('v1/purchase-orders/{id}/reject', [PurchaseOrdersController::class, 'reject']);
        $router->post('v1/purchase-orders/{id}/issue', [PurchaseOrdersController::class, 'issue']);
        $router->post('v1/purchase-orders/{id}/acknowledge', [PurchaseOrdersController::class, 'acknowledge']);
        $router->post('v1/purchase-orders/{id}/receive', [PurchaseOrdersController::class, 'receive']);
        $router->post('v1/purchase-orders/{id}/cancel', [PurchaseOrdersController::class, 'cancel']);
        $router->post('v1/receipt-requests/{id}/retry', [PurchaseOrdersController::class, 'retryReceipt']);

        // Vendor bills and the three-way match.
        $router->get('v1/bills', [BillsController::class, 'index']);
        $router->get('v1/bills/payables', [BillsController::class, 'payables']);
        $router->post('v1/bills', [BillsController::class, 'create']);
        $router->get('v1/bills/{id}', [BillsController::class, 'show']);
        $router->post('v1/bills/{id}/rematch', [BillsController::class, 'rematch']);
        $router->post('v1/bills/{id}/post', [BillsController::class, 'post']);
        $router->post('v1/match-exceptions/{id}/accept', [BillsController::class, 'acceptException']);
        $router->post('v1/match-exceptions/{id}/reject', [BillsController::class, 'rejectException']);

        // Returns and claims.
        $router->get('v1/returns', [ReturnsController::class, 'index']);
        $router->post('v1/returns', [ReturnsController::class, 'create']);
        $router->get('v1/returns/{id}', [ReturnsController::class, 'show']);
        $router->post('v1/returns/{id}/approve', [ReturnsController::class, 'approve']);
        $router->post('v1/returns/{id}/dispatch', [ReturnsController::class, 'dispatch']);
        $router->post('v1/returns/{id}/debit-note', [ReturnsController::class, 'debitNote']);

        $router->get('v1/claims', [ClaimsController::class, 'index']);
        $router->post('v1/claims', [ClaimsController::class, 'create']);
        $router->get('v1/claims/{id}', [ClaimsController::class, 'show']);
        $router->post('v1/claims/{id}/{action}', [ClaimsController::class, 'transition']);

        // Suppliers — the procurement profile, not the ledger.
        $router->get('v1/suppliers', [SuppliersController::class, 'index']);
        $router->post('v1/suppliers', [SuppliersController::class, 'upsert']);
        $router->post('v1/suppliers/{id}/status', [SuppliersController::class, 'setStatus']);
        $router->get('v1/suppliers/{id}/scorecard', [SuppliersController::class, 'scorecard']);
        $router->post('v1/suppliers/{id}/scorecard', [SuppliersController::class, 'scorecard']);

        // The five dashboards. The view is in the path so a link to one is a
        // link to that one, and Back behaves.
        $router->get('v1/dashboards/{view}', [DashboardsController::class, 'show']);
        $router->get('v1/dashboards/{view}/export', [DashboardsController::class, 'export']);
        $router->post('v1/insights/ask', [DashboardsController::class, 'ask']);

        // Reading an uploaded document. Both are POST because both take a file;
        // neither writes anything, and `preview` deliberately has no side
        // effects at all so a mapping can be checked before it is acted on.
        $router->post('v1/import/preview', [ImportController::class, 'preview']);
        $router->post('v1/import/reconcile-statement', [ImportController::class, 'reconcile']);

        // The original summary endpoint, kept for anything already calling it.
        $router->get('v1/dashboard', [DashboardController::class, 'index']);
        $router->get('v1/approvals', [DashboardController::class, 'approvals']);
        $router->get('v1/match-exceptions', [DashboardController::class, 'exceptions']);
        $router->get('v1/integration-commands', [DashboardController::class, 'commands']);
    }
}
