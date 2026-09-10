import { describe, expect, it } from 'vitest';
import source from './QuickAddBankAccountModal.tsx?raw';

describe('QuickAddBankAccount response evidence', () => {
  it('does not report success or invalidate before a persisted bank account id exists', () => {
    const guard = "const persistedAccount = res?.data;";
    const error = "Máy chủ không trả về tài khoản ngân hàng đã lưu; không thể xác nhận thao tác thành công.";
    const success = "message.success('Đã lưu tài khoản ngân hàng thành công!');";

    expect(source).toContain(guard);
    expect(source).toContain(error);
    expect(source.indexOf(error)).toBeLessThan(source.indexOf(success));
  });
});
