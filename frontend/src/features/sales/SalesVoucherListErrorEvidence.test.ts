import { describe, expect, it } from 'vitest';
import invoiceSource from './SalesInvoices.tsx?raw';
import orderSource from './SalesOrders.tsx?raw';

describe('sales voucher list error boundary', () => {
  it('keeps invoice and order failures distinct from valid empty results', () => {
    expect(invoiceSource).toContain('isError: isInvoicesError');
    expect(invoiceSource).toContain('refetch: refetchInvoices');
    expect(invoiceSource).toContain('Không thể tải danh sách chứng từ bán hàng');
    expect(invoiceSource).toContain('Thử lại danh sách chứng từ bán hàng');
    expect(orderSource).toContain('isError: isOrdersError');
    expect(orderSource).toContain('parseSalesOrderCollection(data)');
    expect(orderSource).toContain('Không thể tải danh sách đơn đặt hàng');
    expect(orderSource).toContain('Thử lại danh sách đơn đặt hàng');
    expect(orderSource).toContain('Thử lại danh mục đơn hàng');
    expect(orderSource).toContain('isError: isCustomersError');
    expect(orderSource).toContain('parseSalesOrderCollection<any>(data, \'customers\')');
    expect(orderSource).not.toContain('(data?.data || [])');
  });
});
