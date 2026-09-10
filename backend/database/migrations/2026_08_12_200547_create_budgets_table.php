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
        Schema::create('budgets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('year', 4);
            $table->string('account_code', 20); // Tk can lap ngan sach (thuong la Chi phi or Doanh thu)
            $table->bigInteger('jan')->default(0);
            $table->bigInteger('feb')->default(0);
            $table->bigInteger('mar')->default(0);
            $table->bigInteger('apr')->default(0);
            $table->bigInteger('may')->default(0);
            $table->bigInteger('jun')->default(0);
            $table->bigInteger('jul')->default(0);
            $table->bigInteger('aug')->default(0);
            $table->bigInteger('sep')->default(0);
            $table->bigInteger('oct')->default(0);
            $table->bigInteger('nov')->default(0);
            $table->bigInteger('dec')->default(0);
            $table->timestamps();
            
            // Mot cong ty chi co 1 ngan sach cho 1 tai khoan trong 1 nam
            $table->unique(['company_id', 'year', 'account_code']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('budgets');
    }
};
