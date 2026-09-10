import { describe, expect, it } from 'vitest';
import mainLayoutSource from '../../layouts/MainLayout.tsx?raw';
import routesSource from '../../routes/index.tsx?raw';
import cashWorkspaceSource from '../cash/CashWorkspace.tsx?raw';
import cashPaymentsSource from '../cash/CashPayments.tsx?raw';
import cashReceiptsSource from '../cash/CashReceipts.tsx?raw';
import cashTransactionsSource from '../cash/CashTransactions.tsx?raw';

describe('tax module removal boundary', () => {
    it('removes tax navigation, routes, and cash tax-payment actions', () => {
        expect(mainLayoutSource).not.toContain("key: '/tax'");
        expect(mainLayoutSource).not.toContain("label: 'Thuế'");
        expect(routesSource).toContain('<Route path="tax/*" element={<Navigate to="/" replace />} />');
        expect(routesSource).not.toContain('TaxWorkspace');
        expect(routesSource).not.toContain('VatDeclaration');

        const cashSources = [cashWorkspaceSource, cashPaymentsSource, cashReceiptsSource, cashTransactionsSource].join('\n');
        expect(cashSources).not.toContain('TaxPaymentModal');
        expect(cashSources).not.toContain('isTaxPaymentOpen');
        expect(cashSources).not.toContain('Nộp thuế');
    });
});
