import { cleanup, render, screen, within } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { describe, expect, it, vi } from 'vitest';
import { MisaModal } from '../../components/misa/MisaModal';
import MisaVoucherModal from '../../components/misa/MisaVoucherModal';
import { ExcelImportModal } from '../../components/misa/ExcelImportModal';
import QuickAddBankAccountModal from '../../components/misa/QuickAddBankAccountModal';
import QuickAddUnitModal from '../../components/misa/QuickAddUnitModal';
import QuickAddItemCategoryModal from '../../components/misa/QuickAddItemCategoryModal';
import QuickAddPaymentTermModal from '../../components/misa/QuickAddPaymentTermModal';
import QuickAddWarehouseModal from '../../components/misa/QuickAddWarehouseModal';
import QuickAddEmployeeModal from '../../components/misa/QuickAddEmployeeModal';
import reasonModalSource from '../../components/misa/QuickAddReasonModal.tsx?raw';
import contactModalSource from '../../components/misa/QuickAddContactModal.tsx?raw';
import itemModalSource from '../../components/misa/QuickAddItemModal.tsx?raw';
import { CollectMultiCustomerModal } from '../../components/misa/CollectMultiCustomerModal';
import BankVoucherPrintModal from '../../components/misa/BankVoucherPrintModal';
import misaVoucherModalSource from '../../components/misa/MisaVoucherModal.tsx?raw';

describe('modal structure', () => {
  it('keeps MISA body content inside the shared modal frame', () => {
    render(
      <MisaModal open onCancel={vi.fn()} onSave={vi.fn()} onSaveAndAdd={vi.fn()} title="Phiếu thu">
        <input aria-label="Số chứng từ" defaultValue="PT-001" />
      </MisaModal>,
    );

    expect(screen.getByTestId('ui-modal-frame')).toContainElement(screen.getByLabelText('Số chứng từ'));
    expect(screen.getByTestId('ui-modal-body')).toHaveAttribute('data-region', 'body');
    const footer = screen.getByRole('button', { name: 'Cất' }).closest('.ant-modal-footer')!;
    expect(within(footer as HTMLElement).getAllByRole('button').map((button) => button.textContent?.trim()))
      .toEqual(['Hủy', 'Cất', 'Cất và Thêm']);
  });

  it('keeps the command footer outside the scrolling body without a duplicate frame footer', () => {
    render(
      <MisaModal open onCancel={vi.fn()} title="Phiếu thu">
        <div>Biểu mẫu dài</div>
      </MisaModal>,
    );

    const body = screen.getByTestId('ui-modal-body');
    const cancel = screen.getByRole('button', { name: 'Hủy' });
    expect(body.closest('.ant-modal-body')).not.toContainElement(cancel);
    expect(cancel.closest('.ant-modal-footer')).not.toBeNull();
    expect(screen.queryByTestId('ui-modal-footer')).not.toBeInTheDocument();
  });

  it('preserves the native voucher footer actions while normalizing the body frame', () => {
    const onCancel = vi.fn();
    const onSave = vi.fn();
    const onSaveAndPrint = vi.fn();

    render(
      <MisaVoucherModal
        open
        onCancel={onCancel}
        onSave={onSave}
        onSaveAndPrint={onSaveAndPrint}
        title="Phiếu kế toán"
      >
        <input aria-label="Diễn giải" defaultValue="Điều chỉnh" />
      </MisaVoucherModal>,
    );

    expect(screen.getByTestId('ui-modal-frame')).toContainElement(screen.getByLabelText('Diễn giải'));
    expect(screen.getByRole('button', { name: 'Hủy' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Cất' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /Cất và In/ })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Cất' }).closest('.ant-modal-footer')).not.toBeNull();
  });

  it('uses shared voucher header/footer classes and button variants instead of inline layout styles', () => {
    expect(misaVoucherModalSource).not.toContain('style={{');

    render(
      <MisaVoucherModal
        open
        onCancel={vi.fn()}
        onSave={vi.fn()}
        onSaveAndPrint={vi.fn()}
        title="Phiếu kế toán"
      >
        <div>Chi tiết chứng từ</div>
      </MisaVoucherModal>,
    );

    const header = screen.getByText('Phiếu kế toán').closest('.misa-modal-header-wrapper');
    expect(header).not.toBeNull();
    expect(header?.querySelector('.misa-voucher-modal__header-main')).toBeInTheDocument();
    expect(header?.querySelector('.misa-voucher-modal__header-actions')).toBeInTheDocument();

    expect(screen.getByRole('button', { name: 'Hủy' })).toHaveClass('misa-btn-secondary');
    expect(screen.getByRole('button', { name: 'Cất' })).toHaveClass('misa-btn-secondary');
    expect(screen.getByRole('button', { name: /Cất và In/ })).toHaveClass('misa-btn-primary');
  });

  it('frames unavailable Excel import content without enabling its unavailable actions', () => {
    render(<ExcelImportModal open onClose={vi.fn()} voucherType="receipt" />);

    const frame = screen.getByTestId('ui-modal-frame');
    expect(frame).toContainElement(screen.getByTestId('ui-modal-body'));
    expect(screen.getByText('Nhập Excel chưa khả dụng')).toBeInTheDocument();

    const footer = screen.getByTestId('ui-modal-footer');
    expect(within(footer).getByRole('button', { name: /Tiếp tục/ })).toBeDisabled();
  });

  it('marks quick-add bank as a simple responsive modal family without changing its form fields', () => {
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });

    render(
      <QueryClientProvider client={queryClient}>
        <QuickAddBankAccountModal open onCancel={vi.fn()} />
      </QueryClientProvider>,
    );

    const frame = screen.getByTestId('ui-modal-frame');
    expect(frame).toHaveClass('ui-modal-frame--simple', 'misa-quick-bank-modal__frame');
    expect(frame.querySelector('.misa-bank-form-layout')).toHaveClass('ui-modal-form-grid--responsive');
    expect(frame.querySelector('.misa-bank-form-layout .ant-row')).toBeInTheDocument();
  });

  it('keeps quick-add bank actions outside the scrolling modal body', () => {
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });

    render(
      <QueryClientProvider client={queryClient}>
        <QuickAddBankAccountModal open onCancel={vi.fn()} />
      </QueryClientProvider>,
    );

    const frame = screen.getByTestId('ui-modal-frame');
    const body = screen.getByTestId('ui-modal-body');
    const footer = screen.getByTestId('ui-modal-footer');

    expect(body).not.toContainElement(screen.getByRole('button', { name: 'Hủy' }));
    expect(footer).toContainElement(screen.getByRole('button', { name: 'Hủy' }));
    expect(footer).toContainElement(screen.getByRole('button', { name: 'Cất' }));
    expect(frame.querySelector('.misa-modal-footer-custom')).toBeNull();
  });

  it.each([
    ['unit', <QuickAddUnitModal open onCancel={vi.fn()} />],
    ['item-category', <QuickAddItemCategoryModal open onCancel={vi.fn()} />],
    ['payment-term', <QuickAddPaymentTermModal open onCancel={vi.fn()} />],
    ['warehouse', <QuickAddWarehouseModal open onCancel={vi.fn()} />],
    ['employee', <QuickAddEmployeeModal open onCancel={vi.fn()} />],
  ])('uses the shared frame and command footer for quick-add %s', (_name, modal) => {
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });

    render(<QueryClientProvider client={queryClient}>{modal}</QueryClientProvider>);

    const frame = screen.getByTestId('ui-modal-frame');
    const body = screen.getByTestId('ui-modal-body');
    const cancel = screen.getByRole('button', { name: 'Hủy' });
    const saveAndAdd = screen.getByRole('button', { name: 'Cất và Thêm' });

    expect(frame).toHaveClass('ui-modal-frame--simple');
    expect(body.querySelector('form')).toBeInTheDocument();
    expect(body).not.toContainElement(cancel);
    expect(cancel.closest('.ant-modal-footer')).not.toBeNull();
    expect(cancel).toHaveClass('misa-btn-secondary');
    expect(saveAndAdd).toHaveClass('misa-btn-primary');
    cleanup();
  });

  it('keeps the default-account drawer layout in named regions instead of inline viewport positioning', () => {
    expect(reasonModalSource).toContain('misa-reason-drawer__header');
    expect(reasonModalSource).toContain('misa-reason-drawer__body');
    expect(reasonModalSource).toContain('misa-reason-drawer__footer');
    expect(reasonModalSource).not.toContain("position: 'sticky'");
    expect(reasonModalSource).not.toContain("height: 'calc(100vh - 116px)'");
    expect(reasonModalSource).toContain('variant="primary"');
  });

  it('uses shared command buttons for contact and item quick-add modal footers', () => {
    expect(contactModalSource).toContain("import { MisaButton } from './MisaButton';");
    expect(itemModalSource).toContain("import { MisaButton } from './MisaButton';");
    expect(contactModalSource).toMatch(/<MisaButton[\s\S]*?variant="primary"/);
    expect(itemModalSource).toMatch(/<MisaButton[\s\S]*?variant="primary"/);
  });

  it('keeps representative modal families within their width contracts', () => {
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });

    const { unmount } = render(
      <QueryClientProvider client={queryClient}>
        <QuickAddBankAccountModal open onCancel={vi.fn()} />
      </QueryClientProvider>,
    );
    expect(screen.getByTestId('ui-modal-frame').closest('.ant-modal')).toHaveStyle({ width: '640px' });
    unmount();

    render(
      <QueryClientProvider client={queryClient}>
        <CollectMultiCustomerModal open onClose={vi.fn()} />
      </QueryClientProvider>,
    );
    expect(screen.getByTestId('ui-modal-frame').closest('.ant-modal')).toHaveStyle({ width: '1040px' });
    expect(screen.getByTestId('ui-modal-frame')).toHaveClass('misa-collect-multi-customer-modal__frame');
    cleanup();

    render(<BankVoucherPrintModal open onClose={vi.fn()} />);
    expect(screen.getByTestId('ui-modal-frame').closest('.ant-modal')).toHaveStyle({ width: '880px' });
    expect(screen.getByTestId('ui-modal-frame')).toHaveClass('misa-bank-voucher-print-modal__frame');
  });
});
