<?php

use App\Http\Controllers\ConsoleController;
use App\Http\Controllers\EditorController;
use App\Http\Controllers\LogController;
use App\Http\Controllers\NotifyController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\TinkerController;
use App\Http\Controllers\WorkbenchController;
use Illuminate\Support\Facades\Route;

Route::get('/', [ConsoleController::class, 'index'])->name('console');

Route::post('/projects', [ProjectController::class, 'store'])->name('projects.store');
Route::post('/projects/{project}/activate', [ProjectController::class, 'activate'])->name('projects.activate');
Route::delete('/projects/{project}', [ProjectController::class, 'destroy'])->name('projects.destroy');

Route::patch('/settings', [SettingsController::class, 'update'])->name('settings.update');

Route::post('/tinker', [TinkerController::class, 'run'])->name('tinker.run');

Route::post('/logs/start', [LogController::class, 'start'])->name('logs.start');
Route::post('/logs/stop', [LogController::class, 'stop'])->name('logs.stop');

Route::post('/editor/open', [EditorController::class, 'open'])->name('editor.open');
Route::post('/notify', [NotifyController::class, 'store'])->name('notify.store');

Route::post('/workbench/routes', [WorkbenchController::class, 'routes'])->name('workbench.routes');
Route::post('/workbench/models', [WorkbenchController::class, 'models'])->name('workbench.models');
Route::post('/workbench/model', [WorkbenchController::class, 'model'])->name('workbench.model');
Route::post('/workbench/migrations', [WorkbenchController::class, 'migrations'])->name('workbench.migrations');
Route::post('/workbench/migrate', [WorkbenchController::class, 'migrate'])->name('workbench.migrate');
Route::post('/workbench/rollback', [WorkbenchController::class, 'rollback'])->name('workbench.rollback');
