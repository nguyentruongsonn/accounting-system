import { describe, expect, it } from 'vitest';
import source from './SalesWorkspace.tsx?raw';

describe('sales-to-cash handoff boundary', () => {
  it('navigates to the real cash receipt form and does not create a receipt locally', () => {
    expect(source).toContain('useNavigate');
    expect(source).toContain("navigate('/cash/receipts?action=create')");
    expect(source).toContain('Thu tiền mặt KH');
    expect(source).not.toContain("api.post('/cash");
    expect(source).not.toContain("api.post('/bank");
    expect(source).not.toContain('Đã tự động lập phiếu thu thành công');
    expect(source).not.toContain('Bù trừ công nợ');
    expect(source).not.toContain('Bảng giá bán');
    expect(source).not.toContain('chưa khả dụng');
    expect(source).not.toContain("Mở danh mục bảng giá");
  });

  it('keeps the unimplemented sales-contract menu entry unavailable', () => {
    expect(source).toContain('useSearchParams');
    expect(source).toContain("invoice: 'tab-invoices'");
    expect(source).toContain("report: 'tab-reports'");
    expect(source).toContain("handleTabChange(requestedTabKey ?? 'tab-process');");
  });
});
