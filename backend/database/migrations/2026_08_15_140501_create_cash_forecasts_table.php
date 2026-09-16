<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_forecasts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->default(1);
            $table->string('period_name');
            $table->date('from_date');
            $table->date('to_date');
            $table->string('creator')->default('Nguyễn Văn');
            $table->date('created_date');
            $table->decimal('opening_balance', 18, 2)->default(0);
            $table->decimal('expected_inflow', 18, 2)->default(0);
            $table->decimal('expected_outflow', 18, 2)->default(0);
            $table->decimal('closing_balance', 18, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('cash_forecast_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('cash_forecast_id');
            $table->string('code');
            $table->string('name');
            $table->decimal('amount', 18, 2)->default(0);
            $table->boolean('is_parent')->default(false);
            $table->boolean('is_removable')->default(false);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->foreign('cash_forecast_id')->references('id')->on('cash_forecasts')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_forecast_items');
        Schema::dropIfExists('cash_forecasts');
    }
};
