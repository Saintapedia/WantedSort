# WantedSort production deploy

**Stable release: [v1.0.2](https://github.com/Saintapedia/WantedSort/releases/tag/v1.0.2)**  
Pin prod to this tag (or newer `v1.0.x`). Do not track floating `main`.

See [CHANGELOG.md](./CHANGELOG.md) for details.

## Install (prod or any Canasta wiki)

1. Place the extension (prefer the tag, not floating `main`):

   ```bash
   cd /path/to/mediawiki/w/extensions   # or user-extensions on Canasta
   git clone --branch v1.0.2 --depth 1 \
     https://github.com/Saintapedia/WantedSort.git WantedSort
   ```

   On Canasta, if code lives under `user-extensions/WantedSort`, ensure a symlink:

   ```bash
   ln -sfn ../user-extensions/WantedSort /path/to/w/extensions/WantedSort
   ```

2. Enable:

   ```php
   wfLoadExtension( 'WantedSort' );
   ```

   Canasta `settings.yaml` example:

   ```yaml
   extensions:
     - WantedSort
   ```

3. Optional config:

   ```php
   // Pre-select Category namespace on first visit
   $wgWantedSortDefaultNamespace = NS_CATEGORY; // 14

   // Optional: throttle web CSV export (action key wantedsort-export)
   // $wgRateLimits['wantedsort-export'] = [ 'user' => [ 10, 60 ] ]; // 10/min
   ```

   Respects core `$wgMiserMode` and `$wgWantedPagesThreshold`.

4. Restart web (Canasta: `canasta restart -i <instance>`) and confirm **Special:Version** lists WantedSort **1.0.2**.

No database schema changes — `update.php` is not required for this extension.

### CLI dump (shell)

```bash
php maintenance/run.php extensions/WantedSort/maintenance/DumpWantedSort.php \
  --limit 1000 --format csv > wanted.csv
```

## Smoke checklist

| Check | Expected |
|-------|----------|
| Open `Special:WantedSort` (anon) | Filters + table load |
| Anon export area | “Log in to export results to CSV.” (no download link) |
| Anon `?export=csv` | HTML login / not a CSV attachment |
| Logged-in **Export to CSV** | `Content-Type: text/csv`, file `wantedsort.csv` |
| Filter namespace / sort / next page | Works |
| `Special:WantedSort/Category` | Namespace pre-selected |
| DumpWantedSort CLI | Valid CSV/TSV/wiki to stdout |
| Slow-query log (first day) | No runaway GROUP BY from export spam |

## Performance notes

- HTML result **pages** are WAN-cached (5 min; 1 h under `$wgMiserMode`).
- **CSV export is not cached** and runs a live grouped query (max 5 000 rows, or 1 000 under miser mode). Login-only + optional rate limits reduce abuse.
- Prefer `$wgMiserMode = true` on large production wikis.

## Local Canasta status (this workspace)

| Instance | URL | Status |
|----------|-----|--------|
| **dev** (golden) | http://localhost:8080/wiki/Special:WantedSort | Sync from repo; pin **v1.0.2** |
| **sandbox** | http://localhost:8081/wiki/Special:WantedSort | Sync from repo; pin **v1.0.2** |
| **dev.saintapedia.org** | https://dev.saintapedia.org/wiki/Special:WantedSort | Checkout **v1.0.2** on host when deploying |

### Pin remote / prod host

```bash
cd /path/to/w/extensions/WantedSort   # or user-extensions/WantedSort
git fetch --tags origin
git checkout v1.0.2
# ln -sfn ../user-extensions/WantedSort /path/to/w/extensions/WantedSort
# restart web; confirm Special:Version → WantedSort 1.0.2
```

If `extension.json` is missing, the whole wiki can fatal on every request. Keep the load line only when the directory is present.

## Rollback

```bash
cd extensions/WantedSort && git fetch --tags && git checkout v1.0.0
# or disable WantedSort in settings.yaml / LocalSettings and restart
```
