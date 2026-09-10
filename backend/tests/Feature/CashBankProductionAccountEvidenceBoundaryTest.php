<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\ChartOfAccount;
use App\Models\Company;
use App\Models\User;
use App\Services\BankPaymentService;
use App\Services\BankReceiptService;
use App\Services\CashPaymentService;
use App\Services\CashReceiptService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CashBankProductionAccountEvidenceBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private BankAccount $bankAccount;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'name' => 'Cash bank production boundary',
            'tax_code' => 'CB-PROD-BOUNDARY',
        ]);
        $actor = User::factory()->create();
        $this->configureAccountingTenant($actor, $this->company);
        Sanctum::actingAs($actor);

        foreach ([
            ['1111', 'Tiền mặt', 'asset', 'debit'],
            ['1121', 'Tiền gửi ngân hàng', 'asset', 'debit'],
            ['131', 'Phải thu khách hàng', 'asset', 'debit'],
            ['331', 'Phải trả người bán', 'liability', 'credit'],
        ] as [$code, $name, $type, $nature]) {
            ChartOfAccount::create([
                'company_id' => $this->company->id,
                'code' => $code,
                'name' => $name,
                'type' => $type,
                'nature' => $nature,
                'level' => 1,
                'is_parent' => false,
                'is_active' => true,
            ]);
        }

        $this->bankAccount = BankAccount::create([
            'company_id' => $this->company->id,
            'account_number' => 'CB-PROD-001',
            'bank_name' => 'Production boundary bank',
            'gl_account_code' => '1121',
        ]);
    }

    public function test_cash_services_reject_missing_accounts_on_production_create_and_update(): void
    {
        $receiptService = app(CashReceiptService::class);
        $paymentService = app(CashPaymentService::class);
        $receipt = $receiptService->create($this->cashPayload('CB-CASH-REC-BASE', '1111', '131'));
        $payment = $paymentService->create($this->cashPayload('CB-CASH-PAY-BASE', '331', '1111'));

        Config::set('app.env', 'production');
        try {
            $productionReceipt = $receiptService->create($this->cashPayload('CB-CASH-REC-EXPLICIT', '1111', '131'));
            $productionPayment = $paymentService->create($this->cashPayload('CB-CASH-PAY-EXPLICIT', '331', '1111'));
            $this->assertMissingAccounts(fn () => $receiptService->create($this->cashPayload('CB-CASH-REC-MISSING')));
            $this->assertMissingAccounts(fn () => $paymentService->create($this->cashPayload('CB-CASH-PAY-MISSING')));
            $this->assertMissingAccounts(fn () => $receiptService->update($receipt->id, ['lines' => [$this->line()]]));
            $this->assertMissingAccounts(fn () => $paymentService->update($payment->id, ['lines' => [$this->line()]]));
        } finally {
            Config::set('app.env', 'testing');
        }

        $this->assertDatabaseMissing('cash_receipts', ['voucher_number' => 'CB-CASH-REC-MISSING']);
        $this->assertDatabaseMissing('cash_payments', ['voucher_number' => 'CB-CASH-PAY-MISSING']);
        $this->assertDatabaseHas('cash_receipt_lines', ['cash_receipt_id' => $productionReceipt->id, 'debit_account' => '1111', 'credit_account' => '131']);
        $this->assertDatabaseHas('cash_payment_lines', ['cash_payment_id' => $productionPayment->id, 'debit_account' => '331', 'credit_account' => '1111']);
        $this->assertDatabaseHas('cash_receipt_lines', ['cash_receipt_id' => $receipt->id, 'debit_account' => '1111', 'credit_account' => '131']);
        $this->assertDatabaseHas('cash_payment_lines', ['cash_payment_id' => $payment->id, 'debit_account' => '331', 'credit_account' => '1111']);
    }

    public function test_bank_services_reject_missing_accounts_on_production_create_and_update(): void
    {
        $receiptService = app(BankReceiptService::class);
        $paymentService = app(BankPaymentService::class);
        $receipt = $receiptService->create($this->bankPayload('CB-BANK-REC-BASE', '1121', '131'));
        $payment = $paymentService->create($this->bankPayload('CB-BANK-PAY-BASE', '331', '1121'));

        Config::set('app.env', 'production');
        try {
            $productionReceipt = $receiptService->create($this->bankPayload('CB-BANK-REC-EXPLICIT', '1121', '131'));
            $productionPayment = $paymentService->create($this->bankPayload('CB-BANK-PAY-EXPLICIT', '331', '1121'));
            $this->assertMissingAccounts(fn () => $receiptService->create($this->bankPayload('CB-BANK-REC-MISSING')));
            $this->assertMissingAccounts(fn () => $paymentService->create($this->bankPayload('CB-BANK-PAY-MISSING')));
            $this->assertMissingAccounts(fn () => $receiptService->update($receipt->id, ['lines' => [$this->line()]]));
            $this->assertMissingAccounts(fn () => $paymentService->update($payment->id, ['lines' => [$this->line()]]));
        } finally {
            Config::set('app.env', 'testing');
        }

        $this->assertDatabaseMissing('bank_receipts', ['voucher_number' => 'CB-BANK-REC-MISSING']);
        $this->assertDatabaseMissing('bank_payments', ['voucher_number' => 'CB-BANK-PAY-MISSING']);
        $this->assertDatabaseHas('bank_receipt_lines', ['bank_receipt_id' => $productionReceipt->id, 'debit_account' => '1121', 'credit_account' => '131']);
        $this->assertDatabaseHas('bank_payment_lines', ['bank_payment_id' => $productionPayment->id, 'debit_account' => '331', 'credit_account' => '1121']);
        $this->assertDatabaseHas('bank_receipt_lines', ['bank_receipt_id' => $receipt->id, 'debit_account' => '1121', 'credit_account' => '131']);
        $this->assertDatabaseHas('bank_payment_lines', ['bank_payment_id' => $payment->id, 'debit_account' => '331', 'credit_account' => '1121']);
    }

    private function assertMissingAccounts(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Production cash/bank operations must not infer account defaults.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('lines.0.debit_account', $exception->errors());
            $this->assertArrayHasKey('lines.0.credit_account', $exception->errors());
        }
    }

    /** @return array<string, mixed> */
    private function cashPayload(string $number, ?string $debit = null, ?string $credit = null): array
    {
        return [
            'company_id' => $this->company->id,
            'voucher_number' => $number,
            'voucher_date' => '2026-08-24',
            'posting_date' => '2026-08-24',
            'lines' => [$this->line($debit, $credit)],
        ];
    }

    /** @return array<string, mixed> */
    private function bankPayload(string $number, ?string $debit = null, ?string $credit = null): array
    {
        return [
            ...$this->cashPayload($number, $debit, $credit),
            'bank_account_id' => $this->bankAccount->id,
        ];
    }

    /** @return array<string, mixed> */
    private function line(?string $debit = null, ?string $credit = null): array
    {
        return array_filter([
            'description' => 'Production account evidence boundary',
            'debit_account' => $debit,
            'credit_account' => $credit,
            'amount' => 100,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
