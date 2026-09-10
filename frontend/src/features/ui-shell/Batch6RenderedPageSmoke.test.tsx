import { render, screen } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router-dom';
import { afterAll, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';
import api from '../../api/axios';
import CashPayments from '../cash/CashPayments';
import CashReceipts from '../cash/CashReceipts';

vi.mock('../../api/axios', () => ({
  default: {
    get: vi.fn(),
    post: vi.fn(),
    put: vi.fn(),
    delete: vi.fn(),
  },
}));

const mockedApi = vi.mocked(api);

const renderWithQuery = (element: React.ReactElement) => render(
  <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
    <MemoryRouter>{element}</MemoryRouter>
  </QueryClientProvider>,
);

describe('batch 6 rendered cash page shell smoke coverage', () => {
  const nativeGetComputedStyle = window.getComputedStyle.bind(window);

  beforeAll(() => {
    vi.spyOn(window, 'getComputedStyle').mockImplementation((element) => nativeGetComputedStyle(element));
  });

  afterAll(() => {
    vi.restoreAllMocks();
  });

  beforeEach(() => {
    vi.clearAllMocks();
    mockedApi.get.mockResolvedValue({ data: [] } as never);
  });

  it('renders the cash payment create dialog inside the shared modal frame', async () => {
    renderWithQuery(<CashPayments />);

    expect(screen.queryByRole('heading', { level: 1, name: 'Phiếu chi' })).toBeNull();
    expect(screen.getByTestId('ui-page-toolbar')).toBeInTheDocument();
    expect(screen.getByTestId('ui-table-surface')).toBeInTheDocument();

    window.dispatchEvent(new Event('open-cash-payment'));
    expect(await screen.findByTestId('ui-modal-frame')).toBeInTheDocument();
  });

  it('renders the cash receipt create dialog inside the shared modal frame', async () => {
    renderWithQuery(<CashReceipts />);

    expect(screen.queryByRole('heading', { level: 1, name: 'Phiếu thu' })).toBeNull();
    expect(screen.getByTestId('ui-page-toolbar')).toBeInTheDocument();
    expect(screen.getByTestId('ui-table-surface')).toBeInTheDocument();

    window.dispatchEvent(new Event('open-cash-receipt'));
    expect(await screen.findByTestId('ui-modal-frame')).toBeInTheDocument();
  });
});
