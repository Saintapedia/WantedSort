# WantedSort

MediaWiki extension that provides **Special:WantedSort** — a filterable, sortable alternative to core [Special:WantedPages](https://www.mediawiki.org/wiki/Help:Special_pages#Lists_of_pages).

## Features

- Filter by namespace
- Sort by link count, title, or namespace (ascending / descending)
- Configurable page size with previous/next navigation
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

4. Visit `Special:WantedSort`.

## Configuration

No extension-specific config is required. Behavior respects core settings:

| Setting | Effect |
|---------|--------|
| `$wgWantedPagesThreshold` | Minimum link count (same semantics as Special:WantedPages) |
| `$wgMiserMode` | Caps limit/offset, lengthens cache TTL, shows a notice |

## Caching notes

Result **pages** (namespace + sort + dir + limit + offset) are cached in the main WAN object cache:

- Normal: 5 minutes
- Miser mode: 1 hour

This is **not** a full QueryPage/`querycache` rewrite. Live `GROUP BY` queries still run on cache miss. On very large wikis, prefer enabling miser mode and/or using core Special:WantedPages for the unfiltered report.

## License

GPL-2.0-or-later (same as MediaWiki).
