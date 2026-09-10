import axios from 'axios';
import { handleSessionExpired } from '../auth/session';

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
    withCredentials: false, // Using Bearer token instead of cookies
});

api.interceptors.request.use((config) => {
    const token = localStorage.getItem('token');
    if (token) {
        config.headers.Authorization = `Bearer ${token}`;
    }
    return config;
});

api.interceptors.response.use(
    (response) => response,
    (error) => {
        const hadBearerToken = Boolean(error.config?.headers?.Authorization);

        if (error.response?.status === 401 && hadBearerToken) {
            handleSessionExpired();
        }
        return Promise.reject(error);
    }
);

export default api;
