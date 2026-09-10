import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import ImportFromMeInvoiceModal from './ImportFromMeInvoiceModal';

describe('ImportFromMeInvoiceModal availability contract', () => {
    it('does not present sample meInvoice records or fake sync/import actions', () => {
        render(<ImportFromMeInvoiceModal open onCancel={vi.fn()} />);

        expect(screen.queryByRole('alert')).not.toBeInTheDocument();
        expect(screen.queryByText('00012845')).not.toBeInTheDocument();
        expect(screen.getByRole('button', { name: /Đồng bộ từ TCT/ })).toBeDisabled();
        expect(screen.getByRole('button', { name: 'Lập chứng từ mua hàng' })).toBeDisabled();
    });
});
