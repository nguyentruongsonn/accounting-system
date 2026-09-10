import { describe, expect, it } from 'vitest';
// @ts-expect-error Node filesystem access is intentional for static HTML evidence.
import { readFileSync } from 'node:fs';

const indexHtml = readFileSync('index.html', 'utf8');

describe('document identity', () => {
  it('uses the neutral local accounting identity instead of a fictitious company name', () => {
    expect(indexHtml).toContain('<title>Hệ thống kế toán nội bộ - Phần mềm quản trị kế toán doanh nghiệp</title>');
    expect(indexHtml).not.toContain('Kế toán ABC');
  });
});
