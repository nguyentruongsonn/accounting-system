import React from 'react';
import { Button } from 'antd';
import type { ButtonProps } from 'antd';
import { DownOutlined } from '@ant-design/icons';


export interface MisaButtonProps extends Omit<ButtonProps, 'type' | 'variant'> {
    variant?: 'primary' | 'secondary' | 'danger' | 'tool' | 'tool-danger' | 'split-primary' | 'split-secondary';
    splitMenuText?: string;
    onSplitClick?: () => void;
}

export const MisaButton: React.FC<MisaButtonProps> = ({
    variant = 'secondary',
    children,
    splitMenuText: _splitMenuText,
    onSplitClick: _onSplitClick,
    style,
    className,
    ...rest
}) => {
    if (variant === 'primary') {
        return (
            <Button
                {...rest}
                className={`misa-btn-primary ${className || ''}`}
                style={style}
            >
                {children}
            </Button>
        );
    }

    if (variant === 'split-primary') {
        return (
            <Button
                {...rest}
                className={`misa-btn-primary ${className || ''}`}
                style={{
                    display: 'inline-flex',
                    alignItems: 'center',
                    gap: 6,
                    ...style
                }}
            >
                <span>{children}</span>
                <DownOutlined style={{ fontSize: 10, marginLeft: 2 }} />
            </Button>
        );
    }

    if (variant === 'tool') {
        return (
            <button
                type="button"
                className={`misa-btn-tool ${className || ''}`}
                style={style as any}
                onClick={rest.onClick as any}
                disabled={rest.disabled}
            >
                {children}
            </button>
        );
    }

    if (variant === 'tool-danger') {
        return (
            <button
                type="button"
                className={`misa-btn-tool misa-btn-tool-danger ${className || ''}`}
                style={style as any}
                onClick={rest.onClick as any}
                disabled={rest.disabled}
            >
                {children}
            </button>
        );
    }

    return (
        <Button
            {...rest}
            style={{
                height: 32,
                padding: '0 16px',
                fontSize: 12,
                fontWeight: 500,
                borderRadius: 4,
                ...style
            }}
            className={`misa-btn-secondary ${className || ''}`}
        >
            {children}
        </Button>
    );
};
