<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PurchaseOrderSubtotalMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function migrateSubtotal(): void
    {
        (require database_path('migrations/2026_09_02_010000_add_sub_total_to_purchase_orders_table.php'))->up();
    }

    public function test_existing_totals_are_not_rewritten_even_without_lines(): void
    {
        $id = DB::table('purchase_orders')->insertGetId([
            'order_number' => 'KEEP', 'order_date' => '2026-09-01', 'sub_total' => '123.45',
        ]);
        $this->migrateSubtotal();
        $this->assertEquals('123.45', DB::table('purchase_orders')->where('id', $id)->value('sub_total'));
    }

    public function test_new_column_backfills_once_and_rerun_preserves_saved_total(): void
    {
        Schema::table('purchase_orders', fn (Blueprint $table) => $table->dropColumn('sub_total'));
        $id = DB::table('purchase_orders')->insertGetId(['order_number' => 'LEGACY', 'order_date' => '2026-09-01']);
        foreach (['10.25', '20.50'] as $amount) {
            DB::table('purchase_order_lines')->insert(['purchase_order_id' => $id, 'amount' => $amount]);
        }
        $this->migrateSubtotal();
        $this->assertEquals('30.75', DB::table('purchase_orders')->where('id', $id)->value('sub_total'));
        DB::table('purchase_order_lines')->where('purchase_order_id', $id)->update(['amount' => '999.00']);
        $this->migrateSubtotal();
        $this->assertEquals('30.75', DB::table('purchase_orders')->where('id', $id)->value('sub_total'));
    }

    public function test_partial_backfill_resumes_only_null_totals(): void
    {
        Schema::table('purchase_orders', fn (Blueprint $table) => $table->decimal('sub_total', 18, 2)->nullable()->default(null)->change());
        foreach (['DONE' => '45.25', 'PENDING' => null] as $code => $subtotal) {
            $id = DB::table('purchase_orders')->insertGetId(['order_number' => $code, 'order_date' => '2026-09-01', 'sub_total' => $subtotal]);
            DB::table('purchase_order_lines')->insert(['purchase_order_id' => $id, 'amount' => '10.50']);
        }
        $this->migrateSubtotal();
        $this->assertEquals('45.25', DB::table('purchase_orders')->where('order_number', 'DONE')->value('sub_total'));
        $this->assertEquals('10.50', DB::table('purchase_orders')->where('order_number', 'PENDING')->value('sub_total'));
    }
}
