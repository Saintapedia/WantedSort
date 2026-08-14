# WantedSort

MediaWiki extension that provides **[Special:WantedSort](https://dev.saintapedia.org/wiki/Special:WantedSort)** — a filterable, sortable alternative to core [Special:WantedPages](https://www.mediawiki.org/wiki/Help:Special_pages#Lists_of_pages).

| | |
|--|--|
| **Release** | [v1.0.2](https://github.com/Saintapedia/WantedSort/releases/tag/v1.0.2) |
| **Deploy** | [DEPLOY.md](./DEPLOY.md) |
| **Changelog** | [CHANGELOG.md](./CHANGELOG.md) |
| **User help (wikitext)** | [docs/Help-WantedSort.wikitext](./docs/Help-WantedSort.wikitext) → on-wiki `Help:WantedSort` |
| **mediawiki.org** | [docs/mediawiki.org.Extension-WantedSort.wiki](./docs/mediawiki.org.Extension-WantedSort.wiki) → `Extension:WantedSort` |
| **Live demo** | [dev.saintapedia.org](https://dev.saintapedia.org/wiki/Special:WantedSort) · [localhost:8080](http://localhost:8080/wiki/Special:WantedSort) |

## Features

- Filter by namespace
- Sort by link count, title, or namespace (ascending / descending)
- Configurable page size with previous/next navigation
- **CSV export** for **logged-in users** (`?export=csv`; capped at 5,000 rows, or 1,000 under `$wgMiserMode`)
- CLI dump: `maintenance/DumpWantedSort.php` (`csv` / `tsv` / `wiki`)
- Same SQL shape as modern core WantedPages (threshold, missing-page join, USER/USER_TALK exclusion, MEDIAWIKI source exclusion, redirect HAVING)
- LinkBatch warm-up; WANObjectCache per result page
- Miser-mode safety: lower max page size, capped deep offsets, user notice

## Requirements

- MediaWiki **≥ 1.43.0**
- PHP version required by that MediaWiki release

## Installation

Prefer a release tag (not floating `main`):

```bash
cd /path/to/mediawiki/extensions
git clone --branch v1.0.2 --depth 1 \
  https://github.com/Saintapedia/WantedSort.git WantedSort
```

Enable in `LocalSettings.php` (or Canasta `settings.yaml`):

```php
wfLoadExtension( 'WantedSort' );
```

No `update.php` schema step is required. Visit **Special:WantedSort**.

Optional: create **Help:WantedSort** from [docs/Help-WantedSort.wikitext](./docs/Help-WantedSort.wikitext) so editors have full on-wiki documentation. The special page help icon links to [Extension:WantedSort](https://www.mediawiki.org/wiki/Extension:WantedSort) on mediawiki.org (publish the page from [docs/mediawiki.org.Extension-WantedSort.wiki](./docs/mediawiki.org.Extension-WantedSort.wiki)).

## Configuration

| Setting | Default | Effect |
|---------|---------|--------|
| `$wgWantedPagesThreshold` | `1` | Minimum link count (same semantics as Special:WantedPages) |
| `$wgMiserMode` | `false` | Caps limit/offset, lengthens cache TTL, shows a notice |
| `$wgWantedSortDefaultNamespace` | `null` | Integer namespace ID to pre-select when none is specified |
| `$wgRateLimits['wantedsort-export']` | *(unset)* | Optional throttle for web CSV export (see below) |

### Namespace pre-selection priority

1. Explicit `?namespace=` GET parameter (including empty for “All namespaces”)
2. Subpage path — e.g. `Special:WantedSort/Category` or `Special:WantedSort/14`
3. `$wgWantedSortDefaultNamespace` (only when neither of the above is present)
4. All namespaces

```php
$wgWantedSortDefaultNamespace = NS_CATEGORY; // 14

// Optional: throttle web CSV export (logged-in users)
$wgRateLimits['wantedsort-export'] = [
	'user' => [ 10, 60 ], // 10 exports per minute
];
```

## Using Special:WantedSort

See the full guide in **[Help:WantedSort](./docs/Help-WantedSort.wikitext)** (install that page on your wiki).

Short version:

1. Open **Special:WantedSort**
2. Choose namespace, sort field, direction, and page size → **Filter**
3. Click column headers to re-sort; use previous/next for pagination
4. Use **What links here** on a row to find incoming links
5. **Export to CSV** (logged-in only) for offline work

## CSV export (web)

**Requires login.** Anons see a login prompt; `?export=csv` redirects to Special:UserLogin when anonymous.

```
/wiki/Special:WantedSort?namespace=14&sort=links&dir=desc&export=csv
```

| Column | Meaning |
|--------|---------|
| `title` | Prefixed page title |
| `namespace` | Human-readable namespace (content language) |
| `namespace_id` | Namespace integer ID |
| `links` | Incoming link count |

Export ignores UI pagination and returns up to **5,000** rows ( **1,000** under `$wgMiserMode` ). A `# Export truncated…` comment is appended if the cap is hit. Leading `=`, `+`, `-`, `@` in cells are neutralized for spreadsheet formula injection.

## Maintenance script

```bash
php maintenance/run.php extensions/WantedSort/maintenance/DumpWantedSort.php [options]
```

| Option | Default | Description |
|--------|---------|-------------|
| `--namespace <id>` | *(all)* | Integer namespace ID only (e.g. `14`, not `Category`) |
| `--sort <col>` | `links` | `links`, `title`, or `namespace` |
| `--dir <dir>` | `desc` | `asc` or `desc` |
| `--limit <n>` | `1000` | Max rows (hard max 50 000; digits only) |
| `--format <fmt>` | `csv` | `csv`, `tsv`, or `wiki` |

```bash
php maintenance/run.php extensions/WantedSort/maintenance/DumpWantedSort.php > wanted.csv

php maintenance/run.php extensions/WantedSort/maintenance/DumpWantedSort.php \
  --namespace 14 --limit 50 --format wiki
```

## Caching notes

Result **pages** (namespace + sort + dir + limit + offset) are cached in the main WAN object cache:

- Normal: 5 minutes  
- Miser mode: 1 hour  

This is **not** a full QueryPage / `querycache` rewrite. Live `GROUP BY` queries still run on cache miss. On very large wikis, enable miser mode and/or use core Special:WantedPages for the unfiltered report.

## Documentation map

| Audience | Document |
|----------|----------|
| End users / editors | On-wiki **Help:WantedSort** ← `docs/Help-WantedSort.wikitext` |
| Admins / install | This README + **DEPLOY.md** |
| mediawiki.org | **Extension:WantedSort** ← `docs/mediawiki.org.Extension-WantedSort.wiki` |
| Release history | **CHANGELOG.md** |

### Publish Help:WantedSort on a wiki

```bash
# From MediaWiki root (example: Canasta web container)
php maintenance/run.php edit.php --wiki=mwdev --summary="Import WantedSort help" \
  "Help:WantedSort" < /path/to/extensions/WantedSort/docs/Help-WantedSort.wikitext
```

Or paste the file contents into a new page **Help:WantedSort** in the browser.

### Publish Extension:WantedSort on mediawiki.org

1. Log in at [mediawiki.org](https://www.mediawiki.org/)
2. Create [Extension:WantedSort](https://www.mediawiki.org/wiki/Extension:WantedSort)
3. Paste contents of `docs/mediawiki.org.Extension-WantedSort.wiki`
4. Save (extension help icon on Special:WantedSort points here)

## License

[GPL-2.0-or-later](./LICENSE) (same as MediaWiki).
