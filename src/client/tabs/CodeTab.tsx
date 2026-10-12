import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { useServerPermission } from '@pterodactyl/sdk';
import { getInstalled, keys, type ContentType, type Project, type SourceName } from '../api';
import { useServerIds } from '../hooks';
import Browse, { InstallButtons } from '../content/Browse';
import InstalledList from '../content/InstalledList';
import VersionsDialog from '../content/VersionsDialog';
import Tabs from '../components/Tabs';
import { useInstall } from './useInstall';

const LABELS: Record<string, string> = { plugin: 'plugins', mod: 'mods', datapack: 'datapacks' };

export default function CodeTab({ type, sources }: { type: ContentType; sources: SourceName[] }) {
    const { identifier } = useServerIds();
    const canInstall = useServerPermission('ext.minecraft-content.install');
    const [view, setView] = useState<'browse' | 'installed'>('browse');
    const [versionsOf, setVersionsOf] = useState<Project | null>(null);
    const installation = useInstall(type);
    const installed = useQuery({ queryKey: keys.installed(identifier, type), queryFn: () => getInstalled(identifier, type) });
    const projects = new Set((installed.data?.files ?? []).map((file) => file.project ?? ''));

    return (
        <div className='mcc:grid mcc:grid-cols-1 mcc:gap-4'>
            <Tabs
                active={view}
                onChange={setView}
                tabs={[
                    { id: 'browse', label: `Browse ${LABELS[type]}` },
                    { id: 'installed', label: `Installed (${installed.data?.files.length ?? 0})` },
                ]}
            />
            {view === 'installed' ? (
                <InstalledList type={type} />
            ) : sources.length === 0 ? (
                <p className='mcc:text-sm mcc:text-muted-foreground'>No source offers {LABELS[type]} for this server.</p>
            ) : (
                <Browse
                    type={type}
                    sources={sources}
                    installed={projects}
                    actions={(project) => (
                        <InstallButtons
                            disabled={!canInstall}
                            pending={installation.isPending && installation.variables?.project === project.id}
                            onInstall={() => installation.mutate({ source: project.source, project: project.id })}
                            onVersions={() => setVersionsOf(project)}
                        />
                    )}
                />
            )}
            <VersionsDialog
                type={type}
                project={versionsOf}
                onClose={() => setVersionsOf(null)}
                pending={installation.isPending}
                onPick={(version) => installation.mutateAsync({ source: version.source, project: versionsOf!.id, version: version.id }).then(() => setVersionsOf(null)).catch(() => undefined)}
            />
        </div>
    );
}
