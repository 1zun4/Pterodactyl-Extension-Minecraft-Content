import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Button, Dialog, Switch, Table, Tooltip, httpErrorToHuman, toast, useServerPermission } from '@pterodactyl/sdk';
import { getInstalled, getUpdates, keys, remove, scan, toggle, update, type ContentType, type InstalledFile, type Version } from '../api';
import { useServerIds } from '../hooks';
import { SOURCE_NAMES, formatBytes } from '../lib/format';
import { MEDIUM, useMediaQuery } from '../lib/media';
import Pill from '../components/Pill';
import ProjectIcon from '../components/ProjectIcon';

export default function InstalledList({ type }: { type: ContentType }) {
    const { identifier } = useServerIds();
    const queryClient = useQueryClient();
    const canInstall = useServerPermission('ext.minecraft-content.install');
    const [removing, setRemoving] = useState<InstalledFile | null>(null);
    const wide = useMediaQuery(MEDIUM);

    const files = useQuery({ queryKey: keys.installed(identifier, type), queryFn: () => getInstalled(identifier, type) });
    const updates = useQuery({ queryKey: keys.updates(identifier, type), queryFn: () => getUpdates(identifier, type), enabled: false, retry: false });
    const refresh = () => queryClient.invalidateQueries({ queryKey: keys.installed(identifier, type) });
    const onError = (error: unknown) => toast.error(httpErrorToHuman(error));

    const removal = useMutation({
        mutationFn: (file: InstalledFile) => remove(identifier, type, file.path),
        onSuccess: (data) => {
            queryClient.setQueryData(keys.installed(identifier, type), data);
            setRemoving(null);
        },
        onError,
    });
    const switching = useMutation({
        mutationFn: (file: InstalledFile) => toggle(identifier, type, file.path, !file.enabled),
        onSuccess: (data) => queryClient.setQueryData(keys.installed(identifier, type), data),
        onError,
    });
    const updating = useMutation({
        mutationFn: async (paths: string[]) => {
            for (const path of paths) {
                await update(identifier, type, path);
            }
        },
        onSuccess: (_, paths) => {
            toast.success(paths.length === 1 ? 'Updated.' : `Updated ${paths.length} files.`);
            refresh();
            updates.refetch();
        },
        onError: (error) => {
            onError(error);
            refresh();
        },
    });
    const scanning = useMutation({
        mutationFn: async () => {
            let recognised = 0;
            let result;

            do {
                result = await scan(identifier, type);
                recognised += result.recognised.length;
            } while (result.remaining > 0);

            return recognised;
        },
        onSuccess: (recognised) => {
            toast.success(recognised > 0 ? `Recognised ${recognised} files.` : 'No more files could be recognised.');
            refresh();
        },
        onError,
    });

    const available = updates.data?.updates ?? {};
    const outdated = Object.keys(available);
    const rows = files.data?.files ?? [];
    const unknown = rows.filter((file) => !file.tracked).length;
    const empty = files.isLoading ? 'Loading...' : 'Nothing installed yet.';

    const toggleOf = (file: InstalledFile) => (
        <Switch aria-label={`Enable ${file.name}`} checked={file.enabled} disabled={!canInstall || switching.isPending} onChange={() => switching.mutate(file)} />
    );
    const actionsOf = (file: InstalledFile) => (
        <>
            {available[file.path] && (
                <Button size='small' disabled={updating.isPending} onClick={() => updating.mutate([file.path])}>
                    Update
                </Button>
            )}
            <Button.Danger size='small' onClick={() => setRemoving(file)}>
                Remove
            </Button.Danger>
        </>
    );

    return (
        <div className='mcc:grid mcc:grid-cols-1 mcc:gap-3'>
            <div className='mcc:flex mcc:flex-wrap mcc:items-center mcc:justify-end mcc:gap-2'>
                <Button.Text size='small' isLoading={updates.isFetching} onClick={() => updates.refetch()}>
                    Check for updates
                </Button.Text>
                {canInstall && unknown > 0 && (
                    <Tooltip content='Look up files that were uploaded by hand, so they can be updated too.'>
                        <Button.Text size='small' isLoading={scanning.isPending} onClick={() => scanning.mutate()}>
                            Recognise {unknown} {unknown === 1 ? 'file' : 'files'}
                        </Button.Text>
                    </Tooltip>
                )}
                {canInstall && outdated.length > 0 && (
                    <Button size='small' isLoading={updating.isPending} onClick={() => updating.mutate(outdated)}>
                        Update all ({outdated.length})
                    </Button>
                )}
            </div>
            {updates.isError && <p className='mcc:text-sm mcc:text-destructive'>{httpErrorToHuman(updates.error)}</p>}
            {wide ? (
                <Table
                    rows={rows}
                    keyOf={(file) => file.path}
                    isFetching={files.isFetching}
                    emptyState={empty}
                    columns={[
                        { id: 'name', label: 'Name', cell: (file) => <FileName file={file} /> },
                        { id: 'version', label: 'Version', cell: (file) => <FileVersion file={file} update={available[file.path]} /> },
                        { id: 'enabled', label: 'Enabled', cell: toggleOf },
                        { id: 'actions', label: '', cell: (file) => canInstall && <div className='mcc:flex mcc:justify-end mcc:gap-2'>{actionsOf(file)}</div> },
                    ]}
                />
            ) : rows.length === 0 ? (
                <p className='mcc:rounded-sm mcc:bg-card mcc:p-4 mcc:text-center mcc:text-sm mcc:text-muted-foreground mcc:shadow-md'>{empty}</p>
            ) : (
                <ul className={`mcc:grid mcc:grid-cols-1 mcc:gap-2 ${files.isFetching ? 'mcc:opacity-60' : ''}`}>
                    {rows.map((file) => (
                        <li key={file.path} className='mcc:rounded-sm mcc:bg-card mcc:p-3 mcc:shadow-md'>
                            <div className='mcc:flex mcc:items-center mcc:gap-3'>
                                <div className='mcc:min-w-0 mcc:flex-1'>
                                    <FileName file={file} />
                                </div>
                                {toggleOf(file)}
                            </div>
                            <div className='mcc:mt-2'>
                                <FileVersion file={file} update={available[file.path]} />
                            </div>
                            {canInstall && <div className='mcc:mt-3 mcc:flex mcc:gap-2 mcc:*:flex-1'>{actionsOf(file)}</div>}
                        </li>
                    ))}
                </ul>
            )}
            <Dialog.Confirm
                open={removing !== null}
                onClose={() => setRemoving(null)}
                title={`Remove ${removing?.name}?`}
                confirm='Remove'
                pending={removal.isPending}
                onConfirmed={() => removing && removal.mutate(removing)}
            >
                {removing?.file} is deleted from the server. Restart the server to unload it.
            </Dialog.Confirm>
        </div>
    );
}

function FileName({ file }: { file: InstalledFile }) {
    return (
        <div className='mcc:flex mcc:min-w-0 mcc:items-center mcc:gap-3'>
            <ProjectIcon src={file.icon} name={file.name} size={32} />
            <div className='mcc:min-w-0'>
                <p className='mcc:truncate mcc:text-foreground'>{file.url ? <a href={file.url} target='_blank' rel='noreferrer' className='mcc:hover:underline'>{file.name}</a> : file.name}</p>
                <p className='mcc:truncate mcc:text-xs mcc:text-muted-foreground'>{file.file}</p>
            </div>
        </div>
    );
}

function FileVersion({ file, update }: { file: InstalledFile; update?: Version }) {
    return (
        <div className='mcc:flex mcc:flex-wrap mcc:items-center mcc:gap-1'>
            <span className='mcc:min-w-0 mcc:break-words'>{file.version_name ?? formatBytes(file.size)}</span>
            {file.source ? <Pill>{SOURCE_NAMES[file.source]}</Pill> : <Pill tone='warning'>Unknown</Pill>}
            {update && <Pill tone='info'>{update.number || update.name} available</Pill>}
        </div>
    );
}
