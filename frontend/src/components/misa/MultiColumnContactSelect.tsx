import React, { useMemo } from 'react';
import { PlusOutlined } from '@ant-design/icons';
import { AdaptiveSelect } from '../layout/AdaptiveSelect';

export interface ContactItem {
    id: number | string;
    code: string;
    name: string;
    tax_code?: string;
    address?: string;
    phone?: string;
    department?: string;
    branch?: string;
    contact_person?: string;
    type?: 'customer' | 'supplier' | 'employee' | 'bank';
}

interface MultiColumnContactSelectProps {
    value?: string | number;
    placeholder?: string;
    options: ContactItem[];
    onChange?: (val: string | number, item?: ContactItem) => void;
    onQuickAdd?: () => void;
    disabled?: boolean;
    entityType?: 'customer' | 'supplier' | 'employee' | 'bank';
    className?: string;
    style?: React.CSSProperties;
    allowClear?: boolean;
}

export const MultiColumnContactSelect: React.FC<MultiColumnContactSelectProps> = ({
    value,
    placeholder = 'Chọn khách hàng / đối tượng...',
    options = [],
    onChange,
    onQuickAdd,
    disabled = false,
    entityType = 'customer',
    className = '',
    style,
    allowClear = true
}) => {
    const selectOptions = useMemo(() => {
        return options.map((item) => ({
            value: item.id,
            label: item.code,
            searchText: `${item.code} ${item.name} ${item.tax_code || ''} ${item.address || ''} ${item.phone || ''} ${item.department || ''} ${item.branch || ''}`.toLowerCase(),
            data: item,
        }));
    }, [options]);

    // Persist the server resource ID. Codes are display/search evidence only and
    // must never be submitted in an *_id field.
    const resolvedValue = useMemo(() => {
        if (value === undefined || value === null || value === '') return undefined;
        const matched = options.find(o => o.code === value || String(o.id) === String(value));
        return matched ? matched.id : value;
    }, [value, options]);

    const dropdownWidth = entityType === 'employee' ? 880 : entityType === 'bank' ? 820 : 1120;

    // When inside misa-input-group (with plus button), use borderless so group border shows.
    // Otherwise outlined so the Select itself shows a clear visible border.
    const selectVariant: 'outlined' | 'borderless' = onQuickAdd ? 'borderless' : 'outlined';

    const selectElement = (
        <AdaptiveSelect
            showSearch
            value={resolvedValue}
            placeholder={placeholder}
            disabled={disabled}
            allowClear={allowClear}
            variant={selectVariant}
            className={`misa-w-full ${!onQuickAdd ? className : ''}`}
            style={{ width: '100%', ...(onQuickAdd ? {} : style) }}
            popupMatchSelectWidth={false}
            dropdownStyle={{
                minWidth: `min(${dropdownWidth}px, calc(100vw - 16px))`,
                width: `min(${dropdownWidth}px, calc(100vw - 16px))`,
                maxWidth: 'calc(100vw - 16px)',
                padding: 0,
                borderRadius: 6,
                boxShadow: '0 10px 25px -5px rgba(0, 0, 0, 0.15), 0 8px 10px -6px rgba(0, 0, 0, 0.1)'
            }}
            // A modal/table cell may sit inside an overflow container. Portal
            // the popup and delegate viewport-aware flip/height to AdaptiveSelect.
            getPopupContainer={() => document.body}
            popupAlign={{ overflow: { adjustX: true, adjustY: true, shiftX: true } }}
            listHeight={256}
            filterOption={(input, option) => {
                if (!input) return true;
                return (option?.searchText || '').includes(input.toLowerCase().trim());
            }}
            onChange={(val, opt: any) => {
                if (onChange) {
                    onChange(val ?? '', opt?.data);
                }
            }}
            options={selectOptions}
            optionRender={(option: any) => {
                const item = option?.data?.data as ContactItem;
                if (!item) return option.label;

                if (entityType === 'employee') {
                    return (
                        <div className="misa-multicolumn-option-row misa-grid-employee">
                            <span className="misa-col-code" title={item.code}>{item.code}</span>
                            <span className="misa-col-name" title={item.name}>{item.name}</span>
                            <span className="misa-col-dept" title={item.department || item.address || ''}>{item.department || item.address || '-'}</span>
                            <span className="misa-col-phone" title={item.phone || ''}>{item.phone || '-'}</span>
                        </div>
                    );
                }

                if (entityType === 'bank') {
                    return (
                        <div className="misa-multicolumn-option-row misa-grid-bank">
                            <span className="misa-col-code" title={item.code}>{item.code}</span>
                            <span className="misa-col-name" title={item.name}>{item.name}</span>
                            <span className="misa-col-branch" title={item.branch || item.address || ''}>{item.branch || item.address || '-'}</span>
                        </div>
                    );
                }

                return (
                    <div className="misa-multicolumn-option-row misa-grid-contact">
                        <span className="misa-col-code" title={item.code}>{item.code}</span>
                        <span className="misa-col-name" title={item.name}>{item.name}</span>
                        <span className="misa-col-tax" title={item.tax_code || ''}>{item.tax_code || '-'}</span>
                        <span className="misa-col-addr" title={item.address || ''}>{item.address || '-'}</span>
                        <span className="misa-col-phone" title={item.phone || ''}>{item.phone || '-'}</span>
                    </div>
                );
            }}
            dropdownRender={(menu) => (
                <div className="misa-multicolumn-dropdown-container">
                    <div className="misa-multicolumn-dropdown-header">
                        {entityType === 'employee' ? (
                            <div className="misa-multicolumn-header-row misa-grid-employee">
                                <span className="misa-th-code">Mã NV</span>
                                <span className="misa-th-name">Tên nhân viên</span>
                                <span className="misa-th-dept">Phòng ban</span>
                                <span className="misa-th-phone">Điện thoại</span>
                            </div>
                        ) : entityType === 'bank' ? (
                            <div className="misa-multicolumn-header-row misa-grid-bank">
                                <span className="misa-th-code">Số tài khoản</span>
                                <span className="misa-th-name">Tên ngân hàng</span>
                                <span className="misa-th-branch">Chi nhánh</span>
                            </div>
                        ) : (
                            <div className="misa-multicolumn-header-row misa-grid-contact">
                                <span className="misa-th-code">Mã đối tượng</span>
                                <span className="misa-th-name">Tên đối tượng</span>
                                <span className="misa-th-tax">Mã số thuế</span>
                                <span className="misa-th-addr">Địa chỉ</span>
                                <span className="misa-th-phone">Điện thoại</span>
                            </div>
                        )}
                    </div>
                    <div className="misa-multicolumn-dropdown-body">
                        {menu}
                    </div>
                    {onQuickAdd && (
                        <div className="misa-multicolumn-dropdown-footer">
                            <button
                                type="button"
                                className="misa-btn-quick-add-link"
                                onMouseDown={(e) => e.preventDefault()}
                                onClick={() => onQuickAdd()}
                            >
                                <PlusOutlined style={{ marginRight: 6 }} />
                                <span>Thêm nhanh đối tượng (F9)</span>
                            </button>
                        </div>
                    )}
                </div>
            )}
        />
    );

    if (onQuickAdd) {
        return (
            <div className={`misa-input-group ${className}`} style={{ width: '100%', ...style }}>
                {selectElement}
                <button
                    type="button"
                    className="misa-plus-btn"
                    title="Thêm nhanh (F9)"
                    onClick={onQuickAdd}
                >
                    <PlusOutlined />
                </button>
            </div>
        );
    }

    return selectElement;
};

export default MultiColumnContactSelect;

