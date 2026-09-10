<?php

namespace Tests\Feature;

use App\Http\Resources\JournalEntryResource;
use App\Models\ChartOfAccount;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\JournalEntryService;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class JournalEntryDecimalKernelTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private FiscalYear $fiscalYear;

    private JournalEntryService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::findOrFail(1);
        $this->fiscalYear = FiscalYear::where('company_id', $this->company->id)->firstOrFail();
        $user = User::factory()->create(['company_id' => $this->company->id]);
        Sanctum::actingAs($user);

        foreach ([['1111', 'asset', 'debit'], ['511', 'revenue', 'credit']] as [$code, $type, $nature]) {
            ChartOfAccount::create([
                'company_id' => $this->company->id,
                'code' => $code,
                'name' => $code,
                'type' => $type,
                'nature' => $nature,
                'level' => 1,
                'is_parent' => false,
                'is_active' => true,
            ]);
        }

        $this->service = app(JournalEntryService::class);
    }

    public function test_repeated_decimal_addition_and_zero_point_one_plus_zero_point_two_are_exact(): void
    {
        $entry = $this->service->create($this->payload('DEC-ADD', [
            ['account_code' => '1111', 'debit_amount' => 0.1, 'credit_amount' => 0],
            ['account_code' => '1111', 'debit_amount' => '0.20', 'credit_amount' => 0],
            ['account_code' => '511', 'debit_amount' => 0, 'credit_amount' => '0.30'],
        ]));

        $this->assertSame('0.30', $entry->total_amount);
        $this->assertSame(['0.10', '0.20', '0.00'], $entry->lines->pluck('debit_amount')->all());
        $this->assertSame(['0.00', '0.00', '0.30'], $entry->lines->pluck('credit_amount')->all());
    }

    public function test_fractional_cents_are_rejected_without_silent_rounding(): void
    {
        try {
            $this->service->create($this->payload('DEC-SCALE', [
                ['account_code' => '1111', 'debit_amount' => '10.001', 'credit_amount' => 0],
                ['account_code' => '511', 'debit_amount' => 0, 'credit_amount' => '10.001'],
            ]));
            $this->fail('Expected excess monetary scale to be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('lines.0.debit_amount', $exception->errors());
            $this->assertStringContainsString('không tự làm tròn', $exception->getMessage());
        }

        $this->assertDatabaseMissing('journal_entries', ['voucher_number' => 'DEC-SCALE']);
    }

    public function test_large_decimal_string_is_preserved_and_decimal_overflow_is_rejected(): void
    {
        $entry = $this->service->create($this->payload('DEC-LARGE', [
            ['account_code' => '1111', 'debit_amount' => '50000000000.55', 'credit_amount' => 0],
            ['account_code' => '511', 'debit_amount' => 0, 'credit_amount' => '50000000000.55'],
        ]));

        $this->assertSame('50000000000.55', $entry->total_amount);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('DECIMAL(18,2)');
        $this->service->create($this->payload('DEC-OVERFLOW', [
            ['account_code' => '1111', 'debit_amount' => '10000000000000000.00', 'credit_amount' => 0],
            ['account_code' => '511', 'debit_amount' => 0, 'credit_amount' => '10000000000000000.00'],
        ]));
    }

    public function test_exact_one_cent_mismatch_is_rejected(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Total Debit (100) does not equal Total Credit (99.99)');

        $this->service->create($this->payload('DEC-MISMATCH', [
            ['account_code' => '1111', 'debit_amount' => '100.00', 'credit_amount' => 0],
            ['account_code' => '511', 'debit_amount' => 0, 'credit_amount' => '99.99'],
        ]));
    }

    public function test_post_transition_rejects_an_exactly_imbalanced_legacy_draft(): void
    {
        $entry = JournalEntry::create([
            'company_id' => $this->company->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'voucher_type' => 'general_journal',
            'voucher_number' => 'DEC-LEGACY',
            'voucher_date' => '2026-08-15',
            'posting_date' => '2026-08-15',
            'description' => 'Legacy draft with one-cent mismatch',
            'total_amount' => '100.00',
            'status' => 'draft',
        ]);
        $entry->lines()->createMany([
            ['account_code' => '1111', 'debit_amount' => '100.00', 'credit_amount' => 0],
            ['account_code' => '511', 'debit_amount' => 0, 'credit_amount' => '99.99'],
        ]);

        try {
            $this->service->post($entry->id);
            $this->fail('Expected imbalanced legacy draft to be rejected on posting.');
        } catch (Exception $exception) {
            $this->assertStringContainsString('Total Debit (100) does not equal Total Credit (99.99)', $exception->getMessage());
        }

        $this->assertSame('draft', $entry->fresh()->status);
    }

    public function test_resource_preserves_exact_decimal_strings_and_credit_line_compatibility_amount(): void
    {
        $entry = $this->service->create($this->payload('DEC-RESOURCE', [
            ['account_code' => '1111', 'debit_amount' => '10.25', 'credit_amount' => 0],
            ['account_code' => '511', 'debit_amount' => 0, 'credit_amount' => '10.25'],
        ]));

        $payload = (new JournalEntryResource($entry))->toArray(request());

        $this->assertSame('10.25', $payload['total_amount_decimal']);
        $this->assertSame(10.25, $payload['lines'][1]['amount']);
        $this->assertSame('10.25', $payload['lines'][1]['amount_decimal']);
        $this->assertSame('0.00', $payload['lines'][1]['debit_amount_decimal']);
        $this->assertSame('10.25', $payload['lines'][1]['credit_amount_decimal']);
    }

    private function payload(string $voucherNumber, array $lines): array
    {
        return [
            'company_id' => $this->company->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'voucher_type' => 'general_journal',
            'voucher_number' => $voucherNumber,
            'voucher_date' => '2026-08-15',
            'posting_date' => '2026-08-15',
            'description' => 'Exact decimal kernel test',
            'lines' => $lines,
        ];
    }
}
