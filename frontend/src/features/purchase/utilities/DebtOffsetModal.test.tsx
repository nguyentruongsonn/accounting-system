import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import DebtOffsetModal from './DebtOffsetModal';

describe('DebtOffsetModal availability contract', () => {
    it('does not present sample debts or offer a fake offset action', () => {
        render(<DebtOffsetModal open onCancel={vi.fn()} />);

        expect(screen.queryByRole('alert')).not.toBeInTheDocument();
        expect(screen.queryByText('BH00001')).not.toBeInTheDocument();
        expect(screen.queryByText('PN00002')).not.toBeInTheDocument();
        expect(screen.queryByText('DT001 - Công ty Cổ phần Cơ điện Trần Phú')).not.toBeInTheDocument();
        expect(screen.queryByText('DT002 - Tập đoàn Hòa Phát')).not.toBeInTheDocument();
        expect(screen.getByRole('combobox')).toBeDisabled();
        expect(screen.getByRole('button', { name: /Thực hiện bù trừ/ })).toBeDisabled();
        expect(screen.getByRole('button', { name: /Lấy dữ liệu/ })).toBeDisabled();
    });
});
