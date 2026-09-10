import { describe, expect, it } from 'vitest';
import bankAccountsSource from '../bank/BankAccounts.tsx?raw';
import bankTransactionsSource from '../bank/BankTransactions.tsx?raw';
import payrollVouchersSource from '../payroll/PayrollVouchers.tsx?raw';

describe('legacy list error presentation', () => {
  it('keeps bank-account failures distinguishable from an empty catalogue', () => {
    expect(bankAccountsSource).toContain('isError: isBankAccountsError');
    expect(bankAccountsSource).toContain('refetch: refetchBankAccounts');
    expect(bankAccountsSource).toContain('Không thể tải danh mục tài khoản ngân hàng');
    expect(bankAccountsSource).toContain('Thử lại danh mục tài khoản ngân hàng');
  });

  it('keeps bank transaction failures distinguishable for both receipt and payment lists', () => {
    expect(bankTransactionsSource).toContain('isError: isReceiptsError');
    expect(bankTransactionsSource).toContain('isError: isPaymentsError');
    expect(bankTransactionsSource).toContain('Không thể tải chứng từ tiền gửi');
    expect(bankTransactionsSource).toContain('Thử lại chứng từ tiền gửi');
  });

  it('keeps payroll list and catalogue failures visible with retry actions', () => {
    expect(payrollVouchersSource).toContain('isError: isPayrollsError');
    expect(payrollVouchersSource).toContain('refetch: refetchPayrolls');
    expect(payrollVouchersSource).toContain('Không thể tải dữ liệu bảng lương');
    expect(payrollVouchersSource).toContain('Thử lại bảng lương');
  });
});
