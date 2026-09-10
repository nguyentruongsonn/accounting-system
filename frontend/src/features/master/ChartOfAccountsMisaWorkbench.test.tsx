import { describe, expect, it } from 'vitest';
// @ts-expect-error Node filesystem access is intentional for static CSS evidence.
import { readFileSync } from 'node:fs';
import source from './ChartOfAccounts.tsx?raw';
import styles from './ChartOfAccounts.css?raw';

const chartOfAccountsStyles = readFileSync('src/features/master/ChartOfAccounts.css', 'utf8');

describe('MISA-style chart of accounts workbench', () => {
    it('provides the compact tree-grid toolbar and server-backed search', () => {
        expect(source).toContain("queryKey: ['accounts', deferredSearch, includeInactive]");
        expect(source).toContain("include_inactive: includeInactive ? 1 : 0");
        expect(source).toContain('Tìm kiếm theo số, tên tài khoản');
        expect(source).toContain('Mở rộng');
        expect(source).toContain('Thu gọn');
        expect(source).toContain('Làm mới');
        expect(source).toContain('Xuất Excel');
        expect(source).toContain('Thiết lập cột');
        expect(source).toContain('Chuyển tài khoản hạch toán');
    });

    it('shows MISA account columns and lets the backend derive hierarchy metadata', () => {
        expect(source).toContain("title: 'Số tài khoản'");
        expect(source).toContain("title: 'Diễn giải'");
        expect(source).toContain("title: 'Chức năng'");
        expect(source).toContain('name="description"');
        expect(source).not.toContain('name="level"');
        expect(source).not.toContain('name="is_parent"');
        expect(source).not.toContain("title: 'Tên tiếng Anh'");
        expect(source).not.toContain('name="name_en"');
    });

    it('exposes the same row actions as the MISA account catalogue', () => {
        expect(source).toContain("label: 'Nhân bản'");
        expect(source).toContain("label: 'Xóa'");
        expect(source).toContain("label: record.is_active ? 'Ngừng sử dụng' : 'Kích hoạt'");
        expect(source).toContain("title: 'Xóa tài khoản?'");
        expect(source).toContain("title=\"Chuyển tài khoản hạch toán\"");
    });

    it('keeps title actions grouped with the shared workbench surface', () => {
        expect(source).toContain('coa-workbench__title-actions');
        expect(source).not.toContain('coa-workbench__page-heading');
        expect(source).not.toContain('coa-workbench__title-row');
        expect(source).toContain('coa-workbench__table-surface');
        expect(source).toContain("import './ChartOfAccounts.css'");
    });

    it('keeps the add split button at the far right of the toolbar', () => {
        const titleActionsStart = source.indexOf('<div className="coa-workbench__title-actions">');
        const titleActionsEnd = source.indexOf('</div>', titleActionsStart);
        expect(source.slice(titleActionsStart, titleActionsEnd)).not.toContain('coa-workbench__add-group');
        expect(source.indexOf('coa-workbench__add-group')).toBeGreaterThan(source.indexOf('aria-label="Thiết lập cột"'));
        expect(chartOfAccountsStyles).toContain('.coa-workbench__add-group { display: inline-flex; align-items: stretch; margin-left: auto;');
        expect(chartOfAccountsStyles).toContain('.coa-workbench__header .ui-page-toolbar__leading { display: flex !important; flex: 1 1 100%; width: 100%;');
        expect(chartOfAccountsStyles).toContain('.coa-workbench__toolbar { display: flex; align-items: center; width: 100%;');
    });

    it('allows the toolbar to wrap when browser zoom narrows the effective viewport', () => {
        expect(chartOfAccountsStyles).toContain('.coa-workbench__toolbar { flex-wrap: wrap; }');
        expect(chartOfAccountsStyles).not.toContain('.coa-workbench__toolbar { flex-wrap: nowrap; }');
    });

    it('renders exactly one explicit tree toggle per parent row', () => {
        expect(source).toContain('hasChildren');
        expect(source).toContain('dataSource={tableRows}');
        expect(source).toContain('coa-workbench__code-cell');
        expect(source).not.toContain('record.children?.length ? <button type="button" className="coa-workbench__tree-toggle"');
    });

    it('inherits page and toolbar spacing from the shared admin shell', () => {
        expect(source).not.toContain('coa-workbench__page-heading');
        expect(styles).not.toContain('.coa-workbench .ui-page-body');
        expect(styles).not.toContain('.coa-workbench .ui-page-toolbar-slot');
    });

    it('uses current Ant Design APIs without invalid multi-child named fields', () => {
        expect(source).toContain("placement: ['bottomEnd']");
        expect(source).not.toContain("position: ['bottomRight']");
        expect(source).toContain('coa-account-modal__switch-label');
    });
});
