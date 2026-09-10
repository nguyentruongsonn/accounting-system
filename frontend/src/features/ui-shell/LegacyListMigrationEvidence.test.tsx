import { render } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import Items from '../inventory/Items';
import Periods from '../gl/Periods';
import InventoryTransfers from '../inventory/InventoryTransfers';
import InventoryStockCounts from '../inventory/InventoryStockCounts';
import itemsSource from '../inventory/Items.tsx?raw';
import periodsSource from '../gl/Periods.tsx?raw';
import transfersSource from '../inventory/InventoryTransfers.tsx?raw';
import stockCountsSource from '../inventory/InventoryStockCounts.tsx?raw';

vi.mock('@tanstack/react-query', () => ({
  useQuery: () => ({ data: [], isLoading: false, isError: false, error: null, refetch: vi.fn() }),
  useMutation: () => ({ mutate: vi.fn(), mutateAsync: vi.fn(), isPending: false }),
  useQueryClient: () => ({ invalidateQueries: vi.fn() }),
}));

vi.mock('../../api/axios', () => ({
  default: { get: vi.fn().mockResolvedValue({ data: [] }), post: vi.fn(), put: vi.fn(), delete: vi.fn() },
}));

vi.mock('../sales/modals/SalesReturnModal', () => ({ SalesReturnModal: () => null }));
vi.mock('../purchase/modals/PurchaseDiscountModal', () => ({ PurchaseDiscountModal: () => null }));
vi.mock('../../components/misa', () => ({
  VoucherPrintModal: () => null,
  useVoucherShortcuts: () => undefined,
}));

const pages = [Items, Periods, InventoryTransfers, InventoryStockCounts];
const sources = [itemsSource, periodsSource, transfersSource, stockCountsSource];

describe('legacy list migration structure', () => {
  it.each(sources)('declares the canonical list primitives', (source) => {
    expect(source).toContain("from '../../components/layout/PageShell'");
    expect(source).toContain("from '../../components/layout/PageToolbar'");
    expect(source).toContain("from '../../components/layout/DataTableSurface'");
    expect(source).toContain('<PageShell');
    expect(source).toContain('<PageToolbar');
    expect(source).toContain('<DataTableSurface');
  });

  it.each(pages)('renders one canonical shell, toolbar, body, and table surface', (Page) => {
    const { container } = render(<Page />);
    const shell = container.querySelector('[data-ui="page-shell"]');

    expect(shell).toBeInTheDocument();
    expect(shell?.querySelector('[data-ui="page-toolbar"]')).toBeInTheDocument();
    expect(shell?.querySelector('[data-ui="page-body"]')).toBeInTheDocument();
    expect(shell?.querySelector('[data-ui="table-surface"]')).toBeInTheDocument();
    expect(shell?.querySelector('[data-ui="table-scroll"] table')).toBeInTheDocument();
    expect(container.querySelectorAll('[data-ui="page-shell"]')).toHaveLength(1);
  });
});
