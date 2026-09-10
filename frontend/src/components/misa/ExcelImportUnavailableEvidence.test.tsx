import { describe, expect, it } from 'vitest';
import source from './ExcelImportModal.tsx?raw';

describe('Excel import unavailable boundary', () => {
  it('does not ship sample accounting rows or mappings while the import API is unavailable', () => {
    expect(source).toContain('Chưa có dữ liệu ánh xạ từ máy chủ');
    expect(source).toContain('Chưa có dữ liệu kiểm tra từ máy chủ');
    expect(source).not.toContain('PT00088');
    expect(source).not.toContain('PC00088');
    expect(source).not.toContain('KH0001 / NCC0001');
    expect(source).not.toContain('15,000,000');
    expect(source).not.toContain("defaultValue=\"col_A\"");
    expect(source).not.toContain("defaultValue=\"col_D\"");
    expect(source).not.toContain('Mau_Phieu_Thu_Chi_MISA_2026.xlsx');
    expect(source).toContain('if (currentStep === 0 && !fileName) return;');
  });
});
