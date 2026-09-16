<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // ==========================================
        // 1. BANK RECEIPTS (Thu tiền gửi - Báo Có)
        // ==========================================
        if (Schema::hasTable('bank_receipts')) {
            Schema::table('bank_receipts', function (Blueprint $table) {
                if (! Schema::hasColumn('bank_receipts', 'voucher_type')) {
                    $table->string('voucher_type', 150)->nullable()->after('company_id');
                }
                if (! Schema::hasColumn('bank_receipts', 'payer_address')) {
                    $table->string('payer_address', 255)->nullable()->after('payer_name');
                }
                if (! Schema::hasColumn('bank_receipts', 'employee_id')) {
                    $table->unsignedBigInteger('employee_id')->nullable()->after('payer_bank_account');
                }
                if (! Schema::hasColumn('bank_receipts', 'employee_name')) {
                    $table->string('employee_name', 255)->nullable()->after('employee_id');
                }
                if (! Schema::hasColumn('bank_receipts', 'status')) {
                    $table->string('status', 50)->default('posted')->after('amount');
                }
                if (! Schema::hasColumn('bank_receipts', 'referenced_vouchers')) {
                    $table->json('referenced_vouchers')->nullable()->after('description');
                }
                if (! Schema::hasColumn('bank_receipts', 'created_by')) {
                    $table->unsignedBigInteger('created_by')->nullable();
                }
                if (! Schema::hasColumn('bank_receipts', 'updated_by')) {
                    $table->unsignedBigInteger('updated_by')->nullable();
                }
            });
        }

        // ==========================================
        // 2. BANK PAYMENTS (Ủy nhiệm chi / Chi tiền gửi - Báo Nợ)
        // ==========================================
        if (Schema::hasTable('bank_payments')) {
            Schema::table('bank_payments', function (Blueprint $table) {
                if (! Schema::hasColumn('bank_payments', 'voucher_type')) {
                    $table->string('voucher_type', 150)->nullable()->after('company_id');
                }
                if (! Schema::hasColumn('bank_payments', 'payee_address')) {
                    $table->string('payee_address', 255)->nullable()->after('payee_name');
                }
                if (! Schema::hasColumn('bank_payments', 'employee_id')) {
                    $table->unsignedBigInteger('employee_id')->nullable()->after('payee_bank_account');
                }
                if (! Schema::hasColumn('bank_payments', 'employee_name')) {
                    $table->string('employee_name', 255)->nullable()->after('employee_id');
                }
                if (! Schema::hasColumn('bank_payments', 'payee_bank_name')) {
                    $table->string('payee_bank_name', 255)->nullable()->after('employee_name');
                }
                if (! Schema::hasColumn('bank_payments', 'payee_branch')) {
                    $table->string('payee_branch', 255)->nullable()->after('payee_bank_name');
                }
                if (! Schema::hasColumn('bank_payments', 'fee_bearer')) {
                    $table->string('fee_bearer', 50)->nullable()->default('buyer')->after('payee_branch');
                }
                if (! Schema::hasColumn('bank_payments', 'status')) {
                    $table->string('status', 50)->default('posted')->after('amount');
                }
                if (! Schema::hasColumn('bank_payments', 'referenced_vouchers')) {
                    $table->json('referenced_vouchers')->nullable()->after('description');
                }
                if (! Schema::hasColumn('bank_payments', 'created_by')) {
                    $table->unsignedBigInteger('created_by')->nullable();
                }
                if (! Schema::hasColumn('bank_payments', 'updated_by')) {
                    $table->unsignedBigInteger('updated_by')->nullable();
                }
            });
        }

        // ==========================================
        // 3. BANK RECEIPT & PAYMENT LINES
        // ==========================================
        if (Schema::hasTable('bank_receipt_lines')) {
            Schema::table('bank_receipt_lines', function (Blueprint $table) {
                if (! Schema::hasColumn('bank_receipt_lines', 'operation')) {
                    $table->string('operation', 100)->nullable()->after('amount');
                }
                if (! Schema::hasColumn('bank_receipt_lines', 'loan_contract')) {
                    $table->string('loan_contract', 100)->nullable()->after('operation');
                }
                if (! Schema::hasColumn('bank_receipt_lines', 'line_contact_id')) {
                    $table->unsignedBigInteger('line_contact_id')->nullable()->after('loan_contract');
                }
                if (! Schema::hasColumn('bank_receipt_lines', 'line_contact_name')) {
                    $table->string('line_contact_name', 255)->nullable()->after('line_contact_id');
                }
                if (! Schema::hasColumn('bank_receipt_lines', 'bank_account_id')) {
                    $table->unsignedBigInteger('bank_account_id')->nullable()->after('line_contact_name');
                }
            });
        }

        if (Schema::hasTable('bank_payment_lines')) {
            Schema::table('bank_payment_lines', function (Blueprint $table) {
                if (! Schema::hasColumn('bank_payment_lines', 'operation')) {
                    $table->string('operation', 100)->nullable()->after('amount');
                }
                if (! Schema::hasColumn('bank_payment_lines', 'loan_contract')) {
                    $table->string('loan_contract', 100)->nullable()->after('operation');
                }
                if (! Schema::hasColumn('bank_payment_lines', 'line_contact_id')) {
                    $table->unsignedBigInteger('line_contact_id')->nullable()->after('loan_contract');
                }
                if (! Schema::hasColumn('bank_payment_lines', 'line_contact_name')) {
                    $table->string('line_contact_name', 255)->nullable()->after('line_contact_id');
                }
                if (! Schema::hasColumn('bank_payment_lines', 'bank_account_id')) {
                    $table->unsignedBigInteger('bank_account_id')->nullable()->after('line_contact_name');
                }
            });
        }

        // ==========================================
        // 4. PURCHASE INVOICES (Hóa đơn mua hàng)
        // ==========================================
        if (Schema::hasTable('purchase_invoices')) {
            Schema::table('purchase_invoices', function (Blueprint $table) {
                if (! Schema::hasColumn('purchase_invoices', 'employee_id')) {
                    $table->unsignedBigInteger('employee_id')->nullable()->after('deliverer_name');
                }
                if (! Schema::hasColumn('purchase_invoices', 'employee_name')) {
                    $table->string('employee_name', 255)->nullable()->after('employee_id');
                }
                if (! Schema::hasColumn('purchase_invoices', 'referenced_vouchers')) {
                    $table->json('referenced_vouchers')->nullable()->after('description');
                }
                if (! Schema::hasColumn('purchase_invoices', 'created_by')) {
                    $table->unsignedBigInteger('created_by')->nullable();
                }
                if (! Schema::hasColumn('purchase_invoices', 'updated_by')) {
                    $table->unsignedBigInteger('updated_by')->nullable();
                }
            });
        }

        if (Schema::hasTable('purchase_invoice_lines')) {
            Schema::table('purchase_invoice_lines', function (Blueprint $table) {
                if (! Schema::hasColumn('purchase_invoice_lines', 'warehouse_id')) {
                    $table->unsignedBigInteger('warehouse_id')->nullable()->after('warehouse');
                }
                if (! Schema::hasColumn('purchase_invoice_lines', 'warehouse_code')) {
                    $table->string('warehouse_code', 50)->nullable()->after('warehouse_id');
                }
                if (! Schema::hasColumn('purchase_invoice_lines', 'order_id')) {
                    $table->unsignedBigInteger('order_id')->nullable()->after('invoice_date');
                }
                if (! Schema::hasColumn('purchase_invoice_lines', 'contract_id')) {
                    $table->unsignedBigInteger('contract_id')->nullable()->after('order_id');
                }
            });
        }

        // ==========================================
        // 5. SALES INVOICES (Hóa đơn bán hàng)
        // ==========================================
        if (Schema::hasTable('sales_invoices')) {
            Schema::table('sales_invoices', function (Blueprint $table) {
                if (! Schema::hasColumn('sales_invoices', 'voucher_type')) {
                    $table->string('voucher_type', 150)->nullable()->after('company_id');
                }
                if (! Schema::hasColumn('sales_invoices', 'payment_method')) {
                    $table->string('payment_method', 50)->nullable()->after('voucher_type');
                }
                if (! Schema::hasColumn('sales_invoices', 'customer_name')) {
                    $table->string('customer_name', 255)->nullable()->after('customer_id');
                }
                if (! Schema::hasColumn('sales_invoices', 'customer_address')) {
                    $table->string('customer_address', 255)->nullable()->after('customer_name');
                }
                if (! Schema::hasColumn('sales_invoices', 'receiver_name')) {
                    $table->string('receiver_name', 255)->nullable()->after('customer_address');
                }
                if (! Schema::hasColumn('sales_invoices', 'employee_id')) {
                    $table->unsignedBigInteger('employee_id')->nullable()->after('receiver_name');
                }
                if (! Schema::hasColumn('sales_invoices', 'employee_name')) {
                    $table->string('employee_name', 255)->nullable()->after('employee_id');
                }
                if (! Schema::hasColumn('sales_invoices', 'delivery_voucher_number')) {
                    $table->string('delivery_voucher_number', 50)->nullable()->after('invoice_number');
                }
                if (! Schema::hasColumn('sales_invoices', 'invoice_symbol')) {
                    $table->string('invoice_symbol', 50)->nullable()->after('delivery_voucher_number');
                }
                if (! Schema::hasColumn('sales_invoices', 'invoice_code')) {
                    $table->string('invoice_code', 50)->nullable()->after('invoice_symbol');
                }
                if (! Schema::hasColumn('sales_invoices', 'accounting_date')) {
                    $table->date('accounting_date')->nullable()->after('invoice_date');
                }
                if (! Schema::hasColumn('sales_invoices', 'discount_amount')) {
                    $table->decimal('discount_amount', 18, 2)->default(0)->after('sub_total');
                }
                if (! Schema::hasColumn('sales_invoices', 'payment_status')) {
                    $table->string('payment_status', 50)->default('unpaid')->after('status');
                }
                if (! Schema::hasColumn('sales_invoices', 'is_export_slip')) {
                    $table->boolean('is_export_slip')->default(false);
                }
                if (! Schema::hasColumn('sales_invoices', 'referenced_vouchers')) {
                    $table->json('referenced_vouchers')->nullable()->after('description');
                }
                if (! Schema::hasColumn('sales_invoices', 'created_by')) {
                    $table->unsignedBigInteger('created_by')->nullable();
                }
                if (! Schema::hasColumn('sales_invoices', 'updated_by')) {
                    $table->unsignedBigInteger('updated_by')->nullable();
                }
            });
        }

        // ==========================================
        // 6. SALES INVOICE LINES (Chi tiết HĐ Bán hàng kiêm PXK)
        // ==========================================
        if (Schema::hasTable('sales_invoice_lines')) {
            Schema::table('sales_invoice_lines', function (Blueprint $table) {
                if (! Schema::hasColumn('sales_invoice_lines', 'unit')) {
                    $table->string('unit', 50)->nullable()->after('item_id');
                }
                if (! Schema::hasColumn('sales_invoice_lines', 'warehouse_code')) {
                    $table->string('warehouse_code', 50)->nullable()->after('unit');
                }
                if (! Schema::hasColumn('sales_invoice_lines', 'warehouse_id')) {
                    $table->unsignedBigInteger('warehouse_id')->nullable()->after('warehouse_code');
                }
                if (! Schema::hasColumn('sales_invoice_lines', 'cogs_debit_account')) {
                    $table->string('cogs_debit_account', 20)->nullable()->after('credit_account');
                }
                if (! Schema::hasColumn('sales_invoice_lines', 'cogs_credit_account')) {
                    $table->string('cogs_credit_account', 20)->nullable()->after('cogs_debit_account');
                }
                if (! Schema::hasColumn('sales_invoice_lines', 'cogs_unit_price')) {
                    $table->decimal('cogs_unit_price', 15, 2)->default(0)->after('cogs_credit_account');
                }
                if (! Schema::hasColumn('sales_invoice_lines', 'cogs_amount')) {
                    $table->decimal('cogs_amount', 15, 2)->default(0)->after('cogs_unit_price');
                }
                if (! Schema::hasColumn('sales_invoice_lines', 'vat_group')) {
                    $table->string('vat_group', 50)->nullable()->after('tax_account');
                }
                if (! Schema::hasColumn('sales_invoice_lines', 'order_id')) {
                    $table->unsignedBigInteger('order_id')->nullable()->after('cogs_amount');
                }
                if (! Schema::hasColumn('sales_invoice_lines', 'contract_id')) {
                    $table->unsignedBigInteger('contract_id')->nullable()->after('order_id');
                }
            });
        }

        // ==========================================
        // 7. SALES QUOTES (Báo giá bán hàng)
        // ==========================================
        if (! Schema::hasTable('sales_quotes')) {
            Schema::create('sales_quotes', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id')->default(1);
                $table->string('quote_number', 50)->unique();
                $table->date('quote_date');
                $table->date('expiry_date')->nullable();
                $table->unsignedBigInteger('customer_id')->nullable();
                $table->string('customer_code', 50)->nullable();
                $table->string('customer_name', 255)->nullable();
                $table->string('customer_address', 255)->nullable();
                $table->string('tax_code', 50)->nullable();
                $table->string('contact_person', 255)->nullable();
                $table->string('contact_phone', 50)->nullable();
                $table->string('contact_email', 100)->nullable();
                $table->unsignedBigInteger('employee_id')->nullable();
                $table->string('employee_name', 255)->nullable();
                $table->string('payment_terms', 255)->nullable();
                $table->text('delivery_address')->nullable();
                $table->text('delivery_terms')->nullable();
                $table->text('description')->nullable();
                $table->decimal('sub_total', 18, 2)->default(0);
                $table->decimal('discount_amount', 18, 2)->default(0);
                $table->decimal('vat_amount', 18, 2)->default(0);
                $table->decimal('total_amount', 18, 2)->default(0);
                $table->decimal('grand_total', 18, 2)->default(0);
                $table->string('status', 50)->default('draft'); // draft, sent, approved, ordered, rejected, cancelled
                $table->string('currency', 3)->default('VND');
                $table->decimal('exchange_rate', 10, 4)->default(1);
                $table->text('terms_and_conditions')->nullable();
                $table->json('referenced_vouchers')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->softDeletes();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('sales_quote_lines')) {
            Schema::create('sales_quote_lines', function (Blueprint $table) {
                $table->id();
                $table->foreignId('sales_quote_id')->constrained('sales_quotes')->cascadeOnDelete();
                $table->unsignedBigInteger('item_id')->nullable();
                $table->string('item_code', 50)->nullable();
                $table->string('item_name', 255)->nullable();
                $table->string('unit', 50)->nullable();
                $table->unsignedBigInteger('warehouse_id')->nullable();
                $table->string('warehouse_code', 50)->nullable();
                $table->decimal('quantity', 14, 2)->default(1);
                $table->decimal('unit_price', 18, 2)->default(0);
                $table->decimal('amount', 18, 2)->default(0);
                $table->decimal('discount_rate', 5, 2)->default(0);
                $table->decimal('discount_amount', 18, 2)->default(0);
                $table->decimal('tax_rate', 5, 2)->default(10);
                $table->decimal('tax_amount', 18, 2)->default(0);
                $table->decimal('total_amount', 18, 2)->default(0);
                $table->text('description')->nullable();
                $table->text('note')->nullable();
                $table->timestamps();
            });
        }

        // ==========================================
        // 8. SALES ORDERS (Đơn đặt hàng bán)
        // ==========================================
        if (! Schema::hasTable('sales_orders')) {
            Schema::create('sales_orders', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id')->default(1);
                $table->unsignedBigInteger('quote_id')->nullable();
                $table->unsignedBigInteger('sales_quote_id')->nullable();
                $table->string('order_number', 50)->unique();
                $table->date('order_date');
                $table->date('delivery_date')->nullable();
                $table->unsignedBigInteger('customer_id')->nullable();
                $table->string('customer_code', 50)->nullable();
                $table->string('customer_name', 255)->nullable();
                $table->string('customer_address', 255)->nullable();
                $table->string('tax_code', 50)->nullable();
                $table->string('contact_person', 255)->nullable();
                $table->string('contact_phone', 50)->nullable();
                $table->string('contact_email', 100)->nullable();
                $table->unsignedBigInteger('employee_id')->nullable();
                $table->string('employee_name', 255)->nullable();
                $table->string('payment_terms', 255)->nullable();
                $table->integer('due_days')->default(30);
                $table->text('delivery_address')->nullable();
                $table->text('other_terms')->nullable();
                $table->text('description')->nullable();
                $table->decimal('sub_total', 18, 2)->default(0);
                $table->decimal('discount_amount', 18, 2)->default(0);
                $table->decimal('vat_amount', 18, 2)->default(0);
                $table->decimal('total_amount', 18, 2)->default(0);
                $table->decimal('grand_total', 18, 2)->default(0);
                $table->string('status', 50)->default('pending'); // pending, confirmed, processing, delivering, completed, cancelled
                $table->string('delivery_status', 50)->default('not_delivered'); // not_delivered, partial, delivered
                $table->string('invoice_status', 50)->default('not_invoiced'); // not_invoiced, partial, invoiced
                $table->string('invoiced_status', 50)->default('not_invoiced');
                $table->string('currency', 3)->default('VND');
                $table->decimal('exchange_rate', 10, 4)->default(1);
                $table->json('referenced_vouchers')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->softDeletes();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('sales_order_lines')) {
            Schema::create('sales_order_lines', function (Blueprint $table) {
                $table->id();
                $table->foreignId('sales_order_id')->constrained('sales_orders')->cascadeOnDelete();
                $table->unsignedBigInteger('item_id')->nullable();
                $table->string('item_code', 50)->nullable();
                $table->string('item_name', 255)->nullable();
                $table->string('unit', 50)->nullable();
                $table->unsignedBigInteger('warehouse_id')->nullable();
                $table->string('warehouse_code', 50)->nullable();
                $table->decimal('quantity', 14, 2)->default(1);
                $table->decimal('delivered_quantity', 14, 2)->default(0);
                $table->decimal('invoiced_quantity', 14, 2)->default(0);
                $table->decimal('unit_price', 18, 2)->default(0);
                $table->decimal('amount', 18, 2)->default(0);
                $table->decimal('discount_rate', 5, 2)->default(0);
                $table->decimal('discount_amount', 18, 2)->default(0);
                $table->decimal('tax_rate', 5, 2)->default(10);
                $table->decimal('tax_amount', 18, 2)->default(0);
                $table->decimal('total_amount', 18, 2)->default(0);
                $table->date('delivery_date')->nullable();
                $table->text('description')->nullable();
                $table->text('note')->nullable();
                $table->timestamps();
            });
        }

        // ==========================================
        // 9. INVENTORY RECEIPTS & ISSUES (Kho)
        // ==========================================
        if (Schema::hasTable('inventory_receipts')) {
            Schema::table('inventory_receipts', function (Blueprint $table) {
                if (! Schema::hasColumn('inventory_receipts', 'voucher_type')) {
                    $table->string('voucher_type', 150)->nullable()->after('company_id');
                }
                if (! Schema::hasColumn('inventory_receipts', 'receiver_address')) {
                    $table->string('receiver_address', 255)->nullable()->after('deliverer_name');
                }
                if (! Schema::hasColumn('inventory_receipts', 'employee_id')) {
                    $table->unsignedBigInteger('employee_id')->nullable()->after('receiver_address');
                }
                if (! Schema::hasColumn('inventory_receipts', 'employee_name')) {
                    $table->string('employee_name', 255)->nullable()->after('employee_id');
                }
                if (! Schema::hasColumn('inventory_receipts', 'warehouse_id')) {
                    $table->unsignedBigInteger('warehouse_id')->nullable()->after('employee_name');
                }
                if (! Schema::hasColumn('inventory_receipts', 'status')) {
                    $table->string('status', 50)->default('completed')->after('total_amount');
                }
                if (! Schema::hasColumn('inventory_receipts', 'journal_entry_id')) {
                    $table->unsignedBigInteger('journal_entry_id')->nullable()->after('status');
                }
                if (! Schema::hasColumn('inventory_receipts', 'referenced_vouchers')) {
                    $table->json('referenced_vouchers')->nullable()->after('description');
                }
                if (! Schema::hasColumn('inventory_receipts', 'created_by')) {
                    $table->unsignedBigInteger('created_by')->nullable();
                }
                if (! Schema::hasColumn('inventory_receipts', 'updated_by')) {
                    $table->unsignedBigInteger('updated_by')->nullable();
                }
            });
        }

        if (Schema::hasTable('inventory_issues')) {
            Schema::table('inventory_issues', function (Blueprint $table) {
                if (! Schema::hasColumn('inventory_issues', 'voucher_type')) {
                    $table->string('voucher_type', 150)->nullable()->after('company_id');
                }
                if (! Schema::hasColumn('inventory_issues', 'receiver_address')) {
                    $table->string('receiver_address', 255)->nullable()->after('receiver_name');
                }
                if (! Schema::hasColumn('inventory_issues', 'employee_id')) {
                    $table->unsignedBigInteger('employee_id')->nullable()->after('receiver_address');
                }
                if (! Schema::hasColumn('inventory_issues', 'employee_name')) {
                    $table->string('employee_name', 255)->nullable()->after('employee_id');
                }
                if (! Schema::hasColumn('inventory_issues', 'warehouse_id')) {
                    $table->unsignedBigInteger('warehouse_id')->nullable()->after('employee_name');
                }
                if (! Schema::hasColumn('inventory_issues', 'status')) {
                    $table->string('status', 50)->default('completed')->after('total_amount');
                }
                if (! Schema::hasColumn('inventory_issues', 'journal_entry_id')) {
                    $table->unsignedBigInteger('journal_entry_id')->nullable()->after('status');
                }
                if (! Schema::hasColumn('inventory_issues', 'referenced_vouchers')) {
                    $table->json('referenced_vouchers')->nullable()->after('description');
                }
                if (! Schema::hasColumn('inventory_issues', 'created_by')) {
                    $table->unsignedBigInteger('created_by')->nullable();
                }
                if (! Schema::hasColumn('inventory_issues', 'updated_by')) {
                    $table->unsignedBigInteger('updated_by')->nullable();
                }
            });
        }

        // ==========================================
        // 10. INVENTORY RECEIPT & ISSUE LINES
        // ==========================================
        if (Schema::hasTable('inventory_receipt_lines')) {
            Schema::table('inventory_receipt_lines', function (Blueprint $table) {
                if (! Schema::hasColumn('inventory_receipt_lines', 'warehouse_code')) {
                    $table->string('warehouse_code', 50)->nullable()->after('item_id');
                }
                if (! Schema::hasColumn('inventory_receipt_lines', 'warehouse_id')) {
                    $table->unsignedBigInteger('warehouse_id')->nullable()->after('warehouse_code');
                }
                if (! Schema::hasColumn('inventory_receipt_lines', 'unit')) {
                    $table->string('unit', 50)->nullable()->after('warehouse_id');
                }
                if (! Schema::hasColumn('inventory_receipt_lines', 'description')) {
                    $table->text('description')->nullable()->after('unit');
                }
            });
        }

        if (Schema::hasTable('inventory_issue_lines')) {
            Schema::table('inventory_issue_lines', function (Blueprint $table) {
                if (! Schema::hasColumn('inventory_issue_lines', 'warehouse_code')) {
                    $table->string('warehouse_code', 50)->nullable()->after('item_id');
                }
                if (! Schema::hasColumn('inventory_issue_lines', 'warehouse_id')) {
                    $table->unsignedBigInteger('warehouse_id')->nullable()->after('warehouse_code');
                }
                if (! Schema::hasColumn('inventory_issue_lines', 'unit')) {
                    $table->string('unit', 50)->nullable()->after('warehouse_id');
                }
                if (! Schema::hasColumn('inventory_issue_lines', 'description')) {
                    $table->text('description')->nullable()->after('unit');
                }
            });
        }

        // ==========================================
        // 11. JOURNAL ENTRY & LINES (Tổng hợp)
        // ==========================================
        if (Schema::hasTable('journal_entries')) {
            Schema::table('journal_entries', function (Blueprint $table) {
                if (! Schema::hasColumn('journal_entries', 'referenced_vouchers')) {
                    $table->json('referenced_vouchers')->nullable()->after('description');
                }
                if (! Schema::hasColumn('journal_entries', 'attached_docs')) {
                    $table->string('attached_docs', 255)->nullable()->after('referenced_vouchers');
                }
                if (! Schema::hasColumn('journal_entries', 'currency')) {
                    $table->string('currency', 3)->default('VND')->after('attached_docs');
                }
                if (! Schema::hasColumn('journal_entries', 'exchange_rate')) {
                    $table->decimal('exchange_rate', 15, 2)->default(1)->after('currency');
                }
            });
        }

        if (Schema::hasTable('journal_entry_lines')) {
            Schema::table('journal_entry_lines', function (Blueprint $table) {
                if (! Schema::hasColumn('journal_entry_lines', 'contact_type')) {
                    $table->string('contact_type', 50)->nullable()->after('sub_object_id');
                }
                if (! Schema::hasColumn('journal_entry_lines', 'contact_id')) {
                    $table->unsignedBigInteger('contact_id')->nullable()->after('contact_type');
                }
                if (! Schema::hasColumn('journal_entry_lines', 'contact_name')) {
                    $table->string('contact_name', 255)->nullable()->after('contact_id');
                }
                if (! Schema::hasColumn('journal_entry_lines', 'cost_item_code')) {
                    $table->string('cost_item_code', 50)->nullable()->after('contact_name');
                }
                if (! Schema::hasColumn('journal_entry_lines', 'cost_object_code')) {
                    $table->string('cost_object_code', 50)->nullable()->after('cost_item_code');
                }
                if (! Schema::hasColumn('journal_entry_lines', 'bank_account_id')) {
                    $table->unsignedBigInteger('bank_account_id')->nullable()->after('cost_object_code');
                }
            });
        }

        // ==========================================
        // 12. CLOSING RULES (Quy tắc kết chuyển cuối kỳ)
        // ==========================================
        if (! Schema::hasTable('closing_rules')) {
            Schema::create('closing_rules', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id')->default(1);
                $table->string('rule_code', 50)->unique();
                $table->string('rule_name', 255);
                $table->string('rule_type', 50)->default('revenue'); // revenue, expense, result
                $table->string('debit_account', 20);
                $table->string('credit_account', 20);
                $table->string('source_account', 20)->nullable();
                $table->string('target_account', 20)->nullable();
                $table->string('closing_side', 20)->default('both'); // debit, credit, both
                $table->string('transfer_type', 50)->default('turnover'); // turnover, balance_debit, balance_credit, formula
                $table->integer('sequence')->default(10);
                $table->boolean('is_active')->default(true);
                $table->text('description')->nullable();
                $table->timestamps();
            });

            // Seed Standard TT200 / TT133 Period Closing Rules
            DB::table('closing_rules')->insert([
                [
                    'company_id' => 1,
                    'rule_code' => 'KC_REV_511_911',
                    'rule_name' => 'Kết chuyển Doanh thu bán hàng và cung cấp dịch vụ',
                    'rule_type' => 'revenue',
                    'debit_account' => '511',
                    'credit_account' => '911',
                    'source_account' => '511',
                    'target_account' => '911',
                    'closing_side' => 'credit',
                    'transfer_type' => 'turnover',
                    'sequence' => 10,
                    'is_active' => true,
                    'description' => 'Kết chuyển số phát sinh có TK 511 sang TK 911',
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'company_id' => 1,
                    'rule_code' => 'KC_REV_515_911',
                    'rule_name' => 'Kết chuyển Doanh thu hoạt động tài chính',
                    'rule_type' => 'revenue',
                    'debit_account' => '515',
                    'credit_account' => '911',
                    'source_account' => '515',
                    'target_account' => '911',
                    'closing_side' => 'credit',
                    'transfer_type' => 'turnover',
                    'sequence' => 20,
                    'is_active' => true,
                    'description' => 'Kết chuyển số phát sinh có TK 515 sang TK 911',
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'company_id' => 1,
                    'rule_code' => 'KC_REV_711_911',
                    'rule_name' => 'Kết chuyển Thu nhập khác',
                    'rule_type' => 'revenue',
                    'debit_account' => '711',
                    'credit_account' => '911',
                    'source_account' => '711',
                    'target_account' => '911',
                    'closing_side' => 'credit',
                    'transfer_type' => 'turnover',
                    'sequence' => 30,
                    'is_active' => true,
                    'description' => 'Kết chuyển số phát sinh có TK 711 sang TK 911',
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'company_id' => 1,
                    'rule_code' => 'KC_EXP_632_911',
                    'rule_name' => 'Kết chuyển Giá vốn hàng bán',
                    'rule_type' => 'expense',
                    'debit_account' => '911',
                    'credit_account' => '632',
                    'source_account' => '632',
                    'target_account' => '911',
                    'closing_side' => 'debit',
                    'transfer_type' => 'turnover',
                    'sequence' => 40,
                    'is_active' => true,
                    'description' => 'Kết chuyển số phát sinh nợ TK 632 sang TK 911',
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'company_id' => 1,
                    'rule_code' => 'KC_EXP_635_911',
                    'rule_name' => 'Kết chuyển Chi phí tài chính',
                    'rule_type' => 'expense',
                    'debit_account' => '911',
                    'credit_account' => '635',
                    'source_account' => '635',
                    'target_account' => '911',
                    'closing_side' => 'debit',
                    'transfer_type' => 'turnover',
                    'sequence' => 50,
                    'is_active' => true,
                    'description' => 'Kết chuyển số phát sinh nợ TK 635 sang TK 911',
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'company_id' => 1,
                    'rule_code' => 'KC_EXP_641_911',
                    'rule_name' => 'Kết chuyển Chi phí bán hàng',
                    'rule_type' => 'expense',
                    'debit_account' => '911',
                    'credit_account' => '641',
                    'source_account' => '641',
                    'target_account' => '911',
                    'closing_side' => 'debit',
                    'transfer_type' => 'turnover',
                    'sequence' => 60,
                    'is_active' => true,
                    'description' => 'Kết chuyển số phát sinh nợ TK 641 sang TK 911',
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'company_id' => 1,
                    'rule_code' => 'KC_EXP_642_911',
                    'rule_name' => 'Kết chuyển Chi phí quản lý doanh nghiệp',
                    'rule_type' => 'expense',
                    'debit_account' => '911',
                    'credit_account' => '642',
                    'source_account' => '642',
                    'target_account' => '911',
                    'closing_side' => 'debit',
                    'transfer_type' => 'turnover',
                    'sequence' => 70,
                    'is_active' => true,
                    'description' => 'Kết chuyển số phát sinh nợ TK 642 sang TK 911',
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'company_id' => 1,
                    'rule_code' => 'KC_EXP_811_911',
                    'rule_name' => 'Kết chuyển Chi phí khác',
                    'rule_type' => 'expense',
                    'debit_account' => '911',
                    'credit_account' => '811',
                    'source_account' => '811',
                    'target_account' => '911',
                    'closing_side' => 'debit',
                    'transfer_type' => 'turnover',
                    'sequence' => 80,
                    'is_active' => true,
                    'description' => 'Kết chuyển số phát sinh nợ TK 811 sang TK 911',
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'company_id' => 1,
                    'rule_code' => 'KC_EXP_821_911',
                    'rule_name' => 'Kết chuyển Chi phí thuế TNDN',
                    'rule_type' => 'expense',
                    'debit_account' => '911',
                    'credit_account' => '821',
                    'source_account' => '821',
                    'target_account' => '911',
                    'closing_side' => 'debit',
                    'transfer_type' => 'turnover',
                    'sequence' => 90,
                    'is_active' => true,
                    'description' => 'Kết chuyển số phát sinh nợ TK 821 sang TK 911',
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'company_id' => 1,
                    'rule_code' => 'KC_RES_PROFIT_911_4212',
                    'rule_name' => 'Kết chuyển Lãi năm nay',
                    'rule_type' => 'result',
                    'debit_account' => '911',
                    'credit_account' => '4212',
                    'source_account' => '911',
                    'target_account' => '4212',
                    'closing_side' => 'debit',
                    'transfer_type' => 'balance_credit',
                    'sequence' => 100,
                    'is_active' => true,
                    'description' => 'Kết chuyển Lãi thuần sau thuế (911 -> 4212)',
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'company_id' => 1,
                    'rule_code' => 'KC_RES_LOSS_4212_911',
                    'rule_name' => 'Kết chuyển Lỗ năm nay',
                    'rule_type' => 'result',
                    'debit_account' => '4212',
                    'credit_account' => '911',
                    'source_account' => '911',
                    'target_account' => '4212',
                    'closing_side' => 'credit',
                    'transfer_type' => 'balance_debit',
                    'sequence' => 110,
                    'is_active' => true,
                    'description' => 'Kết chuyển Lỗ thuần sau thuế (4212 -> 911)',
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('closing_rules');
        Schema::dropIfExists('sales_order_lines');
        Schema::dropIfExists('sales_orders');
        Schema::dropIfExists('sales_quote_lines');
        Schema::dropIfExists('sales_quotes');

        if (Schema::hasTable('journal_entry_lines')) {
            Schema::table('journal_entry_lines', function (Blueprint $table) {
                $table->dropColumn(array_values(array_filter([
                    Schema::hasColumn('journal_entry_lines', 'contact_type') ? 'contact_type' : null,
                    Schema::hasColumn('journal_entry_lines', 'contact_id') ? 'contact_id' : null,
                    Schema::hasColumn('journal_entry_lines', 'contact_name') ? 'contact_name' : null,
                    Schema::hasColumn('journal_entry_lines', 'cost_item_code') ? 'cost_item_code' : null,
                    Schema::hasColumn('journal_entry_lines', 'cost_object_code') ? 'cost_object_code' : null,
                    Schema::hasColumn('journal_entry_lines', 'bank_account_id') ? 'bank_account_id' : null,
                ])));
            });
        }

        if (Schema::hasTable('journal_entries')) {
            Schema::table('journal_entries', function (Blueprint $table) {
                $table->dropColumn(array_values(array_filter([
                    Schema::hasColumn('journal_entries', 'referenced_vouchers') ? 'referenced_vouchers' : null,
                    Schema::hasColumn('journal_entries', 'attached_docs') ? 'attached_docs' : null,
                    Schema::hasColumn('journal_entries', 'currency') ? 'currency' : null,
                    Schema::hasColumn('journal_entries', 'exchange_rate') ? 'exchange_rate' : null,
                ])));
            });
        }

        if (Schema::hasTable('inventory_issue_lines')) {
            Schema::table('inventory_issue_lines', function (Blueprint $table) {
                $table->dropColumn(array_values(array_filter([
                    Schema::hasColumn('inventory_issue_lines', 'warehouse_code') ? 'warehouse_code' : null,
                    Schema::hasColumn('inventory_issue_lines', 'warehouse_id') ? 'warehouse_id' : null,
                    Schema::hasColumn('inventory_issue_lines', 'unit') ? 'unit' : null,
                    Schema::hasColumn('inventory_issue_lines', 'description') ? 'description' : null,
                ])));
            });
        }

        if (Schema::hasTable('inventory_receipt_lines')) {
            Schema::table('inventory_receipt_lines', function (Blueprint $table) {
                $table->dropColumn(array_values(array_filter([
                    Schema::hasColumn('inventory_receipt_lines', 'warehouse_code') ? 'warehouse_code' : null,
                    Schema::hasColumn('inventory_receipt_lines', 'warehouse_id') ? 'warehouse_id' : null,
                    Schema::hasColumn('inventory_receipt_lines', 'unit') ? 'unit' : null,
                    Schema::hasColumn('inventory_receipt_lines', 'description') ? 'description' : null,
                ])));
            });
        }

        if (Schema::hasTable('inventory_issues')) {
            Schema::table('inventory_issues', function (Blueprint $table) {
                $table->dropColumn(array_values(array_filter([
                    Schema::hasColumn('inventory_issues', 'voucher_type') ? 'voucher_type' : null,
                    Schema::hasColumn('inventory_issues', 'receiver_address') ? 'receiver_address' : null,
                    Schema::hasColumn('inventory_issues', 'employee_id') ? 'employee_id' : null,
                    Schema::hasColumn('inventory_issues', 'employee_name') ? 'employee_name' : null,
                    Schema::hasColumn('inventory_issues', 'warehouse_id') ? 'warehouse_id' : null,
                    Schema::hasColumn('inventory_issues', 'status') ? 'status' : null,
                    Schema::hasColumn('inventory_issues', 'journal_entry_id') ? 'journal_entry_id' : null,
                    Schema::hasColumn('inventory_issues', 'referenced_vouchers') ? 'referenced_vouchers' : null,
                    Schema::hasColumn('inventory_issues', 'created_by') ? 'created_by' : null,
                    Schema::hasColumn('inventory_issues', 'updated_by') ? 'updated_by' : null,
                ])));
            });
        }

        if (Schema::hasTable('inventory_receipts')) {
            Schema::table('inventory_receipts', function (Blueprint $table) {
                $table->dropColumn(array_values(array_filter([
                    Schema::hasColumn('inventory_receipts', 'voucher_type') ? 'voucher_type' : null,
                    Schema::hasColumn('inventory_receipts', 'receiver_address') ? 'receiver_address' : null,
                    Schema::hasColumn('inventory_receipts', 'employee_id') ? 'employee_id' : null,
                    Schema::hasColumn('inventory_receipts', 'employee_name') ? 'employee_name' : null,
                    Schema::hasColumn('inventory_receipts', 'warehouse_id') ? 'warehouse_id' : null,
                    Schema::hasColumn('inventory_receipts', 'status') ? 'status' : null,
                    Schema::hasColumn('inventory_receipts', 'journal_entry_id') ? 'journal_entry_id' : null,
                    Schema::hasColumn('inventory_receipts', 'referenced_vouchers') ? 'referenced_vouchers' : null,
                    Schema::hasColumn('inventory_receipts', 'created_by') ? 'created_by' : null,
                    Schema::hasColumn('inventory_receipts', 'updated_by') ? 'updated_by' : null,
                ])));
            });
        }

        if (Schema::hasTable('sales_invoice_lines')) {
            Schema::table('sales_invoice_lines', function (Blueprint $table) {
                $table->dropColumn(array_values(array_filter([
                    Schema::hasColumn('sales_invoice_lines', 'unit') ? 'unit' : null,
                    Schema::hasColumn('sales_invoice_lines', 'warehouse_code') ? 'warehouse_code' : null,
                    Schema::hasColumn('sales_invoice_lines', 'warehouse_id') ? 'warehouse_id' : null,
                    Schema::hasColumn('sales_invoice_lines', 'cogs_debit_account') ? 'cogs_debit_account' : null,
                    Schema::hasColumn('sales_invoice_lines', 'cogs_credit_account') ? 'cogs_credit_account' : null,
                    Schema::hasColumn('sales_invoice_lines', 'cogs_unit_price') ? 'cogs_unit_price' : null,
                    Schema::hasColumn('sales_invoice_lines', 'cogs_amount') ? 'cogs_amount' : null,
                    Schema::hasColumn('sales_invoice_lines', 'vat_group') ? 'vat_group' : null,
                    Schema::hasColumn('sales_invoice_lines', 'order_id') ? 'order_id' : null,
                    Schema::hasColumn('sales_invoice_lines', 'contract_id') ? 'contract_id' : null,
                ])));
            });
        }

        if (Schema::hasTable('sales_invoices')) {
            Schema::table('sales_invoices', function (Blueprint $table) {
                $table->dropColumn(array_values(array_filter([
                    Schema::hasColumn('sales_invoices', 'voucher_type') ? 'voucher_type' : null,
                    Schema::hasColumn('sales_invoices', 'payment_method') ? 'payment_method' : null,
                    Schema::hasColumn('sales_invoices', 'customer_name') ? 'customer_name' : null,
                    Schema::hasColumn('sales_invoices', 'customer_address') ? 'customer_address' : null,
                    Schema::hasColumn('sales_invoices', 'receiver_name') ? 'receiver_name' : null,
                    Schema::hasColumn('sales_invoices', 'employee_id') ? 'employee_id' : null,
                    Schema::hasColumn('sales_invoices', 'employee_name') ? 'employee_name' : null,
                    Schema::hasColumn('sales_invoices', 'delivery_voucher_number') ? 'delivery_voucher_number' : null,
                    Schema::hasColumn('sales_invoices', 'invoice_symbol') ? 'invoice_symbol' : null,
                    Schema::hasColumn('sales_invoices', 'invoice_code') ? 'invoice_code' : null,
                    Schema::hasColumn('sales_invoices', 'accounting_date') ? 'accounting_date' : null,
                    Schema::hasColumn('sales_invoices', 'discount_amount') ? 'discount_amount' : null,
                    Schema::hasColumn('sales_invoices', 'payment_status') ? 'payment_status' : null,
                    Schema::hasColumn('sales_invoices', 'is_export_slip') ? 'is_export_slip' : null,
                    Schema::hasColumn('sales_invoices', 'referenced_vouchers') ? 'referenced_vouchers' : null,
                    Schema::hasColumn('sales_invoices', 'created_by') ? 'created_by' : null,
                    Schema::hasColumn('sales_invoices', 'updated_by') ? 'updated_by' : null,
                ])));
            });
        }

        if (Schema::hasTable('purchase_invoice_lines')) {
            Schema::table('purchase_invoice_lines', function (Blueprint $table) {
                $table->dropColumn(array_values(array_filter([
                    Schema::hasColumn('purchase_invoice_lines', 'warehouse_id') ? 'warehouse_id' : null,
                    Schema::hasColumn('purchase_invoice_lines', 'warehouse_code') ? 'warehouse_code' : null,
                    Schema::hasColumn('purchase_invoice_lines', 'order_id') ? 'order_id' : null,
                    Schema::hasColumn('purchase_invoice_lines', 'contract_id') ? 'contract_id' : null,
                ])));
            });
        }

        if (Schema::hasTable('purchase_invoices')) {
            Schema::table('purchase_invoices', function (Blueprint $table) {
                $table->dropColumn(array_values(array_filter([
                    Schema::hasColumn('purchase_invoices', 'employee_id') ? 'employee_id' : null,
                    Schema::hasColumn('purchase_invoices', 'employee_name') ? 'employee_name' : null,
                    Schema::hasColumn('purchase_invoices', 'referenced_vouchers') ? 'referenced_vouchers' : null,
                    Schema::hasColumn('purchase_invoices', 'created_by') ? 'created_by' : null,
                    Schema::hasColumn('purchase_invoices', 'updated_by') ? 'updated_by' : null,
                ])));
            });
        }

        if (Schema::hasTable('bank_payment_lines')) {
            Schema::table('bank_payment_lines', function (Blueprint $table) {
                $table->dropColumn(array_values(array_filter([
                    Schema::hasColumn('bank_payment_lines', 'operation') ? 'operation' : null,
                    Schema::hasColumn('bank_payment_lines', 'loan_contract') ? 'loan_contract' : null,
                    Schema::hasColumn('bank_payment_lines', 'line_contact_id') ? 'line_contact_id' : null,
                    Schema::hasColumn('bank_payment_lines', 'line_contact_name') ? 'line_contact_name' : null,
                    Schema::hasColumn('bank_payment_lines', 'bank_account_id') ? 'bank_account_id' : null,
                ])));
            });
        }

        if (Schema::hasTable('bank_payments')) {
            Schema::table('bank_payments', function (Blueprint $table) {
                $table->dropColumn(array_values(array_filter([
                    Schema::hasColumn('bank_payments', 'voucher_type') ? 'voucher_type' : null,
                    Schema::hasColumn('bank_payments', 'payee_address') ? 'payee_address' : null,
                    Schema::hasColumn('bank_payments', 'employee_id') ? 'employee_id' : null,
                    Schema::hasColumn('bank_payments', 'employee_name') ? 'employee_name' : null,
                    Schema::hasColumn('bank_payments', 'payee_bank_name') ? 'payee_bank_name' : null,
                    Schema::hasColumn('bank_payments', 'payee_branch') ? 'payee_branch' : null,
                    Schema::hasColumn('bank_payments', 'fee_bearer') ? 'fee_bearer' : null,
                    Schema::hasColumn('bank_payments', 'status') ? 'status' : null,
                    Schema::hasColumn('bank_payments', 'referenced_vouchers') ? 'referenced_vouchers' : null,
                    Schema::hasColumn('bank_payments', 'created_by') ? 'created_by' : null,
                    Schema::hasColumn('bank_payments', 'updated_by') ? 'updated_by' : null,
                ])));
            });
        }

        if (Schema::hasTable('bank_receipt_lines')) {
            Schema::table('bank_receipt_lines', function (Blueprint $table) {
                $table->dropColumn(array_values(array_filter([
                    Schema::hasColumn('bank_receipt_lines', 'operation') ? 'operation' : null,
                    Schema::hasColumn('bank_receipt_lines', 'loan_contract') ? 'loan_contract' : null,
                    Schema::hasColumn('bank_receipt_lines', 'line_contact_id') ? 'line_contact_id' : null,
                    Schema::hasColumn('bank_receipt_lines', 'line_contact_name') ? 'line_contact_name' : null,
                    Schema::hasColumn('bank_receipt_lines', 'bank_account_id') ? 'bank_account_id' : null,
                ])));
            });
        }

        if (Schema::hasTable('bank_receipts')) {
            Schema::table('bank_receipts', function (Blueprint $table) {
                $table->dropColumn(array_values(array_filter([
                    Schema::hasColumn('bank_receipts', 'voucher_type') ? 'voucher_type' : null,
                    Schema::hasColumn('bank_receipts', 'payer_address') ? 'payer_address' : null,
                    Schema::hasColumn('bank_receipts', 'employee_id') ? 'employee_id' : null,
                    Schema::hasColumn('bank_receipts', 'employee_name') ? 'employee_name' : null,
                    Schema::hasColumn('bank_receipts', 'status') ? 'status' : null,
                    Schema::hasColumn('bank_receipts', 'referenced_vouchers') ? 'referenced_vouchers' : null,
                    Schema::hasColumn('bank_receipts', 'created_by') ? 'created_by' : null,
                    Schema::hasColumn('bank_receipts', 'updated_by') ? 'updated_by' : null,
                ])));
            });
        }
    }
};
