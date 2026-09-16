import React from 'react';


interface MisaFieldProps {
    label?: React.ReactNode;
    required?: boolean;
    span?: number; // 1 to 12 in 12-column grid
    children: React.ReactNode;
    style?: React.CSSProperties;
    className?: string;
}

export const MisaField: React.FC<MisaFieldProps> = ({
    label,
    required = false,
    span = 12,
    children,
    style,
    className
}) => {
    return (
        <div style={{ gridColumn: `span ${span}`, ...style }} className={className}>
            {label ? (
                <div className="misa-field-label">
                    {label} {required && <span style={{ color: '#ef4444' }}>*</span>}
                </div>
            ) : (
                <div className="misa-field-label">&nbsp;</div>
            )}
            {children}
        </div>
    );
};
