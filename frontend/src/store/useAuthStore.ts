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

export const useAuthStore = create<AuthState>((set) => ({
    // The browser keeps only the scoped, short-lived bearer token in memory.
    // AuthSessionBootstrap rehydrates it through the HttpOnly refresh cookie;
    // Web Storage is intentionally never used for authentication credentials.
    user: null,
    token: null,
    isAuthenticated: false,
    authReady: false,
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
