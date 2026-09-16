import React, { useMemo, useState } from 'react';
import { Table, Button, Form, Input, Select } from 'antd';
import { toast as message } from '../../components/feedback/toast';
import { SearchOutlined, ReloadOutlined } from '@ant-design/icons';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import api from '../../api/axios';
import PageHeader from '../../components/layout/PageHeader';
import PageShell from '../../components/layout/PageShell';
import PageToolbar from '../../components/layout/PageToolbar';
import DataTableSurface from '../../components/layout/DataTableSurface';
import Modal from '../../components/layout/AppModal';
import ModalFrame from '../../components/layout/ModalFrame';
import { useAuthStore } from '../../store/useAuthStore';
import { formatDate } from '../../utils/dateUtils';
import { runManualDataLoad } from '../../components/feedback/runManualDataLoad';

interface AccountingPeriod {
    id: number;
    name: string;
    start_date: string;
    end_date: string;
    status: 'open' | 'closed';
    is_closed: boolean;
}

function parsePeriodsResponse(value: unknown): AccountingPeriod[] {
    if (Array.isArray(value)) return value;
    if (value && typeof value === 'object' && Array.isArray((value as { data?: unknown }).data)) {
        return (value as { data: AccountingPeriod[] }).data;
    }
    throw new Error('Invalid accounting-periods response');
}

const EMPTY_PERIODS: AccountingPeriod[] = [];

function isPersistedReopenResponse(value: unknown, periodId: number): boolean {
    const period = (value as { data?: { data?: { period?: unknown } } })?.data?.data?.period;
    return !!period
        && typeof period === 'object'
        && Number((period as { id?: unknown }).id) === periodId
        && (period as { status?: unknown }).status === 'open';
}

interface PeriodsProps {
    embedded?: boolean;
    onSelectPeriod?: (period: AccountingPeriod) => void;
}

const Periods: React.FC<PeriodsProps> = ({ embedded = false, onSelectPeriod }) => {
    const queryClient = useQueryClient();
    const permissions = useAuthStore((state) => state.user?.permissions);
    const canReopen = permissions?.includes('gl.periods.close') ?? false;
    const [reopeningPeriod, setReopeningPeriod] = useState<AccountingPeriod | null>(null);
    const [searchText, setSearchText] = useState('');
    const [statusFilter, setStatusFilter] = useState<'all' | 'open' | 'closed'>('all');
    const [reopenForm] = Form.useForm<{ reason: string }>();

    const { data, isLoading, isError, refetch } = useQuery({
        queryKey: ['periods'],
        queryFn: async () => {
            const { data } = await api.get('/gl/periods');
            return parsePeriodsResponse(data);
        },
    });

    const reopenMutation = useMutation({
        mutationFn: async ({ period, reason }: { period: AccountingPeriod; reason: string }) => (
            api.post(`/gl/periods/${period.id}/reopen`, { reason: reason.trim() })
        ),
        onSuccess: async (response, { period }) => {
            if (!isPersistedReopenResponse(response, period.id)) {
                message.error('Máy chủ chưa xác nhận kỳ đã được mở lại. Danh sách hiện tại được giữ nguyên.');
                return;
            }
            message.success(`Đã mở lại ${period.name}. Phải tính giá và chạy đối chiếu lại trước khi khóa kỳ.`);
            setReopeningPeriod(null);
            reopenForm.resetFields();
            await queryClient.invalidateQueries({ queryKey: ['periods'] });
            await queryClient.invalidateQueries({ queryKey: ['period-close-readiness', period.id] });
        },
        onError: (mutationError: { response?: { data?: { message?: string; errors?: { reason?: string[] } } } }) => {
            message.error(
                mutationError.response?.data?.errors?.reason?.[0]
                ?? mutationError.response?.data?.message
                ?? 'Không thể mở lại kỳ. Không có trạng thái nào được thay đổi.',
            );
        },
    });

    const totalPeriods = (data ?? EMPTY_PERIODS).length;
    const openPeriods = (data ?? EMPTY_PERIODS).filter((p: AccountingPeriod) => !p.is_closed).length;
    const closedPeriods = (data ?? EMPTY_PERIODS).filter((p: AccountingPeriod) => p.is_closed).length;

    const filteredPeriods = useMemo(() => {
        const list = data ?? EMPTY_PERIODS;
        return list.filter((p: AccountingPeriod) => {
            if (statusFilter === 'open' && p.is_closed) return false;
            if (statusFilter === 'closed' && !p.is_closed) return false;
            if (!searchText.trim()) return true;
            const q = searchText.toLowerCase();
            return p.name?.toLowerCase().includes(q) || p.start_date?.includes(q) || p.end_date?.includes(q);
        });
    }, [data, searchText, statusFilter]);

    const columns = [
        {
            title: '#',
            key: 'stt',
            width: 50,
            align: 'center' as const,
            render: (_: unknown, __: unknown, index: number) => index + 1,
        },
        {
            title: 'Kỳ kế toán',
            dataIndex: 'name',
            key: 'name',
            render: (text: string) => <span className="font-medium text-slate-800">{text}</span>,
        },
        {
            title: 'Từ ngày',
            dataIndex: 'start_date',
            key: 'start_date',
            width: 140,
            align: 'center' as const,
            render: (d: any) => formatDate(d) || '—',
        },
        {
            title: 'Đến ngày',
            dataIndex: 'end_date',
            key: 'end_date',
            width: 140,
            align: 'center' as const,
            render: (d: any) => formatDate(d) || '—',
        },
        {
            title: 'Trạng thái',
            dataIndex: 'is_closed',
            key: 'is_closed',
            width: 140,
            align: 'center' as const,
            render: (closed: boolean) => (
                <span className={`inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-medium ${
                    closed
                        ? 'bg-slate-100 text-slate-600 border border-slate-200'
                        : 'bg-emerald-50 text-emerald-700 border border-emerald-200/80'
                }`}>
                    <span className={`w-1.5 h-1.5 rounded-full ${closed ? 'bg-slate-400' : 'bg-emerald-500'}`} />
                    {closed ? 'Đã khóa sổ' : 'Đang mở'}
                </span>
            )
        },
        {
            title: 'Chức năng',
            key: 'actions',
            width: 150,
            align: 'center' as const,
            render: (_: unknown, period: AccountingPeriod) => (
                <div className="flex items-center justify-center gap-2">
                    {period.is_closed ? (
                        canReopen ? (
                            <Button
                                type="link"
                                size="small"
                                className="text-slate-600 hover:text-amber-600 font-medium p-0 text-xs"
                                onClick={() => {
                                    reopenForm.resetFields();
                                    setReopeningPeriod(period);
                                }}
                            >
                                Mở lại kỳ
                            </Button>
                        ) : (
                            <span className="text-xs text-slate-400">—</span>
                        )
                    ) : (
                        <Button
                            type="link"
                            size="small"
                            className="text-blue-600 hover:text-blue-700 font-medium p-0 text-xs"
                            onClick={() => onSelectPeriod?.(period)}
                        >
                            Khóa sổ kỳ này
                        </Button>
                    )}
                </div>
            ),
        },
    ];

    const periodsToolbar = (
        <PageToolbar
            filters={(
                <div className="flex items-center gap-2">
                    <Input
                        placeholder="Tìm kiếm kỳ kế toán..."
                        prefix={<SearchOutlined className="text-slate-400" />}
                        className="w-64 text-sm"
                        value={searchText}
                        onChange={e => setSearchText(e.target.value)}
                        allowClear
                    />
                    <Select
                        value={statusFilter}
                        onChange={setStatusFilter}
                        className="w-36 text-sm"
                        options={[
                            { value: 'all', label: 'Tất cả trạng thái' },
                            { value: 'open', label: 'Đang mở' },
                            { value: 'closed', label: 'Đã khóa sổ' },
                        ]}
                    />
                </div>
            )}
            actions={(
                <Button
                    icon={<ReloadOutlined />}
                    className="misa-btn-tool"
                    title="Làm mới dữ liệu"
                    onClick={() => void runManualDataLoad(() => refetch(), { success: 'Tải lại kỳ kế toán thành công.', failure: 'Không thể tải lại kỳ kế toán.' })}
                >
                    Nạp lại
                </Button>
            )}
        />
    );

    const content = (
        <>
            {/* Minimal Stat Bar - Clean, Apple-inspired, no large colorful cards */}
            <div className="bg-slate-50/70 border border-slate-200/90 rounded-lg p-3.5 mb-4 flex flex-wrap items-center justify-between gap-4">
                <div className="flex items-center gap-6 divide-x divide-slate-200">
                    <div className="flex items-center gap-2">
                        <span className="text-xs text-slate-500 font-medium">Tổng số kỳ:</span>
                        <span className="text-sm font-bold text-slate-800">{totalPeriods}</span>
                    </div>
                    <div className="flex items-center gap-2 pl-6">
                        <span className="w-2 h-2 rounded-full bg-emerald-500" />
                        <span className="text-xs text-slate-500 font-medium">Đang mở:</span>
                        <span className="text-sm font-bold text-emerald-700">{openPeriods}</span>
                    </div>
                    <div className="flex items-center gap-2 pl-6">
                        <span className="w-2 h-2 rounded-full bg-slate-400" />
                        <span className="text-xs text-slate-500 font-medium">Đã khóa:</span>
                        <span className="text-sm font-bold text-slate-600">{closedPeriods}</span>
                    </div>
                </div>

                {/* Inline filter in embedded view */}
                {embedded && (
                    <div className="flex items-center gap-2">
                        <Input
                            placeholder="Tìm kiếm kỳ..."
                            prefix={<SearchOutlined className="text-slate-400 text-xs" />}
                            size="small"
                            className="w-48 text-xs"
                            value={searchText}
                            onChange={e => setSearchText(e.target.value)}
                            allowClear
                        />
                        <Select
                            size="small"
                            value={statusFilter}
                            onChange={setStatusFilter}
                            className="w-32 text-xs"
                            options={[
                                { value: 'all', label: 'Tất cả' },
                                { value: 'open', label: 'Đang mở' },
                                { value: 'closed', label: 'Đã khóa' },
                            ]}
                        />
                        <Button
                            size="small"
                            icon={<ReloadOutlined className="text-xs" />}
                            title="Làm mới dữ liệu"
                            onClick={() => void runManualDataLoad(() => refetch(), { success: 'Tải lại kỳ kế toán thành công.', failure: 'Không thể tải lại kỳ kế toán.' })}
                        >
                            Nạp lại
                        </Button>
                    </div>
                )}
            </div>

            {isError && (
                <div role="alert" className="p-3 mb-4 bg-rose-50/80 border border-rose-200 rounded-lg flex items-center justify-between text-xs">
                    <div>
                        <div className="font-semibold text-rose-800">Không thể tải trạng thái kỳ kế toán</div>
                        <div className="text-rose-600 text-[11px] mt-0.5">Danh sách hiện tại không được thay bằng dữ liệu rỗng. Kiểm tra kết nối hoặc quyền truy cập rồi thử lại.</div>
                    </div>
                    <Button size="small" onClick={() => void refetch()}>Thử lại kỳ kế toán</Button>
                </div>
            )}

            <DataTableSurface>
                <Table
                    columns={columns}
                    dataSource={filteredPeriods}
                    rowKey="id"
                    loading={isLoading}
                    className="misa-table"
                    pagination={{ pageSize: 12, showSizeChanger: true }}
                />
            </DataTableSurface>

            <Modal
                title={reopeningPeriod ? `Mở lại ${reopeningPeriod.name}` : 'Mở lại kỳ'}
                open={reopeningPeriod !== null}
                onCancel={() => {
                    if (!reopenMutation.isPending) setReopeningPeriod(null);
                }}
                onOk={() => reopenForm.submit()}
                okText="Mở lại kỳ"
                cancelText="Hủy"
                confirmLoading={reopenMutation.isPending}
                destroyOnHidden
            >
                <ModalFrame>
                    <div className="p-3 bg-slate-50 border border-slate-200/90 rounded-md text-slate-700 text-xs mb-4 leading-relaxed">
                        <span className="font-semibold text-slate-900">Lưu ý nghiệp vụ: </span>
                        Hệ thống sẽ đảo bút toán kết chuyển và yêu cầu đối chiếu lại. Kết quả tính giá từ kỳ này trở đi sẽ mất hiệu lực. Chỉ tiếp tục khi đã xác định rõ chứng từ cần bổ sung hoặc điều chỉnh.
                    </div>
                    <Form
                        form={reopenForm}
                        layout="vertical"
                        onFinish={({ reason }) => {
                            if (reopeningPeriod) reopenMutation.mutate({ period: reopeningPeriod, reason });
                        }}
                    >
                        <Form.Item
                            name="reason"
                            label="Lý do mở lại kỳ"
                            rules={[
                                { required: true, whitespace: true, message: 'Nhập lý do mở lại kỳ.' },
                                { max: 2000, message: 'Lý do không được vượt quá 2.000 ký tự.' },
                            ]}
                        >
                            <Input.TextArea rows={4} placeholder="Nêu rõ chứng từ hoặc dữ liệu cần điều chỉnh..." />
                        </Form.Item>
                    </Form>
                </ModalFrame>
            </Modal>
        </>
    );

    if (embedded) return content;

    return (
        <PageShell
            title={<PageHeader eyebrow="Tổng hợp" title="Kỳ kế toán" description="Danh sách kỳ kế toán. Thao tác đóng kỳ được kiểm soát tại workbench readiness." />}
            toolbar={periodsToolbar}
        >
            {content}
        </PageShell>
    );
};

export default Periods;
