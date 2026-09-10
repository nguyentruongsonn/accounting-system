import React from 'react';
import { Input, Select, Dropdown } from 'antd';
import { 
    SearchOutlined, 
    ReloadOutlined, 
    PrinterOutlined, 
    ExportOutlined, 
    PlusOutlined, 
    DownOutlined 
} from '@ant-design/icons';
import type { MenuProps } from 'antd';

export interface MisaToolbarProps {
    searchText?: string;
    onSearchChange?: (val: string) => void;
    searchPlaceholder?: string;
    datePreset?: string;
    onDatePresetChange?: (val: string) => void;
    onRefresh?: () => void;
    onPrint?: () => void;
    onExport?: () => void;
    addButtonText?: string;
    onAddClick?: () => void;
    addMenuItems?: MenuProps['items']; extraLeft?: React.ReactNode;
    extraRight?: React.ReactNode;
}

export const MisaToolbar: React.FC<MisaToolbarProps> = ({
    searchText = '',
    onSearchChange,
    searchPlaceholder = 'Tìm kiếm số chứng từ, đối tượng...',
    datePreset = 'Tháng này',
    onDatePresetChange,
    onRefresh,
    onPrint,
    onExport,
    addButtonText = 'Thêm',
    onAddClick,
    addMenuItems, extraLeft,
    extraRight
}) => {
    return (
        <div className="misa-toolbar">
            {/* Left Filter Group */}
            <div className="misa-toolbar-filter-group">
                {onSearchChange && (
                    <div style={{ width: 280 }}>
                        <Input 
                            placeholder={searchPlaceholder} 
                            prefix={<SearchOutlined style={{ color: 'var(--apple-muted-text)' }} />}
                            className="misa-input"
                            allowClear
                            value={searchText}
                            onChange={e => onSearchChange(e.target.value)}
                        />
                    </div>
                )}

                {extraLeft} {onDatePresetChange && (
                    <Select 
                        value={datePreset} 
                        onChange={onDatePresetChange} 
                        style={{ width: 140 }} 
                        className="misa-input"
                        options={[
                            { value: 'Hôm nay', label: 'Hôm nay' },
                            { value: 'Tuần này', label: 'Tuần này' },
                            { value: 'Tháng này', label: 'Tháng này' },
                            { value: 'Quý này', label: 'Quý này' },
                            { value: 'Năm nay', label: 'Năm nay' },
                        ]}
                    />
                )}
            </div>

            {/* Right Action Tools Group */}
            <div className="misa-toolbar-actions">
                {onRefresh && (
                    <button type="button" className="misa-btn-tool" title="Làm mới (F5)" onClick={onRefresh}>
                        <ReloadOutlined />
                    </button>
                )}
                {onPrint && (
                    <button type="button" className="misa-btn-tool" title="In" onClick={onPrint}>
                        <PrinterOutlined />
                    </button>
                )}
                {onExport && (
                    <button type="button" className="misa-btn-tool" title="Xuất Excel" onClick={onExport}>
                        <ExportOutlined />
                    </button>
                )}

                {extraRight}

                {/* Unified Single Clean MISA Add Button */}
                {onAddClick && (
                    <div className="misa-toolbar-split-btn">
                        <button
                            type="button"
                            onClick={onAddClick}
                            className="misa-btn-primary-add"
                        >
                            <PlusOutlined style={{ fontSize: 11 }} /> {addButtonText}
                        </button>

                        {addMenuItems && addMenuItems.length > 0 && (
                            <Dropdown menu={{ items: addMenuItems }} placement="bottomRight" trigger={['click']}>
                                <button
                                    type="button"
                                    className="misa-btn-primary-add-menu"
                                >
                                    <DownOutlined />
                                </button>
                            </Dropdown>
                        )}
                    </div>
                )}
            </div>
        </div>
    );
};

export default MisaToolbar;
