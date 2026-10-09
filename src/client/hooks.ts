import { useEffect, useRef } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { toast, useCurrentServerRequired, useExtensionJobProgress } from '@pterodactyl/sdk';
import { getDetection, keys } from './api';

export function useServerIds() {
    const { identifier, uuid } = useCurrentServerRequired().attributes;

    return { identifier, uuid };
}

export function useDetection() {
    const { identifier } = useServerIds();

    return useQuery({ queryKey: keys.detect(identifier), queryFn: () => getDetection(identifier), staleTime: 30_000 });
}

/** Follows the server's running install and refreshes everything once it ends. */
export function useJob(job: string | null | undefined) {
    const { identifier, uuid } = useServerIds();
    const queryClient = useQueryClient();
    const progress = useExtensionJobProgress(job ?? undefined, { serverUuid: uuid });
    const status = progress.data?.status;
    const announced = useRef<string | null>(null);

    useEffect(() => {
        if (!job || !status || status === 'running' || announced.current === job) {
            return;
        }

        announced.current = job;
        status === 'completed' ? toast.success(progress.data!.message) : toast.error(progress.data!.message);
        queryClient.invalidateQueries({ queryKey: ['minecraft-content', identifier] });
    }, [job, status]);

    return progress;
}
