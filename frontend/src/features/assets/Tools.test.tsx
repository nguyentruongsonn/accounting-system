import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import Tools from './Tools';

describe('Tools availability contract', () => {
    it('does not present local sample CCDC records when list endpoint is unavailable', () => {
        render(<Tools />);

        expect(screen.getByText('Danh sách CCDC chưa khả dụng')).toBeInTheDocument();
        expect(screen.queryByText('Đồng phục nhân viên')).not.toBeInTheDocument();
    });
});
