<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\BankPayment;
use App\Models\BankReceipt;
use App\Models\CashPayment;
use App\Models\CashReceipt;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\Period;
use App\Services\BankPaymentService;
use App\Services\BankReceiptService;
use App\Services\CashPaymentService;
use App\Services\CashReceiptService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

/**
 * Runs against source services to cover non-HTTP callers as well as the API.
 */
class CashBankVoucherClosedPeriodMutationGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function grantGlReportPermissionsToLegacyActors(): bool
    {
        return false;
    }

    public function test_closed_period_blocks_every_cash_and_bank_source_mutation_path(): void
    {
        CarbonImmutable::setTestNow('2026-08-15 10:00:00');
        try {
            $company = $this->company('Closed cash-bank tenant', 'CASH-BANK-CLOSED');
            $this->period($company, true);

            foreach ($this->voucherTypes() as $type) {
                $service = app($type['service']);
                $voucher = $this->source($type['model'], $company, 'CLOSED-'.$type['key']);

                $this->assertClosed(fn () => $service->create($this->payload($type['key'], $company, 'CREATE-'.$type['key'])));
                $this->assertClosed(fn () => $service->update($voucher->id, ['description' => 'must not persist']));
                $this->assertClosed(fn () => $service->delete($voucher->id));
                $this->assertClosed(fn () => $service->post($voucher->id));
                $this->assertClosed(fn () => $service->void($voucher->id));
                $this->assertClosed(fn () => $service->unpost($voucher->id));
                $this->assertClosed(fn () => $service->duplicate($voucher->id));

                $this->assertSame('2026-08-15', $voucher->fresh()->posting_date->toDateString());
                $this->assertDatabaseHas($type['table'], ['id' => $voucher->id]);
            }
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_foreign_tenant_close_does_not_block_open_tenant_cash_and_bank_vouchers(): void
    {
        $openCompany = $this->company('Open cash-bank tenant', 'CASH-BANK-OPEN');
        $foreignClosedCompany = $this->company('Foreign closed tenant', 'CASH-BANK-FOREIGN');
        $this->period($foreignClosedCompany, true);

        foreach ($this->voucherTypes() as $type) {
            $voucher = app($type['service'])->create($this->payload($type['key'], $openCompany, 'OPEN-'.$type['key']));

            $this->assertSame($openCompany->id, $voucher->company_id);
            $this->assertSame('2026-08-15', $voucher->posting_date->toDateString());
        }
    }

    /** @return array<int, array{key: string, model: class-string<Model>, service: class-string, table: string}> */
    private function voucherTypes(): array
    {
        return [
            ['key' => 'cash_receipt', 'model' => CashReceipt::class, 'service' => CashReceiptService::class, 'table' => 'cash_receipts'],
            ['key' => 'cash_payment', 'model' => CashPayment::class, 'service' => CashPaymentService::class, 'table' => 'cash_payments'],
            ['key' => 'bank_receipt', 'model' => BankReceipt::class, 'service' => BankReceiptService::class, 'table' => 'bank_receipts'],
            ['key' => 'bank_payment', 'model' => BankPayment::class, 'service' => BankPaymentService::class, 'table' => 'bank_payments'],
        ];
    }

    private function company(string $name, string $taxCode): Company
    {
        return Company::create(['name' => $name, 'tax_code' => $taxCode]);
    }

    private function period(Company $company, bool $closed): Period
    {
        $fiscal = FiscalYear::withoutGlobalScope('company')->create([
            'company_id' => $company->id,
            'year' => 2026,
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'status' => 'open',
        ]);

        return Period::create([
            'fiscal_year_id' => $fiscal->id,
            'period' => 8,
            'period_number' => 8,
            'name' => 'August 2026',
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-31',
            'status' => $closed ? 'closed' : 'open',
            'is_closed' => $closed,
        ]);
    }

    private function source(string $model, Company $company, string $number): Model
    {
        $attributes = [
            'company_id' => $company->id,
            'voucher_number' => $number,
            'voucher_date' => '2026-08-15',
            'posting_date' => '2026-08-15',
            'status' => 'draft',
            'is_posted' => false,
        ];

        if (in_array($model, [CashReceipt::class, CashPayment::class], true)) {
            $attributes['total_amount'] = 100;
            $attributes[$model === CashReceipt::class ? 'payer_name' : 'receiver_name'] = 'Counterparty';
        } else {
            $attributes['bank_account_id'] = $this->bankAccount($company)->id;
            $attributes['amount'] = 100;
            $attributes[$model === BankReceipt::class ? 'payer_name' : 'payee_name'] = 'Counterparty';
        }

        return $model::withoutGlobalScope('company')->create($attributes);
    }

    private function payload(string $type, Company $company, string $number): array
    {
        $payload = [
            'company_id' => $company->id,
            'voucher_number' => $number,
            'voucher_date' => '2026-08-15',
            'posting_date' => '2026-08-15',
            'lines' => [['amount' => 100, 'description' => 'Guard test']],
        ];

        return match ($type) {
            'cash_receipt' => [...$payload, 'payer_name' => 'Counterparty'],
            'cash_payment' => [...$payload, 'receiver_name' => 'Counterparty'],
            'bank_receipt' => [...$payload, 'bank_account_id' => $this->bankAccount($company)->id, 'payer_name' => 'Counterparty'],
            'bank_payment' => [...$payload, 'bank_account_id' => $this->bankAccount($company)->id, 'payee_name' => 'Counterparty'],
        };
    }

    private function bankAccount(Company $company): BankAccount
    {
        return BankAccount::withoutGlobalScope('company')->firstOrCreate(
            ['company_id' => $company->id, 'account_number' => '001-'.$company->id],
            ['bank_name' => 'Test Bank', 'currency' => 'VND', 'is_active' => true],
        );
    }

    private function assertClosed(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Expected a closed-period conflict.');
        } catch (ConflictHttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
            $this->assertStringContainsString('kỳ kế toán đã khóa', $exception->getMessage());
        }
    }
}
