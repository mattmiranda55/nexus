# Nexus (NativePHP) — Feature Roadmap

_Spec generated 2026-07-19 for the **nexus-php** codebase (NativePHP + Laravel 13 +
Inertia/Vue 3 + CodeMirror, bun). Scope: the three differentiating directions plus email —
**B) rich output**, **A) deep logs**, **C) Laravel workbench**, **F) email**._

## Strategic frame

Nexus is the **hub** — "links everything into one window," scoped to the active project. That's
the position TweakPHP and Tinkerwell (both "code runners") don't hold. Guiding principle:
**orchestrate best-of-breed, don't reinvent it** (bundle + manage + integrate over rebuild), so
"one-stop" doesn't become "never ships."

**Build order:** B → A → C → F. B is the rendering layer C and F render through; A extends code
that already exists.

## Architecture primer (current extension points)

| Concern | Where it lives now |
| --- | --- |
| Run PHP in a project | `app/Services/TinkerRunner.php` (Symfony `Process` → `php artisan tinker`, stdin, 60s) |
| Clean tinker output | `app/Services/TinkerOutputParser.php` (→ **string**) |
| Resolve the PHP binary | `app/Services/PhpBinaryResolver.php` |
| HTTP endpoints | thin controllers in `app/Http/Controllers/*`, routes in `routes/web.php` (named, POST→JSON) |
| Active project | `Setting::current()->active_project_id` → `Project::find()`; `Project->path`, `Project->logPath()` |
| Long-lived native processes | NativePHP `ChildProcess::start([...], $alias)` (see `LogController`) |
| Native→UI push | `window.Native.on(...)` bridged by `resources/js/lib/nativeEvents.js` (`onChildProcessMessage`) |
| Log parsing | **already in JS**: `resources/js/lib/logParser.js` (+ `.test.js`) |
| UI | `resources/js/Pages/Console.vue` + `resources/js/Components/*.vue`; `lib/http.js` for requests |

---

## Tier B — Rich structured output (the foundation)

Today `TinkerController` returns `{ output: string }`. To render tables/trees we need the
**value**, not its text form. This is the one genuine re-architecture; everything downstream reuses it.

### B1. Structured execution + typed envelope
- **New service `app/Services/TinkerResultSerializer.php`**: turns any PHP value into a typed array
  — `scalar | array | assoc | collection | model | object` — with `columns`/`rows` for tabular data.
  Cap depth + row count. Models/Collections → `->toArray()`.
- **`TinkerRunner::runStructured(string $projectPath, string $code): array`**: wrap the user code
  so its final value is serialized and printed between sentinels the parser can extract:
  ```
  __NEXUS_OUT_START__{json envelope}__NEXUS_OUT_END__
  ```
  Prepend a preamble to the piped stdin that defines the serializer inline (tinker has no access to
  Nexus's classes — it boots the *target* project), then emit the envelope after the user's code.
- **`TinkerController`** gains `structured` mode → returns `{ envelope, raw }`. Keep the existing
  string path as the **raw/CLI fallback view** (parity with `artisan tinker`).
- ⚠️ **Key spike:** reliably capturing "the value of the last expression" from arbitrary pasted code.
  Recommended v1 rule: wrap the body in a closure and treat the trailing expression as the return
  (document it), or evaluate via PsySH's programmatic API instead of the REPL. Prototype this first —
  it gates the whole tier.

### B2. Table view — `resources/js/Components/Output.vue` (extend)
- Envelope `collection`/`assoc[]` → sortable, filterable, paginated grid.
- Export **CSV / JSON** (client-side).
- Distinct rendering for null / bool / dates.

### B3. Object / array tree
- Nested envelopes → collapsible tree component (lazy-expand deep nodes).

### B4. Captured SQL + N+1 hints
- In the `runStructured` preamble: `DB::enableQueryLog();` before user code, `DB::getQueryLog()` after,
  fold the queries into the envelope (`queries: [{sql, bindings, time}]`).
- Frontend panel lists them; flag the same normalized SQL firing > N times as a possible N+1.

_Effort: medium-high. Highest leverage — C and F render through B2/B3._

---

## Tier A — Deep logs (extend what already exists)

`logParser.js` already parses lines and `LogViewer.vue` renders the `ChildProcess` tail. Build on both.

### A1. Structured parsing — extend `resources/js/lib/logParser.js`
- Group multi-line stack traces under their parent entry; collapse by default.
- Emit `{ timestamp, level, env, message, stack[] }`. Extend `logParser.test.js` alongside (bun test).

### A2. Filters & search — `LogViewer.vue`
- Multi-select level, full-text search, time range. **Dedup:** collapse consecutive identical
  exceptions into one row + count.

### A3. Click-to-source
- Parse `/abs/File.php:123` from stack frames → open in the user's editor via URL scheme
  (`phpstorm://open?file=…&line=…`, `vscode://file/…:line`, configurable in `SettingsModal.vue`).
- Open through NativePHP's shell/open-external API (verify exact facade) or a short-lived
  `Process(['open', $url])`; add a `SettingsController` field for the preferred editor scheme.

### A4. Run↔log correlation (novel — nobody does this)
- In `TinkerController`: capture `filesize($project->logPath())` before the run; after, read the byte
  delta and include it in the response as `loggedDuringRun`. UI shows **"this run logged: …"** inline
  under the output. Fuses REPL + log viewer — a unique hook, ~half a day given the pieces exist.

### A5. Multi-log watching
- Generalize `LogController` to tail `storage/logs/*.log` (daily/Horizon/queue) — one `ChildProcess`
  alias per file, or a merged view with a source column (`Project` gains a `logPaths()` helper).

### A6. Desktop notifications
- On `error`/`critical` (detected in `logParser.js`), fire an OS notification via NativePHP's
  `Notification` facade (small POST route) — per-project toggle in settings.

_Effort: low-medium. Mostly Vue + JS on top of the existing tail + parser._

---

## Tier C — Laravel workbench (the land-grab)

Reframes Nexus REPL → workbench. Most of these are **artisan commands with `--json`**, so they don't
even need Tinker — run them through `Symfony\Process` and render via **Tier B**.

- **New `app/Services/ArtisanRunner.php`**: `run(string $projectPath, array $args): array` — resolves
  the binary via `PhpBinaryResolver`, runs `php artisan …`, returns decoded JSON. Sibling to `TinkerRunner`.
- **New `app/Http/Controllers/WorkbenchController.php`** (+ routes in `web.php`), one method per panel,
  all scoped to the active project. New Vue panels under `resources/js/Components/Workbench/*`, surfaced
  as tabs in `Console.vue`.

| Panel | Command / source | Notes |
| --- | --- | --- |
| **C1 Model explorer** | `php artisan model:show {Model} --json` | Discover via `app/Models` glob; "Query" button injects `Model::query()->limit(50)->get()` into the editor → runs through **B2** |
| **C2 Routes** | `route:list --json` | Filterable table (method/URI/name/action/middleware) |
| **C3 Failed jobs** | `queue:failed` (or query `failed_jobs`) | Retry (`queue:retry {id}`) / forget buttons |
| **C4 Schedule** | `schedule:list` | Commands + next run |
| **C5 Migrations** | `migrate:status` | migrate/rollback **behind a confirm** (destructive) |
| **C6 App/config** | `about --json` + selected `config()` via Tinker | **Mask secrets** (APP_KEY, DB, tokens) |

_Effort: low-medium **per panel** once B and `ArtisanRunner` exist._

---

## Tier F — Email (bundle & manage Mailpit)

Nexus **bundles and manages Mailpit** (single static Go binary) and acts as a client over its HTTP
API. Reuses the exact `ChildProcess` pattern already used for the log tail. No SMTP/MIME code owned.

### F1. Managed Mailpit lifecycle — `app/Services/MailpitManager.php` + `MailController`
- **Detect first:** `Http::timeout(1)->get('http://127.0.0.1:8025/api/v1/info')` — if Mailpit is already
  up (e.g. Herd's), reuse it; don't fight the user's setup.
- **Else launch** the bundled binary via `ChildProcess::start([$mailpitBin, '--smtp', '127.0.0.1:1025',
  '--listen', '127.0.0.1:8025'], 'mailpit')`; stop on quit. Bundle the binary as a NativePHP resource
  and resolve its path per-OS (verify NativePHP's binary-bundling approach in `config/nativephp.php`).

### F2. Auto-wire the active project — `app/Services/EnvWriter.php`
- Read the project `.env`; confirm/repair `MAIL_MAILER=smtp`, `MAIL_HOST`, `MAIL_PORT=1025`, null user/pass.
- **One-click "connect this app"** writes those lines when missing.
- Derive the API URL by convention (SMTP 1025 → HTTP 8025); **per-project override** in settings (Docker remaps).

### F3. Inbox viewer — `MailController` (Laravel HTTP client) + `resources/js/Components/MailInbox.vue`
- `GET /api/v1/messages` (list) · `/api/v1/message/{ID}` (HTML/text/headers) · `/part/{PartID}` (attach) ·
  `/raw` (source) · `DELETE /api/v1/messages` (clear).
- **Live:** connect the renderer directly to Mailpit's websocket `ws://127.0.0.1:8025/api/events` for
  instant new-mail push (keeps it off the single-threaded PHP server); poll fallback if unavailable.
- Render HTML/text/source tabs + attachments, tied to the active project.

### F4. "Mailpit not found" helper
- If detection fails and no bundled binary launches, show a friendly setup hint, not a silent empty inbox.

**Complementary (Tier B family): Mailable preview** — render an *unsent* mailable via Tinker
(`(new App\Mail\X($model))->render()`) into a webview. Distinct from the Mailpit inbox (sent mail).

_Effort: low-medium (~few days over a pure client). No SMTP server or MIME parsing owned._

---

## Suggested milestones

1. **M1 – Rendering core:** B1 (serializer + `runStructured` + envelope) → B2 table → B3 tree. Spike B1 first.
2. **M2 – Logs that sell it:** A1–A3 (parser/filters/click-to-source) + A6 notifications.
3. **M3 – Workbench v1:** `ArtisanRunner` + C1 models + C2 routes + C5 migrations (all reuse M1).
4. **M4 – The hooks:** A4 run↔log correlation + B4 captured SQL/N+1 — what makes Nexus feel unique.
5. **M5 – Email hub:** F1 managed Mailpit + F2 auto-wire + F3 inbox (reuses M1 rendering).

## Testing conventions
- Backend services (`TinkerResultSerializer`, `ArtisanRunner`, `EnvWriter`, `MailpitManager`): PHPUnit
  under `tests/` (`php artisan test`).
- Frontend parsers (`logParser.js` extensions): `bun test resources/js/lib`.

## Key risks / spikes
- **B1 last-expression capture** — the one hard problem; prototype before committing to the tier.
- **Mailpit binary bundling** across macOS/Windows/Linux — confirm NativePHP's resource path story.
- **Editor URL-scheme open** — verify the exact NativePHP facade for opening external URLs.

## Deliberately deferred
- Snippet sharing / collaboration (Tier D) — real gap, not chosen this round.
- Docker / SSH remote execution — parity with rivals, not differentiation.
- AI (BYO-key / local Ollama) — parity; revisit as a "free AI" angle later.
