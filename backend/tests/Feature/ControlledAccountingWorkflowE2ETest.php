<?php

namespace Tests\Feature;

use App\Exceptions\AccountingAccountMappingUnavailableException;
use App\Exceptions\AccountingPolicyUnavailableException;
use App\Models\AccountingDimensionDefinition;
use App\Models\AccountingDimensionValue;
use App\Models\AuditLog;
use App\Models\ChartOfAccount;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\Period;
use App\Models\Supplier;
use App\Models\User;
use App\Services\AccountingDimensionRequirementService;
use App\Services\AccountingDocumentDimensionAssignmentService;
use App\Services\AccountingPolicyLifecycleService;
use App\Services\ApprovedAccountMappingLifecycleService;
use App\Services\PeriodClosingService;
use App\Services\PurchaseInvoiceAccountMappingPostingGate;
use App\Services\PurchaseInvoiceService;
use App\Support\DecimalMoney;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Controlled production-path evidence for one high-risk source document.
 *
 * This suite deliberately enables every deployment gate. It establishes that
 * a purchase invoice cannot skip policy, analytic dimensions, account mapping
 * and owner-approved mappings; it also proves that a blocked period-close
 * does not fabricate a closing voucher. Per the two-role operating policy,
 * routine purchase posting does not require an admin approval step. It is
 * not a claim of statutory completion.
 */
class ControlledAccountingWorkflowE2ETest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $maker;

    private User $owner;

    private User $poster;

    private AccountingDimensionDefinition $costCenter;

    private AccountingDimensionValue $costCenterValue;

    protected function setUp(): void
    {
        parent::setUp();
        foreach ([
            'enforce_purchase_invoice_posting_policy',
            'enforce_purchase_invoice_posting_dimensions',
            'enforce_purchase_invoice_posting_account_mappings',
        ] as $flag) {
            config()->set("accounting.{$flag}", true);
        }
        config()->set('accounting.enforce_period_close_signoff', true);

        $this->company = Company::create(['name' => 'Controlled E2E Co', 'tax_code' => 'CTRL-E2E-2026']);
        $this->maker = User::factory()->create(['company_id' => $this->company->id]);
        $this->owner = User::factory()->create(['company_id' => $this->company->id]);
        $this->poster = User::factory()->create(['company_id' => $this->company->id]);
        $accountantRole = Role::findOrCreate('accountant', 'web');
        $adminRole = Role::findOrCreate('admin', 'web');
        $this->maker->assignRole($accountantRole);
        $this->owner->assignRole($adminRole);
        $this->poster->assignRole($accountantRole);
        $this->configureAccountingTenant($this->maker, $this->company);

        foreach ([
            ['1561', 'Hàng hóa nguồn', 'asset', 'debit'], ['331', 'Phải trả nguồn', 'liability', 'credit'],
            ['1331', 'VAT đầu vào nguồn', 'asset', 'debit'], ['1562', 'Hàng hóa được phê duyệt', 'asset', 'debit'],
            ['3312', 'Phải trả được phê duyệt', 'liability', 'credit'], ['1332', 'VAT được phê duyệt', 'asset', 'debit'],
            ['911', 'Xác định kết quả', 'equity', 'amphibious'], ['4212', 'Lợi nhuận sau thuế chưa phân phối', 'equity', 'amphibious'],
        ] as [$code, $name, $type, $nature]) {
            ChartOfAccount::create(['company_id' => $this->company->id, 'code' => $code, 'name' => $name, 'type' => $type, 'nature' => $nature, 'level' => 1, 'is_parent' => false, 'is_active' => true]);
        }

        $this->costCenter = AccountingDimensionDefinition::create(['company_id' => $this->company->id, 'code' => 'cost_center', 'name' => 'Trung tâm chi phí', 'status' => 'active', 'effective_from' => '2026-01-01', 'effective_to' => '2026-12-31']);
        $this->costCenterValue = AccountingDimensionValue::create(['company_id' => $this->company->id, 'accounting_dimension_definition_id' => $this->costCenter->id, 'code' => 'HQ', 'name' => 'Văn phòng chính', 'status' => 'active', 'effective_from' => '2026-01-01', 'effective_to' => '2026-12-31']);
    }

    public function test_full_controlled_purchase_path_posts_exactly_once_with_complete_audit_lineage(): void
    {
        $policy = $this->approvedPolicy();
        $this->approvedMappings($policy);
        $invoice = $this->invoice('E2E-FULL');
        app(AccountingDocumentDimensionAssignmentService::class)->savePurchaseInvoice($this->maker, $invoice->id, ['cost_center' => $this->costCenterValue->id]);

        $this->actingAs($this->poster);
        $posted = app(PurchaseInvoiceService::class)->post($invoice->id);

        $this->assertTrue((bool) $posted->is_posted);
        $this->assertSame(['3312', '1562', '1332'], $posted->journalEntry->lines()->orderBy('id')->pluck('account_code')->all());
        // DECIMAL zero values are returned as numeric 0 by SQLite but as the
        // truthy string "0.00" by MariaDB. Select the non-zero side by value
        // instead of PHP truthiness so this evidence is database-portable.
        $this->assertSame(['110.00', '100.00', '10.00'], $posted->journalEntry->lines()->orderBy('id')->get()->map(function ($line): string {
            $debit = $line->getRawOriginal('debit_amount');
            $credit = $line->getRawOriginal('credit_amount');

            return DecimalMoney::normalize(
                DecimalMoney::compare($debit, DecimalMoney::ZERO) !== 0 ? $debit : $credit,
            );
        })->all());

        $audits = AuditLog::withoutGlobalScope('company')->where('model_id', $posted->id)->whereIn('action', [
            'purchase_invoice.policy_applied', 'purchase_invoice.dimensions_applied', 'purchase_invoice.account_mappings_applied',
        ])->get()->keyBy('action');
        $this->assertCount(3, $audits);
        $this->assertSame($policy->contract_hash, data_get($audits['purchase_invoice.policy_applied']->metadata, 'accounting_policy.contract_hash'));
        $this->assertSame($policy->contract_hash, data_get($audits['purchase_invoice.dimensions_applied']->metadata, 'dimensions.policy_contract_hash'));
        $this->assertSame($policy->contract_hash, data_get($audits['purchase_invoice.account_mappings_applied']->metadata, 'account_mappings.policy_contract_hash'));
        foreach ($audits as $audit) {
            $this->assertSame($posted->journalEntry->id, (int) $audit->metadata['journal_entry_id']);
        }
    }

    public function test_each_missing_posting_prerequisite_fails_atomically_without_a_journal(): void
    {
        $this->actingAs($this->poster);
        $invoice = $this->invoice('E2E-NO-POLICY');
        $this->assertPostingBlocked($invoice->id, AccountingPolicyUnavailableException::class);

        $policy = $this->approvedPolicy();
        $invoice = $this->invoice('E2E-NO-DIMENSION');
        $this->assertPostingBlocked($invoice->id, ValidationException::class, 'dimensions');

        app(AccountingDocumentDimensionAssignmentService::class)->savePurchaseInvoice($this->maker, $invoice->id, ['cost_center' => $this->costCenterValue->id]);
        $this->assertPostingBlocked($invoice->id, AccountingAccountMappingUnavailableException::class);

        $this->approvedMappings($policy);
        $this->actingAs($this->poster);
        $posted = app(PurchaseInvoiceService::class)->post($invoice->id);
        $this->assertTrue((bool) $posted->is_posted);
    }

    public function test_period_close_refuses_without_current_controlled_readiness_and_signoff_and_creates_no_closing_journal(): void
    {
        $period = Period::create(['fiscal_year_id' => FiscalYear::withoutGlobalScope('company')->where('company_id', $this->company->id)->where('year', 2026)->sole()->id, 'period' => 8, 'period_number' => 8, 'name' => 'August 2026', 'start_date' => '2026-08-01', 'end_date' => '2026-08-31', 'status' => 'open', 'is_closed' => false]);
        $this->actingAs($this->poster);

        try {
            app(PeriodClosingService::class)->executeForCompany($this->company->id, ['period_id' => $period->id, 'from_date' => '2026-08-01', 'to_date' => '2026-08-31', 'voucher_number' => 'E2E-BLOCKED-CLOSE', 'close_reason' => 'Đối chiếu cuối kỳ']);
            $this->fail('Period close must fail closed without current controlled readiness and immutable signoff.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('period_close_readiness', $exception->errors());
        }

        $this->assertDatabaseHas('period_close_readiness_snapshots', ['company_id' => $this->company->id, 'period_id' => $period->id, 'eligible_to_close' => false]);
        $this->assertDatabaseMissing('journal_entries', ['company_id' => $this->company->id, 'voucher_number' => 'E2E-BLOCKED-CLOSE']);
        $this->assertDatabaseHas('periods', ['id' => $period->id, 'is_closed' => false, 'status' => 'open']);
    }

    private function assertPostingBlocked(int $invoiceId, string $exceptionClass, ?string $validationKey = null): void
    {
        try {
            app(PurchaseInvoiceService::class)->post($invoiceId);
            $this->fail("{$exceptionClass} was expected.");
        } catch (\Throwable $exception) {
            $this->assertInstanceOf($exceptionClass, $exception);
            if ($validationKey !== null) {
                $this->assertInstanceOf(ValidationException::class, $exception);
                $this->assertArrayHasKey($validationKey, $exception->errors());
            }
        }
        $this->assertDatabaseHas('purchase_invoices', ['id' => $invoiceId, 'is_posted' => false]);
        $this->assertDatabaseMissing('journal_entries', ['source_document_id' => $invoiceId]);
    }

    private function approvedPolicy()
    {
        $year = FiscalYear::withoutGlobalScope('company')->where('company_id', $this->company->id)->where('year', 2026)->sole();
        $lifecycle = app(AccountingPolicyLifecycleService::class);
        $policy = $lifecycle->createDraft($this->maker, [
            'company_id' => $this->company->id, 'accounting_regime_profile_id' => $year->accountingRegimeProfile()->firstOrFail()->id,
            'policy_key' => 'posting.purchase_invoice', 'policy_version' => 'controlled-e2e-v1', 'effective_from' => '2026-01-01', 'effective_to' => '2026-12-31',
            'posting_rule_contract' => ['mapping_reference' => 'owner-approved-controlled-e2e'], 'required_dimensions' => ['cost_center'], 'regulatory_dependencies' => ['TT99/2025/TT-BTC'],
        ]);
        app(AccountingDimensionRequirementService::class)->replaceDraftRequirements($this->maker, $policy, [['accounting_dimension_definition_id' => $this->costCenter->id]]);

        return $lifecycle->approve($this->owner, $policy);
    }

    private function approvedMappings($policy): void
    {
        $mappings = [
            ['settlement_credit', '3312', ['entry' => 'settlement_credit', 'payment_method' => 'unpaid', 'payment_status' => 'draft', 'source_account_code' => '331']],
            ['purchase_debit', '1562', ['entry' => 'purchase_debit', 'voucher_type' => 'domestic_inward', 'source_account_code' => '1561']],
            ['input_vat', '1332', ['entry' => 'input_vat', 'source_account_code' => '1331']],
        ];
        $lifecycle = app(ApprovedAccountMappingLifecycleService::class);
        foreach ($mappings as [$role, $code, $context]) {
            $draft = $lifecycle->createDraft($this->maker, ['company_id' => $this->company->id, 'accounting_policy_version_id' => $policy->id, 'mapping_key' => PurchaseInvoiceAccountMappingPostingGate::MAPPING_KEY, 'mapping_context' => $context, 'account_role' => $role, 'account_code' => $code, 'effective_from' => '2026-01-01', 'effective_to' => '2026-12-31', 'regulatory_dependencies' => ['TT99/2025/TT-BTC']]);
            $lifecycle->approve($this->owner, $draft);
        }
    }

    private function invoice(string $number)
    {
        $supplier = Supplier::create(['company_id' => $this->company->id, 'code' => $number, 'name' => "Supplier {$number}"]);

        return app(PurchaseInvoiceService::class)->create(['company_id' => $this->company->id, 'supplier_id' => $supplier->id, 'invoice_number' => $number, 'invoice_date' => '2026-08-22', 'accounting_date' => '2026-08-22', 'due_date' => '2026-09-22', 'created_by' => $this->maker->id, 'updated_by' => $this->maker->id, 'lines' => [['description' => 'Controlled purchased goods', 'debit_account' => '1561', 'credit_account' => '331', 'quantity' => '1', 'unit_price' => '100.00', 'tax_rate' => '10', 'tax_account' => '1331']]]);
    }
}
