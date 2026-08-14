# WantedSort documentation sources

| File | Publish as |
|------|------------|
| [Help-WantedSort.wikitext](./Help-WantedSort.wikitext) | Local wiki **Help:WantedSort** |
| [mediawiki.org.Extension-WantedSort.wiki](./mediawiki.org.Extension-WantedSort.wiki) | [mediawiki.org **Extension:WantedSort**](https://www.mediawiki.org/wiki/Extension:WantedSort) |

The special page help icon uses `addHelpLink( 'Extension:WantedSort' )` → mediawiki.org.

## Local import (Canasta example)

```bash
docker exec -i dev-web-1 php /var/www/mediawiki/w/maintenance/run.php edit.php \
  --wiki=mwdev --summary="Import WantedSort user help" \
  "Help:WantedSort" < docs/Help-WantedSort.wikitext
```

(Path to the wikitext file must be readable inside the container, or pipe from a mounted path.)

## mediawiki.org

1. Log in (e.g. User:PhotographerTom).
2. Open https://www.mediawiki.org/w/index.php?title=Extension:WantedSort&action=edit
3. Paste `mediawiki.org.Extension-WantedSort.wiki` and save.
