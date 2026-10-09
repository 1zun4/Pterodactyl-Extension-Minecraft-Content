import { useState } from 'react';

export default function ProjectIcon({ src, name, size = 48 }: { src: string | null; name: string; size?: number }) {
    const [failed, setFailed] = useState(false);

    if (!src || failed) {
        return (
            <span
                aria-hidden
                style={{ width: size, height: size }}
                className='mcc:inline-flex mcc:shrink-0 mcc:items-center mcc:justify-center mcc:rounded-md mcc:bg-muted mcc:text-sm mcc:font-semibold mcc:uppercase mcc:text-muted-foreground'
            >
                {name.slice(0, 2)}
            </span>
        );
    }

    return <img alt='' src={src} width={size} height={size} loading='lazy' onError={() => setFailed(true)} className='mcc:shrink-0 mcc:rounded-md mcc:object-cover' />;
}
