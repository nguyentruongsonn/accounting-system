import { useMemo } from 'react';
import { useQuery } from '@tanstack/react-query';
import api from '../../api/axios';
import {
  parseCashReportResponse,
  type CashReportCode,
  type CashReportFilters,
} from './cashReportContract';

type AppliedCashReportFilters = {
  date_from: string;
  date_to: string;
  status: CashReportFilters['status'];
  search: string;
  cash_account?: string;
};

function normaliseFilters(filters: CashReportFilters): AppliedCashReportFilters {
  const cashAccount = filters.cash_account?.trim();
  return {
    date_from: filters.date_from,
    date_to: filters.date_to,
    status: filters.status,
    search: filters.search?.trim() ?? '',
    ...(cashAccount ? { cash_account: cashAccount } : {}),
  };
}

function assertResponseIdentity(
  response: ReturnType<typeof parseCashReportResponse>,
  code: CashReportCode,
  filters: AppliedCashReportFilters,
): void {
  if (
    response.report.code !== code
    || response.report.date_from !== filters.date_from
    || response.report.date_to !== filters.date_to
    || response.report.status !== filters.status
    || response.report.cash_account !== (filters.cash_account ?? null)
    || response.report.search !== filters.search
  ) {
    throw new Error('Cash report response does not match the requested selection');
  }
}

export function useCashReportQuery(
  selection: { code: CashReportCode; filters: CashReportFilters } | null,
  active = true,
) {
  const appliedFilters = useMemo(
    () => selection ? normaliseFilters(selection.filters) : null,
    [selection?.filters.cash_account, selection?.filters.date_from, selection?.filters.date_to, selection?.filters.search, selection?.filters.status],
  );
  const code = selection?.code ?? null;

  return useQuery({
    queryKey: ['cash-reports', code, appliedFilters],
    enabled: active && code !== null && appliedFilters !== null,
    staleTime: 0,
    refetchOnMount: 'always',
    queryFn: async ({ signal }) => {
      if (code === null || appliedFilters === null) throw new Error('Cash report selection is required');
      const response = await api.get(`/cash/reports/${code}`, { params: appliedFilters, signal });
      const parsed = parseCashReportResponse(response.data?.data);
      assertResponseIdentity(parsed, code, appliedFilters);
      return parsed;
    },
  });
}
