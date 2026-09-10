import { describe, expect, it } from 'vitest';
import source from './AccountingAccountCatalogues.tsx?raw';

describe('accounting account catalogue evidence boundary', () => {
  it('uses server-only account/default/closing catalogues and keeps execution authorization disabled', () => {
    expect(source).toContain("api.get('/master/accounts')");
    expect(source).toContain("api.get('/master/voucher-type-settings/types')");
    expect(source).toContain('include_inactive=1');
    expect(source).toContain("api.get('/master/closing-rules')");
    expect(source).toContain("api.post('/master/voucher-type-settings'");
    expect(source).toContain("api.put(`/master/voucher-type-settings/${id}`");
    expect(source).toContain("api.delete(`/master/voucher-type-settings/${id}`");
    expect(source).toContain("api.put(`/master/closing-rules/${id}`");
    expect(source).toContain("api.delete(`/master/closing-rules/${id}`");
    expect(source).toContain('name="is_active"');
    expect(source).toContain('chưa cấp quyền ghi sổ');
    expect(source).toContain('Chưa phê duyệt thực thi');
    expect(source).toContain('account.is_parent !== true');
    expect(source).toContain('isDepositVoucherType');
    expect(source).toContain('Loại chứng từ tiền mặt');
    expect(source).toContain('Tiền gửi nằm ngoài phạm vi catalogue tiền mặt nội bộ.');
    expect(source).toContain('Chọn ít nhất TK Nợ hoặc TK Có từ catalogue máy chủ.');
    expect(source).not.toContain("default_account: '156'");
    expect(source).not.toContain("debit_account: '1111'");
  });
});
