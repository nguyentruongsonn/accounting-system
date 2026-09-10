import { describe, expect, it } from 'vitest';
import legacySource from '../assets/FixedAssets.tsx?raw';
import depreciationSource from './FixedAssetDepreciations.tsx?raw';
import runDepreciationSource from './modals/RunDepreciationModal.tsx?raw';
import revaluationSource from './modals/AssetRevaluationModal.tsx?raw';
import disposalSource from './modals/AssetDisposalModal.tsx?raw';

describe('fixed asset action evidence boundary', () => {
    it('requires server-persisted lifecycle evidence before reporting success', () => {
        const source = [legacySource, depreciationSource, runDepreciationSource, revaluationSource, disposalSource].join('\n');

        expect(source).toContain('depreciationLog.id === undefined || depreciationLog.id === null');
        expect(source).toContain('revaluation.id === undefined || revaluation.id === null');
        expect(source).toContain('disposal.id === undefined || disposal.id === null');
        expect(source).toContain('Máy chủ không xác nhận đã xóa kỳ khấu hao');
        expect(source).toContain('!Array.isArray(data.lines) || data.total_amount === undefined');
        expect(source).toContain('Máy chủ không trả về bản xem trước khấu hao hợp lệ');
        expect(source).not.toContain('VAS TT200');
        expect(source).not.toContain('Tiền mặt (Nợ TK 1111)');
        expect(source).not.toContain('Chưa thu tiền (Nợ TK 131)');
        expect(source).toContain('payment_method: values.payment_method');
        expect(source).toContain('Chọn hình thức thu tiền');
    });
});
