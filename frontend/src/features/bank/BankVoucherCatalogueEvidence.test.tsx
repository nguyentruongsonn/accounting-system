import { describe, expect, it } from 'vitest';
import receiptSource from './BankReceipts.tsx?raw';
import paymentSource from './BankPayments.tsx?raw';

describe('bank voucher catalogue response boundary', () => {
    it('does not turn malformed master-data 2xx responses into valid empty options', () => {
        for (const source of [receiptSource, paymentSource]) {
            expect(source).toContain('parseCollectionResponse');
            expect(source).not.toContain('(data?.data || [])');
        }
    });
});
