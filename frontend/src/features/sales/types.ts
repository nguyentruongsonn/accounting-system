export type SalesPaymentMethod = 'reduce_receivable' | 'cash' | 'bank';

export interface CustomerOption {
    id: number;
    code?: string;
    name: string;
    address?: string;
    tax_code?: string;
    contact_name?: string;
    phone?: string;
}

export interface EmployeeOption {
    id: number;
    code?: string;
    name: string;
    department?: string;
    phone?: string;
}

export interface InventoryItemOption {
    id: number;
    code: string;
    name: string;
    unit?: string;
    sale_price?: number;
    cost_price?: number;
    stock_quantity?: number;
    tax_rate?: number;
    inventory_account?: string;
    cogs_account?: string;
    revenue_account?: string;
    [key: string]: any;
}

export interface WarehouseOption {
    id: number;
    code: string;
    name: string;
    account?: string;
}

export interface SalesReturnLine {
    id?: number;
    key?: string;
    item_id?: number;
    item_code?: string;
    item_name?: string;
    description?: string;
    unit?: string;
    quantity?: number;
    unit_price?: number;
    amount?: number;
    debit_account?: string;
    credit_account?: string;
    tax_rate?: number;
    tax_amount?: number;
    tax_account?: string;
    invoice_number?: string;
    invoice_date?: string;
    warehouse_id?: number;
    warehouse_code?: string;
    cogs_debit_account?: string;
    cogs_credit_account?: string;
    cogs_unit_price?: number;
    cogs_amount?: number;
    order_reference?: string;
    contract_reference?: string;
    expense_item_code?: string;
}

export interface SalesReturnRecord {
    id: number;
    voucher_number: string;
    voucher_date: string;
    accounting_date: string;
    customer_id?: number;
    customer_name: string;
    customer_address?: string;
    tax_code?: string;
    receiver_name?: string;
    reason?: string;
    description?: string;
    employee_id?: number;
    payment_method: SalesPaymentMethod;
    bank_account_id?: number | null;
    is_inward: boolean;
    reference_invoice_id?: number | null;
    sub_total?: number;
    tax_amount?: number;
    total_amount: number;
    is_posted: boolean;
    lines?: SalesReturnLine[];
    customer?: CustomerOption;
    employee?: EmployeeOption;
    created_at?: string;
    updated_at?: string;
}

export interface SalesDiscountLine {
    id?: number;
    key?: string;
    item_id?: number;
    item_code?: string;
    item_name?: string;
    description?: string;
    unit?: string;
    quantity?: number;
    unit_price?: number;
    amount?: number;
    debit_account?: string;
    credit_account?: string;
    tax_rate?: number;
    tax_amount?: number;
    tax_account?: string;
    invoice_number?: string;
    invoice_date?: string;
    order_reference?: string;
    contract_reference?: string;
    expense_item_code?: string;
}

export interface SalesDiscountRecord {
    id: number;
    voucher_number: string;
    voucher_date: string;
    accounting_date: string;
    customer_id?: number;
    customer_name: string;
    customer_address?: string;
    tax_code?: string;
    receiver_name?: string;
    reason?: string;
    description?: string;
    employee_id?: number;
    payment_method: SalesPaymentMethod;
    bank_account_id?: number | null;
    reference_invoice_id?: number | null;
    sub_total?: number;
    tax_amount?: number;
    total_amount: number;
    is_posted: boolean;
    lines?: SalesDiscountLine[];
    customer?: CustomerOption;
    employee?: EmployeeOption;
    created_at?: string;
    updated_at?: string;
}
