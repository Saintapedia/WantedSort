# WantedSort production deploy

**Stable release: [v1.0.0](https://github.com/Saintapedia/WantedSort/releases/tag/v1.0.0)**  
Pin prod to this tag (or the latest `v1.0.x` packaging tag). Do not track floating `main`.

See [CHANGELOG.md](./CHANGELOG.md) for feature details.

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
| **dev.saintapedia.org** | https://dev.saintapedia.org/wiki/Special:WantedSort | **Live** — page + login-gated export observed; Special:Version still reported git `31bfd7f` (pre-v1.0.0). **Pin to tag `v1.0.0` on that host.** |

### Pin remote `dev.saintapedia.org` to v1.0.0

SSH to the host (`165.22.40.203`) and update the extension checkout:

```bash
cd /path/to/w/extensions/WantedSort   # or user-extensions/WantedSort
git fetch --tags origin
git checkout v1.0.0
# ensure symlink if using user-extensions:
# ln -sfn ../user-extensions/WantedSort /path/to/w/extensions/WantedSort
# restart web container if needed, then confirm Special:Version shows v1.0.0 / b80b7b8
```

If `extension.json` is missing, the whole wiki can fatal on every request (`Unable to load the extension WantedSort`). Keep the load line only when the directory is present.

## Rollback

```bash
cd extensions/WantedSort && git fetch --tags && git checkout v1.0.0
# or disable:
# remove WantedSort from settings.yaml / LocalSettings and restart
```
