import { useState } from 'react';
import { Alert, Button, DatePicker, Form, Input } from 'antd';
import { useQuery } from '@tanstack/react-query';
import { AdaptiveSelect } from '../../components/layout/AdaptiveSelect';
import api from '../../api/axios';
import { createRoot } from 'react-dom/client';
import { flushSync } from 'react-dom';
import dayjs, { type Dayjs } from 'dayjs';
import PageShell from '../../components/layout/PageShell';
import PageToolbar from '../../components/layout/PageToolbar';
import AppModal from '../../components/layout/AppModal';
import { CashReportResult } from './CashReportResult';
import { CashReportSelector } from './CashReportSelector';
import { REPORTS, type CashReportCode, type CashReportFilters } from './cashReportContract';
import { useCashReportQuery } from './useCashReportQuery';
import { CashReportPrint } from './CashReportPrint';
import { exportCashReport } from './exportCashReport';
import { printCashReport } from './printCashReport';
import AccountSelect from '../../components/misa/AccountSelect';
import type { AccountItem } from '../../components/misa/AccountSelect';
import { getApiErrorMessage } from '../../utils/apiErrorMessage';
import './cash-reports.css';

interface CashReportsProps { active?: boolean; }
type Selection = { code: CashReportCode; filters: CashReportFilters };
type FormValues = { dateRange: [Dayjs, Dayjs]; search?: string; cash_account?: string; status?: CashReportFilters['status'] };

function parseCashAccountCatalogue(value: unknown): AccountItem[] {
  const rows = Array.isArray(value)
    ? value
    : value && typeof value === 'object' && Array.isArray((value as { data?: unknown }).data)
      ? (value as { data: unknown[] }).data
      : null;
  if (rows === null) {
    throw new Error('Máy chủ không trả về danh mục tài khoản tiền hợp lệ.');
  }
  if (rows.some((row) => !row || typeof row !== 'object'
    || typeof (row as { code?: unknown }).code !== 'string'
    || typeof (row as { name?: unknown }).name !== 'string')) {
    throw new Error('Máy chủ trả về bản ghi tài khoản tiền không hợp lệ.');
  }
  return rows.filter((row): row is AccountItem => Boolean(row)
    && typeof row === 'object'
    && typeof (row as { code?: unknown }).code === 'string'
    && /^111\d*$/.test((row as { code: string }).code)
    && typeof (row as { name?: unknown }).name === 'string'
    && (row as { is_active?: unknown }).is_active !== false);
}

function defaultFilters(): CashReportFilters {
  const now = dayjs();
  return { date_from: now.startOf('month').format('YYYY-MM-DD'), date_to: now.endOf('month').format('YYYY-MM-DD'), status: 'posted', search: '' };
}
function isJournal(code: CashReportCode): boolean { return code === 'S03a1-DNN' || code === 'S03a2-DNN'; }
function sameSelection(left: Selection | null, right: Selection): boolean {
  return left?.code === right.code && left.filters.date_from === right.filters.date_from && left.filters.date_to === right.filters.date_to
    && left.filters.status === right.filters.status && (left.filters.search ?? '').trim() === (right.filters.search ?? '').trim()
    && (left.filters.cash_account ?? '').trim() === (right.filters.cash_account ?? '').trim();
}

export function CashReports({ active = true }: CashReportsProps) {
  const [form] = Form.useForm<FormValues>();
  const [pendingCode, setPendingCode] = useState<CashReportCode | null>(null);
  const [appliedSelection, setAppliedSelection] = useState<Selection | null>(null);
  const [actionError, setActionError] = useState<string | null>(null);
  const [accountPickerOpen, setAccountPickerOpen] = useState(false);
  const cashAccountsQuery = useQuery<AccountItem[]>({
    queryKey: ['cash-report-accounts'],
    queryFn: async () => parseCashAccountCatalogue((await api.get('/master/accounts')).data),
    enabled: accountPickerOpen,
    retry: false,
    staleTime: 15 * 60 * 1000,
  });
  const query = useCashReportQuery(appliedSelection, active);
  const activeResult = appliedSelection && !query.isFetching && !query.isError ? query.data : undefined;
  const openParameters = (code: CashReportCode) => {
    const filters = appliedSelection?.code === code ? appliedSelection.filters : defaultFilters();
    setPendingCode(code);
    setActionError(null);
    form.setFieldsValue({
      dateRange: [dayjs(filters.date_from), dayjs(filters.date_to)],
      search: filters.search ?? '',
      cash_account: filters.cash_account ?? '',
      status: isJournal(code) ? filters.status : 'posted',
    });
  };
  const reset = () => {
    const defaults = defaultFilters();
    setAppliedSelection(null);
    setPendingCode(null);
    setAccountPickerOpen(false);
    setActionError(null);
    form.setFieldsValue({ dateRange: [dayjs(defaults.date_from), dayjs(defaults.date_to)], search: '', cash_account: '', status: defaults.status });
  };
  const apply = (values: FormValues) => {
    if (!pendingCode) return;
    const range = values.dateRange;
    const next: Selection = {
      code: pendingCode,
      filters: {
        date_from: range[0].format('YYYY-MM-DD'), date_to: range[1].format('YYYY-MM-DD'),
        status: isJournal(pendingCode) ? (values.status ?? 'posted') : 'posted',
        search: values.search?.trim() ?? '', ...(values.cash_account?.trim() ? { cash_account: values.cash_account.trim() } : {}),
      },
    };
    setPendingCode(null);
    setAccountPickerOpen(false);
    if (sameSelection(appliedSelection, next)) { void query.refetch(); return; }
    setAppliedSelection(next);
  };
  if (!active) return null;
  const exportActive = () => {
    if (!activeResult) return;
    try { setActionError(null); exportCashReport(activeResult); }
    catch { setActionError('Không thể xuất báo cáo. Hãy thử lại.'); }
  };
  const printActive = () => {
    if (!activeResult) return;
    // Render the dedicated full table into a detached tree only on demand;
    // neither the application's controls nor a paginated table are cloned.
    const host = document.createElement('div');
    const root = createRoot(host);
    try {
      setActionError(null);
      flushSync(() => root.render(<CashReportPrint report={activeResult} />));
      printCashReport(host.firstElementChild as HTMLElement, `${activeResult.report.code} · ${activeResult.report.name}`);
    } catch { setActionError('Không thể tạo bản in báo cáo. Hãy thử lại.'); }
    finally { root.unmount(); }
  };
  const selectedReport = pendingCode ? REPORTS.find((report) => report.code === pendingCode) : null;
  return (
    <PageShell className="cash-reports-page">
      <PageToolbar className="cash-report-toolbar" actions={<><Button onClick={reset}>Đặt lại</Button><Button disabled={!appliedSelection || pendingCode !== null} onClick={() => { if (appliedSelection) openParameters(appliedSelection.code); }}>Tham số</Button><Button disabled={!activeResult || pendingCode !== null} onClick={exportActive}>Xuất Excel</Button><Button disabled={!activeResult || pendingCode !== null} onClick={printActive}>In báo cáo</Button></>} />
      {actionError && <Alert type="error" role="alert" message={actionError} />}
      <div className="cash-report-workbench">
        <CashReportSelector value={pendingCode ?? appliedSelection?.code ?? null} onChange={openParameters} />
        <CashReportResult report={query.data} loading={query.isFetching} error={query.isError} onRetry={() => { void query.refetch(); }} />
      </div>
      <AppModal className="cash-report-parameter-modal" open={pendingCode !== null} title={`Chọn tham số · ${selectedReport?.name ?? ''}`} onCancel={() => { setPendingCode(null); setAccountPickerOpen(false); }} onOk={() => form.submit()} okText="Xem báo cáo" cancelText="Hủy" getContainer={() => document.body}>
        <Form<FormValues> form={form} layout="vertical" onFinish={apply}>
          <Form.Item name="dateRange" label="Kỳ báo cáo" rules={[{ required: true, message: 'Chọn kỳ báo cáo' }]}><DatePicker.RangePicker className="cash-report-form__range" format="DD/MM/YYYY" allowClear={false} /></Form.Item>
          <Form.Item name="search" label="Tìm kiếm chứng từ"><Input placeholder="Số chứng từ, diễn giải" allowClear /></Form.Item>
          <Form.Item name="cash_account" label="Tài khoản tiền" rules={[{ pattern: /^111\d*$/, message: 'Tài khoản tiền bắt đầu bằng 111' }]}>
            <AccountSelect
              accounts={cashAccountsQuery.data ?? []}
              loading={cashAccountsQuery.isLoading}
              placeholder="Chọn tài khoản tiền"
              allowClear
              onOpenChange={setAccountPickerOpen}
            />
          </Form.Item>
          {cashAccountsQuery.isError && <Alert
            type="error"
            showIcon
            message="Không thể tải danh mục tài khoản tiền"
            description={getApiErrorMessage(cashAccountsQuery.error, 'Không hiển thị danh sách tài khoản thay thế.')}
            action={<Button size="small" onClick={() => void cashAccountsQuery.refetch()}>Thử lại danh mục tài khoản tiền</Button>}
          />}
          {pendingCode && isJournal(pendingCode) && <Form.Item name="status" label="Trạng thái"><AdaptiveSelect options={[{ value: 'posted', label: 'Đã ghi sổ' }, { value: 'draft', label: 'Bản nháp' }, { value: 'voided', label: 'Đã hủy' }, { value: 'all', label: 'Tất cả' }]} getPopupContainer={() => document.body} /></Form.Item>}
        </Form>
      </AppModal>
    </PageShell>
  );
}

export default CashReports;
