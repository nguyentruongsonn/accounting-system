import dayjs from 'dayjs';
import { describe, expect, it } from 'vitest';
import { buildStockReportParams } from './stockReportFilters';

describe('buildStockReportParams', () => {
  it('sends only manifest-published, explicitly selected filters', () => {
    expect(buildStockReportParams({
      dateRange: [dayjs('2026-01-01'), dayjs('2026-01-31')],
      warehouse_id: 17,
      item_id: 23,
    })).toEqual({
      from_date: '2026-01-01',
      to_date: '2026-01-31',
      warehouse_id: 17,
      item_id: 23,
    });
    expect(buildStockReportParams({})).toEqual({});
  });

  it('rejects an inverted date range before an API request can be made', () => {
    expect(() => buildStockReportParams({
      dateRange: [dayjs('2026-02-01'), dayjs('2026-01-31')],
    })).toThrow('Khoảng ngày bắt đầu không được sau ngày kết thúc.');
  });
});
