import { useState } from 'react';
import { ServerContentBlock, ServerError, Spinner, httpErrorToHuman, toast, useServerPermission } from '@pterodactyl/sdk';
import { cancelJob, type ContentType, type Detection } from '../api';
import { useDetection, useJob, useServerIds } from '../hooks';
import { SOFTWARE_NAMES, softwareIcon } from '../lib/format';
import JobProgress from '../components/JobProgress';
import PlatformDialog from '../components/PlatformDialog';
import Tabs from '../components/Tabs';
import CodeTab from '../tabs/CodeTab';
import ModpacksTab from '../tabs/ModpacksTab';
import ResourcePackTab from '../tabs/ResourcePackTab';
import VersionTab from '../tabs/VersionTab';
import WorldsTab from '../tabs/WorldsTab';

type Tab = ContentType | 'worlds' | 'version';

const TITLES: Record<Tab, string> = { plugin: 'Plugins', mod: 'Mods', datapack: 'Datapacks', resourcepack: 'Resource Pack', modpack: 'Modpacks', worlds: 'Worlds', version: 'Server Version' };

export default function ContentScreen({ title }: { title: string }) {
    const detection = useDetection();
    const progress = useJob(detection.data?.job);

    if (detection.isError) {
        return <ServerError title='Could not read the server' message={httpErrorToHuman(detection.error)} onRetry={() => detection.refetch()} />;
    }

    return (
        <ServerContentBlock title={title}>
            {detection.data ? <Content detection={detection.data} running={progress.data?.status === 'running' || (!!detection.data.job && !progress.data)} progress={progress.data} /> : <Spinner centered size={Spinner.Size.LARGE} />}
        </ServerContentBlock>
    );
}

function Content({ detection, running, progress }: { detection: Detection; running: boolean; progress: ReturnType<typeof useJob>['data'] }) {
    const { identifier } = useServerIds();
    const canChange = useServerPermission('ext.minecraft-content.version');
    const canCancel = useServerPermission(['ext.minecraft-content.version', 'ext.minecraft-content.modpacks', 'ext.minecraft-content.worlds'], true);
    const [editing, setEditing] = useState(false);
    const supported = detection.types.filter((type) => type.supported);
    const tabs: Tab[] = [...supported.map((type) => type.type), ...(detection.platform.kind !== 'proxy' ? (['worlds'] as const) : []), 'version'];
    const [tab, setTab] = useState<Tab>(tabs[0]);
    const sources = (type: ContentType) => detection.types.find((item) => item.type === type)?.sources ?? [];
    const { platform } = detection;

    return (
        <div className='mcc:grid mcc:gap-4'>
            <div className='mcc:flex mcc:items-center mcc:gap-3 mcc:rounded-sm mcc:bg-card mcc:p-3 mcc:shadow-md'>
                <img alt='' src={softwareIcon(platform.software)} className='mcc:h-9 mcc:w-9' />
                <div className='mcc:min-w-0 mcc:flex-1'>
                    <h2 className='mcc:font-header mcc:text-sm mcc:font-semibold mcc:uppercase mcc:tracking-wide mcc:text-foreground'>
                        {SOFTWARE_NAMES[platform.software] ?? platform.software} {platform.minecraft ?? ''}
                    </h2>
                    <p className='mcc:text-xs mcc:text-muted-foreground'>
                        {platform.detected ? 'Detected from the server files.' : 'Set by hand.'} Search results only show content for this server.
                    </p>
                </div>
                {canChange && (
                    <button type='button' className='mcc:text-sm mcc:text-muted-foreground mcc:hover:text-foreground' onClick={() => setEditing(true)}>
                        Change
                    </button>
                )}
            </div>

            {progress && (running || progress.status === 'failed') && (
                <JobProgress
                    progress={progress}
                    onCancel={canCancel ? () => cancelJob(identifier).then(() => toast.info('Stopping after the current step...')) : undefined}
                />
            )}

            <Tabs active={tab} onChange={setTab} tabs={tabs.map((id) => ({ id, label: TITLES[id] }))} />

            {(tab === 'plugin' || tab === 'mod' || tab === 'datapack') && <CodeTab key={tab} type={tab} sources={sources(tab)} />}
            {tab === 'resourcepack' && <ResourcePackTab current={detection.resource_pack} sources={sources('resourcepack')} />}
            {tab === 'modpack' && <ModpacksTab current={detection.modpack} sources={sources('modpack')} busy={running} />}
            {tab === 'worlds' && <WorldsTab busy={running} />}
            {tab === 'version' && <VersionTab platform={platform} busy={running} />}

            <PlatformDialog open={editing} onClose={() => setEditing(false)} detection={detection} />
        </div>
    );
}
