import React from 'react';
import { Table, Button, Space, DatePicker, Empty } from 'antd';
import { PrinterOutlined, ExportOutlined, ReloadOutlined } from '@ant-design/icons';
import { useQuery } from '@tanstack/react-query';
import dayjs from 'dayjs';
import api from '../../api/axios';
import { formatDecimalMoney } from '../../utils/decimalMoney';
import { downloadControlledReport } from './reportDownload';
import ReportDataError from './ReportDataError';
import PageShell from '../../components/layout/PageShell';
import PageHeader from '../../components/layout/PageHeader';
import PageToolbar from '../../components/layout/PageToolbar';
import DataTableSurface from '../../components/layout/DataTableSurface';

type IncomeStatementReportProps = {
    embedded?: boolean;
};

const IncomeStatementReport: React.FC<IncomeStatementReportProps> = ({ embedded = false }) => {
    const [period, setPeriod] = React.useState<[dayjs.Dayjs, dayjs.Dayjs]>([dayjs().startOf('year'), dayjs().endOf('year')]);
    const { data: reportData, error, isError, isLoading, refetch } = useQuery({
        queryKey: ['income-statement', period[0].format('YYYY-MM-DD'), period[1].format('YYYY-MM-DD')],
        queryFn: async () => {
            const { data } = await api.get('/reports/income-statement', {
                params: { from_date: period[0].format('YYYY-MM-DD'), to_date: period[1].format('YYYY-MM-DD') },
            });
            if (!Array.isArray(data)) {
                throw new Error('Invalid income-statement report response');
            }
            return data;
        },
    });

    const columns = [
        { title: 'Chỉ tiêu', dataIndex: 'name', key: 'name', width: 400 },
        { title: 'Mã số', dataIndex: 'code', key: 'code', width: 100, align: 'center' as const },
        { title: 'Thuyết minh', dataIndex: 'note', key: 'note', width: 100, align: 'center' as const },
        { 
            title: 'Kỳ này', 
            dataIndex: 'this_period', 
            key: 'this_period', 
            align: 'right' as const,
            render: (val: string) => formatDecimalMoney(val)
        },
        { 
            title: 'Kỳ trước', 
            dataIndex: 'prev_period', 
            key: 'prev_period', 
            align: 'right' as const,
            render: (val: string) => formatDecimalMoney(val)
        },
    ];

    const handleExport = (format: 'excel' | 'pdf') => {
        void downloadControlledReport('/reports/income-statement', format, 'income_statement', {
            from_date: period[0].format('YYYY-MM-DD'),
            to_date: period[1].format('YYYY-MM-DD'),
        });
    };

    return (
        <PageShell embedded={embedded} title={<PageHeader
            eyebrow="BÁO CÁO TÀI CHÍNH"
            title="Báo cáo kết quả hoạt động kinh doanh"
            description="Tổng hợp doanh thu, chi phí và kết quả kinh doanh theo kỳ."
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
                        reportName="Báo cáo kết quả hoạt động kinh doanh"
                        error={error}
                        onRetry={refetch}
                    />
                ) : (
                    <Table
                        columns={columns}
                        dataSource={reportData || []}
                        rowKey="id"
                        loading={isLoading}
                        size="small"
                        bordered
                        pagination={false}
                        locale={{
                            emptyText: isLoading ? 'Đang tải dữ liệu báo cáo...' : <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="Không có số liệu trong kỳ đã chọn." />,
                        }}
                        scroll={{ y: 'calc(100vh - 250px)' }}
                        rowClassName={(record: any) => ['10', '20', '30', '50', '60'].includes(record.code) ? 'bg-yellow-50 font-bold' : ''}
                    />
                )}
            </DataTableSurface>
        </PageShell>
    );
};

export default IncomeStatementReport;
