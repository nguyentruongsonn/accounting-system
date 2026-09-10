<?php

namespace App\Services;

use App\Enums\SystemVoucherType;

class SystemVoucherTypeRegistry
{
    /**
     * Lấy toàn bộ danh mục chứng từ hệ thống với metadata phục vụ Frontend Modal & Registry
     */
    public static function all(): array
    {
        return SystemVoucherType::allTypes();
    }

    /**
     * Tìm loại chứng từ theo mã code, nhãn hoặc tên model
     */
    public static function resolve(string|SystemVoucherType|null $type): ?SystemVoucherType
    {
        return SystemVoucherType::resolveType($type);
    }

    /**
     * Lấy danh sách chứng từ thuộc về một phân hệ cụ thể
     */
    public static function getByModule(string $module): array
    {
        return array_values(array_filter(
            SystemVoucherType::allTypes(),
            fn ($item) => $item['module'] === $module
        ));
    }

    /**
     * Tự động suy diễn đối tượng, diễn giải, định khoản Nợ/Có khi chọn chứng từ tham chiếu
     */
    public static function resolveDefaults(
        string|SystemVoucherType|null $sourceType,
        string|SystemVoucherType|null $targetType,
        mixed $targetModelOrId,
        string $standard = 'TT99'
    ): array {
        return SystemVoucherType::resolveCrossVoucherDefaults(
            $sourceType,
            $targetType,
            $targetModelOrId,
            $standard
        );
    }
}
