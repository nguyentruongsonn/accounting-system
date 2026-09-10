import { describe, expect, it } from 'vitest';
import source from './FixedAssetRegistrations.tsx?raw';

describe('fixed-asset registration evidence boundary', () => {
    it('does not invent posting accounts or auto-post a new asset', () => {
        expect(source).not.toContain("credit_account: values.credit_account || '331'");
        expect(source).not.toContain("asset_account: values.asset_account || '211'");
        expect(source).not.toContain("depreciation_account: values.depreciation_account || '2141'");
        expect(source).not.toContain("expense_account: values.expense_account || '6424'");
        expect(source).not.toContain('is_posted: true');
        expect(source).toContain('is_posted: values.is_posted ?? false');
        expect(source).toContain('a.is_active !== false');
        expect(source).toContain("selectedAssetDetail?.credit_account || '—'");
    });
});
