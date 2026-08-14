# Changelog

## 1.0.1 — 2026-08-14

### Security (from 1.0.0 post-release)

- CSV formula-injection hardening (`WantedSortCsv::formulaSafe`)
- Web export rate limit via `pingLimiter('wantedsort-export')` / `$wgRateLimits['wantedsort-export']`

### Features

- **CLI:** `maintenance/DumpWantedSort.php` (`csv` / `tsv` / `wiki`)

### Fixes / cleanup

- Namespace labels use **content language** consistently (form, table, CSV, CLI)
- Shared `WantedSortQuery` (special page + dump; one GROUP BY implementation)
- Shared `WantedSortCsv` helpers
- `getLimitOptions()` / `LIMIT_OPTIONS`; remove unused `MAX_LIMIT`
- Align CLI CSV main-namespace label with web export (`blanknamespace`)

### Install pin

```bash
git clone --branch v1.0.1 --depth 1 \
  https://github.com/Saintapedia/WantedSort.git WantedSort
```

## 1.0.0 — 2026-08-13

Final stable feature release (filter/sort/pagination, login-gated CSV export, cache, miser caps, default namespace). Prefer **v1.0.1** for security + CLI + shared query cleanup.
