import { queryClient } from '../lib/queryClient';
import { useAuthStore } from '../store/useAuthStore';

export const clearClientSession = () => {
    useAuthStore.getState().logout();
    queryClient.clear();
};

export const handleSessionExpired = () => {
    clearClientSession();

    if (window.location.pathname !== '/login') {
        window.history.replaceState(null, '', '/login');
        window.dispatchEvent(new PopStateEvent('popstate'));
    }
};
