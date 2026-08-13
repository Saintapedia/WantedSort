# WantedSort

MediaWiki extension that provides **Special:WantedSort** — a filterable, sortable alternative to core [Special:WantedPages](https://www.mediawiki.org/wiki/Help:Special_pages#Lists_of_pages).

**Release:** [v1.0.0](https://github.com/Saintapedia/WantedSort/releases/tag/v1.0.0) · **Deploy guide:** [DEPLOY.md](./DEPLOY.md)

**Live demo:** [Special:WantedSort on Saintapedia (dev)](https://dev.saintapedia.org/wiki/Special:WantedSort) · local golden: [http://localhost:8080/wiki/Special:WantedSort](http://localhost:8080/wiki/Special:WantedSort)

## Features

- Filter by namespace
- Sort by link count, title, or namespace (ascending / descending)
- Configurable page size with previous/next navigation
- **CSV export** of the filtered set for **logged-in users** (`?export=csv`; capped at 5,000 rows, or 1,000 under `$wgMiserMode`)
- Uses the same SQL shape as modern core WantedPages (threshold, missing-page join, USER/USER_TALK exclusion, MEDIAWIKI source exclusion, redirect HAVING)
- LinkBatch warm-up for result rows
- WANObjectCache for each result page (short TTL; longer under `$wgMiserMode`)
- Under miser mode: lower max page size, capped deep offsets, and a user-visible notice

## Requirements

- MediaWiki **≥ 1.43.0**
- PHP version required by that MediaWiki release

## Installation

1. Clone or copy this extension into your MediaWiki `extensions/` directory as `WantedSort`:

   ```bash
   cd /path/to/mediawiki/extensions
   git clone https://github.com/Saintapedia/WantedSort.git WantedSort
   ```

2. Enable it in `LocalSettings.php` (or your Canasta settings):

   ```php
   wfLoadExtension( 'WantedSort' );
   ```

3. Run `maintenance/update.php` if your deployment requires it after enabling extensions.

4. Visit `Special:WantedSort` on your wiki (example: [https://dev.saintapedia.org/wiki/Special:WantedSort](https://dev.saintapedia.org/wiki/Special:WantedSort)).

## Configuration

Behavior respects core settings and adds one extension-specific option:

| Setting | Default | Effect |
|---------|---------|--------|
| `$wgWantedPagesThreshold` | `1` | Minimum link count (same semantics as Special:WantedPages) |
| `$wgMiserMode` | `false` | Caps limit/offset, lengthens cache TTL, shows a notice |
| `$wgWantedSortDefaultNamespace` | `null` | Integer namespace ID to pre-select when no namespace is specified; `null` shows all namespaces |

### Namespace pre-selection priority

1. Explicit `?namespace=` GET parameter (including empty for "All namespaces")
2. Subpage path — e.g. `Special:WantedSort/Category` or `Special:WantedSort/14`
3. `$wgWantedSortDefaultNamespace` (only when neither of the above is present)
4. All namespaces

### Example: default to the Category namespace

```php
$wgWantedSortDefaultNamespace = NS_CATEGORY; // 14
```

After this, visiting `Special:WantedSort` lands on the Category filter automatically. Users can still select "All namespaces" from the form to override it.

## CSV export

**Requires a logged-in account.** Anonymous visitors see a login prompt; direct `?export=csv` hits redirect to Special:UserLogin.

Use **Export to CSV** on the special page (when signed in), or open the same filters with `export=csv`:

```
/wiki/Special:WantedSort?namespace=14&sort=links&dir=desc&export=csv
```

Columns: `title`, `namespace`, `namespace_id`, `links`. Export ignores pagination (`limit` / `offset`) and returns up to the export cap for the active namespace/sort filters. If the set is larger than the cap, a `# Export truncated at N rows` comment is appended.

## Caching notes

Result **pages** (namespace + sort + dir + limit + offset) are cached in the main WAN object cache:

- Normal: 5 minutes
- Miser mode: 1 hour

This is **not** a full QueryPage/`querycache` rewrite. Live `GROUP BY` queries still run on cache miss. On very large wikis, prefer enabling miser mode and/or using core Special:WantedPages for the unfiltered report.

## License

GPL-2.0-or-later (same as MediaWiki).
