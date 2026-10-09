# Minecraft Content for Pterodactyl

[![Release](https://img.shields.io/github/v/release/1zun4/Pterodactyl-Extension-Minecraft-Content?style=flat-square)](https://github.com/1zun4/Pterodactyl-Extension-Minecraft-Content/releases/latest)
[![Downloads](https://img.shields.io/github/downloads/1zun4/Pterodactyl-Extension-Minecraft-Content/total?style=flat-square)](https://github.com/1zun4/Pterodactyl-Extension-Minecraft-Content/releases)
[![Pterodactyl](https://img.shields.io/badge/Pterodactyl-2.0-0e4688?style=flat-square)](https://pterodactyl.io)
[![License](https://img.shields.io/github/license/1zun4/Pterodactyl-Extension-Minecraft-Content?style=flat-square)](LICENSE)

Plugins, mods, modpacks and server versions for your Minecraft servers, in one tab.

![Browse mods](docs/screenshots/browse.png)

## Install

1. Download `minecraft-content-<version>.pteroext` from the [latest release](https://github.com/1zun4/Pterodactyl-Extension-Minecraft-Content/releases/latest).
2. Open **Admin → Extensions**, click **Install** and pick the file.
3. Enable it and reload the panel.

Plugin servers get a **Plugins** tab, modded and vanilla servers a **Mods** tab.

## Features

- **Plugins and mods** from Modrinth, Hangar and CurseForge, filtered for the server's software and version. Required dependencies come along.
- **Updates**: one click per file or all at once. Files you uploaded yourself are recognised by their hash.
- **Datapacks and resource packs**: datapacks go into the world, resource packs into `server.properties`.
- **Modpacks** from Modrinth, CurseForge and FTB, with backup, clean-up and the right server software.
- **Server version**: switch between Paper, Purpur, Fabric, Forge, NeoForge, Velocity and more, with the matching Java image.

![Installed mods](docs/screenshots/installed.png)

![Server version](docs/screenshots/version.png)

## Permissions

Subusers get a **Minecraft content** group in the server's **Users** tab: browse, install, install modpacks and change the version.

## Settings

**Admin → Extensions → Minecraft Content**

| Setting | What it does |
| --- | --- |
| CurseForge API key | Turns on CurseForge. Get a key at [console.curseforge.com](https://console.curseforge.com). |

Modpacks and version changes run in the background, so the panel's queue worker has to run. The Docker image runs it already.

---

Inspired by the Pelican plugins Minecraft Mod Manager, Minecraft Modrinth, Modpack Manager and Minecraft Version Changer. Server jars come from [MCJars](https://mcjars.app).
