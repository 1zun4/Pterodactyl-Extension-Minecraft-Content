import { useEffect, useState, type ReactNode } from 'react';
import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { Button, Input, Pagination, Select, Spinner, httpErrorToHuman } from '@pterodactyl/sdk';
import { keys, search, type ContentType, type Project, type SourceName, type Sort } from '../api';
import { useServerIds } from '../hooks';
import { FOLLOWERS, SOURCE_NAMES, formatDownloads } from '../lib/format';
import Pill from '../components/Pill';
import ProjectIcon from '../components/ProjectIcon';
import ProjectDialog from './ProjectDialog';

interface Props {
    type: ContentType;
    sources: SourceName[];
    installed: Set<string>;
    actions: (project: Project) => ReactNode;
}

const SORTS: { value: Sort; label: string }[] = [
    { value: 'relevance', label: 'Relevance' },
    { value: 'downloads', label: 'Downloads' },
    { value: 'updated', label: 'Recently updated' },
    { value: 'newest', label: 'Newest' },
    { value: 'follows', label: 'Most followed' },
];

export default function Browse({ type, sources, installed, actions }: Props) {
    const { identifier } = useServerIds();
    const [source, setSource] = useState<SourceName>(sources[0]);
    const [input, setInput] = useState('');
    const [query, setQuery] = useState('');
    const [sort, setSort] = useState<Sort>('relevance');
    const [page, setPage] = useState(1);
    const [open, setOpen] = useState<Project | null>(null);

    useEffect(() => {
        const timeout = setTimeout(() => {
            setQuery(input.trim());
            setPage(1);
        }, 350);

        return () => clearTimeout(timeout);
    }, [input]);

    const results = useQuery({
        queryKey: keys.search(identifier, type, source, query, sort, page),
        queryFn: () => search(identifier, type, source, query, sort, page),
        placeholderData: keepPreviousData,
    });

    const total = results.data?.total ?? 0;

    return (
        <div className='mcc:grid mcc:grid-cols-1 mcc:gap-4'>
            <div className='mcc:grid mcc:grid-cols-2 mcc:gap-2 mcc:sm:grid-cols-[minmax(0,1fr)_10rem_12rem]'>
                <div className='mcc:col-span-2 mcc:sm:col-span-1'>
                    <Input placeholder='Search...' aria-label='Search' value={input} onChange={(event) => setInput(event.currentTarget.value)} />
                </div>
                <Select
                    value={source}
                    disabled={sources.length < 2}
                    onChange={(value) => {
                        setSource(value as SourceName);
                        setPage(1);
                    }}
                    options={sources.map((name) => ({ value: name, label: SOURCE_NAMES[name] }))}
                />
                <Select value={sort} onChange={(value) => setSort(value as Sort)} options={SORTS} />
            </div>

            {results.isError ? (
                <p className='mcc:text-sm mcc:text-destructive'>{httpErrorToHuman(results.error)}</p>
            ) : !results.data ? (
                <Spinner centered />
            ) : results.data.projects.length === 0 ? (
                <p className='mcc:py-8 mcc:text-center mcc:text-sm mcc:text-muted-foreground'>Nothing found.</p>
            ) : (
                <Pagination
                    onPageSelect={setPage}
                    data={{
                        data: results.data.projects,
                        meta: { pagination: { total, count: results.data.projects.length, per_page: 20, current_page: page, total_pages: Math.max(1, Math.ceil(total / 20)) } },
                    }}
                >
                    {({ items }) => (
                        <div className={`mcc:grid mcc:grid-cols-1 mcc:gap-3 mcc:lg:grid-cols-2 ${results.isPlaceholderData ? 'mcc:opacity-60' : ''}`}>
                            {items.map((project) => (
                                <ProjectCard key={`${project.source}:${project.id}`} project={project} installed={installed.has(project.id)} onOpen={() => setOpen(project)}>
                                    {actions(project)}
                                </ProjectCard>
                            ))}
                        </div>
                    )}
                </Pagination>
            )}
            <ProjectDialog
                project={open}
                onClose={() => setOpen(null)}
                actions={open && <span className='mcc:contents' onClickCapture={() => setTimeout(() => setOpen(null))}>{actions(open)}</span>}
            />
        </div>
    );
}

/** On phones the buttons move below the text and share its full width. */
function ProjectCard({ project, installed, onOpen, children }: { project: Project; installed: boolean; onOpen: () => void; children: ReactNode }) {
    return (
        <div className='mcc:flex mcc:min-w-0 mcc:flex-col mcc:gap-3 mcc:rounded-sm mcc:bg-card mcc:p-3 mcc:shadow-md mcc:sm:flex-row'>
            <div className='mcc:flex mcc:min-w-0 mcc:flex-1 mcc:gap-3'>
                <ProjectIcon src={project.icon} name={project.name} />
                <div className='mcc:min-w-0 mcc:flex-1'>
                    <div className='mcc:flex mcc:min-w-0 mcc:items-center mcc:gap-2'>
                        <button type='button' onClick={onOpen} className='mcc:min-w-0 mcc:truncate mcc:text-left mcc:font-medium mcc:text-foreground mcc:hover:underline'>
                            {project.name}
                        </button>
                        {installed && (
                            <span className='mcc:shrink-0'>
                                <Pill tone='success'>Installed</Pill>
                            </span>
                        )}
                    </div>
                    <p className='mcc:truncate mcc:text-xs mcc:text-muted-foreground'>
                        {project.author && `by ${project.author} · `}
                        {formatDownloads(project.downloads)} downloads
                        {project.followers != null && FOLLOWERS[project.source] && ` · ${formatDownloads(project.followers)} ${FOLLOWERS[project.source]}`}
                    </p>
                    <p className='mcc:mt-1 mcc:line-clamp-2 mcc:text-sm mcc:break-words mcc:text-muted-foreground'>{project.description}</p>
                </div>
            </div>
            <div className='mcc:flex mcc:gap-2 mcc:*:flex-1 mcc:sm:shrink-0 mcc:sm:flex-col mcc:sm:justify-center mcc:sm:*:flex-none'>{children}</div>
        </div>
    );
}

export function InstallButtons({ onInstall, onVersions, pending, disabled }: { onInstall: () => void; onVersions: () => void; pending: boolean; disabled: boolean }) {
    return (
        <>
            <Button size='small' isLoading={pending} disabled={disabled} onClick={onInstall}>
                Install
            </Button>
            <Button.Text size='small' disabled={disabled} onClick={onVersions}>
                Versions
            </Button.Text>
        </>
    );
}
