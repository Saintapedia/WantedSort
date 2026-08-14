# Changelog

## Unreleased

- **Security:** CSV formula-injection hardening; `pingLimiter('wantedsort-export')` for web export
- **Fix:** Namespace labels use content language consistently (form, table, CSV)
- **Cleanup:** Shared `namespaceLabel()` / `getLimitOptions()`; `MISER_MAX_LIMIT` applied via `LIMIT_OPTIONS`
- **New:** `maintenance/DumpWantedSort.php` CLI dump (`csv` / `tsv` / `wiki`)

## 1.0.0 — 2026-08-13

Final stable release.

### Features

- **Special:WantedSort** — filterable, sortable list of most-linked missing pages
- Filter by namespace; sort by link count, title, or namespace (asc/desc)
- Pagination with configurable page size
- **CSV export** for **logged-in users** (`?export=csv`)
  - Columns: `title`, `namespace`, `namespace_id`, `links`
  - Cap: 5,000 rows (1,000 under `$wgMiserMode`)
- WANObjectCache for HTML result pages (5 minutes; 1 hour under miser mode)
- Miser-mode safety: lower max page size, capped deep offsets, user notice
- `$wgWantedSortDefaultNamespace` — optional default namespace filter
- Subpage / `?namespace=` pre-selection (e.g. `Special:WantedSort/Category`)

### Requirements

- MediaWiki **≥ 1.43.0**

### Packaging

- `extension.json` version field `1.0.0`
- `LICENSE` (GPL-2.0)
- Deploy guide: `DEPLOY.md`

### Install pin

```bash
git clone --branch v1.0.0 --depth 1 \
  https://github.com/Saintapedia/WantedSort.git WantedSort
```

If you already track the repo, prefer tag **`v1.0.0`** (or the latest **v1.0.x** packaging tag if present).
