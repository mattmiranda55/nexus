# Nexus

A desktop companion for Laravel development. Point it at your Laravel projects and
it gives you a graphical `php artisan tinker`, a live `laravel.log` viewer, and an
inbox for the mail your apps send — all in one window, on macOS and Windows.

Built with [NativePHP](https://nativephp.com) (Electron), Laravel 13, Inertia +
Vue 3, Tailwind 4 and CodeMirror 6.

## What it does

### Tinker

- **Run PHP inside any of your projects.** Code runs through that project's own
  `php artisan tinker`, with its own `.env`, database and PHP version (Herd and
  project-local PHP are preferred automatically).
- **Structured results.** Collections and query results open as a sortable,
  filterable table (with CSV/JSON export), nested data as a tree, and every SQL
  query the snippet ran is listed — with likely N+1 queries flagged. The raw
  tinker output is always one tab away.
- **Run just a selection.** ⌘↵ / Ctrl+↵ runs the selected lines when there's a
  selection, the whole buffer when there isn't.
- **Timing.** The output shows how long your code took (Laravel's boot isn't
  counted); hover for the whole run and peak memory.
- **Never blocks the app.** Runs happen in a background process, so logs, mail
  and settings stay responsive. A slow run can be stopped with the Stop button.
- **Your buffer is kept.** Each project's editor contents are saved and come
  back after a restart.
- **Run history.** The last 100 runs per project, with status and duration.
  Restoring one loads its code into the editor; nothing re-runs until you press ⌘↵.
- **Logs from the run.** Anything a run wrote to `laravel.log` appears under its
  output, with a "Show in Logs" link to see it in context.

### Logs

- Live tail of the project's `laravel.log`, parsed into entries with level
  filters, search, collapsed repeats and expandable stack traces.
- Click a stack frame to open it in your editor (VS Code, PhpStorm, Cursor,
  Sublime, VSCodium or TextMate).
- Optional desktop notification when an error is logged.
- "Empty log file" clears `laravel.log` for a clean reproduce-and-read cycle.

### Mail

- **Uses the mail server you already run.** Nexus finds smtp4dev, Mailpit or
  MailHog on your machine and shows their mail in its own inbox, so you never
  need their web UIs. If several are running, pick one in Settings.
- **One inbox for every project**, with each email tagged by the project that
  sent it (matched by its `MAIL_FROM_ADDRESS`) and a filter by project.
- **Wire projects up in one click.** The inbox shows which projects' `.env`
  send mail to it, and a Connect button fixes the ones that don't.
- **HTML, text and raw source views**, attachments listed, and a phone-width
  preview for checking how a mailable looks on mobile.
- **Desktop notifications** for new mail while Nexus isn't in front; clicking
  one opens the message.
- **No mail server?** Settings → Mail can download Mailpit for you (checksum
  verified) and either start it with Nexus or start it at login — no admin
  rights needed.

## Getting started

**Prerequisites:** PHP 8.3+, Composer, Node 22+. On Windows, also
[Git for Windows](https://git-scm.com/download/win) for the log viewer (see below).

```bash
npm run setup          # checks prerequisites, installs everything, migrates, builds
composer native:dev    # opens the Nexus window (with the Vite dev server)
```

Then click **+ Add Laravel project** and pick a project folder.

`npm run setup` is safe to re-run at any time. It checks your PHP, Composer and
Node versions, creates `.env`, installs Composer and npm dependencies, repairs a
missing Electron binary, migrates both databases, and builds the frontend.

Windows users: see [WINDOWS_SETUP.txt](WINDOWS_SETUP.txt) for Defender
exclusions and the log-viewer shell — both make a large difference there.

### Optional `.env` settings

| Variable | Purpose |
| --- | --- |
| `NEXUS_PHP_PATH` | PHP binary to run tinker with (also settable in Settings) |
| `NEXUS_GIT_BASH_PATH` | Git Bash location on Windows, if not in `%ProgramFiles%\Git` |
| `NEXUS_SMTP4DEV_URL` | Where to look for smtp4dev (default `http://127.0.0.1:5000`) |
| `NEXUS_MAILPIT_PATH` | Use this Mailpit binary instead of downloading one |
| `NEXUS_MAILPIT_HOST`, `_SMTP_PORT`, `_HTTP_PORT` | Where Nexus's own Mailpit listens (default `127.0.0.1`, 1025, 8025) |

## Development

```bash
composer native:dev         # run the app
composer native:dev:deps    # after bumping nativephp/desktop: reinstall Electron deps
php artisan native:migrate  # apply new migrations to the database the app reads

php artisan test            # PHPUnit
npm test                    # vitest (resources/js/**/*.test.js)
npm run build               # production frontend build
```

Use **npm** for JavaScript and **Composer** for PHP — not bun or yarn.
NativePHP's Electron installer only supports npm.

[CLAUDE.md](CLAUDE.md) is the detailed developer guide: architecture, the tinker
pipeline, how mail detection works, and the rules below in full.

### Things that will bite you

- **Two databases.** The running app reads `database/nativephp.sqlite`; a plain
  `php artisan migrate` writes `database/database.sqlite`. Apply new migrations
  with `php artisan native:migrate` (setup does both).
- **Don't `php artisan config:cache` in dev.** It bakes in "not running in
  NativePHP" and the app silently switches to the wrong database.
- **One request at a time.** NativePHP serves the app with PHP's built-in
  server, and on Windows that means a single worker. Anything slow belongs in a
  NativePHP `ChildProcess` (tinker runs, the log tail and the mail watcher
  already are), and network checks need short timeouts.
- **Windows is the primary target.** Development happens on macOS, but every
  change has to work on Windows: no POSIX-only tools, separator-agnostic paths,
  and remember npm/composer are batch shims there.
- **Don't run `composer update` without `--no-scripts`** for routine bumps;
  its post-update hook can overwrite NativePHP's config and service provider.
- **No `env()` outside `config/`.** Packaged builds cache config, after which
  `env()` returns null. Nexus's own settings live in `config/nexus.php`.

### Branches

`master` is a deliberately focused version of the app. Larger features — a ⌘K
command palette, a Workbench (routes, models, migrations, database browser), a
snippet library and a `dump()` receiver — live on the `future-features` branch
and will come back once the core is solid.

### History

Nexus started as a Wails (Go + Svelte) app. Roughly where the old Go methods
ended up:

| Original (Go / Wails) | Now (Laravel / Vue) |
| --- | --- |
| `GetProjects` / `AddProject` / `RemoveProject` | `ProjectController`, `app/Models/Project.php` |
| `GetSettings` / `UpdateSettings` | `SettingsController`, `app/Models/Setting.php` |
| `SelectDirectory` (native picker) | `ProjectController::store()` → NativePHP `Dialog` |
| `RunTinker` + output parsing | `TinkerController` → `TinkerExecutor` → `TinkerRunner`, `TinkerOutputParser` |
| `resolvePHPBinary` (Herd fallback chain) | `app/Services/PhpBinaryResolver.php` |
| `StartLogTail` / `StopLogTail` | `LogController` → a NativePHP `ChildProcess` (`LogTailCommand`) |
| Svelte UI | `resources/js/Pages/Console.vue` + `resources/js/Components/*` |
