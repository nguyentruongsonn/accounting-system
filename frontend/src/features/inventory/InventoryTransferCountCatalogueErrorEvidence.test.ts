import { describe, expect, it } from 'vitest';
import transferSource from './InventoryTransfers.tsx?raw';
import countSource from './InventoryStockCounts.tsx?raw';

describe('inventory transfer/count catalogue error boundaries', () => {
    it('keeps transfer creation blocked until warehouse and item catalogues are verified', () => {
        expect(transferSource).toContain('const catalogueError = warehouses.isError || items.isError;');
        expect(transferSource).toContain('const catalogueUnavailable = catalogueError || warehouses.isLoading || items.isLoading');
        expect(transferSource).toContain('Thử lại danh mục điều chuyển');
        expect(transferSource).toContain('okButtonProps={{ disabled: catalogueUnavailable }}');
        expect(transferSource).toContain("getApiErrorMessage(error, 'Không thể ghi sổ phiếu điều chuyển; máy chủ đã từ chối thao tác.')");
    });

    it('keeps stock-count creation blocked until warehouse and item catalogues are verified', () => {
        expect(countSource).toContain('const catalogueError = warehouses.isError || items.isError;');
        expect(countSource).toContain('const catalogueUnavailable = catalogueError || warehouses.isLoading || items.isLoading');
        expect(countSource).toContain('Thử lại danh mục kiểm kê');
        expect(countSource).toContain('okButtonProps={{ disabled: catalogueUnavailable }}');
        expect(countSource).toContain("getApiErrorMessage(error, 'Không thể tạo chứng từ điều chỉnh nháp; kiểm tra kỳ, quyền và dữ liệu kiểm kê.')");
    });
});
