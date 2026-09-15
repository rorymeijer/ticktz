import { usePage } from '@inertiajs/react';

import type { SharedProps, User } from '@/types';

/**
 * Convenience accessor for the signed-in user plus the permission helpers the
 * navigation uses. Server-side policies remain the source of truth — these
 * checks only decide what to *render*.
 */
export function useAuth() {
    const user = usePage<SharedProps>().props.auth.user;

    const can = (permission: string): boolean =>
        Boolean(user?.permissions.includes('*') || user?.permissions.includes(permission));

    const canAny = (...permissions: string[]): boolean => permissions.some(can);

    return { user: user as User | null, can, canAny };
}
