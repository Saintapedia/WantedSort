<?php
/**
 * Special:WantedSort — filterable, sortable wanted-pages report.
 *
 * @license GPL-2.0-or-later
 */

namespace MediaWiki\Extension\WantedSort;

use MediaWiki\Cache\LinkBatchFactory;
use MediaWiki\HTMLForm\HTMLForm;
use MediaWiki\Html\Html;
use MediaWiki\Linker\LinksMigration;
use MediaWiki\MainConfigNames;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Title\NamespaceInfo;
use MediaWiki\Title\Title;
use Wikimedia\ObjectCache\WANObjectCache;
use Wikimedia\Rdbms\Database;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Rdbms\IReadableDatabase;

class SpecialWantedSort extends SpecialPage {

	private const VALID_SORTS = [ 'links', 'title', 'namespace' ];
	private const DEFAULT_LIMIT = 50;
	private const MAX_LIMIT = 500;
	/** Tighter caps when $wgMiserMode is on (parity with QueryPage intent). */
	private const MISER_MAX_LIMIT = 100;
	private const MISER_MAX_RESULTS = 10000;
	/** Short TTL when live queries are cheap enough to re-run. */
	private const CACHE_TTL = 300;
	/** Longer TTL under miser mode so repeated views hit the cache. */
	private const CACHE_TTL_MISER = 3600;

	private IConnectionProvider $dbProvider;
	private LinksMigration $linksMigration;
	private NamespaceInfo $namespaceInfo;
	private LinkBatchFactory $linkBatchFactory;
	private WANObjectCache $wanCache;

	public function __construct(
		IConnectionProvider $dbProvider,
		LinksMigration $linksMigration,
		NamespaceInfo $namespaceInfo,
		LinkBatchFactory $linkBatchFactory,
		WANObjectCache $wanCache
	) {
		parent::__construct( 'WantedSort' );
		$this->dbProvider = $dbProvider;
		$this->linksMigration = $linksMigration;
		$this->namespaceInfo = $namespaceInfo;
		$this->linkBatchFactory = $linkBatchFactory;
		$this->wanCache = $wanCache;
	}

	/** @inheritDoc */
	public function execute( $par ) {
		$this->setHeaders();
		$this->outputHeader();
		$this->addHelpLink( 'Extension:WantedSort' );

		$out = $this->getOutput();
		$out->addModuleStyles( 'ext.wantedSort' );

		$request = $this->getRequest();
		$miserMode = (bool)$this->getConfig()->get( MainConfigNames::MiserMode );

		// Priority: explicit GET param > $par subpage path > wiki config default.
		$nsRaw = $request->getVal( 'namespace' );
		if ( $nsRaw === null && $par !== null && $par !== '' ) {
			$nsRaw = $par;
		}
		$namespace = $this->resolveNamespace( $nsRaw );
		if ( $namespace === null ) {
			$defaultNs = $this->getConfig()->get( 'WantedSortDefaultNamespace' );
			if ( $defaultNs !== null ) {
				$namespace = $this->resolveNamespace( (string)$defaultNs );
			}
		}

		$sort = $request->getVal( 'sort', 'links' );
		if ( !in_array( $sort, self::VALID_SORTS, true ) ) {
			$sort = 'links';
		}

		$dir = $request->getVal( 'dir', 'desc' );
		$dir = ( $dir === 'asc' ) ? 'asc' : 'desc';

		$validLimits = $miserMode ? [ 20, 50, 100 ] : [ 20, 50, 100, 250, 500 ];
		$limit = $request->getInt( 'limit', self::DEFAULT_LIMIT );
		// Snap to a valid discrete option; reject arbitrary crafted values.
		$limit = $this->snapToValidLimit( $limit, $validLimits );

		$offset = max( 0, $request->getInt( 'offset', 0 ) );
		if ( $miserMode ) {
			// Cap deep OFFSET scans (GROUP BY + OFFSET is expensive).
			$offset = min( $offset, self::MISER_MAX_RESULTS );
		}

		if ( $miserMode ) {
			$out->addWikiMsg( 'wantedsort-miser-notice' );
		}

		$this->showFilterForm( $namespace, $sort, $dir, $limit, $miserMode );
		$this->showResults( $namespace, $sort, $dir, $limit, $offset, $miserMode );
	}

	private function showFilterForm(
		?int $namespace,
		string $sort,
		string $dir,
		int $limit,
		bool $miserMode
	): void {
		$lang = $this->getContentLanguage();

		// Build namespace options with "All" pinned first, rest sorted by label
		$allLabel = $this->msg( 'wantedsort-ns-all' )->text();
		$nsOptions = [];
		foreach ( $lang->getNamespaces() as $nsId => $nsName ) {
			if ( $nsId < NS_MAIN ) {
				continue;
			}
			$label = $nsId === NS_MAIN
				? $this->msg( 'blanknamespace' )->text()
				: str_replace( '_', ' ', $nsName );
			$nsOptions[$label] = (string)$nsId;
		}
		ksort( $nsOptions );
		$nsOptions = array_merge( [ $allLabel => '' ], $nsOptions );

		$limitOptions = $miserMode
			? [ '20' => 20, '50' => 50, '100' => 100 ]
			: [ '20' => 20, '50' => 50, '100' => 100, '250' => 250, '500' => 500 ];

		// Explicit field names so GET params are namespace/sort/dir/limit (not wp*).
		$formFields = [
			'namespace' => [
				'type'          => 'select',
				'name'          => 'namespace',
				'label-message' => 'wantedsort-field-namespace',
				'options'       => $nsOptions,
				'default'       => $namespace !== null ? (string)$namespace : '',
			],
			'sort' => [
				'type'          => 'select',
				'name'          => 'sort',
				'label-message' => 'wantedsort-field-sort',
				'options'       => [
					$this->msg( 'wantedsort-sort-links' )->text()     => 'links',
					$this->msg( 'wantedsort-sort-title' )->text()     => 'title',
					$this->msg( 'wantedsort-sort-namespace' )->text() => 'namespace',
				],
				'default'       => $sort,
			],
			'dir' => [
				'type'          => 'select',
				'name'          => 'dir',
				'label-message' => 'wantedsort-field-dir',
				'options'       => [
					$this->msg( 'wantedsort-dir-desc' )->text() => 'desc',
					$this->msg( 'wantedsort-dir-asc' )->text()  => 'asc',
				],
				'default'       => $dir,
			],
			'limit' => [
				'type'          => 'select',
				'name'          => 'limit',
				'label-message' => 'wantedsort-field-limit',
				'options'       => $limitOptions,
				'default'       => $limit,
			],
		];

		$form = HTMLForm::factory( 'ooui', $formFields, $this->getContext() );
		$form->setMethod( 'get' )
			->setAction( $this->getPageTitle()->getLocalURL() )
			->setSubmitTextMsg( 'wantedsort-submit' )
			->setWrapperLegendMsg( 'wantedsort-legend' )
			->setId( 'mw-wantedsort-form' )
			->prepareForm()
			->displayForm( false );
	}

	private function showResults(
		?int $namespace,
		string $sort,
		string $dir,
		int $limit,
		int $offset,
		bool $miserMode
	): void {
		$out = $this->getOutput();

		$page = $this->fetchResultPage( $namespace, $sort, $dir, $limit, $offset );
		$rows = $page['rows'];
		$fromCache = $page['fromCache'];

		// In miser mode, suppress the Next link once we've hit the offset ceiling
		// so users can't loop forever on the same clamped page.
		$hasMore = $page['hasMore']
			&& ( !$miserMode || $offset + $limit < self::MISER_MAX_RESULTS );

		if ( $rows === [] ) {
			if ( $fromCache ) {
				$out->addWikiMsg( 'wantedsort-cached-notice' );
			}
			$out->addWikiMsg( 'wantedsort-noresults' );
			// Still render nav so users with a stale deep-link have a Prev control.
			if ( $offset > 0 ) {
				$out->addHTML( $this->buildNavigationBar(
					$offset, $limit, 0, false, $namespace, $sort, $dir
				) );
			}
			return;
		}

		if ( $fromCache ) {
			$out->addWikiMsg( 'wantedsort-cached-notice' );
		}

		[ 'html' => $tableHtml, 'shown' => $shown ] = $this->buildTable(
			$rows, $sort, $dir, $namespace, $limit
		);

		$out->addHTML( $this->buildNavigationBar(
			$offset, $limit, $shown, $hasMore, $namespace, $sort, $dir
		) );
		$out->addHTML( $tableHtml );
		$out->addHTML( $this->buildNavigationBar(
			$offset, $limit, $shown, $hasMore, $namespace, $sort, $dir
		) );
	}

	/**
	 * Fetch one page of results, optionally via WAN cache.
	 *
	 * @return array{rows:list<array{namespace:int,title:string,value:int}>,hasMore:bool,fromCache:bool}
	 */
	private function fetchResultPage(
		?int $namespace,
		string $sort,
		string $dir,
		int $limit,
		int $offset
	): array {
		$threshold = (int)$this->getConfig()->get( MainConfigNames::WantedPagesThreshold );
		$ttl = (bool)$this->getConfig()->get( MainConfigNames::MiserMode )
			? self::CACHE_TTL_MISER
			: self::CACHE_TTL;

		$cacheKey = $this->wanCache->makeKey(
			'WantedSort',
			'v1',
			(string)$threshold,
			(string)( $namespace ?? 'all' ),
			$sort,
			$dir,
			(string)$limit,
			(string)$offset
		);

		$fromCache = true;
		$cached = $this->wanCache->getWithSetCallback(
			$cacheKey,
			$ttl,
			function ( $oldValue, &$ttlOut, array &$setOpts ) use (
				$namespace, $sort, $dir, $limit, $offset, $threshold, &$fromCache
			) {
				$fromCache = false;
				$dbr = $this->dbProvider->getReplicaDatabase();
				// Prevent caching results from a lagged replica for the full TTL.
				$setOpts += Database::getCacheSetOptions( $dbr );
				// Pass the same $dbr so cache-set options and the SELECT share one handle.
				return $this->queryResultPage( $namespace, $sort, $dir, $limit, $offset, $threshold, $dbr );
			},
			[
				'lockTSE'  => 30,
				'staleTTL' => 60,
				'pcTTL'    => WANObjectCache::TTL_PROC_LONG,
			]
		);

		return [
			'rows'      => $cached['rows'] ?? [],
			'hasMore'   => (bool)( $cached['hasMore'] ?? false ),
			'fromCache' => $fromCache && $cached !== false,
		];
	}

	/**
	 * Run the live grouped pagelinks query for one UI page.
	 *
	 * @param IReadableDatabase|null $dbr Caller-supplied handle; obtained fresh if null.
	 * @return array{rows:list<array{namespace:int,title:string,value:int}>,hasMore:bool}
	 */
	private function queryResultPage(
		?int $namespace,
		string $sort,
		string $dir,
		int $limit,
		int $offset,
		int $threshold,
		?IReadableDatabase $dbr = null
	): array {
		$dbr ??= $this->dbProvider->getReplicaDatabase();

		// Under miser mode, cap the DB fetch so a crafted high offset cannot
		// return rows past MISER_MAX_RESULTS, matching QueryPage::getDBLimit().
		$miserMode = (bool)$this->getConfig()->get( MainConfigNames::MiserMode );
		if ( $miserMode ) {
			$dbLimit = max( 0, min( $limit + 1, self::MISER_MAX_RESULTS - $offset ) );
			if ( $dbLimit === 0 ) {
				return [ 'rows' => [], 'hasMore' => false ];
			}
		} else {
			$dbLimit = $limit + 1;
		}

		[ $blNamespace, $blTitle ] = $this->linksMigration->getTitleFields( 'pagelinks' );
		$queryInfo = $this->linksMigration->getQueryInfo( 'pagelinks', 'pagelinks' );

		$conds = [
			'pg1.page_namespace' => null,
			$dbr->expr( $blNamespace, '!=', [ NS_USER, NS_USER_TALK ] ),
			$dbr->expr( 'pg2.page_namespace', '!=', NS_MEDIAWIKI ),
		];

		if ( $namespace !== null ) {
			$conds[$blNamespace] = $namespace;
		}

		$tables = array_merge( $queryInfo['tables'], [
			'pg1' => 'page',
			'pg2' => 'page',
		] );

		$joinConds = array_merge( [
			'pg1' => [
				'LEFT JOIN', [
					'pg1.page_namespace = ' . $blNamespace,
					'pg1.page_title = ' . $blTitle,
				],
			],
			'pg2' => [ 'LEFT JOIN', 'pg2.page_id = pl_from' ],
		], $queryInfo['joins'] );

		$having = [
			'COUNT(*) > ' . $dbr->addQuotes( $threshold - 1 ),
			'COUNT(*) > SUM(pg2.page_is_redirect)',
		];

		$orderBy = $this->buildOrderBy( $sort, $dir, $blNamespace, $blTitle );

		// One extra row detects a next page without a separate COUNT(*).
		$res = $dbr->newSelectQueryBuilder()
			->rawTables( $tables )
			->select( [
				'namespace' => $blNamespace,
				'title'     => $blTitle,
				'value'     => 'COUNT(*)',
			] )
			->where( $conds )
			->having( $having )
			->groupBy( [ $blNamespace, $blTitle ] )
			->orderBy( $orderBy )
			->limit( $dbLimit )
			->offset( $offset )
			->joinConds( $joinConds )
			->caller( __METHOD__ )
			->fetchResultSet();

		$rows = [];
		$hasMore = false;
		foreach ( $res as $row ) {
			if ( count( $rows ) >= $limit ) {
				$hasMore = true;
				break;
			}
			$rows[] = [
				'namespace' => (int)$row->namespace,
				'title'     => (string)$row->title,
				'value'     => (int)$row->value,
			];
		}

		return [ 'rows' => $rows, 'hasMore' => $hasMore ];
	}

	/**
	 * @return string|array ORDER BY clause for SelectQueryBuilder::orderBy()
	 */
	private function buildOrderBy( string $sort, string $dir, string $blNamespace, string $blTitle ) {
		$dirUpper = strtoupper( $dir );
		switch ( $sort ) {
			case 'title':
				return $dirUpper === 'DESC'
					? [ "$blTitle DESC", "$blNamespace DESC" ]
					: [ $blTitle, $blNamespace ];
			case 'namespace':
				return $dirUpper === 'DESC'
					? [ "$blNamespace DESC", $blTitle ]
					: [ $blNamespace, $blTitle ];
			case 'links':
			default:
				return $dirUpper === 'DESC'
					? [ 'COUNT(*) DESC', $blNamespace, $blTitle ]
					: [ 'COUNT(*)', $blNamespace, $blTitle ];
		}
	}

	/**
	 * @param list<array{namespace:int,title:string,value:int}> $rows
	 * @return array{html:string,shown:int}
	 */
	private function buildTable(
		array $rows,
		string $sort,
		string $dir,
		?int $namespace,
		int $limit
	): array {
		$linkRenderer = $this->getLinkRenderer();
		$lang = $this->getLanguage();

		// Collect titles for one-shot link cache warm-up; filter bad titles here
		// so $shown reflects only rows that actually render.
		$prepared = [];
		$titles = [];
		foreach ( $rows as $row ) {
			$title = Title::makeTitleSafe( $row['namespace'], $row['title'] );
			if ( $title ) {
				$prepared[] = [ 'title' => $title, 'value' => $row['value'], 'namespace' => $row['namespace'] ];
				$titles[] = $title;
			}
		}

		$this->linkBatchFactory->newLinkBatch( $titles )->execute();

		$nsNames = $lang->getNamespaces();

		$html = Html::openElement( 'table', [ 'class' => 'wikitable mw-wantedsort-table' ] );
		$html .= Html::openElement( 'thead' ) . Html::openElement( 'tr' );

		$columns = [
			'title'     => 'wantedsort-col-title',
			'namespace' => 'wantedsort-col-namespace',
			'links'     => 'wantedsort-col-links',
		];
		foreach ( $columns as $col => $msgKey ) {
			$html .= Html::rawElement( 'th', [],
				$this->makeSortLink( $col, $msgKey, $sort, $dir, $namespace, $limit )
			);
		}
		$html .= Html::element( 'th', [], $this->msg( 'wantedsort-col-wlh' )->text() );
		$html .= Html::closeElement( 'tr' ) . Html::closeElement( 'thead' );

		$html .= Html::openElement( 'tbody' );
		foreach ( $prepared as [ 'title' => $title, 'value' => $value, 'namespace' => $nsId ] ) {
			$pageLink = $linkRenderer->makeBrokenLink( $title );
			$wlhTitle = SpecialPage::getTitleFor( 'Whatlinkshere', $title->getPrefixedText() );
			$wlhLink  = $linkRenderer->makeLink(
				$wlhTitle,
				$this->msg( 'nlinks' )->numParams( $value )->text()
			);

			$nsText = $nsId === NS_MAIN
				? $this->msg( 'blanknamespace' )->escaped()
				: htmlspecialchars( str_replace( '_', ' ', $nsNames[$nsId] ?? (string)$nsId ) );

			$html .= Html::openElement( 'tr' );
			$html .= Html::rawElement( 'td', [], $pageLink );
			$html .= Html::rawElement( 'td', [], $nsText );
			$html .= Html::element( 'td', [ 'class' => 'mw-wantedsort-count' ], (string)$value );
			$html .= Html::rawElement( 'td', [], $wlhLink );
			$html .= Html::closeElement( 'tr' );
		}
		$html .= Html::closeElement( 'tbody' );
		$html .= Html::closeElement( 'table' );

		return [ 'html' => $html, 'shown' => count( $prepared ) ];
	}

	/** Sort column header link; always resets offset to 0 to avoid stale pagination. */
	private function makeSortLink(
		string $col,
		string $msgKey,
		string $currentSort,
		string $currentDir,
		?int $namespace,
		int $limit
	): string {
		$label = $this->msg( $msgKey )->escaped();

		if ( $col === $currentSort ) {
			$newDir    = $currentDir === 'asc' ? 'desc' : 'asc';
			$indicator = $currentDir === 'asc' ? ' ↑' : ' ↓';
			$label .= Html::element( 'span', [ 'class' => 'mw-wantedsort-sort-indicator' ], $indicator );
		} else {
			$newDir = 'desc';
		}

		$params = [ 'sort' => $col, 'dir' => $newDir, 'limit' => $limit, 'offset' => 0 ];
		if ( $namespace !== null ) {
			$params['namespace'] = $namespace;
		}

		return Html::rawElement( 'a',
			[ 'href' => $this->getPageTitle()->getLocalURL( $params ) ],
			$label
		);
	}

	private function buildNavigationBar(
		int $offset,
		int $limit,
		int $shown,
		bool $hasMore,
		?int $namespace,
		string $sort,
		string $dir
	): string {
		$baseParams = [ 'sort' => $sort, 'dir' => $dir, 'limit' => $limit ];
		if ( $namespace !== null ) {
			$baseParams['namespace'] = $namespace;
		}

		$parts = [];

		if ( $offset > 0 ) {
			$prevOffset = max( 0, $offset - $limit );
			$prevUrl = $this->getPageTitle()->getLocalURL( $baseParams + [ 'offset' => $prevOffset ] );
			$parts[] = Html::rawElement( 'a', [ 'href' => $prevUrl ],
				$this->msg( 'prevn' )->numParams( $limit )->escaped()
			);
		}

		if ( $shown > 0 ) {
			$parts[] = $this->msg( 'wantedsort-showing-from' )
				->numParams( $offset + 1, $offset + $shown )
				->escaped();
		}

		if ( $hasMore ) {
			$nextUrl = $this->getPageTitle()->getLocalURL( $baseParams + [ 'offset' => $offset + $limit ] );
			$parts[] = Html::rawElement( 'a', [ 'href' => $nextUrl ],
				$this->msg( 'nextn' )->numParams( $limit )->escaped()
			);
		}

		return Html::rawElement( 'div', [ 'class' => 'mw-wantedsort-nav' ],
			implode( ' | ', $parts )
		);
	}

	/**
	 * Snap $value to the largest option in $options that is <= $value,
	 * falling back to the smallest option if $value is below all of them.
	 *
	 * @param int[] $options Sorted ascending list of valid values.
	 */
	private function snapToValidLimit( int $value, array $options ): int {
		$snapped = $options[0];
		foreach ( $options as $option ) {
			if ( $value >= $option ) {
				$snapped = $option;
			}
		}
		return $snapped;
	}

	/**
	 * Resolve a raw string (numeric ID or namespace name) to a valid namespace index.
	 * Returns null if the string is empty, unrecognised, or below NS_MAIN.
	 */
	private function resolveNamespace( ?string $raw ): ?int {
		if ( $raw === null || $raw === '' ) {
			return null;
		}
		if ( ctype_digit( $raw ) ) {
			$id = (int)$raw;
			return ( $id >= NS_MAIN && $this->namespaceInfo->exists( $id ) ) ? $id : null;
		}
		// Try canonical or localised namespace name.
		$id = $this->getContentLanguage()->getNsIndex( str_replace( ' ', '_', $raw ) );
		return ( $id !== false && $id >= NS_MAIN ) ? (int)$id : null;
	}

	/** @inheritDoc */
	protected function getGroupName(): string {
		return 'maintenance';
	}
}
