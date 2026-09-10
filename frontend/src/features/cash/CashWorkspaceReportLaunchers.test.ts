import { describe, expect, it } from 'vitest';
import source from './CashWorkspace.tsx?raw';

describe('CashWorkspace report launchers', () => {
  it('presents all five report launchers as functional reports', () => {
    expect(source).not.toContain('— chưa phát hành');
    expect(source).toContain('Bảng kê số dư tiền theo ngày');
    expect(source).toContain('Dòng tiền');
    expect(source).toContain('S03a1-DNN: Sổ nhật ký thu tiền');
    expect(source).toContain('Sổ kế toán chi tiết quỹ tiền mặt');
    expect(source).toContain('S03a2-DNN: Sổ nhật ký chi tiền');
  });
});
