<?php

use App\Http\Controllers\ConsoleController;
use App\Http\Controllers\LogController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\TinkerController;
use Illuminate\Support\Facades\Route;

Route::get('/', [ConsoleController::class, 'index'])->name('console');

Route::post('/projects', [ProjectController::class, 'store'])->name('projects.store');
Route::post('/projects/{project}/activate', [ProjectController::class, 'activate'])->name('projects.activate');
Route::delete('/projects/{project}', [ProjectController::class, 'destroy'])->name('projects.destroy');

Route::patch('/settings', [SettingsController::class, 'update'])->name('settings.update');

Route::post('/tinker', [TinkerController::class, 'run'])->name('tinker.run');

Route::post('/logs/start', [LogController::class, 'start'])->name('logs.start');
Route::post('/logs/stop', [LogController::class, 'stop'])->name('logs.stop');
