# SelectorTester

Admin page for ProcessWire 3 that runs a selector and shows what it matches, without editing a
template or bootstrapping a script. Replaces the standalone `tools/selector.php` of atis.pro sites.

**Setup > Selector Tester**:

- Pick a template (optional) and type a selector, press **Find**. The page does not reload.
- The results table has ID, title, name, template, status, created, modified, created by,
  modified by, path, and view/edit links.
- Click a column header to sort, click again to reverse. Sorting is done in the browser on the
  raw values, IDs numerically, dates as `Y-m-d H:i:s`.
- Type into **Filter rows** to narrow the rows already loaded. Nothing is sent to the server.
- Click a row (or focus it and press Enter) to open its details: page properties and every field
  of its template, rendered by the field's own Fieldtype, as Lister does.
- **Get more** under the table loads the next chunk and appends it. Sorting and filter stay.

## Selectors

The selector goes to `$pages->find()` as typed, with these additions:

- The chosen template is added as `template=name`. A `template=` in the selector still applies,
  so both must match.
- Without `limit=` or `start=` in the selector, the module adds `start=…, limit=<rows per chunk>`,
  and `sort=id` as the last sort, so chunks never overlap. A single `parent=` without a sort keeps
  the parent's own sort order.
- With `limit=` or `start=` in the selector, it is used as is and **Get more** is not offered.
- The effective selector and the query time are shown above the table.

It runs as superuser: no access control applies. Unpublished and trashed pages still need
`include=unpublished` or `include=all`, as in any `$pages->find()`. Syntax errors and unknown fields
are shown above the table.

Titles, names and paths are those of the default language.

## Access

Superuser only. The module has no permission on purpose: it lists every page, user and system page a
selector reaches, with edit links, so the reference script it replaces had to be guarded too.

## Settings

**Rows per chunk** (default 1000, max 10000) in the module config.

## Performance

A chunk costs one `findRaw()` query for the rows, one for the user names, one per tree level for the
paths, and one `count()` only if the chunk is full. 1236 pages in one chunk take about 15 ms on a local
install. No Page objects are loaded for the table. The detail endpoint loads the one clicked page.

## Endpoints

Both are POST, need the `X-Requested-With: XMLHttpRequest` header and the CSRF token of the form, and
answer JSON with a real HTTP status. Opened directly in the browser, they redirect to the tool.

| URL | Input | Answer |
|---|---|---|
| `./find/` | `template`, `selector`, `start` | `{ok, rows, count, total, start, limit, hasMore, userPaginates, selector, ms}` |
| `./page/` | `id` | `{ok, id, title, viewUrl, editUrl, props: [[label, html]], fields: [{name, label, type, html}]}` |

Errors: `{ok: false, error: true, message}` with 400, 403, 404, 405 or 500.

## Requirements

ProcessWire 3.0.200+, PHP 8.1+, AdminThemeUikit for the modal (another admin theme gets a native
`<dialog>`).

Not related to `ProcessSelectorTest` by Niklas Lakanen. Both can be installed at the same time.

## License

MIT
