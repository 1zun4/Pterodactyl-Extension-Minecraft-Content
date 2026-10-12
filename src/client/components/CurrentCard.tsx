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
                <div className='mcc:flex mcc:flex-col mcc:gap-3 mcc:sm:flex-row mcc:sm:items-center'>
                    <div className='mcc:flex mcc:min-w-0 mcc:flex-1 mcc:items-center mcc:gap-3'>
                        <ProjectIcon src={item.icon} name={item.name} size={40} />
                        <div className='mcc:min-w-0 mcc:flex-1'>
                            <p className='mcc:truncate mcc:text-foreground'>
                                {item.url ? <a href={item.url} target='_blank' rel='noreferrer' className='mcc:hover:underline'>{item.name}</a> : item.name}
                            </p>
                            <p className='mcc:text-xs mcc:break-words mcc:text-muted-foreground mcc:sm:truncate'>
                                {item.version_name} · {SOURCE_NAMES[item.source]}
                                {hint && ` · ${hint}`}
                            </p>
                        </div>
                    </div>
                    {action && <div className='mcc:flex mcc:gap-2 mcc:*:flex-1 mcc:sm:shrink-0 mcc:sm:*:flex-none'>{action}</div>}
                </div>
            ) : (
                <p className='mcc:text-sm mcc:text-muted-foreground'>{empty}</p>
            )}
        </TitledGreyBox>
    );
}
