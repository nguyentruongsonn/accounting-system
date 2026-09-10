import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { useQuery } from '@tanstack/react-query';
import PurchaseDashboard from './PurchaseDashboard';

vi.mock('@tanstack/react-query', async (importOriginal) => {
    const actual = await importOriginal<typeof import('@tanstack/react-query')>();
    return { ...actual, useQuery: vi.fn() };
});

const mockedUseQuery = vi.mocked(useQuery);

describe('PurchaseDashboard data presentation', () => {
    beforeEach(() => {
        vi.resetAllMocks();
    });

    it('renders canonical AP balances from exact decimal companion fields', () => {
        mockedUseQuery.mockReturnValue({
            data: {
                as_of: '2026-09-04T12:00:00Z',
                currency: 'VND',
                orders: { total_amount_decimal: '120000.00', executed_amount_decimal: '80000.00', paid_amount: null, remaining_amount: null },
                contracts: { total_amount_decimal: '0.00', executed_amount: null, paid_amount: null, remaining_amount: null },
                invoices: {
                    total_amount_decimal: '100000.00',
                    paid_amount_decimal: '30000.00',
                    remaining_amount_decimal: '70000.00',
                },
                top_debt_suppliers: [{ name: 'Nhà cung cấp AP', amount_decimal: '70000.00', percent: 100 }],
                top_purchase_suppliers: [{ name: 'Nhà cung cấp AP', amount_decimal: '100000.00', percent: 100 }],
                metric_availability: {
                    'orders.paid_amount': { status: 'unavailable', reason: 'Không có liên kết.' },
                    'orders.remaining_amount': { status: 'unavailable', reason: 'Không có liên kết.' },
                    'contracts.executed_amount': { status: 'unavailable', reason: 'Không có nguồn.' },
                    'contracts.paid_amount': { status: 'unavailable', reason: 'Không có nguồn.' },
                    'contracts.remaining_amount': { status: 'unavailable', reason: 'Không có nguồn.' },
                    'invoices.paid_amount': { status: 'available', source: 'settlement_allocations' },
                    'invoices.remaining_amount': { status: 'available', source: 'settlement_allocations' },
                    top_debt_suppliers: { status: 'available', source: 'purchase_invoices + settlement_allocations' },
                },
            },
            isLoading: false,
            isError: false,
            refetch: vi.fn(),
        } as unknown as ReturnType<typeof useQuery>);

        render(<PurchaseDashboard />);

        expect(screen.getAllByText('70.000').length).toBeGreaterThan(0);
        expect(screen.getByText('100.000')).toBeInTheDocument();
        expect(screen.getAllByText('Chưa có nguồn dữ liệu').length).toBeGreaterThan(0);
    });

    it('keeps dashboard errors distinct from empty data and provides a retry action', () => {
        const refetch = vi.fn();
        mockedUseQuery.mockReturnValue({
            data: undefined,
            isLoading: false,
            isError: true,
            refetch,
        } as unknown as ReturnType<typeof useQuery>);

        render(<PurchaseDashboard />);

        expect(screen.getByText('Không thể tải dữ liệu dashboard')).toBeInTheDocument();
        const retry = screen.getByRole('button', { name: 'Thử lại dashboard' });
        fireEvent.click(retry);
        expect(refetch).toHaveBeenCalledTimes(1);
    });
});
