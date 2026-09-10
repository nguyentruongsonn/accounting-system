import { useState } from 'react';
import { Table, Form, DatePicker, Button, Space } from 'antd';
import { useNavigate } from 'react-router-dom';
import { toast as message } from '../../components/feedback/toast';
import { SearchOutlined, BookOutlined, PrinterOutlined, ExportOutlined, ReloadOutlined } from '@ant-design/icons';
import dayjs from 'dayjs';
import api from '../../api/axios';
import { formatDecimalMoney } from '../../utils/decimalMoney';
import ReportDataError from './ReportDataError';
import { downloadControlledReport } from './reportDownload';
import PageShell from '../../components/layout/PageShell';
import PageHeader from '../../components/layout/PageHeader';
import PageToolbar from '../../components/layout/PageToolbar';
import DataTableSurface from '../../components/layout/DataTableSurface';
import { generalReportSourceRoute } from './generalReportDrilldown';

type GeneralJournalProps = {
    embedded?: boolean;
};

export default function GeneralJournal({ embedded = false }: GeneralJournalProps) {
    const navigate = useNavigate();
    const [form] = Form.useForm();
    const [loading, setLoading] = useState(false);
    const [data, setData] = useState<any[]>([]);
    const [reportError, setReportError] = useState<unknown>(null);
    const [lastSearch, setLastSearch] = useState<any | null>(null);

    const handleSearch = async (values: any) => {
        setLastSearch(values);
        setReportError(null);
        setLoading(true);
        try {
            const fromDate = values.dateRange[0].format('YYYY-MM-DD');
            const toDate = values.dateRange[1].format('YYYY-MM-DD');
            // Tenant identity is server-resolved from the authenticated session;
            // never hard-code or send a client-selected company id for a report.
            const response = await api.get('/reports/general-journal', {
                params: { from_date: fromDate, to_date: toDate },
            });
            
            if (!Array.isArray(response.data?.data)) {
                throw new Error('Invalid general-journal report response');
            }
            setData(response.data.data);
            message.success('Đã tải Sổ nhật ký chung.');
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
            title: 'Ngày hạch toán',
            dataIndex: 'posting_date',
            key: 'posting_date',
            width: 120,
        },
        {
            title: 'Ngày CT',
            dataIndex: 'voucher_date',
            key: 'voucher_date',
            width: 120,
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
            title: 'TK Nợ',
            dataIndex: 'debit_account',
            key: 'debit_account',
            width: 80,
            render: (text: string) => <strong>{text}</strong>,
        },
        {
            title: 'TK Có',
            dataIndex: 'credit_account',
            key: 'credit_account',
            width: 80,
            render: (text: string) => <strong>{text}</strong>,
        },
        {
            title: 'Số tiền Nợ',
            dataIndex: 'debit_amount',
            key: 'debit_amount',
            align: 'right' as const,
            width: 150,
            render: (val: string) => formatDecimalMoney(val, { emptyZero: true }),
        },
        {
            title: 'Số tiền Có',
            dataIndex: 'credit_amount',
            key: 'credit_amount',
            align: 'right' as const,
            width: 150,
            render: (val: string) => formatDecimalMoney(val, { emptyZero: true }),
        },
    ];

    const handleExport = (format: 'excel' | 'pdf') => {
        const values = form.getFieldsValue();
        const dateRange = values.dateRange;
        if (!dateRange?.[0] || !dateRange?.[1]) {
            message.warning('Hãy chọn kỳ báo cáo trước khi xuất.');
            return;
        }

        void downloadControlledReport('/reports/general-journal', format, 'general_journal', {
            from_date: dateRange[0].format('YYYY-MM-DD'),
            to_date: dateRange[1].format('YYYY-MM-DD'),
        });
    };

    return (
        <PageShell embedded={embedded} className="reports-general-journal-page">
            {!embedded && <PageHeader
                eyebrow="SỔ NHẬT KÝ"
                title={<><BookOutlined className="mr-2 text-indigo-600" />Sổ nhật ký chung</>}
                description="Liệt kê các bút toán đã ghi sổ theo trình tự ngày hạch toán trong kỳ đã chọn."
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
                    <Form.Item name="dateRange" label="Kỳ báo cáo" rules={[{ required: true }]}>
                        <DatePicker.RangePicker format="DD/MM/YYYY" />
                    </Form.Item>
                    <Form.Item>
                        <Button type="primary" htmlType="submit" icon={<SearchOutlined />} loading={loading} className="misa-btn-primary">
                            Xem báo cáo
                        </Button>
                    </Form.Item>
                </Form>
                )}
                actions={<Space>
                    <Button icon={<ReloadOutlined />} onClick={() => form.resetFields()}>Đặt lại</Button>
                    <Button icon={<PrinterOutlined />} onClick={() => handleExport('pdf')}>Tải PDF</Button>
                    <Button icon={<ExportOutlined />} onClick={() => handleExport('excel')}>Xuất Excel</Button>
                </Space>}
            />
            <DataTableSurface className="reports-general-journal-table">
                {reportError ? (
                    <div className="p-4 pb-0">
                        <ReportDataError
                            reportName="Sổ nhật ký chung"
                            error={reportError}
                            onRetry={() => (lastSearch ? handleSearch(lastSearch) : undefined)}
                        />
                    </div>
                ) : null}
                <Table
                    columns={columns}
                    dataSource={data}
                    rowKey={(record, index) => `${record.voucher_number}_${index}`}
                    pagination={{ pageSize: 50 }}
                    loading={loading}
                    size="small"
                    scroll={{ x: 1000 }}
                    locale={{ emptyText: loading ? 'Đang tải dữ liệu báo cáo...' : reportError ? 'Không có dữ liệu mới do lỗi tải báo cáo.' : 'Không có phát sinh trong kỳ đã chọn.' }}
                />
            </DataTableSurface>
        </PageShell>
    );
}
