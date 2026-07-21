# Nexus (NativePHP edition)

A desktop tool for Laravel developers: a graphical `php artisan tinker` console
plus a live `laravel.log` viewer. This is a port of the original Wails (Go +
Svelte) app to a stack that's all PHP + Vue.

## Stack

- **Shell:** [NativePHP](https://nativephp.com) desktop (Electron runtime)
- **Backend:** Laravel 13 (all app logic in PHP)
- **Frontend:** Inertia + Vue 3, Tailwind 4, CodeMirror 6 editor — managed with **bun**
- **Storage:** SQLite (Eloquent) — `projects` and a single-row `settings`

> Package managers: the Vue frontend is bun-only. NativePHP's internal Electron
> runtime is installed with npm (its installer only supports npm/yarn) — that's
> the one place npm is used.

## Run it

```bash
composer install
bun install
composer native:dev   # launches the Electron window + vite dev server
```

Tests:

```bash
php artisan test                       # backend (PHPUnit)
bun test resources/js/lib              # frontend log parser
```

## How it maps to the original Go app

The old app exposed ~10 bound Go methods; here's where that logic now lives.

| Original (Go / Wails) | Now (Laravel / Vue) |
| --- | --- |
| `GetProjects` / `AddProject` / `RemoveProject` | `app/Http/Controllers/ProjectController.php` + `app/Models/Project.php` |
| `GetSettings` / `UpdateSettings` | `SettingsController` + `app/Models/Setting.php` |
| `SelectDirectory` (native picker) | `ProjectController::store()` → `Dialog::new()->folders()->open()` |
| `RunTinker` | `TinkerController` → `app/Services/TinkerRunner.php` (Symfony Process) |
| tinker output parsing | `app/Services/TinkerOutputParser.php` |
| `resolvePHPBinary` (6-tier Herd fallback) | `app/Services/PhpBinaryResolver.php` |
| `StartLogTail` / `StopLogTail` + `log:update` events | `LogController` → `ChildProcess::start('tail -n 200 -F …')`; UI receives lines via `window.Native.on(...)` (`resources/js/lib/nativeEvents.js`) |
| Svelte UI | `resources/js/Pages/Console.vue` + `resources/js/Components/*` |

### Why the log tail uses a child process

NativePHP boots the app with `php artisan serve`, which is single-threaded. A
long-lived log stream must not run as a Laravel request or it would block the
server. Instead the tail runs as an Electron-side child process that pushes lines
straight to the Vue UI via `window.Native.on(...)` — no websocket, no blocking.

### Load-time notes

- Editor (CodeMirror) and the log viewer are lazy-loaded, so the initial JS
  bundle is ~185KB instead of ~845KB.
- `config/nativephp.php` `prebuild` runs `php artisan optimize` at build time;
  OPcache + JIT are enabled in `NativeAppServiceProvider::phpIni()`.
