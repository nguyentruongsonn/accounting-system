type PurchaseInvoiceSearchRecord = {
    invoice_number?: unknown;
    supplier_name?: unknown;
    description?: unknown;
};

export function filterPurchaseInvoices<T extends PurchaseInvoiceSearchRecord>(
    invoices: readonly T[],
    searchText: string,
): T[] {
    const keyword = searchText.trim().toLowerCase();
    if (!keyword) return [...invoices];

    return invoices.filter((invoice) => [
        invoice.invoice_number,
        invoice.supplier_name,
        invoice.description,
    ].some((value) => String(value ?? '').toLowerCase().includes(keyword)));
}
