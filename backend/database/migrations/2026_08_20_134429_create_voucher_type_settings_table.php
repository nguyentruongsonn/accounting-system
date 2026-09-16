<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voucher_type_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->default(1);
            $table->enum('voucher_type', [
                'thu_tien_mat',
                'thu_tien_gui',
                'chi_tien_mat',
                'chi_tien_gui',
                'chung_tu_khac',
            ]);
            $table->string('name', 255); // Tên định khoản
            $table->string('debit_account', 20)->nullable();  // TK Nợ ngầm định
            $table->string('credit_account', 20)->nullable(); // TK Có ngầm định
            $table->string('filter_debit', 100)->nullable();  // Lọc TK Nợ (prefix, vd: "111")
            $table->string('filter_credit', 100)->nullable(); // Lọc TK Có (vd: "131, 511, 711")
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'voucher_type']);
            $table->index(['company_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voucher_type_settings');
    }
};
