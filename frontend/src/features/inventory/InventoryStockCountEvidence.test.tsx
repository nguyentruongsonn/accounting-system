import { describe, expect, it } from 'vitest';
import source from './InventoryStockCounts.tsx?raw';

describe('inventory stock-count draft evidence boundary', () => {
  it('uses server catalogues, previews variances, and creates linked adjustment drafts only', () => {
    expect(source).toContain("api.get('/inventory/stock-counts')");
    expect(source).toContain("api.post('/inventory/stock-counts'");
    expect(source).toContain("api.put(`/inventory/stock-counts/${editing.id}`");
    expect(source).toContain("api.delete(`/inventory/stock-counts/${id}`");
    expect(source).toContain('`/inventory/stock-counts/${id}/variance`');
    expect(source).toContain('`/inventory/stock-counts/${id}/adjustment-drafts`');
    expect(source).toContain('Tạo phiếu điều chỉnh nháp');
    expect(source).toContain('Mở phiếu nhập nháp');
    expect(source).toContain('Mở phiếu xuất nháp');
    expect(source).toContain('onOpenAdjustmentDraft');
    expect(source).toContain('Chênh lệch kiểm kê');
    expect(source).toContain('Chỉ ghi nhận số đếm thực tế');
    expect(source).toContain('Số lượng thực tế');
    expect(source).toContain('Chọn từ catalogue máy chủ');
    expect(source).toContain("from '../../components/layout/PageHeader'");
    expect(source).toContain("from '../../components/layout/ModalFrame'");
    expect(source).toContain('<PageHeader');
    expect(source).toContain('<ModalFrame>');
    expect(source).toContain('Thử lại biên bản kiểm kê');
    expect(source).toContain('counts.refetch()');
    expect(source).not.toContain('journal_entry_id');
    expect(source).not.toContain("/inventory/stock-counts/${id}/post");
    expect(source).toContain('book_quantity');
    expect(source).not.toContain('variance_amount');
    expect(source).not.toContain('1561');
    expect(source).not.toContain('331');
  });
});
