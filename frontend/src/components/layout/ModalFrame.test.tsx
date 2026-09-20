import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import ModalFrame from './ModalFrame';

describe('ModalFrame', () => {
    it('uses a scoped modal enter class', () => {
        render(<ModalFrame>Modal content</ModalFrame>);

        expect(screen.getByTestId('ui-modal-frame')).toHaveClass('ui-motion-modal-enter');
    });
});
