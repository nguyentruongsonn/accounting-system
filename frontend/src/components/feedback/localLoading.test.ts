import { describe, expect, it } from 'vitest';

import { isLocalQueryLoading } from './localLoading';

describe('isLocalQueryLoading', () => {
    it('keeps the table loading while a background refresh is fetching', () => {
        expect(isLocalQueryLoading(false, true)).toBe(true);
    });

    it('stays idle only when the query is neither loading nor fetching', () => {
        expect(isLocalQueryLoading(false, false)).toBe(false);
    });
});
