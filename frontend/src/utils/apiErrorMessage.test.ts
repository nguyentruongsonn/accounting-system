import { describe, expect, it } from 'vitest';
import { getApiErrorMessage } from './apiErrorMessage';

describe('getApiErrorMessage', () => {
  it('prefers a safe field-level detail over a generic API error', () => {
    expect(getApiErrorMessage({
      response: {
        data: {
          error: 'Approved posting account mapping evidence is not available.',
          errors: { account_mapping: ['Account mapping [cash_bank.voucher/debit] is missing.'] },
        },
      },
    }, 'Không thể ghi sổ.')).toContain('cash_bank.voucher/debit');
  });

  it('supports the standard message/error envelope', () => {
    expect(getApiErrorMessage({ response: { data: { message: 'Kỳ kế toán đã khóa.' } } }, 'Không thể thực hiện.'))
      .toBe('Kỳ kế toán đã khóa.');
    expect(getApiErrorMessage({ response: { data: { error: 'This action is unauthorized.' } } }, 'Không thể thực hiện.'))
      .toBe('This action is unauthorized.');
  });

  it('returns the caller fallback when the error has no safe text', () => {
    expect(getApiErrorMessage({ response: { data: { errors: { field: [] } } } }, 'Không thể thực hiện.'))
      .toBe('Không thể thực hiện.');
  });
});
