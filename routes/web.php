<?php

use App\Http\Controllers\ConsoleController;
use App\Http\Controllers\EditorController;
use App\Http\Controllers\HistoryController;
use App\Http\Controllers\LinkController;
use App\Http\Controllers\LogController;
use App\Http\Controllers\MailController;
use App\Http\Controllers\NotifyController;
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
Route::get('/tinker/{id}', [TinkerController::class, 'result'])->whereUuid('id')->name('tinker.result');
Route::delete('/tinker/{id}', [TinkerController::class, 'stop'])->whereUuid('id')->name('tinker.stop');

Route::post('/logs/start', [LogController::class, 'start'])->name('logs.start');
Route::post('/logs/stop', [LogController::class, 'stop'])->name('logs.stop');

Route::post('/editor/open', [EditorController::class, 'open'])->name('editor.open');
Route::post('/notify', [NotifyController::class, 'store'])->name('notify.store');
Route::post('/links/{key}', [LinkController::class, 'open'])->where('key', '[a-z0-9-]+')->name('links.open');

Route::post('/mail/status', [MailController::class, 'status'])->name('mail.status');
Route::post('/mail/watch', [MailController::class, 'watch'])->name('mail.watch');
Route::post('/mail/notify', [MailController::class, 'notify'])->name('mail.notify');
Route::post('/mail/connect/{project}', [MailController::class, 'connect'])->name('mail.connect');
Route::get('/mail/messages', [MailController::class, 'messages'])->name('mail.messages');
Route::get('/mail/message/{id}', [MailController::class, 'message'])->name('mail.message');
Route::get('/mail/message/{id}/raw', [MailController::class, 'raw'])->name('mail.raw');
Route::delete('/mail/messages', [MailController::class, 'destroy'])->name('mail.destroy');
Route::post('/mail/mailpit/start', [MailController::class, 'startMailpit'])->name('mail.mailpit.start');
Route::post('/mail/mailpit/download', [MailController::class, 'downloadMailpit'])->name('mail.mailpit.download');
Route::delete('/mail/mailpit', [MailController::class, 'removeMailpit'])->name('mail.mailpit.remove');

Route::get('/history', [HistoryController::class, 'index'])->name('history.index');
Route::delete('/history', [HistoryController::class, 'destroy'])->name('history.destroy');
