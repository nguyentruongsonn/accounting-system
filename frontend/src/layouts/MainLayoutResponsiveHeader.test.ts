import { describe, expect, it } from 'vitest';
import source from './MainLayout.tsx?raw';

describe('MainLayout responsive header contract', () => {
    it('keeps header text from inheriting Ant header line-height and overlapping workspace tabs on narrow viewports', () => {
        expect(source).toMatch(/<Header\s+[\s\S]*?lineHeight:\s*['"]normal['"]/);
        expect(source).toMatch(/className="[^"]*app-user-menu-trigger[^"]*"/);
        expect(source).toMatch(/app-user-menu-trigger[\s\S]*?whiteSpace:\s*['"]nowrap['"]/);
    });
});
