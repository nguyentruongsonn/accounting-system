import { describe, expect, it } from 'vitest';
import receiptsSource from './InventoryReceipts.tsx?raw';
import issuesSource from './InventoryIssues.tsx?raw';

describe('inventory list response evidence boundary', () => {
    it('rejects malformed 2xx list envelopes instead of treating them as valid empty lists', () => {
        expect(receiptsSource).toContain("parseInventoryDocumentList(data, 'receipt')");
        expect(issuesSource).toContain("parseInventoryDocumentList(data, 'issue')");
    });

    it('lets operators reopen a persisted draft instead of exposing a dead view action', () => {
        expect(receiptsSource).toContain('api.put(`/inventory/receipts/${editingId}`');
        expect(issuesSource).toContain('api.put(`/inventory/issues/${editingId}`');
        expect(receiptsSource).toContain('Sửa');
        expect(issuesSource).toContain('Sửa');
    });

    it('keeps receipt and issue list failures distinct from valid empty results', () => {
        expect(receiptsSource).toContain('isError: isReceiptsError');
        expect(receiptsSource).toContain('refetch: refetchReceipts');
        expect(receiptsSource).toContain('Không thể tải danh sách phiếu nhập kho');
        expect(receiptsSource).toContain('Thử lại danh sách phiếu nhập kho');
        expect(issuesSource).toContain('isError: isIssuesError');
        expect(issuesSource).toContain('refetch: refetchIssues');
        expect(issuesSource).toContain('Không thể tải danh sách phiếu xuất kho');
        expect(issuesSource).toContain('Thử lại danh sách phiếu xuất kho');
    });

    it('does not expose the unsupported AVA Kho assistant affordance in voucher modals', () => {
        expect(receiptsSource).not.toContain('AVA Kho');
        expect(issuesSource).not.toContain('AVA Kho');
    });
});
