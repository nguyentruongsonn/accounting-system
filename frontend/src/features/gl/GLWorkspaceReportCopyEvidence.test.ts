import { describe, expect, it } from 'vitest';
import source from './GLWorkspace.tsx?raw';

describe('GL report panel copy', () => {
  it('keeps the internal report panel neutral and does not render statutory disclaimers', () => {
    expect(source).not.toContain('reportManifest?.meta.disclaimer');
    expect(source).not.toContain('Trạng thái không tự xác nhận biểu mẫu hoặc tuân thủ pháp lý.');
    expect(source).toContain('Báo cáo vận hành lấy dữ liệu trực tiếp từ máy chủ.');
    expect(source).not.toContain('(bản vận hành)');
    expect(source).not.toContain('(chưa hỗ trợ)');
    expect(source).toContain('displayInternalReportLabel(capability)');
    expect(source).toContain('resolveInternalReportRoute(capability)');
    expect(source).toContain('refetchReportCapabilities');
    expect(source).toContain('Thử lại báo cáo');
  });

  it('keeps the GL process bar limited to real in-scope destinations', () => {
    expect(source).toContain('Hệ thống tài khoản (COA)');
    expect(source).not.toContain('Đối tượng THCP (chưa khả dụng)');
    expect(source).not.toContain('Evidence cutoff AP/AR');
    expect(source).not.toContain('Tùy chọn (chưa khả dụng)');
  });

  it('does not expose placeholder tabs for unavailable prepaid or advance workflows', () => {
    expect(source).not.toContain("key: 'tab-advance'");
    expect(source).not.toContain("key: 'tab-prepaid'");
    expect(source).not.toContain('Chức năng Quyết toán tạm ứng nhân viên...');
    expect(source).not.toContain('Chức năng Phân bổ chi phí trả trước (TK 242)...');
  });
});
