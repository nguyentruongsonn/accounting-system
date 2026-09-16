<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('cost_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('production_order_id')->constrained('production_orders')->cascadeOnDelete();
            $table->string('month', 7); // e.g. 2023-08
            
            // Chi phí tập hợp được trong kỳ
            $table->bigInteger('direct_material_cost')->default(0); // 621
            $table->bigInteger('direct_labor_cost')->default(0); // 622
            $table->bigInteger('manufacturing_overhead')->default(0); // 627 (được phân bổ)
            
            // Chi phí dở dang
            $table->bigInteger('wip_beginning')->default(0); // Dở dang đầu kỳ
            $table->bigInteger('wip_ending')->default(0); // Dở dang cuối kỳ
            
            // Tổng giá thành = Dở dang đầu + Phát sinh - Dở dang cuối
            $table->bigInteger('total_cost')->default(0);
            
            $table->boolean('is_posted')->default(false);
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cost_allocations');
    }
};
