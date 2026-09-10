import { QueryClient } from '@tanstack/react-query';

export const queryClient = new QueryClient({
    defaultOptions: {
        queries: {
            refetchOnWindowFocus: false,
            refetchOnMount: false,
            refetchOnReconnect: false,
            retry: 1,
            staleTime: 5 * 60 * 1000,
            gcTime: 15 * 60 * 1000,
        },
    },
});
