<?php

use App\Http\Controllers\ConsoleController;
use App\Http\Controllers\DumpController;
use App\Http\Controllers\EditorController;
use App\Http\Controllers\HistoryController;
use App\Http\Controllers\LogController;
use App\Http\Controllers\MailController;
use App\Http\Controllers\NotifyController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\SnippetController;
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
Route::post('/workbench/db/tables', [WorkbenchController::class, 'dbTables'])->name('workbench.db.tables');
Route::post('/workbench/db/table', [WorkbenchController::class, 'dbTable'])->name('workbench.db.table');
Route::post('/workbench/db/rows', [WorkbenchController::class, 'dbRows'])->name('workbench.db.rows');
Route::post('/workbench/migrate', [WorkbenchController::class, 'migrate'])->name('workbench.migrate');
Route::post('/workbench/rollback', [WorkbenchController::class, 'rollback'])->name('workbench.rollback');

Route::post('/mail/status', [MailController::class, 'status'])->name('mail.status');
Route::post('/mail/start', [MailController::class, 'start'])->name('mail.start');
Route::post('/mail/connect', [MailController::class, 'connect'])->name('mail.connect');
Route::post('/mail/config', [MailController::class, 'config'])->name('mail.config');
Route::get('/mail/messages', [MailController::class, 'messages'])->name('mail.messages');
Route::get('/mail/message/{id}', [MailController::class, 'message'])->name('mail.message');
Route::get('/mail/message/{id}/raw', [MailController::class, 'raw'])->name('mail.raw');
Route::delete('/mail/messages', [MailController::class, 'destroy'])->name('mail.destroy');

Route::get('/snippets', [SnippetController::class, 'index'])->name('snippets.index');
Route::post('/snippets', [SnippetController::class, 'store'])->name('snippets.store');
Route::delete('/snippets/{snippet}', [SnippetController::class, 'destroy'])->name('snippets.destroy');

Route::get('/history', [HistoryController::class, 'index'])->name('history.index');
Route::delete('/history', [HistoryController::class, 'destroy'])->name('history.destroy');

Route::post('/dumps/status', [DumpController::class, 'status'])->name('dumps.status');
Route::post('/dumps/start', [DumpController::class, 'start'])->name('dumps.start');
Route::post('/dumps/stop', [DumpController::class, 'stop'])->name('dumps.stop');
Route::post('/dumps/connect', [DumpController::class, 'connect'])->name('dumps.connect');
