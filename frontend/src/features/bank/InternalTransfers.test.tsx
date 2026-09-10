import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import InternalTransfers from './InternalTransfers';
import source from './InternalTransfers.tsx?raw';

describe('InternalTransfers availability contract', () => {
    it('does not present local sample transfers while backend workflow is unavailable', () => {
        render(<InternalTransfers />);

        expect(screen.getByText('Chuyển tiền nội bộ chưa khả dụng')).toBeInTheDocument();
        expect(screen.queryByText('CTNB00001')).not.toBeInTheDocument();
        expect(screen.getByRole('button', { name: /Thêm chuyển tiền nội bộ/ })).toBeDisabled();
    });

    it('does not embed tenant bank accounts, sample amounts, or local posting success', () => {
        expect(source).not.toContain('Math.random');
        expect(source).not.toContain('0011001234567');
        expect(source).not.toContain('1903009876543');
        expect(source).not.toContain("useState(25000000)");
        expect(source).not.toContain("useState(5500)");
        expect(source).not.toContain("message.success('Đã xóa chứng từ chuyển tiền nội bộ thành công!')");
        expect(source).not.toContain('setTransfers(transfers.filter');
        expect(source).toContain('backend chưa công bố endpoint chuyển tiền nội bộ');
    });
});
