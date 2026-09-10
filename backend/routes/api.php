<?php

use App\Http\Controllers\Api\BudgetController;
// Public routes
use App\Http\Controllers\Api\CostingController;
use App\Http\Controllers\Api\FixedAssetController;
use App\Http\Controllers\Api\ToolEquipmentController;
use App\Http\Controllers\Api\V1\DebtAdjustmentController;
use App\Http\Controllers\Api\V1\EInvoiceDocumentController;
use App\Http\Controllers\Api\V1\EInvoiceProviderConfigurationController;
use App\Http\Controllers\Api\V1\ApArFxRevaluationController;
use App\Http\Controllers\Api\V1\ApArReconciliationInputBoundaryController;
use App\Http\Controllers\Api\V1\Inventory\InventorySubledgerGlReconciliationController;
use App\Http\Controllers\Api\V1\ApprovedAccountMappingController;
use App\Http\Controllers\Api\V1\AccountingAuditTrailController;
use App\Http\Controllers\Api\V1\AccountingPolicyController;
use App\Http\Controllers\Api\V1\GL\PeriodController;
use App\Http\Controllers\Api\V1\GL\PeriodCloseSignoffController;
use App\Http\Controllers\Api\V1\GL\ReconciliationController;
use App\Http\Controllers\Api\V1\SettlementAllocationController;
use App\Http\Controllers\Api\V2\ManagementReportDraftController;
use App\Http\Middleware\ClassifyOperationalReportOutput;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::middleware('throttle:login')->group(function () {
        Route::post('/auth/login', 'App\Http\Controllers\Api\V1\AuthController@login')->name('login');
        Route::post('/auth/token', 'App\Http\Controllers\Api\V1\AuthController@token'); // for Desktop app
    });
});

// Protected routes
Route::prefix('v1')->middleware(['auth:sanctum', 'active_account'])->group(function () {
    Route::middleware('role:admin')->group(function () {
        Route::get('/users', [\App\Http\Controllers\Api\V1\UserManagementController::class, 'index']);
        Route::post('/users', [\App\Http\Controllers\Api\V1\UserManagementController::class, 'store']);
        Route::put('/users/{id}', [\App\Http\Controllers\Api\V1\UserManagementController::class, 'update']);
    });
    // Auth
    Route::get('/auth/user', 'App\Http\Controllers\Api\V1\AuthController@user');
    Route::post('/auth/logout', 'App\Http\Controllers\Api\V1\AuthController@logout');

    Route::get('/opening-balances', [\App\Http\Controllers\Api\V1\OpeningBalanceController::class, 'index'])
        ->middleware('permission:opening-balances.view');
    Route::post('/opening-balances', [\App\Http\Controllers\Api\V1\OpeningBalanceController::class, 'store'])
        ->middleware('permission:opening-balances.create');
    Route::get('/opening-balances/{id}', [\App\Http\Controllers\Api\V1\OpeningBalanceController::class, 'show'])
        ->middleware('permission:opening-balances.view');
    Route::put('/opening-balances/{id}', [\App\Http\Controllers\Api\V1\OpeningBalanceController::class, 'update'])
        ->middleware('permission:opening-balances.update');
    Route::post('/opening-balances/{id}/confirm', [\App\Http\Controllers\Api\V1\OpeningBalanceController::class, 'confirm'])
        ->middleware('permission:opening-balances.confirm');

    Route::get('cash/reports/{code}', 'App\Http\Controllers\Api\V1\Report\CashReportController@show')
        ->middleware(['permission:cash.receipts.view', 'permission:cash.payments.view']);

    // Phase 6: Fixed Assets & Tools
    Route::prefix('fixed-assets')->group(function () {
        // Master data & next codes
        Route::get('next-code', [FixedAssetController::class, 'nextCode'])->middleware('permission:fixed-assets.create');

        // Depreciation routes (static sub-routes placed before {id} to avoid collision)
        Route::get('depreciation/periods', [FixedAssetController::class, 'depreciationIndex'])->middleware('permission:fixed-assets.depreciation.view');
        Route::get('depreciation/periods/{id}', [FixedAssetController::class, 'depreciationShow'])->middleware('permission:fixed-assets.depreciation.view');
        Route::get('depreciation/preview', [FixedAssetController::class, 'previewDepreciation'])->middleware('permission:fixed-assets.depreciation.view');
        Route::post('depreciation/run', [FixedAssetController::class, 'runDepreciation'])->middleware('permission:fixed-assets.depreciation.run');
        Route::post('depreciation/{id}/unpost', [FixedAssetController::class, 'unpostDepreciation'])->middleware('permission:fixed-assets.depreciation.unpost');
        Route::delete('depreciation/{id}', [FixedAssetController::class, 'destroyDepreciation'])->middleware('permission:fixed-assets.depreciation.delete');
        Route::post('depreciate', [FixedAssetController::class, 'runDepreciation'])->middleware('permission:fixed-assets.depreciation.run'); // For backwards compatibility

        // Disposals & Revaluations index
        Route::get('disposals', [FixedAssetController::class, 'disposalIndex'])->middleware('permission:fixed-assets.disposals.view');
        Route::get('revaluations', [FixedAssetController::class, 'revaluationIndex'])->middleware('permission:fixed-assets.revaluations.view');

        // Asset CRUD & Lifecycle Actions
        Route::get('/', [FixedAssetController::class, 'index'])->middleware('permission:fixed-assets.view');
        Route::post('/', [FixedAssetController::class, 'store'])->middleware('permission:fixed-assets.create');
        Route::get('{id}', [FixedAssetController::class, 'show'])->middleware('permission:fixed-assets.view');
        Route::put('{id}', [FixedAssetController::class, 'update'])->middleware('permission:fixed-assets.update');
        Route::delete('{id}', [FixedAssetController::class, 'destroy'])->middleware('permission:fixed-assets.delete');
        Route::post('{id}/post', [FixedAssetController::class, 'post'])->middleware('permission:fixed-assets.post');
        Route::post('{id}/unpost', [FixedAssetController::class, 'unpost'])->middleware('permission:fixed-assets.unpost');
        Route::post('{id}/duplicate', [FixedAssetController::class, 'duplicate'])->middleware('permission:fixed-assets.create');
        Route::post('{id}/dispose', [FixedAssetController::class, 'dispose'])->middleware('permission:fixed-assets.disposals.create');
        Route::post('{id}/revalue', [FixedAssetController::class, 'revalue'])->middleware('permission:fixed-assets.revaluations.create');
    });
    // These runs create/void accounting evidence.  The active tenant is
    // resolved by transactional_tenant; a payload company_id is never an
    // authority boundary.
    Route::post('/tools/allocate', [ToolEquipmentController::class, 'runAllocation'])
        ->middleware(['transactional_tenant', 'permission:tools.equipment.allocate']);

    // Phase 7: Costing & Budget
    Route::post('/costing/allocate', [CostingController::class, 'allocateCosts'])
        ->middleware(['transactional_tenant', 'permission:costing.allocate']);
    Route::post('/budgets', [BudgetController::class, 'setBudget'])->middleware('permission:budgets.manage');
    Route::get('/budgets/report', [BudgetController::class, 'report'])->middleware('permission:budgets.view');
    Route::get('/settlement-allocations', [SettlementAllocationController::class, 'index'])
        ->middleware('permission:settlement.allocations.view');
    Route::post('/settlement-allocations', [SettlementAllocationController::class, 'store'])
        ->middleware('permission:settlement.allocations.create');
    Route::post('/settlement-allocations/{id}/reverse', [SettlementAllocationController::class, 'reverse'])
        ->middleware('permission:settlement.allocations.reverse');
    Route::get('/debt-adjustments', [DebtAdjustmentController::class, 'index'])
        ->middleware('permission:debt-adjustments.view');
    Route::post('/debt-adjustments', [DebtAdjustmentController::class, 'store'])
        ->middleware('permission:debt-adjustments.create');
    Route::post('/debt-adjustments/{id}/post', [DebtAdjustmentController::class, 'post'])
        ->middleware('permission:debt-adjustments.post');
    Route::post('/debt-adjustments/{id}/reverse', [DebtAdjustmentController::class, 'reverse'])
        ->middleware('permission:debt-adjustments.reverse');
    Route::get('/ap-ar-fx-revaluations', [ApArFxRevaluationController::class, 'index'])
        ->middleware('permission:ap-ar-fx-revaluations.view');
    // Capture-only AP/AR cutoff/watermark evidence.  This deliberately uses
    // distinct permissions from generic GL reconciliation and period close.
    Route::get('/ap-ar/reconciliation-input-boundaries', [ApArReconciliationInputBoundaryController::class, 'index'])
        ->middleware('permission:apar.reconciliations.view');
    Route::post('/ap-ar/reconciliation-input-boundaries', [ApArReconciliationInputBoundaryController::class, 'store'])
        ->middleware('permission:apar.reconciliations.capture');
    Route::get('/ap-ar/reconciliation-input-boundaries/{uuid}', [ApArReconciliationInputBoundaryController::class, 'show'])
        ->middleware('permission:apar.reconciliations.view');
    Route::get('/inventory/subledger-gl-reconciliations', [InventorySubledgerGlReconciliationController::class, 'index'])
        ->middleware('permission:inventory.reconciliations.view');
    Route::post('/inventory/subledger-gl-reconciliations', [InventorySubledgerGlReconciliationController::class, 'store'])
        ->middleware('permission:inventory.reconciliations.capture');
    Route::get('/inventory/subledger-gl-reconciliations/{uuid}', [InventorySubledgerGlReconciliationController::class, 'show'])
        ->middleware('permission:inventory.reconciliations.view');
    Route::post('/ap-ar-fx-revaluations', [ApArFxRevaluationController::class, 'store'])
        ->middleware('permission:ap-ar-fx-revaluations.create');
    Route::post('/ap-ar-fx-revaluations/{id}/post', [ApArFxRevaluationController::class, 'post'])
        ->middleware('permission:ap-ar-fx-revaluations.post');
    Route::post('/ap-ar-fx-revaluations/{id}/reverse', [ApArFxRevaluationController::class, 'reverse'])
        ->middleware('permission:ap-ar-fx-revaluations.reverse');
    // Provider/evidence records only. This is not an e-invoice issuance or tax-filing integration.
    Route::get('/einvoice-documents', [EInvoiceDocumentController::class, 'index'])
        ->middleware('permission:einvoices.view');
    Route::post('/einvoice-documents', [EInvoiceDocumentController::class, 'store'])
        ->middleware('permission:einvoices.lifecycle.record');
    // Provider control plane/readiness only. No secret values, signing, or transport endpoint is exposed.
    Route::get('/einvoice-provider-configurations', [EInvoiceProviderConfigurationController::class, 'index'])->middleware('permission:einvoices.provider-configurations.view');
    Route::get('/einvoice-provider-readiness', [EInvoiceProviderConfigurationController::class, 'readiness'])->middleware('permission:einvoices.provider-configurations.view');
    Route::post('/einvoice-provider-configurations', [EInvoiceProviderConfigurationController::class, 'store'])->middleware('permission:einvoices.provider-configurations.create');
    Route::put('/einvoice-provider-configurations/{id}', [EInvoiceProviderConfigurationController::class, 'update'])->middleware('permission:einvoices.provider-configurations.update');
    Route::post('/einvoice-provider-configurations/{id}/approve', [EInvoiceProviderConfigurationController::class, 'approve'])->middleware('permission:einvoices.provider-configurations.approve');
    Route::post('/einvoice-provider-configurations/{id}/disable', [EInvoiceProviderConfigurationController::class, 'disable'])->middleware('permission:einvoices.provider-configurations.disable');

    // Forensic, tenant-scoped, read-only evidence projection. It intentionally
    // does not expose original payloads, secrets, IPs or user-agent strings.
    Route::get('/accounting-audit-trail', [AccountingAuditTrailController::class, 'index'])
        ->middleware('permission:accounting.audit-trail.view');
    Route::get('/accounting-audit-trail/{entityType}/{entityId}', [AccountingAuditTrailController::class, 'show'])
        ->where(['entityType' => '[a-z_]+', 'entityId' => '[A-Za-z0-9-]+'])
        ->middleware('permission:accounting.audit-trail.view');

    // Account mappings are owner-supplied control-plane evidence. Draft editing
    // and approval are separate grants; approval additionally enforces SoD.
    Route::get('/approved-account-mappings', [ApprovedAccountMappingController::class, 'index'])
        ->middleware('permission:accounting.account-mappings.view');
    Route::get('/approved-account-mappings/policies', [ApprovedAccountMappingController::class, 'policies'])
        ->middleware('permission:accounting.account-mappings.view');
    Route::post('/approved-account-mappings', [ApprovedAccountMappingController::class, 'store'])
        ->middleware('permission:accounting.account-mappings.create');
    Route::get('/approved-account-mappings/{id}', [ApprovedAccountMappingController::class, 'show'])
        ->middleware('permission:accounting.account-mappings.view');
    Route::put('/approved-account-mappings/{id}', [ApprovedAccountMappingController::class, 'update'])
        ->middleware('permission:accounting.account-mappings.update');
    Route::post('/approved-account-mappings/{id}/approve', [ApprovedAccountMappingController::class, 'approve'])
        ->middleware('permission:accounting.account-mappings.approve');

    // Accountants prepare policy drafts through controlled fields; admins
    // approve them. Raw posting-rule JSON is never accepted from this API.
    Route::get('/accounting-policies/profiles', [AccountingPolicyController::class, 'profiles'])
        ->middleware('permission:accounting.account-mappings.view');
    Route::get('/accounting-policies', [AccountingPolicyController::class, 'index'])
        ->middleware('permission:accounting.account-mappings.view');
    Route::post('/accounting-policies', [AccountingPolicyController::class, 'store'])
        ->middleware('permission:accounting.account-mappings.create');
    Route::put('/accounting-policies/{id}', [AccountingPolicyController::class, 'update'])
        ->middleware('permission:accounting.account-mappings.update');
    Route::post('/accounting-policies/{id}/approve', [AccountingPolicyController::class, 'approve'])
        ->middleware('permission:accounting.account-mappings.approve');

    // Master Data
    Route::prefix('master')->group(function () {
        Route::get('company', 'App\Http\Controllers\Api\V1\Master\CompanyController@index')->middleware('permission:master.company.view');
        Route::put('company/{id}', 'App\Http\Controllers\Api\V1\Master\CompanyController@update')->middleware('permission:master.company.update');
        Route::apiResource('accounts', 'App\Http\Controllers\Api\V1\Master\AccountController')
            ->names([
                'index' => 'master.accounts.index',
                'show' => 'master.accounts.show',
                'store' => 'master.accounts.store',
                'update' => 'master.accounts.update',
                'destroy' => 'master.accounts.destroy',
            ])
            ->middlewareFor(['index', 'show'], 'permission:master.accounts.view')
            ->middlewareFor('store', 'permission:master.accounts.create')
            ->middlewareFor('update', 'permission:master.accounts.update')
            ->middlewareFor('destroy', 'permission:master.accounts.delete');
        Route::post('accounts/{id}/transfer', 'App\\Http\\Controllers\\Api\\V1\\Master\\AccountController@transfer')
            ->middleware('permission:master.accounts.transfer');
        Route::apiResource('customers', 'App\Http\Controllers\Api\V1\Master\CustomerController')
            ->middlewareFor(['index', 'show'], 'permission:master.customers.view')
            ->middlewareFor('store', 'permission:master.customers.create')
            ->middlewareFor('update', 'permission:master.customers.update')
            ->middlewareFor('destroy', 'permission:master.customers.delete');
        Route::apiResource('suppliers', 'App\Http\Controllers\Api\V1\Master\SupplierController')
            ->middlewareFor(['index', 'show'], 'permission:master.suppliers.view')
            ->middlewareFor('store', 'permission:master.suppliers.create')
            ->middlewareFor('update', 'permission:master.suppliers.update')
            ->middlewareFor('destroy', 'permission:master.suppliers.delete');
        Route::apiResource('employees', 'App\Http\Controllers\Api\V1\Master\EmployeeController')
            ->middlewareFor(['index', 'show'], 'permission:master.employees.view')
            ->middlewareFor('store', 'permission:master.employees.create')
            ->middlewareFor('update', 'permission:master.employees.update')
            ->middlewareFor('destroy', 'permission:master.employees.delete');
        Route::apiResource('payment-terms', 'App\Http\Controllers\Api\V1\Master\PaymentTermController')
            ->middlewareFor(['index', 'show'], 'permission:master.payment-terms.view')
            ->middlewareFor('store', 'permission:master.payment-terms.create')
            ->middlewareFor('update', 'permission:master.payment-terms.update')
            ->middlewareFor('destroy', 'permission:master.payment-terms.delete');
        Route::apiResource('item-categories', 'App\Http\Controllers\Api\V1\Master\ItemCategoryController')
            ->middlewareFor(['index', 'show'], 'permission:master.item-categories.view')
            ->middlewareFor('store', 'permission:master.item-categories.create')
            ->middlewareFor('update', 'permission:master.item-categories.update')
            ->middlewareFor('destroy', 'permission:master.item-categories.delete');
        Route::apiResource('units', 'App\Http\Controllers\Api\V1\Master\UnitController')
            ->middlewareFor(['index', 'show'], 'permission:master.units.view')
            ->middlewareFor('store', 'permission:master.units.create')
            ->middlewareFor('update', 'permission:master.units.update')
            ->middlewareFor('destroy', 'permission:master.units.delete');
        Route::apiResource('warehouses', 'App\Http\Controllers\Api\V1\Master\WarehouseController')
            ->middlewareFor(['index', 'show'], 'permission:master.warehouses.view')
            ->middlewareFor('store', 'permission:master.warehouses.create')
            ->middlewareFor('update', 'permission:master.warehouses.update')
            ->middlewareFor('destroy', 'permission:master.warehouses.delete');
        // Tài khoản ngầm định theo Loại chứng từ (MISA AMIS: Thêm Tài khoản ngầm định)
        Route::get('voucher-type-settings/types', 'App\Http\Controllers\Api\V1\Master\VoucherTypeSettingController@types')->middleware('permission:master.voucher-settings.view');
        Route::apiResource('voucher-type-settings', 'App\Http\Controllers\Api\V1\Master\VoucherTypeSettingController')
            ->middlewareFor(['index', 'show'], 'permission:master.voucher-settings.view')
            ->middlewareFor('store', 'permission:master.voucher-settings.create')
            ->middlewareFor('update', 'permission:master.voucher-settings.update')
            ->middlewareFor('destroy', 'permission:master.voucher-settings.delete');
        Route::get('closing-rules', 'App\Http\Controllers\Api\V1\Master\ClosingRuleController@index')
            ->middleware('permission:master.voucher-settings.view');
        Route::get('closing-rules/{id}', 'App\Http\Controllers\Api\V1\Master\ClosingRuleController@show')
            ->middleware('permission:master.voucher-settings.view');
        Route::post('closing-rules', 'App\Http\Controllers\Api\V1\Master\ClosingRuleController@store')
            ->middleware('permission:master.voucher-settings.create');
        Route::put('closing-rules/{id}', 'App\Http\Controllers\Api\V1\Master\ClosingRuleController@update')
            ->middleware('permission:master.voucher-settings.update');
        Route::delete('closing-rules/{id}', 'App\Http\Controllers\Api\V1\Master\ClosingRuleController@destroy')
            ->middleware('permission:master.voucher-settings.delete');
    });

    // Cash Module
    Route::prefix('cash')->group(function () {
        Route::get('book-balance', 'App\Http\Controllers\CashInventoryController@bookBalance')->middleware('permission:cash.inventories.view');
        Route::post('payment-requests/{id}/submit', 'App\Http\Controllers\Api\V1\Cash\CashPaymentRequestController@submit')->middleware('permission:cash.payment-requests.submit');
        Route::apiResource('payment-requests', 'App\Http\Controllers\Api\V1\Cash\CashPaymentRequestController')
            ->middlewareFor(['index', 'show'], 'permission:cash.payment-requests.view')
            ->middlewareFor('store', 'permission:cash.payment-requests.create')
            ->middlewareFor('update', 'permission:cash.payment-requests.update')
            ->middlewareFor('destroy', 'permission:cash.payment-requests.delete');
        Route::post('advance-settlements/{id}/submit', 'App\Http\Controllers\Api\V1\Cash\CashAdvanceSettlementController@submit')->middleware('permission:cash.advance-settlements.submit');
        Route::apiResource('advance-settlements', 'App\Http\Controllers\Api\V1\Cash\CashAdvanceSettlementController')
            ->middlewareFor(['index', 'show'], 'permission:cash.advance-settlements.view')
            ->middlewareFor('store', 'permission:cash.advance-settlements.create')
            ->middlewareFor('update', 'permission:cash.advance-settlements.update')
            ->middlewareFor('destroy', 'permission:cash.advance-settlements.delete');
        Route::get('receipts/next-code', 'App\Http\Controllers\Api\V1\Cash\CashReceiptController@nextCode')->middleware('permission:cash.receipts.create');
        Route::apiResource('receipts', 'App\Http\Controllers\Api\V1\Cash\CashReceiptController')
            ->names('cash.receipts')
            ->middlewareFor(['index', 'show'], 'permission:cash.receipts.view')
            ->middlewareFor('store', 'permission:cash.receipts.create')
            ->middlewareFor('update', 'permission:cash.receipts.update')
            ->middlewareFor('destroy', 'permission:cash.receipts.delete');
        Route::post('receipts/{id}/post', 'App\Http\Controllers\Api\V1\Cash\CashReceiptController@post')->middleware('permission:cash.receipts.post');
        Route::post('receipts/{id}/void', 'App\Http\Controllers\Api\V1\Cash\CashReceiptController@void')->middleware('permission:cash.receipts.void');
        Route::post('receipts/{id}/unpost', 'App\Http\Controllers\Api\V1\Cash\CashReceiptController@unpost')->middleware('permission:cash.receipts.unpost');
        Route::post('receipts/{id}/duplicate', 'App\Http\Controllers\Api\V1\Cash\CashReceiptController@duplicate')->middleware('permission:cash.receipts.create');

        Route::get('payments/next-code', 'App\Http\Controllers\Api\V1\Cash\CashPaymentController@nextCode')->middleware('permission:cash.payments.create');
        Route::apiResource('payments', 'App\Http\Controllers\Api\V1\Cash\CashPaymentController')
            ->names('cash.payments')
            ->middlewareFor(['index', 'show'], 'permission:cash.payments.view')
            ->middlewareFor('store', 'permission:cash.payments.create')
            ->middlewareFor('update', 'permission:cash.payments.update')
            ->middlewareFor('destroy', 'permission:cash.payments.delete');
        Route::post('payments/{id}/post', 'App\Http\Controllers\Api\V1\Cash\CashPaymentController@post')->middleware('permission:cash.payments.post');
        Route::post('payments/{id}/void', 'App\Http\Controllers\Api\V1\Cash\CashPaymentController@void')->middleware('permission:cash.payments.void');
        Route::post('payments/{id}/unpost', 'App\Http\Controllers\Api\V1\Cash\CashPaymentController@unpost')->middleware('permission:cash.payments.unpost');
        Route::post('payments/{id}/duplicate', 'App\Http\Controllers\Api\V1\Cash\CashPaymentController@duplicate')->middleware('permission:cash.payments.create');
    });

    // Bank Module
    Route::prefix('bank')->group(function () {
        // Bank-statement reconciliation is an evidence workflow.  It never
        // posts vouchers or gives authority to close a period.
        Route::get('reconciliation/imports', [\App\Http\Controllers\Api\V1\Bank\BankStatementReconciliationController::class, 'imports'])->middleware('permission:bank.reconciliations.view');
        Route::get('reconciliation/imports/{uuid}/lines', [\App\Http\Controllers\Api\V1\Bank\BankStatementReconciliationController::class, 'lines'])->middleware('permission:bank.reconciliations.view');
        Route::get('reconciliation/lines/{uuid}/timeline', [\App\Http\Controllers\Api\V1\Bank\BankStatementReconciliationController::class, 'timeline'])->middleware('permission:bank.reconciliations.view');
        Route::post('reconciliation/lines/{uuid}/matches', [\App\Http\Controllers\Api\V1\Bank\BankStatementReconciliationController::class, 'proposeMatch'])->middleware('permission:bank.reconciliations.propose');
        Route::post('reconciliation/matches/{id}/decisions', [\App\Http\Controllers\Api\V1\Bank\BankStatementReconciliationController::class, 'decideMatch'])->middleware('permission:bank.reconciliations.decide');
        Route::post('reconciliation/exceptions/{key}/events', [\App\Http\Controllers\Api\V1\Bank\BankStatementReconciliationController::class, 'recordException'])->middleware('permission:bank.reconciliations.exceptions.record');

        Route::apiResource('accounts', 'App\Http\Controllers\Api\V1\Bank\BankAccountController')
            ->names([
                'index' => 'bank.accounts.index',
                'show' => 'bank.accounts.show',
                'store' => 'bank.accounts.store',
                'update' => 'bank.accounts.update',
                'destroy' => 'bank.accounts.destroy',
            ])
            ->middlewareFor(['index', 'show'], 'permission:bank.accounts.view')
            ->middlewareFor('store', 'permission:bank.accounts.create')
            ->middlewareFor('update', 'permission:bank.accounts.update')
            ->middlewareFor('destroy', 'permission:bank.accounts.delete');

        Route::get('receipts/next-code', 'App\Http\Controllers\Api\V1\Bank\BankReceiptController@nextCode')->middleware('permission:bank.receipts.create');
        Route::apiResource('receipts', 'App\Http\Controllers\Api\V1\Bank\BankReceiptController')
            ->names('bank.receipts')
            ->middlewareFor(['index', 'show'], 'permission:bank.receipts.view')
            ->middlewareFor('store', 'permission:bank.receipts.create')
            ->middlewareFor('update', 'permission:bank.receipts.update')
            ->middlewareFor('destroy', 'permission:bank.receipts.delete');
        Route::post('receipts/{id}/post', 'App\Http\Controllers\Api\V1\Bank\BankReceiptController@post')->middleware('permission:bank.receipts.post');
        Route::post('receipts/{id}/void', 'App\Http\Controllers\Api\V1\Bank\BankReceiptController@void')->middleware('permission:bank.receipts.void');
        Route::post('receipts/{id}/unpost', 'App\Http\Controllers\Api\V1\Bank\BankReceiptController@unpost')->middleware('permission:bank.receipts.unpost');
        Route::post('receipts/{id}/duplicate', 'App\Http\Controllers\Api\V1\Bank\BankReceiptController@duplicate')->middleware('permission:bank.receipts.create');

        Route::get('payments/next-code', 'App\Http\Controllers\Api\V1\Bank\BankPaymentController@nextCode')->middleware('permission:bank.payments.create');
        Route::apiResource('payments', 'App\Http\Controllers\Api\V1\Bank\BankPaymentController')
            ->names('bank.payments')
            ->middlewareFor(['index', 'show'], 'permission:bank.payments.view')
            ->middlewareFor('store', 'permission:bank.payments.create')
            ->middlewareFor('update', 'permission:bank.payments.update')
            ->middlewareFor('destroy', 'permission:bank.payments.delete');
        Route::post('payments/{id}/post', 'App\Http\Controllers\Api\V1\Bank\BankPaymentController@post')->middleware('permission:bank.payments.post');
        Route::post('payments/{id}/void', 'App\Http\Controllers\Api\V1\Bank\BankPaymentController@void')->middleware('permission:bank.payments.void');
        Route::post('payments/{id}/unpost', 'App\Http\Controllers\Api\V1\Bank\BankPaymentController@unpost')->middleware('permission:bank.payments.unpost');
        Route::post('payments/{id}/duplicate', 'App\Http\Controllers\Api\V1\Bank\BankPaymentController@duplicate')->middleware('permission:bank.payments.create');
    });

    // Purchase Module
    Route::prefix('purchase')->middleware('transactional_tenant')->group(function () {
        Route::get('dashboard', 'App\Http\Controllers\Api\V1\Purchase\PurchaseDashboardController@stats')->middleware('permission:purchase.dashboard.view');

        Route::get('orders/next-code', 'App\Http\Controllers\Api\V1\Purchase\PurchaseOrderController@nextCode')->middleware('permission:purchase.orders.create');
        Route::apiResource('orders', 'App\Http\Controllers\Api\V1\Purchase\PurchaseOrderController')
            ->names('purchase.orders')
            ->middlewareFor(['index', 'show'], 'permission:purchase.orders.view')
            ->middlewareFor('store', 'permission:purchase.orders.create')
            ->middlewareFor('update', 'permission:purchase.orders.update')
            ->middlewareFor('destroy', 'permission:purchase.orders.delete');

        Route::get('contracts/next-code', 'App\Http\Controllers\Api\V1\Purchase\PurchaseContractController@nextCode')->middleware('permission:purchase.contracts.create');
        Route::apiResource('contracts', 'App\Http\Controllers\Api\V1\Purchase\PurchaseContractController')
            ->middlewareFor(['index', 'show'], 'permission:purchase.contracts.view')
            ->middlewareFor('store', 'permission:purchase.contracts.create')
            ->middlewareFor('update', 'permission:purchase.contracts.update')
            ->middlewareFor('destroy', 'permission:purchase.contracts.delete');

        Route::get('invoices/next-code', 'App\Http\Controllers\Api\V1\Purchase\PurchaseInvoiceController@nextCode')->middleware('permission:purchase.invoices.create');
        Route::get('invoices/outstanding', 'App\Http\Controllers\Api\V1\Purchase\PurchaseInvoiceController@outstanding')->middleware('permission:purchase.invoices.view');
        Route::post('invoices/{id}/pay', 'App\Http\Controllers\Api\V1\Purchase\PurchaseInvoiceController@pay')->middleware(['permission:cash.payments.create', 'permission:cash.payments.post', 'permission:settlement.allocations.create']);
        Route::get('invoices/{id}/dimension-readiness', 'App\Http\Controllers\Api\V1\Purchase\PurchaseInvoiceController@dimensionReadiness')->middleware('permission:purchase.invoices.view');
        Route::get('invoices/{id}/dimensions', 'App\Http\Controllers\Api\V1\Purchase\PurchaseInvoiceController@dimensions')->middleware('permission:purchase.invoices.view');
        Route::get('invoices/{id}/dimension-selection-context', 'App\Http\Controllers\Api\V1\Purchase\PurchaseInvoiceController@dimensionSelectionContext')->middleware('permission:purchase.invoices.view');
        Route::post('invoices/{id}/dimensions', 'App\Http\Controllers\Api\V1\Purchase\PurchaseInvoiceController@saveDimensions')->middleware('permission:purchase.invoices.update');
        // Approval requests are derived from the locked purchase invoice on
        // the server. These routes expose no approval decision or posting
        // action; maker-checker remains enforced by the workflow service.
        Route::get('invoices/{id}/approval-requests', 'App\Http\Controllers\Api\V1\Purchase\PurchaseInvoiceApprovalController@index')->middleware('permission:purchase.invoices.view');
        Route::post('invoices/{id}/approval-requests', 'App\Http\Controllers\Api\V1\Purchase\PurchaseInvoiceApprovalController@store')->middleware('permission:purchase.invoices.update');
        Route::apiResource('invoices', 'App\Http\Controllers\Api\V1\Purchase\PurchaseInvoiceController')
            ->names('purchase.invoices')
            ->middlewareFor(['index', 'show'], 'permission:purchase.invoices.view')
            ->middlewareFor('store', 'permission:purchase.invoices.create')
            ->middlewareFor('update', 'permission:purchase.invoices.update')
            ->middlewareFor('destroy', 'permission:purchase.invoices.delete');
        Route::post('invoices/{id}/post', 'App\Http\Controllers\Api\V1\Purchase\PurchaseInvoiceController@post')->middleware('permission:purchase.invoices.post');
        Route::post('invoices/{id}/void', 'App\Http\Controllers\Api\V1\Purchase\PurchaseInvoiceController@void')->middleware('permission:purchase.invoices.void');
        Route::post('invoices/{id}/unpost', 'App\Http\Controllers\Api\V1\Purchase\PurchaseInvoiceController@unpost')->middleware('permission:purchase.invoices.unpost');
        Route::post('invoices/{id}/duplicate', 'App\Http\Controllers\Api\V1\Purchase\PurchaseInvoiceController@duplicate')->middleware('permission:purchase.invoices.create');

        // Purchase Returns
        Route::get('returns/next-code', 'App\Http\Controllers\Api\V1\Purchase\PurchaseReturnController@nextCode')->middleware('permission:purchase.returns.create');
        Route::apiResource('returns', 'App\Http\Controllers\Api\V1\Purchase\PurchaseReturnController')
            ->names('purchase.returns')
            ->middlewareFor(['index', 'show'], 'permission:purchase.returns.view')
            ->middlewareFor('store', 'permission:purchase.returns.create')
            ->middlewareFor('update', 'permission:purchase.returns.update')
            ->middlewareFor('destroy', 'permission:purchase.returns.delete');
        Route::post('returns/{id}/post', 'App\Http\Controllers\Api\V1\Purchase\PurchaseReturnController@post')->middleware('permission:purchase.returns.post');
        Route::post('returns/{id}/void', 'App\Http\Controllers\Api\V1\Purchase\PurchaseReturnController@void')->middleware('permission:purchase.returns.void');
        Route::post('returns/{id}/unpost', 'App\Http\Controllers\Api\V1\Purchase\PurchaseReturnController@unpost')->middleware('permission:purchase.returns.unpost');
        Route::post('returns/{id}/duplicate', 'App\Http\Controllers\Api\V1\Purchase\PurchaseReturnController@duplicate')->middleware('permission:purchase.returns.create');

        // Purchase Discounts
        Route::get('discounts/next-code', 'App\Http\Controllers\Api\V1\Purchase\PurchaseDiscountController@nextCode')->middleware('permission:purchase.discounts.create');
        Route::apiResource('discounts', 'App\Http\Controllers\Api\V1\Purchase\PurchaseDiscountController')
            ->names('purchase.discounts')
            ->middlewareFor(['index', 'show'], 'permission:purchase.discounts.view')
            ->middlewareFor('store', 'permission:purchase.discounts.create')
            ->middlewareFor('update', 'permission:purchase.discounts.update')
            ->middlewareFor('destroy', 'permission:purchase.discounts.delete');
        Route::post('discounts/{id}/post', 'App\Http\Controllers\Api\V1\Purchase\PurchaseDiscountController@post')->middleware('permission:purchase.discounts.post');
        Route::post('discounts/{id}/void', 'App\Http\Controllers\Api\V1\Purchase\PurchaseDiscountController@void')->middleware('permission:purchase.discounts.void');
        Route::post('discounts/{id}/unpost', 'App\Http\Controllers\Api\V1\Purchase\PurchaseDiscountController@unpost')->middleware('permission:purchase.discounts.unpost');
        Route::post('discounts/{id}/duplicate', 'App\Http\Controllers\Api\V1\Purchase\PurchaseDiscountController@duplicate')->middleware('permission:purchase.discounts.create');

        Route::get('reports', 'App\Http\Controllers\Api\V1\Purchase\PurchaseReportController@index')
            ->middleware(['permission:purchase.reports.view', ClassifyOperationalReportOutput::class]);
        Route::get('ap-aging', 'App\Http\Controllers\Api\V1\Purchase\APAgingController@index')
            ->middleware(['permission:purchase.reports.view', ClassifyOperationalReportOutput::class]);
    });

    // Sales Module
    Route::prefix('sales')->middleware('transactional_tenant')->group(function () {
        Route::get('quotes/next-code', 'App\Http\Controllers\Api\V1\Sales\SalesQuoteController@nextCode')->middleware('permission:sales.quotes.create');
        Route::apiResource('quotes', 'App\Http\Controllers\Api\V1\Sales\SalesQuoteController')
            ->middlewareFor(['index', 'show'], 'permission:sales.quotes.view')
            ->middlewareFor('store', 'permission:sales.quotes.create')
            ->middlewareFor('update', 'permission:sales.quotes.update')
            ->middlewareFor('destroy', 'permission:sales.quotes.delete');
        Route::post('quotes/{id}/duplicate', 'App\Http\Controllers\Api\V1\Sales\SalesQuoteController@duplicate')->middleware('permission:sales.quotes.create');
        Route::post('quotes/{id}/unpost', 'App\Http\Controllers\Api\V1\Sales\SalesQuoteController@unpost')->middleware('permission:sales.quotes.unpost');
        Route::post('quotes/{id}/status', 'App\Http\Controllers\Api\V1\Sales\SalesQuoteController@updateStatus')->middleware('permission:sales.quotes.update');

        Route::get('orders/next-code', 'App\Http\Controllers\Api\V1\Sales\SalesOrderController@nextCode')->middleware('permission:sales.orders.create');
        Route::apiResource('orders', 'App\Http\Controllers\Api\V1\Sales\SalesOrderController')
            ->names('sales.orders')
            ->middlewareFor(['index', 'show'], 'permission:sales.orders.view')
            ->middlewareFor('store', 'permission:sales.orders.create')
            ->middlewareFor('update', 'permission:sales.orders.update')
            ->middlewareFor('destroy', 'permission:sales.orders.delete');
        Route::post('orders/{id}/duplicate', 'App\Http\Controllers\Api\V1\Sales\SalesOrderController@duplicate')->middleware('permission:sales.orders.create');
        Route::post('orders/{id}/unpost', 'App\Http\Controllers\Api\V1\Sales\SalesOrderController@unpost')->middleware('permission:sales.orders.unpost');
        Route::post('orders/{id}/status', 'App\Http\Controllers\Api\V1\Sales\SalesOrderController@updateStatus')->middleware('permission:sales.orders.update');

        Route::get('invoices/next-code', 'App\Http\Controllers\Api\V1\Sales\SalesInvoiceController@nextCode')->middleware('permission:sales.invoices.create');
        Route::get('invoices/outstanding', 'App\Http\Controllers\Api\V1\Sales\SalesInvoiceController@outstanding')->middleware('permission:sales.invoices.view');
        Route::post('invoices/{id}/collect', 'App\Http\Controllers\Api\V1\Sales\SalesInvoiceController@collect')->middleware(['permission:cash.receipts.create', 'permission:cash.receipts.post', 'permission:settlement.allocations.create']);
        Route::get('invoices/{id}/dimension-readiness', 'App\Http\Controllers\Api\V1\Sales\SalesInvoiceController@dimensionReadiness')->middleware('permission:sales.invoices.view');
        Route::get('invoices/{id}/dimensions', 'App\Http\Controllers\Api\V1\Sales\SalesInvoiceController@dimensions')->middleware('permission:sales.invoices.view');
        Route::get('invoices/{id}/dimension-selection-context', 'App\Http\Controllers\Api\V1\Sales\SalesInvoiceController@dimensionSelectionContext')->middleware('permission:sales.invoices.view');
        Route::post('invoices/{id}/dimensions', 'App\Http\Controllers\Api\V1\Sales\SalesInvoiceController@saveDimensions')->middleware('permission:sales.invoices.update');
        // Evidence-only approval projection: no browser approval decision or posting path.
        Route::get('invoices/{id}/approval-requests', 'App\Http\Controllers\Api\V1\Sales\SalesInvoiceApprovalController@index')->middleware('permission:sales.invoices.view');
        Route::post('invoices/{id}/approval-requests', 'App\Http\Controllers\Api\V1\Sales\SalesInvoiceApprovalController@store')->middleware('permission:sales.invoices.update');
        Route::apiResource('invoices', 'App\Http\Controllers\Api\V1\Sales\SalesInvoiceController')
            ->names('sales.invoices')
            ->middlewareFor(['index', 'show'], 'permission:sales.invoices.view')
            ->middlewareFor('store', 'permission:sales.invoices.create')
            ->middlewareFor('update', 'permission:sales.invoices.update')
            ->middlewareFor('destroy', 'permission:sales.invoices.delete');
        Route::post('invoices/{id}/post', 'App\Http\Controllers\Api\V1\Sales\SalesInvoiceController@post')->middleware('permission:sales.invoices.post');
        Route::post('invoices/{id}/void', 'App\Http\Controllers\Api\V1\Sales\SalesInvoiceController@void')->middleware('permission:sales.invoices.void');
        Route::post('invoices/{id}/unpost', 'App\Http\Controllers\Api\V1\Sales\SalesInvoiceController@unpost')->middleware('permission:sales.invoices.unpost');
        Route::post('invoices/{id}/duplicate', 'App\Http\Controllers\Api\V1\Sales\SalesInvoiceController@duplicate')->middleware('permission:sales.invoices.create');

        // Sales Returns
        Route::get('returns/next-code', 'App\Http\Controllers\Api\V1\Sales\SalesReturnController@nextCode')->middleware('permission:sales.returns.create');
        Route::apiResource('returns', 'App\Http\Controllers\Api\V1\Sales\SalesReturnController')
            ->names('sales.returns')
            ->middlewareFor(['index', 'show'], 'permission:sales.returns.view')
            ->middlewareFor('store', 'permission:sales.returns.create')
            ->middlewareFor('update', 'permission:sales.returns.update')
            ->middlewareFor('destroy', 'permission:sales.returns.delete');
        Route::post('returns/{id}/post', 'App\Http\Controllers\Api\V1\Sales\SalesReturnController@post')->middleware('permission:sales.returns.post');
        Route::post('returns/{id}/void', 'App\Http\Controllers\Api\V1\Sales\SalesReturnController@void')->middleware('permission:sales.returns.void');
        Route::post('returns/{id}/unpost', 'App\Http\Controllers\Api\V1\Sales\SalesReturnController@unpost')->middleware('permission:sales.returns.unpost');
        Route::post('returns/{id}/duplicate', 'App\Http\Controllers\Api\V1\Sales\SalesReturnController@duplicate')->middleware('permission:sales.returns.create');

        // Sales Discounts
        Route::get('discounts/next-code', 'App\Http\Controllers\Api\V1\Sales\SalesDiscountController@nextCode')->middleware('permission:sales.discounts.create');
        Route::apiResource('discounts', 'App\Http\Controllers\Api\V1\Sales\SalesDiscountController')
            ->names('sales.discounts')
            ->middlewareFor(['index', 'show'], 'permission:sales.discounts.view')
            ->middlewareFor('store', 'permission:sales.discounts.create')
            ->middlewareFor('update', 'permission:sales.discounts.update')
            ->middlewareFor('destroy', 'permission:sales.discounts.delete');
        Route::post('discounts/{id}/post', 'App\Http\Controllers\Api\V1\Sales\SalesDiscountController@post')->middleware('permission:sales.discounts.post');
        Route::post('discounts/{id}/void', 'App\Http\Controllers\Api\V1\Sales\SalesDiscountController@void')->middleware('permission:sales.discounts.void');
        Route::post('discounts/{id}/unpost', 'App\Http\Controllers\Api\V1\Sales\SalesDiscountController@unpost')->middleware('permission:sales.discounts.unpost');
        Route::post('discounts/{id}/duplicate', 'App\Http\Controllers\Api\V1\Sales\SalesDiscountController@duplicate')->middleware('permission:sales.discounts.create');

        Route::get('reports', 'App\Http\Controllers\Api\V1\Sales\SalesReportController@index')
            ->middleware(['permission:sales.reports.view', ClassifyOperationalReportOutput::class]);
        Route::get('ar-aging', 'App\Http\Controllers\Api\V1\Sales\ARAgingController@index')
            ->middleware(['permission:sales.reports.view', ClassifyOperationalReportOutput::class]);
    });

    // Inventory Module
    Route::prefix('inventory')->middleware('transactional_tenant')->group(function () {
        Route::get('items/next-code', 'App\Http\Controllers\Api\V1\Inventory\ItemController@nextCode')->middleware('permission:inventory.items.create');
        Route::apiResource('items', 'App\Http\Controllers\Api\V1\Inventory\ItemController')
            ->middlewareFor(['index', 'show'], 'permission:inventory.items.view')
            ->middlewareFor('store', 'permission:inventory.items.create')
            ->middlewareFor('update', 'permission:inventory.items.update')
            ->middlewareFor('destroy', 'permission:inventory.items.delete');

        Route::get('receipts/next-code', 'App\Http\Controllers\Api\V1\Inventory\InventoryReceiptController@nextCode')->middleware('permission:inventory.receipts.create');
        Route::apiResource('receipts', 'App\Http\Controllers\Api\V1\Inventory\InventoryReceiptController')
            ->names('inventory.receipts')
            ->middlewareFor(['index', 'show'], 'permission:inventory.receipts.view')
            ->middlewareFor('store', 'permission:inventory.receipts.create')
            ->middlewareFor('update', 'permission:inventory.receipts.update')
            ->middlewareFor('destroy', 'permission:inventory.receipts.delete');
        Route::post('receipts/{id}/post', 'App\Http\Controllers\Api\V1\Inventory\InventoryReceiptController@post')->middleware('permission:inventory.receipts.post');
        Route::post('receipts/{id}/void', 'App\Http\Controllers\Api\V1\Inventory\InventoryReceiptController@void')->middleware('permission:inventory.receipts.void');
        Route::post('receipts/{id}/unpost', 'App\Http\Controllers\Api\V1\Inventory\InventoryReceiptController@unpost')->middleware('permission:inventory.receipts.unpost');
        Route::post('receipts/{id}/duplicate', 'App\Http\Controllers\Api\V1\Inventory\InventoryReceiptController@duplicate')->middleware('permission:inventory.receipts.create');

        Route::get('issues/next-code', 'App\Http\Controllers\Api\V1\Inventory\InventoryIssueController@nextCode')->middleware('permission:inventory.issues.create');
        Route::apiResource('issues', 'App\Http\Controllers\Api\V1\Inventory\InventoryIssueController')
            ->middlewareFor(['index', 'show'], 'permission:inventory.issues.view')
            ->middlewareFor('store', 'permission:inventory.issues.create')
            ->middlewareFor('update', 'permission:inventory.issues.update')
            ->middlewareFor('destroy', 'permission:inventory.issues.delete');
        Route::post('issues/{id}/post', 'App\Http\Controllers\Api\V1\Inventory\InventoryIssueController@post')->middleware('permission:inventory.issues.post');
        Route::post('issues/{id}/void', 'App\Http\Controllers\Api\V1\Inventory\InventoryIssueController@void')->middleware('permission:inventory.issues.void');
        Route::post('issues/{id}/unpost', 'App\Http\Controllers\Api\V1\Inventory\InventoryIssueController@unpost')->middleware('permission:inventory.issues.unpost');
        Route::post('issues/{id}/duplicate', 'App\Http\Controllers\Api\V1\Inventory\InventoryIssueController@duplicate')->middleware('permission:inventory.issues.create');

        // Internal transfer drafts only; posting/stock-ledger integration is a
        // separate controlled capability and is intentionally not exposed.
        Route::apiResource('transfers', 'App\Http\Controllers\Api\V1\Inventory\InventoryTransferController')
            ->middlewareFor(['index', 'show'], 'permission:inventory.transfers.view')
            ->middlewareFor('store', 'permission:inventory.transfers.create')
            ->middlewareFor('update', 'permission:inventory.transfers.update')
            ->middlewareFor('destroy', 'permission:inventory.transfers.delete');
        Route::post('transfers/{id}/post', 'App\Http\Controllers\Api\V1\Inventory\InventoryTransferController@post')->middleware('permission:inventory.transfers.post');
        Route::post('transfers/{id}/unpost', 'App\Http\Controllers\Api\V1\Inventory\InventoryTransferController@unpost')->middleware('permission:inventory.transfers.unpost');

        Route::get('stock-counts/{id}/variance', 'App\Http\Controllers\Api\V1\Inventory\InventoryStockCountController@variance')
            ->middleware('permission:inventory.stock-counts.view');
        Route::post('stock-counts/{id}/adjustment-drafts', 'App\Http\Controllers\Api\V1\Inventory\InventoryStockCountController@adjustmentDrafts')
            ->middleware('permission:inventory.stock-counts.create');
        // Count drafts are a source document. Variance preview is read-only;
        // any inventory adjustment must be created and posted separately.
        Route::apiResource('stock-counts', 'App\Http\Controllers\Api\V1\Inventory\InventoryStockCountController')
            ->middlewareFor(['index', 'show'], 'permission:inventory.stock-counts.view')
            ->middlewareFor('store', 'permission:inventory.stock-counts.create')
            ->middlewareFor('update', 'permission:inventory.stock-counts.update')
            ->middlewareFor('destroy', 'permission:inventory.stock-counts.delete');

        Route::get('cost-calculation/runs', 'App\Http\Controllers\Api\V1\Inventory\CostCalculationController@index')->middleware('permission:inventory.cost-calculation.run');
        Route::post('cost-calculation/run', 'App\Http\Controllers\Api\V1\Inventory\CostCalculationController@run')->middleware('permission:inventory.cost-calculation.run');
        Route::get('stock-report', 'App\Http\Controllers\Api\V1\Inventory\StockReportController@index')->middleware('permission:inventory.stock-report.view');
    });

    // General Ledger Module
    Route::prefix('gl')->group(function () {
        Route::get('journal-entries/next-code', 'App\Http\Controllers\Api\V1\GL\JournalEntryController@nextCode')
            ->middleware('permission:gl.journal-entries.create');
        Route::apiResource('journal-entries', 'App\Http\Controllers\Api\V1\GL\JournalEntryController')
            ->middlewareFor(['index', 'show'], 'permission:gl.journal-entries.view')
            ->middlewareFor('store', 'permission:gl.journal-entries.create')
            ->middlewareFor('update', 'permission:gl.journal-entries.update')
            ->middlewareFor('destroy', 'permission:gl.journal-entries.delete');
        Route::post('journal-entries/{id}/post', 'App\Http\Controllers\Api\V1\GL\JournalEntryController@post')
            ->middleware('permission:gl.journal-entries.post');
        Route::post('journal-entries/{id}/reverse', 'App\Http\Controllers\Api\V1\GL\JournalEntryController@reverse')
            ->middleware('permission:gl.journal-entries.reverse');
        Route::post('journal-entries/{id}/void', 'App\Http\Controllers\Api\V1\GL\JournalEntryController@void')
            ->middleware('permission:gl.journal-entries.reverse');
        Route::post('journal-entries/{id}/unpost', 'App\Http\Controllers\Api\V1\GL\JournalEntryController@unpost')
            ->middleware('permission:gl.journal-entries.reverse');
        Route::post('journal-entries/{id}/duplicate', 'App\Http\Controllers\Api\V1\GL\JournalEntryController@duplicate')
            ->middleware('permission:gl.journal-entries.create');

        Route::get('closing-entries/preview', 'App\Http\Controllers\Api\V1\GL\PeriodClosingController@preview')
            ->middleware('permission:gl.periods.view');
        Route::post('closing-entries/execute', 'App\Http\Controllers\Api\V1\GL\PeriodClosingController@execute')
            ->middleware('permission:gl.periods.close');

        // Static routes MUST come before apiResource to avoid being captured as {periods} param
        Route::post('periods/close', [PeriodController::class, 'close'])
            ->middleware('permission:gl.periods.close');
        Route::post('periods/{periodId}/reopen', [PeriodController::class, 'reopen'])
            ->middleware('permission:gl.periods.close');
        Route::post('periods/{periodId}/close-readiness/evaluate', [ReconciliationController::class, 'evaluateCloseReadiness'])
            ->middleware('permission:gl.reconciliations.execute');
        Route::get('periods/{periodId}/close-readiness', [ReconciliationController::class, 'closeReadiness'])
            ->middleware('permission:gl.reconciliations.view');
        // Controlled evidence workbench only. No route in this group grants
        // close authority based on a package or sign-off event.
        Route::get('periods/{periodId}/close-signoff-packages', [PeriodCloseSignoffController::class, 'index'])
            ->middleware('permission:gl.periods.view');
        Route::post('periods/{periodId}/close-signoff-packages', [PeriodCloseSignoffController::class, 'store'])
            ->middleware('permission:gl.periods.close');
        Route::post('close-signoff-packages/{uuid}/submit', [PeriodCloseSignoffController::class, 'submit'])
            ->middleware('permission:gl.periods.close');
        Route::post('close-signoff-packages/{uuid}/decisions', [PeriodCloseSignoffController::class, 'decide'])
            ->middleware('permission:gl.periods.close');
        // PeriodController intentionally exposes listing/creation only;
        // lifecycle mutation belongs to closing-entries/execute and direct
        // close is rejected. Do not advertise undefined show/update/destroy
        // actions through apiResource (which would fail at runtime).
        Route::apiResource('periods', PeriodController::class)->only(['index', 'store'])
            ->middlewareFor(['index', 'show'], 'permission:gl.periods.view')
            ->middlewareFor(['store', 'update', 'destroy'], 'permission:gl.periods.close');

        Route::get('reconciliations', [ReconciliationController::class, 'index'])
            ->middleware('permission:gl.reconciliations.view');
        Route::post('reconciliations', [ReconciliationController::class, 'store'])
            ->middleware('permission:gl.reconciliations.execute');
        Route::get('reconciliations/{uuid}', [ReconciliationController::class, 'show'])
            ->middleware('permission:gl.reconciliations.view');
        Route::get('reconciliations/{uuid}/results', [ReconciliationController::class, 'results'])
            ->middleware('permission:gl.reconciliations.view');
    });

    // Reports are read-only but still require an explicit reporting permission.
    Route::prefix('reports')->middleware([
        'permission:reports.view',
        ClassifyOperationalReportOutput::class,
    ])->group(function () {
        Route::get('capabilities', 'App\Http\Controllers\Api\V1\Report\ReportCapabilityController@index');
        Route::get('management-capabilities', 'App\Http\Controllers\Api\V1\Report\ManagementReportCapabilityController@index');
        Route::get('statutory-financial-statement-readiness', 'App\Http\Controllers\Api\V1\Report\StatutoryFinancialStatementReadinessController@show');
        Route::get('runs/{uuid}/controls', 'App\Http\Controllers\Api\V1\Report\ReportRunControlController@show');
        Route::get('general-journal', 'App\Http\Controllers\Api\V1\Report\GeneralJournalController@index');
        Route::get('cash-book', 'App\Http\Controllers\Api\V1\Report\CashBookController@index');
        Route::get('general-ledger', 'App\Http\Controllers\Api\V1\Report\GeneralLedgerController@index');
        Route::get('trial-balance', 'App\Http\Controllers\Api\V1\Report\TrialBalanceController@index');
        Route::get('balance-sheet', 'App\Http\Controllers\Api\V1\Report\BalanceSheetController@index');
        Route::get('income-statement', 'App\Http\Controllers\Api\V1\Report\IncomeStatementController@index');
        Route::get('tax/vat', 'App\Http\Controllers\Api\V1\Report\TaxReportController@vatDeclaration');
    });

    // Borrowing contracts and payroll contain sensitive financial / personal
    // information. Each state transition has an explicit server-side grant;
    // client-side menu visibility is never an authorization control.
    Route::get('/borrowing-contracts', 'App\Http\Controllers\BorrowingContractController@index')
        ->middleware('permission:borrowing.contracts.view');
    Route::post('/borrowing-contracts', 'App\Http\Controllers\BorrowingContractController@store')
        ->middleware('permission:borrowing.contracts.create');
    Route::get('/borrowing-contracts/{id}', 'App\Http\Controllers\BorrowingContractController@show')
        ->middleware('permission:borrowing.contracts.view');

    // Reference Vouchers (Chứng từ tham chiếu)
    Route::get('/voucher-references/search', 'App\Http\Controllers\Api\V1\VoucherReferenceController@search')
        ->middleware('permission:voucher-references.view');
    Route::post('/voucher-references/resolve-defaults', 'App\Http\Controllers\Api\V1\VoucherReferenceController@resolveDefaults')
        ->middleware('permission:voucher-references.resolve-defaults');

    // Cash Forecasts (Dự báo dòng tiền)
    Route::get('/cash-forecasts', 'App\Http\Controllers\CashForecastController@index')->middleware('permission:cash.forecasts.view');
    Route::post('/cash-forecasts', 'App\Http\Controllers\CashForecastController@store')->middleware('permission:cash.forecasts.create');
    Route::get('/cash-forecasts/{id}', 'App\Http\Controllers\CashForecastController@show')->middleware('permission:cash.forecasts.view');

    // Tiện ích Kiểm kê quỹ
    Route::get('/cash-inventories', 'App\Http\Controllers\CashInventoryController@index')->middleware('permission:cash.inventories.view');
    Route::post('/cash-inventories', 'App\Http\Controllers\CashInventoryController@store')->middleware('permission:cash.inventories.create');
    Route::get('/cash-inventories/next-code', 'App\Http\Controllers\CashInventoryController@nextCode')->middleware('permission:cash.inventories.create');
    Route::get('/cash-inventories/{id}', 'App\Http\Controllers\CashInventoryController@show')->middleware('permission:cash.inventories.view');
    Route::delete('/cash-inventories/{id}', 'App\Http\Controllers\CashInventoryController@destroy')->middleware('permission:cash.inventories.delete');

    // Payroll Module
    Route::prefix('payroll')->group(function () {
        Route::get('/', 'App\Http\Controllers\Api\V1\PayrollController@index')->middleware('permission:payroll.view');
        Route::post('/', 'App\Http\Controllers\Api\V1\PayrollController@store')->middleware('permission:payroll.create');
        Route::post('{id}/post', 'App\Http\Controllers\Api\V1\PayrollController@post')->middleware('permission:payroll.post');
        Route::post('{id}/void', 'App\Http\Controllers\Api\V1\PayrollController@void')->middleware('permission:payroll.unpost');
    });
});

// Management report v2 is an explicit fail-closed boundary. It must never
// silently reuse the operational-draft v1 calculations.
Route::prefix('v2')->middleware(['auth:sanctum', 'active_account'])->group(function () {
    Route::get('management-reports/ap-aging', [ManagementReportDraftController::class, 'accountsPayableAging'])
        ->middleware(['permission:reports.view', 'permission:purchase.reports.view']);
    Route::get('management-reports/ar-aging', [ManagementReportDraftController::class, 'accountsReceivableAging'])
        ->middleware(['permission:reports.view', 'permission:sales.reports.view']);
    Route::get('management-reports/stock-movement', [ManagementReportDraftController::class, 'stockMovement'])
        ->middleware(['permission:reports.view', 'permission:inventory.stock-report.view']);
    Route::get('management-reports/budget-vs-actual', [ManagementReportDraftController::class, 'budgetVsActual'])
        ->middleware(['permission:reports.view', 'permission:budgets.view']);
});
