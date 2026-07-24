# Fable Journal — "do your thing"

_Claude Fable 5, 2026-07-23. Brief: free rein on the Nexus repo; document the thought
process and everything done. This file is that document, written as I work._

---

## 1. What I found

Nexus is a NativePHP desktop app: a graphical tinker console + log viewer + Laravel
workbench + Mailpit client, all scoped to an "active project." The FEATURE_ROADMAP
(M1–M5) is fully shipped — structured output envelopes, deep logs with click-to-source,
workbench panels, run↔log correlation, the mail hub. The codebase is small and unusually
consistent:

- **Backend pattern:** thin controllers → services (`TinkerRunner`, `ArtisanRunner`),
  everything resolves the active project via `Setting::current()->active_project_id`,
  errors come back as `422 {error}`.
- **Frontend pattern:** one Inertia page (`Console.vue`) + components, `lib/http.js`
  for JSON endpoints, heavy components lazy-loaded, per-project state kept in maps
  keyed by project id.
- **The crown jewel** is the Tier B envelope: any PHP value → typed JSON → rendered as
  Table/Tree/SQL tabs. Everything downstream renders through it.

## 2. What's worth building

The roadmap's own framing is the tell: Nexus wins by being **the hub** — one window
that links everything about the active project. Reading the deferred list and using
the app's architecture as a constraint, I picked four features that compound each
other rather than four disconnected ideas:

| Feature | Why this one |
| --- | --- |
| **⌘K command palette** | A hub with four tabs and N projects needs a switchboard. Every feature below becomes a palette entry, so the palette multiplies everything else. Zero new dependencies — a ~60-line fuzzy scorer is plenty. |
| **Database browser** (Workbench panel) | The most glaring gap for a "Laravel workbench": you can see models and migrations but not the actual tables/data. Laravel already ships `db:show --json` and `db:table --json`, and the Tier B envelope already renders row sets — so this is mostly *wiring existing muscle together*, exactly the "orchestrate, don't reinvent" principle. |
| **Snippet library** (deferred Tier D, minus the sharing) | A REPL you use daily accumulates incantations. Per-project + global snippets, saved from the editor, inserted from the palette. |
| **Run history** | Every tinker run is already a POST through one controller — recording `(code, ok, duration)` is nearly free, and "what was that query I ran yesterday?" is a real daily pain. Restore from a popover or the palette. |

Things I considered and rejected:

- **A Ray-style dump receiver** — genuinely differentiating, but it needs a listener
  socket + a per-project client package; too much new surface area to land well in one
  pass alongside the above. Noted as the best "next" candidate.
- **Visual redesign** — the UI is already coherent (neutral palette, emerald accent,
  dark/light). A redesign would churn every file for taste reasons. Instead the palette
  adds the "feels like a real tool" polish where it's actually felt: the keyboard.
- **Docker/SSH remote execution** — the roadmap explicitly calls it parity-not-
  differentiation; agreed.

## 3. Design decisions

### Command palette
- **No dependency.** `lib/fuzzy.js`: subsequence match with a scorer that rewards
  word-boundary hits, start-of-string, and contiguity. Tested with `bun test` like
  `logParser.js`.
- **Two-level model kept flat:** the palette gets one list of `{ group, label, hint,
  action }` commands built by `Console.vue` from live state (projects, snippets,
  history, tabs, actions). Grouped rendering, single ranked keyboard order.
- **⌘K opens it anywhere** — including while the CodeMirror editor is focused (listener
  on `window`, capture phase). Esc closes, ↑/↓/Enter navigate, mouse works too.

### Database browser
- **Schema via artisan** (`db:show --json`, `db:table {name} --json`) through the
  existing `ArtisanRunner` — no new process machinery.
- **Rows via the existing structured tinker pipeline**: generate
  `DB::table('x')->offset(n)->limit(50)->get()` server-side (table name sanitized to
  `[A-Za-z0-9_]`, never interpolated from free text), run through
  `TinkerRunner::runStructured()`, return the envelope, render with the existing
  `OutputTable`. The panel gets paging by shifting the offset.
- Read-only by design. Writes stay in the tinker editor where they're explicit.

### Snippets & history
- Two small tables. `snippets.project_id` nullable → null means global.
- History is written inside the existing tinker POST (code, ok flag, duration ms,
  project) and pruned to the newest 100 rows per project in the same request — no
  scheduler needed.
- History restore loads the code into the buffer but does **not** auto-run it —
  restoring a `->delete()` should never re-execute by surprise.

### Testing
Per house convention: PHPUnit feature tests target the app itself as the active
project (the `WorkbenchTest::activateSelf()` trick); the fuzzy scorer gets a bun test
next to `logParser.test.js`. (Per repo owner's workflow, I write tests but don't run
them — builds and test runs are done by the owner.)

## 4. Build log

- ✅ Read the whole codebase; wrote this assessment.
- ✅ **Backend — snippets & history.** `snippets` (nullable `project_id` = global) and
  `runs` migrations; `Snippet` model with a `visibleTo` scope; `Run::record()` which
  inserts and prunes to the newest 100 per project in the same request.
  `SnippetController` (index/store/destroy — `updateOrCreate` so saving the same name
  twice overwrites instead of duplicating) and `HistoryController` (index/clear).
  `TinkerController` now times each run with `hrtime()` and records it; "ok" is defined
  as "an envelope came back," i.e. the code executed to completion.
- ✅ **Backend — database browser.** Three `WorkbenchController` endpoints:
  `db/tables` (`db:show --json --counts`), `db/table` (`db:table {name} --json`), and
  `db/rows`, which generates `DB::table('x')->offset(n)->limit(50)->get()` server-side
  (table name stripped to `[A-Za-z0-9_]`, offset cast to int — user text never reaches
  the query) and runs it through `TinkerRunner::runStructured()`, returning a Tier B
  envelope. New muscle: zero. New wiring: three thin methods.
- ✅ **`lib/fuzzy.js` + tests.** Subsequence scorer with start/boundary/contiguity
  bonuses, drift penalty, and a shorter-target tie-break. While hand-checking the test
  expectations against the scorer I caught my own bug — the initial `fuzzyFilter('m', …)`
  test assumed insertion order, but the length tie-break correctly ranks `Mail` above
  `Migrations` — and fixed the test, not the scorer (the behavior is the designed one).
- ✅ **`CommandPalette.vue`.** Flat ranked list with group headers whenever the group
  changes between adjacent rows — so browsing (empty query) reads as grouped sections
  and searching reads as one ranked list. ⌘K listener lives in `Console.vue` on the
  window in **capture phase**, so it wins even while CodeMirror has focus (and beats
  CM's own Ctrl-K binding). Commands are plain `{group, label, hint, action}` objects
  built in a computed from live state: actions, tab/panel navigation (deep-links into
  workbench panels via a new `panel` model on `Workbench.vue`), project switching,
  snippets, and the 8 most recent runs (fetched fresh each time the palette opens).
- ✅ **`DatabasePanel.vue`.** Left column = tables with row counts; right = Rows
  (envelope → the existing `OutputTable`, offset paging, Next disabled when a page
  comes back short) or Schema (columns via `toTable`, indexes listed with
  unique/primary badges). `db:show`'s JSON keys have drifted across Laravel versions,
  so the panel normalizes `table ?? name` / `rows ?? count` defensively. "Open in
  Tinker →" hands the query to the editor for when browsing turns into real work.
- ✅ **Snippets & history UI.** `SaveSnippetModal` (a modal because Electron doesn't
  implement `window.prompt()`), toolbar bookmark/clock buttons, `HistoryModal` with
  status dot + duration + relative age. Deliberate choice: restoring history or
  inserting a snippet only loads the buffer — **nothing auto-runs**, because restored
  code can be destructive (`->delete()`).
- ✅ **Tests.** `fuzzy.test.js` (bun) covering scoring properties, not just examples;
  `SnippetTest` (scoping, overwrite semantics, cross-project isolation, validation);
  `HistoryTest` (ordering, per-project pruning at the cap, scoped clear);
  `WorkbenchTest` extended with db-endpoint tests that point Nexus at itself, the
  house trick. All new PHP passes `php -l`; per repo convention I did not run
  `php artisan test` / `bun test` — that's the owner's step.

## 5. What I'd do next

1. **Ray-style dump receiver** — a `dump()`/`ray()`-like sink: NativePHP child process
   listening on a local port, target apps get a tiny helper, output renders through the
   existing envelope views. This is the strongest remaining differentiator.
2. Palette v2: index route names and model names as jump targets ("open route", "query
   model") — the endpoints already exist.
3. Snippet management UI (rename, edit body) — today it's save/insert/delete.
4. DatabasePanel: per-column sort pushed into the generated query instead of the
   client-side page sort.

## 6. Notes for the owner

- Run `php artisan migrate` once (adds `snippets` and `runs`).
- `bun test resources/js/lib` covers `fuzzy.js` alongside the log parser; the three new
  PHPUnit files run with the usual `php artisan test`.
- The two feature tests that browse a real database point at this repo itself and read
  the `migrations` table via `db:show`/tinker — they assume `database/database.sqlite`
  exists and is migrated (it is, in this checkout).

---

# Round 2 — the dump receiver

_The owner opened a second free-rein round (and rightly reminded me between rounds
that committing is never my job — round 1's commit was undone; the work now sits
uncommitted in the working tree). Item 1 from "What I'd do next" — the Ray-style
dump receiver — is the obvious pick, and this time it gets the full pass I didn't
have room for in round 1._

## R2.1 The unlock: no client package needed

My round-1 hesitation was "needs a listener socket + per-project client package."
Reading Symfony VarDumper's source killed the second half of that: `VarDumper::register()`
already checks `$_SERVER['VAR_DUMPER_FORMAT']` — when it's `server`, every `dump()`/`dd()`
in the app is wrapped in a `ServerDumper` pointed at `VAR_DUMPER_SERVER` (default
`127.0.0.1:9912`). Laravel loads `.env` into `$_SERVER` before the first dump, and
var-dumper ships with every Laravel app. So the *entire* client side is two `.env` lines —
which Nexus's existing `EnvWriter` (built for Mailpit auto-wiring in M5) can write.
And `ServerDumper` falls back to normal in-page dumps when the server is down, so
connecting a project costs nothing when Nexus isn't running.

That flips the feature from "too much surface" to "pure orchestration": every piece
maps onto muscle the app already has —

| Need | Existing muscle |
| --- | --- |
| Listen on TCP without blocking `artisan serve` | `ChildProcess` pattern from the log tail (M2) — and `ChildProcess::artisan()` exists, so the listener is just a Nexus artisan command |
| Decode payloads | `Symfony\...\Server\DumpServer` is already in `vendor/` (var-dumper ships it) |
| Push to the UI | `nativeEvents.js` MessageReceived fan-out, same as the tail |
| Wire up a project | `EnvWriter`, same read/repair line-editing as MAIL_* |
| Jump to the `dump()` call site | `/editor/open` (A3 click-to-source) — the dump context includes file + line |

## R2.2 Design decisions

- **Transport format:** the artisan command (`nexus:dump-server`) emits one JSON object
  per line on stdout: `{type: 'dump', ts, source: {name, file, line}, text}` plus a
  `{type: 'ready'}` handshake. Line-oriented JSON because the Electron bridge delivers
  arbitrary chunks — the viewer buffers partials exactly like the log tail does.
- **Rendering:** `CliDumper` text (colors off) in a `<pre>`, not `HtmlDumper`. HtmlDumper's
  collapsible output needs its own injected JS, which dies under `v-html` and would push
  me toward per-dump iframes. Text keeps fidelity, matches the app's monospace aesthetic,
  and the interesting structure lives one click away anyway (the call site opens in the
  editor). Possible later upgrade: map `Data` into the Tier B tree.
- **Formatter as a service** (`DumpFormatter`), not inline in the command — so the
  Data→array conversion is unit-testable with a real `VarCloner` without sockets.
- **Server lifecycle:** started idempotently when the Dumps tab mounts (stop-then-start,
  like the log tail); `persistent: true` so a crashed listener resurrects. One global
  server, not per-project — dumps carry their source path, and the panel tags each entry
  with which project it came from.
- **Safety:** `DumpServer::listen` already restricts `unserialize` to `Data`/`Stub`
  classes; the callback wraps formatting in a catch so one weird payload can't kill the
  listener.

## R2.3 Build log

- ✅ **Verified the unlock before building.** Confirmed in `vendor/` that
  `VarDumper::register()` honors `VAR_DUMPER_FORMAT=server` + `VAR_DUMPER_SERVER`, that
  `Server\DumpServer` ships with var-dumper (payload `unserialize` already restricted to
  `Data`/`Stub`), and that NativePHP's `ChildProcess::artisan()` + fake's `assertArtisan`
  exist. No assumptions survived unchecked.
- ✅ **`DumpFormatter`** (service, unit-testable without sockets): `Data` + context →
  `{type, ts, source{name,file,line}, text}`; CliDumper, colors off, 64KB cap with a
  truncation marker so nobody's 50MB collection melts the renderer.
- ✅ **`nexus:dump-server`** artisan command: `DumpServer::listen()` → one JSON object
  per stdout line, `{type:'ready'}` handshake, `{type:'error'}` when the port's taken,
  per-payload try/catch so a weird dump can't kill the listener.
- ✅ **`EnvWriter`** grew `dumpStatus()` / `connectDumps()`, and the shared
  write-only-what's-wrong loop got extracted into a private `apply()` (used by Mailpit
  too — net code *removed* from `connectMailpit`). Subtlety handled: `VAR_DUMPER_SERVER`
  absent still counts as connected when we host var-dumper's default (127.0.0.1:9912).
- ✅ **`DumpController`**: status / start (stop-then-`ChildProcess::artisan`,
  `persistent: true` so a dead listener resurrects) / stop / connect. One global
  receiver, not per-project — entries carry their own source paths.
- ✅ **Frontend.** `lib/lineBuffer.js` (chunk→line reassembly, bun-tested) →
  `lib/dumpStream.js` — module-level reactive refs, started from `Console.vue` on mount,
  **not** inside the tab: tabs are v-if-destroyed, and dumps that arrive while you're
  elsewhere must still be captured. That also bought the Dumps tab an unseen-count badge
  for free. `DumpsViewer.vue`: live feed (newest first), filter, pause/resume, clear,
  one-click "Route {project}'s dump() here" (disabled with a reason when there's no
  .env), click-to-source through the existing `/editor/open`, and an empty state that
  teaches the feature.
- ✅ **Proved it end-to-end** (allowed verification, not the test suite): launched
  `nexus:dump-server` on a scratch port, ran `VAR_DUMPER_FORMAT=server php -r "dump([…])"`
  in a second process, and watched the ready line + a correctly formatted dump entry
  come out stdout. The TCP → decode → format → JSON-line chain is real, not theoretical.
- ✅ **Tests written** (not run, per house rules): `DumpFormatterTest` (5 cases incl.
  truncation), `EnvWriterTest` +6 dump-wiring cases (incl. the default-server subtlety
  and idempotency), `DumpTest` feature coverage with `ChildProcess::fake()` +
  `assertArtisan`, `lineBuffer.test.js` (4 cases incl. three-chunk splits).

## R2.4 Notes for the owner

- No new migrations this round; no new dependencies anywhere.
- Try it: open the Dumps tab, click "Route {project}'s dump() here," then hit any page
  of that project (or `dump()` in its tinker) — entries appear live with a call-site
  link. `dd()` works too (it still halts the request after sending).
- The `.env` change is safe to leave permanently: with Nexus closed, `ServerDumper`
  falls back to normal in-browser dumps automatically.
- If 9912 is ever taken by another dump-server, the panel will show the bind error
  verbatim — that's the `{type:'error'}` path.
- Left uncommitted on `fable`, stacked on round 1's changes, per the never-commit rule.
