import { beforeEach, describe, expect, it } from 'vitest';
import { useAuthStore } from './useAuthStore';

describe('useAuthStore', () => {
  beforeEach(() => {
    localStorage.clear();
    useAuthStore.setState({
      user: null,
      token: null,
      isAuthenticated: false,
      authReady: false,
    });
  });

  it('keeps the access token in memory instead of localStorage', () => {
    const user = { id: 1, name: 'Admin', email: 'admin@example.test' };

    useAuthStore.getState().setAuth(user, 'access-token');

    expect(useAuthStore.getState().token).toBe('access-token');
    expect(localStorage.getItem('accounting_auth_token')).toBeNull();
    expect(localStorage.getItem('accounting_auth_user')).toBeNull();
  });
});
