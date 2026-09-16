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
        // 1. Cập nhật bảng fixed_assets (bổ sung các cột vòng đời chuẩn MISA & VAS TT200)
        if (Schema::hasTable('fixed_assets')) {
            Schema::table('fixed_assets', function (Blueprint $table) {
                if (! Schema::hasColumn('fixed_assets', 'supplier_id')) {
                    $table->foreignId('supplier_id')->nullable()->after('quantity')->constrained('suppliers')->nullOnDelete();
                }
                if (! Schema::hasColumn('fixed_assets', 'supplier_name')) {
                    $table->string('supplier_name')->nullable()->after('supplier_id');
                }
                if (! Schema::hasColumn('fixed_assets', 'status')) {
                    $table->string('status', 30)->default('active')->after('is_active');
                }
                if (! Schema::hasColumn('fixed_assets', 'is_posted')) {
                    $table->boolean('is_posted')->default(false)->after('status');
                }
                if (! Schema::hasColumn('fixed_assets', 'credit_account')) {
                    $table->string('credit_account', 20)->default('331')->after('expense_account');
                }
                if (! Schema::hasColumn('fixed_assets', 'disposal_date')) {
                    $table->date('disposal_date')->nullable()->after('credit_account');
                }
                if (! Schema::hasColumn('fixed_assets', 'disposal_reason')) {
                    $table->string('disposal_reason')->nullable()->after('disposal_date');
                }
                if (! Schema::hasColumn('fixed_assets', 'referenced_vouchers')) {
                    $table->json('referenced_vouchers')->nullable()->after('journal_entry_id');
                }
                if (! Schema::hasColumn('fixed_assets', 'created_by')) {
                    $table->foreignId('created_by')->nullable()->after('referenced_vouchers')->constrained('users')->nullOnDelete();
                }
                if (! Schema::hasColumn('fixed_assets', 'updated_by')) {
                    $table->foreignId('updated_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
                }
            });
        }

        // 2. Chuẩn hóa bảng asset_depreciation_logs
        if (Schema::hasTable('asset_depreciation_logs')) {
            Schema::table('asset_depreciation_logs', function (Blueprint $table) {
                if (! Schema::hasColumn('asset_depreciation_logs', 'branch_id')) {
                    $table->foreignId('branch_id')->nullable()->after('company_id');
                }
                if (! Schema::hasColumn('asset_depreciation_logs', 'voucher_number')) {
                    $table->string('voucher_number', 50)->nullable()->after('branch_id');
                }
                if (! Schema::hasColumn('asset_depreciation_logs', 'voucher_date')) {
                    $table->date('voucher_date')->nullable()->after('voucher_number');
                }
                if (! Schema::hasColumn('asset_depreciation_logs', 'accounting_date')) {
                    $table->date('accounting_date')->nullable()->after('voucher_date');
                }
                if (! Schema::hasColumn('asset_depreciation_logs', 'description')) {
                    $table->text('description')->nullable()->after('month');
                }
                if (! Schema::hasColumn('asset_depreciation_logs', 'total_amount')) {
                    $table->decimal('total_amount', 15, 2)->default(0)->after('description');
                }
                if (! Schema::hasColumn('asset_depreciation_logs', 'is_posted')) {
                    $table->boolean('is_posted')->default(false)->after('total_amount');
                }
                if (! Schema::hasColumn('asset_depreciation_logs', 'status')) {
                    $table->string('status', 30)->default('draft')->after('is_posted');
                }
                if (! Schema::hasColumn('asset_depreciation_logs', 'referenced_vouchers')) {
                    $table->json('referenced_vouchers')->nullable()->after('journal_entry_id');
                }
                if (! Schema::hasColumn('asset_depreciation_logs', 'created_by')) {
                    $table->foreignId('created_by')->nullable()->after('referenced_vouchers')->constrained('users')->nullOnDelete();
                }
                if (! Schema::hasColumn('asset_depreciation_logs', 'updated_by')) {
                    $table->foreignId('updated_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
                }
            });
        } else {
            Schema::create('asset_depreciation_logs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
                $table->foreignId('branch_id')->nullable();
                $table->string('voucher_number', 50)->nullable();
                $table->date('voucher_date')->nullable();
                $table->date('accounting_date')->nullable();
                $table->string('month', 7)->index();
                $table->text('description')->nullable();
                $table->decimal('total_amount', 15, 2)->default(0);
                $table->boolean('is_posted')->default(false);
                $table->string('status', 30)->default('draft');
                $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
                $table->json('referenced_vouchers')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }

        // 3. Tạo mới bảng asset_depreciation_log_lines (Chi tiết khấu hao từng TSCĐ)
        if (! Schema::hasTable('asset_depreciation_log_lines')) {
            Schema::create('asset_depreciation_log_lines', function (Blueprint $table) {
                $table->id();
                $table->foreignId('depreciation_log_id')->constrained('asset_depreciation_logs')->cascadeOnDelete();
                $table->foreignId('fixed_asset_id')->constrained('fixed_assets')->cascadeOnDelete();
                $table->integer('line_order')->default(0);

                $table->string('asset_code', 50);
                $table->string('asset_name');
                $table->string('department_code', 50)->nullable();
                $table->string('category_code', 50)->nullable();

                $table->decimal('original_cost', 15, 2)->default(0);
                $table->decimal('depreciable_cost', 15, 2)->default(0);
                $table->integer('useful_life_months')->default(0);

                $table->decimal('accumulated_depreciation_before', 15, 2)->default(0);
                $table->decimal('monthly_depreciation', 15, 2)->default(0);
                $table->decimal('accumulated_depreciation_after', 15, 2)->default(0);
                $table->decimal('net_value_after', 15, 2)->default(0);

                $table->string('expense_account', 20)->default('6424');
                $table->string('depreciation_account', 20)->default('2141');
                $table->string('description')->nullable();

                $table->timestamps();
            });
        }

        // 4. Tạo mới bảng asset_disposals (Ghi giảm / Thanh lý TSCĐ)
        if (! Schema::hasTable('asset_disposals')) {
            Schema::create('asset_disposals', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
                $table->foreignId('branch_id')->nullable();
                $table->string('voucher_number', 50)->index();
                $table->date('voucher_date');
                $table->date('accounting_date')->nullable();
                $table->date('disposal_date')->nullable();

                $table->foreignId('fixed_asset_id')->constrained('fixed_assets');
                $table->string('asset_code', 50);
                $table->string('asset_name');
                $table->string('department_code', 50)->nullable();

                $table->string('disposal_type', 50)->default('liquidation'); // liquidation, sale, lost, transfer, other
                $table->string('disposal_reason')->nullable();

                // Giá trị sổ sách tại thời điểm ghi giảm
                $table->decimal('original_cost', 15, 2)->default(0);
                $table->decimal('accumulated_depreciation', 15, 2)->default(0);
                $table->decimal('net_value', 15, 2)->default(0);

                // Doanh thu / Thu nhập thanh lý (nếu có)
                $table->decimal('disposal_price', 15, 2)->default(0);
                $table->decimal('tax_rate', 5, 2)->default(0);
                $table->decimal('tax_amount', 15, 2)->default(0);
                $table->decimal('total_income', 15, 2)->default(0);

                $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
                $table->string('customer_name')->nullable();
                $table->foreignId('buyer_id')->nullable()->constrained('customers')->nullOnDelete();
                $table->string('buyer_name')->nullable();
                $table->string('payment_method', 50)->default('unpaid');

                // Tài khoản định khoản theo VAS TT200
                $table->string('asset_account', 20)->default('211');         // Có 211
                $table->string('depreciation_account', 20)->default('2141');  // Nợ 2141
                $table->string('expense_account', 20)->default('811');        // Nợ 811 (GTCL)
                $table->string('income_account', 20)->default('711');         // Có 711 (Thu nhập khác)
                $table->string('receivable_account', 20)->default('1111');    // Nợ 1111/1121/131
                $table->string('tax_account', 20)->default('33311');          // Có 33311

                $table->boolean('is_posted')->default(false);
                $table->string('status', 30)->default('draft');
                $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
                $table->json('referenced_vouchers')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

                $table->timestamps();
            });
        }

        // 5. Tạo mới bảng asset_revaluations (Đánh giá lại TSCĐ)
        if (! Schema::hasTable('asset_revaluations')) {
            Schema::create('asset_revaluations', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
                $table->foreignId('branch_id')->nullable();
                $table->string('voucher_number', 50)->index();
                $table->date('voucher_date');
                $table->date('accounting_date')->nullable();

                $table->foreignId('fixed_asset_id')->constrained('fixed_assets');
                $table->string('asset_code', 50);
                $table->string('asset_name');
                $table->string('department_code', 50)->nullable();

                // So sánh Nguyên giá cũ vs mới
                $table->decimal('old_original_cost', 15, 2)->default(0);
                $table->decimal('new_original_cost', 15, 2)->default(0);
                $table->decimal('cost_difference', 15, 2)->default(0);

                // So sánh Hao mòn lũy kế cũ vs mới
                $table->decimal('old_accumulated_depreciation', 15, 2)->default(0);
                $table->decimal('new_accumulated_depreciation', 15, 2)->default(0);
                $table->decimal('depreciation_difference', 15, 2)->default(0);

                // Giá trị còn lại & Thời gian sử dụng
                $table->decimal('old_net_value', 15, 2)->default(0);
                $table->decimal('new_net_value', 15, 2)->default(0);
                $table->integer('old_useful_life_months')->default(0);
                $table->integer('new_useful_life_months')->default(0);
                $table->integer('old_useful_life')->default(0);
                $table->integer('new_useful_life')->default(0);

                // Mức trích khấu hao tháng cũ vs mới
                $table->decimal('old_monthly_depreciation', 15, 2)->default(0);
                $table->decimal('new_monthly_depreciation', 15, 2)->default(0);

                $table->string('decision_number', 50)->nullable();
                $table->date('decision_date')->nullable();
                $table->string('reason')->nullable();

                // Định khoản chênh lệch ĐGL qua TK 412
                $table->string('asset_account', 20)->default('211');
                $table->string('revaluation_account', 20)->default('412');
                $table->string('depreciation_account', 20)->default('2141');

                $table->boolean('is_posted')->default(false);
                $table->string('status', 30)->default('draft');
                $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
                $table->json('referenced_vouchers')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('asset_revaluations');
        Schema::dropIfExists('asset_disposals');
        Schema::dropIfExists('asset_depreciation_log_lines');
    }
};
