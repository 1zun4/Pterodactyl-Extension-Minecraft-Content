import { useQuery } from '@tanstack/react-query';
import { Button, Dialog, Spinner, httpErrorToHuman } from '@pterodactyl/sdk';
import { getVersions, keys, type ContentType, type Project, type Version } from '../api';
import { useServerIds } from '../hooks';
import { formatBytes } from '../lib/format';
import Pill from '../components/Pill';

interface Props {
    type: ContentType;
    project: Project | null;
    onClose: () => void;
    onPick: (version: Version) => void;
    pending: boolean;
    action?: string;
}

const TONES = { release: 'success', beta: 'warning', alpha: 'danger' } as const;

export default function VersionsDialog({ type, project, onClose, onPick, pending, action = 'Install' }: Props) {
    const { identifier } = useServerIds();
    const versions = useQuery({
        queryKey: keys.versions(identifier, type, project?.source ?? '', project?.id ?? ''),
        queryFn: () => getVersions(identifier, type, project!.source, project!.id),
        enabled: project !== null,
    });

    return (
        <Dialog open={project !== null} onClose={onClose} title={project ? `${project.name} versions` : ''} description='Only versions that fit this server are listed.'>
            {versions.isLoading ? (
                <Spinner centered />
            ) : versions.isError ? (
                <p className='mcc:text-sm mcc:text-destructive'>{httpErrorToHuman(versions.error)}</p>
            ) : versions.data?.versions.length === 0 ? (
                <p className='mcc:text-sm mcc:text-muted-foreground'>No version of this project fits the server.</p>
            ) : (
                <ul className='mcc:grid mcc:max-h-96 mcc:gap-2 mcc:overflow-y-auto'>
                    {versions.data?.versions.slice(0, 50).map((version) => (
                        <li key={version.id} className='mcc:flex mcc:items-center mcc:gap-3 mcc:rounded-sm mcc:border mcc:border-border mcc:p-2'>
                            <div className='mcc:min-w-0 mcc:flex-1'>
                                <p className='mcc:flex mcc:items-center mcc:gap-2 mcc:text-sm mcc:text-foreground'>
                                    <span className='mcc:truncate'>{version.name}</span>
                                    <Pill tone={TONES[version.type] ?? 'muted'}>{version.type}</Pill>
                                </p>
                                <p className='mcc:truncate mcc:text-xs mcc:text-muted-foreground'>
                                    {[version.loaders.join(', '), version.game_versions.slice(-3).join(', '), version.file ? formatBytes(version.file.size) : null]
                                        .filter(Boolean)
                                        .join(' · ')}
                                </p>
                            </div>
                            <Button size='small' disabled={pending || (version.file === null && type !== 'modpack')} onClick={() => onPick(version)}>
                                {version.file === null && type !== 'modpack' ? 'Not allowed' : action}
                            </Button>
                        </li>
                    ))}
                </ul>
            )}
        </Dialog>
    );
}
