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
    // TODO(auth-adr): replace Web Storage only when the backend and desktop
    // clients migrate end-to-end to HttpOnly cookies or OS secure storage.
    // The current backend contract issues scoped, short-lived bearer tokens.
    user: null,
    token: localStorage.getItem('token'),
    isAuthenticated: !!localStorage.getItem('token'),
    setAuth: (user, token) => {
        localStorage.setItem('token', token);
        set({ user, token, isAuthenticated: true });
    },
    logout: () => {
        localStorage.removeItem('token');
        set({ user: null, token: null, isAuthenticated: false });
    },
}));
