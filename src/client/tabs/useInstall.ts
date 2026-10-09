import { useMutation, useQueryClient } from '@tanstack/react-query';
import { httpErrorToHuman, toast } from '@pterodactyl/sdk';
import { install, type ContentType } from '../api';
import { useServerIds } from '../hooks';

export function useInstall(type: ContentType) {
    const { identifier } = useServerIds();
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: ({ source, project, version }: { source: string; project: string; version?: string }) => install(identifier, type, source, project, version),
        onSuccess: ({ installed }) => {
            const [first, ...dependencies] = installed;
            toast.success(
                `Installed ${first.name} ${first.version_name}${dependencies.length ? ` with ${dependencies.map((item) => item.name).join(', ')}` : ''}. Restart the server to load it.`
            );
            queryClient.invalidateQueries({ queryKey: ['minecraft-content', identifier] });
        },
        onError: (error) => toast.error(httpErrorToHuman(error)),
    });
}
