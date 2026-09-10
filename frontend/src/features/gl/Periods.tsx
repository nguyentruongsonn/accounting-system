import React, { useMemo, useState } from 'react';
import { Alert, Table, Tag, Button, Form, Input } from 'antd';
import { toast as message } from '../../components/feedback/toast';
import {
    CalendarOutlined,
    LockOutlined,
    ReloadOutlined,
    SearchOutlined,
    UnlockOutlined
} from '@ant-design/icons';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import api from '../../api/axios';
import PageHeader from '../../components/layout/PageHeader';
import PageShell from '../../components/layout/PageShell';
import PageToolbar from '../../components/layout/PageToolbar';
import DataTableSurface from '../../components/layout/DataTableSurface';
import Modal from '../../components/layout/AppModal';
import ModalFrame from '../../components/layout/ModalFrame';
import { useAuthStore } from '../../store/useAuthStore';

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
    const openPeriods = (data ?? EMPTY_PERIODS).filter(p => !p.is_closed).length;
    const closedPeriods = (data ?? EMPTY_PERIODS).filter(p => p.is_closed).length;

    const filteredPeriods = useMemo(() => {
        const list = data ?? EMPTY_PERIODS;
        if (!searchText.trim()) return list;
        const q = searchText.toLowerCase();
        return list.filter(p => p.name?.toLowerCase().includes(q) || p.start_date?.includes(q) || p.end_date?.includes(q));
    }, [data, searchText]);

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
            render: (text: string) => <span className="misa-text-semibold text-gray-800">{text}</span>,
        },
        {
            title: 'Từ ngày',
            dataIndex: 'start_date',
            key: 'start_date',
            width: 140,
            align: 'center' as const,
        },
        {
            title: 'Đến ngày',
            dataIndex: 'end_date',
            key: 'end_date',
            width: 140,
            align: 'center' as const,
        },
        {
            title: 'Trạng thái',
            dataIndex: 'is_closed',
            key: 'is_closed',
            width: 140,
            align: 'center' as const,
            render: (closed: boolean) => (
                <Tag color={closed ? 'default' : 'processing'} className={closed ? 'text-gray-600' : 'text-blue-600 font-medium'}>
                    {closed ? 'Đã khóa sổ' : 'Đang mở'}
                </Tag>
            )
        },
        {
            title: 'Thao tác',
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
                                className="text-amber-600 font-medium p-0"
                                onClick={() => {
                                    reopenForm.resetFields();
                                    setReopeningPeriod(period);
                                }}
                            >
                                Mở lại kỳ
                            </Button>
                        ) : null
                    ) : (
                        <Button
                            type="link"
                            size="small"
                            className="text-blue-600 font-medium p-0"
                            onClick={() => onSelectPeriod?.(period)}
                        >
                            Khóa sổ kỳ này
                        </Button>
                    )}
                </div>
            ),
        },
    ];

    // Keep the search and refresh controls in the canonical page toolbar for
    // the standalone period list. Embedded mode is hosted by PeriodLock,
    // whose workspace owns the toolbar region and must not receive a nested
    // toolbar in its tab body.
    const periodsToolbar = (
        <PageToolbar
            filters={(
                <Input
                    placeholder="Tìm kiếm kỳ kế toán..."
                    prefix={<SearchOutlined className="text-gray-400" />}
                    className="w-72"
                    value={searchText}
                    onChange={e => setSearchText(e.target.value)}
                    allowClear
                />
            )}
            actions={(
                <Button
                    icon={<ReloadOutlined />}
                    className="misa-btn-tool"
                    title="Làm mới dữ liệu (F5)"
                    onClick={() => void queryClient.invalidateQueries({ queryKey: ['periods'] })}
                >
                    Nạp lại
                </Button>
            )}
        />
    );

    const content = (
        <>
            {/* KPI Summary Cards */}
            <div className="grid grid-cols-1 md:grid-cols-3 gap-4 misa-mb-16">
                <div className="bg-slate-50/80 border border-slate-200 rounded-lg p-3.5 flex items-center gap-3 shadow-xs">
                    <div className="w-10 h-10 rounded-lg bg-slate-200/80 flex items-center justify-center text-slate-700 text-lg">
                        <CalendarOutlined />
                    </div>
                    <div>
                        <div className="text-xs text-gray-500 font-medium">Tổng số kỳ kế toán</div>
                        <div className="text-xl font-bold text-slate-800">{totalPeriods} <span className="text-xs font-normal text-gray-500">kỳ</span></div>
                    </div>
                </div>
                <div className="bg-blue-50/70 border border-blue-200 rounded-lg p-3.5 flex items-center gap-3 shadow-xs">
                    <div className="w-10 h-10 rounded-lg bg-blue-100 flex items-center justify-center text-blue-600 text-lg">
                        <UnlockOutlined />
                    </div>
                    <div>
                        <div className="text-xs text-gray-500 font-medium">Kỳ đang mở (chưa khóa)</div>
                        <div className="text-xl font-bold text-blue-700">{openPeriods} <span className="text-xs font-normal text-gray-500">kỳ</span></div>
                    </div>
                </div>
                <div className="bg-amber-50/70 border border-amber-200 rounded-lg p-3.5 flex items-center gap-3 shadow-xs">
                    <div className="w-10 h-10 rounded-lg bg-amber-100 flex items-center justify-center text-amber-600 text-lg">
                        <LockOutlined />
                    </div>
                    <div>
                        <div className="text-xs text-gray-500 font-medium">Kỳ đã khóa sổ</div>
                        <div className="text-xl font-bold text-amber-700">{closedPeriods} <span className="text-xs font-normal text-gray-500">kỳ</span></div>
                    </div>
                </div>
            </div>

            {isError && <Alert
                className="mb-4"
                type="error"
                showIcon
                message="Không thể tải trạng thái kỳ kế toán"
                description="Danh sách hiện tại không được thay bằng dữ liệu rỗng. Kiểm tra kết nối hoặc quyền truy cập rồi thử lại."
                action={<Button size="small" onClick={() => void refetch()}>Thử lại kỳ kế toán</Button>}
            />}

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
                confirmLoading={reopenMutation.isPending}
                destroyOnHidden
            >
                <ModalFrame>
                    <div className="p-3 bg-amber-50 border border-amber-200 rounded text-amber-800 text-xs mb-4">
                        <strong>Lưu ý: </strong>
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
                            <Input.TextArea rows={4} placeholder="Nêu rõ chứng từ hoặc dữ liệu cần điều chỉnh" />
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
