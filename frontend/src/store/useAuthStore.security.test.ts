import { afterEach, describe, expect, it, vi } from 'vitest';
import { useAuthStore } from './useAuthStore';
import api from '../api/axios';

const user = { id: 1, name: 'Admin', email: 'admin@example.test' };

describe('browser auth token storage', () => {
  afterEach(() => {
    localStorage.clear();
    useAuthStore.getState().logout();
    vi.restoreAllMocks();
  });

  it('keeps bearer tokens in memory instead of writing Web Storage', () => {
    localStorage.setItem('token', 'old-persisted-token');
    const setItem = vi.spyOn(Storage.prototype, 'setItem');

    useAuthStore.getState().setAuth(user, 'memory-only-token');

    expect(useAuthStore.getState().token).toBe('memory-only-token');
    expect(localStorage.getItem('token')).toBe('old-persisted-token');
    expect(setItem).not.toHaveBeenCalled();
  });

  it('starts unauthenticated after storage contains a stale token', () => {
    localStorage.setItem('token', 'stale-token');
    useAuthStore.setState({ user: null, token: null, isAuthenticated: false });

    expect(useAuthStore.getState().isAuthenticated).toBe(false);
    expect(useAuthStore.getState().token).toBeNull();
  });

  it('attaches the in-memory token and ignores a stale stored token', async () => {
    localStorage.setItem('token', 'stale-token');
    useAuthStore.setState({ user, token: 'memory-only-token', isAuthenticated: true });
    const authorizationHeaders: string[] = [];

    await api.get('/security-test', {
      adapter: async (config) => {
        authorizationHeaders.push(String(config.headers?.Authorization ?? ''));
        return { data: { ok: true }, status: 200, statusText: 'OK', headers: {}, config };
      },
    });

    expect(authorizationHeaders).toEqual(['Bearer memory-only-token']);
  });
});
