import { useLayoutEffect, useRef, useState } from 'react';

interface Props<T extends string> {
    tabs: { id: T; label: string }[];
    active: T;
    onChange: (tab: T) => void;
}

/** Fades the edges that have more tabs behind them. */
function edgeMask(start: boolean, end: boolean): string | undefined {
    if (!start && !end) {
        return undefined;
    }

    return `linear-gradient(to right, ${start ? 'transparent, black 1.5rem' : 'black'}, ${end ? 'black calc(100% - 1.5rem), transparent' : 'black'})`;
}

/** Scrolls sideways instead of wrapping when the tabs do not fit, and keeps the active tab in view. */
export default function Tabs<T extends string>({ tabs, active, onChange }: Props<T>) {
    const list = useRef<HTMLDivElement>(null);
    const [edges, setEdges] = useState({ start: false, end: false });

    const updateEdges = () => {
        const container = list.current;

        if (container) {
            const start = container.scrollLeft > 1;
            const end = container.scrollLeft + container.clientWidth < container.scrollWidth - 1;
            setEdges((current) => (current.start === start && current.end === end ? current : { start, end }));
        }
    };

    useLayoutEffect(() => {
        updateEdges();

        if (typeof ResizeObserver === 'undefined' || !list.current) {
            return;
        }

        const observer = new ResizeObserver(updateEdges);
        observer.observe(list.current);

        return () => observer.disconnect();
    }, []);

    useLayoutEffect(() => {
        const container = list.current;
        const tab = container?.querySelector<HTMLElement>('[aria-selected="true"]');

        if (!container || !tab || container.scrollWidth <= container.clientWidth) {
            return;
        }

        const offset = tab.getBoundingClientRect().left - container.getBoundingClientRect().left;
        container.scrollLeft += offset - (container.clientWidth - tab.offsetWidth) / 2;
    }, [active]);

    const mask = edgeMask(edges.start, edges.end);

    return (
        <div
            ref={list}
            role='tablist'
            onScroll={updateEdges}
            style={{ maskImage: mask, WebkitMaskImage: mask }}
            className='mcc:flex mcc:gap-2 mcc:overflow-x-auto mcc:scroll-smooth mcc:border-b mcc:border-border mcc:text-sm mcc:[scrollbar-width:none] mcc:[&::-webkit-scrollbar]:hidden'
        >
            {tabs.map((tab) => (
                <button
                    key={tab.id}
                    type='button'
                    role='tab'
                    aria-selected={tab.id === active}
                    onClick={() => onChange(tab.id)}
                    className={
                        tab.id === active
                            ? 'mcc:shrink-0 mcc:whitespace-nowrap mcc:px-3 mcc:py-2 mcc:text-foreground mcc:shadow-navigation-active'
                            : 'mcc:shrink-0 mcc:whitespace-nowrap mcc:px-3 mcc:py-2 mcc:text-muted-foreground mcc:transition-colors mcc:hover:text-foreground'
                    }
                >
                    {tab.label}
                </button>
            ))}
        </div>
    );
}
