interface Props<T extends string> {
    tabs: { id: T; label: string }[];
    active: T;
    onChange: (tab: T) => void;
}

export default function Tabs<T extends string>({ tabs, active, onChange }: Props<T>) {
    return (
        <div role='tablist' className='mcc:flex mcc:gap-2 mcc:border-b mcc:border-border mcc:text-sm'>
            {tabs.map((tab) => (
                <button
                    key={tab.id}
                    type='button'
                    role='tab'
                    aria-selected={tab.id === active}
                    onClick={() => onChange(tab.id)}
                    className={
                        tab.id === active
                            ? 'mcc:px-3 mcc:py-2 mcc:text-foreground mcc:shadow-navigation-active'
                            : 'mcc:px-3 mcc:py-2 mcc:text-muted-foreground mcc:transition-colors mcc:hover:text-foreground'
                    }
                >
                    {tab.label}
                </button>
            ))}
        </div>
    );
}
