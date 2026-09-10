<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Populated legacy-upgrade regression. */
class SettlementLegacyUpgradeDiagnosticTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_posted_rows_upgrade_and_retry_preserve_protection(): void
    {
        // Reconstruct only the old table in the forced in-memory test database.
        Schema::drop('settlement_allocations');
        Schema::create('settlement_allocations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('source_document_type');
            $table->unsignedBigInteger('source_document_id');
            $table->string('source_line_type')->nullable();
            $table->unsignedBigInteger('source_line_id')->nullable();
            $table->string('target_document_type');
            $table->unsignedBigInteger('target_document_id');
            $table->string('status');
        });
        DB::table('settlement_allocations')->insert([
            'company_id' => 1, 'source_document_type' => 'cash_payment',
            'source_document_id' => 1, 'target_document_type' => 'purchase_invoice',
            'target_document_id' => 2, 'status' => 'posted',
        ]);
        (require database_path('migrations/2026_08_22_153010_add_settlement_allocation_immutability_triggers.php'))->up();
        $migration = require database_path('migrations/2026_08_22_153020_add_settlement_allocation_source_reference_key.php');
        $migration->up();
        $this->assertTrue(Schema::hasColumn('settlement_allocations', 'source_reference_key'));
        $this->assertSame('cash_payment|1|-|-|purchase_invoice|2', DB::table('settlement_allocations')->value('source_reference_key'));
        $migration->up();
        $this->assertSame('cash_payment|1|-|-|purchase_invoice|2', DB::table('settlement_allocations')->value('source_reference_key'));
        $this->assertTrue(collect(Schema::getIndexes('settlement_allocations'))->contains('name', 'settlement_allocations_source_reference_unique'));
        foreach (['update', 'delete'] as $operation) {
            $failure = null;
            try {
                $query = DB::table('settlement_allocations')->where('id', 1);
                $operation === 'update' ? $query->update(['status' => 'draft']) : $query->delete();
            } catch (\Illuminate\Database\QueryException $error) {
                $failure = $error;
            }
            $this->assertNotNull($failure);
            $this->assertStringContainsString('immutable', $failure->getMessage());
        }
    }
}
