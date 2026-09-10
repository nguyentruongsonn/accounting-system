import { describe, expect, it } from 'vitest';
import { toJournalEntryFormLines } from './journalEntryFormMapper';

describe('toJournalEntryFormLines', () => {
  it('pairs persisted debit and credit rows into editable balanced form lines without double-counting the entry total', () => {
    const lines = toJournalEntryFormLines([
      { account_code: '1111', description: 'Thu tiền bán hàng', debit_amount: 100, credit_amount: 0 },
      { account_code: '1331', description: 'Thuế GTGT đầu vào', debit_amount: 10, credit_amount: 0 },
      { account_code: '331', description: 'Phải trả nhà cung cấp', debit_amount: 0, credit_amount: 110 },
    ]);

    expect(lines).toEqual([
      { debit_account: '1111', credit_account: '331', amount: '100.00', description: 'Thu tiền bán hàng' },
      { debit_account: '1331', credit_account: '331', amount: '10.00', description: 'Thuế GTGT đầu vào' },
    ]);
    expect(lines.reduce((total, line) => total + Number(line.amount), 0)).toBe(110);
  });

  it('keeps an unmatched persisted side visible with an empty counterpart so the existing form validation remains fail-closed', () => {
    expect(toJournalEntryFormLines([
      { account_code: '1111', description: 'Dòng thiếu đối ứng', debit_amount: 25, credit_amount: 0 },
    ])).toEqual([
      { debit_account: '1111', credit_account: '', amount: '25.00', description: 'Dòng thiếu đối ứng' },
    ]);
  });

  it('retains a zero or negative persisted amount as an invalid row instead of dropping it from the editor', () => {
    expect(toJournalEntryFormLines([
      { account_code: '1111', description: 'Dòng âm', debit_amount: -25, credit_amount: 0 },
      { account_code: '331', description: 'Dòng không tiền', debit_amount: 0, credit_amount: 0 },
    ])).toEqual([
      { debit_account: '1111', credit_account: '', amount: '-25.00', description: 'Dòng âm', invalid_reason: 'Số tiền Nợ/Có đã lưu không hợp lệ.' },
      { debit_account: '331', credit_account: '', amount: '0.00', description: 'Dòng không tiền', invalid_reason: 'Số tiền Nợ/Có đã lưu không hợp lệ.' },
    ]);
  });

  it('retains both sides of a dual-sided persisted row as invalid rows with empty counterparts', () => {
    expect(toJournalEntryFormLines([
      { account_code: '1121', description: 'Dòng hai bên', debit_amount: 25, credit_amount: 25 },
    ])).toEqual([
      { debit_account: '1121', credit_account: '', amount: '25.00', description: 'Dòng hai bên', invalid_reason: 'Dòng hạch toán đã lưu đồng thời có Nợ và Có.' },
      { debit_account: '', credit_account: '1121', amount: '25.00', description: 'Dòng hai bên', invalid_reason: 'Dòng hạch toán đã lưu đồng thời có Nợ và Có.' },
    ]);
  });

  it('retains every side of a mixed-sign persisted row as invalid instead of silently dropping its negative side', () => {
    expect(toJournalEntryFormLines([
      { account_code: '1111', description: 'Nợ âm', debit_amount: -25, credit_amount: 25 },
      { account_code: '331', description: 'Có âm', debit_amount: 25, credit_amount: -25 },
    ])).toEqual([
      { debit_account: '1111', credit_account: '', amount: '-25.00', description: 'Nợ âm', invalid_reason: 'Số tiền Nợ/Có đã lưu không hợp lệ.' },
      { debit_account: '', credit_account: '1111', amount: '25.00', description: 'Nợ âm', invalid_reason: 'Số tiền Nợ/Có đã lưu không hợp lệ.' },
      { debit_account: '331', credit_account: '', amount: '25.00', description: 'Có âm', invalid_reason: 'Số tiền Nợ/Có đã lưu không hợp lệ.' },
      { debit_account: '', credit_account: '331', amount: '-25.00', description: 'Có âm', invalid_reason: 'Số tiền Nợ/Có đã lưu không hợp lệ.' },
    ]);
  });
});
