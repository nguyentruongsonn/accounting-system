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

const STORAGE_KEY_TOKEN = 'accounting_auth_token';
const STORAGE_KEY_USER = 'accounting_auth_user';

const getStoredToken = (): string | null => {
    try {
        const token = localStorage.getItem(STORAGE_KEY_TOKEN);
        return typeof token === 'string' && token.trim() !== '' ? token : null;
    } catch {
        return null;
    }
};

const getStoredUser = (): User | null => {
    try {
        const raw = localStorage.getItem(STORAGE_KEY_USER);
        if (!raw) return null;
        const parsed = JSON.parse(raw);
        return parsed && typeof parsed.id === 'number' ? parsed : null;
    } catch {
        return null;
    }
};

const initialToken = getStoredToken();
const initialUser = getStoredUser();
const initialIsAuth = Boolean(initialToken && initialUser);

export const useAuthStore = create<AuthState>((set) => ({
    user: initialUser,
    token: initialToken,
    isAuthenticated: initialIsAuth,
    authReady: initialIsAuth,
    setAuth: (user, token) => {
        try {
            localStorage.setItem(STORAGE_KEY_TOKEN, token);
            localStorage.setItem(STORAGE_KEY_USER, JSON.stringify(user));
        } catch {
            // Web storage may be unavailable in restricted environments
        }
        set({ user, token, isAuthenticated: true, authReady: true });
    },
    markAuthReady: () => {
        set({ authReady: true });
    },
    logout: () => {
        try {
            localStorage.removeItem(STORAGE_KEY_TOKEN);
            localStorage.removeItem(STORAGE_KEY_USER);
        } catch {
            // Web storage may be unavailable in restricted environments
        }
        set({ user: null, token: null, isAuthenticated: false, authReady: true });
    },
}));
