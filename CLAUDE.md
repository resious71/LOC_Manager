# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project status

All three stages are implemented (backend APIs + `tests/run.php`, and the UI: `index.html`, `assets/app.js`, `assets/style.css`, `lib/` with Chart.js 4.5.1 and JSZip 3.10.2). Specs and tools:

- `prd.md` — the authoritative spec (v2, Korean). Read it fully before any work.
- `prompt-1-server.md`, `prompt-2-counter.md`, `prompt-3-ui.md`, `prompt-4-query.md` — staged implementation prompts, executed **in order** (1: config/history API/env check → 2: upload/unzip/cloc runner/tests → 3: UI/chart/history integration → 4: read-only LOC 조회 tab, PRD v3 FR-9). Each stage says what *not* to build yet; respect those boundaries. All four are implemented.
- `ctool/cloc-<version>.exe|.pl` — vendored cloc (2.10 today, GPL v2+; notice in `ctool/NOTICE.md`, license text `ctool/COPYING-GPL-2.0.txt`, summary in `THIRD_PARTY_NOTICES.md`). Never modify or regenerate them, and keep the notice files with any cloc you add. The project itself is MIT (`LICENSE`). `cloc_select()` in `api/cloc_runner.php` scans `ctool/` with `CLOC_FILE_PATTERN` and uses the highest version (version_compare) whose `--version` runs, falling back to the next one; there is no fixed CLOC_EXE path anymore — use `cloc_exe_path()` / `cloc_command()`.

An earlier Node.js draft was abandoned (PRD §11); do not reintroduce Node.js.

## What it is

An intranet web app that measures CLOC per component. The browser selects a local folder or zip, the server runs cloc on each component, results render as a Chart.js multi-series (two-ring) pie + table, and can be saved as JSON into `history/`. Saved history is browsed in the default **LOC 조회** tab (per component, or per component group with a 3-ring chart) and in the 측정 이력 tab.

## Stack constraints

- XAMPP for Windows (Apache 2.4 + PHP 8.1+), deployed under `htdocs` (e.g. `C:\xampp\htdocs\LOC_Mgmt`).
- Plain HTML + JS + PHP only. No Composer, no framework, no database.
- No external network access at runtime: Chart.js and JSZip are loaded from `lib/` (`chart.umd.js`, `jszip.min.js`) — no CDNs, no web fonts. The UI must still work (table only) if `lib/chart.umd.js` is missing.

## Architecture (PRD §8)

```
index.html, assets/{app.js,style.css}   UI (vanilla JS IIFE, no build step; CSP meta allows only 'self')
lib/                                    chart.umd.js, jszip.min.js
config.php                              all paths/limits/timezone in one place (__DIR__-based)
api/common.php                          JSON response helper, error shape, name sanitizer, body reader
api/env.php                             GET environment check (each item: ok + message w/ fix)
api/upload.php                          POST chunked zip upload -> work/<jobId>/upload.zip
api/measure.php                         POST jobId+mode+excludes -> results; GET ?jobId= -> progress
api/jobs.php                            (internal) upload_chunk, extract_zip_safely, resolve_components, measure_job, cleanup_stale_work
api/cloc_runner.php                     (internal) exe/pl selection, cloc_run, JSON + --ignored summarizing
api/history.php                         POST save one component / GET list / GET ?file=
history/  work/  ctool/  tests/         each blocked with its own .htaccess AND by the root .htaccess <If> (defense in depth)
tests/run.php                           framework-free PHP tests
```

Data flow: browser filters excluded dirs/exts and builds a STORE-mode zip with JSZip (or uses a selected zip as-is) → uploads in 8MB chunks → `measure.php` extracts to `work/<jobId>/src/`, determines components, runs cloc sequentially per component, returns results, and deletes `work/<jobId>` in a `finally` → user clicks save → one `history/<Component>_YYMMDDN.json` per component.

## Rules that span multiple files

- **No shell strings.** Always invoke cloc/perl via `proc_open` with an argument array. Exclude values are whitelisted to `[A-Za-z0-9._*-]`.
- **cloc invocation:** `--json --quiet --exclude-dir=<csv> --exclude-ext=<csv> <componentDir>`. Default runner is the exe; fall back to `perl ctool/cloc-2.10.pl` only if the exe fails and perl exists; record `runner: "exe"|"pl"`. Set `TEMP` to a writable dir (e.g. `work/_tmp/`) because the exe self-extracts. Per-component timeout (120s default) kills the process and marks only that component failed. Read stdout/stderr non-blocking.
- **Output conversion:** drop `header` and `SUM`; map to `{language, files: nFiles, code, comment, blank}`; server always recomputes `totals` from `languages`. Empty cloc output = zeros, not failure.
- **Component rules (FR-2):** `byFolder` = each immediate subfolder is a component; if the root contains a single folder, descend into it; loose root files go into `_root`. `single` = whole source named after folder/zip name (sans extension).
- **Default excludes (FR-3):** dirs `node_modules .git .svn bin obj dist build target vendor __pycache__ .idea .vs`; exts `min.js min.css map`. Applied both in browser zip creation and cloc flags.
- **Upload safety:** `jobId` must match the server-issued random alnum format. Reject zip entries with `../`, absolute paths, or drive letters (zip slip); abort beyond 2GB / 200k files (zip bomb). Handle UTF-8 and CP949 entry names (Korean folder names). Stale `work/<jobId>` and `work/_tmp` older than 24h are purged on each request.
- **History filenames:** `<Component>_YYMMDDN.json`, date in `Asia/Seoul` (from `config.php`), N = per-component per-day run count, **not zero-padded**. Compute N as max existing + 1, create with `fopen($path, 'x')`, retry up to 20 times on collision — concurrent saves must never overwrite. Component names: replace `\ / : * ? " < > |` and control chars with `_`, strip leading `.`, max 80 chars, empty = error. Korean names must survive on Windows.
- **History contents:** never store file paths or source content. Save with `JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT`, UTF-8. `GET ?file=` must use basename + extension checks to stay inside `history/`; list skips corrupt JSON and sorts newest first.
- **API responses:** `Content-Type: application/json; charset=utf-8`, `Cache-Control: no-store`; errors are `{ "error": "<human-readable sentence>" }`. Request bodies capped at 5MB.
- **UI:** light and dark themes via CSS variables, system fonts, no horizontal scroll on narrow screens, never rely on color alone (table + legend with `tabular-nums`). Language slices use shades of their component's color; group small components into "기타" beyond 16. Theme follows the OS setting only (no toggle).

## Non-obvious behavior (verified)

- **cloc exe silently skips any path containing non-ASCII characters** (Korean folder/file names), even on a CP949 system and even from the command line. `extract_zip_safely()` therefore renames unsafe path segments to ASCII (`_u<n>` + original extension, so language detection still works) and returns a `names` map used to report original component names. Tests compare the Korean component against CLI cloc on an ASCII-renamed copy for this reason.
- With `--json`, cloc also writes the `--ignored=<file>` report as a JSON array `[{file, reason}]` (text `path:  reason` otherwise). Reasons containing `Not_Code_` (i.e. `--exclude-ext` hits) are not counted as unrecognized.
- `--exclude-ext=min.js` does work on compound extensions.
- Use `last_segment()` (common.php), not `basename()`, for names that may contain Korean — `basename()` is locale-dependent.

- XAMPP's default `AddHandler cgi-script .pl` would try to execute `ctool/cloc-2.10.pl` over HTTP; the root `.htaccess` denies `.exe/.pl/.cgi/.bat/.cmd`. Once `ctool/.htaccess` went missing and the exe became downloadable — keep the root-level block.
- UI: `env.php` also returns `config` (chunk size, upload max, default excludes) so `app.js` doesn't duplicate `config.php`. The rule textareas are pre-filled with built-in defaults at startup (then replaced by server defaults if untouched) — otherwise a click before `env.php` responds sends empty excludes.
- Chart colors/grouping are ranked by total lines (not the selected metric) so colors stay stable when switching metric; >16 components → top 15 + '기타' (16-color pastel palette `--c1..--c16` in style.css; c9–c16 are the in-between hues, slightly darker).
- Page frame follows the look of the sibling intranet portal pages (user request): body 85vw × 90vh centered, header panel with centered icon+title and folder-style tabs, `main.page` scrolls internally, slate color tokens, 13px buttons. Keep new UI consistent with it.
- cloc runs once per component with `--by-file` (cwd = component dir, target `.`); `cloc_group()` derives `languages` (identical to cloc's default by-language output — tested), `folders` (first-level subfolder inside the component) and `exts`. Renamed (non-ASCII) paths are mapped back via `extract_zip_safely()['paths']` + each component's `safeRel/origRel`. History stores `folders`/`exts` only when sent (older records lack them → UI shows "정보 없음"). Folder *names* are stored; file names/paths never are.
- History trend is built client-side from `GET api/history.php` list items grouped by exact component name — no extra API. Two charts side by side (user request): stacked vertical bars of code/comment/blank (pastel `--c1/--c2/--c4`) and a straight-segment line of total-LOC change vs. the previous run (first run = null). Charts show only the latest `TREND_MAX_RUNS` (10); the table below lists every run.
- The header house icon links to the portal (`../`). Its image is `htdocs/icons/house.png`, but it must be loaded as `../?icon=house.png` (the portal index.php serves icons) because XAMPP's `Alias /icons/` in httpd-autoindex.conf hijacks `/icons/` URLs.
- "Export to Excel" builds a real .xlsx client-side (`assets/xlsx-export.js`: minimal SpreadsheetML zipped with JSZip, inline strings, styles for header/#,##0/0.0%) from `exportSheets(model)` in app.js, which mirrors the on-screen table for the current dim/metric. E2E validates the download with openpyxl (warnings = failure).
- Outer-ring item colors are per item *name* (user request, deviates from PRD FR-5.4 "shades of the component color"): `layout()` ranks item names by total lines across all components and assigns `distinctColor(i)` (golden-angle hue, 3 alternating lightness levels, pastel); the same name gets the same color in every component. Inner ring keeps the 16-color component palette.
- Exclude rules are managed in the 관리 → 제외 규칙 관리 tab: `api/rules.php` (GET/POST/PUT/DELETE, POST {action:'reset'}) over `api/rules_store.php`, persisted in `settings/exclude_rules.json` (flock read-modify-write; absent file = seeded from `DEFAULT_EXCLUDE_*`). Enabled rules = `current_default_excludes()`, used by `env.php` config (UI defaults) and by `measure_job()` when the request omits excludes. E2E backs up/restores this file (atexit).
- "제외 파일" patterns become `cloc --not-match-f=^(…)$` (`cloc_file_regex()`) and are **case-sensitive** (user request; dir/ext rules stay case-insensitive, and so does the rule duplicate check for dir/ext only); the browser applies the same glob (`globToRegExp(f, true)`) when zipping folders.
- Bump `?v=N` on the asset URLs in `index.html` after changing `assets/*` (browser cache).
- Admin auth: the password starts as `admin` (`ADMIN_DEFAULT_PASSWORD` in config.php) and is changed in 관리 → **비밀번호 변경** (`PUT api/auth.php {currentPassword, newPassword}`, admin token required). `api/admin_password.php` stores a bcrypt hash plus a `version` in `settings/admin_password.json` (gitignored; deleting it restores the default; a corrupt file is an error, never a silent fallback). Tokens record the password version (`issue_admin_token()` / `is_admin()` in common.php), so a change invalidates every other page's token; the changing page gets a new token. Wrong current passwords count toward the 5-fails lockout. `GET/POST auth.php` return `defaultPassword` → warning banner `#admin-default-pw`. Password inputs (login modal, change form) exist in the DOM only while shown, to avoid browser autofill into other fields. E2E must back up/remove `settings/admin_password.json` and restore it at exit.
- 관리 sub-tabs: `ADMIN_TABS = ['rules', 'history', 'password', 'guide']` (pastel-1..4 buttons; 2 per row under 600px). **가이드** (panel `#admin-guide`, pastel-3 green button) It is static HTML in `index.html` (installation, php.ini, folders, cloc/lib, usage, exclude rules, history files, config.php, auth, troubleshooting) and mirrors `docs/GUIDE.md` (the detailed Korean manual; `README.md` / `README.en.md` are short landing pages with screenshots in `docs/images/`); when setup or behavior changes, update both and add a line to `CHANGELOG.md` under `[Unreleased]`.

## LOC 조회 tab (v3, `prompt-4-query.md`, PRD FR-9)

- Top tabs: `TABS = ['query', 'measure', 'history', 'admin']`; `state.tab` starts as `'query'` and startup calls `showTab('query')`. ←/→/Home/End move between top tabs (End → 관리 still asks for the password). `admin-logout` still returns to `measure` (unchanged).
- Read-only. No new API or stored fields: only `GET api/history.php` (list) and `?file=`. The list is re-read every time the tab opens (`loadQueryList`) and by `#query-reload`. State lives in a separate `query` object (declared right after `state`): its own `items` copy (never touches `state.histItems`), a per-list-load `recs` cache, per-sub-tab selections `query.comp {component, version, file}` / `query.group {parent, version, batchId}`, `compModel` / `groupModel`, sequence numbers that drop stale responses, and its own trend charts.
- `currentModel()` returns `query.compModel` or `query.groupModel` on the query tab, so the shared `#result` card, `renderModel`, Excel export and theme redraw all work unchanged. The save box stays hidden because these are `kind: 'history'` models.
- Sub-tabs use the `.query-tab-bar` class with the `.admin-tab-button` styles. Don't give the bar the `.admin-tab-bar` class: `document.querySelector('.admin-tab-bar')` wires the admin keyboard handler to the first match.
- Component sub-tab: `qcState()` normalizes the cascade.
  - Components are listed 가나다순; the default is the component of the newest item.
  - Versions are sorted descending by version (`versionsDesc`, same as the group tab; user request), and the highest is the default; `''` is shown as `(버전 없음)`, last. Option values carry a `v:` prefix so the empty version is selectable.
  - Runs are newest first.
  - It renders `modelFromHistory(rec, file)` (which now also carries `file`, used by the Excel 정보 sheet) plus the trend.
- `renderTrend(view)` is parameterized.
  - `histTrendView()` (the default, so `renderTrend()` keeps 측정 이력's behavior) uses `#trend…` ids.
  - `queryTrendView()` uses the duplicate card with the `qtrend` id prefix.
  - Clicking a run in the query trend selects that run (and its version).
- Group sub-tab:
  - Groups are items with `batchMode === 'byFolder'` and a non-empty `groupNameOf(i)` = `batchParent`, falling back to `sourceName` without the archive extension, like `batchLabel`. The fallback exists because records saved before `batch.parent` existed have an empty parent (typical for older group measurements).
  - **Versions are sorted descending by version** (`versionsDesc`: numeric `localeCompare`, empty last), and the highest version is the default (user request; the component tab uses the same order).
  - The 측정 list is by `batchId`, newest first.
  - All files with the selected `batchId` are read through `withQuerySlot` (a global limit of 6 concurrent GETs; stale loads stop early) with progress in `#qg-progress`. Failed files and `stored < batchSize` are reported in `#qg-notice`.
  - `modelFromGroup` wraps `modelFromHistoryMulti`. Names within one batch are unique, so no date/run suffix appears; it adds `group`, `compFiles` (name → file) and `onComponent`.
- Navigation: `compNameNode()`, `canOpenComponent()`, and the chart `onClick` on dataset 1 are active only when `model.onComponent` exists, i.e. only for query-tab group models; other tabs render exactly as before. `기타 (N개)` is not navigable. Navigation sets `query.comp` and switches to the component sub-tab; `query.group` is untouched, so going back restores the group view.
- 3-ring chart (`isThreeRing(model)`: a group model with `state.dim === 'folder'`):
  - Datasets: 0 = folders (outer), 1 = components, 2 = group (one slice, center disc, name drawn by `outerLabelsPlugin` via `chart.$centerLabel`).
  - The `#group-legend-block` legend and the `#legend-ring` text switch with it.
  - Each ring has its own tooltip.
  - If every component lacks folder data, the outer legend shows a notice.
- Tests (scratch, not in repo):
  - `query_e2e.py`: acceptance criteria 10–17 with POSTed `QT*` history that is deleted at exit, and mocked empty/failing list responses.
  - `baseline.py`: before/after snapshot diff of the measure/history tabs, including Excel output.

## Existing helpers to reuse

`api/common.php` provides: `run_api()` (wraps a handler; `ApiError` → `{error}` with its status, other exceptions → generic 500), `json_response()`/`json_error()`, `require_method()`, `read_json_body()`, `sanitize_component_name()`, `validate_rule_list()`, `last_segment()`, `ensure_dir()`, `ini_bytes()`, `function_usable()`, and `run_process()` — the argument-array `proc_open` runner with timeout + process-tree kill that captures stdout/stderr via temp files in `work/_tmp` (Windows pipes can't be read non-blocking). `cloc_runner.php` calls it with `cloc_env()` (`TEMP`/`TMP` = `work/_tmp`, where the exe keeps its `par-*` cache). All limits live as constants in `config.php`.

## Local environment

- In a default XAMPP install PHP is **not on PATH**: use `C:\xampp\php\php.exe` (developed with PHP 8.2). In Git Bash: `/c/xampp/php/php.exe`.
- `extension=zip` must be enabled in `C:\xampp\php\php.ini` (XAMPP ships with it commented out). Ask before editing a shared server's `php.ini`, and keep a backup.
- A convenient dev setup keeps the project outside `htdocs` and links it with a directory junction (e.g. `mklink /J C:\xampp\htdocs\loc_mgmt <project dir>`, served at `http://localhost/loc_mgmt/`). Never `rmdir /s` or recursively delete through the junction, because that deletes the real project; remove only the link (`cmd /c rmdir C:\xampp\htdocs\loc_mgmt`).
- The :80 Apache may be shared with other apps in the same `htdocs`; don't restart it or edit its config without asking. To test through Apache without touching a running XAMPP instance, start a second `httpd.exe -f <scratch conf>` on another port with an `Alias /LOC_Mgmt` → project dir, `AllowOverride All`, and the PHP module lines from `conf/extra/httpd-xampp.conf`.

## Commands

- Syntax check: `php -l <file>` on every PHP file.
- Quick local server (when XAMPP isn't available): `php -S localhost:8000` from the project root (note: `.htaccess` is not honored by `php -S`).
- Tests: `C:\xampp\php\php.exe tests\run.php` (exit code 0 = all pass; add `-d extension=zip` if zip is off in php.ini). No single-test selector; sections run in order and each cleans up. Tests call `upload_chunk()`/`measure_job()` directly (not HTTP); `measure_job()` accepts a second `$limits` arg (`maxBytes`, `maxFiles`, `componentHook`) for test-only overrides.
- UI end-to-end checks were done with Playwright (pip-installed into a scratch venv, `channel='msedge'`, headless) against `http://localhost/loc_mgmt/`; `set_input_files` works on the `webkitdirectory` input with a directory path. Delete any `history/` files such runs create.
- Verify cloc directly: `ctool/cloc-2.10.exe --version` / `ctool/cloc-2.10.exe --json <dir>`.

Each stage prompt ends by requiring a report that separates what was verified from what could not be verified (e.g. XAMPP-only checks such as `.htaccess` 403s). Follow that.
