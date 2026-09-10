import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import ModuleWorkspace from './ModuleWorkspace';

describe('ModuleWorkspace module header', () => {
  it('renders the provided module title above the tab workspace', () => {
    render(
      <ModuleWorkspace
        title="Báo cáo Kế toán"
        items={[{ key: 'trial-balance', label: 'Bảng cân đối tài khoản', children: <div /> }]}
      />,
    );

    expect(screen.getByTestId('module-workspace-title')).toHaveTextContent('Báo cáo Kế toán');
    expect(screen.getByRole('tab', { name: 'Bảng cân đối tài khoản' })).toBeInTheDocument();
  });

  it('does not add an empty header when no title is provided', () => {
    render(
      <ModuleWorkspace
        items={[{ key: 'one', label: 'Một', children: <div /> }]}
      />,
    );

    expect(screen.queryByTestId('module-workspace-title')).not.toBeInTheDocument();
  });

  it('exposes native workspace tabs with selected state and controlled panels', () => {
    render(
      <ModuleWorkspace
        nativeTabs
        activeKey="one"
        items={[
          { key: 'one', label: 'Một', children: <div>Nội dung một</div> },
          { key: 'two', label: 'Hai', children: <div>Nội dung hai</div> },
        ]}
      />,
    );

    const selectedTab = screen.getByRole('tab', { name: 'Một' });
    const otherTab = screen.getByRole('tab', { name: 'Hai' });
    expect(selectedTab).toHaveAttribute('aria-selected', 'true');
    expect(otherTab).toHaveAttribute('aria-selected', 'false');
    expect(selectedTab).toHaveAttribute('aria-controls', 'workspace-panel-one');
    expect(document.getElementById('workspace-panel-one')).toContainElement(screen.getByText('Nội dung một'));
  });
});
