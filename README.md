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

## Beyond the port (the Hub update)

On top of the roadmap features (structured output, deep logs, workbench, mail):

- **⌘K command palette** — fuzzy switchboard over everything: tabs, workbench
  panels, project switching, snippets, recent runs, theme/layout/settings.
  `resources/js/Components/CommandPalette.vue` + `resources/js/lib/fuzzy.js`.
- **Database browser** — Workbench → Database. Table list (`db:show --json`),
  schema (`db:table --json`), and read-only row browsing that runs
  `DB::table(...)` through the same structured tinker pipeline as the REPL.
- **Snippet library** — save the editor buffer as a named per-project or global
  snippet (toolbar bookmark icon), insert from the palette. Same name overwrites.
- **Run history** — every tinker run is recorded (code, ok, duration), kept to
  the last 100 per project. Toolbar clock icon or the palette restores old code
  into the editor; nothing re-runs without an explicit ⌘↵.
- **Dumps tab (Ray-style receiver)** — one click writes `VAR_DUMPER_FORMAT=server`
  to a project's `.env`, and every `dump()`/`dd()` in that app streams live into
  Nexus with a click-to-source link. No package needed in the target project —
  it's plain `symfony/var-dumper` talking to `nexus:dump-server` (a ChildProcess,
  like the log tail). Safe to leave connected: dumps fall back to normal output
  whenever Nexus isn't running.

After pulling: `php artisan migrate` (adds `snippets` + `runs`).

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
