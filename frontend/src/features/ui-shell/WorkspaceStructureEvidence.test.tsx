import { describe, expect, it } from 'vitest';
import { render, screen } from '@testing-library/react';
import ModuleWorkspace from '../../components/ModuleWorkspace';
import { MisaWorkspaceLayout } from '../../components/misa/MisaWorkspaceLayout';
import cashWorkspace from '../cash/CashWorkspace.tsx?raw';
import purchaseWorkspace from '../purchase/PurchaseWorkspace.tsx?raw';
import salesWorkspace from '../sales/SalesWorkspace.tsx?raw';
import inventoryWorkspace from '../inventory/InventoryWorkspace.tsx?raw';
import fixedAssetWorkspace from '../fixed-asset/FixedAssetWorkspace.tsx?raw';
import glWorkspace from '../gl/GLWorkspace.tsx?raw';
import payrollWorkspace from '../payroll/PayrollWorkspace.tsx?raw';
import reportsWorkspace from '../reports/ReportsWorkspace.tsx?raw';
import bankWorkspace from '../bank/BankWorkspace.tsx?raw';
import moduleWorkspace from '../../components/ModuleWorkspace.tsx?raw';
import misaWorkspaceLayout from '../../components/misa/MisaWorkspaceLayout.tsx?raw';
import budgetWorkspace from '../budget/BudgetWorkspace.tsx?raw';
import costingWorkspace from '../costing/CostingWorkspace.tsx?raw';
import taxWorkspace from '../tax/TaxWorkspace.tsx?raw';

const processSources = [
  cashWorkspace,
  purchaseWorkspace,
  salesWorkspace,
  inventoryWorkspace,
  fixedAssetWorkspace,
  glWorkspace,
];

const directWorkspaceSources = [
  cashWorkspace,
  purchaseWorkspace,
  salesWorkspace,
  bankWorkspace,
];

const expectSeparatedWorkspaceRegions = (container: HTMLElement, tabLabel: string, bodyText: string) => {
  const workspace = container.querySelector('[data-ui="workspace"]');
  const frame = workspace?.querySelector(':scope > .ui-workspace-frame');
  const tabs = workspace?.querySelector('[data-ui="workspace-tabs"]');
  const body = workspace?.querySelector('[data-ui="workspace-body"]');

  expect(workspace).not.toBeNull();
  expect(frame).not.toBeNull();
  expect(frame?.className).not.toContain('misa-workspace-card');
  expect(frame?.className).not.toMatch(/(^|\s)p-(2|4)(\s|$)/);
  expect(tabs).not.toBeNull();
  expect(body).not.toBeNull();
  expect(tabs?.contains(body ?? null)).toBe(false);
  expect(body?.contains(tabs ?? null)).toBe(false);
  const tabControl = screen.queryByRole('tab', { name: tabLabel })
    ?? screen.getByRole('button', { name: tabLabel });
  expect(tabs).toContainElement(tabControl);
  expect(body).toContainElement(screen.getByText(bodyText));
};

describe('workspace and report structure', () => {
  it('uses shared page shell and toolbar adapters', () => {
    for (const source of processSources) {
      expect(source).toContain("from '../../components/layout/PageShell'");
      expect(source).toContain('<PageShell');
      if (source !== cashWorkspace) {
        expect(source).toContain("from '../../components/layout/PageToolbar'");
        expect(source).toContain('<PageToolbar');
      }
    }
  });

  it('keeps process-canvas content in the page body instead of a new horizontal page root', () => {
    for (const source of processSources.slice(0, 6)) {
      expect(source).toContain('misa-ca-process-container');
    }
  });

  it('gives every direct tabbed workspace one semantic tab strip and body frame', () => {
    for (const source of directWorkspaceSources) {
      expect(source.match(/data-ui="workspace"/g)).toHaveLength(1);
      expect(source.match(/data-ui="workspace-tabs"/g)).toHaveLength(1);
      expect(source.match(/data-ui="workspace-body"/g)).toHaveLength(1);
      expect(source).not.toContain('misa-workspace-card');
    }
  });

  it('makes the shared workspace layouts own one tab strip and one body frame', () => {
    for (const source of [moduleWorkspace, misaWorkspaceLayout]) {
      expect(source.match(/data-ui="workspace"/g)).toHaveLength(1);
      expect(source.match(/data-ui="workspace-tabs"/g)).toHaveLength(1);
      expect(source.match(/data-ui="workspace-body"/g)).toHaveLength(1);
      expect(source).not.toContain('misa-workspace-card');
    }
  });

  it('renders ModuleWorkspace tab navigation beside, not around, its active tab body', () => {
    const { container } = render(
      <ModuleWorkspace
        items={[{ key: 'trial-balance', label: 'Bảng cân đối tài khoản', children: <span>Báo cáo cân đối</span> }]}
      />,
    );

    expectSeparatedWorkspaceRegions(container, 'Bảng cân đối tài khoản', 'Báo cáo cân đối');
  });

  it('renders MisaWorkspaceLayout with one card-free frame around sibling tab and body regions', () => {
    const { container } = render(
      <MisaWorkspaceLayout
        tabs={[{ key: 'process', label: 'Quy trình' }]}
        activeTabKey="process"
        onTabChange={() => undefined}
      >
        <span>Nội dung quy trình</span>
      </MisaWorkspaceLayout>,
    );

    expectSeparatedWorkspaceRegions(container, 'Quy trình', 'Nội dung quy trình');
  });

  it('exposes Misa workspace tabs with selected state and a controlled body', () => {
    render(
      <MisaWorkspaceLayout
        tabs={[{ key: 'process', label: 'Quy trình' }, { key: 'journal', label: 'Chứng từ' }]}
        activeTabKey="process"
        onTabChange={() => undefined}
      >
        <span>Nội dung quy trình</span>
      </MisaWorkspaceLayout>,
    );

    const selectedTab = screen.getByRole('tab', { name: 'Quy trình' });
    const otherTab = screen.getByRole('tab', { name: 'Chứng từ' });
    expect(selectedTab).toHaveAttribute('aria-selected', 'true');
    expect(otherTab).toHaveAttribute('aria-selected', 'false');
    expect(selectedTab).toHaveAttribute('aria-controls', 'workspace-body');
    expect(document.getElementById('workspace-body')).toContainElement(screen.getByText('Nội dung quy trình'));
  });

  it('keeps layout-backed and route-controlled workspaces on the shared shell', () => {
    for (const source of [inventoryWorkspace, fixedAssetWorkspace, glWorkspace, payrollWorkspace]) {
      expect(source).toContain('<MisaWorkspaceLayout');
    }
    expect(reportsWorkspace).toContain('<ModuleWorkspace');
    expect(reportsWorkspace).toContain('activeKey={activeKey}');
    expect(reportsWorkspace).toContain('onChange={(key) =>');
  });

  it('leaves fail-closed landing routes minimal instead of fabricating tab content', () => {
    expect(taxWorkspace).toContain('<TaxComplianceBoundaryNotice');
    expect(budgetWorkspace).toContain('Lập dự toán chưa khả dụng');
    expect(costingWorkspace).toContain('Tổng hợp chi phí theo mẫu cũ chưa khả dụng');
    for (const source of [taxWorkspace, budgetWorkspace, costingWorkspace]) {
      expect(source).not.toContain('data-ui="workspace-tabs"');
    }
  });
});
