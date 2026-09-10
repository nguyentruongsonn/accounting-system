import { describe, expect, it } from 'vitest';
import salesWorkspace from '../sales/SalesWorkspace.tsx?raw';
import purchaseWorkspace from '../purchase/PurchaseWorkspace.tsx?raw';
import fixedAssetWorkspace from '../fixed-asset/FixedAssetWorkspace.tsx?raw';
import glWorkspace from '../gl/GLWorkspace.tsx?raw';

describe('operational report labels', () => {
  it('does not present report links as unpublished placeholders', () => {
    for (const source of [salesWorkspace, purchaseWorkspace, fixedAssetWorkspace]) {
      expect(source).not.toContain('— chưa phát hành');
    }
  });

  it('does not advertise item-level sales totals when the screen is voucher-level', () => {
    expect(salesWorkspace).not.toContain('Tổng hợp doanh thu theo mặt hàng');
    expect(salesWorkspace).not.toContain('Nhật ký bán hàng');
    expect(salesWorkspace).toContain("label: 'Báo cáo bán hàng'");
  });

  it('does not advertise item-level purchase totals when the screen is voucher-level', () => {
    expect(purchaseWorkspace).not.toContain('Tổng hợp mua hàng theo mặt hàng');
    expect(purchaseWorkspace).not.toContain('Nhật ký mua hàng');
    expect(purchaseWorkspace).toContain("<span>Tổng hợp mua hàng</span>");
  });

  it('keeps the operational general-ledger workspace separate from statutory wording', () => {
    expect(glWorkspace).not.toContain('báo cáo tài chính.');
    expect(glWorkspace).not.toContain('Tổng hợp & BCTC');
    expect(glWorkspace).toContain('báo cáo nội bộ.');
  });
});
