import { render } from '@testing-library/react';
import { afterAll, beforeAll, describe, expect, it, vi } from 'vitest';
import Customers from '../master/Customers';
import Suppliers from '../master/Suppliers';
import ChartOfAccounts from '../master/ChartOfAccounts';
import Employees from '../master/Employees';

vi.mock('@tanstack/react-query', () => ({
  useQuery: () => ({ data: [], isLoading: false, isError: false, refetch: vi.fn() }),
  useMutation: () => ({ mutate: vi.fn(), mutateAsync: vi.fn(), isPending: false }),
  useQueryClient: () => ({ invalidateQueries: vi.fn() }),
}));

vi.mock('../../api/axios', () => ({
  default: { get: vi.fn().mockResolvedValue({ data: { data: [] } }), post: vi.fn(), put: vi.fn(), delete: vi.fn() },
}));

vi.mock('../../components/misa', () => ({
  QuickAddContactModal: () => null,
}));

const representativePages = [
  Customers, Suppliers, ChartOfAccounts, Employees,
];

const catalogPageCopy = [
  ['customers', Customers, ['DANH MỤC', 'Khách hàng', 'Quản lý thông tin khách hàng do máy chủ cung cấp.']],
  ['suppliers', Suppliers, ['DANH MỤC', 'Nhà cung cấp', 'Quản lý thông tin nhà cung cấp do máy chủ cung cấp.']],
] as const;

describe('representative list page structure', () => {
  const nativeGetComputedStyle = window.getComputedStyle.bind(window);

  beforeAll(() => {
    vi.spyOn(window, 'getComputedStyle').mockImplementation((element) => nativeGetComputedStyle(element));
  });

  afterAll(() => {
    vi.restoreAllMocks();
  });

  it.each(representativePages)('mounts %p inside one compact shared list surface', (Page) => {
    const { container } = render(<Page />);
    const shell = container.querySelector('[data-ui="page-shell"]');
    const tableSurface = shell?.querySelector('[data-ui="table-surface"]');

    expect(shell).toBeInTheDocument();
    expect(shell?.querySelector('[data-ui="page-toolbar"]')).toBeInTheDocument();
    expect(tableSurface).toBeInTheDocument();
    expect(tableSurface).toHaveClass('ui-table-surface');
    expect(tableSurface?.querySelector('.ant-table-placeholder')).toBeInTheDocument();
    expect(tableSurface?.querySelector('.ant-empty')).toBeInTheDocument();
    expect(container.querySelectorAll('[data-ui="page-shell"]')).toHaveLength(1);
    expect(shell?.querySelector('.apple-page-header, .apple-compact-header, .misa-page-container')).toBeNull();
  });

  it.each(catalogPageCopy)('does not render the removed page title slot for %s', (_name, Page, copy) => {
    const { container, queryByText } = render(<Page />);
    const title = container.querySelector('[data-region="title"]');

    expect(title).toBeNull();
    copy.forEach((text) => expect(queryByText(text)).not.toBeInTheDocument());
  });
});
