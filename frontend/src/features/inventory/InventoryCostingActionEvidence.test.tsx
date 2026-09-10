import { describe, expect, it } from 'vitest';
import receiptsSource from './InventoryReceipts.tsx?raw';
import issuesSource from './InventoryIssues.tsx?raw';
import costingSource from '../costing/CostAllocations.tsx?raw';

describe('inventory and costing action evidence boundary', () => {
    it('requires persisted inventory resources and valid costing rows before success', () => {
        expect(receiptsSource).toContain('requirePersistedInventoryDocument(res.data);');
        expect(receiptsSource).toContain('requirePersistedInventoryDocument(response.data, true);');
        expect(issuesSource).toContain('requirePersistedInventoryDocument(res.data);');
        expect(issuesSource).toContain('requirePersistedInventoryDocument(response.data, true);');
        expect(costingSource).toContain('const allocationRows = Array.isArray(apiData)');
        expect(costingSource).toContain('Máy chủ không trả về dữ liệu phân bổ giá thành hợp lệ');
    });
});
