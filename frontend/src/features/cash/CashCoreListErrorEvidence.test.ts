import { describe, expect, it } from 'vitest';
import receiptsSource from './CashReceipts.tsx?raw';
import transactionsSource from './CashTransactions.tsx?raw';

describe('cash core list error boundary', () => {
  it('keeps receipt and payment failures distinct from a valid empty receipt list', () => {
    expect(receiptsSource).toContain('refetch: refetchReceipts');
    expect(receiptsSource).toContain('refetch: refetchPayments');
    expect(receiptsSource).toContain('Không thể tải danh sách phiếu thu');
    expect(receiptsSource).toContain('Không thể tải danh sách phiếu chi');
    expect(receiptsSource).toContain('Thử lại danh sách phiếu thu');
    expect(receiptsSource).toContain('Thử lại danh sách phiếu chi');
    expect(transactionsSource).toContain('refetch: refetchReceipts');
    expect(transactionsSource).toContain('refetch: refetchPayments');
    expect(transactionsSource).toContain('Không thể tải giao dịch tiền mặt');
    expect(transactionsSource).toContain('Thử lại giao dịch tiền mặt');
  });

  it('uses an explicit Vietnamese empty state for the combined cash transaction table', () => {
    expect(transactionsSource).toContain('locale={{');
    expect(transactionsSource).toContain('Không có chứng từ thu chi trong phạm vi đã chọn.');
  });

  it('names combined receipt/payment rows by voucher type and id', () => {
    expect(transactionsSource).toContain('function cashTransactionRowKey(record: any): string');
    expect(transactionsSource).toContain('rowKey={cashTransactionRowKey}');
    expect(transactionsSource).toContain('selectedRowKeys: selectedRow ? [cashTransactionRowKey(selectedRow)] : []');
    expect(transactionsSource).not.toContain('rowKey="id"');
  });
});
