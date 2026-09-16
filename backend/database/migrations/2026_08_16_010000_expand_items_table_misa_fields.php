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
        Schema::table('items', function (Blueprint $table) {
            if (!Schema::hasColumn('items', 'item_type')) {
                $table->string('item_type', 30)->default('goods')->after('type'); // goods, service, material, product, tool, combo
            }
            if (!Schema::hasColumn('items', 'category_code')) {
                $table->string('category_code', 50)->nullable()->after('name');
            }
            if (!Schema::hasColumn('items', 'category_name')) {
                $table->string('category_name', 150)->nullable()->after('category_code');
            }
            if (!Schema::hasColumn('items', 'image_url')) {
                $table->string('image_url', 255)->nullable()->after('unit');
            }
            if (!Schema::hasColumn('items', 'warranty_period')) {
                $table->integer('warranty_period')->default(0)->after('image_url');
            }
            if (!Schema::hasColumn('items', 'warranty_unit')) {
                $table->string('warranty_unit', 20)->default('month')->after('warranty_period');
            }
            if (!Schema::hasColumn('items', 'warehouse_location')) {
                $table->string('warehouse_location', 100)->nullable()->after('warranty_unit');
            }
            if (!Schema::hasColumn('items', 'minimum_stock')) {
                $table->decimal('minimum_stock', 15, 2)->default(0)->after('warehouse_location');
            }
            if (!Schema::hasColumn('items', 'origin')) {
                $table->string('origin', 100)->nullable()->after('minimum_stock');
            }
            if (!Schema::hasColumn('items', 'purchase_description')) {
                $table->string('purchase_description', 255)->nullable()->after('description');
            }
            if (!Schema::hasColumn('items', 'sale_description')) {
                $table->string('sale_description', 255)->nullable()->after('purchase_description');
            }
            if (!Schema::hasColumn('items', 'special_feature_type')) {
                $table->string('special_feature_type', 50)->nullable()->after('sale_description');
            }

            // Defaults & Accounts
            if (!Schema::hasColumn('items', 'default_warehouse')) {
                $table->string('default_warehouse', 50)->nullable()->after('special_feature_type');
            }
            if (!Schema::hasColumn('items', 'inventory_account')) {
                $table->string('inventory_account', 20)->default('156')->after('default_warehouse');
            }
            if (!Schema::hasColumn('items', 'revenue_account')) {
                $table->string('revenue_account', 20)->default('5111')->after('inventory_account');
            }
            if (!Schema::hasColumn('items', 'discount_account')) {
                $table->string('discount_account', 20)->default('5111')->after('revenue_account');
            }
            if (!Schema::hasColumn('items', 'rebate_account')) {
                $table->string('rebate_account', 20)->default('5111')->after('discount_account');
            }
            if (!Schema::hasColumn('items', 'return_account')) {
                $table->string('return_account', 20)->default('5111')->after('rebate_account');
            }
            if (!Schema::hasColumn('items', 'cost_account')) {
                $table->string('cost_account', 20)->default('632')->after('return_account');
            }
            if (!Schema::hasColumn('items', 'purchase_discount_rate')) {
                $table->decimal('purchase_discount_rate', 5, 2)->default(0)->after('cost_account');
            }
            if (!Schema::hasColumn('items', 'fixed_purchase_price')) {
                $table->decimal('fixed_purchase_price', 18, 2)->default(0)->after('purchase_discount_rate');
            }
            if (!Schema::hasColumn('items', 'latest_purchase_price')) {
                $table->decimal('latest_purchase_price', 18, 2)->default(0)->after('fixed_purchase_price');
            }
            if (!Schema::hasColumn('items', 'sale_price')) {
                $table->decimal('sale_price', 18, 2)->default(0)->after('latest_purchase_price');
            }
            if (!Schema::hasColumn('items', 'vat_rate')) {
                $table->string('vat_rate', 20)->default('10')->after('sale_price');
            }
            if (!Schema::hasColumn('items', 'import_tax_rate')) {
                $table->decimal('import_tax_rate', 5, 2)->default(0)->after('vat_rate');
            }
            if (!Schema::hasColumn('items', 'export_tax_rate')) {
                $table->decimal('export_tax_rate', 5, 2)->default(0)->after('import_tax_rate');
            }

            // Tables / JSON extensions
            if (!Schema::hasColumn('items', 'unit_conversions')) {
                $table->json('unit_conversions')->nullable()->after('export_tax_rate');
            }
            if (!Schema::hasColumn('items', 'tier_discounts')) {
                $table->json('tier_discounts')->nullable()->after('unit_conversions');
            }
            if (!Schema::hasColumn('items', 'combo_details')) {
                $table->json('combo_details')->nullable()->after('tier_discounts');
            }
            if (!Schema::hasColumn('items', 'quantity_formula')) {
                $table->text('quantity_formula')->nullable()->after('combo_details');
            }
            if (!Schema::hasColumn('items', 'custom_fields')) {
                $table->json('custom_fields')->nullable()->after('quantity_formula');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropColumn([
                'item_type', 'category_code', 'category_name', 'image_url',
                'warranty_period', 'warranty_unit', 'warehouse_location', 'minimum_stock', 'origin',
                'purchase_description', 'sale_description', 'special_feature_type',
                'default_warehouse', 'inventory_account', 'revenue_account', 'discount_account',
                'rebate_account', 'return_account', 'cost_account', 'purchase_discount_rate',
                'fixed_purchase_price', 'latest_purchase_price', 'sale_price', 'vat_rate',
                'import_tax_rate', 'export_tax_rate', 'unit_conversions', 'tier_discounts',
                'combo_details', 'quantity_formula', 'custom_fields'
            ]);
        });
    }
};
