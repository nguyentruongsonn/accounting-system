import React from 'react';
import { useAuthStore } from '../store/useAuthStore';

interface RequirePermissionProps {
    permission: string;
    children: React.ReactNode;
    fallback?: React.ReactNode;
}

export const RequirePermission: React.FC<RequirePermissionProps> = ({ 
    permission, 
    children, 
    fallback = null 
}) => {
    const { user } = useAuthStore();

    // The backend returns the effective permission set in the auth contract and
    // enforces the same permission server-side.  Do not infer authority from a
    // role name here: a stale/partial client payload must fail closed, and the
    // UI must not advertise a posting action that the API will reject.
    const hasPermission = user?.permissions?.includes(permission) === true;

    if (hasPermission) {
        return <>{children}</>;
    }

    return <>{fallback}</>;
};
