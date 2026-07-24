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
