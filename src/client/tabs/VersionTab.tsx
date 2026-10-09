import { useEffect, useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Button, Dialog, Label, Select, Switch, TitledGreyBox, httpErrorToHuman, toast, useServerPermission } from '@pterodactyl/sdk';
import { changeSoftware, getSoftwareBuilds, getSoftwareTypes, getSoftwareVersions, keys, type Platform } from '../api';
import { useServerIds } from '../hooks';
import { SOFTWARE_NAMES, formatBytes, softwareIcon } from '../lib/format';

const GROUPS = [
    { id: 'plugins', label: 'Plugins' },
    { id: 'modded', label: 'Mods' },
    { id: 'proxy', label: 'Proxies' },
    { id: '', label: 'Other' },
];

export default function VersionTab({ platform, busy }: { platform: Platform; busy: boolean }) {
    const { identifier } = useServerIds();
    const queryClient = useQueryClient();
    const canChange = useServerPermission('ext.minecraft-content.version');
    const [type, setType] = useState(platform.software.toUpperCase());
    const [version, setVersion] = useState<string | null>(null);
    const [build, setBuild] = useState<number | null>(null);
    const [clean, setClean] = useState(false);
    const [confirming, setConfirming] = useState(false);

    const types = useQuery({ queryKey: keys.software(identifier), queryFn: () => getSoftwareTypes(identifier), staleTime: Infinity });
    const versions = useQuery({ queryKey: keys.software(identifier, type), queryFn: () => getSoftwareVersions(identifier, type) });
    const builds = useQuery({
        queryKey: keys.software(identifier, type, version ?? ''),
        queryFn: () => getSoftwareBuilds(identifier, type, version!),
        enabled: version !== null,
    });

    useEffect(() => {
        const list = versions.data?.versions ?? [];
        const current = list.find((item) => item.id === platform.minecraft) ?? list.find((item) => item.type === 'release') ?? list[0];
        setVersion(current?.id ?? null);
    }, [versions.data]);

    useEffect(() => setBuild(builds.data?.builds[0]?.id ?? null), [builds.data]);

    const groups = useMemo(
        () =>
            GROUPS.map((group) => ({
                label: group.label,
                options: (types.data?.types ?? [])
                    .filter((item) => !item.deprecated && (group.id === '' ? !GROUPS.some((other) => other.id && item.categories.includes(other.id)) : item.categories[0] === group.id))
                    .map((item) => ({ value: item.id, label: item.experimental ? `${item.name} (experimental)` : item.name })),
            })).filter((group) => group.options.length > 0),
        [types.data]
    );

    const chosen = versions.data?.versions.find((item) => item.id === version);
    const chosenBuild = builds.data?.builds.find((item) => item.id === build);

    const change = useMutation({
        mutationFn: () => changeSoftware(identifier, { type, version: version!, build, clean }),
        onSuccess: () => {
            setConfirming(false);
            queryClient.invalidateQueries({ queryKey: keys.detect(identifier) });
        },
        onError: (error) => toast.error(httpErrorToHuman(error)),
    });

    return (
        <div className='mcc:grid mcc:gap-4 mcc:lg:grid-cols-[minmax(0,1fr)_20rem]'>
            <TitledGreyBox title='Change server software'>
                <div className='mcc:grid mcc:gap-4 mcc:md:grid-cols-3'>
                    <div>
                        <Label htmlFor='mcc-software'>Software</Label>
                        <Select id='mcc-software' value={type} groups={groups} onChange={(value) => setType(String(value))} />
                    </div>
                    <div>
                        <Label htmlFor='mcc-version'>Version</Label>
                        <Select
                            id='mcc-version'
                            value={version}
                            disabled={!versions.data}
                            onChange={(value) => setVersion(String(value))}
                            options={(versions.data?.versions ?? []).map((item) => ({
                                value: item.id,
                                textLabel: item.id,
                                label: `${item.id}${item.type !== 'release' ? ` (${item.type})` : ''}`,
                            }))}
                        />
                    </div>
                    <div>
                        <Label htmlFor='mcc-build'>Build</Label>
                        <Select
                            id='mcc-build'
                            value={build}
                            disabled={!builds.data}
                            onChange={(value) => setBuild(Number(value))}
                            options={(builds.data?.builds ?? []).map((item, index) => ({
                                value: item.id,
                                label: `${item.name}${index === 0 ? ' (latest)' : ''}${item.experimental ? ' (experimental)' : ''}`,
                            }))}
                        />
                    </div>
                </div>
                <div className='mcc:mt-4'>
                    <Switch
                        label='Clean install'
                        description='Removes libraries, cached versions and the old server jar first. Worlds, plugins and mods stay.'
                        checked={clean}
                        onChange={setClean}
                    />
                </div>
                <p className='mcc:mt-4 mcc:text-xs mcc:text-muted-foreground'>
                    {chosen?.java && `Needs Java ${chosen.java}; the matching Docker image is picked when the egg has one. `}
                    {chosenBuild?.size ? `Download size ${formatBytes(chosenBuild.size)}.` : ''}
                </p>
                {canChange && (
                    <div className='mcc:mt-4 mcc:flex mcc:justify-end'>
                        <Button disabled={!version || !build || busy} onClick={() => setConfirming(true)}>
                            Install
                        </Button>
                    </div>
                )}
            </TitledGreyBox>
            <TitledGreyBox title='Current'>
                <div className='mcc:flex mcc:items-center mcc:gap-3'>
                    <img alt='' src={softwareIcon(platform.software)} className='mcc:h-10 mcc:w-10' />
                    <div>
                        <p className='mcc:text-foreground'>
                            {SOFTWARE_NAMES[platform.software] ?? platform.software} {platform.minecraft}
                        </p>
                        <p className='mcc:text-xs mcc:text-muted-foreground'>{platform.detected ? 'Detected from the server files.' : 'Set by hand.'}</p>
                    </div>
                </div>
            </TitledGreyBox>
            <Dialog.Confirm
                open={confirming}
                onClose={() => setConfirming(false)}
                title={`Install ${types.data?.types.find((item) => item.id === type)?.name ?? type} ${version}?`}
                confirm='Install'
                pending={change.isPending}
                onConfirmed={() => change.mutate()}
            >
                The server stops while the new server software is downloaded and starts again afterwards if it was running. Plugins or mods for other
                software stop loading.
            </Dialog.Confirm>
        </div>
    );
}
