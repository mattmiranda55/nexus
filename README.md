# Nexus (NativePHP edition)

A desktop tool for Laravel developers: a graphical `php artisan tinker` console
plus a live `laravel.log` viewer. This is a port of the original Wails (Go +
Svelte) app to a stack that's all PHP + Vue.

## Stack

- **Shell:** [NativePHP](https://nativephp.com) desktop (Electron runtime)
- **Backend:** Laravel 13 (all app logic in PHP)
- **Frontend:** Inertia + Vue 3, Tailwind 4, CodeMirror 6 editor
- **Storage:** SQLite (Eloquent) — `projects` and a single-row `settings`

> Package managers: **npm for everything JavaScript**, Composer for PHP. The
> project used to use bun for the frontend while NativePHP's Electron runtime
> installed with npm (its installer supports only npm/yarn) — two lockfiles and
> two toolchains to keep working on two operating systems, for no benefit.

## Run it

```bash
npm run setup              # checks prerequisites, installs everything, migrates
composer native:dev        # launches the Electron window + vite dev server
```

`npm run setup` is idempotent — re-run it any time. It verifies PHP, Composer
and Node are on PATH, creates `.env`, installs both dependency trees, downloads
the Mailpit binary for your platform, migrates **both** databases (see the
Windows notes on why there are two), and builds the frontend.

After bumping `nativephp/desktop`, run `composer native:dev:deps` once to
reinstall its Electron dependencies.

`native:dev` passes `-D` to `native:run`. Without it NativePHP re-runs a full
`npm install` inside `vendor/nativephp/desktop/resources/electron/` on **every**
launch (`RunCommand` calls `installNPMDependencies(force: true)`, which skips
its own confirm prompt). On macOS that's a quick no-op; on Windows it's minutes
of pegged CPU and disk before the window even appears.

Tests:

```bash
php artisan test     # backend (PHPUnit)
npm test             # frontend (vitest) — resources/js/**/*.test.js
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
  OPcache is enabled in `NativeAppServiceProvider::phpIni()`. JIT is
  deliberately off — tracing JIT bought a request-scoped Laravel app nothing and
  cost real CPU on every cold start.
- `opcache.validate_timestamps` is gated on `app.debug`. Frozen in a packaged
  build, revalidating on a 2s floor in dev — otherwise edits to PHP silently do
  nothing until the whole app restarts.

### Windows notes

Development happens on macOS, but Windows is the primary test target, and it is
where the performance cliffs are. Things that are free on macOS and are not on
Windows:

- **File watching.** `vite.config.js` excludes `vendor/`, `storage/` and the
  SQLite files from the dev watcher. macOS gets one recursive FSEvents watch;
  Windows needs a `ReadDirectoryChangesW` handle per directory plus a Defender
  scan per hit, and `vendor/` alone is tens of thousands of files.
- **Defender.** Exclude the project directory and `vendor/nativephp/php-bin`.
  Every PHP `include` is a file open, and dev mode has no config cache, so each
  request re-reads a lot of small files.
- **One request at a time.** NativePHP serves the app with `php -S`
  (`php.ts` → `['-S', '127.0.0.1:<port>', server.php]`). `PHP_CLI_SERVER_WORKERS`
  is `fork()`-based and therefore POSIX-only, so Windows can never run more than
  one worker. Anything that blocks a request blocks the entire UI — which is why
  `MailpitManager::detect()` uses sub-second timeouts and `MailInbox` backs off
  instead of polling on a flat interval.
- **Two databases.** `NativeServiceProvider::bootingPackage()` only repoints
  `database.default` when `NATIVEPHP_RUNNING` is set, i.e. only for the PHP
  process Electron spawns. The app reads `database/nativephp.sqlite`; a plain
  `php artisan migrate` from your shell writes `database/database.sqlite`. Use
  **`php artisan native:migrate`**. NativePHP only auto-migrates when the file
  is absent, so migrations added later never run on a machine that already has
  one.

Don't run `php artisan config:cache` to speed up dev: `nativephp-internal.running`
reads `env('NATIVEPHP_RUNNING')`, which is unset in your shell, so caching bakes
in `false` and the app silently switches to the wrong SQLite file.
