<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\BankPayment;
use App\Models\BankReceipt;
use App\Models\CashPayment;
use App\Models\CashReceipt;
use App\Models\ChartOfAccount;
use App\Models\Company;
use App\Models\Customer;
use App\Models\User;
use App\Services\BankPaymentService;
use App\Services\BankReceiptService;
use App\Services\CashPaymentService;
use App\Services\CashReceiptService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CashBankTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Company $companyA;

    private Company $companyB;

    private User $userA;

    private BankAccount $bankA;

    private BankAccount $bankB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->companyA = Company::create(['name' => 'Tenant A', 'tax_code' => 'TENANT-A']);
        $this->companyB = Company::create(['name' => 'Tenant B', 'tax_code' => 'TENANT-B']);
        $this->userA = User::factory()->create();
        $this->configureAccountingTenant($this->userA, $this->companyA);
        Sanctum::actingAs($this->userA);

        foreach (['1111', '1121', '131', '331'] as $code) {
            ChartOfAccount::create([
                'company_id' => $this->companyA->id,
                'code' => $code,
                'name' => "Account $code",
                'type' => 'asset',
                'nature' => 'debit',
                'level' => 1,
                'is_parent' => false,
            ]);
        }

        $this->bankA = BankAccount::create([
            'company_id' => $this->companyA->id,
            'account_number' => 'A-001',
            'bank_name' => 'Bank A',
        ]);
        $this->bankB = BankAccount::withoutGlobalScopes()->create([
            'company_id' => $this->companyB->id,
            'account_number' => 'B-001',
            'bank_name' => 'Bank B',
        ]);
    }

    public function test_lists_show_and_next_codes_cannot_be_switched_by_client_company_id(): void
    {
        $documents = $this->createTenantDocuments();

        foreach ($documents as $document) {
            $response = $this->getJson($document['endpoint'].'?company_id='.$this->companyB->id);
            $response->assertOk();
            $ids = collect($response->json('data'))->pluck('id');
            $this->assertTrue($ids->contains($document['a']->id));
            $this->assertFalse($ids->contains($document['b']->id));

            $this->getJson($document['endpoint'].'/'.$document['b']->id)->assertNotFound();
        }

        $this->getJson('/api/v1/bank/accounts?company_id='.$this->companyB->id)
            ->assertOk()
            ->assertJsonFragment(['account_number' => 'A-001'])
            ->assertJsonMissing(['account_number' => 'B-001']);
        $this->getJson('/api/v1/bank/accounts/'.$this->bankB->id)->assertNotFound();

        $this->getJson('/api/v1/cash/receipts/next-code?company_id='.$this->companyB->id)
            ->assertOk()
            ->assertJsonPath('next_code', 'PT00002');
        $this->getJson('/api/v1/bank/receipts/next-code?company_id='.$this->companyB->id)
            ->assertOk()
            ->assertJsonPath('next_code', 'BC-'.now()->format('Y').'-0002');
    }

    public function test_create_overwrites_malicious_company_and_rejects_foreign_resources(): void
    {
        $cash = $this->postJson('/api/v1/cash/receipts', [
            'company_id' => $this->companyB->id,
            'voucher_number' => 'PT-MALICIOUS',
            'voucher_date' => '2026-08-21',
            'posting_date' => '2026-08-21',
            'lines' => [['debit_account' => '1111', 'credit_account' => '131', 'amount' => 100]],
        ]);
        $cash->assertCreated();
        $this->assertDatabaseHas('cash_receipts', [
            'voucher_number' => 'PT-MALICIOUS',
            'company_id' => $this->companyA->id,
        ]);

        $this->postJson('/api/v1/bank/receipts', [
            'company_id' => $this->companyB->id,
            'bank_account_id' => $this->bankB->id,
            'voucher_number' => 'BR-FOREIGN-BANK',
            'voucher_date' => '2026-08-21',
            'lines' => [['credit_account' => '131', 'amount' => 100]],
        ])->assertUnprocessable()->assertJsonValidationErrors('bank_account_id');

        $foreignCustomer = Customer::withoutGlobalScopes()->create([
            'company_id' => $this->companyB->id,
            'code' => 'CUS-B-ONLY',
            'name' => 'Foreign customer',
        ]);
        $this->postJson('/api/v1/bank/payments', [
            'company_id' => $this->companyB->id,
            'bank_account_id' => $this->bankA->id,
            'contact_id' => $foreignCustomer->id,
            'voucher_number' => 'BP-FOREIGN-CONTACT',
            'voucher_date' => '2026-08-21',
            'lines' => [['debit_account' => '331', 'amount' => 100]],
        ])->assertUnprocessable()->assertJsonValidationErrors('contact_id');
    }

    public function test_foreign_route_ids_cannot_be_mutated_by_any_cash_or_bank_lifecycle_action(): void
    {
        foreach ($this->createTenantDocuments() as $document) {
            $foreign = $document['b'];
            $originalNumber = $foreign->voucher_number;

            foreach (['post', 'void', 'unpost', 'duplicate'] as $action) {
                $response = $this->postJson($document['endpoint'].'/'.$foreign->id.'/'.$action);
                $this->assertGreaterThanOrEqual(400, $response->status(), "$action unexpectedly accepted a foreign ID");
            }

            $update = $this->putJson($document['endpoint'].'/'.$foreign->id, ['description' => 'tenant attack']);
            $this->assertGreaterThanOrEqual(400, $update->status());
            $delete = $this->deleteJson($document['endpoint'].'/'.$foreign->id);
            $this->assertGreaterThanOrEqual(400, $delete->status());

            $fresh = $foreign::withoutGlobalScopes()->find($foreign->id);
            $this->assertNotNull($fresh);
            $this->assertSame($originalNumber, $fresh->voucher_number);
        }

        $this->putJson('/api/v1/bank/accounts/'.$this->bankB->id, ['bank_name' => 'Attacked'])
            ->assertNotFound();
        $this->deleteJson('/api/v1/bank/accounts/'.$this->bankB->id)->assertNotFound();
        $this->assertSame('Bank B', BankAccount::withoutGlobalScopes()->find($this->bankB->id)->bank_name);
    }

    public function test_foreign_and_unsupported_voucher_references_and_cash_line_contacts_are_rejected(): void
    {
        $foreignDocument = $this->createTenantDocuments()[0]['b'];
        $foreignCustomer = Customer::withoutGlobalScopes()->create([
            'company_id' => $this->companyB->id,
            'code' => 'CUS-B-LINE',
            'name' => 'Foreign line customer',
        ]);
        $reference = [[
            'target_type' => CashReceipt::class,
            'target_id' => $foreignDocument->id,
        ]];

        $cashPayload = [
            'voucher_number' => 'PT-CROSS-REFERENCE',
            'voucher_date' => '2026-08-21',
            'posting_date' => '2026-08-21',
            'referenced_vouchers' => $reference,
            'lines' => [[
                'credit_account' => '131',
                'amount' => 100,
                'line_contact_id' => $foreignCustomer->id,
            ]],
        ];
        $this->postJson('/api/v1/cash/receipts', $cashPayload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'referenced_vouchers.0',
                'lines.0.line_contact_id',
            ]);

        $bankPayload = [
            'bank_account_id' => $this->bankA->id,
            'voucher_number' => 'BR-CROSS-REFERENCE',
            'voucher_date' => '2026-08-21',
            'referenced_vouchers' => $reference,
            'lines' => [['credit_account' => '131', 'amount' => 100]],
        ];
        $this->postJson('/api/v1/bank/receipts', $bankPayload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('referenced_vouchers.0');

        data_set($cashPayload, 'referenced_vouchers.0.target_type', 'App\\Models\\UnregisteredVoucher');
        data_set($cashPayload, 'referenced_vouchers.0.target_id', 999);
        unset($cashPayload['lines'][0]['line_contact_id']);
        $this->postJson('/api/v1/cash/receipts', $cashPayload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('referenced_vouchers.0');
    }

    public function test_unassigned_authenticated_user_is_denied_instead_of_falling_back_to_company_one(): void
    {
        $unassigned = User::factory()->create(['company_id' => null]);
        Sanctum::actingAs($unassigned);

        $this->getJson('/api/v1/cash/receipts')->assertForbidden();
        $this->getJson('/api/v1/bank/accounts')->assertForbidden();
        $this->postJson('/api/v1/cash/payments', [])->assertForbidden();
        $this->postJson('/api/v1/bank/receipts', [])->assertForbidden();
    }

    public function test_direct_cash_bank_detail_services_fail_closed_without_tenant_context(): void
    {
        $documents = $this->createTenantDocuments();
        Auth::forgetGuards();

        $lookups = [
            [CashReceiptService::class, $documents[0]['b']->id],
            [CashPaymentService::class, $documents[1]['b']->id],
            [BankReceiptService::class, $documents[2]['b']->id],
            [BankPaymentService::class, $documents[3]['b']->id],
        ];

        foreach ($lookups as [$serviceClass, $foreignId]) {
            try {
                app($serviceClass)->getById($foreignId);
                $this->fail("{$serviceClass} must require an authenticated tenant for detail reads.");
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('company_id', $exception->errors());
            }
        }
    }

    public function test_direct_cash_bank_lifecycle_services_cannot_mutate_foreign_resources(): void
    {
        foreach ($this->createTenantDocuments() as $document) {
            $model = $document['b']::class;
            $foreignId = $document['b']->id;
            $originalNumber = $document['b']->voucher_number;

            $updateRejected = false;
            try {
                app($this->serviceForModel($model))->update($foreignId, ['description' => 'direct tenant attack']);
            } catch (\Throwable) {
                $updateRejected = true;
            }
            $this->assertTrue($updateRejected, "{$model} direct update must reject a foreign resource.");
            $this->assertSame($originalNumber, $model::withoutGlobalScopes()->findOrFail($foreignId)->voucher_number);

            $countBeforeDuplicate = $model::withoutGlobalScopes()->where('company_id', $this->companyB->id)->count();
            $duplicateRejected = false;
            try {
                app($this->serviceForModel($model))->duplicate($foreignId);
            } catch (\Throwable) {
                $duplicateRejected = true;
            }
            $this->assertTrue($duplicateRejected, "{$model} direct duplicate must reject a foreign resource.");
            $this->assertSame($countBeforeDuplicate, $model::withoutGlobalScopes()->where('company_id', $this->companyB->id)->count());

            $deleteRejected = false;
            try {
                app($this->serviceForModel($model))->delete($foreignId);
            } catch (\Throwable) {
                $deleteRejected = true;
            }
            $this->assertTrue($deleteRejected, "{$model} direct delete must reject a foreign resource.");
            $this->assertNotNull($model::withoutGlobalScopes()->find($foreignId));
        }
    }

    private function serviceForModel(string $model): string
    {
        return match ($model) {
            CashReceipt::class => CashReceiptService::class,
            CashPayment::class => CashPaymentService::class,
            BankReceipt::class => BankReceiptService::class,
            BankPayment::class => BankPaymentService::class,
            default => throw new \InvalidArgumentException("Unsupported cash/bank model: {$model}"),
        };
    }

    /** @return array<int, array{endpoint: string, a: object, b: object}> */
    private function createTenantDocuments(): array
    {
        return [
            $this->documentPair(CashReceipt::class, '/api/v1/cash/receipts', 'PT00001', 'PT99999'),
            $this->documentPair(CashPayment::class, '/api/v1/cash/payments', 'PC-A-001', 'PC-B-999'),
            $this->documentPair(BankReceipt::class, '/api/v1/bank/receipts', 'BC-'.now()->format('Y').'-0001', 'BC-'.now()->format('Y').'-9999', true),
            $this->documentPair(BankPayment::class, '/api/v1/bank/payments', 'UNC-A-001', 'UNC-B-999', true),
        ];
    }

    /** @return array{endpoint: string, a: object, b: object} */
    private function documentPair(string $model, string $endpoint, string $numberA, string $numberB, bool $bank = false): array
    {
        $common = [
            'voucher_date' => '2026-08-21',
            'posting_date' => '2026-08-21',
            'status' => 'draft',
            'is_posted' => false,
        ];
        $amountField = $bank ? ['amount' => 100] : ['total_amount' => 100];

        $a = $model::withoutGlobalScopes()->create([
            ...$common,
            ...$amountField,
            'company_id' => $this->companyA->id,
            'bank_account_id' => $bank ? $this->bankA->id : null,
            'voucher_number' => $numberA,
        ]);
        $b = $model::withoutGlobalScopes()->create([
            ...$common,
            ...$amountField,
            'company_id' => $this->companyB->id,
            'bank_account_id' => $bank ? $this->bankB->id : null,
            'voucher_number' => $numberB,
        ]);

        return compact('endpoint', 'a', 'b');
    }
}
