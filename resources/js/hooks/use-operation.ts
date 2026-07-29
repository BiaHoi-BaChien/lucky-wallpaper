import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';

export interface Operation {
    id: string;
    type?: string;
    status: 'queued' | 'running' | 'succeeded' | 'failed';
    total?: number;
    processed?: number;
    imported?: number;
    skipped_existing?: number;
    skipped_invalid?: number;
    skipped_empty_body?: number;
    error_code?: string | null;
    retryable?: boolean;
    warnings?: string[] | null;
}

export function useOperation(initial?: Operation | null) {
    const [operation, setOperation] = useState<Operation | null>(initial ?? null);
    const operationId = operation?.id;
    const operationStatus = operation?.status;

    useEffect(() => {
        if (initial && initial.id !== operationId) {
            setOperation(initial);
        }
    }, [initial, operationId]);

    useEffect(() => {
        if (!operationId || !operationStatus || !['queued', 'running'].includes(operationStatus)) {
            return;
        }

        const timer = window.setInterval(async () => {
            const response = await fetch(route('operations.show', { id: operationId }), {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
            });
            if (!response.ok) {
                return;
            }
            const next = (await response.json()) as Operation;
            setOperation(next);
            if (['succeeded', 'failed'].includes(next.status)) {
                window.clearInterval(timer);
                router.reload();
            }
        }, 3000);

        return () => window.clearInterval(timer);
    }, [operationId, operationStatus]);

    return { operation, setOperation };
}
