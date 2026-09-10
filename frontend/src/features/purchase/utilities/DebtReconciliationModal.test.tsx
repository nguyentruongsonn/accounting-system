import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import DebtReconciliationModal from './DebtReconciliationModal';

describe('DebtReconciliationModal availability contract', () => {
    it('does not report local Excel reconciliation as completed', () => {
        render(<DebtReconciliationModal open onCancel={vi.fn()} />);

        expect(screen.queryByRole('alert')).not.toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Bắt đầu đối chiếu' })).toBeDisabled();
        expect(screen.getAllByRole('combobox')[0]).toBeDisabled();
        expect(screen.getByRole('button', { name: /Chọn file Excel công nợ/ })).toBeDisabled();
    });
});
