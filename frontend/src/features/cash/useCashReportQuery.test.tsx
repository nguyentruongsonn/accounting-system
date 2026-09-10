import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import api from '../../api/axios';
import type { CashReportCode, CashReportFilters } from './cashReportContract';
import { useCashReportQuery } from './useCashReportQuery';

vi.mock('../../api/axios', () => ({ default: { get: vi.fn() } }));

const mockedApi = vi.mocked(api);

const baseFilters: CashReportFilters = {
  date_from: '2026-08-01',
  date_to: '2026-08-31',
  status: 'posted',
  search: '',
};

function responseFor(code: CashReportCode, filters: CashReportFilters = baseFilters) {
  const report: {
    code: CashReportCode;
    name: string;
    date_from: string;
    date_to: string;
    status: CashReportFilters['status'];
    cash_account: string | null;
    search: string;
  } = {
    code,
    name: code,
    date_from: filters.date_from,
    date_to: filters.date_to,
    status: filters.status,
    cash_account: filters.cash_account?.trim() || null,
    search: filters.search?.trim() ?? '',
  };
  if (code === 'S03a1-DNN' || code === 'S03a2-DNN') {
    return { report, columns: [
      { key: 'posting_date', label: 'Posting', type: 'date' }, { key: 'voucher_date', label: 'Voucher', type: 'date' },
      { key: 'voucher_number', label: 'No', type: 'text' }, { key: 'contact_name', label: 'Contact', type: 'text' },
      { key: 'description', label: 'Description', type: 'text' }, { key: 'cash_account', label: 'Cash', type: 'text' },
      { key: 'counterpart_account', label: 'Counter', type: 'text' }, { key: 'amount', label: 'Amount', type: 'money' },
      { key: 'status', label: 'Status', type: 'status' },
    ], summary: code === 'S03a1-DNN' ? { row_count: 1, total_receipts: 25 } : { row_count: 1, total_payments: 25 }, rows: [{
      key: `${code}:row`, posting_date: '2026-08-10', voucher_date: '2026-08-10', voucher_number: 'PT-10',
      contact_name: '', description: '', cash_account: '1111', counterpart_account: '131', amount: 25, status: 'posted',
    }] };
  }
  if (code === 'CA-01') return { report, columns: [
    { key: 'posting_date', label: 'Date', type: 'date' }, { key: 'opening_balance', label: 'Open', type: 'money' },
    { key: 'total_receipts', label: 'Receipt', type: 'money' }, { key: 'total_payments', label: 'Payment', type: 'money' },
    { key: 'closing_balance', label: 'Close', type: 'money' },
  ], summary: { opening_balance: 2, total_receipts: 25, total_payments: 0, closing_balance: 27 }, rows: [] };
  if (code === 'CA-02') return { report, columns: [
    { key: 'posting_date', label: 'Date', type: 'date' }, { key: 'direction', label: 'Direction', type: 'text' },
    { key: 'transaction_count', label: 'Count', type: 'number' }, { key: 'amount', label: 'Amount', type: 'money' },
  ], summary: { total_receipts: 25, total_payments: 0, net_cash_flow: 25 }, rows: [] };
  return { report, columns: [
    { key: 'posting_date', label: 'Posting', type: 'date' }, { key: 'voucher_date', label: 'Voucher', type: 'date' },
    { key: 'voucher_number', label: 'No', type: 'text' }, { key: 'direction', label: 'Direction', type: 'text' },
    { key: 'contact_name', label: 'Contact', type: 'text' }, { key: 'description', label: 'Description', type: 'text' },
    { key: 'cash_account', label: 'Cash', type: 'text' }, { key: 'counterpart_account', label: 'Counter', type: 'text' },
    { key: 'receipt_amount', label: 'Receipt', type: 'money' }, { key: 'payment_amount', label: 'Payment', type: 'money' },
    { key: 'running_balance', label: 'Balance', type: 'money' },
  ], summary: { opening_balance: 2, total_receipts: 25, total_payments: 0, closing_balance: 27 }, rows: [] };
}

function Probe({ selection, active = true }: { selection: { code: CashReportCode; filters: CashReportFilters } | null; active?: boolean }) {
  const query = useCashReportQuery(selection, active);
  return <>
    <output data-testid="state">{query.isError ? 'error' : query.isPending ? 'pending' : 'success'}</output>
    <output data-testid="code">{query.data?.report.code ?? 'none'}</output>
    <output data-testid="name">{query.data?.report.name ?? 'none'}</output>
    <button onClick={() => { void query.refetch(); }}>Retry</button>
  </>;
}

function renderQuery(selection: { code: CashReportCode; filters: CashReportFilters } | null, active = true) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: 0 } } });
  const wrapper = ({ children }: { children: ReactNode }) => <QueryClientProvider client={client}>{children}</QueryClientProvider>;
  const view = render(<Probe selection={selection} active={active} />, { wrapper });
  return { ...view, client };
}

function deferred<T>() {
  let resolve!: (value: T) => void;
  const promise = new Promise<T>((res) => { resolve = res; });
  return { promise, resolve };
}

afterEach(() => {
  vi.clearAllMocks();
});

describe('useCashReportQuery', () => {
  it.each<CashReportCode>(['S03a1-DNN', 'S03a2-DNN', 'CA-01', 'CA-02', 'CA-03'])('requests the %s endpoint with all normalised filters', async (code) => {
    const filters = { ...baseFilters, status: code.startsWith('S') ? 'all' as const : 'posted' as const, search: '  invoice 10  ', cash_account: '1111' };
    mockedApi.get.mockResolvedValue({ data: { data: responseFor(code, filters) } } as never);
    const { unmount, client } = renderQuery({ code, filters });

    await waitFor(() => expect(screen.getByTestId('code')).toHaveTextContent(code));
    const [path, config] = mockedApi.get.mock.calls[0];
    expect(path).toBe(`/cash/reports/${code}`);
    expect(config).toMatchObject({ params: { date_from: '2026-08-01', date_to: '2026-08-31', status: filters.status, search: 'invoice 10', cash_account: '1111' } });
    expect((config as { signal: AbortSignal }).signal).toBeInstanceOf(AbortSignal);
    unmount();
    client.clear();
  });

  it('does not request when selection is null or inactive', async () => {
    const first = renderQuery(null);
    await waitFor(() => expect(screen.getByTestId('state')).toHaveTextContent('pending'));
    expect(mockedApi.get).not.toHaveBeenCalled();
    first.unmount();
    first.client.clear();

    const second = renderQuery({ code: 'CA-02', filters: baseFilters }, false);
    await waitFor(() => expect(screen.getByTestId('state')).toHaveTextContent('pending'));
    expect(mockedApi.get).not.toHaveBeenCalled();
    second.unmount();
    second.client.clear();
  });

  it('changes query identity for status, cash account, and search independently', async () => {
    mockedApi.get.mockImplementation((_url, config) => Promise.resolve({
      data: { data: responseFor('S03a1-DNN', config?.params as CashReportFilters) },
    }) as never);
    const first = renderQuery({ code: 'S03a1-DNN', filters: baseFilters });
    await waitFor(() => expect(screen.getByTestId('code')).toHaveTextContent('S03a1-DNN'));
    first.rerender(<QueryClientProvider client={first.client}><Probe selection={{ code: 'S03a1-DNN', filters: { ...baseFilters, status: 'draft' } }} /></QueryClientProvider>);
    await waitFor(() => expect(mockedApi.get).toHaveBeenCalledTimes(2));
    first.rerender(<QueryClientProvider client={first.client}><Probe selection={{ code: 'S03a1-DNN', filters: { ...baseFilters, cash_account: '1111' } }} /></QueryClientProvider>);
    await waitFor(() => expect(mockedApi.get).toHaveBeenCalledTimes(3));
    first.rerender(<QueryClientProvider client={first.client}><Probe selection={{ code: 'S03a1-DNN', filters: { ...baseFilters, search: 'next' } }} /></QueryClientProvider>);
    await waitFor(() => expect(mockedApi.get).toHaveBeenCalledTimes(4));
    expect(mockedApi.get.mock.calls.map(([, config]) => (config as { params: CashReportFilters }).params)).toEqual([
      { ...baseFilters, search: '' }, { ...baseFilters, status: 'draft', search: '' },
      { ...baseFilters, cash_account: '1111', search: '' }, { ...baseFilters, search: 'next' },
    ]);
    first.unmount();
    first.client.clear();
  });

  it('clears old data, cancels its request, and ignores a late previous response', async () => {
    const oldRequest = deferred<unknown>();
    const newRequest = deferred<unknown>();
    mockedApi.get.mockImplementationOnce(() => oldRequest.promise as never).mockImplementationOnce(() => newRequest.promise as never);
    const view = renderQuery({ code: 'S03a1-DNN', filters: baseFilters });
    await waitFor(() => expect(mockedApi.get).toHaveBeenCalledTimes(1));
    const oldSignal = (mockedApi.get.mock.calls[0][1] as { signal: AbortSignal }).signal;
    view.rerender(<QueryClientProvider client={view.client}><Probe selection={{ code: 'CA-02', filters: baseFilters }} /></QueryClientProvider>);
    await waitFor(() => expect(mockedApi.get).toHaveBeenCalledTimes(2));
    expect(oldSignal.aborted).toBe(true);
    expect(screen.getByTestId('code')).toHaveTextContent('none');
    newRequest.resolve({ data: { data: responseFor('CA-02') } });
    await waitFor(() => expect(screen.getByTestId('code')).toHaveTextContent('CA-02'));
    oldRequest.resolve({ data: { data: responseFor('S03a1-DNN') } });
    await waitFor(() => expect(screen.getByTestId('code')).toHaveTextContent('CA-02'));
    view.unmount();
    view.client.clear();
  });

  it('exposes malformed, identity-mismatched, 422, and network failures and supports retry', async () => {
    mockedApi.get.mockResolvedValueOnce({ data: { data: { report: {} } } } as never);
    const malformed = renderQuery({ code: 'CA-02', filters: baseFilters });
    await waitFor(() => expect(screen.getByTestId('state')).toHaveTextContent('error'));
    malformed.unmount();
    malformed.client.clear();

    const wrongReport = responseFor('CA-02');
    wrongReport.report.code = 'CA-01';
    mockedApi.get.mockResolvedValueOnce({ data: { data: wrongReport } } as never);
    const mismatch = renderQuery({ code: 'CA-02', filters: baseFilters });
    await waitFor(() => expect(screen.getByTestId('state')).toHaveTextContent('error'));
    mismatch.unmount();
    mismatch.client.clear();

    mockedApi.get.mockRejectedValueOnce({ response: { status: 422 } } as never).mockRejectedValueOnce(new Error('offline') as never).mockResolvedValueOnce({ data: { data: responseFor('CA-02') } } as never);
    const retryable = renderQuery({ code: 'CA-02', filters: baseFilters });
    await waitFor(() => expect(screen.getByTestId('state')).toHaveTextContent('error'));
    fireEvent.click(screen.getByRole('button', { name: 'Retry' }));
    await waitFor(() => expect(screen.getByTestId('state')).toHaveTextContent('error'));
    fireEvent.click(screen.getByRole('button', { name: 'Retry' }));
    await waitFor(() => expect(screen.getByTestId('code')).toHaveTextContent('CA-02'));
    retryable.unmount();
    retryable.client.clear();
  });

  it.each([
    ['period', 'CA-02', baseFilters, (payload: ReturnType<typeof responseFor>) => { payload.report.date_to = '2026-09-01'; }],
    ['status', 'S03a1-DNN', { ...baseFilters, status: 'draft' as const }, (payload: ReturnType<typeof responseFor>) => { payload.report.status = 'posted'; }],
    ['cash account', 'CA-03', { ...baseFilters, cash_account: '1111' }, (payload: ReturnType<typeof responseFor>) => { payload.report.cash_account = null; }],
    ['search', 'CA-02', { ...baseFilters, search: 'needle' }, (payload: ReturnType<typeof responseFor>) => { payload.report.search = ''; }],
  ] as const)('treats a mismatched returned %s as an error', async (_field, code, filters, mutate) => {
    const payload = responseFor(code, filters);
    mutate(payload);
    mockedApi.get.mockResolvedValue({ data: { data: payload } } as never);
    const view = renderQuery({ code, filters });

    await waitFor(() => expect(screen.getByTestId('state')).toHaveTextContent('error'));
    view.unmount();
    view.client.clear();
  });

  it('refreshes when reactivated or remounted despite production-like fresh-cache defaults', async () => {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: 60_000, staleTime: 300_000, refetchOnMount: false } } });
    const selection = { code: 'CA-02' as const, filters: baseFilters };
    const initial = responseFor('CA-02');
    initial.report.name = 'Initial server result';
    const reactivated = responseFor('CA-02');
    reactivated.report.name = 'Reactivated server result';
    const remounted = responseFor('CA-02');
    remounted.report.name = 'Remounted server result';
    mockedApi.get
      .mockResolvedValueOnce({ data: { data: initial } } as never)
      .mockResolvedValueOnce({ data: { data: reactivated } } as never)
      .mockResolvedValueOnce({ data: { data: remounted } } as never);

    const first = render(<QueryClientProvider client={client}><Probe selection={selection} /></QueryClientProvider>);
    await waitFor(() => expect(screen.getByTestId('name')).toHaveTextContent('Initial server result'));
    first.rerender(<QueryClientProvider client={client}><Probe selection={selection} active={false} /></QueryClientProvider>);
    first.rerender(<QueryClientProvider client={client}><Probe selection={selection} /></QueryClientProvider>);
    await waitFor(() => expect(screen.getByTestId('name')).toHaveTextContent('Reactivated server result'));
    first.unmount();

    const second = render(<QueryClientProvider client={client}><Probe selection={selection} /></QueryClientProvider>);
    await waitFor(() => expect(screen.getByTestId('name')).toHaveTextContent('Remounted server result'));
    second.unmount();
    client.clear();
  });
});
