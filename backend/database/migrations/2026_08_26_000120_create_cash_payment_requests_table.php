<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_payment_requests', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('request_number', 50);
            $table->date('request_date');
            $table->string('requester_name', 255);
            $table->string('department', 255)->nullable();
            $table->text('reason');
            $table->decimal('amount', 20, 2);
            $table->date('deadline')->nullable();
            $table->string('status', 30)->default('draft');
            $table->timestamp('submitted_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'request_number'], 'cash_payment_requests_company_number_unique');
            $table->index(['company_id', 'request_date']);
            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_payment_requests');
    }
};
