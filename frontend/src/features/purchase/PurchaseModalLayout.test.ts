import { describe, expect, it } from 'vitest';
// @ts-expect-error Node filesystem access is intentional for static CSS evidence.
import { readFileSync } from 'node:fs';
import contractSource from './PurchaseContracts.tsx?raw';
import voucherSource from './modals/PurchaseVoucherDetailModal.tsx?raw';

const themeSource = readFileSync('src/styles/misa-theme.css', 'utf8');

describe('purchase modal layout contracts', () => {
    it('places the purchase contract payment total in the canonical footer grid', () => {
        const footerIndex = contractSource.indexOf('misa-contract-footer-grid');
        const paymentTotalIndex = contractSource.indexOf('TỔNG THANH TOÁN:');

        expect(footerIndex).toBeGreaterThanOrEqual(0);
        expect(contractSource).toContain('className="misa-col-4 misa-summary-financial-card"');
        expect(footerIndex).toBeLessThan(paymentTotalIndex);
        expect(contractSource).not.toContain('className="misa-flex-end misa-top-12"');
    });

    it('keeps purchase voucher attachment as a centered footer column', () => {
        const attachmentIndex = voucherSource.indexOf('misa-footer-attachment');
        const summaryIndex = voucherSource.indexOf('misa-footer-right');

        expect(attachmentIndex).toBeGreaterThanOrEqual(0);
        expect(voucherSource).toContain('className="misa-footer-layout"');
        expect(attachmentIndex).toBeLessThan(summaryIndex);
        expect(voucherSource).not.toContain('misa-footer-left" style={{ flex: 1, maxWidth: 520 }}');
    });

    it('uses one scrollable table shell for contract goods and payment schedule tabs', () => {
        const tableShells = contractSource.match(/misa-table-container misa-contract-table-scroll/g) ?? [];

        expect(tableShells).toHaveLength(2);
        expect(contractSource).toContain('misa-grid-footer-bar misa-contract-schedule-actions');
        expect(contractSource).toContain('Tổng số: <strong>{fields.length}</strong> dòng');
    });

    it('uses one light border token for editable table fields', () => {
        const inputNumberStyleStart = themeSource.indexOf('/* Standard outlined style for table input number */');
        const inputNumberStyleEnd = themeSource.indexOf('/* Standard outlined date picker style');
        const inputNumberStyle = themeSource.slice(inputNumberStyleStart, inputNumberStyleEnd);

        expect(inputNumberStyle).toContain('border: 1px solid #d9d9d9 !important;');
        expect(inputNumberStyle).not.toContain('#8c9ba5');
    });

    it('keeps contract table columns readable instead of shrinking away their data', () => {
        expect(themeSource).toContain('.misa-col-min-w-220 { min-width: 220px; }');
        expect(themeSource).toContain('.misa-col-w-65 { width: 65px; min-width: 65px; }');
        expect(themeSource).toContain('.misa-col-w-80 { width: 80px; min-width: 80px; }');
        expect(themeSource).toContain('.misa-col-w-95 { width: 95px; min-width: 95px; }');
    });

    it('gives payment schedule date columns enough room for the date and calendar control', () => {
        expect(contractSource).toContain('className="misa-col-w-150 misa-text-center">Hạn thanh toán</th>');
        expect(contractSource).toContain('className="misa-col-w-150 misa-text-center">Ngày thanh toán</th>');
    });

    it('keeps the calendar suffix inside payment schedule date fields', () => {
        expect(themeSource).toContain('.misa-voucher-table td .ant-picker .ant-picker-input,');
        expect(themeSource).toContain('flex: 1 1 auto !important;');
        expect(themeSource).toContain('min-width: 0 !important;');
        expect(themeSource).toContain('.misa-voucher-table td .ant-picker .ant-picker-input > input,');
        expect(themeSource).toContain('max-width: none !important;');
    });
});
