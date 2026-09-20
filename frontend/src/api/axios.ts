import axios from 'axios';
import type { InternalAxiosRequestConfig } from 'axios';
import { handleSessionExpired } from '../auth/session';
import { useAuthStore } from '../store/useAuthStore';
import { getAccountingDataScope, notifyDataChanged } from '../lib/queryClient';

const configuredApiUrl = String(import.meta.env.VITE_API_URL || '').trim();
// Never ship the developer's localhost API fallback in a production bundle.
// A same-origin relative API is a safe web deployment default; desktop builds
// must provide an owner-approved VITE_API_URL and matching Tauri connect-src.
const apiBaseUrl = configuredApiUrl || '/api/v1';

const api = axios.create({
    baseURL: apiBaseUrl,
    // Bearer credentials must remain bound to the configured API origin even
    // if a future caller accidentally supplies an absolute URL.
    allowAbsoluteUrls: false,
    headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
    },
    withCredentials: true, // Refresh token is an HttpOnly cookie.
});

type RetryableRequestConfig = InternalAxiosRequestConfig & { _authRetry?: boolean };
let refreshPromise: Promise<string> | null = null;

const refreshAccessToken = async (): Promise<string> => {
    if (!refreshPromise) {
        refreshPromise = api.post('/auth/refresh')
            .then(({ data }) => {
                const token = data?.token;
                const user = data?.user;
                if (typeof token !== 'string' || token.trim() === '' || !user || user.id == null) {
                    throw new Error('Refresh response did not contain a valid session');
                }
                useAuthStore.getState().setAuth(user, token);
                return token;
            })
            .finally(() => { refreshPromise = null; });
    }
    return refreshPromise;
};

api.interceptors.request.use((config) => {
    const token = useAuthStore.getState().token;
    if (token) {
        config.headers.Authorization = `Bearer ${token}`;
    }
    return config;
});

api.interceptors.response.use(
    (response) => {
        const method = response.config?.method?.toLowerCase();
        const url = String(response.config?.url || '');
        if (method && ['post', 'put', 'patch', 'delete'].includes(method)) {
            const isAuthOrExport = url.includes('/auth/login')
                || url.includes('/auth/refresh')
                || url.includes('/auth/logout')
                || url.includes('/export')
                || url.includes('/print');
            if (!isAuthOrExport) {
                notifyDataChanged(getAccountingDataScope(url));
            }
        }
        return response;
    },
    (error) => {
        const hadBearerToken = Boolean(error.config?.headers?.Authorization);

        const originalRequest = error.config as RetryableRequestConfig | undefined;
        const isRefreshRequest = String(originalRequest?.url || '').includes('/auth/refresh');

        if (error.response?.status === 401 && hadBearerToken && originalRequest && !originalRequest._authRetry && !isRefreshRequest) {
            originalRequest._authRetry = true;
            return refreshAccessToken()
                .then((token) => {
                    originalRequest.headers.Authorization = `Bearer ${token}`;
                    return api.request(originalRequest);
                })
                .catch((refreshError) => {
                    handleSessionExpired();
                    return Promise.reject(refreshError);
                });
        }

        if (error.response?.status === 401 && hadBearerToken && !isRefreshRequest) {
            handleSessionExpired();
        }
        return Promise.reject(error);
    }
);

export default api;
