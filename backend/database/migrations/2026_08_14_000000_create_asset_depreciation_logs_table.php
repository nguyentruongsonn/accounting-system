<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('asset_depreciation_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->string('month', 7);
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries');
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('asset_depreciation_logs');
    }
};
