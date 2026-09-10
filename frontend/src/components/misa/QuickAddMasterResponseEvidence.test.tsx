import { describe, expect, it } from 'vitest';

import paymentTerm from './QuickAddPaymentTermModal.tsx?raw';
import unit from './QuickAddUnitModal.tsx?raw';
import warehouse from './QuickAddWarehouseModal.tsx?raw';
import itemCategory from './QuickAddItemCategoryModal.tsx?raw';
import reason from './QuickAddReasonModal.tsx?raw';

describe('quick-add master response evidence', () => {
  it('requires a persisted id before reporting success for each helper', () => {
    for (const source of [paymentTerm, unit, warehouse, itemCategory, reason]) {
      expect(source).toContain('id === undefined ||');
      expect(source).toContain('message.error');
    }
  });
});
