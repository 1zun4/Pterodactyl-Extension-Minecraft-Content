import './styles.css';
import { useQuery } from '@tanstack/react-query';
import { definePterodactylExtension, type ScreenContext } from '@pterodactyl/sdk';
import { getDetection, keys } from './api';

// Plugin servers and proxies get a "Plugins" tab, modded and vanilla servers a "Mods" tab.
function useKind(context: ScreenContext) {
    const identifier = context.server?.attributes.identifier ?? '';
    const detection = useQuery({ queryKey: keys.detect(identifier), queryFn: () => getDetection(identifier), enabled: identifier !== '', staleTime: 30_000 });

    return detection.data?.platform.kind;
}

export default definePterodactylExtension({
    setup({ screens }) {
        screens.register('plugins', () => import('./screens/PluginsScreen'), {
            visible: (context) => ['plugins', 'proxy'].includes(useKind(context) ?? ''),
        });
        screens.register('mods', () => import('./screens/ModsScreen'), {
            visible: (context) => ['mods', 'vanilla'].includes(useKind(context) ?? ''),
        });
    },
});
