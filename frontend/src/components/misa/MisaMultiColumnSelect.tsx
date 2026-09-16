import React from 'react';
import { Button } from 'antd';
import { PlusOutlined } from '@ant-design/icons';
import { AdaptiveSelect } from '../layout/AdaptiveSelect';

export interface ColumnDefinition<T> {
    key: keyof T | string;
    title: string;
    width: number | string;
    align?: 'left' | 'center' | 'right';
    render?: (value: any, record: T) => React.ReactNode;
}

export interface MisaMultiColumnSelectProps<T> {
    data: T[];
    columns: ColumnDefinition<T>[];
    value?: any;
    onChange?: (value: any, record?: T) => void;
    placeholder?: string;
    valueKey?: keyof T | string;
    labelKey?: keyof T | string;
    dropdownWidth?: number;
    quickAddLabel?: string;
    onQuickAdd?: () => void;
    disabled?: boolean;
    allowClear?: boolean;
    style?: React.CSSProperties;
    className?: string;
}

export function MisaMultiColumnSelect<T extends Record<string, any>>({
    data = [],
    columns,
    value,
    onChange,
    placeholder = 'Chọn...',
    valueKey = 'id',
    labelKey = 'code',
    dropdownWidth = 600,
    quickAddLabel,
    onQuickAdd,
    disabled = false,
    allowClear = true,
    style,
    className = ''
}: MisaMultiColumnSelectProps<T>) {
    const gridColumnsTemplate = columns.map(c => typeof c.width === 'number' ? `${c.width}px` : c.width).join(' ');

    const options = data.map(item => ({
        value: item[valueKey],
        label: `${item[labelKey]} - ${item.name || ''}`,
        data: item
    }));

    return (
        <AdaptiveSelect
            showSearch
            value={value}
            placeholder={placeholder}
            disabled={disabled}
            allowClear={allowClear}
            className={className}
            style={{ width: '100%', ...style }}
            optionLabelProp="value"
            popupMatchSelectWidth={false}
            dropdownStyle={{
                minWidth: `min(${dropdownWidth}px, calc(100vw - 16px))`,
                width: `min(${dropdownWidth}px, calc(100vw - 16px))`,
                maxWidth: 'calc(100vw - 16px)',
            }}
            // Render outside overflow-hidden form/grid containers and let the
            // adaptive boundary choose top/bottom placement at runtime.
            getPopupContainer={() => document.body}
            popupAlign={{ overflow: { adjustX: true, adjustY: true, shiftX: true } }}
            listHeight={256}
            filterOption={(input, option) => {
                if (!option?.data) return false;
                const searchStr = Object.values(option.data).join(' ').toLowerCase();
                return searchStr.includes(input.toLowerCase());
            }}
            onChange={(val, opt: any) => {
                if (onChange) {
                    onChange(val, opt?.data);
                }
            }}
            options={options}
            optionRender={(option: any) => {
                const item = option?.data;
                if (!item) return null;
                return (
                    <div style={{ display: 'grid', gridTemplateColumns: gridColumnsTemplate, gap: 8, fontSize: 13, padding: '2px 0' }}>
                        {columns.map(col => {
                            const val = item[col.key];
                            return (
                                <div 
                                    key={String(col.key)} 
                                    style={{ 
                                        textAlign: col.align || 'left',
                                        overflow: 'hidden',
                                        textOverflow: 'ellipsis',
                                        whiteSpace: 'nowrap'
                                    }}
                                >
                                    {col.render ? col.render(val, item) : `${val ?? ''}`}
                                </div>
                            );
                        })}
                    </div>
                );
            }}
            dropdownRender={menu => (
                <div>
                    <div className="misa-grid-dropdown-header" style={{ display: 'grid', gridTemplateColumns: gridColumnsTemplate, gap: 8 }}>
                        {columns.map(col => (
                            <span key={String(col.key)} style={{ textAlign: col.align || 'left' }}>
                                {col.title}
                            </span>
                        ))}
                    </div>
                    {menu}
                    {quickAddLabel && onQuickAdd && (
                        <div className="misa-grid-dropdown-footer">
                            <Button 
                                type="link" 
                                size="small" 
                                icon={<PlusOutlined />} 
                                onClick={onQuickAdd}
                                className="misa-btn-link-quickadd"
                                style={{ padding: 0, fontSize: 12, fontWeight: 600 }}
                            >
                                {quickAddLabel}
                            </Button>
                        </div>
                    )}
                </div>
            )}
        />
    );
}

export default MisaMultiColumnSelect;
