import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { MisaModal } from '../../components/misa/MisaModal';

describe('purchase-style legacy modal compatibility', () => {
  it('preserves cancel, save, save-and-add and account toggle handlers', () => {
    const onCancel = vi.fn();
    const onSave = vi.fn();
    const onSaveAndAdd = vi.fn();
    const onShowAccountsChange = vi.fn();
    render(<MisaModal open title="Phiếu thử" onCancel={onCancel} onSave={onSave}
      onSaveAndAdd={onSaveAndAdd} onShowAccountsChange={onShowAccountsChange}>
      <input aria-label="Diễn giải" defaultValue="Giữ nguyên dữ liệu"/>
    </MisaModal>);
    fireEvent.click(screen.getByRole('button', { name: 'Cất' }));
    fireEvent.click(screen.getByRole('button', { name: /Cất và Thêm/ }));
    fireEvent.click(screen.getByRole('switch'));
    fireEvent.click(screen.getByRole('button', { name: 'Hủy' }));
    expect(onSave).toHaveBeenCalledTimes(1);
    expect(onSaveAndAdd).toHaveBeenCalledTimes(1);
    expect(onCancel).toHaveBeenCalledTimes(1);
    expect(onShowAccountsChange).toHaveBeenCalledWith(false, expect.anything());
    expect(screen.getByLabelText('Diễn giải')).toHaveValue('Giữ nguyên dữ liệu');
  });
});
