import './styles.css';
import { useQuery } from '@tanstack/react-query';
import { definePterodactylExtension, usePanelLocation, type ScreenContext } from '@pterodactyl/sdk';
import { getDetection, keys } from './api';

function useContentScreen(context: ScreenContext, screen: 'plugins' | 'mods') {
    const identifier = context.server?.attributes.identifier ?? '';
    const { pathname, params } = usePanelLocation();
    const id = params.id ?? identifier;
    const current = pathname.replace(/\/$/, '') === `/server/${id}/${screen}`;
    const detection = useQuery({ queryKey: keys.detect(identifier), queryFn: () => getDetection(identifier), enabled: identifier !== '', staleTime: 30_000 });
    const kind = detection.data?.platform.kind;
    const target = kind ? (kind === 'plugins' || kind === 'proxy' ? 'plugins' : 'mods') : null;

    // Keep the current route mounted while detection loads or navigation replaces it.
    return target === screen || current;
}

export default definePterodactylExtension({
    setup({ screens }) {
        screens.register('plugins', () => import('./screens/PluginsScreen'), {
            visible: (context) => useContentScreen(context, 'plugins'),
        });
        screens.register('mods', () => import('./screens/ModsScreen'), {
            visible: (context) => useContentScreen(context, 'mods'),
        });
    },
});
