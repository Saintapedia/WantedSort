# WantedSort production deploy

Pinned release: **[v1.0.0](https://github.com/Saintapedia/WantedSort/releases/tag/v1.0.0)**  
Commit: `b80b7b8` (require login for CSV export)

## Install (prod or any Canasta wiki)

1. Place the extension (prefer the tag, not floating `main`):

   ```bash
   cd /path/to/mediawiki/w/extensions   # or user-extensions on Canasta
   git clone --branch v1.0.0 --depth 1 \
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
   ```

   Respects core `$wgMiserMode` and `$wgWantedPagesThreshold`.

4. Restart web (Canasta: `canasta restart -i <instance>`) and confirm **Special:Version** lists WantedSort.

No database schema changes — `update.php` is not required for this extension.

## Smoke checklist

| Check | Expected |
|-------|----------|
| Open `Special:WantedSort` (anon) | Filters + table load |
| Anon export area | “Log in to export results to CSV.” (no download link) |
| Anon `?export=csv` | HTML login / not a CSV attachment |
| Logged-in **Export to CSV** | `Content-Type: text/csv`, file `wantedsort.csv` |
| Filter namespace / sort / next page | Works |
| `Special:WantedSort/Category` | Namespace pre-selected |
| Slow-query log (first day) | No runaway GROUP BY from export spam |

## Performance notes

- HTML result **pages** are WAN-cached (5 min; 1 h under `$wgMiserMode`).
- **CSV export is not cached** and runs a live grouped query (max 5 000 rows, or 1 000 under miser mode). Login-only reduces abuse; watch DB if many editors export often.
- Prefer `$wgMiserMode = true` on large production wikis.

## Local Canasta status (this workspace)

| Instance | URL | Status (2026-08-13) |
|----------|-----|---------------------|
| **dev** (golden) | http://localhost:8080/wiki/Special:WantedSort | **Installed** v1.0.0; smoke passed |
| **sandbox** | http://localhost:8081/wiki/Special:WantedSort | **Installed** v1.0.0; smoke passed |
| **dev.saintapedia.org** | https://dev.saintapedia.org/wiki/Special:WantedSort | **Broken** — `wfLoadExtension(WantedSort)` but files missing on host `165.22.40.203` |

### Fix remote `dev.saintapedia.org`

SSH to the host and either:

```bash
# if Canasta user-extensions layout:
cd /path/to/user-extensions
git clone --branch v1.0.0 --depth 1 \
  https://github.com/Saintapedia/WantedSort.git WantedSort
ln -sfn ../user-extensions/WantedSort /path/to/w/extensions/WantedSort
# ensure settings load WantedSort, then restart web
```

Or remove `wfLoadExtension( 'WantedSort' )` until the files are present (site currently fatals on every request while the load line is active without files).

## Rollback

```bash
cd extensions/WantedSort && git fetch --tags && git checkout v1.0.0
# or disable:
# remove WantedSort from settings.yaml / LocalSettings and restart
```
