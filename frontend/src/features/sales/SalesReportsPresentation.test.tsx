import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { describe, expect, it, vi } from 'vitest';
import { SalesReports } from './SalesReports';

vi.mock('@tanstack/react-query', async (importOriginal) => ({
  ...await importOriginal<typeof import('@tanstack/react-query')>(),
  useQuery: vi.fn(({ queryKey }: { queryKey: unknown[] }) => queryKey[0] === 'sales-report'
    ? {
        data: {
          data: [],
          totals: { sub_total: '0.00', discount_amount: '0.00', tax_amount: '0.00', total_amount: '0.00' },
          meta: { report_key: 'sales', date_basis: 'accounting_date', source: [], posted_only: true, from_date: null, to_date: null, customer_id: null },
        },
        isFetching: false,
        isError: false,
        error: null,
        refetch: vi.fn(),
      }
    : {
        data: [],
        isLoading: false,
        isError: false,
        refetch: vi.fn(),
      }),
}));

describe('sales report embedded presentation', () => {
  it('keeps filters and report actions in one toolbar outside the table surface', () => {
    const { container } = render(
      <MemoryRouter>
        <SalesReports embedded active />
      </MemoryRouter>,
    );

    const toolbar = container.querySelector<HTMLElement>('[data-ui="page-toolbar"]');
    const tableSurface = container.querySelector<HTMLElement>('[data-ui="table-surface"]');

    expect(toolbar).toBeInTheDocument();
    expect(tableSurface).toBeInTheDocument();
    expect(toolbar).toContainElement(screen.getByText('Kỳ hạch toán'));
    expect(toolbar).toContainElement(screen.getByRole('button', { name: /Tải lại/ }));
    expect(tableSurface).not.toContainElement(toolbar);
  });
});
