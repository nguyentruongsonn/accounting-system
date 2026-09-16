import React, { useEffect, useMemo, useState } from 'react';
import { Button, DatePicker, Input, InputNumber, Space, Table } from 'antd';
import type { TableProps } from 'antd';
import dayjs, { type Dayjs } from 'dayjs';
import Modal from '../../../components/layout/AppModal';
import { CloseOutlined, DeleteOutlined, QuestionCircleOutlined, SearchOutlined } from '@ant-design/icons';
import { runManualDataLoad } from '../../../components/feedback/runManualDataLoad';
import { toast } from '../../../components/feedback/toast';
import api from '../../../api/axios';
import { useQuery } from '@tanstack/react-query';
import {
    flattenPurchaseExpenseCandidates,
    toAmountString,
    type PurchaseExpenseAllocationSelection,
    type PurchaseExpenseCandidate,
} from './purchaseExpenseAllocation';

export interface SelectExpenseVoucherModalProps {
    open: boolean;
    onCancel: () => void;
    onSelect: (selectedVouchers: PurchaseExpenseAllocationSelection[]) => void;
    supplierId?: number;
    sourceInvoiceId?: number;
    sourceExpenseTotal?: number | string;
    existingSelections?: PurchaseExpenseAllocationSelection[];
    selectionMode?: 'purchase-targets' | 'expense-sources';
}

type CandidateLine = ReturnType<typeof flattenPurchaseExpenseCandidates>[number];

const numberFormatter = new Intl.NumberFormat('vi-VN');

const amount = (value: string | number | null | undefined): number => {
    const parsed = Number(value);
    return Number.isFinite(parsed) ? parsed : 0;
};

const money = (value: string | number | null | undefined): string => `${numberFormatter.format(amount(value))} ₫`;
const EMPTY_SELECTIONS: PurchaseExpenseAllocationSelection[] = [];

const parseCandidates = (payload: unknown): PurchaseExpenseCandidate[] => {
    if (Array.isArray(payload)) return payload as PurchaseExpenseCandidate[];
    if (payload && typeof payload === 'object' && Array.isArray((payload as { data?: unknown }).data)) {
        return (payload as { data: PurchaseExpenseCandidate[] }).data;
    }
    throw new Error('Dữ liệu chứng từ mua hàng không hợp lệ.');
};

/** Select target purchase-invoice lines that can receive this expense source. */
export const SelectExpenseVoucherModal: React.FC<SelectExpenseVoucherModalProps> = ({
    open,
    onCancel,
    onSelect,
    supplierId,
    sourceInvoiceId,
    sourceExpenseTotal,
    existingSelections = EMPTY_SELECTIONS,
    selectionMode = 'purchase-targets',
}) => {
    const [search, setSearch] = useState('');
    const [asOfDate, setAsOfDate] = useState<Dayjs | null>(null);
    const [selectionByLine, setSelectionByLine] = useState<Record<number, PurchaseExpenseAllocationSelection>>({});

    const candidatesQuery = useQuery({
        queryKey: [
            'purchase-expense-allocation-candidates',
            sourceInvoiceId ?? 'new',
            supplierId ?? 'all',
            asOfDate?.format('YYYY-MM-DD') ?? '',
            search.trim(),
        ],
        enabled: open && selectionMode === 'purchase-targets',
        queryFn: async (): Promise<PurchaseExpenseCandidate[]> => {
            const params = new URLSearchParams();
            if (sourceInvoiceId !== undefined) params.set('source_invoice_id', String(sourceInvoiceId));
            if (supplierId !== undefined) params.set('supplier_id', String(supplierId));
            if (asOfDate) params.set('as_of_date', asOfDate.format('YYYY-MM-DD'));
            if (search.trim()) params.set('search', search.trim());
            params.set('per_page', '100');
            const { data } = await api.get(`/purchase/invoices/expense-allocation-candidates?${params.toString()}`);
            return parseCandidates(data);
        },
    });

    useEffect(() => {
        if (!open) return;
        const next: Record<number, PurchaseExpenseAllocationSelection> = {};
        existingSelections.forEach((selection) => {
            if (selection.target_purchase_invoice_line_id) {
                next[selection.target_purchase_invoice_line_id] = {
                    ...selection,
                    allocated_amount: toAmountString(selection.allocated_amount),
                    allocation_method: selection.allocation_method ?? 'value',
                };
            }
        });
        setSelectionByLine(next);
    }, [open, existingSelections]);

    const rows = useMemo(() => flattenPurchaseExpenseCandidates(candidatesQuery.data ?? []), [candidatesQuery.data]);
    const selectedRows = useMemo(() => Object.values(selectionByLine), [selectionByLine]);
    const selectedRowKeys = selectedRows.map((selection) => selection.target_purchase_invoice_line_id);
    const totalAllocated = selectedRows.reduce((sum, selection) => sum + amount(selection.allocated_amount), 0);
    const sourceTotal = amount(sourceExpenseTotal);
    const exceedsSource = sourceTotal > 0 && totalAllocated > sourceTotal + 0.005;

    const updateSelection = (row: CandidateLine, checked: boolean) => {
        setSelectionByLine((current) => {
            const next = { ...current };
            if (!checked) {
                delete next[row.id];
                return next;
            }
            next[row.id] = {
                target_purchase_invoice_id: row.target_purchase_invoice_id,
                target_purchase_invoice_line_id: row.id,
                allocated_amount: toAmountString(row.remaining_allocatable_amount),
                allocation_method: 'value',
                voucher_number: row.voucher_number,
                voucher_date: row.voucher_date,
                supplier_name: row.supplier_name,
                item_code: row.item_code,
                item_name: row.item_name,
                remaining_allocatable_amount: toAmountString(row.remaining_allocatable_amount),
            };
            return next;
        });
    };

    const updateAmount = (row: CandidateLine, value: number | null) => {
        setSelectionByLine((current) => {
            const existing = current[row.id];
            if (!existing) return current;
            return {
                ...current,
                [row.id]: { ...existing, allocated_amount: toAmountString(value ?? 0) },
            };
        });
    };

    const handleConfirm = () => {
        if (selectedRows.length === 0) return;
        if (selectedRows.some((selection) => amount(selection.allocated_amount) <= 0)) {
            toast.error('Số tiền phân bổ theo dòng phải lớn hơn 0.');
            return;
        }
        if (exceedsSource) {
            toast.error(`Tổng phân bổ không được vượt quá ${money(sourceTotal)}.`);
            return;
        }
        onSelect(selectedRows);
    };

    const columns: TableProps<CandidateLine>['columns'] = [
        {
            title: 'Chứng từ',
            key: 'voucher',
            width: 190,
            render: (_, row) => (
                <div>
                    <div className="misa-text-bold">{row.voucher_number || '—'}</div>
                    <div className="apple-muted-text">{row.voucher_date ? dayjs(row.voucher_date).format('DD/MM/YYYY') : '—'}</div>
                </div>
            ),
        },
        { title: 'Nhà cung cấp', dataIndex: 'supplier_name', key: 'supplier_name', width: 190, ellipsis: true },
        {
            title: 'Hàng hóa',
            key: 'item',
            render: (_, row) => (
                <div>
                    <div className="misa-text-bold">{row.item_code || '—'}</div>
                    <div className="apple-muted-text">{row.item_name || '—'}</div>
                </div>
            ),
        },
        {
            title: 'Giá trị còn được phân bổ',
            dataIndex: 'remaining_allocatable_amount',
            key: 'remaining_allocatable_amount',
            width: 180,
            align: 'right',
            render: (value) => money(value),
        },
        {
            title: 'Số tiền phân bổ',
            key: 'allocated_amount',
            width: 170,
            align: 'right',
            render: (_, row) => {
                const selection = selectionByLine[row.id];
                return (
                    <InputNumber
                        min={0}
                        max={amount(row.remaining_allocatable_amount)}
                        precision={2}
                        value={selection ? amount(selection.allocated_amount) : undefined}
                        placeholder="Chọn dòng"
                        disabled={!selection}
                        onChange={(value) => updateAmount(row, value)}
                        style={{ width: '100%' }}
                    />
                );
            },
        },
    ];

    const rowSelection: TableProps<CandidateLine>['rowSelection'] = {
        selectedRowKeys,
        onSelect: updateSelection,
        onSelectAll: (checked, selectedRowsOnPage) => {
            selectedRowsOnPage.forEach((row) => updateSelection(row, checked));
        },
        preserveSelectedRowKeys: true,
    };

    const isExpenseSourceMode = selectionMode === 'expense-sources';
    const queryError = candidatesQuery.error instanceof Error
        ? candidatesQuery.error.message
        : 'Không thể tải danh sách chứng từ mua hàng.';

    return (
        <Modal
            open={open}
            onCancel={onCancel}
            width={1120}
            className="misa-modal-top-30"
            destroyOnHidden
            closeIcon={<CloseOutlined className="text-sm" />}
            title={
                <div className="misa-modal-title-between-sm">
                    <div className="misa-flex-center-gap-8">
                        <span className="misa-font-16-bold">Chọn chứng từ mua hàng</span>
                        <QuestionCircleOutlined className="apple-muted-text text-sm" />
                    </div>
                </div>
            }
            footer={
                <div className="misa-modal-footer-end">
                    <Button
                        icon={<DeleteOutlined />}
                        onClick={() => setSelectionByLine({})}
                        disabled={selectedRows.length === 0}
                        className="misa-btn-secondary"
                    >
                        Bỏ chọn
                    </Button>
                    <Button onClick={onCancel} className="misa-btn-secondary">Hủy</Button>
                    <Button
                        type="primary"
                        onClick={handleConfirm}
                        disabled={selectedRows.length === 0 || exceedsSource}
                        className="misa-btn-modal-action"
                    >
                        Đồng ý
                    </Button>
                </div>
            }
        >
            {isExpenseSourceMode ? (
                <div className="misa-empty-table-cell misa-table-card">
                    Chưa có API nguồn chi phí để chọn ngược từ chứng từ hàng hóa.
                </div>
            ) : (
                <>
                    <div className="misa-flex-between misa-mb-12" style={{ gap: 12, flexWrap: 'wrap' }}>
                        <Space wrap>
                            <Input
                                allowClear
                                prefix={<SearchOutlined />}
                                value={search}
                                onChange={(event) => setSearch(event.target.value)}
                                placeholder="Tìm số chứng từ, nhà cung cấp..."
                                style={{ width: 300 }}
                            />
                            <DatePicker
                                allowClear
                                value={asOfDate}
                                onChange={setAsOfDate}
                                format="DD/MM/YYYY"
                                placeholder="Đến ngày hạch toán"
                            />
                            <Button
                                onClick={() => void runManualDataLoad(
                                    () => candidatesQuery.refetch(),
                                    { success: 'Tải danh sách chứng từ mua hàng thành công.', failure: 'Không thể tải danh sách chứng từ mua hàng.' },
                                )}
                            >
                                Tải dữ liệu
                            </Button>
                        </Space>
                        <div className="misa-text-right">
                            <div className="apple-muted-text">Đã chọn {selectedRows.length} dòng</div>
                            <div className={exceedsSource ? 'misa-text-red misa-text-bold' : 'misa-text-blue misa-text-bold'}>
                                Phân bổ: {money(totalAllocated)}{sourceTotal > 0 ? ` / ${money(sourceTotal)}` : ''}
                            </div>
                        </div>
                    </div>

                    {candidatesQuery.isError && (
                        <div className="misa-mb-12" style={{ color: '#b91c1c' }} role="alert">
                            {queryError}
                            <Button
                                type="link"
                                onClick={() => void runManualDataLoad(
                                    () => candidatesQuery.refetch(),
                                    { success: 'Tải danh sách chứng từ mua hàng thành công.', failure: 'Không thể tải danh sách chứng từ mua hàng.' },
                                )}
                            >
                                Thử lại
                            </Button>
                        </div>
                    )}

                    <Table<CandidateLine>
                        rowKey="id"
                        size="small"
                        dataSource={rows}
                        columns={columns}
                        rowSelection={rowSelection}
                        loading={{ spinning: candidatesQuery.isLoading || candidatesQuery.isFetching, description: 'Đang tải dữ liệu...' }}
                        pagination={{ pageSize: 8, showSizeChanger: false }}
                        scroll={{ y: 420 }}
                        locale={{ emptyText: 'Không có dòng hàng nào còn giá trị được phân bổ.' }}
                    />
                </>
            )}
        </Modal>
    );
};

export default SelectExpenseVoucherModal;
