import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import RouteLoadingFallback from './RouteLoadingFallback';

describe('RouteLoadingFallback', () => {
    it('uses a scoped surface motion class without changing the loading contract', () => {
        render(<RouteLoadingFallback />);

        expect(screen.getByRole('status')).toHaveClass('ui-motion-surface-enter');
        expect(screen.getByLabelText('Đang tải màn hình')).toBeInTheDocument();
    });
});
