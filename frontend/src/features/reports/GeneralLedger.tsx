import { useState, useEffect, useCallback } from 'react';
import { Alert, Table, Form, DatePicker, Button, Space, Typography, Select } from 'antd';
import { useNavigate } from 'react-router-dom';
import { toast as message } from '../../components/feedback/toast';
import { SearchOutlined, BookOutlined, PrinterOutlined, ExportOutlined, ReloadOutlined } from '@ant-design/icons';
import dayjs from 'dayjs';
import api from '../../api/axios';
import { formatDecimalMoney } from '../../utils/decimalMoney';
import ReportDataError from './ReportDataError';
import PageShell from '../../components/layout/PageShell';
import PageHeader from '../../components/layout/PageHeader';
import PageToolbar from '../../components/layout/PageToolbar';
import DataTableSurface from '../../components/layout/DataTableSurface';
import { downloadControlledReport } from './reportDownload';
import { generalReportSourceRoute } from './generalReportDrilldown';

const { Text } = Typography;
const { Option } = Select;

type GeneralLedgerProps = {
    embedded?: boolean;
};

export default function GeneralLedger({ embedded = false }: GeneralLedgerProps) {
    const navigate = useNavigate();
    const [form] = Form.useForm();
    const [loading, setLoading] = useState(false);
    const [data, setData] = useState<any[]>([]);
    const [openingBalance, setOpeningBalance] = useState<{ as_of_date: string; debit: string; credit: string; balance: string } | null>(null);
    const [accounts, setAccounts] = useState<any[]>([]);
    const [reportError, setReportError] = useState<unknown>(null);
    const [accountsLoadError, setAccountsLoadError] = useState<unknown>(null);
    const [lastSearch, setLastSearch] = useState<any | null>(null);

    const fetchAccounts = useCallback(async () => {
        try {
            const response = await api.get('/master/accounts');
            const rows = Array.isArray(response?.data)
                ? response.data
                : response?.data?.data;
            if (!Array.isArray(rows)) throw new Error('Invalid account catalogue response');
            setAccounts(rows);
            setAccountsLoadError(null);
        } catch (error) {
            setAccountsLoadError(error);
            console.error('Failed to fetch accounts:', error);
        }
    }, []);

    useEffect(() => {
        void fetchAccounts();
    }, [fetchAccounts]);

    const handleSearch = async (values: any) => {
        setLastSearch(values);
        setReportError(null);
        setLoading(true);
        try {
            const fromDate = values.dateRange[0].format('YYYY-MM-DD');
            const toDate = values.dateRange[1].format('YYYY-MM-DD');
            // Tenant identity is server-resolved from the authenticated session;
            // never hard-code or send a client-selected company id for a report.
            const response = await api.get('/reports/general-ledger', {
                params: {
                    account_code: values.account_code,
                    from_date: fromDate,
                    to_date: toDate,
                },
            });
            
            if (!Array.isArray(response.data?.data)) {
                throw new Error('Invalid general-ledger report response');
            }
            setData(response.data.data);
            setOpeningBalance(response.data.meta?.opening_balance ?? null);
            message.success('Đã tải Sổ cái chi tiết.');
        } catch (error) {
            setReportError(error);
            message.error('Lỗi khi tải báo cáo.');
            console.error(error);
        } finally {
            setLoading(false);
        }
    };

    const columns = [
        {
            title: 'Ngày HT',
            dataIndex: 'posting_date',
            key: 'posting_date',
            width: 100,
        },
        {
            title: 'Số CT',
            dataIndex: 'voucher_number',
            key: 'voucher_number',
            width: 120,
            render: (text: string, record: any) => {
                const route = generalReportSourceRoute(record);
                return route ? (
                    <a
                        href={route}
                        onClick={(event) => {
                            event.preventDefault();
                            navigate(route);
                        }}
                    >
                        {text}
                    </a>
                ) : <span>{text}</span>;
            },
        },
        {
            title: 'Diễn giải',
            dataIndex: 'description',
            key: 'description',
        },
        {
            title: 'TK Đối ứng',
            dataIndex: 'corresponding_account',
            key: 'corresponding_account',
            width: 100,
            align: 'center' as const,
            render: (text: string) => <strong>{text}</strong>,
        },
        {
            title: 'Phát sinh Nợ',
            dataIndex: 'debit',
            key: 'debit',
            align: 'right' as const,
            width: 150,
            render: (val: string) => formatDecimalMoney(val, { emptyZero: true }),
        },
        {
            title: 'Phát sinh Có',
            dataIndex: 'credit',
            key: 'credit',
            align: 'right' as const,
            width: 150,
            render: (val: string) => formatDecimalMoney(val, { emptyZero: true }),
        },
    ];

    const handleExport = (format: 'excel' | 'pdf') => {
        const values = form.getFieldsValue();
        const dateRange = values.dateRange;
        if (!values.account_code || !dateRange?.[0] || !dateRange?.[1]) {
            message.warning('Hãy chọn tài khoản và kỳ báo cáo trước khi xuất.');
            return;
        }

        void downloadControlledReport('/reports/general-ledger', format, 'general_ledger', {
            account_code: values.account_code,
            from_date: dateRange[0].format('YYYY-MM-DD'),
            to_date: dateRange[1].format('YYYY-MM-DD'),
        });
    };

    return (
        <PageShell embedded={embedded} className="reports-general-ledger-page">
            {!embedded && <PageHeader
                eyebrow="SỔ CÁI"
                title={<><BookOutlined className="mr-2 text-indigo-600" />Sổ cái</>}
                description="Truy vấn phát sinh theo tài khoản và số dư đầu kỳ trong phạm vi ngày hạch toán đã chọn."
            />}
            <PageToolbar
                filters={(
                    <Form
                        form={form}
                        layout="inline"
                        onFinish={handleSearch}
                        initialValues={{
                            dateRange: [dayjs().startOf('month'), dayjs().endOf('month')],
                        }}
                    >
                        <Form.Item name="account_code" label="Tài khoản" rules={[{ required: true }]}>
                            <Select
                                showSearch
                                className="w-[200px]"
                                placeholder="Chọn tài khoản"
                                disabled={accounts.length === 0}
                                notFoundContent={accounts.length === 0 ? 'Chưa có tài khoản từ máy chủ' : 'Không có tài khoản phù hợp'}
                                optionFilterProp="children"
                            >
                                {accounts.map(acc => (
                                    <Option key={acc.code} value={acc.code}>
                                        {acc.code} - {acc.name}
                                    </Option>
                                ))}
                            </Select>
                        </Form.Item>
                        <Form.Item name="dateRange" label="Kỳ báo cáo" rules={[{ required: true }]}>
                            <DatePicker.RangePicker format="DD/MM/YYYY" />
                        </Form.Item>
                        <Form.Item>
                            <Button type="primary" htmlType="submit" icon={<SearchOutlined />} loading={loading} className="misa-btn-primary">
                                Xem Sổ cái
                            </Button>
                        </Form.Item>
                    </Form>
                )}
                actions={<Space>
                    <Button icon={<ReloadOutlined />} onClick={() => void fetchAccounts()}>Tải lại danh mục</Button>
                    <Button icon={<PrinterOutlined />} onClick={() => handleExport('pdf')}>Tải PDF</Button>
                    <Button icon={<ExportOutlined />} onClick={() => handleExport('excel')}>Xuất Excel</Button>
                </Space>}
            />
            {accountsLoadError ? (
                <Alert
                    className="mb-4"
                    type="error"
                    showIcon
                    message="Không thể tải danh mục tài khoản"
                    description="Không thể chọn tài khoản khi máy chủ chưa trả về danh mục hợp lệ."
                    action={<Button size="small" onClick={() => void fetchAccounts()}>Thử lại</Button>}
                />
            ) : null}
            <DataTableSurface className="reports-general-ledger-table">
                {reportError ? (
                    <div className="p-4 pb-0">
                        <ReportDataError
                            reportName="Sổ cái"
                            error={reportError}
                            onRetry={() => (lastSearch ? handleSearch(lastSearch) : undefined)}
                        />
                    </div>
                ) : null}
                {openingBalance ? (
                    <div className="flex flex-wrap items-center justify-between gap-3 border-b border-gray-200 bg-gray-50 px-4 py-3">
                        <div>
                            <Text type="secondary">Số dư đầu kỳ đến {dayjs(openingBalance.as_of_date).format('DD/MM/YYYY')}</Text>
                            <div className="text-sm text-gray-600">Tài khoản {lastSearch?.account_code ?? '—'}</div>
                        </div>
                        <Space size="large">
                            <div className="text-right"><Text type="secondary">Dư Nợ</Text><div className="font-semibold">{formatDecimalMoney(openingBalance.debit, { emptyZero: true })}</div></div>
                            <div className="text-right"><Text type="secondary">Dư Có</Text><div className="font-semibold">{formatDecimalMoney(openingBalance.credit, { emptyZero: true })}</div></div>
                            <div className="text-right"><Text type="secondary">Số dư ròng</Text><div className="font-semibold text-blue-700">{formatDecimalMoney(openingBalance.balance, { emptyZero: true })}</div></div>
                        </Space>
                    </div>
                ) : null}
                <Table
                    columns={columns}
                    dataSource={data}
                    rowKey={(record, index) => `${record.voucher_number}_${index}`}
                    pagination={{ pageSize: 50 }}
                    loading={loading}
                    size="small"
                    locale={{ emptyText: loading ? 'Đang tải dữ liệu báo cáo...' : reportError ? 'Không có dữ liệu mới do lỗi tải báo cáo.' : 'Không có phát sinh trong kỳ đã chọn.' }}
                />
            </DataTableSurface>
        </PageShell>
    );
}
