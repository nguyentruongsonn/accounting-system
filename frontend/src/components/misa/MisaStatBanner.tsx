import React from 'react';

export interface StatItem {
    label: string;
    value: number | string;
    color?: 'green' | 'orange' | 'blue' | 'purple';
    time?: string;
    isCurrency?: boolean;
}

export interface MisaStatBannerProps {
    stats: StatItem[];
    visible?: boolean;
}

export const MisaStatBanner: React.FC<MisaStatBannerProps> = ({ stats, visible = true }) => {
    if (!visible || !stats || stats.length === 0) return null;

    const getColorClass = (color?: string) => {
        switch (color) {
            case 'green': return 'misa-stat-value-green';
            case 'orange': return 'misa-stat-value-orange';
            case 'blue': return 'misa-color-blue';
            case 'purple': return 'misa-color-purple';
            default: return '';
        }
    };

    return (
        <div className="misa-stat-grid-3">
            {stats.map((item, idx) => (
                <div className="misa-stat-card" key={idx}>
                    <div className="misa-stat-label">{item.label}</div>
                    <div className={`misa-stat-value ${getColorClass(item.color)}`}>
                        {typeof item.value === 'number'
                            ? `${new Intl.NumberFormat('vi-VN').format(item.value)} ₫`
                            : item.value}
                    </div>
                    <div className="misa-stat-time">
                        {item.time ? `Cập nhật: ${item.time}` : ''}
                    </div>
                </div>
            ))}
        </div>
    );
};
