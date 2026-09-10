import { describe, expect, it } from 'vitest';
import source from './CashWorkspace.tsx?raw';

describe('cash workflow capability labels', () => {
  it('does not advertise out-of-scope payment helpers while retaining real import flow', () => {
    // Tax, insurance and payroll payments are outside the internal two-role scope.
    expect(source).not.toContain("Nộp thuế (chưa khả dụng)");
    expect(source).not.toContain("label: 'Nộp bảo hiểm (chưa khả dụng)'");
    expect(source).not.toContain("label: 'Trả lương (chưa khả dụng)'");
    expect(source).toContain("label: 'Nhập từ excel (chỉ chọn file cục bộ)'");
    expect(source).toContain("setIsExcelImportOpen(true)");
    expect(source).not.toContain('Tùy chọn (chưa khả dụng)');
    expect(source).not.toContain('Hướng dẫn nghiệp vụ tiền mặt chưa khả dụng');
    expect(source).not.toContain('Cài đặt tiền mặt chưa khả dụng');
    expect(source).not.toContain("Mở tùy chọn hệ thống tiền mặt");
    expect(source).not.toContain("Mở hướng dẫn nghiệp vụ tiền mặt");
  });
});
