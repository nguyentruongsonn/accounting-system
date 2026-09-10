<?php

namespace Tests\Feature;

use App\Models\CashReceipt;
use App\Models\CashReceiptLine;
use App\Models\CashPayment;
use App\Models\Company;
use App\Services\CashReportMovementSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use UnexpectedValueException;
use Tests\TestCase;

class CashReportMovementSourceTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Company $otherCompany;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'name' => 'Cash movement tenant',
            'tax_code' => '100000001',
            'address' => 'Test address',
        ]);
        $this->otherCompany = Company::create([
            'name' => 'Other cash movement tenant',
            'tax_code' => '100000002',
            'address' => 'Other test address',
        ]);
    }

    public function test_collect_returns_only_requested_tenant_valid_cash_lines(): void
    {
        $receipt = $this->receipt($this->company, 'PT-CASH');
        $receipt->lines()->create([
            'debit_account' => '1111',
            'credit_account' => '131',
            'description' => 'Cash received',
            'amount' => 1000,
        ]);
        $receipt->lines()->create([
            'debit_account' => '1121',
            'credit_account' => '131',
            'description' => 'Bank received',
            'amount' => 9000,
        ]);
        $receipt->lines()->create([
            'debit_account' => '1111',
            'credit_account' => '1121',
            'description' => 'Mixed bank line',
            'amount' => 8000,
        ]);

        $otherReceipt = $this->receipt($this->otherCompany, 'PT-CASH');
        $otherReceipt->lines()->create([
            'debit_account' => '1111',
            'credit_account' => '131',
            'amount' => 7000,
        ]);

        $movements = app(CashReportMovementSource::class)->collect($this->company->id, [
            'date_from' => '2026-08-01',
            'date_to' => '2026-08-31',
            'status' => 'posted',
            'search' => '',
            'cash_account' => null,
        ]);

        $this->assertCount(1, $movements);
        $this->assertSame('PT-CASH', $movements->first()['voucher_number']);
        $this->assertSame('1111', $movements->first()['cash_account']);
        $this->assertSame('receipt', $movements->first()['source']);
        $this->assertSame('in', $movements->first()['direction']);
        $this->assertSame(1000, $movements->first()['amount']);
    }

    public function test_collect_normalizes_payment_contact_description_and_search_text_without_applying_search(): void
    {
        $payment = $this->payment($this->company, 'PC-SEARCH', [
            'contact_name' => null,
            'receiver_name' => 'Receiver fallback',
            'reason' => 'Reason remains searchable',
        ]);
        $payment->lines()->create([
            'debit_account' => '331',
            'credit_account' => '1111',
            'description' => 'Line description wins',
            'amount' => 2500,
        ]);
        $payment->lines()->create([
            'debit_account' => '642',
            'credit_account' => '1111',
            'description' => null,
            'amount' => 2600,
        ]);

        $movements = $this->collect(['search' => 'not-applied-here']);

        $this->assertCount(2, $movements);
        $movement = $movements->first();
        $this->assertSame('payment', $movement['source']);
        $this->assertSame('out', $movement['direction']);
        $this->assertSame('1111', $movement['cash_account']);
        $this->assertSame('331', $movement['counterpart_account']);
        $this->assertSame('Receiver fallback', $movement['contact_name']);
        $this->assertSame('Line description wins', $movement['description']);
        $this->assertSame('Reason remains searchable', $movements->last()['description']);
        $this->assertTrue(app(CashReportMovementSource::class)->matchesSearch($movement, 'reason remains'));
        $this->assertTrue(app(CashReportMovementSource::class)->matchesSearch($movement, '331'));
    }

    public function test_matches_search_is_trimmed_case_insensitive_and_treats_percent_and_underscore_literally(): void
    {
        $movement = [
            'search_text' => 'Voucher PT%_01 and Contact Name',
        ];
        $source = app(CashReportMovementSource::class);

        $this->assertTrue($source->matchesSearch($movement, '  contact name  '));
        $this->assertTrue($source->matchesSearch($movement, 'PT%_01'));
        $this->assertFalse($source->matchesSearch($movement, 'PTA001'));
        $this->assertTrue($source->matchesSearch($movement, '   '));
    }

    public function test_collect_applies_status_cash_account_dates_history_and_soft_delete_compatibly(): void
    {
        $this->cashReceipt('PT-BEFORE', '2026-07-31', 'posted', true, 100, '1111');
        $this->cashReceipt('PT-IN', '2026-08-15', 'posted', true, 200, '1111');
        $this->cashReceipt('PT-111', '2026-08-16', 'posted', true, 300, '111');
        $this->cashReceipt('PT-DRAFT', '2026-08-17', 'draft', false, 400, '1111');
        $this->cashReceipt('PT-STATUS-LIE', '2026-08-18', 'posted', false, 500, '1111');
        $this->cashReceipt('PT-VOID-WINS', '2026-08-19', 'voided', true, 600, '1111');
        $this->cashReceipt('PT-AFTER', '2026-09-01', 'posted', true, 700, '1111');
        $deleted = $this->cashReceipt('PT-DELETED', '2026-08-20', 'posted', true, 800, '1111');
        $deleted->delete();

        $this->assertSame(['PT-IN', 'PT-111'], $this->collect(['status' => 'posted'])->pluck('voucher_number')->all());
        $this->assertSame(['PT-DRAFT', 'PT-STATUS-LIE'], $this->collect(['status' => 'draft'])->pluck('voucher_number')->all());
        $this->assertSame(['PT-VOID-WINS'], $this->collect(['status' => 'voided'])->pluck('voucher_number')->all());
        $this->assertSame(
            ['PT-IN', 'PT-111', 'PT-DRAFT', 'PT-STATUS-LIE', 'PT-VOID-WINS'],
            $this->collect(['status' => 'all'])->pluck('voucher_number')->all(),
        );
        $this->assertSame(['PT-IN', 'PT-111'], $this->collect(['status' => 'posted', 'cash_account' => '111'])->pluck('voucher_number')->all());
        $this->assertSame(['PT-IN'], $this->collect(['status' => 'posted', 'cash_account' => '1111'])->pluck('voucher_number')->all());
        $this->assertSame(
            ['PT-BEFORE', 'PT-IN', 'PT-111'],
            $this->collect(['status' => 'posted'], true)->pluck('voucher_number')->all(),
        );
    }

    public function test_collect_orders_by_dates_voucher_source_and_numeric_line_id(): void
    {
        $receipt = $this->receipt($this->company, 'PT-SAME');
        CashReceiptLine::unguarded(function () use ($receipt): void {
            $receipt->lines()->create([
                'id' => 10,
                'debit_account' => '1111',
                'credit_account' => '131',
                'amount' => 1000,
            ]);
            $receipt->lines()->create([
                'id' => 2,
                'debit_account' => '1111',
                'credit_account' => '131',
                'amount' => 2000,
            ]);
        });
        $this->cashReceipt('PT-LATER', '2026-08-16', 'posted', true, 3000, '1111');

        $movements = $this->collect();

        $this->assertSame([2, 10, 11], $movements->pluck('line_id')->all());
        $this->assertSame(['PT-SAME', 'PT-SAME', 'PT-LATER'], $movements->pluck('voucher_number')->all());
    }

    public function test_collect_orders_numeric_looking_voucher_numbers_lexicographically(): void
    {
        $this->cashReceipt('2', '2026-08-15', 'posted', true, 1000, '1111');
        $this->cashReceipt('10', '2026-08-15', 'posted', true, 2000, '1111');

        $this->assertSame(['10', '2'], $this->collect()->pluck('voucher_number')->all());
    }

    public function test_collect_rejects_fractional_line_amounts_without_mutating_source_data(): void
    {
        $fractional = $this->cashReceipt('PT-FRACTION', '2026-08-15', 'posted', true, 1000, '1111');
        $fractionalLine = $fractional->lines->first();
        CashReceiptLine::query()->whereKey($fractionalLine->id)->update(['amount' => '1000.5']);

        $beforeFractional = DB::table('cash_receipt_lines')->where('id', $fractionalLine->id)->value('amount');

        try {
            $this->collect();
            $this->fail('Expected corrupt monetary data to be rejected.');
        } catch (UnexpectedValueException $exception) {
            $this->assertStringContainsString('safe integer VND', $exception->getMessage());
        }

        $this->assertSame($beforeFractional, DB::table('cash_receipt_lines')->where('id', $fractionalLine->id)->value('amount'));
    }

    public function test_collect_rejects_unsafe_line_amounts(): void
    {
        $unsafe = $this->cashReceipt('PT-UNSAFE', '2026-08-16', 'posted', true, 1000, '1111');
        CashReceiptLine::query()->whereKey($unsafe->lines->first()->id)->update(['amount' => '9007199254740992']);

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('safe integer VND');
        $this->collect();
    }

    public function test_collect_rejects_corrupt_required_voucher_dates(): void
    {
        $receipt = $this->cashReceipt('PT-CORRUPT-DATE', '2026-08-15', 'posted', true, 1000, '1111');
        CashReceipt::query()->whereKey($receipt->id)->update(['voucher_date' => 'not-a-date']);

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('voucher_date');
        $this->collect();
    }

    public function test_collect_rejects_corrupt_posting_dates_without_loading_valid_future_vouchers(): void
    {
        $corrupt = $this->cashReceipt('PT-CORRUPT-POSTING', '2026-08-15', 'posted', true, 1000, '1111');
        $this->cashReceipt('PT-VALID-FUTURE', '2026-09-01', 'posted', true, 2000, '1111');
        $this->assertCount(1, $this->collect());
        CashReceipt::query()->whereKey($corrupt->id)->update(['posting_date' => 'not-a-date']);

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('posting_date');
        $this->collect();
    }

    public function test_null_posting_dates_are_rejected_when_normalized(): void
    {
        $receipt = new CashReceipt;
        $receipt->setRawAttributes([
            'id' => 1,
            'status' => 'posted',
            'is_posted' => 1,
            'posting_date' => null,
            'voucher_date' => '2026-08-15',
            'voucher_number' => 'PT-NULL-POSTING',
        ], true);
        $receipt->setRelation('lines', collect());

        $normaliseVoucher = \Closure::bind(
            fn (): array => $this->normaliseVoucher($receipt, 'receipt', 'posted', null),
            app(CashReportMovementSource::class),
            CashReportMovementSource::class,
        );

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('posting_date');
        $normaliseVoucher();
    }

    private function receipt(Company $company, string $voucherNumber): CashReceipt
    {
        return CashReceipt::create([
            'company_id' => $company->id,
            'voucher_number' => $voucherNumber,
            'voucher_date' => '2026-08-15',
            'posting_date' => '2026-08-15',
            'contact_name' => 'Cash contact',
            'reason' => 'Cash receipt reason',
            'total_amount' => 999999,
            'status' => 'posted',
            'is_posted' => true,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function payment(Company $company, string $voucherNumber, array $overrides = []): CashPayment
    {
        return CashPayment::create(array_replace([
            'company_id' => $company->id,
            'voucher_number' => $voucherNumber,
            'voucher_date' => '2026-08-15',
            'posting_date' => '2026-08-15',
            'contact_name' => 'Payment contact',
            'receiver_name' => 'Payment receiver',
            'reason' => 'Payment reason',
            'total_amount' => 999999,
            'status' => 'posted',
            'is_posted' => true,
        ], $overrides));
    }

    private function cashReceipt(
        string $voucherNumber,
        string $postingDate,
        string $status,
        bool $isPosted,
        int $amount,
        string $cashAccount,
    ): CashReceipt {
        $receipt = $this->receipt($this->company, $voucherNumber);
        $receipt->update([
            'voucher_date' => $postingDate,
            'posting_date' => $postingDate,
            'status' => $status,
            'is_posted' => $isPosted,
        ]);
        $receipt->lines()->create([
            'debit_account' => $cashAccount,
            'credit_account' => '131',
            'description' => $voucherNumber . ' line',
            'amount' => $amount,
        ]);

        return $receipt->load('lines');
    }

    /** @param array<string, mixed> $overrides */
    private function collect(array $overrides = [], bool $includeBeforePeriod = false): \Illuminate\Support\Collection
    {
        return app(CashReportMovementSource::class)->collect($this->company->id, array_replace([
            'date_from' => '2026-08-01',
            'date_to' => '2026-08-31',
            'status' => 'posted',
            'search' => '',
            'cash_account' => null,
        ], $overrides), $includeBeforePeriod);
    }
}
