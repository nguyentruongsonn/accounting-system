<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\FiscalYear;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class JournalMoneyMigrationRollbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_rollback_refuses_fractional_values_before_changing_any_column(): void
    {
        $this->insertEntry('ROLLBACK-CENTS', '10.25', '10.25', '10.25');
        $migration = $this->moneyMigration();

        try {
            $migration->down();
            $this->fail('Expected a cent-bearing ledger to block BIGINT rollback.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('fractional monetary values', $exception->getMessage());
        }

        $this->assertSame('numeric', Schema::getColumnType('journal_entries', 'total_amount'));
        $this->assertSame('numeric', Schema::getColumnType('journal_entry_lines', 'debit_amount'));
        $this->assertSame('10.25', (string) DB::table('journal_entries')->where('voucher_number', 'ROLLBACK-CENTS')->value('total_amount'));
    }

    public function test_integral_values_can_rollback_and_migrate_forward_without_value_loss(): void
    {
        $this->insertEntry('ROLLBACK-WHOLE', '10.00', '10.00', '10.00');
        $migration = $this->moneyMigration();

        $migration->down();

        try {
            $this->assertSame('integer', Schema::getColumnType('journal_entries', 'total_amount'));
            $this->assertSame('integer', Schema::getColumnType('journal_entry_lines', 'debit_amount'));
            $this->assertSame(10, DB::table('journal_entries')->where('voucher_number', 'ROLLBACK-WHOLE')->value('total_amount'));
        } finally {
            $migration->up();
        }

        $this->assertSame('numeric', Schema::getColumnType('journal_entries', 'total_amount'));
        $this->assertSame('10', (string) DB::table('journal_entries')->where('voucher_number', 'ROLLBACK-WHOLE')->value('total_amount'));
    }

    private function moneyMigration(): Migration
    {
        return require database_path('migrations/2026_08_21_200000_standardize_journal_money_decimal_scale.php');
    }

    private function insertEntry(string $voucherNumber, string $total, string $debit, string $credit): void
    {
        $company = Company::findOrFail(1);
        $fiscalYear = FiscalYear::where('company_id', $company->id)->firstOrFail();
        $entryId = DB::table('journal_entries')->insertGetId([
            'company_id' => $company->id,
            'fiscal_year_id' => $fiscalYear->id,
            'voucher_type' => 'general_journal',
            'voucher_number' => $voucherNumber,
            'voucher_date' => '2026-08-21',
            'posting_date' => '2026-08-21',
            'description' => $voucherNumber,
            'total_amount' => $total,
            'status' => 'posted',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('journal_entry_lines')->insert([
            [
                'journal_entry_id' => $entryId,
                'account_code' => '1111',
                'debit_amount' => $debit,
                'credit_amount' => '0.00',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'journal_entry_id' => $entryId,
                'account_code' => '511',
                'debit_amount' => '0.00',
                'credit_amount' => $credit,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }
}
