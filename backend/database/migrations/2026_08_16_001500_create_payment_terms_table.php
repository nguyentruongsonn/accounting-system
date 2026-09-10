<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasTable('payment_terms')) {
            Schema::create('payment_terms', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id')->default(1);
                $table->string('code')->unique();
                $table->string('name');
                $table->integer('due_days')->default(30);
                $table->integer('discount_days')->default(0);
                $table->decimal('discount_rate', 5, 2)->default(0);
                $table->text('description')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });

            // Seed default MISA payment terms
            DB::table('payment_terms')->insert([
                [
                    'company_id' => 1,
                    'code' => 'TM',
                    'name' => 'Thanh toán ngay',
                    'due_days' => 0,
                    'discount_days' => 0,
                    'discount_rate' => 0,
                    'description' => 'Thanh toán ngay bằng tiền mặt hoặc chuyển khoản',
                    'created_at' => now(),
                    'updated_at' => now()
                ],
                [
                    'company_id' => 1,
                    'code' => 'ĐKTT15',
                    'name' => 'Nợ 15 ngày',
                    'due_days' => 15,
                    'discount_days' => 5,
                    'discount_rate' => 1,
                    'description' => 'Hạn nợ 15 ngày, thanh toán trước 5 ngày chiết khấu 1%',
                    'created_at' => now(),
                    'updated_at' => now()
                ],
                [
                    'company_id' => 1,
                    'code' => 'ĐKTT30',
                    'name' => 'Nợ 30 ngày',
                    'due_days' => 30,
                    'discount_days' => 10,
                    'discount_rate' => 1.5,
                    'description' => 'Hạn nợ 30 ngày, thanh toán trước 10 ngày chiết khấu 1.5%',
                    'created_at' => now(),
                    'updated_at' => now()
                ],
                [
                    'company_id' => 1,
                    'code' => 'ĐKTT60',
                    'name' => 'Nợ 60 ngày',
                    'due_days' => 60,
                    'discount_days' => 15,
                    'discount_rate' => 2,
                    'description' => 'Hạn nợ 60 ngày',
                    'created_at' => now(),
                    'updated_at' => now()
                ]
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_terms');
    }
};
