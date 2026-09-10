import onboardingSource from './settings/OnboardingReadiness.tsx?raw';
import salesSource from './sales/SalesWorkspace.tsx?raw';
import purchaseSource from './purchase/PurchaseWorkspace.tsx?raw';
import cashSource from './cash/CashWorkspace.tsx?raw';
import inventorySource from './inventory/InventoryWorkspace.tsx?raw';
import reportsSource from './reports/ReportsWorkspace.tsx?raw';
import glSource from './gl/GLWorkspace.tsx?raw';
import { describe, expect, it } from 'vitest';

describe('SMB core-process acceptance evidence boundary', () => {
  it('R0 keeps regime fail-closed while exposing server-backed opening setup', () => {
    expect(onboardingSource).toContain('parseRegimeEvidence');
    expect(onboardingSource).not.toContain('Không mặc định TT99 hay TT133');
    expect(onboardingSource).toContain('Không có dữ liệu thay thế');
    expect(onboardingSource).toContain('Có thể nhập và đối chiếu');
    expect(onboardingSource).toContain('/settings/opening-balances');
  });

  it('R1 keeps sales and purchase handoff navigation-only', () => {
    expect(salesSource).toContain('useNavigate');
    expect(salesSource).toContain("navigate('/cash/receipts?action=create')");
    expect(purchaseSource).not.toMatch(/api\.(post|put|patch|delete)\s*\(/);
    expect(salesSource).not.toMatch(/api\.(post|put|patch|delete)\s*\(/);
  });

  it('R2 removes unsupported cash payment workflows from the process workspace', () => {
    expect(cashSource).not.toContain('Nộp thuế (chưa khả dụng)');
    expect(cashSource).not.toContain('Nộp bảo hiểm (chưa khả dụng)');
    expect(cashSource).not.toContain('Trả lương (chưa khả dụng)');
  });

  it('R3 exposes draft transfer/count capture without ledger authority', () => {
    expect(inventorySource).toContain("import InventoryTransfers from './InventoryTransfers'");
    expect(inventorySource).toContain("import InventoryStockCounts from './InventoryStockCounts'");
    expect(inventorySource).not.toContain('inventory-transfer-unavailable');
    expect(inventorySource).toContain('<InventoryTransfers embedded />');
    expect(inventorySource).toMatch(/<InventoryStockCounts\s+embedded(?:\s|>)/);
  });

  it('R4 requires report capability evidence and keeps GL unavailable states fail-closed', () => {
    expect(reportsSource).toContain('Không xác minh được capability từ máy chủ');
    expect(reportsSource).toContain('không tự mở báo cáo bằng danh sách hard-code');
    expect(glSource).toContain('danh sách được fail-closed');
    expect(glSource).toContain('Hệ thống tài khoản (COA)');
    expect(glSource).not.toContain('Đối tượng THCP (chưa khả dụng)');
    expect(glSource).not.toContain('Evidence cutoff AP/AR');
    expect(glSource).not.toContain('Tùy chọn (chưa khả dụng)');
    expect(glSource).not.toContain("Mở tùy chọn cấu hình Tổng hợp");
  });
});
