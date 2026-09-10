import React from 'react';
import { Switch, Select } from 'antd';
import Modal from '../layout/AppModal';
import { 
    DownOutlined, 
    SettingOutlined, 
    PlusOutlined
} from '@ant-design/icons';
import ModalFrame from '../layout/ModalFrame';
import { MisaButton } from './MisaButton';

export interface VoucherTypeOption {
    value: string;
    label: string;
}

export interface MisaVoucherModalProps {
    open: boolean;
    onCancel: () => void;
    title: string;
    voucherCode?: string;
    voucherTypes?: VoucherTypeOption[];
    selectedVoucherType?: string;
    onVoucherTypeChange?: (val: string) => void;
    onAddVoucherType?: () => void;
    onSave?: () => void;
    onSaveAndPrint?: () => void;
    isSaving?: boolean;
    showAccounts?: boolean;
    onShowAccountsChange?: (val: boolean) => void;
    extraHeaderActions?: React.ReactNode;
    children: React.ReactNode;
    width?: string | number;
}

export const MisaVoucherModal: React.FC<MisaVoucherModalProps> = ({
    open,
    onCancel,
    title,
    voucherCode,
    voucherTypes,
    selectedVoucherType,
    onVoucherTypeChange,
    onAddVoucherType,
    onSave,
    onSaveAndPrint,
    isSaving = false,
    showAccounts = true,
    onShowAccountsChange,
    extraHeaderActions,
    children,
    width = 'min(1440px, calc(100vw - 48px))'
}) => {
    return (
        <Modal
            title={
                <div className="misa-modal-header-wrapper">
                    <div className="misa-voucher-modal__header-main">
                        <span className="misa-voucher-header-title">
                            {title} {voucherCode ? `: ${voucherCode}` : ''}
                        </span>

                        {voucherTypes && voucherTypes.length > 0 && (
                            <div className="misa-input-group misa-voucher-modal__type-group">
                                <Select 
                                    variant="borderless" 
                                    className="misa-voucher-modal__type-select"
                                    value={selectedVoucherType}
                                    onChange={onVoucherTypeChange}
                                    options={voucherTypes}
                                />
                                {onAddVoucherType && (
                                    <button 
                                        type="button" 
                                        className="misa-plus-btn"
                                        title="Thêm loại chứng từ"
                                        onClick={onAddVoucherType}
                                    >
                                         <PlusOutlined />
                                    </button>
                                )}
                            </div>
                        )}
                    </div>

                    <div className="misa-voucher-modal__header-actions">
                        {extraHeaderActions}
                        <button type="button" className="misa-voucher-modal__settings-button" aria-label="Thiết lập"><SettingOutlined /></button>
                    </div>
                </div>
            }
            open={open}
            onCancel={onCancel}
            width={width}
            className="misa-voucher-modal"
            footer={
                <div className="misa-modal-footer">
                    <div className="misa-voucher-modal__footer-left">
                        {onShowAccountsChange && (
                            <div className="misa-voucher-modal__account-toggle">
                                <Switch size="small" checked={showAccounts} onChange={onShowAccountsChange} />
                                <span>Hiển thị tài khoản</span>
                            </div>
                        )}
                    </div>
                    <div className="misa-voucher-modal__footer-actions">
                        <MisaButton onClick={onCancel}>Hủy</MisaButton>
                        {onSave && (
                            <MisaButton onClick={onSave} loading={isSaving}>
                                Cất
                            </MisaButton>
                        )}
                        {onSaveAndPrint && (
                            <MisaButton
                                variant="primary"
                                onClick={onSaveAndPrint} 
                                loading={isSaving}
                            >
                                Cất và In <DownOutlined />
                            </MisaButton>
                        )}
                    </div>
                </div>
            }
        >
             <ModalFrame className="misa-voucher-modal__frame">{children}</ModalFrame>
        </Modal>
    );
};

export default MisaVoucherModal;
