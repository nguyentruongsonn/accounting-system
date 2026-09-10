import { describe, expect, it } from 'vitest';
import source from './InventoryTransfers.tsx?raw';

describe('inventory transfer posting evidence boundary', () => {
  it('uses server catalogues and exposes only server-backed post/unpost actions', () => {
    expect(source).toContain("api.get('/inventory/transfers')");
    expect(source).toContain("api.post('/inventory/transfers'");
    expect(source).toContain("api.put(`/inventory/transfers/${editing.id}`");
    expect(source).toContain("api.delete(`/inventory/transfers/${id}`");
    expect(source).toContain('api.post(`/inventory/transfers/${id}/post`)');
    expect(source).toContain('api.post(`/inventory/transfers/${id}/unpost`)');
    expect(source).toContain('Ghi sổ');
    expect(source).toContain('Bỏ ghi sổ');
    expect(source).toContain('Chọn từ catalogue máy chủ');
    expect(source).toContain("from '../../components/layout/PageHeader'");
    expect(source).toContain("from '../../components/layout/ModalFrame'");
    expect(source).toContain('<PageHeader');
    expect(source).toContain('<ModalFrame>');
    expect(source).toContain('Thử lại phiếu điều chuyển');
    expect(source).toContain('transfers.refetch()');
    expect(source).not.toContain('journal_entry_id');
    expect(source).not.toContain('1561');
    expect(source).not.toContain('331');
  });
});
