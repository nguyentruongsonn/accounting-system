import { describe, expect, it } from 'vitest';
import receiptSource from './InventoryReceipts.tsx?raw';
import issueSource from './InventoryIssues.tsx?raw';
import journalSource from '../gl/GeneralJournals.tsx?raw';
import bankPaymentSource from '../bank/BankPayments.tsx?raw';
import bankReceiptSource from '../bank/BankReceipts.tsx?raw';

describe('account evidence boundary in voucher forms', () => {
    it('does not synthesize inventory or journal account mappings', () => {
        const source = `${receiptSource}\n${issueSource}\n${journalSource}\n${bankPaymentSource}\n${bankReceiptSource}`;

        // Account values must come from the user-selected leaf account or an
        // approved server mapping. The UI must never invent a legacy default.
        for (const mapping of [
            "debit_account: '1561'",
            "credit_account: '1561'",
            "credit_account: '331'",
            "debit_account: '632'",
            "debit_account: '642'",
            "credit_account: '1111'",
        ]) {
            expect(source).not.toContain(mapping);
        }

        expect(source).not.toContain("line.debit_account || '1561'");
        expect(source).not.toContain("line.credit_account || '331'");
        expect(source).not.toContain("line.debit_account || '632'");
        expect(source).not.toContain("line.credit_account || '1561'");
        expect(source).toContain("message: 'Chọn TK Nợ'");
        expect(source).toContain("message: 'Chọn TK Có'");
        expect(source).not.toContain("filter((a: any) => !a.is_parent && a.is_active !== false)");
        expect(receiptSource).toContain('parseInventoryAccountCatalogue(data)');
        expect(issueSource).toContain('parseInventoryAccountCatalogue(data)');
        expect(receiptSource).toContain('getActiveLeafInventoryAccounts(chartOfAccounts)');
        expect(issueSource).toContain('getActiveLeafInventoryAccounts(chartOfAccounts)');
        expect(receiptSource).toContain('canPostPersistedInventoryDocument(record, postMutation.isPending)');
        expect(issueSource).toContain('canPostPersistedInventoryDocument(record, postMutation.isPending)');
        expect(source).not.toContain("bankAccounts[0].id");
        expect(source).not.toContain("bankAccounts?.[0]?.id");
        expect(source).not.toContain("Math.floor(Math.random()");
        expect(source).not.toContain("items?.[0]");
        expect(source).not.toContain("sale_price) * 0.7");
        expect(receiptSource).toContain('quantity: line.quantity ?? undefined');
        expect(receiptSource).toContain("? '—'");
        expect(issueSource).toContain('quantity: line.quantity ?? undefined');
        expect(issueSource).toContain("? '—'");
        // Bank voucher line contacts must use the backend's persisted
        // line_contact_* contract; a contact name must never be sent as an ID.
        expect(bankPaymentSource).toContain('line_contact_id: l.contact_id');
        expect(bankPaymentSource).toContain('line_contact_name: l.contact_name');
        expect(bankReceiptSource).toContain('line_contact_id: l.contact_id');
        expect(bankReceiptSource).toContain('line_contact_name: l.contact_name');
        expect(bankReceiptSource).not.toContain('contact_id: l.contact_id || values.contact_name');
    });
});
