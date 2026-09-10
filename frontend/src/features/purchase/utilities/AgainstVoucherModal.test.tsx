import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import AgainstVoucherModal from './AgainstVoucherModal';

describe('AgainstVoucherModal availability contract', () => {
    it('does not present sample vouchers or offer a fake offset action', () => {
        render(<AgainstVoucherModal open onCancel={vi.fn()} />);

        expect(screen.queryByRole('alert')).not.toBeInTheDocument();
        expect(screen.queryByText('Công ty Cổ phần Thép Hòa Phát')).not.toBeInTheDocument();
        expect(screen.queryByText('UNC00001')).not.toBeInTheDocument();
        expect(screen.queryByText('PN00001')).not.toBeInTheDocument();
        expect(screen.getByRole('button', { name: /Đối trừ tự động/ })).toBeDisabled();
        expect(screen.getByRole('button', { name: 'Thực hiện đối trừ' })).toBeDisabled();
        expect(screen.getByText('Chưa có tài khoản từ máy chủ')).toBeInTheDocument();
        expect(screen.queryByText('331 - Phải trả cho người bán')).not.toBeInTheDocument();
        expect(screen.queryByText('3388 - Phải trả khác')).not.toBeInTheDocument();
    });
});
