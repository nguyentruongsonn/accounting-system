import React, { useEffect, useState } from 'react';
import { ConfigProvider, Table, Button, Form, Input, InputNumber, Space, Select, DatePicker, Tabs, Popconfirm, Tag, Dropdown, Alert } from 'antd';
import { toast as message } from '../../components/feedback/toast';
import Modal from '../../components/layout/AppModal';
import {
    PlusOutlined,
    DeleteOutlined,
    FileTextOutlined,
    LinkOutlined,
    DownOutlined,
    PrinterOutlined,
    CopyOutlined,
    EditOutlined,
    ReloadOutlined,
    CheckCircleOutlined,
    StopOutlined
} from '@ant-design/icons';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import api from '../../api/axios';
import dayjs from 'dayjs';
import {
    ReferenceVoucherModal,
    VoucherPrintModal,
    useVoucherShortcuts
} from '../../components/misa';
import { getVoucherActionDecision, runVoucherAction, type VoucherAction } from '../../types/voucherActions';
import {
    absDecimalMoney,
    addDecimalMoney,
    compareDecimalMoney,
    formatDecimalMoney,
    formatDecimalMoneyInput,
    normalizeDecimalMoney,
    parseDecimalMoneyInput,
    subtractDecimalMoney,
} from '../../utils/decimalMoney';
import PageShell from '../../components/layout/PageShell';
import PageToolbar from '../../components/layout/PageToolbar';
import DataTableSurface from '../../components/layout/DataTableSurface';
import PageHeader from '../../components/layout/PageHeader';
import ModalFrame from '../../components/layout/ModalFrame';
import { BANK_ACCOUNTS_ENDPOINT, readBankAccountsResponse } from '../bank/bankAccountData';
import { useSearchParams } from 'react-router-dom';

interface JournalLineItem {
    id?: number;
    description?: string;
    account_code?: string;
    debit_account?: string;
    credit_account?: string;
    amount?: string | number;
    amount_decimal?: string;
    debit_amount?: string | number;
    debit_amount_decimal?: string;
    credit_amount?: string | number;
    credit_amount_decimal?: string;
    contact_type?: string;
    contact_id?: string | number;
    contact_name?: string;
    cost_item_code?: string;
    cost_object_code?: string;
    bank_account_id?: number;
}

function preferredMoney(...values: Array<string | number | null | undefined>): string {
    const value = values.find((candidate) => candidate !== null && candidate !== undefined && candidate !== '');
    return normalizeDecimalMoney(value ?? '0');
}

function parseChartOfAccountsResponse(value: unknown): any[] {
    if (Array.isArray(value)) return value;
    if (value && typeof value === 'object' && Array.isArray((value as { data?: unknown }).data)) {
        return (value as { data: any[] }).data;
    }
    throw new Error('Invalid chart-of-accounts catalogue response.');
}

const GeneralJournals: React.FC<{ embedded?: boolean }> = ({ embedded = false }) => {
    const [searchParams, setSearchParams] = useSearchParams();
    const [isModalVisible, setIsModalVisible] = useState(false);
    const [modalMode, setModalMode] = useState<'create' | 'edit' | 'view'>('create');
    const [selectedVoucherId, setSelectedVoucherId] = useState<number | null>(null);
    const [isRefModalVisible, setIsRefModalVisible] = useState(false);
    const [referencedVouchers, setReferencedVouchers] = useState<any[]>([]);
    const [isPrintModalVisible, setIsPrintModalVisible] = useState(false);
    const [printVoucherData, setPrintVoucherData] = useState<any>(null);
    const [form] = Form.useForm();
    const queryClient = useQueryClient();

    // 1. Queries
    const { data: journals = [], isLoading, isError: isJournalsError, refetch: refetchJournals } = useQuery<any[]>({
        queryKey: ['journal-entries'],
        queryFn: async () => {
            const { data } = await api.get('/gl/journal-entries');
            if (Array.isArray(data)) return data;
            if (data && typeof data === 'object' && Array.isArray((data as { data?: unknown }).data)) {
                return (data as { data: unknown[] }).data;
            }
            throw new Error('Invalid general journal list response.');
        },
    });

    const { data: chartOfAccounts = [] } = useQuery({
        queryKey: ['chart-of-accounts'],
        queryFn: async () => {
            const { data } = await api.get('/master/accounts');
            return parseChartOfAccountsResponse(data);
        },
    });

    const { data: bankAccounts = [] } = useQuery({
        queryKey: ['bank-accounts'],
        queryFn: async () => {
            const { data } = await api.get(BANK_ACCOUNTS_ENDPOINT);
            return readBankAccountsResponse(data);
        },
    });

    // 2. Mutations
    const saveMutation = useMutation({
        mutationFn: async (values: any) => {
            const formattedLines = (values.lines || []).map((line: any) => {
                const amount = preferredMoney(
                    line.amount_decimal,
                    line.amount,
                    line.debit_amount_decimal,
                    line.debit_amount,
                    line.credit_amount_decimal,
                    line.credit_amount,
                );

                return {
                    description: line.description || values.description,
                    debit_account: line.debit_account,
                    credit_account: line.credit_account,
                    account_code: line.account_code,
                    debit_amount: line.debit_amount !== undefined && line.debit_amount !== null && line.debit_amount !== ''
                        ? preferredMoney(line.debit_amount_decimal, line.debit_amount)
                        : (line.debit_account ? amount : '0.00'),
                    credit_amount: line.credit_amount !== undefined && line.credit_amount !== null && line.credit_amount !== ''
                        ? preferredMoney(line.credit_amount_decimal, line.credit_amount)
                        : (line.credit_account ? amount : '0.00'),
                    amount,
                    contact_type: line.contact_type,
                    contact_id: line.contact_id,
                    contact_name: line.contact_name,
                    cost_item_code: line.cost_item_code,
                    cost_object_code: line.cost_object_code,
                    bank_account_id: line.bank_account_id,
                };
            });

            const payload = {
                voucher_number: values.voucher_number,
                voucher_date: values.voucher_date.format('YYYY-MM-DD'),
                posting_date: values.posting_date.format('YYYY-MM-DD'),
                voucher_type: 'general_journal',
                reason: values.description,
                description: values.description,
                attached_docs: values.attached_docs,
                referenced_vouchers: referencedVouchers,
                lines: formattedLines,
            };

            if (selectedVoucherId && modalMode === 'edit') {
                return api.put(`/gl/journal-entries/${selectedVoucherId}`, payload);
            }
            return api.post('/gl/journal-entries', payload);
        },
        onSuccess: (response: any) => {
            const persistedEntry = response?.data?.data ?? response?.data;
            if (!persistedEntry || persistedEntry.id === undefined || persistedEntry.id === null) {
                message.error('Máy chủ không trả về chứng từ nghiệp vụ khác đã lưu; không thể xác nhận thao tác thành công.');
                return;
            }
            message.success(selectedVoucherId ? 'Cập nhật Chứng từ thành công!' : 'Lưu Chứng từ nghiệp vụ khác thành công!');
            setIsModalVisible(false);
            queryClient.invalidateQueries({ queryKey: ['journal-entries'] });
        },
        onError: (err: any) => {
            message.error(err.response?.data?.message || 'Có lỗi xảy ra khi lưu chứng từ!');
        }
    });

    const postMutation = useMutation({
        mutationFn: async (id: number) => api.post(`/gl/journal-entries/${id}/post`),
        onSuccess: (response: any) => {
            const persistedEntry = response?.data?.data ?? response?.data;
            if (!persistedEntry || persistedEntry.id === undefined || persistedEntry.id === null) {
                message.error('Máy chủ không trả về chứng từ đã ghi sổ; không thể xác nhận thao tác thành công.');
                return;
            }
            message.success('Ghi sổ chứng từ thành công!');
            queryClient.invalidateQueries({ queryKey: ['journal-entries'] });
        },
        onError: (err: any) => message.error(err.response?.data?.message || 'Lỗi khi ghi sổ!')
    });

    const unpostMutation = useMutation({
        mutationFn: async (id: number) => api.post(`/gl/journal-entries/${id}/unpost`),
        onSuccess: (response: any) => {
            const persistedEntry = response?.data?.data ?? response?.data;
            if (!persistedEntry || persistedEntry.id === undefined || persistedEntry.id === null) {
                message.error('Máy chủ không trả về chứng từ đã bỏ ghi; không thể xác nhận thao tác thành công.');
                return;
            }
            message.success('Bỏ ghi sổ chứng từ thành công!');
            queryClient.invalidateQueries({ queryKey: ['journal-entries'] });
        },
        onError: (err: any) => message.error(err.response?.data?.message || 'Lỗi khi bỏ ghi sổ!')
    });

    const duplicateMutation = useMutation({
        mutationFn: async (id: number) => api.post(`/gl/journal-entries/${id}/duplicate`),
        onSuccess: (response: any) => {
            const persistedEntry = response?.data?.data ?? response?.data;
            if (!persistedEntry || persistedEntry.id === undefined || persistedEntry.id === null) {
                message.error('Máy chủ không trả về chứng từ nhân bản; không thể xác nhận thao tác thành công.');
                return;
            }
            message.success('Nhân bản chứng từ thành công!');
            queryClient.invalidateQueries({ queryKey: ['journal-entries'] });
        },
        onError: (err: any) => message.error(err.response?.data?.message || 'Lỗi khi nhân bản!')
    });

    const deleteMutation = useMutation({
        mutationFn: async (id: number) => api.delete(`/gl/journal-entries/${id}`),
        onSuccess: (response: any) => {
            if (response?.status !== 204) {
                message.error('Máy chủ không xác nhận đã xóa chứng từ.');
                return;
            }
            message.success('Xóa chứng từ thành công!');
            queryClient.invalidateQueries({ queryKey: ['journal-entries'] });
        },
        onError: (err: any) => message.error(err.response?.data?.message || 'Lỗi khi xóa chứng từ!')
    });

    // 3. Computed Totals
    const lines: JournalLineItem[] = Form.useWatch('lines', form) || [];
    const totals = lines.reduce((running, l) => {
        if (l?.debit_account && l?.credit_account) {
            const amount = preferredMoney(l.amount_decimal, l.amount, l.debit_amount_decimal, l.debit_amount, l.credit_amount_decimal, l.credit_amount);
            return {
                debit: addDecimalMoney(running.debit, amount),
                credit: addDecimalMoney(running.credit, amount),
            };
        } else {
            return {
                debit: addDecimalMoney(running.debit, preferredMoney(l.debit_amount_decimal, l.debit_amount)),
                credit: addDecimalMoney(running.credit, preferredMoney(l.credit_amount_decimal, l.credit_amount)),
            };
        }
    }, { debit: '0.00', credit: '0.00' });

    const totalDebit = totals.debit;
    const totalCredit = totals.credit;
    const balanceDifference = absDecimalMoney(subtractDecimalMoney(totalDebit, totalCredit));
    const isUnbalanced = compareDecimalMoney(totalDebit, totalCredit) !== 0;

    // 4. Modal Handlers
    const handleOpenModal = async (mode: 'create' | 'edit' | 'view' = 'create', record?: any) => {
        if (mode === 'edit' && record) {
            const decision = getVoucherActionDecision('edit', {
                status: record.status,
                isPosted: record.is_posted,
                hasIdentity: Boolean(record.id),
            });
            if (!decision.allowed) {
                message.warning(decision.reason);
                return;
            }
        }

        setModalMode(mode);
        form.resetFields();
        setReferencedVouchers([]);

        if (mode === 'create') {
            setSelectedVoucherId(null);
            try {
                const { data: nextCodeRes } = await api.get('/gl/journal-entries/next-code');
                const nextCode = nextCodeRes?.voucher_number || nextCodeRes?.data || nextCodeRes?.code || '';
                form.setFieldsValue({
                    voucher_number: nextCode,
                    voucher_date: dayjs(),
                    posting_date: dayjs(),
                    lines: [
                        { description: '', debit_account: '', credit_account: '', amount: '0.00' }
                    ]
                });
            } catch {
                form.setFieldsValue({
                    voucher_number: '',
                    voucher_date: dayjs(),
                    posting_date: dayjs(),
                    lines: [{ debit_account: '', credit_account: '', amount: '0.00' }]
                });
            }
        } else if (record) {
            setSelectedVoucherId(record.id);
            try {
                const { data: detailData } = await api.get(`/gl/journal-entries/${record.id}`);
                const v = detailData?.data || detailData;

                // If lines are separate debit/credit, pair them or map directly
                const mappedLines = (v.lines || []).map((l: any) => ({
                    id: l.id,
                    description: l.description,
                    debit_account: compareDecimalMoney(preferredMoney(l.debit_amount_decimal, l.debit_amount), '0') > 0 ? l.account_code : undefined,
                    credit_account: compareDecimalMoney(preferredMoney(l.credit_amount_decimal, l.credit_amount), '0') > 0 ? l.account_code : undefined,
                    account_code: l.account_code,
                    debit_amount: preferredMoney(l.debit_amount_decimal, l.debit_amount),
                    credit_amount: preferredMoney(l.credit_amount_decimal, l.credit_amount),
                    amount: preferredMoney(l.amount_decimal, l.amount, l.debit_amount_decimal, l.debit_amount, l.credit_amount_decimal, l.credit_amount),
                    contact_name: l.contact_name,
                    cost_item_code: l.cost_item_code,
                    cost_object_code: l.cost_object_code,
                    bank_account_id: l.bank_account_id,
                }));

                setReferencedVouchers(v.referenced_vouchers || []);

                form.setFieldsValue({
                    voucher_number: v.voucher_number,
                    voucher_date: v.voucher_date ? dayjs(v.voucher_date) : dayjs(),
                    posting_date: v.posting_date ? dayjs(v.posting_date) : dayjs(),
                    description: v.description || v.reason,
                    attached_docs: v.attached_docs,
                    lines: mappedLines.length > 0 ? mappedLines : [{ debit_account: '', credit_account: '', amount: '0.00' }]
                });
            } catch (err) {
                console.error(err);
            }
        }

        setIsModalVisible(true);
    };

    useEffect(() => {
        const rawSourceId = searchParams.get('source_id');
        if (rawSourceId === null || isLoading || isJournalsError) return;

        const sourceId = Number(rawSourceId);
        const clearSourceQuery = () => {
            const nextParams = new URLSearchParams(searchParams);
            nextParams.delete('source_id');
            setSearchParams(nextParams, { replace: true });
        };

        if (!Number.isSafeInteger(sourceId) || sourceId <= 0) {
            message.error('Liên kết nhật ký chung không hợp lệ.');
            clearSourceQuery();
            return;
        }

        const listed = journals.find((journal: any) => Number(journal.id) === sourceId);
        if (listed) {
            clearSourceQuery();
            void handleOpenModal('view', listed);
            return;
        }

        let active = true;
        api.get('/gl/journal-entries/' + sourceId)
            .then(({ data }) => {
                if (!active) return;
                const record = data?.data ?? data;
                if (!record || Number(record.id) !== sourceId) {
                    throw new Error('Máy chủ không trả về chứng từ nhật ký được yêu cầu.');
                }
                clearSourceQuery();
                void handleOpenModal('view', record);
            })
            .catch((error) => {
                if (!active) return;
                clearSourceQuery();
                message.error(error?.response?.data?.message || 'Không tìm thấy chứng từ nguồn trong doanh nghiệp hiện tại.');
            });

        return () => {
            active = false;
        };
    }, [handleOpenModal, isJournalsError, isLoading, journals, searchParams, setSearchParams]);

    useEffect(() => {
        const openCreateJournal = () => { void handleOpenModal('create'); };
        const refreshJournals = () => { void refetchJournals(); };
        window.addEventListener('open-general-journal', openCreateJournal);
        window.addEventListener('refresh-general-journals', refreshJournals);
        return () => {
            window.removeEventListener('open-general-journal', openCreateJournal);
            window.removeEventListener('refresh-general-journals', refreshJournals);
        };
    }, [handleOpenModal, refetchJournals]);

    const handleSaveForm = (andNew = false, andPrint = false) => {
        form.validateFields().then(values => {
            if (isUnbalanced) {
                message.error(`Tổng phát sinh Nợ (${formatDecimalMoney(totalDebit)}) và Có (${formatDecimalMoney(totalCredit)}) không bằng nhau!`);
                return;
            }
            saveMutation.mutate(values);
            if (andPrint) {
                setPrintVoucherData({
                    voucher_number: values.voucher_number,
                    voucher_date: values.voucher_date,
                    posting_date: values.posting_date,
                    description: values.description,
                    total_amount: totalDebit,
                    attached_docs: values.attached_docs,
                    lines: values.lines
                });
                setIsPrintModalVisible(true);
            }
            if (andNew) handleOpenModal('create');
        }).catch(() => {
            message.error('Vui lòng kiểm tra lại các trường bắt buộc!');
        });
    };

    const handleOpenPrintPreview = (record: any) => {
        setPrintVoucherData({
            voucher_number: record.voucher_number,
            voucher_date: record.voucher_date,
            posting_date: record.posting_date,
            description: record.description || record.reason,
            total_amount: record.total_amount_decimal ?? record.total_amount,
            attached_docs: record.attached_docs,
            lines: record.lines || []
        });
        setIsPrintModalVisible(true);
    };

    const activeJournal = selectedVoucherId
        ? journals.find((journal: any) => journal.id === selectedVoucherId)
        : undefined;
    const actionContext = {
        status: activeJournal?.status || (modalMode === 'create' ? 'draft' : undefined),
        isPosted: activeJournal?.is_posted,
        mode: modalMode,
        hasIdentity: Boolean(selectedVoucherId),
    };
    const runGuardedShortcut = (action: VoucherAction, execute: () => void) => {
        runVoucherAction(action, actionContext, execute, (reason) => message.warning(reason));
    };

    const confirmDeleteJournal = (record: any) => {
        runVoucherAction('delete', {
            status: record.status,
            isPosted: record.is_posted,
            hasIdentity: Boolean(record.id),
        }, () => {
            Modal.confirm({
                title: 'Xác nhận xóa chứng từ',
                content: `Bạn có chắc chắn muốn xóa chứng từ ${record.voucher_number}?`,
                okText: 'Xóa',
                okType: 'danger',
                cancelText: 'Hủy',
                onOk: () => deleteMutation.mutate(record.id),
            });
        }, (reason) => message.warning(reason));
    };

    // 5. Shortcuts use the same state policy as visible row actions.
    useVoucherShortcuts({
        onSave: () => runGuardedShortcut('save', () => handleSaveForm(false, false)),
        onSaveAndNew: () => runGuardedShortcut('save-and-new', () => handleSaveForm(true, false)),
        onPrint: () => {
            if (modalMode === 'view' && activeJournal) {
                handleOpenPrintPreview(activeJournal);
                return;
            }
            runGuardedShortcut('save', () => handleSaveForm(false, true));
        },
        onAddLine: () => runGuardedShortcut('add-line', () => {
            const current = form.getFieldValue('lines') || [];
            form.setFieldsValue({
                lines: [...current, { description: form.getFieldValue('description') || '', debit_account: '', credit_account: '', amount: '0.00' }]
            });
        }),
        onPost: () => runGuardedShortcut('post', () => postMutation.mutate(selectedVoucherId!)),
        onUnpost: () => runGuardedShortcut('unpost', () => unpostMutation.mutate(selectedVoucherId!)),
        onEdit: () => runGuardedShortcut('edit', () => handleOpenModal('edit', activeJournal)),
        onDelete: () => runGuardedShortcut('delete', () => confirmDeleteJournal(activeJournal)),
        onClose: () => setIsModalVisible(false),
        enabled: isModalVisible,
    });

    return (
        <PageShell
            embedded={embedded}
            className="apple-ledger-page"
            title={<PageHeader eyebrow="TỔNG HỢP" title="Nhật ký chung" description="Tra cứu các nghiệp vụ kế toán tổng hợp theo dữ liệu máy chủ." />}
            toolbar={<PageToolbar leading={<Space>
                    <Button type="default" icon={<ReloadOutlined />} onClick={() => refetchJournals()}>
                        Nạp lại
                    </Button>
                    <Button type="default" icon={<FileTextOutlined />}>
                        Báo cáo
                    </Button>
                </Space>} actions={<Space>
                    <Button
                        icon={<PlusOutlined />}
                        onClick={() => handleOpenModal('create')}
                        className="misa-btn-pill-primary"
                    >
                        Thêm Chứng từ nghiệp vụ khác
                    </Button>
                </Space>} />}
        >

            {/* Main Table */}
            <DataTableSurface className="general-journals-table-surface">{isJournalsError ? <Alert
                type="error"
                showIcon
                title="Không thể tải nhật ký chung"
                description="Dữ liệu chưa được xác minh từ máy chủ; không hiển thị danh sách rỗng thay thế."
                action={<Button onClick={() => void refetchJournals()}>Thử lại nhật ký chung</Button>}
            /> : <Table
                columns={[
                    {
                        title: 'Ngày hạch toán',
                        dataIndex: 'posting_date',
                        key: 'posting_date',
                        width: 120,
                        render: (d: string) => d ? dayjs(d).format('DD/MM/YYYY') : '-'
                    },
                    {
                        title: 'Ngày chứng từ',
                        dataIndex: 'voucher_date',
                        key: 'voucher_date',
                        width: 120,
                        render: (d: string) => d ? dayjs(d).format('DD/MM/YYYY') : '-'
                    },
                    {
                        title: 'Số chứng từ',
                        dataIndex: 'voucher_number',
                        key: 'voucher_number',
                        width: 150,
                        render: (text: string, record: any) => (
                            <a
                                onClick={() => handleOpenModal('view', record)}
                                className="font-semibold text-blue-600 hover:underline cursor-pointer"
                            >
                                {text}
                            </a>
                        )
                    },
                    {
                        title: 'Diễn giải',
                        dataIndex: 'description',
                        key: 'description',
                        ellipsis: true
                    },
                    {
                        title: 'Tổng tiền',
                        dataIndex: 'total_amount',
                        key: 'total_amount',
                        width: 160,
                        align: 'right' as const,
                        render: (_: unknown, record: any) => <span className="font-semibold">{formatDecimalMoney(record.total_amount_decimal ?? record.total_amount, { currency: true })}</span>
                    },
                    {
                        title: 'Trạng thái',
                        dataIndex: 'status',
                        key: 'status',
                        width: 130,
                        align: 'center' as const,
                        render: (status: string) => {
                            if (status === 'posted') {
                                return <span className="misa-badge-posted">Đã ghi sổ</span>;
                            }
                            if (status === 'draft') {
                                return <span className="misa-badge-draft">Bản nháp</span>;
                            }
                            return <span className="misa-badge-cancelled">Đã hủy</span>;
                        }
                    },
                    {
                        title: 'Chức năng',
                        key: 'action',
                        width: 140,
                        align: 'center' as const,
                        render: (_: any, record: any) => {
                            const isPosted = record.status === 'posted' || Boolean(record.is_posted);
                            const items = [
                                isPosted ? {
                                    key: 'unpost',
                                    label: 'Bỏ ghi sổ',
                                    icon: <StopOutlined style={{ color: '#fa8c16' }} />,
                                    onClick: () => unpostMutation.mutate(record.id)
                                } : {
                                    key: 'post',
                                    label: 'Ghi sổ',
                                    icon: <CheckCircleOutlined style={{ color: '#0064E0' }} />,
                                    onClick: () => postMutation.mutate(record.id)
                                },
                                ...(!isPosted ? [{
                                    key: 'edit',
                                    label: 'Sửa',
                                    icon: <EditOutlined />,
                                    onClick: () => handleOpenModal('edit', record),
                                }] : []),
                                {
                                    key: 'print',
                                    label: 'In (Mẫu 01-PKT)',
                                    icon: <PrinterOutlined />,
                                    onClick: () => handleOpenPrintPreview(record)
                                },
                                {
                                    key: 'duplicate',
                                    label: 'Nhân bản',
                                    icon: <CopyOutlined />,
                                    onClick: () => duplicateMutation.mutate(record.id)
                                },
                                ...(!isPosted ? [{ type: 'divider' as const }, {
                                    key: 'delete',
                                    label: <span className="text-red-500">Xóa</span>,
                                    icon: <DeleteOutlined style={{ color: '#ff4d4f' }} />,
                                    onClick: () => confirmDeleteJournal(record),
                                }] : []),
                            ];

                            return (
                                <Space size="small">
                                    <Button
                                        type="link"
                                        size="small"
                                        onClick={() => handleOpenModal(isPosted ? 'view' : 'edit', record)}
                                    >
                                        {isPosted ? 'Xem' : 'Sửa'}
                                    </Button>
                                    <Dropdown menu={{ items }} trigger={['click']}>
                                        <Button type="text" size="small" icon={<DownOutlined />} />
                                    </Dropdown>
                                </Space>
                            );
                        },
                    },
                ]}
                dataSource={journals || []}
                rowKey="id"
                loading={isLoading}
                size="small"
                bordered
                pagination={{ pageSize: 20, showSizeChanger: true }}
            />}</DataTableSurface>

            {/* MISA Modal: Master Card + Detail Grid + Tabs */}
            <Modal
                title={
                    <div className="flex justify-between items-center w-full pr-8 border-b pb-2 mb-2">
                        <div className="flex items-center gap-3">
                            <span className="text-xl font-bold">Chứng từ nghiệp vụ khác</span>
                            {selectedVoucherId && (
                                <Tag color={modalMode === 'view' ? 'blue' : 'orange'}>
                                    {modalMode === 'view' ? 'Chế độ xem' : 'Chỉnh sửa'}
                                </Tag>
                            )}
                        </div>
                        <div className="text-right flex items-center gap-4">
                            <span className="text-sm text-gray-500 block">Tổng tiền</span>
                            <span className="text-2xl font-bold text-gray-800 w-48 text-right">
                                {formatDecimalMoney(totalDebit, { currency: true })}
                            </span>
                        </div>
                    </div>
                }
                open={isModalVisible}
                onCancel={() => setIsModalVisible(false)}
                width="95vw"
                footer={
                    <ConfigProvider componentSize="small" componentDisabled={modalMode === 'view'}>
                        <div className="misa-modal-footer-container">
                            <div className="text-slate-600 text-sm flex gap-4">
                                <span>Trạng thái: {selectedVoucherId ? (modalMode === 'view' ? 'Đã ghi sổ' : 'Đang sửa') : 'Bản nháp'}</span>
                                {isUnbalanced && <span className="text-red-500 font-bold">Lệch Nợ/Có: {formatDecimalMoney(balanceDifference)}</span>}
                            </div>
                            <Space>
                                <Button
                                    onClick={() => setIsModalVisible(false)}
                                    className="misa-btn-dark-cancel"
                                >
                                    Hủy (ESC)
                                </Button>
                                {modalMode !== 'view' && (
                                    <>
                                        <Button
                                            onClick={() => handleSaveForm(false, false)}
                                            className="misa-btn-dark-submit"
                                            loading={saveMutation.isPending}
                                            disabled={isUnbalanced}
                                        >
                                            Cất (Ctrl+S)
                                        </Button>
                                        <Button
                                            type="primary"
                                            onClick={() => handleSaveForm(true, false)}
                                            className="misa-btn-dark-save-add"
                                            loading={saveMutation.isPending}
                                            disabled={isUnbalanced}
                                        >
                                            Cất và Thêm (Ctrl+Shift+S)
                                        </Button>
                                    </>
                                )}
                                <Button
                                    icon={<PrinterOutlined />}
                                    onClick={() => handleSaveForm(false, true)}
                                    disabled={isUnbalanced}
                                >
                                    In (Mẫu 01-PKT)
                                </Button>
                            </Space>
                        </div>
                    </ConfigProvider>
                }
            >
                <ModalFrame>
                <div className="app-modal-form-body">
                    <Form form={form} layout="vertical" onFinish={saveMutation.mutate} size="small" disabled={modalMode === 'view'}>
                        {/* Master Card: Thông tin chung & Chứng từ */}
                        <div className="flex gap-6 mb-4">
                            <div className="flex-1 border p-4 rounded bg-gray-50">
                                <h3 className="font-bold text-slate-700 mb-2">Thông tin chung</h3>
                                <div className="grid grid-cols-2 gap-x-4">
                                    <Form.Item name="description" label="Diễn giải" className="mb-2 col-span-2" rules={[{ required: true, message: 'Vui lòng nhập diễn giải' }]}>
                                        <Input placeholder="Ví dụ: Trích chi phí lương, trích khấu hao, bù trừ công nợ..." />
                                    </Form.Item>
                                    <Form.Item name="attached_docs" label="Kèm theo" className="mb-2">
                                        <Input placeholder="Số lượng chứng từ gốc kèm theo..." />
                                    </Form.Item>
                                </div>
                            </div>

                            <div className="w-[350px] border p-4 rounded bg-gray-50">
                                <h3 className="font-bold text-slate-700 mb-2">Chứng từ</h3>
                                <div className="grid grid-cols-2 gap-x-4">
                                    <Form.Item name="posting_date" label="Ngày hạch toán" rules={[{ required: true }]} className="mb-2">
                                        <DatePicker className="w-full" format="DD/MM/YYYY" />
                                    </Form.Item>
                                    <Form.Item name="voucher_date" label="Ngày chứng từ" rules={[{ required: true }]} className="mb-2">
                                        <DatePicker className="w-full" format="DD/MM/YYYY" />
                                    </Form.Item>
                                    <Form.Item name="voucher_number" label="Số chứng từ" rules={[{ required: true }]} className="col-span-2 mb-2">
                                        <Input className="font-semibold" />
                                    </Form.Item>
                                </div>
                            </div>
                        </div>

                        {/* Tabs: Hạch toán & Tham chiếu */}
                        <div className="border border-gray-200 rounded">
                            <Tabs type="card" size="small" className="misa-tabs" items={[
                                { key: '1', label: '1. Hạch toán', children: (
                                    <Form.List name="lines">
                                        {(fields, { add, remove }) => (
                                            <>
                                                <Table
                                                    dataSource={fields}
                                                    pagination={false}
                                                    size="small"
                                                    rowKey="key"
                                                    scroll={{ x: 1200 }}
                                                    columns={[
                                                        {
                                                            title: '#',
                                                            width: 50,
                                                            render: (_, __, index) => (
                                                                <Popconfirm title="Xóa dòng?" onConfirm={() => remove(index)}>
                                                                    <Button type="text" danger icon={<DeleteOutlined />} size="small" />
                                                                </Popconfirm>
                                                            )
                                                        },
                                                        {
                                                            title: 'Diễn giải',
                                                            width: 220,
                                                            render: (_, field) => (
                                                                <Form.Item name={[field.name, 'description']} noStyle>
                                                                    <Input placeholder="Diễn giải chi tiết..." />
                                                                </Form.Item>
                                                            )
                                                        },
                                                        {
                                                            title: 'TK Nợ',
                                                            width: 130,
                                                            render: (_, field) => (
                                                                <Form.Item name={[field.name, 'debit_account']} noStyle rules={[{ required: true, message: 'Chọn TK Nợ' }]}>
                                                                    <Select showSearch placeholder="TK Nợ" optionFilterProp="children">
                                                                        {chartOfAccounts?.filter((acc: any) => !acc.is_parent && acc.is_active !== false).map((acc: any) => (
                                                                            <Select.Option key={`deb_${acc.code}`} value={acc.code}>
                                                                                {acc.code} - {acc.name}
                                                                            </Select.Option>
                                                                        ))}
                                                                    </Select>
                                                                </Form.Item>
                                                            )
                                                        },
                                                        {
                                                            title: 'TK Có',
                                                            width: 130,
                                                            render: (_, field) => (
                                                                <Form.Item name={[field.name, 'credit_account']} noStyle rules={[{ required: true, message: 'Chọn TK Có' }]}>
                                                                    <Select showSearch placeholder="TK Có" optionFilterProp="children">
                                                                        {chartOfAccounts?.filter((acc: any) => !acc.is_parent && acc.is_active !== false).map((acc: any) => (
                                                                            <Select.Option key={`crd_${acc.code}`} value={acc.code}>
                                                                                {acc.code} - {acc.name}
                                                                            </Select.Option>
                                                                        ))}
                                                                    </Select>
                                                                </Form.Item>
                                                            )
                                                        },
                                                        {
                                                            title: 'Số tiền',
                                                            width: 160,
                                                            render: (_, field) => (
                                                                <Form.Item name={[field.name, 'amount']} noStyle initialValue="0.00" rules={[{ required: true }]}>
                                                                    <InputNumber
                                                                        stringMode
                                                                        formatter={formatDecimalMoneyInput}
                                                                        parser={(value) => parseDecimalMoneyInput(value) as any}
                                                                        className="w-full text-right font-semibold"
                                                                        min={0}
                                                                    />
                                                                </Form.Item>
                                                            )
                                                        },
                                                        {
                                                            title: 'Đối tượng',
                                                            width: 180,
                                                            render: (_, field) => (
                                                                <Form.Item name={[field.name, 'contact_name']} noStyle>
                                                                    <Input placeholder="Tên đối tượng..." />
                                                                </Form.Item>
                                                            )
                                                        },
                                                        {
                                                            title: 'Khoản mục CP',
                                                            width: 130,
                                                            render: (_, field) => (
                                                                <Form.Item name={[field.name, 'cost_item_code']} noStyle>
                                                                    <Input placeholder="Mã KMCP..." />
                                                                </Form.Item>
                                                            )
                                                        },
                                                        {
                                                            title: 'Đối tượng THCP',
                                                            width: 130,
                                                            render: (_, field) => (
                                                                <Form.Item name={[field.name, 'cost_object_code']} noStyle>
                                                                    <Input placeholder="Mã ĐTTHCP..." />
                                                                </Form.Item>
                                                            )
                                                        },
                                                        {
                                                            title: 'TK Ngân hàng',
                                                            width: 160,
                                                            render: (_, field) => (
                                                                <Form.Item name={[field.name, 'bank_account_id']} noStyle>
                                                                    <Select allowClear placeholder="Chọn TK NH">
                                                                        {bankAccounts?.map((b: any) => (
                                                                            <Select.Option key={b.id} value={b.id}>
                                                                                {b.account_number} ({b.bank_name})
                                                                            </Select.Option>
                                                                        ))}
                                                                    </Select>
                                                                </Form.Item>
                                                            )
                                                        },
                                                    ]}
                                                    summary={() => (
                                                        <Table.Summary.Row className="misa-summary-row-bold">
                                                            <Table.Summary.Cell index={0} colSpan={4}>Tổng cộng</Table.Summary.Cell>
                                                            <Table.Summary.Cell index={4} className={isUnbalanced ? 'text-red-500 text-right font-bold' : 'text-green-600 text-right font-bold'}>
                                                                {formatDecimalMoney(totalDebit)}
                                                            </Table.Summary.Cell>
                                                            <Table.Summary.Cell index={5} colSpan={4}></Table.Summary.Cell>
                                                        </Table.Summary.Row>
                                                    )}
                                                />
                                                <Button
                                                    type="dashed"
                                                    onClick={() => add({ description: form.getFieldValue('description') || '', debit_account: '', credit_account: '', amount: '0.00' })}
                                                    block
                                                    icon={<PlusOutlined />}
                                                    className="mt-2 text-left"
                                                >
                                                    Thêm dòng
                                                </Button>
                                            </>
                                        )}
                                    </Form.List>
                                ) },

                                { key: '2', label: `2. Tham chiếu ${referencedVouchers.length > 0 ? `(${referencedVouchers.length})` : ''}`, children: (
                                    <div style={{ padding: '8px 0' }}>
                                        <div style={{ display: 'flex', justifyContent: 'flex-end', marginBottom: 8 }}>
                                            <Button
                                                type="default"
                                                size="small"
                                                icon={<LinkOutlined style={{ color: '#0064E0' }} />}
                                                onClick={() => setIsRefModalVisible(true)}
                                                style={{ color: '#0064E0', borderColor: '#BFDBFE', background: '#EBF5FF', fontWeight: 500 }}
                                            >
                                                Chọn chứng từ tham chiếu
                                            </Button>
                                        </div>
                                        {referencedVouchers.length > 0 ? (
                                            <div className="misa-table-container">
                                                <table className="misa-voucher-table">
                                                    <thead>
                                                        <tr>
                                                            <th style={{ width: 40 }}>#</th>
                                                            <th style={{ width: 140 }}>Loại chứng từ</th>
                                                            <th style={{ width: 130 }}>Số chứng từ</th>
                                                            <th style={{ width: 120 }}>Ngày chứng từ</th>
                                                            <th style={{ width: 160 }}>Đối tượng</th>
                                                            <th>Diễn giải</th>
                                                            <th style={{ width: 140, textAlign: 'right' }}>Số tiền</th>
                                                            <th style={{ width: 50 }}></th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        {referencedVouchers.map((v, i) => (
                                                            <tr key={v.id || i}>
                                                                <td>{i + 1}</td>
                                                                <td>{v.voucher_type}</td>
                                                                <td><span style={{ color: '#0064E0', fontWeight: 600 }}>{v.voucher_number}</span></td>
                                                                <td>{v.voucher_date || '-'}</td>
                                                                <td>{v.contact_name || '-'}</td>
                                                                <td>{v.description || '-'}</td>
                                                                <td style={{ textAlign: 'right', fontWeight: 600 }}>
                                                                    {formatDecimalMoney(v.total_amount_decimal ?? v.total_amount, { currency: true })}
                                                                </td>
                                                                <td style={{ textAlign: 'center' }}>
                                                                    <button
                                                                        type="button"
                                                                        className="misa-btn-row-delete"
                                                                        onClick={() => setReferencedVouchers(referencedVouchers.filter((_, idx) => idx !== i))}
                                                                        title="Xóa tham chiếu"
                                                                    >
                                                                        <DeleteOutlined />
                                                                    </button>
                                                                </td>
                                                            </tr>
                                                        ))}
                                                    </tbody>
                                                </table>
                                            </div>
                                        ) : (
                                            <div style={{ padding: '30px 0', textAlign: 'center', background: '#fafafa', borderRadius: 4, border: '1px dashed #d9d9d9' }}>
                                                <p style={{ color: '#6b7280', marginBottom: 12 }}>Chưa có chứng từ tham chiếu nào</p>
                                                <Button
                                                    type="primary"
                                                    size="small"
                                                    icon={<LinkOutlined />}
                                                    onClick={() => setIsRefModalVisible(true)}
                                                    className="misa-btn-primary"
                                                >
                                                    Chọn chứng từ tham chiếu ngay
                                                </Button>
                                            </div>
                                        )}
                                    </div>
                                ) },
                            ]} />
                        </div>

                        {/* MISA Action Footer */}
                    </Form>
                </div>
                </ModalFrame>
            </Modal>

            {/* Reference Voucher Selection Modal */}
            <ReferenceVoucherModal
                open={isRefModalVisible}
                onCancel={() => setIsRefModalVisible(false)}
                onSelect={(selected) => {
                    const newRefs = [...referencedVouchers, ...selected];
                    setReferencedVouchers(newRefs);
                    if (selected.length > 0) {
                        const first = selected[0];
                        if (first.description && !form.getFieldValue('description')) {
                            form.setFieldsValue({
                                description: `Tổng hợp theo ${selected.map(s => s.voucher_number).join(', ')}`
                            });
                        }
                        message.success(`Đã nạp ${selected.length} chứng từ tham chiếu!`);
                    }
                }}
            />

            {/* Voucher Print Preview Modal (Phiếu kế toán Mẫu 01-PKT) */}
            <VoucherPrintModal
                open={isPrintModalVisible}
                onCancel={() => setIsPrintModalVisible(false)}
                type="journal"
                data={printVoucherData}
            />
        </PageShell>
    );
};

export default GeneralJournals;
