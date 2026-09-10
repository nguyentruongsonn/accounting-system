import { describe, expect, it } from 'vitest';
import source from './CashReceipts.tsx?raw';

describe('cash receipt collection route handoff', () => {
  it('opens the real multi-customer collection modal from the sales flyout route', () => {
    expect(source).toContain("action === 'collect-multi'");
    expect(source).toContain('setIsCollectMultiCustomerOpen(true)');
    expect(source).toContain("nextSearchParams.delete('action')");
  });
});
