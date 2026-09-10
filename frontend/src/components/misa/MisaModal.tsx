import React from 'react';
import { Switch, Select } from 'antd';
import Modal from '../layout/AppModal';
import { 
    ReloadOutlined, 
    SettingOutlined, 
    CalculatorOutlined,
    PlusOutlined
} from '@ant-design/icons';
import { MisaButton } from './MisaButton';
import ModalFrame from '../layout/ModalFrame';


export interface MisaModalProps {
    open: boolean;
    onCancel: () => void;
    title: string;
    voucherCode?: string;
    voucherTypes?: { value: string; label: string }[];
    selectedVoucherType?: string;
    onVoucherTypeChange?: (val: string) => void;
    onAddVoucherType?: () => void;
    onReload?: () => void;
    onSave?: () => void;
    onSaveAndAdd?: () => void;
    saveAndAddText?: string;
    isSaving?: boolean;
    showAccounts?: boolean;
    onShowAccountsChange?: (val: boolean) => void;
    children: React.ReactNode;
    width?: string | number;
}

export const MisaModal: React.FC<MisaModalProps> = ({
    open,
    onCancel,
    title,
    voucherCode,
    voucherTypes,
    selectedVoucherType,
    onVoucherTypeChange,
    onAddVoucherType,
    onReload,
    onSave,
    onSaveAndAdd,
    saveAndAddText = 'Cất và Thêm',
    isSaving = false,
    showAccounts = true,
    onShowAccountsChange,
    children,
    width = 'min(1440px, calc(100vw - 48px))'
}) => {
    return (
        <Modal
            className="misa-modal"
            title={
                <div className="misa-modal-header-wrapper">
                    <div className="misa-modal__title-main">
                        <div 
                            className="misa-modal__reload"
                            onClick={onReload}
                            title="Nạp lại"
                        >
                            <ReloadOutlined />
                        </div>
                        <span className="misa-voucher-header-title">
                            {title} {voucherCode}
                        </span>

                        {voucherTypes && voucherTypes.length > 0 && (
                            <div className="misa-input-group misa-modal__voucher-type">
                                <Select 
                                    variant="borderless" 
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
                                        <PlusOutlined style={{ fontSize: 11 }} />
                                    </button>
                                )}
                            </div>
                        )}
                    </div>

                    <div className="misa-modal__title-actions">
                        <button type="button" className="misa-modal__icon-button"><CalculatorOutlined /></button>
                        <button type="button" className="misa-modal__icon-button"><SettingOutlined /></button>
                    </div>
                </div>
            }
            open={open}
            onCancel={onCancel}
            width={width}
            footer={
                    <div className="misa-modal-footer">
                        <div className="misa-modal__footer-left">
                            {onShowAccountsChange && (
                                <div className="misa-modal__account-toggle">
                                    <Switch size="small" checked={showAccounts} onChange={onShowAccountsChange} />
                                    <span>Hiển thị tài khoản</span>
                                </div>
                            )}
                        </div>
                        <div className="misa-modal__footer-actions">
                            <MisaButton onClick={onCancel}>Hủy</MisaButton>
                            {onSave && (
                                <MisaButton onClick={onSave} loading={isSaving}>Cất</MisaButton>
                            )}
                            {onSaveAndAdd && (
                                <MisaButton variant="split-primary" onClick={onSaveAndAdd} loading={isSaving}>
                                    {saveAndAddText}
                                </MisaButton>
                            )}
                        </div>
                    </div>
            }
        >
            <ModalFrame>{children}</ModalFrame>
        </Modal>
    );
};
