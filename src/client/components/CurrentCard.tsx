import type { ReactNode } from 'react';
import { TitledGreyBox } from '@pterodactyl/sdk';
import type { Tracked } from '../api';
import { SOURCE_NAMES } from '../lib/format';
import ProjectIcon from './ProjectIcon';

interface Props {
    title: string;
    empty: string;
    item: Tracked | null;
    hint?: string;
    action?: ReactNode;
}

export default function CurrentCard({ title, empty, item, hint, action }: Props) {
    return (
        <TitledGreyBox title={title}>
            {item ? (
                <div className='mcc:flex mcc:items-center mcc:gap-3'>
                    <ProjectIcon src={item.icon} name={item.name} size={40} />
                    <div className='mcc:min-w-0 mcc:flex-1'>
                        <p className='mcc:truncate mcc:text-foreground'>
                            {item.url ? <a href={item.url} target='_blank' rel='noreferrer' className='mcc:hover:underline'>{item.name}</a> : item.name}
                        </p>
                        <p className='mcc:truncate mcc:text-xs mcc:text-muted-foreground'>
                            {item.version_name} · {SOURCE_NAMES[item.source]}
                            {hint && ` · ${hint}`}
                        </p>
                    </div>
                    {action}
                </div>
            ) : (
                <p className='mcc:text-sm mcc:text-muted-foreground'>{empty}</p>
            )}
        </TitledGreyBox>
    );
}
