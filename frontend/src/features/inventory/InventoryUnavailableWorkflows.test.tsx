import { fireEvent, render, screen } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import InventoryWorkspace from './InventoryWorkspace';
import api from '../../api/axios';

vi.mock('../../api/axios', () => ({
    default: {
        get: vi.fn(),
        post: vi.fn(),
        put: vi.fn(),
        delete: vi.fn(),
    },
}));

beforeEach(() => {
    vi.mocked(api.get).mockResolvedValue({ data: [] } as never);
});

const renderWorkspace = () => {
    const queryClient = new QueryClient({
        defaultOptions: { queries: { retry: false } },
    });

    return render(
        <QueryClientProvider client={queryClient}>
            <InventoryWorkspace />
        </QueryClientProvider>,
    );
};

describe('inventory workflow boundaries', () => {
    it('exposes the server-backed transfer surface without restoring a draft-only limitation', () => {
        renderWorkspace();

        fireEvent.click(screen.getByRole('tab', { name: 'Chuyển kho' }));

        expect(screen.queryByRole('heading', { name: 'Quản lý kho' })).toBeNull();
        expect(screen.queryByRole('heading', { name: 'Chuyển kho nội bộ' })).not.toBeInTheDocument();
        expect(screen.getByRole('button', { name: /Thêm phiếu điều chuyển/ })).toBeDisabled();
        expect(screen.queryByText('Chỉ quản lý phiếu nháp')).not.toBeInTheDocument();
    });

    it('exposes stock-count draft capture without adjustment or posting authority', () => {
        renderWorkspace();

        fireEvent.click(screen.getByRole('tab', { name: 'Kiểm kê kho' }));

        expect(screen.queryByRole('heading', { name: 'Quản lý kho' })).toBeNull();
        expect(screen.queryByRole('heading', { name: 'Kiểm kê kho' })).not.toBeInTheDocument();
        expect(screen.queryByText('Chỉ ghi nhận số đếm thực tế')).not.toBeInTheDocument();
        expect(screen.getByRole('button', { name: /Thêm biên bản nháp/ })).toBeDisabled();
        expect(screen.queryByRole('button', { name: /Điều chỉnh|Ghi sổ/i })).not.toBeInTheDocument();
    });

    it.each([
        ['Tính giá xuất kho'],
        ['Báo cáo kho'],
    ])('does not render a context-only parent toolbar for %s', (tabName) => {
        const { container } = renderWorkspace();

        fireEvent.click(screen.getByRole('tab', { name: tabName }));

        expect(container.querySelector('.ui-page-toolbar__context')).not.toBeInTheDocument();
    });

    it('keeps the embedded stock report free of decorative report notes', () => {
        renderWorkspace();

        fireEvent.click(screen.getByRole('tab', { name: 'Báo cáo kho' }));

        expect(screen.queryByText(/Báo cáo tồn kho vận hành/)).not.toBeInTheDocument();
        expect(screen.queryByText(/Chọn bộ lọc nếu cần rồi bấm/)).not.toBeInTheDocument();
    });
});
