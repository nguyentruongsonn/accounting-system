import { describe, expect, it } from 'vitest';
import source from './LegacyReportSurfaceNotice.tsx?raw';

describe('legacy report surface copy', () => {
  it('keeps unavailable-state guidance focused on data availability', () => {
    expect(source).toContain('Chưa có nguồn dữ liệu cho báo cáo');
    expect(source).not.toContain('chưa được công bố là khả dụng');
    expect(source).toContain('Màn hình này không dựng số liệu mẫu hoặc thông báo xuất file thành công.');
    expect(source).not.toContain('chưa xác nhận Phụ lục IV');
    expect(source).not.toContain('không phải biểu mẫu BCTC, báo cáo thuế hoặc căn cứ kết luận tuân thủ');
  });
});
