import { afterEach, expect, test, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { createExtensionTestHost, createTestServer } from '@pterodactyl/sdk/testing';
import ContentScreen from './ContentScreen';

vi.mock('../api', async (importOriginal) => ({
    ...(await importOriginal<typeof import('../api')>()),
    getDetection: vi.fn().mockResolvedValue({
        platform: { software: 'paper', minecraft: '26.1.2', kind: 'plugins', loaders: ['paper'], detected: true },
        types: [
            { type: 'plugin', supported: true, sources: ['modrinth', 'hangar'] },
            { type: 'mod', supported: false, sources: [] },
            { type: 'datapack', supported: true, sources: ['modrinth'] },
            { type: 'resourcepack', supported: true, sources: ['modrinth'] },
            { type: 'modpack', supported: true, sources: ['modrinth'] },
        ],
        software: ['paper', 'fabric'],
        modpack: null,
        cleanup: 'smart',
        resource_pack: null,
        job: null,
    }),
    search: vi.fn().mockResolvedValue({
        total: 1,
        projects: [{ source: 'modrinth', id: 'Vebnzrzj', slug: 'luckperms', name: 'LuckPerms', author: 'Luck', description: 'A permissions plugin.', icon: null, downloads: 2873688, url: 'https://modrinth.com/plugin/luckperms' }],
    }),
    getInstalled: vi.fn().mockResolvedValue({
        files: [{ path: 'plugins/LuckPerms.jar', file: 'LuckPerms.jar', size: 1000, modified: null, enabled: true, tracked: true, source: 'modrinth', project: 'Vebnzrzj', version: 'x', name: 'LuckPerms', version_name: '5.5.71', icon: null, url: null }],
    }),
}));

let host: ReturnType<typeof createExtensionTestHost> | undefined;

afterEach(() => {
    cleanup();
    host?.dispose();
    host = undefined;
    Reflect.deleteProperty(window, 'matchMedia');
});

function renderScreen(permissions: string[]) {
    host = createExtensionTestHost({ extensionId: 'minecraft-content', prefix: 'mcc', server: createTestServer({ owner: false, permissions, eggFeatures: ['eula'] }) });
    render(<ContentScreen title='Plugins' />, { wrapper: host.Wrapper });
}

test('shows the tabs the server supports and marks installed projects', async () => {
    renderScreen(['ext.minecraft-content.read', 'ext.minecraft-content.install']);

    expect(await screen.findByText('Paper 26.1.2')).toBeTruthy();
    expect(screen.getByRole('tab', { name: 'Plugins' })).toBeTruthy();
    expect(screen.queryByRole('tab', { name: 'Mods' })).toBeNull();
    expect(screen.getByRole('tab', { name: 'Server Version' })).toBeTruthy();

    expect(await screen.findByText('LuckPerms')).toBeTruthy();
    expect(await screen.findByText('Installed')).toBeTruthy();
    expect(screen.getByRole('button', { name: 'Install' })).toBeTruthy();
});

test('disables installing without the permission', async () => {
    renderScreen(['ext.minecraft-content.read']);

    await screen.findByText('LuckPerms');
    expect((screen.getByRole('button', { name: 'Install' }) as HTMLButtonElement).disabled).toBe(true);
    expect(screen.queryByText('Change')).toBeNull();

    fireEvent.click(screen.getByRole('tab', { name: 'Installed (1)' }));
    expect(await screen.findByText('5.5.71')).toBeTruthy();
    expect(screen.queryByRole('button', { name: 'Remove' })).toBeNull();
});

test('lists installed files as cards on phones', async () => {
    Object.defineProperty(window, 'matchMedia', {
        configurable: true,
        value: (query: string) => ({ matches: false, media: query, addEventListener: () => undefined, removeEventListener: () => undefined }),
    });
    renderScreen(['ext.minecraft-content.read', 'ext.minecraft-content.install']);

    await screen.findByText('LuckPerms');
    for (const tablist of screen.getAllByRole('tablist')) {
        expect(tablist.className).toContain('mcc:overflow-x-auto');
    }

    fireEvent.click(await screen.findByRole('tab', { name: 'Installed (1)' }));

    const card = (await screen.findByText('5.5.71')).closest('li')!;
    expect(screen.queryByRole('table')).toBeNull();
    expect(within(card).getByRole('switch', { name: 'Enable LuckPerms' })).toBeTruthy();
    expect(within(card).getByRole('button', { name: 'Remove' })).toBeTruthy();
});
