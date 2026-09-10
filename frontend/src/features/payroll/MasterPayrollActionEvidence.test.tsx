import { describe, expect, it } from 'vitest';
import payrollVouchers from './PayrollVouchers.tsx?raw';
import payrollList from './PayrollList.tsx?raw';
import bankAccounts from '../bank/BankAccounts.tsx?raw';
import employees from '../master/Employees.tsx?raw';
import companySettings from '../settings/CompanySettings.tsx?raw';

describe('master/payroll action evidence boundary', () => {
    it('requires server response evidence before reporting writes or posting as successful', () => {
        expect(payrollVouchers).toContain("response?.data?.id === undefined || response?.data?.id === null");
        expect(payrollVouchers).toContain("response?.data?.data?.id === undefined || response?.data?.data?.id === null");
        expect(payrollVouchers).not.toContain('Tạo Bảng tính lương & Hạch toán thành công!');
        expect(payrollVouchers).toContain('Tạo bảng lương dự thảo thành công; cần ghi sổ riêng.');
        expect(payrollVouchers).toContain('const rows = Array.isArray(data) ? data : data?.data;');
        expect(payrollList).toContain("response?.data?.data?.id === undefined || response?.data?.data?.id === null");
        expect(payrollList).toContain('if (!Array.isArray(payload))');
        expect(payrollList).toContain('Máy chủ trả về danh sách bảng lương không hợp lệ.');
        expect(bankAccounts).toContain('response?.status !== 204');
        expect(bankAccounts).toContain("response?.data?.id === undefined || response?.data?.id === null");
        expect(employees).toContain("response?.data?.data?.id === undefined || response?.data?.data?.id === null");
        expect(employees).toContain("typeof response?.data?.message !== 'string'");
        expect(companySettings).toContain("response?.data?.data?.id === undefined || response?.data?.data?.id === null");
    });
});
