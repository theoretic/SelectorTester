# Changelog

## 0.1.0 (24.09.26)

- Admin page under Setup with template select, selector input and results table.
- `./find/` endpoint: `findRaw()` rows in chunks, user names and paths in batched queries.
- `./page/` endpoint: page properties and all fields rendered by `Fieldtype::markupValue()`.
- `<selector-tester-results>` element: sortable columns, client-side filter, "get more", detail modal.
- Module setting: rows per chunk.
- `tests/smoke.php` against a real install, `tests/harness.php` for the UI without a login.
