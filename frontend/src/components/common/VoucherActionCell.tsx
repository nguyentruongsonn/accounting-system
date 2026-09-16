import React from 'react';
import { Button, Dropdown } from 'antd';
import type { MenuProps } from 'antd';
import { DownOutlined } from '@ant-design/icons';

export interface VoucherActionCellProps {
    isDraft?: boolean;
    onPrimaryAction: () => void;
    primaryLabel?: string;
    primaryActionLabel?: string;
    primaryAriaLabel?: string;
    primaryIcon?: React.ReactNode;
    menuItems: MenuProps['items'];
    disabled?: boolean;
    className?: string;
}

export const VoucherActionCell: React.FC<VoucherActionCellProps> = ({
    isDraft = false,
    onPrimaryAction,
    primaryLabel,
    primaryActionLabel,
    primaryAriaLabel,
    primaryIcon,
    menuItems,
    disabled = false,
    className = '',
}) => {
    const label = primaryActionLabel ?? primaryLabel ?? (isDraft ? 'Sửa' : 'Xem');

    return (
        <div className={`misa-flex-center misa-gap-4 ${className}`.trim()}>
            <Button
                type="link"
                size="small"
                className="misa-btn-link-action"
                onClick={onPrimaryAction}
                disabled={disabled}
                aria-label={primaryAriaLabel}
                icon={primaryIcon}
            >
                {label}
            </Button>
            {menuItems && menuItems.length > 0 && (
                <Dropdown menu={{ items: menuItems }} trigger={['click']} disabled={disabled}>
                    <Button type="text" size="small" className="misa-btn-action-more" aria-label="Thao tác khác">
                        <DownOutlined className="misa-fs-10 misa-color-blue" />
                    </Button>
                </Dropdown>
            )}
        </div>
    );
};

export default VoucherActionCell;
