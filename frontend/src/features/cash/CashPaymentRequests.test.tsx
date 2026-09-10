import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import CashPaymentRequests from './CashPaymentRequests';

describe('CashPaymentRequests availability contract', () => {
    it('shows an internal draft workflow without local sample requests', () => {
        render(<CashPaymentRequests />);

        expect(screen.queryByText('ĐNCT00001')).not.toBeInTheDocument();
        expect(screen.getByRole('button', { name: /Lập đề nghị chi tiền/ })).toBeEnabled();
    });
});
