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
    setAuth: (user: User, token: string) => void;
    logout: () => void;
}

export const useAuthStore = create<AuthState>((set) => ({
    // The browser keeps the scoped, short-lived bearer token in Zustand memory
    // only. A hard refresh intentionally starts a new session; Web Storage is
    // readable by every script and is unsafe for authentication credentials.
    user: null,
    token: null,
    isAuthenticated: false,
    setAuth: (user, token) => {
        set({ user, token, isAuthenticated: true });
    },
    logout: () => {
        set({ user: null, token: null, isAuthenticated: false });
    },
}));
