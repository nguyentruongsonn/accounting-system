import React from 'react';
import { PlusOutlined } from '@ant-design/icons';


interface MisaInputGroupProps {
    children: React.ReactNode;
    onPlusClick?: () => void;
    plusTitle?: string;
    style?: React.CSSProperties;
    className?: string;
}

export const MisaInputGroup: React.FC<MisaInputGroupProps> = ({
    children,
    onPlusClick,
    plusTitle = 'Thêm nhanh',
    style,
    className
}) => {
    return (
        <div className={`misa-input-group ${className || ''}`} style={style}>
            {children}
            {onPlusClick && (
                <button
                    type="button"
                    className="misa-plus-btn"
                    title={plusTitle}
                    onClick={onPlusClick}
                >
                    <PlusOutlined style={{ fontSize: 11 }} />
                </button>
            )}
        </div>
    );
};
