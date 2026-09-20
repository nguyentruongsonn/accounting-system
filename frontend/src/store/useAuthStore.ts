import { create } from 'zustand';

interface User {
    id: number;
    name: string;
    email: string;
    roles?: string[];
    permissions?: string[];
}

interface AuthState {
    user: User | null;
    token: string | null;
    isAuthenticated: boolean;
    authReady: boolean;
    setAuth: (user: User, token: string) => void;
    markAuthReady: () => void;
    logout: () => void;
}

// Access tokens are intentionally memory-only. A hard reload or a new tab is
// rehydrated through the HttpOnly refresh cookie in AuthSessionBootstrap.
const initialToken: string | null = null;
const initialUser: User | null = null;
const initialIsAuth = false;

export const useAuthStore = create<AuthState>((set) => ({
    user: initialUser,
    token: initialToken,
    isAuthenticated: initialIsAuth,
    authReady: initialIsAuth,
    setAuth: (user, token) => {
        set({ user, token, isAuthenticated: true, authReady: true });
    },
    markAuthReady: () => {
        set({ authReady: true });
    },
    logout: () => {
        set({ user: null, token: null, isAuthenticated: false, authReady: true });
    },
}));
