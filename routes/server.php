<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use MinecraftContent\Http\Controllers\ContentController;
use MinecraftContent\Http\Controllers\InstalledController;
use MinecraftContent\Http\Controllers\ModpackController;
use MinecraftContent\Http\Controllers\SoftwareController;
use MinecraftContent\Http\Controllers\WorldController;

Route::get('/detect', [ContentController::class, 'detect']);
Route::put('/detect', [ContentController::class, 'override']);
Route::delete('/job', [ContentController::class, 'cancel']);
Route::get('/search', [ContentController::class, 'search']);
Route::get('/versions', [ContentController::class, 'versions']);

Route::get('/installed', [InstalledController::class, 'index']);
Route::post('/installed', [InstalledController::class, 'store']);
Route::delete('/installed', [InstalledController::class, 'destroy']);
Route::post('/installed/toggle', [InstalledController::class, 'toggle']);
Route::get('/installed/updates', [InstalledController::class, 'updates']);
Route::post('/installed/update', [InstalledController::class, 'update']);
Route::post('/installed/scan', [InstalledController::class, 'scan']);
Route::delete('/resource-pack', [InstalledController::class, 'removeResourcePack']);

Route::get('/software', [SoftwareController::class, 'types']);
Route::get('/software-current', [SoftwareController::class, 'current']);
Route::get('/software/{type}', [SoftwareController::class, 'versions']);
Route::get('/software/{type}/{version}', [SoftwareController::class, 'builds']);
Route::post('/software', [SoftwareController::class, 'change']);

Route::post('/modpacks', [ModpackController::class, 'store']);

Route::get('/worlds', [WorldController::class, 'index']);
Route::post('/worlds', [WorldController::class, 'create']);
Route::put('/worlds/active', [WorldController::class, 'activate']);
Route::delete('/worlds', [WorldController::class, 'destroy']);
Route::post('/worlds/reset', [WorldController::class, 'reset']);
Route::get('/worlds/search', [WorldController::class, 'search']);
Route::post('/worlds/import', [WorldController::class, 'import']);
