import { describe, expect, it } from 'vitest';
import cashReceiptsSource from './CashReceipts.tsx?raw';
import cashPaymentsSource from './CashPayments.tsx?raw';

describe('cash report source drilldown', () => {
  it('consumes tenant-scoped source_id links in the receipt and payment workspaces', () => {
    expect(cashReceiptsSource).toContain("searchParams.get('source_id')");
    expect(cashReceiptsSource).toContain("api.get('/cash/receipts/' + sourceId)");
    expect(cashReceiptsSource).toContain("void handleViewReceipt(record, 'view')");
    expect(cashPaymentsSource).toContain("searchParams.get('source_id')");
    expect(cashPaymentsSource).toContain("api.get('/cash/payments/' + sourceId)");
    expect(cashPaymentsSource).toContain("void handleViewPayment(record, 'view')");
  });
});
