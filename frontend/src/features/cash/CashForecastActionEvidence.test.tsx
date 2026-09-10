import { describe, expect, it } from 'vitest';
import rawSource from './CashForecast.tsx?raw';

const source = rawSource.replace(/\r\n/g, '\n');

describe('cash forecast action evidence boundary', () => {
    it('requires a persisted forecast before success or print', () => {
        expect(source).toContain("throw new Error('Invalid cash forecast response')");
        expect(source).toContain('const payload = parseForecastCollection(data);');
        expect(source).toContain('!Number.isInteger(Number(saved.id))');
        expect(source).toContain('return false;');
        expect(source).toContain('if (await handleSaveForecast()) window.print()');
    });

    it('keeps saved forecasts read-only so viewing cannot create a duplicate', () => {
        expect(source).toContain('const [isSheetReadOnly, setIsSheetReadOnly]');
        expect(source).toContain('Bảng đã lưu — chỉ xem');
        expect(source).toContain('disabled={isSheetReadOnly}');
    });

    it('keeps the new cash-only forecast template free of deposit rows', () => {
        expect(source).not.toContain("name: 'Tiền gửi ngân hàng (TK 112)'");
        expect(source).not.toContain("name: 'Thu tiền vay ngân hàng, tổ chức tín dụng'");
        expect(source).not.toContain("name: 'Thu từ lãi tiền gửi ngân hàng, các khoản cho vay'");
    });

    it('does not manufacture a creator identity in the browser payload', () => {
        expect(source).not.toContain("creator: 'Kế toán trưởng'");
    });

    it('preserves missing server monetary evidence instead of displaying zero', () => {
        expect(source).toContain('const sourceMoney = (value: unknown): number | null');
        expect(source).toContain('amount: sourceMoney(item.amount)');
        expect(source).toContain("value === null || value === undefined\n        ? '—'");
        expect(source).not.toContain('amount: Number(item.amount) || 0');
    });
});
