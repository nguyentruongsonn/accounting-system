import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import api from '../../api/axios';
import FixedAssets from './FixedAssets';

vi.mock('../../api/axios', () => ({
    default: {
        get: vi.fn(),
        post: vi.fn(),
    },
}));

const mockedApi = vi.mocked(api);

describe('legacy fixed asset list contract', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        mockedApi.get.mockResolvedValue({ data: [] } as never);
        mockedApi.post.mockResolvedValue({ data: { data: { id: 1 } } } as never);
    });

    it('loads tenant data instead of hard-coded assets and refreshes after depreciation', async () => {
        render(<FixedAssets />);

        await waitFor(() => expect(mockedApi.get).toHaveBeenCalledWith('/fixed-assets'));
        await waitFor(() => expect(screen.getByRole('button', { name: /Chạy Khấu hao tháng/ })).not.toBeDisabled());
        expect(screen.queryByText('Máy tính xách tay Dell XPS')).not.toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: /Chạy Khấu hao tháng/ }));
        await waitFor(() => expect(mockedApi.post).toHaveBeenCalledWith('/fixed-assets/depreciate', expect.objectContaining({ month: expect.any(String) })));
        await waitFor(() => expect(mockedApi.get).toHaveBeenCalledTimes(2));
    });

    it('fails closed when the fixed-asset list response is malformed', async () => {
        mockedApi.get.mockResolvedValueOnce({ data: { data: null } } as never);
        render(<FixedAssets />);

        await waitFor(() => expect(screen.getByText('Không thể tải danh sách tài sản cố định từ máy chủ.')).toBeInTheDocument());
        expect(screen.queryByText('Máy tính xách tay Dell XPS')).not.toBeInTheDocument();
    });

    it('preserves missing monetary evidence instead of displaying synthetic zeroes', async () => {
        mockedApi.get.mockResolvedValueOnce({
            data: [{ id: 7, asset_code: 'FA-7', asset_name: 'Server', original_cost: null, accumulated_depreciation: null, net_value: 0, monthly_depreciation: undefined, status: 'active' }],
        } as never);
        render(<FixedAssets />);

        await waitFor(() => expect(screen.getByText('FA-7')).toBeInTheDocument());
        expect(screen.getAllByText('—').length).toBeGreaterThanOrEqual(3);
        expect(screen.getByText('0')).toBeInTheDocument();
    });
});
