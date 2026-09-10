import { describe, expect, it } from 'vitest';
import source from './PurchaseWorkspace.tsx?raw';

describe('purchase-to-pay workspace handoff boundary', () => {
  it('keeps supplier payment inside the internal cash-only workspace', () => {
    expect(source).not.toContain("navigate('/bank/payments')");
    expect(source).toContain('Trả tiền NCC</div>');
    expect(source).toContain('Thanh toán tiền mặt');
    expect(source).toContain('setIsPayVendorModalOpen(true)');
    expect(source).not.toContain("api.post('/bank/payments");
    expect(source).not.toContain('Trả tiền NCC (chưa khả dụng)');
  });

  it('does not advertise unsupported supplier debt utilities', () => {
    expect(source).not.toContain('Đối chiếu công nợ nhà cung cấp (Excel)');
    expect(source).not.toContain('Đối trừ chứng từ công nợ');
    expect(source).not.toContain('Bù trừ công nợ');
    expect(source).not.toContain('Tự động đối chiếu thành công');
  });

  it('routes purchase-type choices to the server-backed purchase draft surface', () => {
    expect(source).toContain("onClick: () => handleTabChange('tab-invoices')");
    expect(source).toContain("label: 'Mua hàng trong nước nhập kho'");
    expect(source).toContain("label: 'Mua dịch vụ'");
    expect(source).not.toContain('Tùy chọn (chưa khả dụng)');
    expect(source).not.toContain("Mở tùy chọn mua hàng");
  });

  it('honors supported numeric flyout deep links instead of advertising unsupported intake', () => {
    expect(source).toContain('useSearchParams');
    expect(source).toContain("'2': 'tab-orders'");
    expect(source).toContain("'3': 'tab-contracts'");
    expect(source).toContain("'4': 'tab-invoices'");
    expect(source).not.toContain("'5': 'tab-receive-invoices'");
    expect(source).not.toContain('PurchaseReceiveInvoices');
    expect(source).toContain("'9': 'tab-reports'");
    expect(source).toContain("handleTabChange(requestedTabKey ?? 'tab-process');");
  });
});
