import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import type { ReactNode } from 'react';
import dayjs from 'dayjs';
import { afterAll, afterEach, describe, expect, it, vi } from 'vitest';
import api from '../../api/axios';
import CashReports from './CashReports';
import { CashReportSelector } from './CashReportSelector';
import type { CashReportCode, CashReportFilters } from './cashReportContract';
import { read, utils } from 'xlsx';
import { saveAs } from 'file-saver';

vi.mock('../../api/axios', () => ({ default: { get: vi.fn() } }));
vi.mock('file-saver', () => ({ saveAs: vi.fn() }));
const mockedApi = vi.mocked(api);
const nativeGetComputedStyle = window.getComputedStyle.bind(window);
const computedStyleShim = vi.spyOn(window, 'getComputedStyle').mockImplementation((element) => nativeGetComputedStyle(element));
const filters: CashReportFilters = { date_from: '2026-08-01', date_to: '2026-08-31', status: 'posted', search: '' };
const journalColumns = [
  { key: 'posting_date', label: 'Ngày hạch toán', type: 'date' }, { key: 'voucher_date', label: 'Ngày chứng từ', type: 'date' }, { key: 'voucher_number', label: 'Số chứng từ', type: 'text' }, { key: 'contact_name', label: 'Đối tượng', type: 'text' }, { key: 'description', label: 'Diễn giải', type: 'text' }, { key: 'cash_account', label: 'TK tiền', type: 'text' }, { key: 'counterpart_account', label: 'TK đối ứng', type: 'text' }, { key: 'amount', label: 'Số tiền', type: 'money' }, { key: 'status', label: 'Trạng thái', type: 'status' },
] as const;
function payload(code: CashReportCode, name: string = code, input: CashReportFilters = filters) {
  const report = { code, name, date_from: input.date_from, date_to: input.date_to, status: code.startsWith('S') ? input.status : 'posted', cash_account: input.cash_account?.trim() || null, search: input.search?.trim() || '' } as const;
  if (code.startsWith('S')) return { report, columns: journalColumns, summary: code === 'S03a1-DNN' ? { row_count: 9, total_receipts: 1200 } : { row_count: 9, total_payments: 1200 }, rows: [{ key: `${code}-1`, posting_date: '2026-08-01', voucher_date: '2026-08-01', voucher_number: 'PT-01', contact_name: 'Khách hàng', description: 'Thu tiền', cash_account: '1111', counterpart_account: '131', amount: 1200, status: 'posted' }] };
  if (code === 'CA-01') return { report, columns: [{ key: 'posting_date', label: 'Ngày', type: 'date' }, { key: 'opening_balance', label: 'Đầu kỳ', type: 'money' }, { key: 'total_receipts', label: 'Thu', type: 'money' }, { key: 'total_payments', label: 'Chi', type: 'money' }, { key: 'closing_balance', label: 'Cuối kỳ', type: 'money' }], summary: { opening_balance: 500, total_receipts: 1200, total_payments: 200, closing_balance: 1500 }, rows: [] };
  if (code === 'CA-02') return { report, columns: [{ key: 'posting_date', label: 'Ngày', type: 'date' }, { key: 'direction', label: 'Loại', type: 'text' }, { key: 'transaction_count', label: 'Số dòng', type: 'number' }, { key: 'amount', label: 'Số tiền', type: 'money' }], summary: { total_receipts: 1200, total_payments: 200, net_cash_flow: 1000 }, rows: [] };
  return { report, columns: [{ key: 'posting_date', label: 'Ngày hạch toán', type: 'date' }, { key: 'voucher_date', label: 'Ngày chứng từ', type: 'date' }, { key: 'voucher_number', label: 'Số chứng từ', type: 'text' }, { key: 'direction', label: 'Loại', type: 'text' }, { key: 'contact_name', label: 'Đối tượng', type: 'text' }, { key: 'description', label: 'Diễn giải', type: 'text' }, { key: 'cash_account', label: 'TK tiền', type: 'text' }, { key: 'counterpart_account', label: 'TK đối ứng', type: 'text' }, { key: 'receipt_amount', label: 'Thu', type: 'money' }, { key: 'payment_amount', label: 'Chi', type: 'money' }, { key: 'running_balance', label: 'Tồn', type: 'money' }], summary: { opening_balance: 500, total_receipts: 1200, total_payments: 200, closing_balance: 1500 }, rows: [] };
}
function renderPage(active = true) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  const wrapper = ({ children }: { children: ReactNode }) => <QueryClientProvider client={client}>{children}</QueryClientProvider>;
  return { ...render(<CashReports active={active} />, { wrapper }), client };
}
function deferred<T>() { let resolve!: (value: T) => void; const promise = new Promise<T>((settle) => { resolve = settle; }); return { promise, resolve }; }
async function choose(code: CashReportCode) { fireEvent.click(screen.getByRole('option', { name: new RegExp(code) })); fireEvent.click(screen.getByRole('button', { name: 'Xem báo cáo' })); }
afterEach(() => { mockedApi.get.mockReset(); vi.mocked(saveAs).mockClear(); vi.useRealTimers(); document.querySelectorAll('iframe').forEach(frame => frame.remove()); });
afterAll(() => computedStyleShim.mockRestore());

describe('CashReports presentation', () => {
  it('keeps the workbench idle until a report is confirmed', () => {
    renderPage();
    expect(screen.getByRole('listbox', { name: 'Chọn báo cáo tiền mặt' })).toBeInTheDocument();
    expect(screen.getByText('Chọn một mẫu báo cáo để thiết lập kỳ và điều kiện lọc.')).toBeInTheDocument();
    expect(mockedApi.get).not.toHaveBeenCalled();
    expect(screen.queryByText('Báo cáo tiền mặt · dữ liệu doanh nghiệp hiện tại')).not.toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Xuất Excel' })).toBeDisabled();
    expect(screen.getByRole('button', { name: 'In báo cáo' })).toBeDisabled();
  });
  it('supports arrow, Home, End, and Enter selector navigation', () => {
    const onChange = vi.fn();
    render(<CashReportSelector value={null} onChange={onChange} />);
    const options = screen.getAllByRole('option');
    options[0].focus(); fireEvent.keyDown(options[0], { key: 'ArrowDown' }); expect(document.activeElement).toBe(options[1]);
    fireEvent.keyDown(options[1], { key: 'End' }); expect(document.activeElement).toBe(options[4]);
    fireEvent.keyDown(options[4], { key: 'Home' }); expect(document.activeElement).toBe(options[0]);
    fireEvent.keyDown(options[0], { key: 'Enter' }); expect(onChange).toHaveBeenCalledWith('S03a1-DNN');
  });
  it.each<CashReportCode>(['S03a1-DNN', 'S03a2-DNN', 'CA-01', 'CA-02', 'CA-03'])('requests the selected %s endpoint and renders its server columns', async (code) => {
    mockedApi.get.mockImplementation((url, config) => Promise.resolve({ data: { data: payload(code, String(url), config?.params as CashReportFilters) } }) as never);
    const view = renderPage(); await choose(code);
    await waitFor(() => expect(mockedApi.get).toHaveBeenCalledWith(`/cash/reports/${code}`, expect.objectContaining({ params: expect.any(Object), signal: expect.any(AbortSignal) })));
    if (code === 'S03a1-DNN' || code === 'S03a2-DNN') expect((mockedApi.get.mock.calls[0][1] as { params: CashReportFilters }).params.status).toBe('posted');
    await waitFor(() => expect(screen.getByText(String(`/cash/reports/${code}`))).toBeInTheDocument()); view.client.clear();
  });
  it('sends the current-month period, posted journal default, and explicit account/search values', async () => {
    mockedApi.get.mockImplementation((url, config) => {
      if (url === '/master/accounts') return Promise.resolve({ data: [{ code: '1111', name: 'Tiền mặt tại quỹ' }] }) as never;
      return Promise.resolve({ data: { data: payload('S03a1-DNN', 'Bộ lọc máy chủ', config?.params as CashReportFilters) } }) as never;
    });
    const view = renderPage();
    fireEvent.click(screen.getByRole('option', { name: /S03a1-DNN/ }));
    fireEvent.change(screen.getByPlaceholderText('Số chứng từ, diễn giải'), { target: { value: '  kim  ' } });
    const accountCombobox = screen.getByRole('combobox', { name: 'Tài khoản tiền' });
    fireEvent.mouseDown(accountCombobox);
    fireEvent.click(await screen.findByText('Tiền mặt tại quỹ'));
    fireEvent.click(screen.getByRole('button', { name: 'Xem báo cáo' }));
    await waitFor(() => expect(mockedApi.get).toHaveBeenCalledWith('/cash/reports/S03a1-DNN', expect.anything()));
    const reportCall = mockedApi.get.mock.calls.find(([url]) => url === '/cash/reports/S03a1-DNN');
    expect((reportCall?.[1] as { params: CashReportFilters }).params).toEqual({
      date_from: dayjs().startOf('month').format('YYYY-MM-DD'), date_to: dayjs().endOf('month').format('YYYY-MM-DD'), status: 'posted', search: 'kim', cash_account: '1111',
    });
    view.client.clear();
  });
  it('renders the cash account parameter as a server-backed searchable select', async () => {
    mockedApi.get.mockImplementation((url, config) => {
      if (url === '/master/accounts') return Promise.resolve({ data: [{ code: '1111', name: 'Tiền mặt tại quỹ' }] }) as never;
      return Promise.resolve({ data: { data: payload('S03a1-DNN', 'Báo cáo tài khoản', config?.params as CashReportFilters) } }) as never;
    });
    const view = renderPage();
    fireEvent.click(screen.getByRole('option', { name: /S03a1-DNN/ }));
    const accountCombobox = await screen.findByRole('combobox', { name: 'Tài khoản tiền' });
    fireEvent.mouseDown(accountCombobox);
    expect(await screen.findByText('Tiền mặt tại quỹ')).toBeInTheDocument();
    fireEvent.click(screen.getByText('Tiền mặt tại quỹ'));
    expect(screen.getAllByText('1111').length).toBeGreaterThan(0);
    view.client.clear();
  });
  it('shows a retryable error when the cash account catalogue response is malformed', async () => {
    mockedApi.get
      .mockResolvedValueOnce({ data: { unexpected: true } } as never)
      .mockResolvedValueOnce({ data: [{ code: '1111', name: 'Tiền mặt tại quỹ' }] } as never);
    const view = renderPage();
    fireEvent.click(screen.getByRole('option', { name: /S03a1-DNN/ }));
    fireEvent.mouseDown(await screen.findByRole('combobox', { name: 'Tài khoản tiền' }));
    await waitFor(() => expect(screen.getByRole('alert')).toHaveTextContent('Không thể tải danh mục tài khoản tiền'));
    expect(screen.queryByText('Tiền mặt tại quỹ')).not.toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Thử lại danh mục tài khoản tiền' }));
    await waitFor(() => expect(screen.getByText('Tiền mặt tại quỹ')).toBeInTheDocument());
    view.client.clear();
  });
  it('clears old rows during a changed pending selection and ignores a late prior response', async () => {
    const oldRequest = deferred<unknown>(); const newRequest = deferred<unknown>();
    mockedApi.get.mockImplementationOnce(() => oldRequest.promise as never).mockImplementationOnce(() => newRequest.promise as never);
    const view = renderPage(); await choose('S03a1-DNN'); await waitFor(() => expect(mockedApi.get).toHaveBeenCalledTimes(1));
    await choose('CA-02'); await waitFor(() => expect(mockedApi.get).toHaveBeenCalledTimes(2));
    expect(screen.queryByText('PT-01')).not.toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Xuất Excel' })).toBeDisabled();
    expect(screen.getByRole('button', { name: 'In báo cáo' })).toBeDisabled();
    newRequest.resolve({ data: { data: payload('CA-02', 'Báo cáo mới', (mockedApi.get.mock.calls[1][1] as { params: CashReportFilters }).params) } });
    await waitFor(() => expect(screen.getByText('Báo cáo mới')).toBeInTheDocument());
    oldRequest.resolve({ data: { data: payload('S03a1-DNN', 'Báo cáo cũ', (mockedApi.get.mock.calls[0][1] as { params: CashReportFilters }).params) } });
    await waitFor(() => expect(screen.getByText('Báo cáo mới')).toBeInTheDocument()); expect(screen.queryByText('Báo cáo cũ')).not.toBeInTheDocument();
    view.client.clear();
  });
  // Ant Design's portalled Select/Alert effects can exceed Vitest's default
  // 5s budget when the complete UI suite is running in parallel. Keep these
  // async boundary tests bounded without changing production behavior.
  it('only enables full-result actions for the current successful response and fails closed on retry mismatch', { timeout: 15_000 }, async () => {
    const refresh = deferred<unknown>();
    mockedApi.get.mockImplementationOnce((_url, config) => Promise.resolve({ data: { data: payload('CA-01', 'Active empty report', config?.params as CashReportFilters) } }) as never)
      .mockImplementationOnce(() => refresh.promise as never)
      .mockImplementationOnce((_url, config) => Promise.resolve({ data: { data: payload('CA-02', 'Wrong code', config?.params as CashReportFilters) } }) as never);
    const view = renderPage(); await choose('CA-01');
    await waitFor(() => expect(screen.getByRole('button', { name: 'Xuất Excel' })).toBeEnabled());
    expect(screen.getByRole('button', { name: 'In báo cáo' })).toBeEnabled();
    await choose('CA-01');
    await waitFor(() => expect(screen.getByRole('button', { name: 'In báo cáo' })).toBeDisabled());
    refresh.resolve({ data: { data: { report: {} } } });
    await waitFor(() => expect(screen.getByRole('alert')).toHaveTextContent('Không thể tải báo cáo'));
    expect(screen.getByRole('button', { name: 'Xuất Excel' })).toBeDisabled();
    fireEvent.click(screen.getByRole('button', { name: 'Thử lại' }));
    await waitFor(() => expect(mockedApi.get).toHaveBeenCalledTimes(3));
    await waitFor(() => expect(screen.getByRole('alert')).toHaveTextContent('Không thể tải báo cáo'));
    expect(screen.getByRole('button', { name: 'In báo cáo' })).toBeDisabled();
    expect(screen.queryByText('Wrong code')).not.toBeInTheDocument();
    view.client.clear();
  });
  it('exports and prints the active response, including the balances of an empty report', async () => {
    mockedApi.get.mockImplementation((_url, config) => Promise.resolve({ data: { data: payload('CA-03', 'Active server name', config?.params as CashReportFilters) } }) as never);
    const view = renderPage(); await choose('CA-03');
    await waitFor(() => expect(screen.getByRole('button', { name: 'Xuất Excel' })).toBeEnabled());
    fireEvent.click(screen.getByRole('button', { name: 'Xuất Excel' }));
    const [blob, filename] = vi.mocked(saveAs).mock.calls[0];
    expect(filename).toBe(`CA-03_${dayjs().startOf('month').format('YYYY-MM-DD')}_${dayjs().endOf('month').format('YYYY-MM-DD')}.xlsx`);
    const bytes = await new Promise<ArrayBuffer>((resolve, reject) => {
      const reader = new FileReader(); reader.onload = () => resolve(reader.result as ArrayBuffer); reader.onerror = reject; reader.readAsArrayBuffer(blob as Blob);
    });
    const workbook = read(bytes, { type: 'array' });
    const cells = utils.sheet_to_json(workbook.Sheets['CA-03'], { header: 1 });
    expect(cells).toContainEqual(['CA-03', 'Active server name']);
    expect(cells).toContainEqual(['Số dư đầu kỳ', 500]);
    expect(cells).toContainEqual(['Số dư cuối kỳ', 1500]);
    vi.useFakeTimers();
    fireEvent.click(screen.getByRole('button', { name: 'In báo cáo' }));
    const printed = document.querySelector('iframe')?.contentDocument;
    expect(printed?.body).toHaveTextContent('Active server name');
    expect(printed?.body).toHaveTextContent('Số dư đầu kỳ: 500 ₫');
    expect(printed?.querySelector('button, nav, [role="dialog"]')).toBeNull();
    vi.clearAllTimers(); vi.useRealTimers(); view.client.clear();
  });
  it('clears an already-rendered report while a changed selection is loading', async () => {
    const nextRequest = deferred<unknown>();
    mockedApi.get.mockImplementationOnce((_url, config) => Promise.resolve({ data: { data: payload('S03a1-DNN', 'Báo cáo A', config?.params as CashReportFilters) } }) as never)
      .mockImplementationOnce(() => nextRequest.promise as never);
    const view = renderPage(); await choose('S03a1-DNN');
    await waitFor(() => expect(screen.getByText('PT-01')).toBeInTheDocument());
    await choose('CA-02'); await waitFor(() => expect(mockedApi.get).toHaveBeenCalledTimes(2));
    expect(screen.queryByText('PT-01')).not.toBeInTheDocument();
    expect(screen.getByText('Đang tải báo cáo…')).toBeInTheDocument();
    nextRequest.resolve({ data: { data: payload('CA-02', 'Báo cáo B', (mockedApi.get.mock.calls[1][1] as { params: CashReportFilters }).params) } });
    await waitFor(() => expect(screen.getByText('Báo cáo B')).toBeInTheDocument());
    view.client.clear();
  });
  it('shows retryable errors and treats malformed server data as a report error', { timeout: 15_000 }, async () => {
    mockedApi.get.mockRejectedValueOnce(new Error('offline') as never).mockImplementationOnce((_url, config) => Promise.resolve({ data: { data: payload('CA-02', 'Đã thử lại', config?.params as CashReportFilters) } }) as never);
    const view = renderPage(); await choose('CA-02'); await waitFor(() => expect(screen.getByRole('alert')).toHaveTextContent('Không thể tải báo cáo tiền mặt'));
    fireEvent.click(screen.getByRole('button', { name: 'Thử lại' })); await waitFor(() => expect(screen.getByText('Đã thử lại')).toBeInTheDocument()); view.unmount(); view.client.clear();
    mockedApi.get.mockResolvedValueOnce({ data: { data: { report: {} } } } as never);
    const malformed = renderPage(); await choose('CA-02'); await waitFor(() => expect(screen.getByRole('alert')).toHaveTextContent('Không thể tải báo cáo tiền mặt')); malformed.client.clear();
  });
  it('shows server summary values verbatim and refetches an unchanged applied selection', async () => {
    mockedApi.get.mockImplementationOnce((_url, config) => Promise.resolve({ data: { data: payload('S03a1-DNN', 'Lần đầu', config?.params as CashReportFilters) } }) as never).mockImplementationOnce((_url, config) => Promise.resolve({ data: { data: payload('S03a1-DNN', 'Lần làm mới', config?.params as CashReportFilters) } }) as never);
    const view = renderPage(); await choose('S03a1-DNN');
    await waitFor(() => expect(screen.getByText('Lần đầu')).toBeInTheDocument()); expect(screen.getAllByText('1.200 ₫')).not.toHaveLength(0);
    await choose('S03a1-DNN'); await waitFor(() => expect(screen.getByText('Lần làm mới')).toBeInTheDocument()); expect(mockedApi.get).toHaveBeenCalledTimes(2); view.client.clear();
  });
  it('preserves the applied report when parameters are cancelled and remains inactive when hidden', async () => {
    mockedApi.get.mockImplementation((_url, config) => Promise.resolve({ data: { data: payload('CA-02', 'Dòng tiền máy chủ', config?.params as CashReportFilters) } }) as never);
    const view = renderPage(); await choose('CA-02'); await waitFor(() => expect(screen.getByText('Dòng tiền máy chủ')).toBeInTheDocument());
    fireEvent.click(screen.getByRole('option', { name: /S03a2-DNN/ })); fireEvent.click(screen.getByRole('button', { name: 'Hủy' })); expect(screen.getByText('Dòng tiền máy chủ')).toBeInTheDocument();
    view.unmount(); view.client.clear(); renderPage(false); expect(mockedApi.get).toHaveBeenCalledTimes(1);
  });
  it('reopens the applied report parameters and resubmits the exact applied filters', { timeout: 15_000 }, async () => {
    vi.useFakeTimers({ toFake: ['Date'] });
    vi.setSystemTime(new Date('2026-05-15T12:00:00'));
    mockedApi.get.mockImplementation((url, config) => {
      if (url === '/master/accounts') return Promise.resolve({ data: [{ code: '1112', name: 'Tiền mặt tại quỹ 2' }] }) as never;
      return Promise.resolve({ data: { data: payload('S03a1-DNN', 'Bộ lọc đã áp dụng', config?.params as CashReportFilters) } }) as never;
    });
    const view = renderPage();
    fireEvent.click(screen.getByRole('option', { name: /S03a1-DNN/ }));
    fireEvent.change(screen.getByPlaceholderText('Số chứng từ, diễn giải'), { target: { value: 'phiếu đặc biệt' } });
    const accountCombobox = screen.getByRole('combobox', { name: 'Tài khoản tiền' });
    fireEvent.mouseDown(accountCombobox);
    fireEvent.click(await screen.findByText('Tiền mặt tại quỹ 2'));
    fireEvent.mouseDown(screen.getByRole('combobox', { name: 'Trạng thái' }));
    fireEvent.click(await screen.findByText('Bản nháp'));
    fireEvent.click(screen.getByRole('button', { name: 'Xem báo cáo' }));
    await waitFor(() => expect(mockedApi.get).toHaveBeenCalledWith('/cash/reports/S03a1-DNN', expect.anything()));
    const appliedCall = mockedApi.get.mock.calls.find(([url]) => url === '/cash/reports/S03a1-DNN');
    const appliedParams = (appliedCall?.[1] as { params: CashReportFilters }).params;
    expect(appliedParams).toEqual({ date_from: '2026-05-01', date_to: '2026-05-31', status: 'draft', search: 'phiếu đặc biệt', cash_account: '1112' });

    vi.setSystemTime(new Date('2026-08-15T12:00:00'));
    fireEvent.click(screen.getByRole('button', { name: 'Tham số' }));
    expect(screen.getByPlaceholderText('Số chứng từ, diễn giải')).toHaveValue('phiếu đặc biệt');
    expect(screen.getAllByText('1112').length).toBeGreaterThan(0);
    fireEvent.click(screen.getByRole('button', { name: 'Xem báo cáo' }));
    await waitFor(() => expect(mockedApi.get.mock.calls.filter(([url]) => url === '/cash/reports/S03a1-DNN')).toHaveLength(2));
    const refreshedCall = mockedApi.get.mock.calls.filter(([url]) => url === '/cash/reports/S03a1-DNN')[1];
    expect((refreshedCall?.[1] as { params: CashReportFilters }).params).toEqual(appliedParams);
    view.client.clear();
  });
});
