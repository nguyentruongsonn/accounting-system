import { render, screen, fireEvent } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { useQuery } from '@tanstack/react-query';
import BalanceSheetReport from './BalanceSheetReport';
import IncomeStatementReport from './IncomeStatementReport';
import balanceSheetSource from './BalanceSheetReport.tsx?raw';
import incomeStatementSource from './IncomeStatementReport.tsx?raw';

vi.mock('@tanstack/react-query', async (importOriginal) => {
    const actual = await importOriginal<typeof import('@tanstack/react-query')>();
    return { ...actual, useQuery: vi.fn() };
});

const mockedUseQuery = vi.mocked(useQuery);

const failedQuery = (refetch: ReturnType<typeof vi.fn>) => ({
    data: undefined,
    error: new Error('report request failed'),
    isError: true,
    isLoading: false,
    refetch,
});

describe('statutory-looking report failure boundary', () => {
    let retry: ReturnType<typeof vi.fn>;

    beforeEach(() => {
        vi.clearAllMocks();
        retry = vi.fn();
        mockedUseQuery.mockReturnValue(failedQuery(retry) as never);
    });

    it('does not render an empty balance sheet when the server request fails', () => {
        render(<BalanceSheetReport />);

        expect(screen.getByText('Không thể tải dữ liệu báo cáo: Bảng cân đối kế toán')).toBeInTheDocument();
        expect(screen.getByText(/Không hiển thị dữ liệu rỗng/)).toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: 'Thử lại' }));
        expect(retry).toHaveBeenCalledOnce();
    });

    it('does not render an empty income statement when the server request fails', () => {
        render(<IncomeStatementReport />);

        expect(screen.getByText('Không thể tải dữ liệu báo cáo: Báo cáo kết quả hoạt động kinh doanh')).toBeInTheDocument();
        expect(screen.getByText(/Không hiển thị dữ liệu rỗng/)).toBeInTheDocument();
    });

    it('keeps valid zero balances visible in financial statements', () => {
        expect(balanceSheetSource).not.toContain('emptyZero: true');
        expect(incomeStatementSource).not.toContain('emptyZero: true');
    });

    it('passes the selected accounting period to both statements and exports', () => {
        for (const source of [balanceSheetSource, incomeStatementSource]) {
            expect(source).toContain("from_date: period[0].format('YYYY-MM-DD')");
            expect(source).toContain("to_date: period[1].format('YYYY-MM-DD')");
            expect(source).toContain('DatePicker.RangePicker');
        }
    });

    it('distinguishes an initial report load from a valid empty period', () => {
        for (const source of [balanceSheetSource, incomeStatementSource]) {
            expect(source).toContain("isLoading ? 'Đang tải dữ liệu báo cáo...' :");
        }
    });
});
