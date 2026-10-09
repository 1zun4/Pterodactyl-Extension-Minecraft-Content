import { useState } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { Alert, Button, Dialog, Switch, httpErrorToHuman, toast, useServerPermission } from '@pterodactyl/sdk';
import { installModpack, type Project, type SourceName, type Tracked, type Version } from '../api';
import { useServerIds } from '../hooks';
import Browse from '../content/Browse';
import VersionsDialog from '../content/VersionsDialog';
import CurrentCard from '../components/CurrentCard';

export default function ModpacksTab({ current, sources, busy }: { current: Tracked | null; sources: SourceName[]; busy: boolean }) {
    const { identifier } = useServerIds();
    const queryClient = useQueryClient();
    const canInstall = useServerPermission('ext.minecraft-content.modpacks');
    const [project, setProject] = useState<Project | null>(null);
    const [choice, setChoice] = useState<{ project: Project; version: Version } | null>(null);
    const [options, setOptions] = useState({ backup: true, wipe: true, start: true });

    const installation = useMutation({
        mutationFn: () => installModpack(identifier, { source: choice!.project.source, project: choice!.project.id, version: choice!.version.id, ...options }),
        onSuccess: () => {
            setChoice(null);
            queryClient.invalidateQueries({ queryKey: ['minecraft-content', identifier] });
        },
        onError: (error) => toast.error(httpErrorToHuman(error)),
    });

    return (
        <div className='mcc:grid mcc:gap-4'>
            <CurrentCard title='Installed modpack' empty='No modpack was installed from the panel.' item={current} />
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
                        <Switch label='Back up first' description='Needs a free backup slot.' checked={options.backup} onChange={(backup) => setOptions((current) => ({ ...current, backup }))} />
                        <Switch label='Remove old mods and configs' description='Deletes mods, config, libraries and similar folders first.' checked={options.wipe} onChange={(wipe) => setOptions((current) => ({ ...current, wipe }))} />
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
        </div>
    );
}
