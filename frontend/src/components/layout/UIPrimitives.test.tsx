import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import PageShell from './PageShell';
import PageToolbar from './PageToolbar';
import DataTableSurface from './DataTableSurface';
import ModalFrame from './ModalFrame';
import PageHeader from './PageHeader';

describe('shared UI shell primitives', () => {
  it('renders page shell regions in stable order without duplicating the toolbar marker', () => {
    render(
      <PageShell
        title={(
          <PageHeader
            eyebrow="Tiền mặt"
            title="Danh sách phiếu thu"
            description="Theo dõi các chứng từ thu tiền mặt."
          />
        )}
        toolbar={<PageToolbar leading={<span>toolbar</span>} />}
      >
        <span>body</span>
      </PageShell>,
    );

    const shell = screen.getByTestId('ui-page-shell');
    expect(shell).toHaveAttribute('data-surface', 'page');
    expect(shell.children[0]).toHaveAttribute('data-region', 'toolbar');
    expect(shell.children[1]).toHaveAttribute('data-ui', 'page-body');
    expect(shell.children[1]).toHaveAttribute('data-region', 'body');
    expect(shell.querySelectorAll('[data-ui="page-toolbar"]')).toHaveLength(1);
    expect(shell.querySelectorAll('[data-testid="ui-page-toolbar"]')).toHaveLength(1);
    expect(shell.querySelectorAll('[data-ui="table-surface"], .ant-card')).toHaveLength(0);
    expect(screen.queryByRole('heading', { level: 1, name: 'Danh sách phiếu thu' })).toBeNull();
    expect(screen.queryByText('Theo dõi các chứng từ thu tiền mặt.')).toBeNull();
  });

  it('does not render the removed page-header region for legacy text titles', () => {
    render(
      <PageShell title="Tiền mặt">
        <span>body</span>
      </PageShell>,
    );

    expect(screen.queryByRole('heading', { level: 1, name: 'Tiền mặt' })).toBeNull();
    expect(screen.queryByTestId('page-title-content')).toBeNull();
  });

  it('does not render the removed custom title region', () => {
    render(
      <PageShell title={<div data-testid="legacy-title">Legacy title content</div>}>
        <span>body</span>
      </PageShell>,
    );

    expect(screen.queryByTestId('legacy-title')).toBeNull();
  });

  it('renders toolbar slots in leading, filters, actions order', () => {
    render(
      <PageToolbar
        leading={<span>leading</span>}
        filters={<span>filters</span>}
        actions={<span>actions</span>}
      />,
    );

    const toolbar = screen.getByTestId('ui-page-toolbar');
    expect(toolbar).toHaveAttribute('data-layout', 'toolbar');
    expect(toolbar.children[0]).toHaveAttribute('data-ui', 'page-toolbar-leading');
    expect(toolbar.children[0]).toHaveAttribute('data-region', 'leading');
    expect(toolbar.children[1]).toHaveAttribute('data-ui', 'page-toolbar-filters');
    expect(toolbar.children[1]).toHaveAttribute('data-region', 'filters');
    expect(toolbar.children[2]).toHaveAttribute('data-ui', 'page-toolbar-actions');
    expect(toolbar.children[2]).toHaveAttribute('data-region', 'actions');
  });

  it('keeps table content and summary inside the table surface', () => {
    render(
      <DataTableSurface summary={<span>summary</span>}>
        <table>
          <tbody>
            <tr>
              <td>row</td>
            </tr>
          </tbody>
        </table>
      </DataTableSurface>,
    );

    const surface = screen.getByTestId('ui-table-surface');
    expect(surface).toHaveAttribute('data-surface', 'table');
    expect(surface.querySelectorAll('[data-ui="table-scroll"]')).toHaveLength(1);
    expect(surface).toContainElement(screen.getByTestId('ui-table-scroll'));
    expect(surface).toContainElement(screen.getByText('row'));
    expect(surface).toContainElement(screen.getByText('summary'));
  });

  it('labels table content and summary as stable semantic regions', () => {
    render(
      <DataTableSurface summary={<span>summary</span>}>
        <table>
          <tbody>
            <tr>
              <td>row</td>
            </tr>
          </tbody>
        </table>
      </DataTableSurface>,
    );

    const surface = screen.getByTestId('ui-table-surface');
    expect(screen.getByTestId('ui-table-scroll')).toHaveAttribute('data-region', 'table-content');
    expect(screen.getByTestId('ui-table-summary')).toHaveAttribute('data-region', 'summary');
    expect(surface.children[0]).toHaveAttribute('data-region', 'table-content');
    expect(surface.children[1]).toHaveAttribute('data-region', 'summary');
  });

  it('renders modal body and footer markers without owning form behaviour', () => {
    render(
      <ModalFrame footer={<button type="button">save</button>}>
        <input aria-label="field" defaultValue="draft value" />
      </ModalFrame>,
    );

    expect(screen.getByTestId('ui-modal-body')).toContainElement(screen.getByLabelText('field'));
    expect(screen.getByTestId('ui-modal-body')).toHaveAttribute('data-region', 'body');
    expect(screen.getByLabelText('field')).toHaveValue('draft value');
    expect(screen.getByTestId('ui-modal-footer')).toContainElement(
      screen.getByRole('button', { name: 'save' }),
    );
    expect(screen.getByTestId('ui-modal-footer')).toHaveAttribute('data-region', 'footer');
  });

  it('distinguishes omitted optional table and modal regions from explicitly empty regions', () => {
    const { rerender } = render(
      <DataTableSurface>
        <span>rows</span>
      </DataTableSurface>,
    );

    expect(screen.queryByTestId('ui-table-summary')).not.toBeInTheDocument();

    rerender(
      <DataTableSurface summary={null}>
        <span>rows</span>
      </DataTableSurface>,
    );

    expect(screen.getByTestId('ui-table-summary')).toBeEmptyDOMElement();

    rerender(
      <ModalFrame footer={null}>
        <span>content</span>
      </ModalFrame>,
    );

    expect(screen.getByTestId('ui-modal-footer')).toBeEmptyDOMElement();
  });

  it('renders page header hierarchy and actions in stable regions', () => {
    render(
      <PageHeader
        eyebrow="Thiết lập hệ thống"
        title="Thông tin công ty"
        description="Quản lý thông tin pháp lý của doanh nghiệp."
        extra={<button type="button">Lưu</button>}
      />,
    );

    const header = screen.getByTestId('page-title-content');
    expect(header).toHaveAttribute('data-ui', 'page-title-content');
    expect(header.querySelector('[data-region="eyebrow"]')).toHaveTextContent('Thiết lập hệ thống');
    expect(screen.getByRole('heading', { level: 1, name: 'Thông tin công ty' })).toHaveAttribute('data-region', 'title');
    expect(header.querySelector('[data-region="description"]')).toHaveTextContent('Quản lý thông tin pháp lý của doanh nghiệp.');
    expect(header.querySelector('[data-region="extra"]')).toContainElement(screen.getByRole('button', { name: 'Lưu' }));
  });

  it('rejects a missing PageHeader title instead of rendering an empty heading', () => {
    expect(() => render(<PageHeader title={null as never} />)).toThrow('PageHeader requires a title.');
  });

  it.each([
    ['', 'an empty string'],
    [' \t ', 'a whitespace-only string'],
    [false as never, 'false'],
  ])('rejects %s as a semantically empty PageHeader title', (title) => {
    expect(() => render(<PageHeader title={title} />)).toThrow('PageHeader requires a title.');
  });
});
