export type PurchasePaymentMethod = 'reduce_payable' | 'cash' | 'bank';

export interface SupplierOption {
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
    tax_rate?: number;
    stock_quantity?: number;
    inventory_account?: string;
    cogs_account?: string;
    [key: string]: any;
}

export interface WarehouseOption {
    id: number;
    code: string;
    name: string;
    default_account?: string;
}

export interface PurchaseReturnLine {
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
    discount_rate?: number;
    discount_amount?: number;
    debit_account?: string;
    credit_account?: string;
    tax_rate?: number;
    tax_amount?: number;
    tax_account?: string;
    invoice_number?: string;
    invoice_date?: string;
    warehouse_id?: number;
    warehouse_code?: string;
    order_id?: number;
    contract_id?: number;
}

export interface PurchaseReturnRecord {
    id: number;
    voucher_number: string;
    voucher_date: string;
    accounting_date: string;
    supplier_id?: number;
    supplier_code?: string;
    supplier_name: string;
    supplier_address?: string;
    tax_code?: string;
    deliverer_name?: string;
    receiver_name?: string;
    reason?: string;
    description?: string;
    employee_id?: number;
    payment_method: PurchasePaymentMethod;
    bank_account_id?: number | null;
    is_outward?: boolean;
    is_export_slip?: boolean;
    reference_invoice_id?: number | null;
    sub_total?: number;
    discount_amount?: number;
    tax_amount?: number;
    vat_amount?: number;
    total_amount: number;
    grand_total?: number;
    is_posted: boolean;
    status?: string;
    lines?: PurchaseReturnLine[];
    supplier?: SupplierOption;
    employee?: EmployeeOption;
    created_at?: string;
    updated_at?: string;
}

export interface PurchaseDiscountLine {
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
    discount_amount?: number;
    debit_account?: string;
    credit_account?: string;
    tax_rate?: number;
    tax_amount?: number;
    tax_account?: string;
    invoice_number?: string;
    invoice_date?: string;
    order_id?: number;
    contract_id?: number;
}

export interface PurchaseDiscountRecord {
    id: number;
    voucher_number: string;
    voucher_date: string;
    accounting_date: string;
    supplier_id?: number;
    supplier_code?: string;
    supplier_name: string;
    supplier_address?: string;
    tax_code?: string;
    deliverer_name?: string;
    receiver_name?: string;
    reason?: string;
    description?: string;
    employee_id?: number;
    payment_method: PurchasePaymentMethod;
    bank_account_id?: number | null;
    reference_invoice_id?: number | null;
    sub_total?: number;
    tax_amount?: number;
    vat_amount?: number;
    total_amount: number;
    grand_total?: number;
    is_posted: boolean;
    status?: string;
    lines?: PurchaseDiscountLine[];
    supplier?: SupplierOption;
    employee?: EmployeeOption;
    created_at?: string;
    updated_at?: string;
}
