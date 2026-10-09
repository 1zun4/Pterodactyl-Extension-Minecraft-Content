const compact = new Intl.NumberFormat(undefined, { notation: 'compact', maximumFractionDigits: 1 });

export const formatDownloads = (downloads: number) => compact.format(downloads);

export function formatBytes(bytes: number): string {
    const units = ['B', 'KiB', 'MiB', 'GiB'];
    let value = bytes;
    let unit = 0;

    while (value >= 1024 && unit < units.length - 1) {
        value /= 1024;
        unit++;
    }

    return `${unit === 0 ? value : value.toFixed(1)} ${units[unit]}`;
}

export const SOURCE_NAMES: Record<string, string> = { modrinth: 'Modrinth', hangar: 'Hangar', curseforge: 'CurseForge', ftb: 'FTB' };

export const SOFTWARE_NAMES: Record<string, string> = {
    vanilla: 'Vanilla',
    paper: 'Paper',
    purpur: 'Purpur',
    folia: 'Folia',
    spigot: 'Spigot',
    fabric: 'Fabric',
    quilt: 'Quilt',
    forge: 'Forge',
    neoforge: 'NeoForge',
    velocity: 'Velocity',
    bungeecord: 'BungeeCord',
};

export const softwareIcon = (software: string) => `https://s3.mcjars.app/icons/${software}.png`;
