import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Alert, Button, Dialog, Input, Label, Select, Switch, Table, TitledGreyBox, httpErrorToHuman, toast, useServerPermission } from '@pterodactyl/sdk';
import { activateWorld, createWorld, deleteWorld, getWorlds, importWorld, keys, resetDimension, searchWorlds, type World, type WorldImport, type WorldList } from '../api';
import { useServerIds } from '../hooks';
import { formatBytes, formatDate } from '../lib/format';
import { MEDIUM, useMediaQuery } from '../lib/media';
import Pill from '../components/Pill';
import ProjectIcon from '../components/ProjectIcon';

type Mode = 'create' | 'url' | 'file' | 'curseforge';

const TYPES = [
    { value: 'normal', label: 'Default' },
    { value: 'flat', label: 'Superflat' },
    { value: 'large_biomes', label: 'Large Biomes' },
    { value: 'amplified', label: 'Amplified' },
];

const NAME = /^[A-Za-z0-9][A-Za-z0-9_.-]{0,63}$/;

export default function WorldsTab({ busy }: { busy: boolean }) {
    const { identifier } = useServerIds();
    const queryClient = useQueryClient();
    const canManage = useServerPermission('ext.minecraft-content.worlds');
    const wide = useMediaQuery(MEDIUM);
    const worlds = useQuery({ queryKey: keys.worlds(identifier), queryFn: () => getWorlds(identifier) });
    const [restart, setRestart] = useState<string | null>(null);
    const [removing, setRemoving] = useState<World | null>(null);
    const [resetting, setResetting] = useState<{ world: World; dimension: 'nether' | 'end' } | null>(null);

    const update = (data: WorldList) => queryClient.setQueryData(keys.worlds(identifier), (current: WorldList | undefined) => ({ ...current, ...data }));
    const onError = (error: unknown) => toast.error(httpErrorToHuman(error));
    const running = !['offline', 'stopped'].includes(worlds.data?.state ?? 'offline');
    const rows = worlds.data?.worlds ?? [];
    const empty = worlds.isLoading ? 'Loading...' : worlds.isError ? httpErrorToHuman(worlds.error) : 'No worlds yet.';

    const activation = useMutation({ mutationFn: (name: string) => activateWorld(identifier, name), onSuccess: (data, name) => (update(data), setRestart(name)), onError });
    const removal = useMutation({ mutationFn: (name: string) => deleteWorld(identifier, name), onSuccess: (data) => (update(data), setRemoving(null)), onError });
    const reset = useMutation({
        mutationFn: ({ world, dimension }: { world: World; dimension: 'nether' | 'end' }) => resetDimension(identifier, world.name, dimension),
        onSuccess: (data) => (update(data), setResetting(null), toast.success('The dimension is generated again on the next start.')),
        onError,
    });

    const actionsOf = (world: World) =>
        canManage &&
        !world.pending && (
            <>
                {!world.active && (
                    <Button.Text size='xsmall' disabled={activation.isPending} onClick={() => activation.mutate(world.name)}>
                        Load
                    </Button.Text>
                )}
                {world.nether && (
                    <Button.Text size='xsmall' disabled={running} onClick={() => setResetting({ world, dimension: 'nether' })}>
                        Reset Nether
                    </Button.Text>
                )}
                {world.end && (
                    <Button.Text size='xsmall' disabled={running} onClick={() => setResetting({ world, dimension: 'end' })}>
                        Reset End
                    </Button.Text>
                )}
                {!world.active && (
                    <Button.Danger size='xsmall' disabled={running} onClick={() => setRemoving(world)}>
                        Delete
                    </Button.Danger>
                )}
            </>
        );

    return (
        <div className='mcc:grid mcc:grid-cols-1 mcc:gap-4 mcc:lg:grid-cols-[minmax(0,1fr)_24rem]'>
            <div className='mcc:grid mcc:min-w-0 mcc:grid-cols-1 mcc:content-start mcc:gap-4'>
                {restart && (
                    <Alert type='info'>
                        Restart the server to load <b>{restart}</b>.
                    </Alert>
                )}
                {running && canManage && <p className='mcc:text-xs mcc:text-muted-foreground'>Stop the server to delete worlds or reset their Nether and End.</p>}
                {wide ? (
                    <Table
                        rows={rows}
                        keyOf={(world) => world.name}
                        isFetching={worlds.isFetching}
                        emptyState={empty}
                        columns={[
                            { id: 'name', label: 'World', cell: (world) => <WorldName world={world} /> },
                            { id: 'dimensions', label: 'Dimensions', cell: (world) => <Dimensions world={world} /> },
                            { id: 'modified', label: 'Last change', cell: (world) => (world.modified ? formatDate(world.modified) : '–') },
                            { id: 'actions', label: '', cell: (world) => <div className='mcc:flex mcc:flex-wrap mcc:justify-end mcc:gap-2'>{actionsOf(world)}</div> },
                        ]}
                    />
                ) : rows.length === 0 ? (
                    <p className='mcc:rounded-sm mcc:bg-card mcc:p-4 mcc:text-center mcc:text-sm mcc:text-muted-foreground mcc:shadow-md'>{empty}</p>
                ) : (
                    <ul className={`mcc:grid mcc:grid-cols-1 mcc:gap-2 ${worlds.isFetching ? 'mcc:opacity-60' : ''}`}>
                        {rows.map((world) => (
                            <li key={world.name} className='mcc:grid mcc:gap-2 mcc:rounded-sm mcc:bg-card mcc:p-3 mcc:shadow-md'>
                                <WorldName world={world} />
                                <div className='mcc:flex mcc:flex-wrap mcc:items-center mcc:justify-between mcc:gap-2 mcc:text-xs mcc:text-muted-foreground'>
                                    <Dimensions world={world} />
                                    {world.modified && <span>Changed {formatDate(world.modified)}</span>}
                                </div>
                                {canManage && !world.pending && <div className='mcc:mt-1 mcc:flex mcc:flex-wrap mcc:gap-2'>{actionsOf(world)}</div>}
                            </li>
                        ))}
                    </ul>
                )}
            </div>
            {canManage && worlds.data && <AddWorld list={worlds.data} busy={busy} onCreated={(name) => setRestart(name)} />}

            <Dialog.Confirm
                open={removing !== null}
                onClose={() => setRemoving(null)}
                title={`Delete ${removing?.name}?`}
                confirm='Delete'
                pending={removal.isPending}
                onConfirmed={() => removing && removal.mutate(removing.name)}
            >
                The world folder{removing?.split ? ', its Nether and its End are' : ' is'} deleted for good. Make a backup first if you might want it back.
            </Dialog.Confirm>
            <Dialog.Confirm
                open={resetting !== null}
                onClose={() => setResetting(null)}
                title={`Reset the ${resetting?.dimension === 'end' ? 'End' : 'Nether'} of ${resetting?.world.name}?`}
                confirm='Reset'
                pending={reset.isPending}
                onConfirmed={() => resetting && reset.mutate(resetting)}
            >
                Everything built there is deleted. The server generates a new {resetting?.dimension === 'end' ? 'End' : 'Nether'} the next time someone enters it.
            </Dialog.Confirm>
        </div>
    );
}

function WorldName({ world }: { world: World }) {
    return (
        <span className='mcc:flex mcc:min-w-0 mcc:flex-wrap mcc:items-center mcc:gap-2'>
            <span className='mcc:min-w-0 mcc:break-words mcc:text-foreground'>{world.name}</span>
            {world.active && <Pill tone='success'>{world.pending ? 'Generates on next start' : 'Loaded'}</Pill>}
        </span>
    );
}

function Dimensions({ world }: { world: World }) {
    return world.pending ? (
        <span>–</span>
    ) : (
        <span className='mcc:flex mcc:gap-1'>
            <Pill tone={world.nether ? 'info' : 'muted'}>Nether</Pill>
            <Pill tone={world.end ? 'info' : 'muted'}>End</Pill>
        </span>
    );
}

function AddWorld({ list, busy, onCreated }: { list: WorldList; busy: boolean; onCreated: (name: string) => void }) {
    const { identifier } = useServerIds();
    const queryClient = useQueryClient();
    const [mode, setMode] = useState<Mode>('create');
    const [name, setName] = useState('');
    const [type, setType] = useState('normal');
    const [seed, setSeed] = useState('');
    const [url, setUrl] = useState('');
    const [file, setFile] = useState<string | null>(null);
    const [activate, setActivate] = useState(true);
    const [query, setQuery] = useState('');
    const [submitted, setSubmitted] = useState('');
    const results = useQuery({ queryKey: keys.worldSearch(identifier, submitted), queryFn: () => searchWorlds(identifier, submitted), enabled: mode === 'curseforge' });
    const onError = (error: unknown) => toast.error(httpErrorToHuman(error));
    const taken = list.worlds.some((world) => world.name === name);
    const validName = NAME.test(name) && !taken;

    const creation = useMutation({
        mutationFn: () => createWorld(identifier, { name, type, seed }),
        onSuccess: (data) => {
            queryClient.setQueryData(keys.worlds(identifier), (current: WorldList | undefined) => ({ ...current, ...data }));
            onCreated(name);
            setName('');
            setSeed('');
        },
        onError,
    });
    const installing = useMutation({
        mutationFn: (input: WorldImport) => importWorld(identifier, input),
        onSuccess: () => {
            toast.success('Installing the world. Progress shows at the top.');
            queryClient.invalidateQueries({ queryKey: keys.detect(identifier) });
            setName('');
        },
        onError,
    });

    const modes = [
        { value: 'create', label: 'Create a new world' },
        { value: 'url', label: 'From a link' },
        { value: 'file', label: 'From an uploaded archive' },
        ...(list.curseforge ? [{ value: 'curseforge', label: 'From CurseForge' }] : []),
    ];

    return (
        <TitledGreyBox title='Add a world' className='mcc:min-w-0'>
            <div className='mcc:grid mcc:grid-cols-1 mcc:gap-4'>
                <Select aria-label='How' value={mode} onChange={(value) => setMode(value as Mode)} options={modes} />
                <div>
                    <Label htmlFor='mcc-world-name'>Folder name</Label>
                    <Input id='mcc-world-name' placeholder='world_survival' value={name} onChange={(event) => setName(event.currentTarget.value.replace(/\s+/g, '_'))} />
                    {taken && <p className='mcc:mt-1 mcc:text-xs mcc:text-destructive'>A world with this name exists.</p>}
                </div>

                {mode === 'create' && (
                    <>
                        <div className='mcc:grid mcc:grid-cols-2 mcc:gap-3'>
                            <div>
                                <Label htmlFor='mcc-world-type'>Type</Label>
                                <Select id='mcc-world-type' value={type} onChange={(value) => setType(String(value))} options={TYPES} />
                            </div>
                            <div>
                                <Label htmlFor='mcc-world-seed'>Seed</Label>
                                <Input id='mcc-world-seed' placeholder='Random' maxLength={64} value={seed} onChange={(event) => setSeed(event.currentTarget.value)} />
                            </div>
                        </div>
                        <p className='mcc:text-xs mcc:text-muted-foreground'>The server loads and generates it on the next start.</p>
                        <Button disabled={!validName} isLoading={creation.isPending} onClick={() => creation.mutate()}>
                            Create world
                        </Button>
                    </>
                )}

                {mode === 'url' && (
                    <>
                        <div>
                            <Label htmlFor='mcc-world-url'>Download link (.zip or .tar.gz)</Label>
                            <Input id='mcc-world-url' type='url' placeholder='https://example.com/world.zip' value={url} onChange={(event) => setUrl(event.currentTarget.value)} />
                        </div>
                        <Switch label='Load it on the next start' checked={activate} onChange={setActivate} />
                        <Button disabled={!validName || !url.startsWith('https://') || busy} isLoading={installing.isPending} onClick={() => installing.mutate({ source: 'url', url, name, activate })}>
                            Install
                        </Button>
                    </>
                )}

                {mode === 'file' && (
                    <>
                        <div>
                            <Label htmlFor='mcc-world-file'>Archive in the server folder</Label>
                            <Select
                                id='mcc-world-file'
                                placeholder={(list.archives ?? []).length ? 'Choose an archive' : 'Upload a .zip in the file manager first'}
                                value={file}
                                disabled={!(list.archives ?? []).length}
                                onChange={(value) => setFile(String(value))}
                                options={(list.archives ?? []).map((archive) => ({ value: archive.name, label: `${archive.name} (${formatBytes(archive.size)})` }))}
                            />
                            <p className='mcc:mt-1 mcc:text-xs mcc:text-muted-foreground'>The archive is unpacked and then removed.</p>
                        </div>
                        <Switch label='Load it on the next start' checked={activate} onChange={setActivate} />
                        <Button disabled={!validName || !file || busy} isLoading={installing.isPending} onClick={() => installing.mutate({ source: 'file', file: file!, name, activate })}>
                            Install
                        </Button>
                    </>
                )}

                {mode === 'curseforge' && (
                    <>
                        <form
                            className='mcc:flex mcc:gap-2'
                            onSubmit={(event) => {
                                event.preventDefault();
                                setSubmitted(query);
                            }}
                        >
                            <Input className='mcc:min-w-0 mcc:flex-1' aria-label='Search CurseForge worlds' placeholder='Search maps...' value={query} onChange={(event) => setQuery(event.currentTarget.value)} />
                            <Button.Text type='submit'>Search</Button.Text>
                        </form>
                        <Switch label='Load it on the next start' checked={activate} onChange={setActivate} />
                        <ul className='mcc:grid mcc:max-h-96 mcc:gap-2 mcc:overflow-y-auto'>
                            {(results.data?.projects ?? []).map((project) => (
                                <li key={project.id} className='mcc:flex mcc:items-center mcc:gap-3 mcc:rounded-sm mcc:bg-sunken mcc:p-2'>
                                    <ProjectIcon src={project.icon} name={project.name} size={36} />
                                    <div className='mcc:min-w-0 mcc:flex-1'>
                                        <p className='mcc:truncate mcc:text-sm mcc:text-foreground'>{project.name}</p>
                                        <p className='mcc:truncate mcc:text-xs mcc:text-muted-foreground'>{project.description}</p>
                                    </div>
                                    {project.downloadable ? (
                                        <Button
                                            size='xsmall'
                                            disabled={busy || installing.isPending}
                                            onClick={() => {
                                                const folder = NAME.test(name) && !taken ? name : project.slug.replace(/[^A-Za-z0-9_.-]/g, '_').slice(0, 64);
                                                installing.mutate({ source: 'curseforge', project: project.id, name: folder, activate });
                                            }}
                                        >
                                            Install
                                        </Button>
                                    ) : (
                                        <Button.Text size='xsmall' title='The author only allows downloads on CurseForge. Download it there and upload it.' onClick={() => window.open(project.url, '_blank', 'noopener')}>
                                            On CurseForge
                                        </Button.Text>
                                    )}
                                </li>
                            ))}
                            {results.data?.projects.length === 0 && <li className='mcc:text-sm mcc:text-muted-foreground'>No worlds found for this version.</li>}
                        </ul>
                    </>
                )}
            </div>
        </TitledGreyBox>
    );
}
