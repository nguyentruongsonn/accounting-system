import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import LendingContracts from './LendingContracts';

describe('LendingContracts availability contract', () => {
    it('does not use the borrowing-contract endpoint shape for local lending samples', () => {
        render(<LendingContracts />);

        expect(screen.getByText('Workflow cho vay chưa khả dụng')).toBeInTheDocument();
        expect(screen.queryByText('KUCV00001')).not.toBeInTheDocument();
        expect(screen.getByRole('button', { name: /Thêm khế ước cho vay/ })).toBeDisabled();
    });
});
