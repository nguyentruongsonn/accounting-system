<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('purchase_expense_allocations')) {
            Schema::create('purchase_expense_allocations', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->unsignedBigInteger('source_purchase_invoice_id');
                $table->unsignedBigInteger('target_purchase_invoice_id');
                $table->unsignedBigInteger('target_purchase_invoice_line_id')->nullable();
                $table->decimal('allocated_amount', 15, 2);
                $table->string('allocation_method', 20)->default('value');
                $table->date('effective_date');
                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamps();

                $table->foreign('company_id', 'pea_company_fk')
                    ->references('id')->on('companies')->restrictOnDelete();
                $table->foreign('source_purchase_invoice_id', 'pea_source_invoice_fk')
                    ->references('id')->on('purchase_invoices')->restrictOnDelete();
                $table->foreign('target_purchase_invoice_id', 'pea_target_invoice_fk')
                    ->references('id')->on('purchase_invoices')->restrictOnDelete();
                $table->foreign('target_purchase_invoice_line_id', 'pea_target_line_fk')
                    ->references('id')->on('purchase_invoice_lines')->restrictOnDelete();
                $table->foreign('created_by', 'pea_created_by_fk')
                    ->references('id')->on('users')->nullOnDelete();
                $table->foreign('updated_by', 'pea_updated_by_fk')
                    ->references('id')->on('users')->nullOnDelete();

                $table->index(['company_id', 'source_purchase_invoice_id'], 'pea_company_source_index');
                $table->index(['company_id', 'target_purchase_invoice_id'], 'pea_company_target_index');
                $table->index(['company_id', 'target_purchase_invoice_line_id'], 'pea_company_target_line_index');
                $table->unique(
                    ['company_id', 'source_purchase_invoice_id', 'target_purchase_invoice_line_id'],
                    'pea_source_target_line_unique',
                );
            });
        }

        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        // MySQL DDL is not transactional. If an earlier deployment created the
        // table and failed on a long auto-generated FK name, complete the
        // partial table instead of requiring a destructive drop.
        $foreignColumns = collect(DB::select(
            "SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'purchase_expense_allocations'
               AND REFERENCED_TABLE_NAME IS NOT NULL",
            [DB::getDatabaseName()],
        ))->pluck('COLUMN_NAME')->all();

        $foreignDefinitions = [
            'target_purchase_invoice_line_id' => ['pea_target_line_fk', 'purchase_invoice_lines', 'restrict'],
            'created_by' => ['pea_created_by_fk', 'users', 'null'],
            'updated_by' => ['pea_updated_by_fk', 'users', 'null'],
        ];

        foreach ($foreignDefinitions as $column => [$name, $table, $onDelete]) {
            if (in_array($column, $foreignColumns, true)) {
                continue;
            }

            Schema::table('purchase_expense_allocations', function (Blueprint $blueprint) use ($column, $name, $table, $onDelete): void {
                $foreign = $blueprint->foreign($column, $name)->references('id')->on($table);
                if ($onDelete === 'null') {
                    $foreign->nullOnDelete();
                } else {
                    $foreign->restrictOnDelete();
                }
            });
        }

        $indexNames = collect(DB::select('SHOW INDEX FROM purchase_expense_allocations'))
            ->pluck('Key_name')
            ->unique()
            ->all();

        if (! in_array('pea_company_target_line_index', $indexNames, true)) {
            Schema::table('purchase_expense_allocations', function (Blueprint $table): void {
                $table->index(['company_id', 'target_purchase_invoice_line_id'], 'pea_company_target_line_index');
            });
        }

        if (! in_array('pea_source_target_line_unique', $indexNames, true)) {
            Schema::table('purchase_expense_allocations', function (Blueprint $table): void {
                $table->unique(
                    ['company_id', 'source_purchase_invoice_id', 'target_purchase_invoice_line_id'],
                    'pea_source_target_line_unique',
                );
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_expense_allocations');
    }
};
