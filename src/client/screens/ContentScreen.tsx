import { useEffect, useState } from 'react';
import { Alert, Button, ServerContentBlock, ServerError, Spinner, httpErrorToHuman, toast, usePanelLocation, useServerPermission } from '@pterodactyl/sdk';
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
    const job = useJob(detection.data?.job);
    const { identifier } = useServerIds();
    const { params } = usePanelLocation();
    const kind = detection.data?.platform.kind;
    const target = kind ? (kind === 'plugins' || kind === 'proxy' ? 'plugins' : 'mods') : null;
    const current = title === 'Plugins' ? 'plugins' : 'mods';
    const id = params.id ?? identifier;

    useEffect(() => {
        if (target && target !== current) {
            // The panel's runtime gate can hide the old route before an SPA transition finishes.
            window.location.replace(`/server/${encodeURIComponent(id)}/${target}`);
        }
    }, [target, current, id]);

    if (detection.isError) {
        return <ServerError title='Could not read the server' message={httpErrorToHuman(detection.error)} onRetry={() => detection.refetch()} />;
    }

    if (target && target !== current) {
        return <Spinner centered />;
    }

    return (
        <ServerContentBlock title={title}>
            {detection.data ? <Content detection={detection.data} running={job.progress?.status === 'running' || (!!detection.data.job && !job.progress)} job={job} /> : <Spinner centered size={Spinner.Size.LARGE} />}
        </ServerContentBlock>
    );
}

function Content({ detection, running, job: { progress, notice, dismiss } }: { detection: Detection; running: boolean; job: ReturnType<typeof useJob> }) {
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
        <div className='mcc:grid mcc:grid-cols-1 mcc:gap-4'>
            <div className='mcc:flex mcc:items-center mcc:gap-3 mcc:rounded-sm mcc:bg-card mcc:p-3 mcc:shadow-md'>
                <img alt='' src={softwareIcon(platform.software)} className='mcc:h-9 mcc:w-9 mcc:shrink-0' />
                <div className='mcc:min-w-0 mcc:flex-1'>
                    <h2 className='mcc:font-header mcc:text-sm mcc:font-semibold mcc:uppercase mcc:tracking-wide mcc:break-words mcc:text-foreground'>
                        {SOFTWARE_NAMES[platform.software] ?? platform.software} {platform.minecraft ?? ''}
                    </h2>
                    <p className='mcc:text-xs mcc:break-words mcc:text-muted-foreground'>
                        {platform.detected ? 'Detected from the server files.' : 'Set by hand.'} Search results only show content for this server.
                    </p>
                </div>
                {canChange && (
                    <button type='button' className='mcc:shrink-0 mcc:text-sm mcc:text-muted-foreground mcc:hover:text-foreground' onClick={() => setEditing(true)}>
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

            {notice && (
                <Alert type='warning'>
                    <div className='mcc:flex mcc:items-start mcc:justify-between mcc:gap-4'>
                        <span>{notice}</span>
                        <Button.Text size='xsmall' onClick={dismiss}>
                            Dismiss
                        </Button.Text>
                    </div>
                </Alert>
            )}

            <Tabs active={tab} onChange={setTab} tabs={tabs.map((id) => ({ id, label: TITLES[id] }))} />

            {(tab === 'plugin' || tab === 'mod' || tab === 'datapack') && <CodeTab key={tab} type={tab} sources={sources(tab)} />}
            {tab === 'resourcepack' && <ResourcePackTab current={detection.resource_pack} sources={sources('resourcepack')} />}
            {tab === 'modpack' && <ModpacksTab current={detection.modpack} sources={sources('modpack')} busy={running} cleanup={detection.cleanup} />}
            {tab === 'worlds' && <WorldsTab busy={running} />}
            {tab === 'version' && <VersionTab platform={platform} busy={running} />}

            <PlatformDialog open={editing} onClose={() => setEditing(false)} detection={detection} />
        </div>
    );
}
