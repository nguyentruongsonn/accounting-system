import React from 'react';
import { Table, Button, Space, DatePicker, Empty } from 'antd';
import { PrinterOutlined, ExportOutlined, ReloadOutlined } from '@ant-design/icons';
import { useQuery } from '@tanstack/react-query';
import dayjs from 'dayjs';
import api from '../../api/axios';
import { addDecimalMoney, formatDecimalMoney } from '../../utils/decimalMoney';
import { downloadControlledReport } from './reportDownload';
import ReportDataError from './ReportDataError';
import PageShell from '../../components/layout/PageShell';
import PageHeader from '../../components/layout/PageHeader';
import PageToolbar from '../../components/layout/PageToolbar';
import DataTableSurface from '../../components/layout/DataTableSurface';

type BalanceSheetReportProps = {
    embedded?: boolean;
};

const BalanceSheetReport: React.FC<BalanceSheetReportProps> = ({ embedded = false }) => {
    const [period, setPeriod] = React.useState<[dayjs.Dayjs, dayjs.Dayjs]>([dayjs().startOf('year'), dayjs().endOf('year')]);
    const { data: reportData, error, isError, isLoading, refetch } = useQuery({
        queryKey: ['balance-sheet', period[0].format('YYYY-MM-DD'), period[1].format('YYYY-MM-DD')],
        queryFn: async () => {
            const { data } = await api.get('/reports/balance-sheet', {
                params: { from_date: period[0].format('YYYY-MM-DD'), to_date: period[1].format('YYYY-MM-DD') },
            });
            if (!data || !Array.isArray(data.assets) || !Array.isArray(data.liabilities) || !Array.isArray(data.equity)) {
                throw new Error('Invalid balance-sheet report response');
            }
            return data;
        },
    });

    const columns = [
        { title: 'Chỉ tiêu', dataIndex: 'name', key: 'name', width: 300 },
        { title: 'Mã số', dataIndex: 'code', key: 'code', width: 100, align: 'center' as const },
        { title: 'Thuyết minh', dataIndex: 'note', key: 'note', width: 100, align: 'center' as const },
        { 
            title: 'Số cuối năm', 
            dataIndex: 'end_balance', 
            key: 'end_balance', 
            align: 'right' as const,
            render: (val: string) => formatDecimalMoney(val)
        },
        { 
            title: 'Số đầu năm', 
            dataIndex: 'start_balance', 
            key: 'start_balance', 
            align: 'right' as const,
            render: (val: string) => formatDecimalMoney(val)
        },
    ];

    const getDataSource = () => {
        if (!reportData) return [];
        const assetsTotal = addDecimalMoney(...reportData.assets.map((item: any) => item.end_balance));
        const liabilitiesTotal = addDecimalMoney(...reportData.liabilities.map((item: any) => item.end_balance));
        const equityTotal = addDecimalMoney(...reportData.equity.map((item: any) => item.end_balance));

        return [
            { key: 'A', name: 'TÀI SẢN', code: '', is_header: true },
            ...reportData.assets.map((item: any) => ({ ...item, key: `asset_${item.id}` })),
            { key: 'total_assets', name: 'TỔNG CỘNG TÀI SẢN', code: '270', end_balance: assetsTotal, is_summary: true },
            { key: 'B', name: 'NGUỒN VỐN', code: '', is_header: true },
            { key: 'C', name: 'I. Nợ phải trả', code: '300', end_balance: liabilitiesTotal, is_group: true },
            ...reportData.liabilities.map((item: any) => ({ ...item, key: `liab_${item.id}` })),
            { key: 'D', name: 'II. Vốn chủ sở hữu', code: '400', end_balance: equityTotal, is_group: true },
            ...reportData.equity.map((item: any) => ({ ...item, key: `eq_${item.id}` })),
            { key: 'total_capital', name: 'TỔNG CỘNG NGUỒN VỐN', code: '440', end_balance: addDecimalMoney(liabilitiesTotal, equityTotal), is_summary: true },
        ];
    };

    const handleExport = (format: 'excel' | 'pdf') => {
        void downloadControlledReport('/reports/balance-sheet', format, 'balance_sheet', {
            from_date: period[0].format('YYYY-MM-DD'),
            to_date: period[1].format('YYYY-MM-DD'),
        });
    };

    return (
        <PageShell embedded={embedded} title={<PageHeader
            eyebrow="BÁO CÁO TÀI CHÍNH"
            title="Bảng cân đối kế toán"
            description="Phản ánh tài sản, nợ phải trả và vốn chủ sở hữu theo dữ liệu sổ cái."
        />}>
            <PageToolbar
                filters={<Space>
                    <DatePicker.RangePicker value={period} onChange={(dates) => dates && setPeriod([dates[0] as dayjs.Dayjs, dates[1] as dayjs.Dayjs])} />
                    <Button icon={<ReloadOutlined />} onClick={() => refetch()}>Tải lại</Button>
                </Space>}
                actions={<Space>
                    <Button icon={<PrinterOutlined />} onClick={() => handleExport('pdf')}>Tải PDF</Button>
                    <Button icon={<ExportOutlined />} onClick={() => handleExport('excel')}>Xuất Excel</Button>
                </Space>}
            />

            <DataTableSurface>
                {isError ? (
                    <ReportDataError
                        reportName="Bảng cân đối kế toán"
                        error={error}
                        onRetry={refetch}
                    />
                ) : (
                    <Table
                        columns={columns}
                        dataSource={getDataSource()}
                        rowKey="key"
                        loading={isLoading}
                        size="small"
                        bordered
                        pagination={false}
                        locale={{
                            emptyText: isLoading ? 'Đang tải dữ liệu báo cáo...' : <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="Không có số liệu trong kỳ đã chọn." />,
                        }}
                        scroll={{ y: 'calc(100vh - 250px)' }}
                        rowClassName={(record) => {
                            if (record.is_header) return 'bg-slate-100 font-bold text-lg';
                            if (record.is_group) return 'bg-slate-50 font-bold';
                            if (record.is_summary) return 'bg-yellow-50 font-bold text-red-600';
                            return '';
                        }}
                    />
                )}
            </DataTableSurface>
        </PageShell>
    );
};

export default BalanceSheetReport;
