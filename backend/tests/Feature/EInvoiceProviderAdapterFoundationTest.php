<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\EInvoiceDocument;
use App\Models\EInvoiceProviderConfiguration;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Services\EInvoiceLifecycleService;
use App\Services\EInvoiceProviderAdapterReadinessService;
use App\Services\EInvoiceProviderConfigurationLifecycleService;
use App\Services\EInvoiceProviderDispatchService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

class EInvoiceProviderAdapterFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_configuration_is_maker_checker_controlled_and_readiness_is_fail_closed_by_default(): void
    {
        $company = Company::query()->firstOrFail();
        $maker = User::factory()->create(['company_id' => $company->id]);
        $checker = User::factory()->create(['company_id' => $company->id]);
        $lifecycle = app(EInvoiceProviderConfigurationLifecycleService::class);
        $draft = $lifecycle->createDraft($maker, $this->config($company->id));

        $this->expectException(AuthorizationException::class);
        $lifecycle->approve($maker, $draft);
    }

    public function test_approved_reference_only_configuration_stays_non_transport_ready_until_explicit_enablement(): void
    {
        $company = Company::query()->firstOrFail();
        $maker = User::factory()->create(['company_id' => $company->id]);
        $checker = User::factory()->create(['company_id' => $company->id]);
        $lifecycle = app(EInvoiceProviderConfigurationLifecycleService::class);
        $draft = $lifecycle->createDraft($maker, $this->config($company->id));
        $approved = $lifecycle->approve($checker, $draft);

        $readiness = app(EInvoiceProviderAdapterReadinessService::class)->forCompany($company->id);
        $this->assertFalse($readiness['ready']);
        $this->assertSame('provider_adapter_transport_disabled', $readiness['reason']);
        $this->assertSame($approved->id, $readiness['provider_configuration_id']);
        $this->assertNull($approved->toArray()['secret_reference'] ?? null);
        $this->assertDatabaseHas('e_invoice_provider_configurations', ['id' => $approved->id, 'status' => 'approved']);
    }

    public function test_provider_readiness_rejects_an_authenticated_foreign_company(): void
    {
        $company = Company::query()->firstOrFail();
        $foreign = Company::query()->create(['name' => 'Foreign provider tenant', 'tax_code' => 'EINV-FOREIGN']);
        $actor = User::factory()->create(['company_id' => $company->id]);
        $this->actingAs($actor);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(EInvoiceProviderAdapterReadinessService::class)->forCompany($foreign->id);
    }

    public function test_provider_dispatch_outcome_rejects_an_authenticated_foreign_company(): void
    {
        $company = Company::query()->firstOrFail();
        $actor = User::factory()->create(['company_id' => $company->id]);
        $this->actingAs($actor);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(EInvoiceProviderDispatchService::class)->recordOutcome($company->id + 1, $actor->id, 1, 'acknowledged');
    }

    public function test_raw_mutation_of_approved_configuration_is_not_transport_ready(): void
    {
        $company = Company::query()->firstOrFail();
        $maker = User::factory()->create(['company_id' => $company->id]);
        $checker = User::factory()->create(['company_id' => $company->id]);
        $lifecycle = app(EInvoiceProviderConfigurationLifecycleService::class);
        $approved = $lifecycle->approve($checker, $lifecycle->createDraft($maker, $this->config($company->id)));

        $updated = DB::table('e_invoice_provider_configurations')->where('id', $approved->id)->update([
            'capability_contract' => json_encode(['tampered' => true]),
        ]);
        $this->assertSame(1, $updated);
        config()->set('accounting.enable_einvoice_provider_adapter', true);

        $fresh = EInvoiceProviderConfiguration::withoutGlobalScope('company')->findOrFail($approved->id);
        $this->assertSame(['tampered' => true], $fresh->capability_contract);
        $this->assertFalse(app(EInvoiceProviderConfigurationLifecycleService::class)->contractHashMatches($fresh));

        $readiness = app(EInvoiceProviderAdapterReadinessService::class)->forCompany($company->id);
        $this->assertFalse($readiness['ready']);
        $this->assertSame('approved_provider_configuration_integrity_invalid', $readiness['reason']);
        $this->assertFalse($readiness['transport_enabled']);
    }

    public function test_idempotent_outbox_and_append_only_sanitized_outcome_evidence_have_sod(): void
    {
        config()->set('accounting.enable_einvoice_provider_adapter', true);
        $company = Company::query()->firstOrFail();
        $maker = User::factory()->create(['company_id' => $company->id]);
        $issuer = User::factory()->create(['company_id' => $company->id]);
        $preparer = User::factory()->create(['company_id' => $company->id]);
        $checker = User::factory()->create(['company_id' => $company->id]);
        $config = app(EInvoiceProviderConfigurationLifecycleService::class)->createDraft($maker, $this->config($company->id));
        app(EInvoiceProviderConfigurationLifecycleService::class)->approve($checker, $config);
        $invoice = $this->invoice($company->id, $maker->id);
        $draft = app(EInvoiceLifecycleService::class)->record($company->id, $maker->id, $this->payload($invoice->id, 'draft'));
        $issued = app(EInvoiceLifecycleService::class)->record($company->id, $issuer->id, $this->payload($invoice->id, 'issued', $draft->id));
        $service = app(EInvoiceProviderDispatchService::class);
        $first = $service->prepare($company->id, $preparer->id, $issued);
        $this->assertSame($first->id, $service->prepare($company->id, $preparer->id, $issued)->id);
        $this->expectException(AuthorizationException::class);
        $service->recordOutcome($company->id, $preparer->id, $first->id, 'acknowledged', 'OK', hash('sha256', 'safe-result'));
    }

    public function test_retry_requires_timestamp_and_outcome_evidence_is_append_only(): void
    {
        config()->set('accounting.enable_einvoice_provider_adapter', true);
        [$company, $preparer, $checker, $dispatch] = $this->preparedDispatch();
        try { app(EInvoiceProviderDispatchService::class)->recordOutcome($company->id, $checker->id, $dispatch->id, 'retryable_failure', 'TIMEOUT'); $this->fail('Retry time must be required.'); }
        catch (LogicException) { $this->assertTrue(true); }
        $updated = app(EInvoiceProviderDispatchService::class)->recordOutcome($company->id, $checker->id, $dispatch->id, 'retryable_failure', 'TIMEOUT', hash('sha256', 'sanitized'), now()->addMinute()->toImmutable());
        $this->assertSame('retry_pending', $updated->state);
        $this->assertDatabaseHas('e_invoice_provider_dispatch_events', ['e_invoice_provider_dispatch_id' => $dispatch->id, 'event_type' => 'retry_scheduled']);
        $eventId = DB::table('e_invoice_provider_dispatch_events')->where('e_invoice_provider_dispatch_id', $dispatch->id)->value('id');
        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('e_invoice_provider_dispatch_events')->where('id', $eventId)->update(['event_type' => 'tampered']);
    }

    public function test_tampered_payload_and_malformed_outcome_hash_fail_closed(): void
    {
        config()->set('accounting.enable_einvoice_provider_adapter', true);
        [$company, $preparer, $checker, $dispatch] = $this->preparedDispatch();
        $source = EInvoiceDocument::withoutGlobalScope('company')->findOrFail($dispatch->e_invoice_document_id);
        $malformed = EInvoiceDocument::withoutGlobalScope('company')->create([
            'company_id' => $company->id,
            'accounting_document_type' => $source->accounting_document_type,
            'accounting_document_id' => $source->accounting_document_id,
            'lifecycle_status' => 'issued',
            'provider_name' => 'malformed-test-provider',
            'provider_document_id' => 'malformed-'.uniqid(),
            'document_reference' => 'MALFORMED-EVIDENCE',
            'payload_hash' => hash('sha256', '{"original":true}'),
            'payload_snapshot' => ['tampered' => true],
            'recorded_by' => $source->recorded_by,
            'occurred_at' => now(),
            'metadata' => ['test_only' => true],
        ]);

        $this->expectException(LogicException::class);
        app(EInvoiceProviderDispatchService::class)->prepare(
            $company->id,
            $preparer->id,
            $malformed,
        );
    }

    public function test_malformed_provider_result_hash_fails_closed(): void
    {
        config()->set('accounting.enable_einvoice_provider_adapter', true);
        [$company, , $checker, $dispatch] = $this->preparedDispatch();

        $this->expectException(LogicException::class);
        app(EInvoiceProviderDispatchService::class)->recordOutcome(
            $company->id,
            $checker->id,
            $dispatch->id,
            'retryable_failure',
            'TIMEOUT',
            'not-a-sha256-digest',
            now()->addMinute()->toImmutable(),
        );
    }

    /** @return array{0:Company,1:User,2:User,3:\App\Models\EInvoiceProviderDispatch} */
    private function preparedDispatch(): array
    {
        $company = Company::query()->firstOrFail(); $maker = User::factory()->create(['company_id' => $company->id]); $issuer = User::factory()->create(['company_id' => $company->id]); $preparer = User::factory()->create(['company_id' => $company->id]); $checker = User::factory()->create(['company_id' => $company->id]);
        $config = app(EInvoiceProviderConfigurationLifecycleService::class)->createDraft($maker, $this->config($company->id)); app(EInvoiceProviderConfigurationLifecycleService::class)->approve($checker, $config);
        $invoice = $this->invoice($company->id, $maker->id); $draft = app(EInvoiceLifecycleService::class)->record($company->id, $maker->id, $this->payload($invoice->id, 'draft')); $issued = app(EInvoiceLifecycleService::class)->record($company->id, $issuer->id, $this->payload($invoice->id, 'issued', $draft->id));
        return [$company, $preparer, $checker, app(EInvoiceProviderDispatchService::class)->prepare($company->id, $preparer->id, $issued)];
    }
    private function config(int $companyId): array { return ['company_id' => $companyId, 'provider_code' => 'adapter-contract-test', 'secret_reference' => 'vault:tenant/einvoice/api', 'endpoint_reference' => 'provider-config:adapter-contract-test', 'callback_secret_reference' => 'vault:tenant/einvoice/callback', 'effective_from' => '2026-01-01', 'effective_to' => '2026-12-31', 'capability_contract' => ['contract_version' => 'v1', 'outbound_transport_not_implemented' => true], 'regulatory_dependencies' => []]; }
    private function invoice(int $companyId, int $createdBy): SalesInvoice { $customer = DB::table('customers')->insertGetId(['company_id' => $companyId, 'code' => 'P-'.uniqid(), 'name' => 'Provider Customer', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]); return SalesInvoice::withoutGlobalScopes()->create(['company_id' => $companyId, 'customer_id' => $customer, 'invoice_number' => 'P-'.uniqid(), 'invoice_date' => '2026-08-22', 'accounting_date' => '2026-08-22', 'total_amount' => 1, 'status' => 'draft', 'created_by' => $createdBy]); }
    private function payload(int $invoiceId, string $status, ?int $previous = null): array { return array_filter(['accounting_document_type' => SalesInvoice::class, 'accounting_document_id' => $invoiceId, 'lifecycle_status' => $status, 'provider_name' => 'evidence-only', 'provider_document_id' => $status.'-'.uniqid(), 'document_reference' => 'P-EVIDENCE', 'payload_snapshot' => ['document' => 'P-EVIDENCE'], 'supersedes_einvoice_document_id' => $previous], fn ($value) => $value !== null); }
}
