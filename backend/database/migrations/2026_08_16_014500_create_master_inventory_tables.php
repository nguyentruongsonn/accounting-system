<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Item Categories (Nhóm VTHH)
        if (!Schema::hasTable('item_categories')) {
            Schema::create('item_categories', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id')->default(1);
                $table->string('code')->unique();
                $table->string('name');
                $table->string('parent_code')->nullable();
                $table->text('description')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });

            DB::table('item_categories')->insert([
                ['company_id' => 1, 'code' => 'HH', 'name' => 'Hàng hóa thương mại', 'parent_code' => null, 'description' => 'Nhóm hàng hóa mua đi bán lại', 'created_at' => now(), 'updated_at' => now()],
                ['company_id' => 1, 'code' => 'VL', 'name' => 'Vật liệu xây dựng', 'parent_code' => null, 'description' => 'Sắt thép, xi măng, gạch đá', 'created_at' => now(), 'updated_at' => now()],
                ['company_id' => 1, 'code' => 'TB', 'name' => 'Thiết bị điện tử', 'parent_code' => null, 'description' => 'Máy tính, máy in, linh kiện', 'created_at' => now(), 'updated_at' => now()],
                ['company_id' => 1, 'code' => 'VP', 'name' => 'Văn phòng phẩm', 'parent_code' => null, 'description' => 'Giấy in, bút, sổ sách', 'created_at' => now(), 'updated_at' => now()],
                ['company_id' => 1, 'code' => 'DV', 'name' => 'Dịch vụ doanh nghiệp', 'parent_code' => null, 'description' => 'Dịch vụ vận chuyển, tư vấn, bảo dưỡng', 'created_at' => now(), 'updated_at' => now()],
            ]);
        }

        // 2. Units (Đơn vị tính)
        if (!Schema::hasTable('units')) {
            Schema::create('units', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id')->default(1);
                $table->string('code')->unique();
                $table->string('name');
                $table->text('description')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });

            $defaultUnits = ['Cái', 'Chiếc', 'Bộ', 'Hộp', 'Thùng', 'Kg', 'Tấn', 'Tạ', 'Mét', 'Cuộn', 'Cây', 'Lít', 'Gói', 'Bình', 'Chai', 'Bao', 'Két'];
            foreach ($defaultUnits as $u) {
                DB::table('units')->insert([
                    'company_id' => 1,
                    'code' => $u,
                    'name' => $u,
                    'description' => "Đơn vị tính {$u}",
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        // 3. Warehouses (Kho)
        if (!Schema::hasTable('warehouses')) {
            Schema::create('warehouses', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id')->default(1);
                $table->string('code')->unique();
                $table->string('name');
                $table->string('default_account')->default('156');
                $table->text('address')->nullable();
                $table->string('manager_name')->nullable();
                $table->text('description')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });

            DB::table('warehouses')->insert([
                ['company_id' => 1, 'code' => 'KHO_TONG', 'name' => 'Kho tổng', 'default_account' => '156', 'address' => 'Hà Nội', 'manager_name' => 'Nguyễn Văn Kho', 'description' => 'Kho lưu trữ chính', 'created_at' => now(), 'updated_at' => now()],
                ['company_id' => 1, 'code' => 'KHO_HH', 'name' => 'Kho hàng hóa', 'default_account' => '1561', 'address' => 'Hà Nội', 'manager_name' => 'Trần Thủ Kho', 'description' => 'Kho hàng hóa bán buôn, bán lẻ', 'created_at' => now(), 'updated_at' => now()],
                ['company_id' => 1, 'code' => 'KHO_NVL', 'name' => 'Kho nguyên vật liệu', 'default_account' => '152', 'address' => 'Khu CN Phố Nối', 'manager_name' => 'Lê Nguyên Vật Liệu', 'description' => 'Kho chứa nguyên vật liệu sản xuất', 'created_at' => now(), 'updated_at' => now()],
                ['company_id' => 1, 'code' => 'KHO_TP', 'name' => 'Kho thành phẩm', 'default_account' => '155', 'address' => 'Khu CN Phố Nối', 'manager_name' => 'Phạm Thành Phẩm', 'description' => 'Kho chứa thành phẩm sau sản xuất', 'created_at' => now(), 'updated_at' => now()],
                ['company_id' => 1, 'code' => 'KHO_CCDC', 'name' => 'Kho công cụ dụng cụ', 'default_account' => '153', 'address' => 'Hà Nội', 'manager_name' => 'Hoàng Dụng Cụ', 'description' => 'Kho công cụ dụng cụ chờ phân bổ', 'created_at' => now(), 'updated_at' => now()],
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('warehouses');
        Schema::dropIfExists('units');
        Schema::dropIfExists('item_categories');
    }
};
