import type { ReactNode } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Dialog, Spinner, httpErrorToHuman } from '@pterodactyl/sdk';
import { getDetails, keys, type Project } from '../api';
import { useServerIds } from '../hooks';
import { FOLLOWERS, SOURCE_NAMES, formatDate, formatDownloads } from '../lib/format';
import Pill from '../components/Pill';
import ProjectIcon from '../components/ProjectIcon';

/** A project's description, stats and links without leaving the panel. Descriptions arrive as plain text. */
export default function ProjectDialog({ project, onClose, actions }: { project: Project | null; onClose: () => void; actions?: ReactNode }) {
    const { identifier } = useServerIds();
    const details = useQuery({
        queryKey: keys.details(identifier, project?.source ?? '', project?.id ?? ''),
        queryFn: () => getDetails(identifier, project!.source, project!.id),
        enabled: project !== null,
        staleTime: 300_000,
    });
    const data = details.data;

    return (
        <Dialog open={project !== null} onClose={onClose} title={project?.name ?? ''}>
            {project && (
                <div className='mcc:grid mcc:gap-4'>
                    <div className='mcc:flex mcc:items-center mcc:gap-3'>
                        <ProjectIcon src={project.icon} name={project.name} size={48} />
                        <div className='mcc:min-w-0 mcc:text-xs mcc:text-muted-foreground'>
                            <p>
                                {project.author && `by ${project.author} · `}
                                {formatDownloads(project.downloads)} downloads
                                {data?.followers != null && FOLLOWERS[project.source] && ` · ${formatDownloads(data.followers)} ${FOLLOWERS[project.source]}`}
                            </p>
                            <p>
                                {SOURCE_NAMES[project.source]}
                                {data?.updated && ` · updated ${formatDate(data.updated)}`}
                                {data?.license && ` · ${data.license}`}
                            </p>
                        </div>
                    </div>

                    {details.isError ? (
                        <p className='mcc:text-sm mcc:text-destructive'>{httpErrorToHuman(details.error)}</p>
                    ) : !data ? (
                        <Spinner centered />
                    ) : (
                        <>
                            {data.categories.length > 0 && (
                                <div className='mcc:flex mcc:flex-wrap mcc:gap-1'>
                                    {data.categories.map((category) => (
                                        <Pill key={category} tone='muted'>
                                            {category}
                                        </Pill>
                                    ))}
                                </div>
                            )}
                            {data.gallery.length > 0 && (
                                <div className='mcc:flex mcc:gap-2 mcc:overflow-x-auto'>
                                    {data.gallery.map((image) => (
                                        <a key={image.url} href={image.url} target='_blank' rel='noreferrer' className='mcc:shrink-0'>
                                            <img alt={image.title} src={image.url} loading='lazy' className='mcc:h-24 mcc:rounded-sm mcc:object-cover' />
                                        </a>
                                    ))}
                                </div>
                            )}
                            <p className='mcc:max-h-72 mcc:overflow-y-auto mcc:whitespace-pre-line mcc:rounded-sm mcc:bg-sunken mcc:p-3 mcc:text-sm mcc:text-foreground/80'>
                                {data.description || project.description}
                            </p>
                            <div className='mcc:flex mcc:flex-wrap mcc:gap-x-4 mcc:gap-y-1 mcc:text-sm'>
                                {[{ label: SOURCE_NAMES[project.source], url: project.url }, ...data.links].map((link) => (
                                    <a key={link.url} href={link.url} target='_blank' rel='noreferrer' className='mcc:text-primary mcc:hover:underline'>
                                        {link.label}
                                    </a>
                                ))}
                            </div>
                        </>
                    )}
                </div>
            )}
            {actions && <Dialog.Footer>{actions}</Dialog.Footer>}
        </Dialog>
    );
}
