<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('purchase_orders') && ! Schema::hasColumn('purchase_orders', 'sub_total')) {
            Schema::table('purchase_orders', function (Blueprint $table): void {
                // NULL distinguishes unfinished backfill from a saved zero.
                $table->decimal('sub_total', 18, 2)->nullable()->default(null)->after('description');
            });
        }

        if (Schema::hasTable('purchase_orders') && Schema::hasTable('purchase_order_lines')) {
            DB::table('purchase_orders')
                ->select('id')
                ->whereNull('sub_total')
                ->orderBy('id')
                ->chunkById(500, function ($orders): void {
                    foreach ($orders as $order) {
                        $subTotal = DB::table('purchase_order_lines')
                            ->where('purchase_order_id', $order->id)
                            ->sum('amount');
                        DB::table('purchase_orders')
                            ->where('id', $order->id)
                            ->whereNull('sub_total')
                            ->update(['sub_total' => $subTotal]);
                    }
                });
            Schema::table('purchase_orders', function (Blueprint $table): void {
                $table->decimal('sub_total', 18, 2)->nullable(false)->default(0)->change();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('purchase_orders') && Schema::hasColumn('purchase_orders', 'sub_total')) {
            Schema::table('purchase_orders', function (Blueprint $table): void {
                $table->dropColumn('sub_total');
            });
        }
    }
};
