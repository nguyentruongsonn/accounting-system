import { useState, useCallback, useEffect } from 'react';
import api from '../api/axios';

export interface UseAutoVoucherNumberOptions {
    prefix?: string;
    endpoint?: string;
    enabled?: boolean;
    initialValue?: string;
}

export function useAutoVoucherNumber({
    endpoint,
    enabled = true,
    initialValue = ''
}: UseAutoVoucherNumberOptions = {}) {
    const [voucherNumber, setVoucherNumber] = useState<string>(initialValue);
    const [isLoading, setIsLoading] = useState<boolean>(false);

    const fetchNextCode = useCallback(async () => {
        if (!enabled) return;

        setIsLoading(true);
        try {
            if (endpoint) {
                const { data } = await api.get(endpoint);
                const code = data?.data?.code || data?.code || data?.voucher_number;
                if (code) {
                    setVoucherNumber(code);
                    return code;
                }
            }
            // A voucher number is accounting/source evidence. If the server
            // cannot issue one, keep the field empty and let the server-side
            // contract either allocate it transactionally or reject the save.
            // Never fabricate a number in the browser.
            setVoucherNumber('');
            return '';
        } catch {
            setVoucherNumber('');
            return '';
        } finally {
            setIsLoading(false);
        }
    }, [endpoint, enabled]);

    useEffect(() => {
        if (enabled && !voucherNumber) {
            fetchNextCode();
        }
    }, [enabled, voucherNumber, fetchNextCode]);

    return {
        voucherNumber,
        setVoucherNumber,
        isLoading,
        refreshVoucherNumber: fetchNextCode
    };
}

export default useAutoVoucherNumber;
