<?php

declare(strict_types=1);

namespace MinecraftContent;

use MinecraftContent\Panel\Panel;
use Pterodactyl\Extensions\ExtensionProvider;
use Pterodactyl\Services\Extensions\ExtensionSettingDefinition;
use Pterodactyl\Services\Extensions\ExtensionSettingsDefinition;

class MinecraftContentProvider extends ExtensionProvider
{
    public function register(): void
    {
        $this->app->scoped(Panel::class);
    }

    public function boot(): void
    {
        $this->registerApiRoutes();
        $this->loadExtensionMigrations();

        $this->registerPermissions('Minecraft content.', [
            'read' => 'Browse content and see what is installed.',
            'install' => 'Install, update and remove plugins, mods, datapacks and resource packs.',
            'modpacks' => 'Install modpacks. This replaces the server software and can delete files.',
            'version' => 'Change the server software and Minecraft version.',
            'worlds' => 'Create, switch, reset, delete and install worlds.',
        ]);

        $this->registerSettings(new ExtensionSettingsDefinition($this->settings(), [
            ExtensionSettingDefinition::make('curseforge_api_key', 'curseforge_api_key', '', ['nullable', 'string', 'max:255'])
                ->label('CurseForge API key')
                ->help('Turns on CurseForge. Get a key at console.curseforge.com.')
                ->secret(),
        ]));
    }
}
