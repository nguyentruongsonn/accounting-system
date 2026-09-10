import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import * as XLSX from 'xlsx';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { useQuery } from '@tanstack/react-query';
import { MemoryRouter, useLocation } from 'react-router-dom';
import PurchaseReports from '../purchase/PurchaseReports';
import SalesReports from '../sales/SalesReports';

vi.mock('xlsx', async (importOriginal) => ({
  ...await importOriginal<typeof import('xlsx')>(),
  writeFile: vi.fn(),
}));

vi.mock('@tanstack/react-query', async (importOriginal) => ({
  ...await importOriginal<typeof import('@tanstack/react-query')>(),
  useQuery: vi.fn(),
}));
afterEach(cleanup);

describe.each([
  ['purchase', PurchaseReports],
  ['sales', SalesReports],
] as const)('%s report freshness', (kind, Report) => {
  it.each(['fetching', 'error', 'ready'])('controls cached values and export while %s', (state) => {
    vi.mocked(useQuery).mockImplementation(({ queryKey }: any) => ({
      data: queryKey[0] === `${kind}-report` ? {
        data: [{ key: 'old', id: 15, source_type: `${kind}_invoice`, voucher_number: 'OLD-PERIOD-001', signed_total_amount: '100.00', is_posted: true }],
        totals: { sub_total: '100.00', discount_amount: '0.00', tax_amount: '0.00', total_amount: '100.00' },
      } : [],
      isFetching: queryKey[0] === `${kind}-report` && state === 'fetching',
      isError: queryKey[0] === `${kind}-report` && state === 'error',
      isLoading: false,
      refetch: vi.fn(),
    }) as any);
    render(<MemoryRouter><Report embedded /></MemoryRouter>);
    const exportButton = screen.getByRole('button', { name: /Xuất CSV/ });
    const excelButton = screen.getByRole('button', { name: /Xuất Excel/ });
    const printButton = screen.getByRole('button', { name: /^printer In$/ });
    if (state === 'ready') {
      expect(screen.getByText('OLD-PERIOD-001')).toBeInTheDocument();
      expect(exportButton).toBeEnabled();
      expect(printButton).toBeEnabled();
      expect(excelButton).toBeEnabled();
      fireEvent.click(excelButton);
      const call = vi.mocked(XLSX.writeFile).mock.calls.at(-1)!;
      expect(call[1]).toBe(`bao-cao-${kind}.xlsx`);
      const workbook = call[0];
      const sheet = workbook.Sheets[workbook.SheetNames[0]];
      const values = XLSX.utils.sheet_to_json(sheet);
      expect(values).toEqual([
        expect.objectContaining({ 'Số chứng từ': 'OLD-PERIOD-001', 'Tổng tiền': '100.00' }),
        expect.objectContaining({ 'Diễn giải': 'Tổng cộng', 'Tổng tiền': '100.00' }),
      ]);
      expect(sheet.G2.t).toBe('s');
    } else {
      expect(screen.queryByText('OLD-PERIOD-001')).not.toBeInTheDocument();
      expect(exportButton).toBeDisabled();
      expect(printButton).toBeDisabled();
      expect(excelButton).toBeDisabled();
    }
  });

  it('opens the exact source record from the voucher number', () => {
    vi.mocked(useQuery).mockImplementation(({ queryKey }: any) => ({
      data: queryKey[0] === `${kind}-report` ? {
        data: [{ key: 'source', id: 15, source_type: `${kind}_invoice`, voucher_number: 'SOURCE-001', signed_total_amount: '100.00', is_posted: true }],
        totals: { sub_total: '100.00', discount_amount: '0.00', tax_amount: '0.00', total_amount: '100.00' },
      } : [],
      isFetching: false,
      isError: false,
      isLoading: false,
      refetch: vi.fn(),
    }) as any);
    const Location = () => <output>{useLocation().pathname}{useLocation().search}</output>;
    render(<MemoryRouter><Report embedded /><Location /></MemoryRouter>);
    fireEvent.click(screen.getByRole('button', { name: 'Mở chứng từ SOURCE-001' }));
    expect(screen.getByText(`/${kind}/invoices?source_id=15`)).toBeInTheDocument();
  });

  it('renders every filtered row before invoking print', () => {
    const rows = Array.from({ length: 21 }, (_, index) => ({
      key: `row-${index + 1}`,
      id: index + 1,
      source_type: `${kind}_invoice`,
      voucher_number: `SOURCE-${String(index + 1).padStart(3, '0')}`,
      signed_total_amount: '1.00',
      is_posted: true,
    }));
    vi.mocked(useQuery).mockImplementation(({ queryKey }: any) => ({
      data: queryKey[0] === `${kind}-report` ? {
        data: rows,
        totals: { sub_total: '21.00', discount_amount: '0.00', tax_amount: '0.00', total_amount: '21.00' },
      } : [],
      isFetching: false,
      isError: false,
      isLoading: false,
      refetch: vi.fn(),
    }) as any);
    const print = vi.spyOn(window, 'print').mockImplementation(() => {
      expect(screen.getByText('SOURCE-021')).toBeInTheDocument();
    });
    render(<MemoryRouter><Report embedded /></MemoryRouter>);
    expect(screen.queryByText('SOURCE-021')).not.toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: /^printer In$/ }));
    expect(print).toHaveBeenCalledOnce();
    print.mockRestore();
  });
});
