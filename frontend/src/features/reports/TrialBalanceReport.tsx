import React, { useState } from 'react';
import { Table, Button, Space, DatePicker, Empty } from 'antd';
import { PrinterOutlined, ExportOutlined, ReloadOutlined } from '@ant-design/icons';
import { useQuery } from '@tanstack/react-query';
import api from '../../api/axios';
import dayjs from 'dayjs';
import {
    absDecimalMoney,
    addDecimalMoney,
    compareDecimalMoney,
    formatDecimalMoney,
    subtractDecimalMoney,
} from '../../utils/decimalMoney';
import { downloadControlledReport } from './reportDownload';
import ReportDataError from './ReportDataError';
import PageShell from '../../components/layout/PageShell';
import PageHeader from '../../components/layout/PageHeader';
import PageToolbar from '../../components/layout/PageToolbar';
import DataTableSurface from '../../components/layout/DataTableSurface';

type TrialBalanceReportProps = {
    embedded?: boolean;
};

const TrialBalanceReport: React.FC<TrialBalanceReportProps> = ({ embedded = false }) => {
    const [period, setPeriod] = useState<[dayjs.Dayjs, dayjs.Dayjs]>([dayjs().startOf('month'), dayjs().endOf('month')]);

    const { data: reportData, error, isError, isLoading, refetch } = useQuery({
        queryKey: ['trial-balance', period[0].format('YYYY-MM-DD'), period[1].format('YYYY-MM-DD')],
        queryFn: async () => {
            const { data } = await api.get('/reports/trial-balance', {
                params: {
                    from_date: period[0].format('YYYY-MM-DD'),
                    to_date: period[1].format('YYYY-MM-DD')
                }
            });
            // The non-export controller contract is an array.  Do not turn a
            // malformed 2xx envelope into an empty report or enable download
            // actions against unverified evidence.
            if (!Array.isArray(data)) {
                throw new Error('Invalid trial-balance report response');
            }
            return data;
        },
    });

    const buildTree = (accounts: any[]) => {
        const map = new Map();
        const tree: any[] = [];
        
        accounts.forEach(acc => {
            map.set(acc.code, { ...acc, children: [] });
        });

        accounts.forEach(acc => {
            if (acc.code.length > 3) {
                const parentCode = acc.code.substring(0, acc.code.length - 1) || acc.code.substring(0, 3);
                const parent = map.get(parentCode) || map.get(acc.code.substring(0, 3));
                if (parent) {
                    parent.children.push(map.get(acc.code));
                } else {
                    tree.push(map.get(acc.code));
                }
            } else {
                tree.push(map.get(acc.code));
            }
        });

        const filterEmptyChildren = (nodes: any[]) => {
            nodes.forEach(node => {
                if (node.children.length === 0) {
                    delete node.children;
                } else {
                    filterEmptyChildren(node.children);
                }
            });
        };
        filterEmptyChildren(tree);
        return tree;
    };

    const treeData = reportData ? buildTree(reportData) : [];

    const columns = [
        { title: 'Số TK', dataIndex: 'code', key: 'code', width: 120, fixed: 'left' as const },
        { title: 'Tên tài khoản', dataIndex: 'name', key: 'name', width: 250, fixed: 'left' as const },
        {
            title: 'Dư đầu kỳ',
            children: [
                { title: 'Nợ', dataIndex: 'opening_debit', key: 'opening_debit', width: 120, align: 'right' as const, render: (val: string) => formatDecimalMoney(val) },
                { title: 'Có', dataIndex: 'opening_credit', key: 'opening_credit', width: 120, align: 'right' as const, render: (val: string) => formatDecimalMoney(val) },
            ],
        },
        {
            title: 'Phát sinh trong kỳ',
            children: [
                { title: 'Nợ', dataIndex: 'arising_debit', key: 'arising_debit', width: 120, align: 'right' as const, render: (val: string) => formatDecimalMoney(val) },
                { title: 'Có', dataIndex: 'arising_credit', key: 'arising_credit', width: 120, align: 'right' as const, render: (val: string) => formatDecimalMoney(val) },
            ],
        },
        {
            title: 'Dư cuối kỳ',
            children: [
                { title: 'Nợ', dataIndex: 'ending_debit', key: 'ending_debit', width: 120, align: 'right' as const, render: (val: string) => formatDecimalMoney(val) },
                { title: 'Có', dataIndex: 'ending_credit', key: 'ending_credit', width: 120, align: 'right' as const, render: (val: string) => formatDecimalMoney(val) },
            ],
        },
    ];

    // Sum posting/leaf accounts once. Account-code length is not a reliable
    // hierarchy rule (tenant catalogues and academic simulations may use
    // symbolic or longer root codes), and previously made the summary show
    // zero while the report contained real movements.
    const postingAccounts = reportData?.filter((account: any) => account.is_parent !== true) ?? [];
    const totalArisingDebit = addDecimalMoney(...postingAccounts.map((account: any) => account.arising_debit));
    const totalArisingCredit = addDecimalMoney(...postingAccounts.map((account: any) => account.arising_credit));

    const handleExport = (format: 'excel' | 'pdf') => {
        void downloadControlledReport('/reports/trial-balance', format, 'trial_balance', {
            from_date: period[0].format('YYYY-MM-DD'),
            to_date: period[1].format('YYYY-MM-DD'),
        });
    };

    return (
        <PageShell embedded={embedded} title={<PageHeader
            eyebrow="BÁO CÁO TÀI CHÍNH"
            title="Bảng cân đối tài khoản"
            description="Đối chiếu số dư đầu kỳ, phát sinh và số dư cuối kỳ theo hệ thống tài khoản."
        />}>
            <PageToolbar
                filters={<Space>
                    <DatePicker.RangePicker 
                        value={period} 
                        onChange={(dates) => dates && setPeriod([dates[0] as dayjs.Dayjs, dates[1] as dayjs.Dayjs])} 
                    />
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
                        reportName="Bảng cân đối tài khoản"
                        error={error}
                        onRetry={refetch}
                    />
                ) : (
                    <Table
                        columns={columns}
                        dataSource={treeData}
                        rowKey="code"
                        loading={isLoading}
                        size="small"
                        bordered
                        pagination={false}
                        locale={{
                            emptyText: isLoading ? 'Đang tải dữ liệu báo cáo...' : <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="Không có phát sinh trong kỳ đã chọn." />,
                        }}
                        scroll={{ y: 'calc(100vh - 250px)', x: 'max-content' }}
                        summary={() => (
                            <Table.Summary.Row className="misa-table-summary-row">
                                <Table.Summary.Cell index={0} colSpan={2} align="center">Tổng cộng</Table.Summary.Cell>
                                <Table.Summary.Cell index={2} align="right"></Table.Summary.Cell>
                                <Table.Summary.Cell index={3} align="right"></Table.Summary.Cell>
                                <Table.Summary.Cell index={4} align="right">{formatDecimalMoney(totalArisingDebit)}</Table.Summary.Cell>
                                <Table.Summary.Cell index={5} align="right">{formatDecimalMoney(totalArisingCredit)}</Table.Summary.Cell>
                                <Table.Summary.Cell index={6} align="right"></Table.Summary.Cell>
                                <Table.Summary.Cell index={7} align="right"></Table.Summary.Cell>
                            </Table.Summary.Row>
                        )}
                        rowClassName={(record) => record.is_parent ? 'font-bold bg-slate-50' : ''}
                    />
                )}
            </DataTableSurface>
            
            {compareDecimalMoney(totalArisingDebit, totalArisingCredit) !== 0 && (
                <div className="mt-2 text-red-500 font-bold">
                    Cảnh báo: Tổng phát sinh Nợ và Có không cân bằng! Lệch: {formatDecimalMoney(absDecimalMoney(subtractDecimalMoney(totalArisingDebit, totalArisingCredit)))}
                </div>
            )}
        </PageShell>
    );
};

export default TrialBalanceReport;
