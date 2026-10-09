import type { ReactNode } from 'react';

const tones = {
    success: 'mcc:bg-success/15 mcc:text-success',
    warning: 'mcc:bg-warning/15 mcc:text-warning',
    info: 'mcc:bg-primary/15 mcc:text-primary',
    danger: 'mcc:bg-destructive/15 mcc:text-destructive',
    muted: 'mcc:bg-muted mcc:text-muted-foreground',
};

export default function Pill({ tone = 'muted', children }: { tone?: keyof typeof tones; children: ReactNode }) {
    return (
        <span className={`mcc:inline-flex mcc:items-center mcc:rounded-full mcc:px-2 mcc:py-0.5 mcc:text-xs mcc:font-medium ${tones[tone]}`}>
            {children}
        </span>
    );
}
