# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

Nexus is a NativePHP (Electron) desktop app for Laravel developers: a graphical `php artisan tinker` console, a live `laravel.log` viewer, a Mailpit inbox, and tinker run history. It is a port of an older Wails (Go + Svelte) app; the README has a table mapping the old Go methods to their new homes.

Stack: Laravel 13 (PHP 8.3+), Inertia + Vue 3, Tailwind 4, CodeMirror 6, SQLite via Eloquent.

**`master` is a deliberately simplified version of the app.** Commit `70f65a4` moved the more complex "Hub" features to the `future-features` branch, so the core could be made solid before re-adding them. Those features are the ⌘K command palette, the Workbench (routes / models / migrations / database browser), the snippet library, and the Dumps receiver (`nexus:dump-server`). The README's "Beyond the port (the Hub update)" section describes them, but they are **not on `master`**. Don't reintroduce them, or code that depends on them, unless asked.

**Package managers: npm for all JavaScript, Composer for PHP.** Never use bun or yarn — the project was deliberately moved off bun, and NativePHP's Electron installer only supports npm. Requires PHP 8.3+ and Node 22+ (`npm run setup` checks).

**Skills:** project skills from skills.sh are installed in `.claude/skills/` (list in `skills.txt`, versions in `skills-lock.json`). Use them for Laravel, PHPUnit, Vue, vitest, Tailwind 4, NativePHP, CodeMirror and cross-platform work.

## Commands

```bash
npm run setup              # idempotent: checks PHP/Composer/Node, creates .env, installs deps, migrates BOTH databases, builds frontend
composer native:dev        # run the app: Electron window + vite dev server (passes -D to skip Electron npm reinstall)
composer native:dev:deps   # only after bumping nativephp/desktop — reinstalls Electron deps
php artisan native:migrate # migrate the database the running app actually reads (see "Two databases")

php artisan test                                  # PHPUnit (tests/Unit, tests/Feature)
php artisan test --filter=TinkerResultSerializerTest   # single test class / method
npm test                                          # vitest, resources/js/**/*.test.js (node env, no DOM)
npx vitest run resources/js/lib/logParser.test.js # single JS test file
npm run build                                     # production frontend build (also catches template/compile errors)

vendor/bin/pint            # Laravel Pint is installed (no project config); available, not required
```

## Hard rules / gotchas

- **Windows compatibility is a hard requirement for every change.** Development is on macOS, but Windows is the primary test target. Use path-separator-agnostic code (e.g. `rtrim($p, '/\\')`), don't assume POSIX tools (`tail`, etc.) exist, and remember npm/composer are batch shims on Windows (see `scripts/setup.mjs`).
- **One request at a time.** NativePHP serves PHP with `php -S`; `PHP_CLI_SERVER_WORKERS` is fork-based and so unavailable on Windows. Any request that blocks freezes the whole UI. Long-lived or streaming work must run as a NativePHP `ChildProcess` (see below), and network probes need short timeouts (`MailpitManager::detect()`), with the UI backing off rather than polling on a flat interval. Tinker runs follow this too (see below).
- **Two databases.** `database.default` is only repointed to `database/nativephp.sqlite` when `NATIVEPHP_RUNNING` is set (i.e. the PHP process Electron spawns). Plain `php artisan migrate` writes `database/database.sqlite`. New migrations must be applied with `php artisan native:migrate`; NativePHP only auto-migrates when the file is missing.
- **Never run `php artisan config:cache` in dev.** It bakes `NATIVEPHP_RUNNING=false` into config and the app silently switches to the wrong SQLite file. (`composer test` runs `config:clear` first for the same reason.)
- **No `env()` outside `config/`.** Packaged builds run `php artisan optimize`, after which `env()` returns null at call sites. Nexus's own knobs (`NEXUS_PHP_PATH`, `NEXUS_GIT_BASH_PATH`, `NEXUS_MAILPIT_*`) are read via `config('nexus.*')` from `config/nexus.php`.
- **Never use `composer update` without `--no-scripts` for routine bumps.** `post-update-cmd` runs `native:install --force`, which can overwrite `config/nativephp.php` and `NativeAppServiceProvider`.
- **The app only answers on loopback hosts** (`EnsureLoopbackHost`, prepended globally). It's a DNS-rebinding guard for `/tinker` (arbitrary code execution) when served without NativePHP's secret cookie, e.g. `composer dev`. Feature tests that need another Host must use absolute URLs; the test client derives Host from the URL.
- The main window uses `suppressNewWindows()` + `preventLeaveDomain()` because it carries the `window.Native` preload bridge. External links go through `POST /links/{key}` (allowlist in `config/nexus.php`), never `target="_blank"`.
- JSON endpoints rely on Laravel's default exception rendering (`expectsJson()` → 422 JSON). Don't reintroduce a `shouldRenderJsonWhen` that narrows it.
- `.env` should have `SESSION_DRIVER=file` and `CACHE_STORE=file` — the database drivers caused SQLite/WAL churn that also woke the Vite watcher.
- `vite.config.js` excludes `vendor/`, `storage/` and the SQLite files from watching — keep it that way (Windows needs a watch handle per directory plus a Defender scan per hit).
- OPcache settings live in `NativeAppServiceProvider::phpIni()`; JIT is intentionally off, and `validate_timestamps` is gated on `app.debug`.

## Architecture

### Request flow
A single Inertia page, `resources/js/Pages/Console.vue`, is rendered by `ConsoleController@index`. Everything after that is JSON over `fetch` (`resources/js/lib/http.js`) to thin controllers in `app/Http/Controllers`, which delegate to `app/Services`. Routes are all in `routes/web.php`. App state is two kinds of rows: `projects` (registered Laravel apps) and a single-row `settings` (`Setting::current()`, which also holds `active_project_id`). Most endpoints act on the active project rather than taking a project id.

### Tinker pipeline (the core feature)
In the desktop app, `POST /tinker` only queues the run: `TinkerJobs` writes a job file under `storage/app/tinker-runs/` (files, not argv or stdin — cmd.exe's ~8KB limit, and NativePHP can't close a child's stdin), starts `php artisan nexus:tinker-run {id}` as a ChildProcess aliased `tinker-{id}`, and answers 202. The worker (`RunTinkerJob` → `TinkerExecutor`) runs it, records it, writes the result file and prints `TinkerJobs::DONE`. The client picks the UUID so it can subscribe before POSTing; `lib/tinkerRun.js` fetches `GET /tinker/{id}` on the DONE line or the process exiting (`?exited=1` → the server reports a dead worker), with a backed-off fallback check, and the server gives up after `STALE_AFTER`. Outside Electron (`composer dev`, tests: `nativephp-internal.running` is false) the run happens inline and `POST /tinker` returns 200 with the result.

`TinkerExecutor` → `TinkerRunner::runStructured()` spawns `php artisan tinker` **inside the target project** via Symfony Process (60s timeout), using the PHP binary chosen by `PhpBinaryResolver` (project/Herd PHP preferred over global — a 6-tier fallback). The stdin it pipes is: `TinkerResultSerializer::preamble()` (base64-evals `app/Services/tinker_serializer.php` into the target process), the user's code rewritten by `TinkerScript`, the emitter, and finally `$__nexusResult;`.

Why the rewrite: piped PsySH only records `$_` and prints the `= …` line for the **last** statement, so the emitter can't read `$_`. `TinkerScript` uses PHP-Parser to assign the final expression to `$__nexusResult` (adding a missing trailing semicolon; passing PsySH commands like `ls`/`doc` and unparseable code through untouched); the emitter serializes that variable, and the trailing `$__nexusResult;` line restores the `= …` output. If the user code throws, PsySH skips the emitter (envelope null → run recorded as failed).

The serializer turns the value into a typed envelope (meta + tree node + optional table) that the frontend renders (`Output.vue`, `OutputTable.vue`, `OutputQueries.vue`). `TinkerOutputParser::parse($output, $inputLines)` builds the raw view: piped PsySH echoes all input behind `> `/`. ` prompts first, then all output starting on the last prompt line, so the parser drops exactly the echoed lines it sent (including `<`-truncated, `\r`-redrawn ones) and keeps everything else verbatim.

**Environment isolation:** the child must not inherit Nexus's environment, or Laravel's immutable dotenv lets Nexus's values beat the target's `.env` (wrong DB, mailer, and — inside Electron — Nexus's `APP_CONFIG_CACHE`/`LARAVEL_STORAGE_PATH`). `TargetEnvironment::isolate()` returns the keys to unset (Nexus's `.env` keys, Laravel bootstrap vars, `NATIVEPHP_*`/`NEXUS_*`) and is passed as the Process env.

Constraints on `tinker_serializer.php`: it runs in *someone else's* Laravel app, so it must stay dependency-free, global (no namespace — the emitter calls helpers by bare name), probe Laravel classes with `instanceof`, and keep everything capped (`NEXUS_MAX_*`). It is also `require`d directly by the PHPUnit suite.

Every run is recorded to `runs` (last 100 per project) for history.

### Streaming via ChildProcess (logs)
Long-lived streams never run as a Laravel request. Controllers start an Electron-side `ChildProcess` under a fixed alias (e.g. `LogController` uses `tail`), and stdout lines reach Vue via `window.Native.on(...)` `ChildProcess\MessageReceived` events. `resources/js/lib/nativeEvents.js` registers one global listener and fans out by alias (`onChildProcessMessage(alias, cb)`); `window.Native` exists only inside Electron, after `native:init`.

- **Logs:** `LogTailCommand` builds the argv per platform — `tail -F` on Unix; on Windows, the shell chosen in Settings (`log_shell`): Git Bash (default, `%ProgramFiles%\Git\bin\bash.exe` or `NEXUS_GIT_BASH_PATH`), WSL, or PowerShell (discouraged: `Get-Content -Wait` loses the stream on log rotation). `LogDeltaReader` reads byte ranges appended to a log, used to correlate a tinker run with the log lines it produced.

### Mail
Mail is **global, not per project** (several apps usually share one catcher): it's its own sidebar destination, not a project tab. Nexus **reads whatever mail catcher is already running** and renders the mail in its own UI: `App\Services\Mail\MailCatchers` probes the Settings URL (`settings.mail_url`), smtp4dev on `:5000` and `:8025` (Mailpit vs MailHog told apart by fingerprint endpoints — on macOS `:5000` is also AirPlay, which the fingerprint ignores), prefers smtp4dev > Mailpit > MailHog, and stores the choice in `settings.mail_source` so inbox requests rebuild the adapter without probing. Each adapter (`Smtp4devCatcher`, `MailpitCatcher`, `MailHogCatcher`, all implementing `MailCatcher`) returns Nexus's normalized message shape — the frontend never sees a catcher's own field names. MailHog returns undecoded MIME, hence `MimeParser`, and has no read flag (Nexus caches opened ids). Live updates come from `scripts/mail-watch.mjs`, run as a `mail-watch` ChildProcess on Electron's Node: the window can't hold the sockets itself because Mailpit rejects cross-origin websockets (smtp4dev's is a SignalR hub, spoken raw). Outside Electron the inbox polls. A server pinned in Settings (`settings.mail_pin`) wins while it runs; otherwise it's automatic. **Mailpit is not bundled.** Nexus's own Mailpit is an opt-in fallback managed entirely from Settings → Mail: downloaded into storage (`MailpitManager::download()` runs `fetch-mailpit.mjs --dest` as a ChildProcess on Electron's Node; `NEXUS_MAILPIT_PATH` overrides), then `settings.mailpit_mode` = off | nexus | login, applied by `MailpitMode` on Settings save. `nexus` starts it on app boot and on `/mail/status`, but only when detection finds no other server (`startWithNexus`) — the user's own server always wins. `login` registers it via `MailpitAutostart` without admin (LaunchAgent / systemd --user / HKCU Run + `conhost --headless`). `/mail/status` reports every project's wiring against the active catcher's SMTP port, and `POST /mail/connect/{project}` uses `EnvWriter` to point that project's `MAIL_*` at it.

### Frontend
Heavy components (CodeMirror editor, log viewer) are lazy-loaded to keep the initial bundle small — preserve that when adding imports. Pure helpers in `resources/js/lib` are what the vitest suite covers. Components that await requests across project switches (`MailInbox`, `LogViewer`) guard with a generation/token counter so stale responses are dropped; keep that pattern for new async flows. `scripts/fetch-mailpit.mjs` verifies downloads against pinned SHA-256 digests — update `PINNED_SHA256` together with `DEFAULT_VERSION`.
