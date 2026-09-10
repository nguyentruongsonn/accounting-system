import { describe, expect, it } from 'vitest';
import source from './Dashboard.tsx?raw';

describe('dashboard accounting evidence boundary', () => {
    it('does not turn missing report amounts into zero or calculate ratios from incomplete evidence', () => {
        expect(source).not.toContain("?.end_balance || '0.00'");
        expect(source).not.toContain("?.this_period || '0.00'");
        expect(source).toContain('value === null ? \'—\'');
        expect(source).toContain("const bank = findAmount(balanceData?.assets, '112', 'end_balance');");
        expect(source).toContain('const cashTotal = cash !== null && bank !== null');
        expect(source).toContain('cashTotal !== null && receivables !== null');
        expect(source).toContain('title="Tổng Tiền (111 + 112)"');
        expect(source).toContain('liquidAssets !== null');
        expect(source).toContain('payablesPositive && liquidAssets !== null');
        expect(source).toContain('payablesPositive && cashTotal !== null');
        expect(source).toContain("Invalid dashboard balance-sheet response");
        expect(source).toContain("Invalid dashboard income-statement response");
        expect(source).toContain('const reportUnavailable = balanceQuery.isError || incomeQuery.isError;');
        expect(source).toContain('Bảng điều khiển chưa có dữ liệu báo cáo hợp lệ');
        expect(source).toContain('void balanceQuery.refetch();');
        expect(source).toContain('Thử lại');
    });
});
