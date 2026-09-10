import React from 'react';
import { Select, Button } from 'antd';
import { DeleteOutlined, PlusOutlined } from '@ant-design/icons';
import { MisaGridActionFooter } from '../../../components/misa';
import { SalesDiscountAccountingTab } from './SalesDiscountAccountingTab';
import { SalesDiscountTaxTab } from './SalesDiscountTaxTab';
import { SalesDiscountStatisticTab } from './SalesDiscountStatisticTab';
import type { SalesDiscountLine, InventoryItemOption } from '../types';

export interface SalesDiscountGridProps {
    activeTab: 'accounting' | 'tax' | 'statistic';
    lines: SalesDiscountLine[];
    items: InventoryItemOption[];
    disabled?: boolean;
    onItemChange: (index: number, itemId: number) => void;
    onUpdateLine: (index: number, changes: Partial<SalesDiscountLine>) => void;
    onRemoveLine: (index: number) => void;
    onAddLine: () => void;
    onRemoveAllLines: () => void;
    onQuickAddItem?: (index: number) => void;
}

export const SalesDiscountGrid: React.FC<SalesDiscountGridProps> = ({
    activeTab,
    lines,
    items,
    disabled = false,
    onItemChange,
    onUpdateLine,
    onRemoveLine,
    onAddLine,
    onRemoveAllLines,
    onQuickAddItem
}) => {
    return (
        <div className="misa-grid-container misa-mt-12">
            <div className="misa-table-container">
                <table className="misa-voucher-table" style={{ minWidth: 1300 }}>
                    <thead>
                        <tr>
                            <th className="misa-w-40 misa-text-center">#</th>
                            <th className="misa-w-160">Mã hàng</th>
                            <th className="misa-min-w-180">Tên hàng</th>
                            {activeTab === 'accounting' && (
                                <>
                                    <th className="misa-w-80 misa-text-center">TK Nợ</th>
                                    <th className="misa-w-80 misa-text-center">TK Có</th>
                                    <th className="misa-w-80">ĐVT</th>
                                    <th className="misa-w-90 misa-text-right">Số lượng</th>
                                    <th className="misa-w-130 misa-text-right">Đơn giá giảm</th>
                                    <th className="misa-w-140 misa-text-right">Thành tiền</th>
                                    <th className="misa-w-160">Diễn giải</th>
                                </>
                            )}
                            {activeTab === 'tax' && (
                                <>
                                    <th className="misa-w-90 misa-text-center">% Thuế GTGT</th>
                                    <th className="misa-w-140 misa-text-right">Tiền thuế GTGT</th>
                                    <th className="misa-w-90 misa-text-center">TK Thuế</th>
                                    <th className="misa-w-120">Số hóa đơn</th>
                                    <th className="misa-w-120">Ngày hóa đơn</th>
                                </>
                            )}
                            {activeTab === 'statistic' && (
                                <>
                                    <th className="misa-w-140">Đơn đặt hàng</th>
                                    <th className="misa-w-140">Hợp đồng bán</th>
                                    <th className="misa-w-140">Khoản mục CP</th>
                                </>
                            )}
                            {!disabled && <th className="misa-w-40 misa-text-center"></th>}
                        </tr>
                    </thead>
                    <tbody>
                        {(lines || []).map((line, index) => (
                            <tr key={line.key || index}>
                                <td className="misa-text-center misa-color-muted misa-fw-600">{index + 1}</td>
                                <td>
                                    <Select
                                        showSearch
                                        value={line.item_id}
                                        placeholder="Mã..."
                                        className="misa-table-input misa-w-full"
                                        variant="borderless"
                                        disabled={disabled}
                                        optionLabelProp="label"
                                        popupMatchSelectWidth={false}
                                        popupClassName="misa-multicolumn-item-popup"
                                        dropdownStyle={{ minWidth: 680, width: 680 }}
                                        filterOption={(input, option: any) => {
                                            const code = String(option?.itemCode || option?.label || '').toLowerCase();
                                            const name = String(option?.itemName || '').toLowerCase();
                                            const q = input.toLowerCase();
                                            return code.includes(q) || name.includes(q);
                                        }}
                                        onChange={val => onItemChange(index, val)}
                                        options={items.map((it: InventoryItemOption) => ({
                                            value: it.id,
                                            label: it.code,
                                            itemCode: it.code,
                                            itemName: it.name,
                                            itemStock: it.stock_quantity,
                                            itemPrice: it.sale_price ?? it.cost_price
                                        }))}
                                        optionRender={option => (
                                            <div className="misa-cell-dropdown-grid-4col">
                                                <span className="misa-text-semibold">{option.data.itemCode}</span>
                                                <span className="misa-text-truncate">{option.data.itemName}</span>
                                                <span className="misa-text-right misa-text-blue">{option.data.itemStock ?? '—'}</span>
                                                <span className="misa-text-right">
                                                    {option.data.itemPrice === undefined
                                                        ? '—'
                                                        : new Intl.NumberFormat('vi-VN').format(option.data.itemPrice)}
                                                </span>
                                            </div>
                                        )}
                                        dropdownRender={menu => (
                                            <div>
                                                <div className="misa-grid-dropdown-header misa-cell-dropdown-grid-4col">
                                                    <span>Mã hàng</span>
                                                    <span>Tên hàng</span>
                                                    <span className="misa-text-right">Số lượng tồn</span>
                                                    <span className="misa-text-right">Đơn giá bán</span>
                                                </div>
                                                {menu}
                                                {!disabled && onQuickAddItem && (
                                                    <div className="misa-grid-dropdown-footer">
                                                        <Button
                                                            type="link"
                                                            size="small"
                                                            icon={<PlusOutlined />}
                                                            onClick={() => onQuickAddItem(index)}
                                                            className="misa-btn-tool-sm misa-text-blue misa-text-semibold"
                                                        >
                                                            Thêm mới
                                                        </Button>
                                                    </div>
                                                )}
                                            </div>
                                        )}
                                    />
                                </td>
                                <td>
                                    <input
                                        className="misa-table-input"
                                        value={line.item_name || ''}
                                        disabled={disabled}
                                        onChange={e => onUpdateLine(index, { item_name: e.target.value })}
                                    />
                                </td>
                                {activeTab === 'accounting' && (
                                    <SalesDiscountAccountingTab
                                        line={line}
                                        index={index}
                                        disabled={disabled}
                                        onUpdateLine={onUpdateLine}
                                    />
                                )}
                                {activeTab === 'tax' && (
                                    <SalesDiscountTaxTab
                                        line={line}
                                        index={index}
                                        disabled={disabled}
                                        onUpdateLine={onUpdateLine}
                                    />
                                )}
                                {activeTab === 'statistic' && (
                                    <SalesDiscountStatisticTab
                                        line={line}
                                        index={index}
                                        disabled={disabled}
                                        onUpdateLine={onUpdateLine}
                                    />
                                )}
                                {!disabled && (
                                    <td className="misa-text-center">
                                        <button
                                            type="button"
                                            className="misa-btn-icon-del"
                                            title="Xóa dòng"
                                            onClick={() => onRemoveLine(index)}
                                        >
                                            <DeleteOutlined className="misa-fs-13" />
                                        </button>
                                    </td>
                                )}
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            {!disabled && (
                <MisaGridActionFooter
                    onAddLine={onAddLine}
                    onAddNote={onAddLine}
                    onDeleteAll={onRemoveAllLines}
                    lineCount={lines.length}
                />
            )}
        </div>
    );
};

export default SalesDiscountGrid;
