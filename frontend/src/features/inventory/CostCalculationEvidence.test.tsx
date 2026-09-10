import { describe, expect, it } from 'vitest';
import source from './CostCalculation.tsx?raw';

describe('inventory cost-calculation evidence boundary', () => {
    it('requires a server result envelope and does not invent account/status evidence', () => {
        expect(source).toContain('res?.success === true && Array.isArray(res.items)');
        expect(source).toContain('Máy chủ không trả về kết quả tính giá hợp lệ');
        expect(source).toContain("unit: item.unit ?? '—'");
        expect(source).toContain('opening_qty: item.opening_qty ?? null');
        expect(source).toContain('total_cost: item.total_out_cost ?? null');
        expect(source).toContain('unverifiedCount: Array.isArray(res.unverified_cost_items)');
        expect(source).toContain('cost_status_reason: unverifiedReasons.get');
        expect(source).toContain('row.cost_status_reason');
        expect(source).toContain('chưa chốt do phải dùng giá dự phòng');
        expect(source).toContain('summaryInfo.updatedCount == null ?');
        expect(source).toContain("res.updated_issues_count == null ? '—' : res.updated_issues_count");
        expect(source).toContain('const formatNullableNumber');
        expect(source).toContain("v == null ? '—' : `${formatNullableNumber(v)} ₫`");
        expect(source).not.toContain('format(v || 0)');
        expect(source).not.toContain('res.updated_issues_count || 0');
        expect(source).toContain('Tài khoản hạch toán và việc cập nhật sổ cái phải theo cấu hình/mapping');
        expect(source).not.toContain('Sổ cái TK 632');
        expect(source).not.toContain('Nợ 632 / Có 1561');
        expect(source).not.toContain('Xuất Excel (chưa khả dụng)');
        expect(source).toContain("/inventory/cost-calculation/runs");
        expect(source).toContain("['inventory-valuation-runs'");
        expect(source).toContain('Cần tính lại vì có phát sinh kho thay đổi');
        expect(source).toContain('valuationRunQuery.refetch()');
        expect(source).toContain('parseCostCalculationCollection');
        expect(source).toContain('Thử lại danh mục kho');
        expect(source).toContain('Thử lại trạng thái tính giá');
        expect(source).not.toContain('return Array.isArray(data?.data) ? data.data : [];');
        expect(source).toContain("const method = 'weighted_average'");
        expect(source).toContain('Bình quân gia quyền cuối kỳ');
        expect(source).not.toContain('Nhập trước xuất trước (FIFO)');
    });
});
