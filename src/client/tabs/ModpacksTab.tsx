import { useState } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { Alert, Button, Dialog, Label, Select, Switch, httpErrorToHuman, toast, useServerPermission } from '@pterodactyl/sdk';
import { installModpack, uninstallModpack, type Cleanup, type Project, type SourceName, type Tracked, type Version } from '../api';
import { formatDate } from '../lib/format';
import { useServerIds } from '../hooks';
import Browse from '../content/Browse';
import VersionsDialog from '../content/VersionsDialog';
import CurrentCard from '../components/CurrentCard';

export default function ModpacksTab({ current, sources, busy, cleanup }: { current: Tracked | null; sources: SourceName[]; busy: boolean; cleanup: Cleanup }) {
    const { identifier } = useServerIds();
    const queryClient = useQueryClient();
    const canInstall = useServerPermission('ext.minecraft-content.modpacks');
    const [project, setProject] = useState<Project | null>(null);
    const [choice, setChoice] = useState<{ project: Project; version: Version } | null>(null);
    const tracked = typeof current?.file_count === 'number';
    const [options, setOptions] = useState({ backup: true, cleanup: tracked || cleanup === 'keep' ? cleanup : ('full' as Cleanup), start: true });
    const [removing, setRemoving] = useState(false);
    const [removalBackup, setRemovalBackup] = useState(true);
    const cleanups = [
        ...(tracked ? [{ value: 'smart', label: `Remove only the ${current!.file_count} files of ${current!.name}` }] : []),
        { value: 'full', label: 'Remove mods, config and similar folders' },
        { value: 'keep', label: 'Keep all files' },
    ];
    const onError = (error: unknown) => toast.error(httpErrorToHuman(error));
    const refresh = () => queryClient.invalidateQueries({ queryKey: ['minecraft-content', identifier] });

    const installation = useMutation({
        mutationFn: () => installModpack(identifier, { source: choice!.project.source, project: choice!.project.id, version: choice!.version.id, ...options }),
        onSuccess: () => (setChoice(null), refresh()),
        onError,
    });
    const removal = useMutation({ mutationFn: () => uninstallModpack(identifier, removalBackup), onSuccess: () => (setRemoving(false), refresh()), onError });

    return (
        <div className='mcc:grid mcc:grid-cols-1 mcc:gap-4'>
            <CurrentCard
                title='Installed modpack'
                empty='No modpack was installed from the panel.'
                item={current}
                hint={current ? [tracked && `${current.file_count} files`, `installed ${formatDate(current.installed_at)}`].filter(Boolean).join(' · ') : undefined}
                action={
                    current &&
                    canInstall && (
                        <>
                            <Button.Text size='xsmall' disabled={busy} onClick={() => setProject(asProject(current))}>
                                Switch version
                            </Button.Text>
                            {tracked && (
                                <Button.Danger size='xsmall' disabled={busy} onClick={() => setRemoving(true)}>
                                    Uninstall
                                </Button.Danger>
                            )}
                        </>
                    )
                }
            />
            <Browse
                type='modpack'
                sources={sources}
                installed={new Set(current ? [current.project] : [])}
                actions={(item) => (
                    <Button size='small' disabled={!canInstall || busy} onClick={() => setProject(item)}>
                        Install
                    </Button>
                )}
            />
            <VersionsDialog
                type='modpack'
                project={project}
                onClose={() => setProject(null)}
                pending={false}
                action='Choose'
                onPick={(version) => {
                    setChoice({ project: project!, version });
                    setProject(null);
                }}
            />
            <Dialog open={choice !== null} onClose={() => setChoice(null)} title={choice ? `Install ${choice.project.name}` : ''} preventExternalClose={installation.isPending}>
                {choice && (
                    <div className='mcc:grid mcc:gap-4'>
                        <p className='mcc:text-sm mcc:text-muted-foreground'>
                            {choice.version.name} for {[choice.version.loaders.join(', '), choice.version.game_versions.join(', ')].filter(Boolean).join(' ')}
                        </p>
                        <Alert type='warning'>The server stops, and its server software is replaced by the one the modpack needs. Worlds are kept.</Alert>
                        <div>
                            <Label htmlFor='mcc-cleanup'>Before installing</Label>
                            <Select id='mcc-cleanup' value={options.cleanup} onChange={(value) => setOptions((current) => ({ ...current, cleanup: value as Cleanup }))} options={cleanups} />
                        </div>
                        <Switch label='Back up first' description='Needs a free backup slot.' checked={options.backup} onChange={(backup) => setOptions((current) => ({ ...current, backup }))} />
                        <Switch label='Start the server when done' checked={options.start} onChange={(start) => setOptions((current) => ({ ...current, start }))} />
                    </div>
                )}
                <Dialog.Footer>
                    <Button.Text className='mcc:w-full mcc:sm:w-auto' disabled={installation.isPending} onClick={() => setChoice(null)}>
                        Cancel
                    </Button.Text>
                    <Button className='mcc:w-full mcc:sm:w-auto' isLoading={installation.isPending} onClick={() => installation.mutate()}>
                        Install
                    </Button>
                </Dialog.Footer>
            </Dialog>
            <Dialog.Confirm
                open={removing}
                onClose={() => setRemoving(false)}
                title={`Uninstall ${current?.name ?? ''}`}
                confirm='Uninstall'
                pending={removal.isPending}
                onConfirmed={() => removal.mutate()}
            >
                <p className='mcc:mb-4'>
                    The server stops and the {current?.file_count} files the pack added are deleted. Worlds, your own files and the server software stay.
                </p>
                <Switch label='Back up first' description='Needs a free backup slot.' checked={removalBackup} onChange={setRemovalBackup} />
            </Dialog.Confirm>
        </div>
    );
}

function asProject(tracked: Tracked): Project {
    return { source: tracked.source, id: tracked.project, slug: '', name: tracked.name, author: '', description: '', icon: tracked.icon, downloads: 0, url: tracked.url ?? '' };
}
