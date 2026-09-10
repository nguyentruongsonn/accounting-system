<?php

// Diagnostic opt-outs are allowed only for the non-production test/legacy
// harness.  A production deployment must remain fail-closed even when an
// operator accidentally carries an old `ACCOUNTING_ENFORCE_*=false` value
// into the environment.  Keep each explicit env() declaration below so the
// static release/security contract can enumerate all 18 controls.
$isProduction = in_array(
    strtolower((string) env('APP_ENV', 'production')),
    ['production', 'prod'],
    true,
);

return [
    /*
     * A posting policy is a tenant-approved control-plane contract.  The
     * purchase-invoice gate is enabled by default outside the legacy test
     * harness so a deployed tenant cannot silently post without one.  Tests
     * that pre-date the policy catalogue explicitly opt out in phpunit.xml;
     * focused policy-gate tests turn it on again.
     *
     * This is deliberately a narrow rollout switch, not a fallback policy.
     * When enabled, absence or ambiguity is a hard posting failure.
     */
    'enforce_purchase_invoice_posting_policy' => $isProduction ? true : env(
        'ACCOUNTING_ENFORCE_PURCHASE_INVOICE_POSTING_POLICY',
        true,
    ),

    // Sales is intentionally an independent rollout control. A tenant may
    // not bypass its own approved sales posting contract merely because the
    // purchase path has a different deployment schedule.
    'enforce_sales_invoice_posting_policy' => $isProduction ? true : env(
        'ACCOUNTING_ENFORCE_SALES_INVOICE_POSTING_POLICY',
        true,
    ),

    /*
     * Compatibility key retained for older environments and evidence-only
     * approval APIs. Routine purchase invoices do not require a per-document
     * admin approval request; post() enforces actor authorization, open-period
     * and approved accounting policy/mapping controls instead.
     */
    'enforce_purchase_invoice_posting_approval' => $isProduction ? true : env(
        'ACCOUNTING_ENFORCE_PURCHASE_INVOICE_POSTING_APPROVAL',
        true,
    ),

    // Explicit document-dimension selections are a separate posting proof.
    // Deployment fails closed; legacy tests opt out explicitly until their
    // fixtures supply policy-bound immutable selections.
    'enforce_purchase_invoice_posting_dimensions' => $isProduction ? true : env(
        'ACCOUNTING_ENFORCE_PURCHASE_INVOICE_POSTING_DIMENSIONS',
        true,
    ),

    // Account selection is independently owner-approved. When enabled, the
    // purchase posting path must resolve every required role/context exactly
    // once; it never falls back to historical account defaults.
    'enforce_purchase_invoice_posting_account_mappings' => $isProduction ? true : env(
        'ACCOUNTING_ENFORCE_PURCHASE_INVOICE_POSTING_ACCOUNT_MAPPINGS',
        true,
    ),

    // Compatibility key for the evidence-only sales approval API. Routine
    // sales invoices are posted directly by the accountant after the same
    // policy/mapping/dimension checks as purchase; no admin approval request
    // is consulted by SalesInvoiceService::post().
    'enforce_sales_invoice_posting_approval' => $isProduction ? true : env(
        'ACCOUNTING_ENFORCE_SALES_INVOICE_POSTING_APPROVAL',
        true,
    ),

    // Sales dimensions are independent evidence. A deployed tenant cannot
    // post a sales invoice requiring dimensions until its explicit immutable
    // selection snapshot is valid for the effective policy and date.
    'enforce_sales_invoice_posting_dimensions' => $isProduction ? true : env(
        'ACCOUNTING_ENFORCE_SALES_INVOICE_POSTING_DIMENSIONS',
        true,
    ),

    // Sales account selection is independently owner-approved. This is a
    // controlled rollout: production fails closed, legacy fixtures opt out.
    'enforce_sales_invoice_posting_account_mappings' => $isProduction ? true : env(
        'ACCOUNTING_ENFORCE_SALES_INVOICE_POSTING_ACCOUNT_MAPPINGS',
        true,
    ),

    // Cash and bank vouchers are an independent high-risk disbursement and
    // collection boundary. Both controls fail closed in deployments; legacy
    // tests opt out explicitly, while focused rollout tests turn them on.
    'enforce_cash_bank_posting_policy' => $isProduction ? true : env('ACCOUNTING_ENFORCE_CASH_BANK_POSTING_POLICY', true),
    'enforce_cash_bank_posting_approval' => $isProduction ? true : env('ACCOUNTING_ENFORCE_CASH_BANK_POSTING_APPROVAL', true),

    // Account choices on cash/bank vouchers are independently owner-approved.
    // When enabled, each persisted debit/credit role must resolve exactly once;
    // the posting engine never falls back to the historical account choice.
    'enforce_cash_bank_posting_account_mappings' => $isProduction ? true : env('ACCOUNTING_ENFORCE_CASH_BANK_POSTING_ACCOUNT_MAPPINGS', true),

    // New policy drafts record their maker. Legacy rows remain nullable; the
    // maker-checker rejection is opt-in outside production until all existing
    // fixtures/rows have an owner-approved migration decision.
    'enforce_accounting_policy_maker_checker' => $isProduction ? true : env('ACCOUNTING_ENFORCE_ACCOUNTING_POLICY_MAKER_CHECKER', false),

    // Cross-voucher auto-fill currently has no approved-mapping resolver. Do
    // not expose its historical hard-coded account lines in a deployment
    // until that resolver is wired to owner-approved mappings.
    'enforce_voucher_reference_account_mappings' => $isProduction ? true : env('ACCOUNTING_ENFORCE_VOUCHER_REFERENCE_ACCOUNT_MAPPINGS', true),

    // Inventory posting still derives debit/credit accounts from legacy
    // voucher/item defaults. Keep the production journal boundary closed
    // until an owner-approved inventory mapping resolver is integrated.
    'enforce_inventory_posting_account_mappings' => $isProduction ? true : env('ACCOUNTING_ENFORCE_INVENTORY_POSTING_ACCOUNT_MAPPINGS', true),

    // New inventory issues are blocked when their item/warehouse balance as
    // of the posting date is insufficient. Test-only legacy fixture suites
    // may explicitly disable this while exercising unrelated GL behavior.
    'enforce_inventory_stock_availability' => $isProduction ? true : env('ACCOUNTING_ENFORCE_INVENTORY_STOCK_AVAILABILITY', true),

    // Fixed-asset lifecycle posting still has no owner-approved mapping
    // resolver; keep increment, depreciation, disposal, and revaluation
    // journal creation closed in production.
    'enforce_fixed_asset_posting_account_mappings' => $isProduction ? true : env(
        'ACCOUNTING_ENFORCE_FIXED_ASSET_POSTING_ACCOUNT_MAPPINGS',
        true,
    ),

    // Returns and discounts resolve every journal role through the
    // owner-approved commercial-adjustment mapping gate. Production remains
    // fail-closed when a policy or any role/context mapping is missing.
    'enforce_return_discount_posting_account_mappings' => $isProduction ? true : env(
        'ACCOUNTING_ENFORCE_RETURN_DISCOUNT_POSTING_ACCOUNT_MAPPINGS',
        true,
    ),

    // CostAllocation persists whole-unit BIGINT costs while the GL source is
    // now DECIMAL(20,2). Do not silently round fractional source costs until
    // the Chief Accountant approves the allocation scale/rounding policy.
    'enforce_costing_integer_allocation_evidence' => $isProduction ? true : env(
        'ACCOUNTING_ENFORCE_COSTING_INTEGER_ALLOCATION_EVIDENCE',
        true,
    ),

    // Period close is irreversible operationally. Deployment therefore
    // requires immutable owner signoff bound to the reviewed readiness
    // snapshot; the legacy suite opts out explicitly until its fixtures carry
    // controlled reconciliation and signoff evidence.
    'enforce_period_close_signoff' => $isProduction ? true : env('ACCOUNTING_ENFORCE_PERIOD_CLOSE_SIGNOFF', true),

    // PeriodClosingService still contains a diagnostic legacy calculator whose
    // account classification uses historical prefixes and fixed closing
    // targets. No owner-approved, effective-dated close mapping resolver is
    // wired yet. Keep close posting unavailable in deployments until that
    // resolver and the TT99/transition policy are supplied; the legacy suite
    // opts out explicitly so its decimal/preview tests remain diagnostic only.
    'enforce_period_close_account_mappings' => $isProduction ? true : env(
        'ACCOUNTING_ENFORCE_PERIOD_CLOSE_ACCOUNT_MAPPINGS',
        true,
    ),

    // This repository intentionally ships no provider driver, signing code,
    // or secret resolver. Keep the adapter transport closed until a separately
    // reviewed deployment explicitly enables a concrete adapter.
    'enable_einvoice_provider_adapter' => env('ACCOUNTING_ENABLE_EINVOICE_PROVIDER_ADAPTER', false),
];
