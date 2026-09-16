import React from 'react';
import { Badge } from 'antd';
import { SettingOutlined, FullscreenOutlined, FullscreenExitOutlined } from '@ant-design/icons';

export interface GridTabItem {
    key: string;
    label: string;
    badge?: number;
    hidden?: boolean;
}

export interface MisaGridToolbarProps {
    tabs: GridTabItem[];
    activeTab: string;
    onTabChange: (key: string) => void;
    extraRight?: React.ReactNode;
    onToggleFullscreen?: () => void;
    isFullscreen?: boolean;
    onOpenSettings?: () => void;
    className?: string;
}

export const MisaGridToolbar: React.FC<MisaGridToolbarProps> = ({
    tabs,
    activeTab,
    onTabChange,
    extraRight,
    onToggleFullscreen,
    isFullscreen = false,
    onOpenSettings,
    className = ''
}) => {
    const visibleTabs = (Array.isArray(tabs) ? tabs : []).filter(t => !t?.hidden);

    return (
        <div className={`misa-grid-toolbar misa-flex-between ${className}`}>
            <div className="misa-grid-tabs">
                {visibleTabs.map(tab => {
                    const isActive = activeTab === tab.key;
                    return (
                        <button
                            key={tab.key}
                            type="button"
                            className={`misa-grid-tab-btn ${isActive ? 'active' : ''}`}
                            onClick={() => onTabChange(tab.key)}
                        >
                            <span>{tab.label}</span>
                            {typeof tab.badge === 'number' && tab.badge > 0 && (
                                <Badge count={tab.badge} className="misa-ml-4" size="small" />
                            )}
                        </button>
                    );
                })}
            </div>

            <div className="misa-grid-tools">
                {extraRight}
                {onOpenSettings && (
                    <button
                        type="button"
                        className="misa-btn-tool"
                        title="Tùy chỉnh cột"
                        onClick={onOpenSettings}
                    >
                        <SettingOutlined />
                    </button>
                )}
                {onToggleFullscreen && (
                    <button
                        type="button"
                        className="misa-btn-tool"
                        title={isFullscreen ? 'Thu nhỏ bảng' : 'Phóng to toàn màn hình'}
                        onClick={onToggleFullscreen}
                    >
                        {isFullscreen ? <FullscreenExitOutlined /> : <FullscreenOutlined />}
                    </button>
                )}
            </div>
        </div>
    );
};

export default MisaGridToolbar;
