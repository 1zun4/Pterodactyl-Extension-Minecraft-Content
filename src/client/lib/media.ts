import { useCallback, useSyncExternalStore } from 'react';

/** Tailwind's `md` breakpoint: below it tables turn into cards. */
export const MEDIUM = '(min-width: 48rem)';

/** Without `matchMedia` (tests, old browsers) the desktop layout is used. */
export function matchesMedia(query: string): boolean {
    return typeof window === 'undefined' || typeof window.matchMedia !== 'function' || window.matchMedia(query).matches;
}

export function useMediaQuery(query: string): boolean {
    const subscribe = useCallback(
        (onChange: () => void) => {
            if (typeof window.matchMedia !== 'function') {
                return () => undefined;
            }

            const list = window.matchMedia(query);
            list.addEventListener('change', onChange);

            return () => list.removeEventListener('change', onChange);
        },
        [query]
    );

    return useSyncExternalStore(subscribe, () => matchesMedia(query), () => true);
}
