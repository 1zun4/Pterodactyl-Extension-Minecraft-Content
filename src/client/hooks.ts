import { useEffect, useRef, useState } from 'react';
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

/** Follows the server's running install and refreshes everything once it ends. Warnings stay as a notice until dismissed. */
export function useJob(job: string | null | undefined) {
    const { identifier, uuid } = useServerIds();
    const queryClient = useQueryClient();
    const progress = useExtensionJobProgress(job ?? undefined, { serverUuid: uuid });
    const status = progress.data?.status;
    const announced = useRef<string | null>(null);
    const [notice, setNotice] = useState<string | null>(null);

    useEffect(() => {
        if (!job || !status || status === 'running' || announced.current === job) {
            return;
        }

        announced.current = job;
        const { message } = progress.data!;

        if (status !== 'completed') {
            toast.error(message);
        } else if (/ finished\. ./.test(message)) {
            setNotice(message);
        } else {
            toast.success(message);
        }

        queryClient.invalidateQueries({ queryKey: ['minecraft-content', identifier] });
    }, [job, status]);

    return { progress: progress.data, notice, dismiss: () => setNotice(null) };
}
