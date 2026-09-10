import { render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { useQuery } from '@tanstack/react-query';
import api from '../../../api/axios';
import { toast } from '../../../components/feedback/toast';
import PayVendorByInvoiceModal from './PayVendorByInvoiceModal';

vi.mock('../../../api/axios', () => ({ default: { get: vi.fn(), post: vi.fn() } }));
vi.mock('../../../components/feedback/toast', () => ({
    toast: { success: vi.fn(), info: vi.fn(), warning: vi.fn(), error: vi.fn() },
}));
vi.mock('@tanstack/react-query', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@tanstack/react-query')>()),
    useQuery: vi.fn((options: any) => ({
        data: options.queryKey[0] === 'purchase-payment-suppliers' ? [] : options.queryKey[0] === 'purchase-payment-accounts' ? [] : undefined,
        isError: false,
        isLoading: false,
        isFetching: false,
        refetch: vi.fn(),
    })),
}));

const mockedApi = vi.mocked(api);
const mockedUseQuery = vi.mocked(useQuery);
const mockedToast = vi.mocked(toast);

afterEach(() => {
    vi.clearAllMocks();
});

describe('PayVendorByInvoiceModal workflow contract', () => {
    it('loads only server invoices and keeps the payment action available for real selections', () => {
        render(<PayVendorByInvoiceModal open onCancel={vi.fn()} />);

        expect(screen.queryByRole('alert')).not.toBeInTheDocument();
        expect(screen.queryByText('MH00001')).not.toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Trả tiền' })).not.toBeDisabled();
        expect(screen.getByText('Chọn bộ lọc rồi bấm Lấy dữ liệu.')).toBeInTheDocument();
        expect(useQuery).toHaveBeenCalled();
    });

    it('requests active accounts with a transport-safe boolean value', async () => {
        mockedApi.get.mockResolvedValue({ data: [] });
        render(<PayVendorByInvoiceModal open onCancel={vi.fn()} />);
        const accountsQuery = mockedUseQuery.mock.calls
            .map(([options]) => options as any)
            .find((options) => options.queryKey[0] === 'purchase-payment-accounts');

        await accountsQuery?.queryFn();

        expect(mockedApi.get).toHaveBeenCalledWith('/master/accounts', { params: { include_inactive: 0 } });
    });

    it('uses a toast and disables payment when a catalogue query fails', () => {
        mockedUseQuery.mockImplementation((options: any) => ({
            data: options.queryKey[0] === 'purchase-payment-accounts' ? undefined : [],
            isError: options.queryKey[0] === 'purchase-payment-accounts',
            isLoading: false,
            isFetching: false,
            refetch: vi.fn(),
        }) as any);

        render(<PayVendorByInvoiceModal open onCancel={vi.fn()} />);

        expect(screen.getByRole('button', { name: 'Trả tiền' })).toBeDisabled();
        expect(screen.queryByRole('alert')).not.toBeInTheDocument();
        expect(mockedToast.error).toHaveBeenCalledWith(expect.stringContaining('danh mục'));
    });

    it('uses the responsive payment-modal layout primitives', () => {
        render(<PayVendorByInvoiceModal open onCancel={vi.fn()} />);

        expect(document.querySelector('.misa-pay-vendor-modal__filter-grid')).toBeInTheDocument();
        expect(document.querySelector('.misa-pay-vendor-modal__toolbar')).toBeInTheDocument();
        expect(document.querySelector('.misa-pay-vendor-modal__table-shell')).toBeInTheDocument();
        expect(document.querySelector('.misa-pay-vendor-modal__footer-actions')).toBeInTheDocument();
    });
});
