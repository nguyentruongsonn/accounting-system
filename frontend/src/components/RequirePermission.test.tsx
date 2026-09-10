import { render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import { RequirePermission } from './RequirePermission';
import { useAuthStore } from '../store/useAuthStore';

describe('RequirePermission', () => {
  afterEach(() => {
    useAuthStore.setState({ user: null, token: null, isAuthenticated: false });
  });

  it('fails closed while the user or permission payload is unavailable', () => {
    render(
      <RequirePermission permission="post_documents" fallback={<span>Không có quyền</span>}>
        <button>Ghi sổ</button>
      </RequirePermission>,
    );

    expect(screen.queryByRole('button', { name: 'Ghi sổ' })).not.toBeInTheDocument();
    expect(screen.getByText('Không có quyền')).toBeInTheDocument();
  });

  it('does not infer posting authority from a role without the effective permission', () => {
    useAuthStore.setState({
      user: {
        id: 1,
        name: 'Kế toán trưởng',
        email: 'chief@example.test',
        roles: ['chief_accountant'],
        permissions: [],
      },
      token: 'test-token',
      isAuthenticated: true,
    });

    render(
      <RequirePermission permission="post_documents" fallback={<span>Không có quyền</span>}>
        <button>Ghi sổ</button>
      </RequirePermission>,
    );

    expect(screen.queryByRole('button', { name: 'Ghi sổ' })).not.toBeInTheDocument();
    expect(screen.getByText('Không có quyền')).toBeInTheDocument();
  });

  it('renders the protected action only when the explicit permission is present', () => {
    useAuthStore.setState({
      user: {
        id: 1,
        name: 'Kế toán trưởng',
        email: 'chief@example.test',
        roles: ['chief_accountant'],
        permissions: ['post_documents'],
      },
      token: 'test-token',
      isAuthenticated: true,
    });

    render(
      <RequirePermission permission="post_documents" fallback={<span>Không có quyền</span>}>
        <button>Ghi sổ</button>
      </RequirePermission>,
    );

    expect(screen.getByRole('button', { name: 'Ghi sổ' })).toBeInTheDocument();
    expect(screen.queryByText('Không có quyền')).not.toBeInTheDocument();
  });
});
