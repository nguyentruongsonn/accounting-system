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
        Schema::table('bank_accounts', function (Blueprint $table) {
            $table->string('bank_code', 50)->nullable()->after('bank_name');
            $table->string('province', 100)->nullable()->after('bank_code');
            $table->string('branch_address', 255)->nullable()->after('branch');
            $table->string('swift_code', 50)->nullable()->after('branch_address');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bank_accounts', function (Blueprint $table) {
            $table->dropColumn(['bank_code', 'province', 'branch_address', 'swift_code']);
        });
    }
};
