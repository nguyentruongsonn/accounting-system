<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('management_report_definitions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('report_key', 80);
            $table->string('definition_version', 80);
            $table->string('status', 20)->default('draft');
            $table->json('source_contract')->nullable();
            $table->json('calculation_contract')->nullable();
            $table->foreignId('signed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('signed_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'report_key', 'definition_version'], 'management_report_definitions_company_report_version_unique');
            $table->index(['company_id', 'report_key', 'status', 'published_at'], 'management_report_definitions_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('management_report_definitions');
    }
};
