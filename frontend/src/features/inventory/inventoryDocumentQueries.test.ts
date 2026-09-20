import { describe, expect, it } from 'vitest';
import { parseInventoryIssueList, parseInventoryReceiptList } from './inventoryDocumentQueries';

describe('inventory document list parsers', () => {
  it('parses persisted receipt and issue envelopes', () => {
    expect(parseInventoryReceiptList({ data: [{ id: 1 }] })).toEqual([{ id: 1 }]);
    expect(parseInventoryIssueList({ data: [{ id: 2 }] })).toEqual([{ id: 2 }]);
  });

  it('rejects a response without persisted rows', () => {
    expect(() => parseInventoryReceiptList({ data: [] })).not.toThrow();
    expect(() => parseInventoryIssueList({ data: [{ id: 0 }] }))
      .toThrow('Invalid inventory issue list response.');
  });
});
