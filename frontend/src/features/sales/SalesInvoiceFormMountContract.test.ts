import { describe, expect, it } from 'vitest';
import dimensionAssignments from './SalesInvoiceDimensionAssignments.tsx?raw';

describe('sales invoice modal form mounting contract', () => {
    it('does not call the dimension form before its conditional Drawer form mounts', () => {
        expect(dimensionAssignments).toContain("if (!open || context?.status !== 'available') return;");
    });
});
