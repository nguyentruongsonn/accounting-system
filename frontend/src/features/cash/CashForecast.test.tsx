import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import api from '../../api/axios';
import CashForecast from './CashForecast';

vi.mock('../../api/axios', () => ({
    default: {
        get: vi.fn(),
        post: vi.fn(),
    },
}));

const mockedApi = vi.mocked(api);

describe('CashForecast API contract', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        mockedApi.get.mockResolvedValue({ data: [] } as never);
        mockedApi.post.mockResolvedValue({ data: { id: 11, period_name: 'Test' } } as never);
    });

    // Ant Design's modal/table effects can exceed Vitest's 5s default when the
    // complete suite is running in parallel; this remains a bounded contract
    // test and the timeout is scoped to this UI integration case only.
    it('loads and saves through tenant-scoped cash forecast routes without fake records', async () => {
        render(<CashForecast />);

        await waitFor(() => expect(mockedApi.get).toHaveBeenCalledWith('/cash-forecasts'));
        expect(screen.getByText('Chưa có dự báo dòng tiền.')).toBeInTheDocument();
        expect(screen.queryByText('Nguyễn Văn Nam')).not.toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: /Thêm dự báo dòng tiền/ }));
        fireEvent.click(screen.getByRole('button', { name: 'Đồng ý' }));
        fireEvent.click(screen.getByRole('button', { name: 'Cất' }));

        await waitFor(() => expect(mockedApi.post).toHaveBeenCalledWith(
            '/cash-forecasts',
            expect.objectContaining({ expected_inflow: 0, expected_outflow: 0 }),
        ));
        expect(JSON.stringify(mockedApi.post.mock.calls)).not.toContain('company_id');
    }, 15000);
});
