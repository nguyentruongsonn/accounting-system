import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { MultiColumnContactSelect } from './MultiColumnContactSelect';

describe('MultiColumnContactSelect viewport boundary', () => {
  it('portals the multi-column popup and leaves height/overflow to AdaptiveSelect', async () => {
    render(
      <MultiColumnContactSelect
        options={[
          { id: 1, code: 'KH001', name: 'Khách hàng thử nghiệm', address: 'Hà Nội' },
        ]}
      />,
    );

    fireEvent.mouseDown(screen.getByRole('combobox'));

    await waitFor(() => {
      const popup = document.querySelector<HTMLElement>('.ant-select-dropdown');
      expect(popup).not.toBeNull();
      expect(popup?.parentElement).toBe(document.body);
      expect(popup?.querySelector('[data-adaptive-select]')).not.toBeNull();
      expect(popup?.style.maxHeight).toBe('');
      expect(popup?.style.overflow).toBe('');
    });
  });
});
