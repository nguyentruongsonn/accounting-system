import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import dayjs from 'dayjs';
import PayrollList from './PayrollList';
import api from '../../api/axios';

vi.mock('../../api/axios', () => ({
    default: {
        get: vi.fn(),
        post: vi.fn(),
    },
}));

const mockedApi = vi.mocked(api);

describe('PayrollList API contract', () => {
    beforeEach(() => {
        // Preserve the shared jsdom matchMedia implementation used by AntD;
        // resetAllMocks would erase its addEventListener methods.
        vi.clearAllMocks();
        mockedApi.get.mockResolvedValue({
            data: [{
                id: 7,
                month: dayjs().format('YYYY-MM'),
                voucher_number: 'PR-007',
                voucher_date: '2026-08-15',
                posting_date: '2026-08-15',
                description: 'Bảng lương tháng 8',
                total_amount: '100000.00',
                is_posted: false,
            }],
        } as never);
        mockedApi.post.mockResolvedValue({ data: { ok: true } } as never);
    });

    it('loads existing vouchers and posts through tenant-bound routes only', async () => {
        render(<PayrollList />);

        fireEvent.click(screen.getByRole('button', { name: /Tải bảng lương/ }));

        await waitFor(() => expect(screen.getByText('PR-007')).toBeInTheDocument());
        expect(mockedApi.get).toHaveBeenCalledWith('/payroll');
        expect(mockedApi.post).not.toHaveBeenCalled();

        fireEvent.click(screen.getByRole('button', { name: /Ghi sổ/ }));

        await waitFor(() => expect(mockedApi.post).toHaveBeenCalledWith('/payroll/7/post'));
        expect(mockedApi.post).not.toHaveBeenCalledWith('/payroll/calculate', expect.anything());
        expect(mockedApi.post).not.toHaveBeenCalledWith('/payroll/post', expect.anything());
        expect(mockedApi.post.mock.calls.flat()).not.toContainEqual(expect.objectContaining({ company_id: 1 }));
    }, 15000);
});
