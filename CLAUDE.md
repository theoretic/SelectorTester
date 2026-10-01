# SelectorTester

ProcessWire 3 Process module: admin page Setup > Selector Tester. Form posts over `fetch` to
`./find/` (JSON rows), row click posts to `./page/` (JSON detail), shown in a UIkit modal.
Superuser only. Successor of the guarded script `tools/selector.php` on atis.pro sites.

## Layout

| File | Role |
|---|---|
| `SelectorTester.module.php` | Process: `___execute()` page + form, `___executeFind()`, `___executePage()`, public `findRows()`, `resolvePaths()`, `userNames()`, `pageDetail()`, config `maxResults` |
| `SelectorTester.js` / `.css` | Auto-loaded by `Process::init()` (basename = class). Classic script, runs in `<head>` before UIkit and DOM. `<selector-tester-results>` custom element (light DOM) + controller |
| `tests/smoke.php` | CLI smoke test against a real install, including endpoint validation |
| `tests/harness.php` | Router for `php -S`: the real page and endpoints as superuser, no login. Refuses any SAPI but `cli-server` |

No build step, no composer, no npm. Ship no `.min.*` files: core would prefer them over the sources.

## Commands

```bash
D:/work/sites/modules/PHP-8.5/php -l SelectorTester.module.php
node --check SelectorTester.js
D:/work/sites/modules/PHP-8.5/php tests/smoke.php D:/work/sites/home/davinci.osp/application/webroot
D:/work/sites/modules/PHP-8.5/php -S localhost:8765 -t D:/work/sites/home/davinci.osp/application/webroot tests/harness.php
```

The harness is also in `.claude/launch.json` (`selectortester-harness`) for the browser pane. Local
`.osp` hosts are not reachable from the browser pane, and the real admin needs a login; the harness
covers both. PW core used locally: `D:/install/processwire/wire` (3.0.273, split into subdirs, e.g.
`wire/core/Pages/PagesRaw/PagesRaw.php`). Read it instead of guessing signatures.

Local davinci: `site/modules/SelectorTester` is a junction to this repo, module installed (page 2618,
`/backend/setup/selector-tester/`). Changes here are live there.

## Conventions

- `namespace ProcessWire;`, tabs, LF, `array()`, `$this->wire()->x`, `$this->_()`, escape all output.
- `___` only on execute methods. Helpers are public without `___` so the smoke test can call them.
- JS: vanilla, no semicolons, no ES modules (admin themes print classic `<script>`), no framework.
- Version: integer in `getModuleInfo()` (10 = 0.1.0). `loadModuleFileAssets()` casts it into the
  `?v=` of the asset URLs, a semver string would become 0. Update `CHANGELOG.md`.

## Core behaviour this module depends on

- An execute method returning an array renders a view file, never JSON: endpoints return
  `json_encode()` strings and set `Content-Type` themselves.
- `$config->ajax` needs `X-Requested-With: XMLHttpRequest`; `fetch` does not send it by default.
- On ajax, `admin.php` turns exceptions into JSON with HTTP 200, and `Wire404Exception` into
  `{"error":false}`. Endpoints therefore never throw for expected errors: `jsonError()` sets the status.
- No `permission` key in module info = superuser only (`ModulesLoader::hasPermission()`, strict).
- `findRaw()` honours `objects=`, `entities=`, `flat=`, `nulls=`, `options=` from the selector string
  unless set in `$options`: all are pinned in `rawOptions`.
- `findRaw()` `path`/`url` need PagePaths and pick an arbitrary language row: paths are resolved by
  `resolvePaths()`, one query per tree level, checked against `$pages->getPath()` in the smoke test.
- `findRaw()` with a 0-indexed list of IDs skips PageFinder: one plain SELECT, no status filters.
- PageFinder has no ORDER BY unless a single `parent=` brings one: chunks get `sort=id` appended.
- `$sanitizer->text()` would strip `<` from `created<123`: the selector is only trimmed and length
  checked, then parsed by `Selectors` (syntax errors come back as 400).

## Ideas, not done

- Test from the perspective of another role/user (access checks, `check_access`).
- Remember the last selectors (session or localStorage) and offer them as a history.
- Extra columns chosen per search (any field, via `findRaw` field list).
- Multi-language titles/paths in the table.
