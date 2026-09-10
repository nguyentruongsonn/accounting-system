import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import api from '../../api/axios';
import CashAudit from './CashAudit';

vi.mock('@tanstack/react-query', () => ({
    useMutation: vi.fn(),
    useQuery: vi.fn(),
    useQueryClient: vi.fn(),
}));

vi.mock('../../api/axios', () => ({
    default: {
        get: vi.fn(),
        post: vi.fn(),
    },
}));

const mockedUseMutation = vi.mocked(useMutation);
const mockedUseQuery = vi.mocked(useQuery);
const mockedUseQueryClient = vi.mocked(useQueryClient);
const mockedApi = vi.mocked(api);

describe('CashAudit availability contract', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        mockedUseMutation.mockReturnValue({ mutate: vi.fn(), isPending: false } as never);
        mockedUseQueryClient.mockReturnValue({ invalidateQueries: vi.fn() } as never);
        mockedUseQuery.mockImplementation((options) => ({
            data: options.queryKey[0] === 'cash-inventories' ? [] : undefined,
            isLoading: false,
        }) as never);
        mockedApi.get.mockResolvedValue({ data: [] } as never);
    });

    it('keeps the portalled audit title independent from an unmounted form instance', () => {
        const consoleMessages: string[] = [];
        const errorSpy = vi.spyOn(console, 'error').mockImplementation((...args) => {
            consoleMessages.push(args.map(String).join(' '));
        });
        const warningSpy = vi.spyOn(console, 'warn').mockImplementation((...args) => {
            consoleMessages.push(args.map(String).join(' '));
        });

        try {
            render(<CashAudit />);
            expect(consoleMessages.some((message) => message.includes('Instance created by `useForm` is not connected'))).toBe(false);
        } finally {
            errorSpy.mockRestore();
            warningSpy.mockRestore();
        }
    });

    it('fails closed when the backend has no cash book-balance endpoint', () => {
        render(<CashAudit />);

        expect(screen.getByText('Đối chiếu sổ sách tiền mặt chưa khả dụng')).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: /Kiểm kê quỹ đến ngày/ }));
        expect(screen.getByRole('button', { name: 'Đồng ý' })).toBeDisabled();
        expect(mockedApi.get).not.toHaveBeenCalledWith('/cash/summary');
        expect(mockedApi.post).not.toHaveBeenCalled();
    });

    it('keeps the audit toolbar focused on search and the supported create action', () => {
        render(<CashAudit />);

        expect(screen.getByPlaceholderText('Tìm kiếm chứng từ kiểm kê...')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: /Kiểm kê quỹ đến ngày/ })).toBeEnabled();
        expect(screen.queryByRole('button', { name: 'Thêm bằng AI' })).not.toBeInTheDocument();
    });

    it('does not turn a failed audit-list request into an empty state', () => {
        const refetch = vi.fn();
        mockedUseQuery.mockImplementation((options) => {
            if (options.queryKey[0] === 'cash-inventories') {
                return { data: undefined, isLoading: false, isError: true, refetch } as never;
            }
            return { data: undefined, isLoading: false, isError: false } as never;
        });

        render(<CashAudit />);

        expect(screen.getByText('Không thể tải danh sách kiểm kê quỹ')).toBeInTheDocument();
        expect(screen.queryByText('Chưa có biên bản kiểm kê quỹ')).not.toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Thử lại' }));
        expect(refetch).toHaveBeenCalledTimes(1);
    });

    it('shows a retryable account-catalogue error before opening the audit form', () => {
        const refetch = vi.fn();
        mockedUseQuery.mockImplementation((options) => {
            if (options.queryKey[0] === 'cash-audit-accounts') {
                return { data: undefined, isLoading: false, isError: true, refetch } as never;
            }
            if (options.queryKey[0] === 'cash-inventories') {
                return { data: [], isLoading: false, isError: false } as never;
            }
            return { data: undefined, isLoading: false, isError: false } as never;
        });

        render(<CashAudit />);
        fireEvent.click(screen.getByRole('button', { name: /Kiểm kê quỹ đến ngày/ }));

        expect(screen.getByText('Không thể tải danh mục tài khoản tiền mặt')).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Thử lại danh mục tài khoản' }));
        expect(refetch).toHaveBeenCalledTimes(1);
    });

    it('offers a retry when the selected account balance cannot be verified', () => {
        const refetch = vi.fn();
        mockedUseQuery.mockImplementation((options) => {
            if (options.queryKey[0] === 'cash-book-balance') {
                return { data: undefined, isLoading: false, isError: true, refetch } as never;
            }
            if (options.queryKey[0] === 'cash-inventories') {
                return { data: [], isLoading: false, isError: false } as never;
            }
            return { data: undefined, isLoading: false, isError: false } as never;
        });

        render(<CashAudit />);

        expect(screen.getByText('Đối chiếu sổ sách tiền mặt chưa khả dụng')).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Thử lại số dư sổ' }));
        expect(refetch).toHaveBeenCalledTimes(1);
    });

    it('does not update the form before the portalled audit form is mounted', async () => {
        const consoleMessages: string[] = [];
        const errorSpy = vi.spyOn(console, 'error').mockImplementation((...args) => {
            consoleMessages.push(args.map(String).join(' '));
        });
        const warningSpy = vi.spyOn(console, 'warn').mockImplementation((...args) => {
            consoleMessages.push(args.map(String).join(' '));
        });
        mockedUseQuery.mockImplementation((options) => {
            if (options.queryKey[0] === 'cash-inventories') {
                return { data: [], isLoading: false, isError: false } as never;
            }
            if (options.queryKey[0] === 'cash-audit-accounts') {
                return { data: [{ code: '111', name: 'Tiền mặt', is_active: true }], isLoading: false, isError: false } as never;
            }
            if (options.queryKey[0] === 'cash-book-balance') {
                return { data: { status: 'available', balance: 100000 }, isLoading: false, isError: false } as never;
            }
            return { data: undefined, isLoading: false, isError: false } as never;
        });

        try {
            render(<CashAudit />);
            fireEvent.click(screen.getByRole('button', { name: /Kiểm kê quỹ đến ngày/ }));
            fireEvent.click(screen.getByRole('button', { name: 'Đồng ý' }));

            await waitFor(() => expect(screen.getByText(/Bảng kiểm kê quỹ/)).toBeInTheDocument());
            expect(consoleMessages.some((message) => message.includes('Instance created by `useForm` is not connected'))).toBe(false);
        } finally {
            errorSpy.mockRestore();
            warningSpy.mockRestore();
        }
    });

    it('limits audit-member roles to the two internal system roles', async () => {
        mockedUseQuery.mockImplementation((options) => {
            if (options.queryKey[0] === 'cash-inventories') {
                return { data: [], isLoading: false, isError: false } as never;
            }
            if (options.queryKey[0] === 'cash-audit-accounts') {
                return { data: [{ code: '111', name: 'Tiền mặt', is_active: true }], isLoading: false, isError: false } as never;
            }
            if (options.queryKey[0] === 'cash-book-balance') {
                return { data: { status: 'available', balance: 100000 }, isLoading: false, isError: false } as never;
            }
            return { data: undefined, isLoading: false, isError: false } as never;
        });

        render(<CashAudit />);
        fireEvent.click(screen.getByRole('button', { name: /Kiểm kê quỹ đến ngày/ }));
        fireEvent.click(screen.getByRole('button', { name: 'Đồng ý' }));

        await waitFor(() => expect(screen.getByText(/Bảng kiểm kê quỹ/)).toBeInTheDocument());
        fireEvent.click(screen.getByText('Thành viên tham gia'));

        expect(screen.getByText('Admin')).toBeInTheDocument();
        expect(screen.getByText('Kế toán')).toBeInTheDocument();
        expect(screen.queryByText('Trưởng ban kiểm kê')).not.toBeInTheDocument();
        expect(screen.queryByText('Thủ quỹ')).not.toBeInTheDocument();
    });
});
