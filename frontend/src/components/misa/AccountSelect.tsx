import React, { useMemo } from 'react';
import { AdaptiveSelect } from '../layout/AdaptiveSelect';

export interface AccountItem {
    id?: number | string;
    code: string;
    name: string;
    account_type?: string;
    nature?: string;
    is_parent?: boolean;
    [key: string]: any;
}

export interface AccountSelectProps {
    id?: string;
    accounts?: AccountItem[];
    value?: string;
    onChange?: (val: string, record?: AccountItem) => void;
    onOpenChange?: (open: boolean) => void;
    loading?: boolean;
    placeholder?: string;
    disabled?: boolean;
    allowClear?: boolean;
    style?: React.CSSProperties;
    className?: string;
    variant?: 'borderless' | 'outlined' | 'filled';
    dropdownWidth?: number;
}

export const AccountSelect: React.FC<AccountSelectProps> = ({
    id,
    accounts = [],
    value,
    onChange,
    onOpenChange,
    loading = false,
    placeholder = 'Chọn TK',
    disabled = false,
    allowClear = false,
    style,
    className = '',
    variant: _variant = 'outlined',
    dropdownWidth = 460,
}) => {
    const dataList = useMemo(() => {
        // Account mappings are company- and policy-specific. Never substitute a
        // client-side chart when the server has not supplied the tenant's COA.
        const source = Array.isArray(accounts) ? accounts.filter(Boolean) : [];
        const leafAccounts = source.filter(a => !a.is_parent);
        return leafAccounts.length > 0 ? leafAccounts : source;
    }, [accounts]);

    const options = useMemo(() => {
        return dataList.map(acc => ({
            value: acc.code || (acc as any).account_code || String(acc.id),
            label: acc.code || (acc as any).account_code,
            searchText: `${acc.code || ''} ${acc.name || ''}`.toLowerCase(),
            data: acc,
        }));
    }, [dataList]);

    return (
        <AdaptiveSelect
            id={id}
            showSearch
            value={value || undefined}
            placeholder={placeholder}
            disabled={disabled}
            loading={loading}
            allowClear={allowClear}
            variant="outlined"
            className={`misa-account-select misa-w-full ${className}`}
            style={{ width: '100%', minWidth: '100%', ...style }}
            popupMatchSelectWidth={false}
            listHeight={240}
            popupAlign={{ overflow: { adjustX: true, adjustY: true, shiftX: true } }}
            styles={{ popup: { root: { minWidth: `min(${dropdownWidth}px, calc(100vw - 16px))`, width: `min(${dropdownWidth}px, calc(100vw - 16px))`, padding: 0 } } }}
            filterOption={(input, option) => {
                if (!input) return true;
                return (option?.searchText || '').includes(input.toLowerCase().trim());
            }}
            onChange={(val, opt: any) => {
                if (onChange) {
                    onChange(val, opt?.data);
                }
            }}
            onOpenChange={onOpenChange}
            options={options}
            notFoundContent={loading ? 'Đang tải danh sách tài khoản…' : accounts.length > 0 ? 'Không có tài khoản phù hợp' : 'Chưa có tài khoản từ máy chủ'}
            optionRender={(option: any) => {
                const acc = option?.data?.data;
                if (!acc) return option.label;
                return (
                    <div className="misa-account-option-row">
                        <span className="misa-account-option-code">{acc.code || (acc as any).account_code}</span>
                        <span className="misa-account-option-name" title={acc.name}>{acc.name}</span>
                    </div>
                );
            }}
            popupRender={(menu) => (
                <div className="misa-account-dropdown-container">
                    <div className="misa-account-dropdown-header">
                        <span className="misa-account-header-col misa-account-col-code">Số tài khoản</span>
                        <span className="misa-account-header-col misa-account-col-name">Tên tài khoản</span>
                    </div>
                    <div className="misa-account-dropdown-body">
                        {menu}
                    </div>
                </div>
            )}
        />
    );
};

export default AccountSelect;
