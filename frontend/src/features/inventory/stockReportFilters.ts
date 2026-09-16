import type { Dayjs } from 'dayjs';

export type StockReportFormValues = {
  dateRange?: [Dayjs, Dayjs] | null;
  warehouse_id?: number;
  item_id?: number;
};

export type StockReportRequestParams = Record<'from_date' | 'to_date' | 'warehouse_id' | 'item_id', string | number>;

/**
 * Keep the UI payload strictly within the manifest-published inventory report
 * filters. An empty date range means no date predicate is sent; it does not
 * represent a client-invented reporting period.
 */
export function buildStockReportParams(values: StockReportFormValues): StockReportRequestParams {
  const params = {} as StockReportRequestParams;
  const [fromDate, toDate] = values.dateRange ?? [];

  if (fromDate && toDate) {
    if (fromDate.isAfter(toDate, 'day')) {
      throw new Error('Khoảng ngày bắt đầu không được sau ngày kết thúc.');
    }
    params.from_date = fromDate.format('YYYY-MM-DD');
    params.to_date = toDate.format('YYYY-MM-DD');
  }

  if (values.warehouse_id !== undefined) {
    params.warehouse_id = values.warehouse_id;
  }
  if (values.item_id !== undefined) {
    params.item_id = values.item_id;
  }

  return params;
}
