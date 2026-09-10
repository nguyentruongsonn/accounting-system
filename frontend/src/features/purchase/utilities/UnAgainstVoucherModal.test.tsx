import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import UnAgainstVoucherModal from './UnAgainstVoucherModal';

describe('UnAgainstVoucherModal availability contract', () => {
    it('does not present sample history or offer a fake un-offset action', () => {
        render(<UnAgainstVoucherModal open onCancel={vi.fn()} />);

        expect(screen.queryByRole('alert')).not.toBeInTheDocument();
        expect(screen.queryByText('Công ty Cổ phần Thép Hòa Phát')).not.toBeInTheDocument();
        expect(screen.queryByText('UNC00001')).not.toBeInTheDocument();
        expect(screen.queryByText('PN00001')).not.toBeInTheDocument();
        expect(screen.getByRole('button', { name: /Bỏ đối trừ/ })).toBeDisabled();
        expect(screen.getByRole('button', { name: /Lấy dữ liệu/ })).toBeDisabled();
        expect(screen.getByText('Chưa có tài khoản từ máy chủ')).toBeInTheDocument();
        expect(screen.queryByText('331 - Phải trả người bán')).not.toBeInTheDocument();
    });
});
