import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import CashAdvanceSettlements from './CashAdvanceSettlements';
import source from './CashAdvanceSettlements.tsx?raw';

describe('CashAdvanceSettlements availability contract', () => {
    it('uses a server-backed draft workflow without local sample settlements', () => {
        render(<CashAdvanceSettlements />);

        expect(screen.queryByText('Quyết toán tạm ứng — quy trình nháp nội bộ')).not.toBeInTheDocument();
        expect(screen.queryByText('QTTU00001')).not.toBeInTheDocument();
        expect(screen.getByRole('button', { name: /Lập quyết toán tạm ứng/ })).toBeEnabled();
    });

    it('does not dispatch fake receipt or payment creation events', () => {
        expect(source).not.toContain("window.dispatchEvent(new Event('open-cash-receipt'))");
        expect(source).not.toContain("window.dispatchEvent(new Event('open-cash-payment'))");
        expect(source).toContain('Thu/chi liên kết (chưa khả dụng)');
        expect(source).not.toContain('apple-section-heading');
        expect(source).not.toContain('cash-inline-note');
        expect(source).toContain("api.post('/cash/advance-settlements'");
        expect(source).toContain('Server did not return a persisted settlement id');
    });
});
