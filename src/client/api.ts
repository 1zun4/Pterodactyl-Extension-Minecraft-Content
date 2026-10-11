import { http } from '@pterodactyl/sdk';

export type ContentType = 'plugin' | 'mod' | 'datapack' | 'resourcepack' | 'modpack';
export type SourceName = 'modrinth' | 'hangar' | 'curseforge' | 'ftb';
export type Sort = 'relevance' | 'downloads' | 'updated' | 'newest';

export interface Platform {
    software: string;
    minecraft: string | null;
    kind: 'plugins' | 'mods' | 'vanilla' | 'proxy';
    loaders: string[];
    detected: boolean;
}

export interface Tracked {
    source: SourceName;
    project: string;
    version: string;
    name: string;
    version_name: string;
    icon: string | null;
    url: string | null;
    installed_at: string;
    /** Modpacks only: how many files the pack placed, or null when it was installed before files were tracked. */
    file_count?: number | null;
}

export interface Detection {
    platform: Platform;
    types: { type: ContentType; supported: boolean; sources: SourceName[] }[];
    software: string[];
    modpack: Tracked | null;
    resource_pack: Tracked | null;
    job: string | null;
}

export interface Project {
    source: SourceName;
    id: string;
    slug: string;
    name: string;
    author: string;
    description: string;
    icon: string | null;
    downloads: number;
    url: string;
}

export interface Version {
    source: SourceName;
    project: string;
    id: string;
    name: string;
    number: string;
    type: 'release' | 'beta' | 'alpha';
    game_versions: string[];
    loaders: string[];
    published: string | null;
    file: { url: string; name: string; size: number; sha1: string | null } | null;
}

export interface InstalledFile {
    path: string;
    file: string;
    size: number;
    modified: string | null;
    enabled: boolean;
    tracked: boolean;
    source: SourceName | null;
    project: string | null;
    version: string | null;
    name: string;
    version_name: string | null;
    icon: string | null;
    url: string | null;
}

export interface SoftwareType {
    id: string;
    name: string;
    icon: string;
    categories: string[];
    deprecated: boolean;
    experimental: boolean;
}

export interface SoftwareVersion {
    id: string;
    type: string;
    java: number | null;
    supported: boolean;
    latest: string | null;
}

export interface SoftwareBuild {
    id: number;
    name: string;
    project_version: string | null;
    experimental: boolean;
    size: number;
    created: string | null;
    changes: string[];
}

const base = (server: string) => `/api/client/servers/${server}/extensions/minecraft-content`;

export const keys = {
    detect: (server: string) => ['minecraft-content', server, 'detect'] as const,
    worlds: (server: string) => ['minecraft-content', server, 'worlds'] as const,
    worldSearch: (server: string, query: string) => ['minecraft-content', server, 'world-search', query] as const,
    search: (server: string, type: ContentType, source: string, query: string, sort: Sort, page: number) =>
        ['minecraft-content', server, 'search', type, source, query, sort, page] as const,
    versions: (server: string, type: ContentType, source: string, project: string) =>
        ['minecraft-content', server, 'versions', type, source, project] as const,
    installed: (server: string, type: ContentType) => ['minecraft-content', server, 'installed', type] as const,
    updates: (server: string, type: ContentType) => ['minecraft-content', server, 'updates', type] as const,
    software: (server: string, ...path: string[]) => ['minecraft-content', server, 'software', ...path] as const,
};

const get = async <T,>(url: string, params?: Record<string, unknown>) => (await http.get<T>(url, { params })).data;

export const getDetection = (server: string) => get<Detection>(`${base(server)}/detect`);

export const setPlatform = async (server: string, software: string | null, minecraft: string | null) =>
    (await http.put<Detection>(`${base(server)}/detect`, { software, minecraft })).data;

export const search = (server: string, type: ContentType, source: string, query: string, sort: Sort, page: number) =>
    get<{ total: number; projects: Project[] }>(`${base(server)}/search`, { type, source, query, sort, page });

export const getVersions = (server: string, type: ContentType, source: string, project: string) =>
    get<{ versions: Version[] }>(`${base(server)}/versions`, { type, source, project });

export const getInstalled = (server: string, type: ContentType) => get<{ files: InstalledFile[] }>(`${base(server)}/installed`, { type });

export const getUpdates = (server: string, type: ContentType) =>
    get<{ updates: Record<string, Version> }>(`${base(server)}/installed/updates`, { type });

export const install = async (server: string, type: ContentType, source: string, project: string, version?: string) =>
    (await http.post<{ installed: { name: string; version_name: string }[] }>(`${base(server)}/installed`, { type, source, project, version })).data;

export const remove = async (server: string, type: ContentType, path: string) =>
    (await http.delete<{ files: InstalledFile[] }>(`${base(server)}/installed`, { data: { type, path } })).data;

export const toggle = async (server: string, type: ContentType, path: string, enabled: boolean) =>
    (await http.post<{ files: InstalledFile[] }>(`${base(server)}/installed/toggle`, { type, path, enabled })).data;

export const update = async (server: string, type: ContentType, path: string) =>
    (await http.post(`${base(server)}/installed/update`, { type, path })).data;

export const scan = async (server: string, type: ContentType) =>
    (await http.post<{ recognised: string[]; unknown: string[]; remaining: number }>(`${base(server)}/installed/scan`, { type })).data;

export const removeResourcePack = async (server: string) => (await http.delete(`${base(server)}/resource-pack`)).data;

export const getSoftwareTypes = (server: string) => get<{ types: SoftwareType[] }>(`${base(server)}/software`);

export const getSoftwareVersions = (server: string, type: string) => get<{ versions: SoftwareVersion[] }>(`${base(server)}/software/${type}`);

export const getSoftwareBuilds = (server: string, type: string, version: string) =>
    get<{ builds: SoftwareBuild[] }>(`${base(server)}/software/${type}/${encodeURIComponent(version)}`);

export interface InstalledSoftware {
    type: string;
    version: string;
    build: number;
    name: string;
    installed_at: string;
}

export const getCurrentSoftware = (server: string) =>
    get<{ installed: InstalledSoftware | null; update: SoftwareBuild | null }>(`${base(server)}/software-current`);

export const changeSoftware = async (server: string, input: { type: string; version: string; build: number | null; clean: boolean }) =>
    (await http.post<{ job: string }>(`${base(server)}/software`, input)).data;

export const installModpack = async (
    server: string,
    input: { source: string; project: string; version: string; wipe: boolean; backup: boolean; start: boolean }
) => (await http.post<{ job: string }>(`${base(server)}/modpacks`, input)).data;

export const uninstallModpack = async (server: string, backup: boolean) => (await http.delete<{ job: string }>(`${base(server)}/modpacks`, { data: { backup } })).data;

export const cancelJob = async (server: string) => (await http.delete(`${base(server)}/job`)).data;

export interface World {
    name: string;
    active: boolean;
    pending: boolean;
    modified: string | null;
    nether: string | null;
    end: string | null;
    split: boolean;
}

export interface WorldList {
    active: string;
    worlds: World[];
    archives?: { name: string; size: number }[];
    curseforge?: boolean;
    state?: string;
}

export type WorldImport = { name: string; activate: boolean } & ({ source: 'url'; url: string } | { source: 'file'; file: string } | { source: 'curseforge'; project: string });

export const getWorlds = (server: string) => get<WorldList>(`${base(server)}/worlds`);
export const createWorld = async (server: string, input: { name: string; type: string; seed: string }) => (await http.post<WorldList>(`${base(server)}/worlds`, input)).data;
export const activateWorld = async (server: string, name: string) => (await http.put<WorldList>(`${base(server)}/worlds/active`, { name })).data;
export const deleteWorld = async (server: string, name: string) => (await http.delete<WorldList>(`${base(server)}/worlds`, { data: { name } })).data;
export const resetDimension = async (server: string, name: string, dimension: 'nether' | 'end') => (await http.post<WorldList>(`${base(server)}/worlds/reset`, { name, dimension })).data;
export const searchWorlds = (server: string, query: string) => get<{ total: number; projects: (Project & { downloadable: boolean })[] }>(`${base(server)}/worlds/search`, { query });
export const importWorld = async (server: string, input: WorldImport) => (await http.post<{ job: string }>(`${base(server)}/worlds/import`, input)).data;
