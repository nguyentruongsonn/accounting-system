import { render, screen, waitFor } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { MemoryRouter, useLocation } from 'react-router-dom';
import { useSourceRecordDeepLink } from './useSourceRecordDeepLink';

function Harness({ records, isLoading = false, isError = false, onMissing = vi.fn() }: {
  records: Array<{ id: number; code: string }>;
  isLoading?: boolean;
  isError?: boolean;
  onMissing?: () => void;
}) {
  const location = useLocation();
  const onOpen = (record: { id: number; code: string }) => {
    document.body.dataset.openedSource = record.code;
  };
  useSourceRecordDeepLink({ records, isLoading, isError, onOpen, onMissing });
  return <output>{location.pathname}{location.search}</output>;
}

describe('useSourceRecordDeepLink', () => {
  it('opens the matching record and consumes only source_id', async () => {
    render(<MemoryRouter initialEntries={['/purchase?tab=6&source_id=8']}>
      <Harness records={[{ id: 8, code: 'RETURN-008' }]} />
    </MemoryRouter>);
    await waitFor(() => expect(document.body.dataset.openedSource).toBe('RETURN-008'));
    expect(screen.getByText('/purchase?tab=6')).toBeInTheDocument();
    delete document.body.dataset.openedSource;
  });

  it('reports a missing record and consumes an invalid completed link', async () => {
    const onMissing = vi.fn();
    render(<MemoryRouter initialEntries={['/sales?tab=discount&source_id=404']}>
      <Harness records={[]} onMissing={onMissing} />
    </MemoryRouter>);
    await waitFor(() => expect(onMissing).toHaveBeenCalledOnce());
    expect(screen.getByText('/sales?tab=discount')).toBeInTheDocument();
  });

  it('keeps the link pending while the source list is loading or failed', async () => {
    const onMissing = vi.fn();
    const { rerender } = render(<MemoryRouter initialEntries={['/sales/invoices?source_id=15']}>
      <Harness records={[]} isLoading onMissing={onMissing} />
    </MemoryRouter>);
    expect(screen.getByText('/sales/invoices?source_id=15')).toBeInTheDocument();
    rerender(<MemoryRouter initialEntries={['/sales/invoices?source_id=15']}>
      <Harness records={[]} isError onMissing={onMissing} />
    </MemoryRouter>);
    expect(onMissing).not.toHaveBeenCalled();
    expect(screen.getByText('/sales/invoices?source_id=15')).toBeInTheDocument();
  });
});
