import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import PurchaseReceiveInvoices from './PurchaseReceiveInvoices';

describe('PurchaseReceiveInvoices availability contract', () => {
    it('does not present local sample invoices while backend workflow is unavailable', () => {
        render(<PurchaseReceiveInvoices />);

        expect(screen.queryByRole('alert')).not.toBeInTheDocument();
        expect(screen.queryByText('00012845')).not.toBeInTheDocument();
        expect(screen.getByRole('button', { name: /Nhận hóa đơn/ })).toBeDisabled();
    });
});
