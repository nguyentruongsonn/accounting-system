import { describe, expect, it } from 'vitest';
import receipts from './InventoryReceipts.tsx?raw';
import issues from './InventoryIssues.tsx?raw';

describe('inventory amount evidence boundary', () => {
    it('does not render zero for a missing server amount or line evidence', () => {
        for (const source of [receipts, issues]) {
            expect(source).toContain("total === null || total === undefined");
            expect(source).toContain('const lineTotal = Array.isArray(record.lines)');
            expect(source).toContain("v.total_amount === null || v.total_amount === undefined || v.total_amount === ''");
            expect(source).toContain('apple-muted-text">—');
        }
    });
});
