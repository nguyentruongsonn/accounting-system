import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { useQuery } from '@tanstack/react-query';
import PayVendorByInvoiceModal from './PayVendorByInvoiceModal';

vi.mock('@tanstack/react-query', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@tanstack/react-query')>()),
    useQuery: vi.fn(() => ({ data: [] })),
}));

describe('PayVendorByInvoiceModal workflow contract', () => {
    it('loads only server invoices and keeps the payment action available for real selections', () => {
        render(<PayVendorByInvoiceModal open onCancel={vi.fn()} />);

        expect(screen.queryByRole('alert')).not.toBeInTheDocument();
        expect(screen.queryByText('MH00001')).not.toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Trả tiền' })).not.toBeDisabled();
        expect(screen.getByText('Chọn bộ lọc rồi bấm Lấy dữ liệu.')).toBeInTheDocument();
        expect(useQuery).toHaveBeenCalled();
    });
});
