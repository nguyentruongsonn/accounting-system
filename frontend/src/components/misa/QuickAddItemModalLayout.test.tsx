// @ts-expect-error Node filesystem access is intentional for static CSS evidence.
import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';
import source from './QuickAddItemModal.tsx?raw';

const appleShell = readFileSync('src/styles/apple-shell.css', 'utf8');
const misaTheme = readFileSync('src/styles/misa-theme.css', 'utf8');

describe('QuickAddItemModal UI layout fixes', () => {
    it('configures modal width to 1200px activating app-modal voucher viewport sizing', () => {
        expect(source).toContain('width={1200}');
        expect(source).not.toContain('width={1020}');
    });

    it('uses flexible layout for warranty period and unit to avoid row overflow', () => {
        expect(source).toContain('style={{ flex: 1, minWidth: 0 }}');
        expect(source).toContain('style={{ width: 110, flexShrink: 0 }}');
    });

    it('ensures .misa-btn-tool dynamically sizes for text and accommodates "Thêm dòng"', () => {
        expect(appleShell).toMatch(/\.misa-btn-tool\s*\{[^}]*min-width:\s*36px/);
        expect(appleShell).toMatch(/\.misa-btn-tool\s*\{[^}]*width:\s*auto/);
        expect(appleShell).toMatch(/\.misa-btn-tool\s*\{[^}]*white-space:\s*nowrap/);
    });

    it('eliminates layout vibration by transitioning only color/shadow instead of all/dimensions', () => {
        expect(misaTheme).toMatch(/transition:\s*border-color\s*0\.2s\s*ease,\s*box-shadow\s*0\.2s\s*ease,\s*background-color\s*0\.2s\s*ease\s*!important/);
        expect(misaTheme).not.toContain('max-height: 34px !important');
    });

    it('provides specialized .misa-table-action-footer button styling for table row additions', () => {
        expect(misaTheme).toContain('.misa-table-action-footer .misa-btn-tool');
        expect(misaTheme).toContain('.misa-table-action-footer-gap10 .misa-btn-tool');
    });
});
